<?php
/**
 * Admin page, preview config and leads CSV export tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Admin\AdminPage;
use DevRivo\Chatbot\Admin\LeadsExport;
use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Data\Leads;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WPDieException;

final class AdminTest extends WP_UnitTestCase {

	/**
	 * Fixture build folder with fake .asset.php files.
	 *
	 * @var string
	 */
	private string $build_dir;

	public function set_up(): void {
		parent::set_up();

		$this->build_dir = get_temp_dir() . 'devrivo-admin-build-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->build_dir );
		foreach ( array( 'admin', 'widget' ) as $name ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				"{$this->build_dir}/{$name}.asset.php",
				"<?php return array( 'dependencies' => array( 'wp-element', 'wp-components' ), 'version' => '{$name}-v1' );"
			);
		}

		delete_option( Settings::OPTION );
		Plugin::instance()->settings()->flush();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->build_dir . '/*' ) as $file ) {
			unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $this->build_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		global $wp_rest_server, $menu;
		$wp_rest_server = null;
		$menu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_dequeue_script( AdminPage::HANDLE );
		wp_deregister_script( AdminPage::HANDLE );
		set_current_screen( 'front' );
		Plugin::instance()->settings()->flush();

		parent::tear_down();
	}

	public function test_menu_needs_the_plugin_capability(): void {
		global $menu;
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $admin );
		$page = new AdminPage( $this->build_dir );
		$page->add_menu();

		$item = array_values( array_filter( (array) $menu, static fn( $entry ): bool => is_array( $entry ) && AdminPage::SLUG === ( $entry[2] ?? '' ) ) );
		$this->assertCount( 1, $item );
		$this->assertSame( 'DevRivo Chatbot', $item[0][0] );
		$this->assertSame( Capabilities::MANAGE, $item[0][1] );
		$this->assertSame( 'dashicons-format-chat', $item[0][6] );
		$this->assertSame( 'toplevel_page_devrivo-chatbot', $page->hook() );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ), 'Editors don\'t see the menu.' );
	}

	public function test_assets_load_only_on_our_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage( $this->build_dir );
		$page->add_menu();

		// Other screens never run our load-{hook}, so nothing is hooked there.
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ), 'Not on other screens.' );
		$this->assertFalse( wp_script_is( AdminPage::HANDLE, 'enqueued' ) );

		set_current_screen( $page->hook() );
		do_action( 'load-' . $page->hook() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook name.
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );
		$page->enqueue();
		remove_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) );

		$this->assertTrue( wp_script_is( AdminPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );
		$script = wp_scripts()->registered[ AdminPage::HANDLE ];
		$this->assertSame( array( 'wp-element', 'wp-components', 'wp-i18n' ), $script->deps, 'Translations add wp-i18n.' );
		$this->assertSame( 'admin-v1', $script->ver );
		$this->assertStringEndsWith( 'build/admin.js', $script->src );
		$this->assertSame( 'devrivo-chatbot', $script->textdomain );
	}

	public function test_boot_data_is_escaped_and_complete(): void {
		update_option( 'blogname', 'Smile </script><script>alert(1)</script> & "Co"' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage( $this->build_dir );
		$page->add_menu();
		set_current_screen( $page->hook() );
		$page->enqueue();

		$inline = implode( '', (array) wp_scripts()->get_data( AdminPage::HANDLE, 'before' ) );
		$this->assertStringStartsWith( 'window.devrivoChatbotAdmin = {', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );
		$this->assertStringNotContainsString( '<script>', $inline );

		$boot = $page->boot();
		$this->assertSame( admin_url( 'options-connectors.php' ), $boot['connectorsUrl'] );
		$this->assertSame( admin_url( 'export-personal-data.php' ), $boot['privacyExportUrl'] );
		$this->assertSame( admin_url( 'erase-personal-data.php' ), $boot['privacyEraseUrl'] );
		$this->assertContains( 'page', array_column( $boot['postTypes'], 'slug' ) );
		$this->assertNotContains( 'attachment', array_column( $boot['postTypes'], 'slug' ) );
		$this->assertContains( 'Asia/Dhaka', $boot['timezones'] );
		$this->assertStringContainsString( 'build/widget.js?ver=widget-v1', $boot['previewWidgetUrl'] );
		$this->assertStringContainsString( 'action=' . LeadsExport::ACTION, $boot['leadsExportUrl'] );
		$this->assertStringContainsString( '_wpnonce=', $boot['leadsExportUrl'] );

		$link = array_values( array_filter( $boot['actionTypes'], static fn( array $type ): bool => 'link' === $type['id'] ) )[0];
		$this->assertTrue( $link['supported'] );
		$this->assertSame( array( 'url' ), $link['required'] );
		$this->assertSame( array( 'url', 'new_tab' ), array_column( $link['fields'], 'key' ) );
	}

	public function test_render_prints_root_and_noscript(): void {
		ob_start();
		( new AdminPage( $this->build_dir ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div id="devrivo-chatbot-admin"></div>', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_plugin_list_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage( $this->build_dir );

		$links = $page->action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'admin.php?page=devrivo-chatbot', $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );

		$meta = $page->row_meta( array(), DEVRIVO_CHATBOT_BASENAME );
		$this->assertStringContainsString( AdminPage::REPOSITORY, $meta[0] );
		$this->assertSame( array(), $page->row_meta( array(), 'other/other.php' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertCount( 1, $page->action_links( array( 'deactivate' => '<a>Deactivate</a>' ) ) );
	}

	public function test_preview_config_validates_without_saving(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Plugin::instance()->settings()->update( array( 'ai' => array( 'custom_instructions' => 'SECRET-INSTRUCTIONS' ) ) );
		$stored  = get_option( Settings::OPTION );
		$display = get_option( Settings::DISPLAY_OPTION );
		$version = Settings::config_version();

		$response = $this->preview(
			array(
				'display'    => array( 'primary_color' => '#ff0000' ),
				'appearance' => array( 'brand_name' => 'Unsaved Brand' ),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( '#ff0000', $data['display']['primary_color'] );
		$this->assertSame( 'Unsaved Brand', $data['appearance']['brand_name'] );

		$public = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/config' ) )->get_data();
		$this->assertSame( array_keys( $public ), array_keys( $data ), 'Same allowlist as the public config.' );
		$this->assertStringNotContainsString( 'SECRET-INSTRUCTIONS', (string) wp_json_encode( $data ) );

		$this->assertSame( $stored, get_option( Settings::OPTION ), 'Nothing saved.' );
		$this->assertSame( $display, get_option( Settings::DISPLAY_OPTION ) );
		$this->assertSame( $version, Settings::config_version() );
		Plugin::instance()->settings()->flush();
		$this->assertSame( '', Plugin::instance()->settings()->get( 'appearance' )['brand_name'] );
	}

	public function test_preview_config_returns_field_errors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$schema = $this->preview( array( 'appearance' => array( 'radius' => 99 ) ) );
		$this->assertSame( 400, $schema->get_status() );
		$this->assertArrayHasKey( 'appearance', $schema->get_data()['data']['params'] );
		$this->assertSame( 'no-store', $schema->get_headers()['Cache-Control'] );

		$cross = $this->preview( array( 'behaviour' => array( 'fallback_action' => 'missing' ) ) );
		$this->assertSame( 400, $cross->get_status() );
		$this->assertArrayHasKey( 'behaviour[fallback_action]', $cross->get_data()['data']['params'] );

		$unknown = $this->preview( array( 'nope' => array() ) );
		$this->assertSame( 400, $unknown->get_status() );

		$this->assertSame( 200, $this->preview( array() )->get_status(), 'No changes: the saved config.' );
	}

	public function test_preview_config_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->preview( array() )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->preview( array() )->get_status() );
	}

	public function test_snapshot_settings_cannot_be_saved(): void {
		$snapshot = Settings::snapshot( Plugin::instance()->settings()->all() );
		$snapshot->flush();

		$this->assertWPError( $snapshot->update( array( 'appearance' => array( 'brand_name' => 'X' ) ) ) );
		$this->assertSame( '', $snapshot->get( 'appearance' )['brand_name'] );
	}

	public function test_export_needs_capability_and_nonce(): void {
		$export = new LeadsExport( new Leads() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['_wpnonce'] = wp_create_nonce( LeadsExport::ACTION );
		$this->assert_dies( $export );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['_wpnonce'] = 'bad';
		$this->assert_dies( $export );
		unset( $_GET['_wpnonce'] );
	}

	public function test_export_headers_and_filters(): void {
		$headers = LeadsExport::headers( strtotime( '2026-10-31 12:00:00 UTC' ) );
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="devrivo-chatbot-leads-2026-10-31.csv"', $headers['Content-Disposition'] );

		$this->assertSame(
			array(
				'status'  => 'new',
				'form_id' => 'contact',
			),
			LeadsExport::filters(
				array(
					'status'  => 'new',
					'form_id' => 'contact',
				)
			)
		);
		$this->assertSame(
			array(),
			LeadsExport::filters(
				array(
					'status'  => 'evil',
					'form_id' => '../x',
				)
			)
		);
	}

	public function test_formula_cells_are_escaped(): void {
		foreach ( array( '=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx" ) as $value ) {
			$this->assertSame( "'" . $value, LeadsExport::cell( $value ), $value );
		}
		$this->assertSame( 'Ann', LeadsExport::cell( 'Ann' ) );
		$this->assertSame( '', LeadsExport::cell( '' ) );
	}

	public function test_export_streams_all_leads_with_label_union(): void {
		$leads = new Leads();
		$this->lead(
			$leads,
			'contact',
			'new',
			'=HYPERLINK("http://evil.example")',
			array(
				'Name'    => 'Ann',
				'Message' => "Line 1\nLine 2",
			)
		);
		$this->lead(
			$leads,
			'callback',
			'contacted',
			'Bob',
			array(
				'Name'                 => 'Bob',
				'When should we call?' => 'Morning',
			)
		);
		$this->lead( $leads, 'contact', 'spam', 'Spam', array( 'Name' => 'Spam' ) );

		$csv  = $this->export( $leads, array() );
		$rows = $this->rows( $csv );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'UTF-8 BOM.' );
		$this->assertSame( array( 'id', 'date', 'form', 'status', 'email', 'name', 'page_url', 'Name', 'Message', 'When should we call?' ), $rows[0] );
		$this->assertCount( 4, $rows );
		$this->assertSame( "'=HYPERLINK(\"http://evil.example\")", $rows[1][5] );
		$this->assertSame( "Line 1\nLine 2", $rows[1][8] );
		$this->assertSame( '', $rows[1][9] );
		$this->assertSame( 'Morning', $rows[2][9] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[1][1] );

		$filtered = $this->rows( $this->export( $leads, array( 'status' => 'contacted' ) ) );
		$this->assertCount( 2, $filtered );
		$this->assertSame( 'Bob', $filtered[1][5] );
		$this->assertSame( array( 'id', 'date', 'form', 'status', 'email', 'name', 'page_url', 'Name', 'When should we call?' ), $filtered[0], 'Labels of the exported leads only.' );

		$form = $this->rows( $this->export( $leads, array( 'form_id' => 'contact' ) ) );
		$this->assertCount( 3, $form );
	}

	public function test_export_batches_beyond_one_query(): void {
		$leads = new Leads();
		for ( $i = 1; $i <= LeadsExport::BATCH + 25; $i++ ) {
			$this->lead( $leads, 'contact', 'new', "Lead {$i}", array( 'Name' => "Lead {$i}" ) );
		}

		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			if ( str_contains( $query, Leads::table() ) && str_starts_with( ltrim( $query ), 'SELECT' ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );
		$rows = $this->rows( $this->export( $leads, array() ) );
		remove_filter( 'query', $count );

		$this->assertCount( LeadsExport::BATCH + 26, $rows );
		$this->assertSame( 'Lead 1', $rows[1][5] );
		$this->assertSame( 'Lead ' . ( LeadsExport::BATCH + 25 ), $rows[ LeadsExport::BATCH + 25 ][5] );
		$this->assertSame( 4, $queries, 'Two batches for the labels, two for the rows.' );
	}

	/**
	 * Asserts the export handler stops with wp_die().
	 *
	 * @param LeadsExport $export Export.
	 */
	private function assert_dies( LeadsExport $export ): void {
		try {
			$export->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}
	}

	/**
	 * Saves a lead.
	 *
	 * @param Leads                 $leads  Repository.
	 * @param string                $form   Form ID.
	 * @param string                $status Status.
	 * @param string                $name   Name.
	 * @param array<string, string> $fields Label => value.
	 */
	private function lead( Leads $leads, string $form, string $status, string $name, array $fields ): void {
		$data = array();
		foreach ( $fields as $label => $value ) {
			$data[] = array(
				'id'    => sanitize_key( $label ),
				'label' => $label,
				'value' => $value,
			);
		}
		$id = (int) $leads->create(
			array(
				'form_id'      => $form,
				'email'        => 'a@example.com',
				'name'         => $name,
				'data'         => $data,
				'page_url'     => home_url( '/' ),
				'consent_text' => 'I agree.',
			)
		);
		if ( 'new' !== $status ) {
			$leads->update_status( $id, $status );
		}
	}

	/**
	 * Export output.
	 *
	 * @param Leads                $leads   Repository.
	 * @param array<string, mixed> $filters Filters.
	 */
	private function export( Leads $leads, array $filters ): string {
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		( new LeadsExport( $leads ) )->stream( LeadsExport::filters( $filters ), $out );
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $csv;
	}

	/**
	 * Parsed CSV rows (BOM removed).
	 *
	 * @param string $csv CSV.
	 * @return list<list<string>>
	 */
	private function rows( string $csv ): array {
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, substr( $csv, 3 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $out );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $out, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$rows[] = array_map( 'strval', $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * POST /preview-config.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function preview( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/devrivo-chatbot/v1/preview-config' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $body ) );

		return rest_get_server()->dispatch( $request );
	}
}
