<?php
/**
 * The workspace activity feed: what happened across the team.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

/**
 * One row per team event (a task finished, a decision made…), shown to every
 * member under Notifications → Workspace. It is not anyone's notification:
 * those stay private to their recipient.
 */
final class ActivityRepository extends Repository {

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'activity';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'activity';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'actor_id'    => '%d',
			'agent'       => '%s',
			'verb'        => '%s',
			'object_type' => '%s',
			'object_id'   => '%d',
			'data'        => '%s',
			'created_at'  => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'actor_id'    => 0,
			'agent'       => '',
			'object_type' => '',
			'object_id'   => 0,
			'data'        => '{}',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		$data = json_decode( (string) $row['data'], true );
		return array(
			'id'         => (int) $row['id'],
			'verb'       => (string) $row['verb'],
			'actor'      => (int) $row['actor_id'],
			'agent'      => (string) $row['agent'],
			'objectType' => (string) $row['object_type'],
			'objectId'   => (int) $row['object_id'],
			'data'       => is_array( $data ) ? $data : array(),
			'at'         => Db::to_ms( $row['created_at'] ),
		);
	}

	/**
	 * A page of the feed, newest first.
	 *
	 * @param int $before_id Only rows older than this id; 0 for the newest.
	 * @param int $limit     Most rows.
	 * @return list<array<string, mixed>>
	 */
	public function page( int $before_id, int $limit ): array {
		global $wpdb;
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE ( %d = 0 OR id < %d ) ORDER BY id DESC LIMIT %d', $this->table_name(), $before_id, $before_id, $limit ),
				ARRAY_A
			)
		);
	}

	/**
	 * The newest row for one verb on one object, if any.
	 *
	 * @param string $verb        Verb.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return array<string, mixed>|null
	 */
	public function last_for( string $verb, string $object_type, int $object_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE verb = %s AND object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1', $this->table_name(), $verb, $object_type, $object_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete rows older than a number of days (the activity_days setting).
	 *
	 * @param int $days Age in days.
	 */
	public function prune( int $days ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table_name(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) )
		);
	}
}
