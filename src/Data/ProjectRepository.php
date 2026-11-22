<?php
/**
 * Projects.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Projects: name, colour, status, dates and goal.
 */
final class ProjectRepository extends Repository {

	public const STATUSES = array( 'active', 'paused', 'done' );

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'projects';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'project';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'name'        => '%s',
			'goal'        => '%s',
			'color'       => '%s',
			'status'      => '%s',
			'start_date'  => '%s',
			'target_date' => '%s',
			'deadline'    => '%s',
			'sort_order'  => '%d',
			'is_sample'   => '%d',
			'created_by'  => '%d',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'goal'   => '',
			'status' => 'active',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return array(
			'id'        => (int) $row['id'],
			'name'      => (string) $row['name'],
			'goal'      => (string) $row['goal'],
			'color'     => (string) $row['color'],
			'status'    => (string) $row['status'],
			'start'     => Db::date_out( $row['start_date'] ),
			'target'    => Db::date_out( $row['target_date'] ),
			'deadline'  => Db::date_out( $row['deadline'] ),
			'order'     => (int) $row['sort_order'],
			'sample'    => (bool) $row['is_sample'],
			'createdBy' => (int) $row['created_by'],
			'createdAt' => Db::to_ms( $row['created_at'] ),
			'updatedAt' => Db::to_ms( $row['updated_at'] ),
		);
	}

	/**
	 * Every project, in board order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		return self::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $this->table_name() ), ARRAY_A ) );
	}

	/**
	 * Projects whose name or goal contains a phrase.
	 *
	 * @param string $phrase Search phrase.
	 * @param int    $limit  Most rows.
	 * @return list<array<string, mixed>>
	 */
	public function search( string $phrase, int $limit ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $phrase ) . '%';
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE name LIKE %s OR goal LIKE %s ORDER BY sort_order ASC, id ASC LIMIT %d', $this->table_name(), $like, $like, $limit ),
				ARRAY_A
			)
		);
	}
}
