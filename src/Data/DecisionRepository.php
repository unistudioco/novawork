<?php
/**
 * Decisions.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

/**
 * Questions that block work, and the answer once someone decides.
 */
final class DecisionRepository extends Repository {

	public const STATUSES = array( 'open', 'decided' );

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'decisions';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'decision';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'project_id' => '%d',
			'question'   => '%s',
			'context'    => '%s',
			'owner_id'   => '%d',
			'decide_by'  => '%s',
			'status'     => '%s',
			'answer'     => '%s',
			'decided_at' => '%s',
			'decided_by' => '%d',
			'sort_order' => '%d',
			'is_sample'  => '%d',
			'created_by' => '%d',
			'created_at' => '%s',
			'updated_at' => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'context' => '',
			'answer'  => '',
			'status'  => 'open',
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
			'question'  => (string) $row['question'],
			'context'   => (string) $row['context'],
			'projectId' => (int) $row['project_id'],
			'ownerId'   => (int) $row['owner_id'],
			'decideBy'  => Db::date_out( $row['decide_by'] ),
			'status'    => (string) $row['status'],
			'answer'    => (string) $row['answer'],
			'decidedAt' => Db::to_ms( $row['decided_at'] ),
			'decidedBy' => (int) $row['decided_by'],
			'order'     => (int) $row['sort_order'],
			'sample'    => (bool) $row['is_sample'],
			'createdBy' => (int) $row['created_by'],
			'createdAt' => Db::to_ms( $row['created_at'] ),
			'updatedAt' => Db::to_ms( $row['updated_at'] ),
		);
	}

	/**
	 * Every decision, in list order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		return self::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $this->table_name() ), ARRAY_A ) );
	}

	/**
	 * Open decisions with a decide-by date on or before a day.
	 *
	 * @param string $date Y-m-d.
	 * @return list<array<string, mixed>>
	 */
	public function open_due_by( string $date ): array {
		global $wpdb;
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM %i WHERE status = 'open' AND decide_by IS NOT NULL AND decide_by <= %s", $this->table_name(), $date ),
				ARRAY_A
			)
		);
	}

	/**
	 * Decisions whose question contains a phrase.
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
				$wpdb->prepare( 'SELECT * FROM %i WHERE question LIKE %s OR context LIKE %s OR answer LIKE %s ORDER BY sort_order ASC, id ASC LIMIT %d', $this->table_name(), $like, $like, $like, $limit ),
				ARRAY_A
			)
		);
	}

	/**
	 * Delete a decision with its comments.
	 *
	 * @param int $id Decision id.
	 */
	public function delete( int $id ): void {
		( new CommentRepository() )->delete_for( 'decision', $id );
		parent::delete( $id );
	}

	/**
	 * Clear a deleted project from its decisions.
	 *
	 * @param int $project_id Project id.
	 */
	public function unlink_project( int $project_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE project_id = %d', $this->table_name(), $project_id ) );
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->update( $id, array( 'project_id' => 0 ) );
		}
	}
}
