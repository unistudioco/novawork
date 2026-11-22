<?php
/**
 * Decisions.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

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
			'project_id'   => '%d',
			'question'     => '%s',
			'context'      => '%s',
			'owner_id'     => '%d',
			'decide_by'    => '%s',
			'status'       => '%s',
			'answer'       => '%s',
			'voting'       => '%d',
			'vote_options' => '%s',
			'decided_at'   => '%s',
			'decided_by'   => '%d',
			'sort_order'   => '%d',
			'is_sample'    => '%d',
			'created_by'   => '%d',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'context'      => '',
			'answer'       => '',
			'status'       => 'open',
			'vote_options' => '[]',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return $this->present_many( array( $row ) )[0];
	}

	/**
	 * API shapes, with every decision's votes loaded in one query.
	 *
	 * Votes are advisory (owner decision): members pick one option each and
	 * can change their mind; the person who decides sees the tally.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<array<string, mixed>>
	 */
	public function present_many( array $rows ): array {
		$votes = $this->votes_of( array_map( static fn( $row ) => (int) $row['id'], $rows ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$out[] = array(
				'id'        => $id,
				'question'  => (string) $row['question'],
				'context'   => (string) $row['context'],
				'projectId' => (int) $row['project_id'],
				'ownerId'   => (int) $row['owner_id'],
				'decideBy'  => Db::date_out( $row['decide_by'] ),
				'status'    => (string) $row['status'],
				'answer'    => (string) $row['answer'],
				'voting'    => (bool) $row['voting'],
				'options'   => self::options( $row ),
				'votes'     => (object) ( $votes[ $id ] ?? array() ),
				'decidedAt' => Db::to_ms( $row['decided_at'] ),
				'decidedBy' => (int) $row['decided_by'],
				'order'     => (int) $row['sort_order'],
				'sample'    => (bool) $row['is_sample'],
				'createdBy' => (int) $row['created_by'],
				'createdAt' => Db::to_ms( $row['created_at'] ),
				'updatedAt' => Db::to_ms( $row['updated_at'] ),
			);
		}
		return $out;
	}

	/**
	 * A decision's vote options: [ { key, label }, … ].
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return list<array{key: string, label: string}>
	 */
	public static function options( array $row ): array {
		$list = json_decode( (string) ( $row['vote_options'] ?? '[]' ), true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $o ) {
			if ( is_array( $o ) && isset( $o['key'], $o['label'] ) ) {
				$out[] = array(
					'key'   => (string) $o['key'],
					'label' => (string) $o['label'],
				);
			}
		}
		return $out;
	}

	/**
	 * Votes per decision: [ decision_id => [ user_id => option_key ] ].
	 *
	 * @param int[] $ids Decision ids.
	 * @return array<int, array<int, string>>
	 */
	public function votes_of( array $ids ): array {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT decision_id, user_id, option_key FROM %i WHERE decision_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( Db::table( 'decision_votes' ) ), $ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( self::rows( $rows ) as $row ) {
			$out[ (int) $row['decision_id'] ][ (int) $row['user_id'] ] = (string) $row['option_key'];
		}
		return $out;
	}

	/**
	 * Cast, change or withdraw a member's vote.
	 *
	 * @param int         $decision_id Decision.
	 * @param int         $user_id     Member.
	 * @param string|null $key         Option key, or null to withdraw.
	 */
	public function vote( int $decision_id, int $user_id, ?string $key ): void {
		global $wpdb;
		$table = Db::table( 'decision_votes' );
		if ( null === $key ) {
			$wpdb->delete(
				$table,
				array(
					'decision_id' => $decision_id,
					'user_id'     => $user_id,
				),
				array( '%d', '%d' )
			);
		} else {
			$wpdb->replace(
				$table,
				array(
					'decision_id' => $decision_id,
					'user_id'     => $user_id,
					'option_key'  => $key,
					'updated_at'  => Db::now(),
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
		$this->touch( $decision_id );
	}

	/**
	 * Drop votes for options that no longer exist.
	 *
	 * @param int      $decision_id Decision.
	 * @param string[] $keys        Keys that remain.
	 */
	public function keep_votes( int $decision_id, array $keys ): void {
		global $wpdb;
		$table = Db::table( 'decision_votes' );
		if ( ! $keys ) {
			$wpdb->delete( $table, array( 'decision_id' => $decision_id ), array( '%d' ) );
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE decision_id = %d AND option_key NOT IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')',
				array_merge( array( $table, $decision_id ), array_values( $keys ) )
			)
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
		$this->keep_votes( $id, array() );
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
