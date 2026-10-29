<?php
/**
 * Knowledge index table.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

// Custom table: direct queries are expected here, and every one uses $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Stores searchable chunks in {prefix}devrivo_chatbot_index.
 *
 * One row per chunk. Posts use source "post" with the post ID; other sources
 * (FAQs) use source_key. Only public site content is stored here, never
 * visitor data. Dates are GMT.
 */
final class Index {

	/**
	 * Search driver option (not autoloaded): "fulltext" or "like".
	 */
	public const DRIVER_OPTION = 'devrivo_chatbot_index_driver';

	/**
	 * Whether the index has any rows (autoloaded, tiny): read by the public
	 * config without a query.
	 */
	public const HAS_CONTENT_OPTION = 'devrivo_chatbot_index_has_content';

	/**
	 * Name of the FULLTEXT key.
	 */
	public const FULLTEXT_KEY = 'title_content';

	/**
	 * Table name without the prefix.
	 */
	private const TABLE = 'devrivo_chatbot_index';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table (dbDelta, so it is safe to run again).
	 * The FULLTEXT key is added separately by add_fulltext().
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta format: one field per line, two spaces after PRIMARY KEY, KEY not INDEX.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_key varchar(64) NOT NULL DEFAULT '',
  chunk smallint(5) unsigned NOT NULL DEFAULT 0,
  post_type varchar(20) NOT NULL DEFAULT '',
  title text NOT NULL,
  content mediumtext NOT NULL,
  content_hash char(32) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source_object (source,object_id)
) {$collate};"
		);
	}

	/**
	 * Tries to add the FULLTEXT key, checks it with SHOW INDEX and stores the
	 * search driver: "fulltext" when the key exists, otherwise "like".
	 *
	 * @return string Driver.
	 */
	public static function add_fulltext(): string {
		global $wpdb;

		if ( ! self::has_fulltext() ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY %i (title, content)', self::table(), self::FULLTEXT_KEY ) );
			$wpdb->suppress_errors( $suppress );
		}

		$driver = self::has_fulltext() ? 'fulltext' : 'like';
		update_option( self::DRIVER_OPTION, $driver, false );

		return $driver;
	}

	/**
	 * Whether the table has the FULLTEXT key.
	 */
	public static function has_fulltext(): bool {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', self::table(), self::FULLTEXT_KEY ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( 'FULLTEXT' === strtoupper( (string) ( $row['Index_type'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stored driver ("fulltext" or "like").
	 */
	public static function driver(): string {
		return 'fulltext' === get_option( self::DRIVER_OPTION ) ? 'fulltext' : 'like';
	}

	/**
	 * Drops the table (uninstall with the data opt-in only).
	 */
	public static function drop_table(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}

	/**
	 * Content hash stored on an item's first chunk.
	 *
	 * @param string $source    Source ID.
	 * @param int    $object_id Object ID (posts).
	 * @param string $key       Source key (other sources).
	 */
	public function hash( string $source, int $object_id, string $key = '' ): ?string {
		global $wpdb;

		$hash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT content_hash FROM %i WHERE source = %s AND object_id = %d AND source_key = %s ORDER BY chunk ASC LIMIT 1',
				self::table(),
				$source,
				$object_id,
				$key
			)
		);

		return is_string( $hash ) ? $hash : null;
	}

	/**
	 * Replaces an item's rows with new chunks.
	 *
	 * @param array{source: string, object_id: int, source_key: string, post_type: string, title: string, hash: string} $item   Item.
	 * @param string[]                                                                                                  $chunks Chunks.
	 */
	public function replace( array $item, array $chunks ): void {
		global $wpdb;

		$this->delete( $item['source'], $item['object_id'], $item['source_key'] );

		$now = current_time( 'mysql', true );
		foreach ( array_values( $chunks ) as $index => $chunk ) {
			$wpdb->insert(
				self::table(),
				array(
					'source'       => $item['source'],
					'object_id'    => $item['object_id'],
					'source_key'   => $item['source_key'],
					'chunk'        => $index,
					'post_type'    => $item['post_type'],
					'title'        => $item['title'],
					'content'      => $chunk,
					'content_hash' => $item['hash'],
					'updated_at'   => $now,
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$this->refresh_has_content();
	}

	/**
	 * Removes an item's rows.
	 *
	 * @param string      $source    Source ID.
	 * @param int         $object_id Object ID.
	 * @param string|null $key       Source key, or null for any key.
	 * @return int Rows removed.
	 */
	public function delete( string $source, int $object_id, ?string $key = '' ): int {
		global $wpdb;

		$removed = null === $key
			? $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND object_id = %d', self::table(), $source, $object_id ) )
			: $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND object_id = %d AND source_key = %s', self::table(), $source, $object_id, $key ) );

		if ( $removed ) {
			$this->refresh_has_content();
		}

		return (int) $removed;
	}

	/**
	 * Removes every row of a source.
	 *
	 * @param string $source Source ID.
	 */
	public function delete_source( string $source ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s', self::table(), $source ) );
		$this->refresh_has_content();
	}

	/**
	 * Object IDs of a source, after an ID (for stale cleanup in batches).
	 *
	 * @param string $source Source ID.
	 * @param int    $after  Only IDs above this.
	 * @param int    $limit  Maximum IDs.
	 * @return int[]
	 */
	public function object_ids( string $source, int $after, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT object_id FROM %i WHERE source = %s AND object_id > %d ORDER BY object_id ASC LIMIT %d',
				self::table(),
				$source,
				$after,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Counts: indexed posts, post chunks and FAQs.
	 *
	 * @return array{posts: int, chunks: int, faqs: int}
	 */
	public function counts(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT CASE WHEN source = %s THEN object_id END) AS posts, SUM(source = %s) AS chunks, SUM(source = %s) AS faqs FROM %i',
				'post',
				'post',
				'faq',
				self::table()
			),
			ARRAY_A
		);

		return array(
			'posts'  => (int) ( $row['posts'] ?? 0 ),
			'chunks' => (int) ( $row['chunks'] ?? 0 ),
			'faqs'   => (int) ( $row['faqs'] ?? 0 ),
		);
	}

	/**
	 * Whether the index has any rows (from the cached flag).
	 */
	public static function has_content(): bool {
		return (bool) get_option( self::HAS_CONTENT_OPTION, false );
	}

	/**
	 * Updates the "has content" flag. When it changes, the public config
	 * version is bumped so widgets learn that search is (un)available.
	 */
	public function refresh_has_content(): void {
		global $wpdb;

		$has = null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', self::table() ) );

		if ( self::has_content() !== $has ) {
			update_option( self::HAS_CONTENT_OPTION, $has ? 1 : 0, true );
			Settings::bump_config_version();
		}
	}
}
