<?php
/**
 * Leads repository.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Data;

defined( 'ABSPATH' ) || exit;

// Custom table: direct queries are expected here, and every one uses $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Stores form submissions (leads) in {prefix}devrivo_chatbot_leads.
 *
 * Personal data lives only here: no IP address or user agent is stored.
 * Dates are GMT ("Y-m-d H:i:s").
 */
final class Leads {

	/**
	 * Lead statuses.
	 */
	public const STATUSES = array( 'new', 'contacted', 'closed', 'spam' );

	/**
	 * Table name without the prefix.
	 */
	private const TABLE = 'devrivo_chatbot_leads';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table (dbDelta, so it is safe to run again).
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
  form_id varchar(40) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'new',
  email varchar(100) NOT NULL DEFAULT '',
  name varchar(191) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  page_url text NOT NULL,
  consent_text text NOT NULL,
  consent_at datetime NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY status_created (status,created_at),
  KEY created_at (created_at)
) {$collate};"
		);
	}

	/**
	 * Drops the table (uninstall with the data opt-in only).
	 */
	public static function drop_table(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}

	/**
	 * Saves a lead.
	 *
	 * @param array{form_id: string, email: string, name: string, data: array<int, array<string, string>>, page_url: string, consent_text: string} $lead Lead.
	 * @return int|null New ID, or null on a database error.
	 */
	public function create( array $lead ): ?int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'form_id'      => $lead['form_id'],
				'status'       => 'new',
				'email'        => $lead['email'],
				'name'         => $lead['name'],
				'data'         => (string) wp_json_encode( $lead['data'] ),
				'page_url'     => $lead['page_url'],
				'consent_text' => $lead['consent_text'],
				'consent_at'   => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : null;
	}

	/**
	 * One lead.
	 *
	 * @param int $id Lead ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A page of leads, newest first.
	 *
	 * @param array{page?: int, per_page?: int, status?: string, form_id?: string} $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array( self::table() );
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %s';
			$params[] = $args['form_id'];
		}
		$where = implode( ' AND ', $where );

		// $where only holds fixed SQL with placeholders; values go through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", ...$params ) );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders and values are built together above.
			$wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", ...array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Leads after an ID, oldest first (exports in batches).
	 *
	 * @param array{status?: string, form_id?: string} $filters Filters.
	 * @param int                                      $after   Only IDs above this.
	 * @param int                                      $limit   Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function batch( array $filters, int $after, int $limit ): array {
		global $wpdb;

		$where  = array( 'id > %d' );
		$params = array( self::table(), $after );
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['form_id'] ) ) {
			$where[]  = 'form_id = %s';
			$params[] = $filters['form_id'];
		}
		$where    = implode( ' AND ', $where );
		$params[] = $limit;

		// $where only holds fixed SQL with placeholders; values go through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id ASC LIMIT %d", ...$params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Changes a lead's status.
	 *
	 * @param int    $id     Lead ID.
	 * @param string $status One of STATUSES.
	 * @return bool Whether the lead exists and the status is valid.
	 */
	public function update_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) || null === $this->find( $id ) ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes a lead.
	 *
	 * @param int $id Lead ID.
	 * @return bool Whether a lead was deleted.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Leads with an email address (privacy export), oldest first.
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_email( string $email, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', self::table(), $email, $limit, $offset ),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Deletes up to $limit leads with an email address (privacy erase).
	 *
	 * @param string $email Email address.
	 * @param int    $limit Batch size.
	 * @return int Leads deleted.
	 */
	public function delete_by_email( string $email, int $limit = 100 ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d', self::table(), $email, $limit )
		);
	}

	/**
	 * Deletes up to $batch leads created more than $days days ago.
	 *
	 * @param int $days  Retention in days.
	 * @param int $batch Batch size.
	 * @return int Leads deleted.
	 */
	public function purge_older_than( int $days, int $batch = 500 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY id ASC LIMIT %d', self::table(), $cutoff, $batch )
		);
	}

	/**
	 * Row to lead array.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$data = json_decode( (string) ( $row['data'] ?? '' ), true );

		return array(
			'id'           => (int) $row['id'],
			'form_id'      => (string) $row['form_id'],
			'status'       => (string) $row['status'],
			'email'        => (string) $row['email'],
			'name'         => (string) $row['name'],
			'data'         => is_array( $data ) ? $data : array(),
			'page_url'     => (string) $row['page_url'],
			'consent_text' => (string) $row['consent_text'],
			'consent_at'   => (string) $row['consent_at'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}
}
