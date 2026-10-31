<?php
/**
 * Leads CSV export.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Data\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-post.php?action=devrivo_chatbot_export_leads.
 *
 * Streams leads as CSV (UTF-8 with BOM) in batches, filtered by status and
 * form. One column per field label found in the exported leads. Values that
 * spreadsheets would run as formulas are prefixed with an apostrophe.
 */
final class LeadsExport {

	/**
	 * The admin-post action, also the nonce action.
	 */
	public const ACTION = 'devrivo_chatbot_export_leads';

	/**
	 * Leads read per query.
	 */
	public const BATCH = 500;

	/**
	 * Fixed columns.
	 */
	private const COLUMNS = array( 'id', 'date', 'form', 'status', 'email', 'name', 'page_url' );

	/**
	 * Constructor.
	 *
	 * @param Leads $leads Leads repository.
	 */
	public function __construct( private Leads $leads ) {}

	/**
	 * Hooks the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Export URL with a nonce (filters are added by the admin app).
	 */
	public static function url(): string {
		return wp_nonce_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Handles the request: checks, then streams the file.
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::ACTION ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export leads.', 'devrivo-chatbot' ), '', array( 'response' => 403 ) );
		}

		$filters = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.

		nocache_headers();
		foreach ( self::headers( time() ) as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the response body, not a file.
		if ( false !== $out ) {
			$this->stream( $filters, $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen above.
		}
		exit;
	}

	/**
	 * Response headers.
	 *
	 * @param int $now Unix time (for the file name, in the site timezone).
	 * @return array<string, string>
	 */
	public static function headers( int $now ): array {
		return array(
			'Content-Type'           => 'text/csv; charset=utf-8',
			'Content-Disposition'    => 'attachment; filename="devrivo-chatbot-leads-' . wp_date( 'Y-m-d', $now ) . '.csv"',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Valid filters from the request.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 * @return array{status?: string, form_id?: string}
	 */
	public static function filters( array $query ): array {
		// Exact values only: anything else means "no filter".
		$filters = array();
		$status  = is_string( $query['status'] ?? null ) ? wp_unslash( $query['status'] ) : '';
		$form    = is_string( $query['form_id'] ?? null ) ? wp_unslash( $query['form_id'] ) : '';

		if ( in_array( $status, Leads::STATUSES, true ) ) {
			$filters['status'] = $status;
		}
		if ( 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $form ) ) {
			$filters['form_id'] = $form;
		}

		return $filters;
	}

	/**
	 * Writes the CSV: BOM, header row, one row per lead. Reads the leads twice
	 * in batches (labels first, then rows) so memory stays small.
	 *
	 * @param array{status?: string, form_id?: string} $filters Filters.
	 * @param resource                                 $out     Output stream.
	 */
	public function stream( array $filters, $out ): void {
		$labels = array();
		$this->each(
			$filters,
			static function ( array $lead ) use ( &$labels ): void {
				foreach ( $lead['data'] as $field ) {
					$label = trim( (string) ( $field['label'] ?? '' ) );
					if ( '' !== $label ) {
						$labels[ $label ] = true;
					}
				}
			}
		);
		$labels = array_keys( $labels );

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Output stream.
		self::row( $out, array_merge( self::COLUMNS, $labels ) );

		$this->each(
			$filters,
			static function ( array $lead ) use ( $out, $labels ): void {
				$values = array();
				foreach ( $lead['data'] as $field ) {
					$label = trim( (string) ( $field['label'] ?? '' ) );
					if ( '' !== $label ) {
						$values[ $label ][] = (string) ( $field['value'] ?? '' );
					}
				}

				$row = array(
					(string) $lead['id'],
					wp_date( 'Y-m-d H:i:s', (int) strtotime( $lead['created_at'] . ' UTC' ) ),
					$lead['form_id'],
					$lead['status'],
					$lead['email'],
					$lead['name'],
					$lead['page_url'],
				);
				foreach ( $labels as $label ) {
					$row[] = implode( '; ', $values[ $label ] ?? array() );
				}

				self::row( $out, $row );
			}
		);
	}

	/**
	 * A cell value that spreadsheets won't run as a formula.
	 *
	 * @param string $value Value.
	 */
	public static function cell( string $value ): string {
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * Calls $fn for every matching lead, in batches of BATCH by ascending ID.
	 *
	 * @param array{status?: string, form_id?: string} $filters Filters.
	 * @param callable                                 $callback Callback( array $lead ).
	 */
	private function each( array $filters, callable $callback ): void {
		$after = 0;
		do {
			$batch = $this->leads->batch( $filters, $after, self::BATCH );
			$found = count( $batch );
			foreach ( $batch as $lead ) {
				$callback( $lead );
				$after = (int) $lead['id'];
			}
		} while ( self::BATCH === $found );
	}

	/**
	 * Writes one CSV row with formula-safe cells.
	 *
	 * @param resource $out   Output stream.
	 * @param string[] $cells Cells.
	 */
	private static function row( $out, array $cells ): void {
		fputcsv( $out, array_map( array( self::class, 'cell' ), $cells ), ',', '"', '' );
	}
}
