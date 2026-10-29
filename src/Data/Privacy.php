<?php
/**
 * Privacy tools.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Data;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Suggested privacy policy text, personal data export and erase (Tools →
 * Export/Erase Personal Data), and the daily retention purge.
 */
final class Privacy {

	/**
	 * Daily purge cron hook.
	 */
	public const PURGE_HOOK = 'devrivo_chatbot_purge';

	/**
	 * Leads per export/erase page.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Leads deleted per purge query, and the most batches per run (keeps each
	 * cron run short; the rest is deleted the next day).
	 */
	private const PURGE_BATCH = 500;

	private const PURGE_MAX_BATCHES = 20;

	/**
	 * Constructor.
	 *
	 * @param Leads    $leads    Leads repository.
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Leads $leads, private Settings $settings ) {}

	/**
	 * Hooks everything.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( self::PURGE_HOOK, array( $this, 'purge' ) );
	}

	/**
	 * Schedules the daily purge if it isn't scheduled yet.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Suggested text for Settings → Privacy. Runs on admin_init.
	 */
	public function add_policy_content(): void {
		wp_add_privacy_policy_content( 'DevRivo Chatbot', $this->policy_text() );
	}

	/**
	 * Suggested privacy policy text (HTML paragraphs).
	 */
	public function policy_text(): string {
		$days = (int) ( $this->settings->get( 'privacy' )['retention_days'] ?? 180 );

		$paragraphs = array(
			__( 'This website uses DevRivo Chatbot, an assistant that helps visitors find information and get in touch.', 'devrivo-chatbot' ),
			__( 'When you ask the assistant a question, it searches this website\'s own pages and FAQs for an answer. Your questions are not stored or logged by the assistant.', 'devrivo-chatbot' ),
			__( 'When you send an enquiry through the assistant, we collect the details you enter in the form (for example your name, email address, phone number and message), the page you were on, and a record of the consent you gave. We use these details only to reply to your enquiry.', 'devrivo-chatbot' ),
			__( 'Your enquiry is stored in this website\'s database and sent by email to our team. Your IP address is not stored with your enquiry.', 'devrivo-chatbot' ),
			sprintf(
				/* translators: %d: Number of days. */
				_n( 'Enquiries are deleted automatically after %d day.', 'Enquiries are deleted automatically after %d days.', $days, 'devrivo-chatbot' ),
				$days
			),
			__( 'You can ask us for a copy of the personal data we hold about you, or ask us to delete it, using the contact details on this website.', 'devrivo-chatbot' ),
		);

		return wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['devrivo-chatbot'] = array(
			'exporter_friendly_name' => __( 'DevRivo Chatbot enquiries', 'devrivo-chatbot' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['devrivo-chatbot'] = array(
			'eraser_friendly_name' => __( 'DevRivo Chatbot enquiries', 'devrivo-chatbot' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of leads for an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$leads = $this->leads->find_by_email( $email, self::PAGE_SIZE, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE );
		$items = array();

		foreach ( $leads as $lead ) {
			$data = array(
				array(
					'name'  => __( 'Form', 'devrivo-chatbot' ),
					'value' => $lead['form_id'],
				),
				array(
					'name'  => __( 'Sent', 'devrivo-chatbot' ),
					'value' => $lead['created_at'] . ' UTC',
				),
			);
			foreach ( $lead['data'] as $field ) {
				$data[] = array(
					'name'  => (string) ( $field['label'] ?? $field['id'] ?? '' ),
					'value' => (string) ( $field['value'] ?? '' ),
				);
			}
			$data[] = array(
				'name'  => __( 'Page', 'devrivo-chatbot' ),
				'value' => $lead['page_url'],
			);
			$data[] = array(
				'name'  => __( 'Consent', 'devrivo-chatbot' ),
				'value' => $lead['consent_text'] . ' (' . $lead['consent_at'] . ' UTC)',
			);

			$items[] = array(
				'group_id'    => 'devrivo-chatbot-leads',
				'group_label' => __( 'Chatbot enquiries', 'devrivo-chatbot' ),
				'item_id'     => 'devrivo-chatbot-lead-' . $lead['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $leads ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erases one batch of leads for an email address. Deleted rows leave the
	 * result set, so every call reads the first batch again.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused, see above).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature required by WordPress erasers.
		$removed = $this->leads->delete_by_email( $email, self::PAGE_SIZE );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $removed < self::PAGE_SIZE,
		);
	}

	/**
	 * Deletes leads older than the retention period. Runs daily.
	 */
	public function purge(): void {
		/**
		 * Filters how many days leads are kept.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Days, from Settings → Privacy (1-3650).
		 */
		$days = (int) apply_filters( 'devrivo_chatbot_retention_days', (int) ( $this->settings->get( 'privacy' )['retention_days'] ?? 180 ) );
		if ( $days < 1 ) {
			return;
		}

		$total = 0;
		for ( $batch = 0; $batch < self::PURGE_MAX_BATCHES; $batch++ ) {
			$deleted = $this->leads->purge_older_than( $days, self::PURGE_BATCH );
			$total  += $deleted;
			if ( $deleted < self::PURGE_BATCH ) {
				break;
			}
		}

		/**
		 * Fires after the retention purge ran.
		 *
		 * @since 1.0.0
		 *
		 * @param array{leads: int} $counts Items deleted, by type.
		 */
		do_action( 'devrivo_chatbot_data_purged', array( 'leads' => $total ) );
	}
}
