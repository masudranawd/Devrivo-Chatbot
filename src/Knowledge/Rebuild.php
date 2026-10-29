<?php
/**
 * Full index rebuild in background batches.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Re-indexes every post in batches of BATCH by ascending ID, one cron event
 * per batch, then removes rows of posts that no longer qualify and re-syncs
 * the other sources (FAQs).
 *
 * State (option, not autoloaded): status idle|running|done, total, processed,
 * last_id, started_at and finished_at (Unix timestamps, 0 when unset).
 */
final class Rebuild {

	/**
	 * State option.
	 */
	public const OPTION = 'devrivo_chatbot_index_state';

	/**
	 * Cron hook that runs one batch.
	 */
	public const HOOK = 'devrivo_chatbot_index_batch';

	/**
	 * Posts per batch.
	 */
	public const BATCH = 50;

	/**
	 * Constructor.
	 *
	 * @param Knowledge $knowledge Knowledge.
	 */
	public function __construct( private Knowledge $knowledge ) {}

	/**
	 * Hooks the batch event.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Current state.
	 *
	 * @return array{status: string, total: int, processed: int, last_id: int, started_at: int, finished_at: int}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'status'      => in_array( $stored['status'] ?? '', array( 'running', 'done' ), true ) ? (string) $stored['status'] : 'idle',
			'total'       => (int) ( $stored['total'] ?? 0 ),
			'processed'   => (int) ( $stored['processed'] ?? 0 ),
			'last_id'     => (int) ( $stored['last_id'] ?? 0 ),
			'started_at'  => (int) ( $stored['started_at'] ?? 0 ),
			'finished_at' => (int) ( $stored['finished_at'] ?? 0 ),
		);
	}

	/**
	 * Starts (or restarts) a rebuild and schedules the first batch.
	 *
	 * @return array{status: string, total: int, processed: int, last_id: int, started_at: int, finished_at: int}
	 */
	public static function start(): array {
		// Post types may not be registered yet (migrations run early), so the
		// total is counted by the first batch.
		$state = array(
			'status'      => 'running',
			'total'       => 0,
			'processed'   => 0,
			'last_id'     => 0,
			'started_at'  => time(),
			'finished_at' => 0,
		);
		update_option( self::OPTION, $state, false );

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );

		return $state;
	}

	/**
	 * Cron: indexes the next batch, then schedules another or finishes.
	 */
	public function run_batch(): void {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		$posts = $this->knowledge->posts();
		if ( 0 === $state['last_id'] ) {
			$state['total'] = $posts->count();
		}

		$ids = $posts->batch( $state['last_id'], self::BATCH );
		foreach ( $ids as $id ) {
			$posts->index( $id );
			$state['last_id'] = $id;
			++$state['processed'];
		}

		if ( count( $ids ) < self::BATCH ) {
			$this->finish( $state );
			return;
		}

		update_option( self::OPTION, $state, false );
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Removes stale rows, re-syncs the other sources and marks the rebuild done.
	 *
	 * @param array{status: string, total: int, processed: int, last_id: int, started_at: int, finished_at: int} $state State.
	 */
	private function finish( array $state ): void {
		$index   = $this->knowledge->index();
		$removed = 0;

		foreach ( $this->knowledge->sources() as $id => $source ) {
			if ( 'post' === $id ) {
				$after = 0;
				do {
					$ids   = $index->object_ids( 'post', $after, 200 );
					$found = count( $ids );
					foreach ( $ids as $post_id ) {
						if ( ! $source->is_current( $post_id, '' ) ) {
							$removed += $index->delete( 'post', $post_id, null ) > 0 ? 1 : 0;
						}
						$after = $post_id;
					}
				} while ( 200 === $found );
				continue;
			}
			$source->sync();
		}

		$state['status']      = 'done';
		$state['total']       = max( $state['total'], $state['processed'] );
		$state['finished_at'] = time();
		update_option( self::OPTION, $state, false );

		$stats = array_merge(
			$index->counts(),
			array(
				'processed' => $state['processed'],
				'removed'   => $removed,
				'seconds'   => max( 0, $state['finished_at'] - $state['started_at'] ),
			)
		);

		/**
		 * Fires after a full index rebuild finished.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, int> $stats posts, chunks and faqs in the index; processed posts; removed stale posts; seconds taken.
		 */
		do_action( 'devrivo_chatbot_index_rebuilt', $stats );
	}
}
