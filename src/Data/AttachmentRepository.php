<?php
/**
 * Private file attachments.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

use NovaWork\Services\Files;

defined( 'ABSPATH' ) || exit;

/**
 * An upload starts unclaimed (object_id 0, visible only to its uploader) and
 * is claimed when the message it belongs to is sent. Unclaimed uploads older
 * than a day are swept by cron. Attachments travel inside their message, so
 * they are not logged on their own.
 */
final class AttachmentRepository extends Repository {

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'attachments';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'attachment';
	}

	/**
	 * Attachments ride inside their message.
	 */
	protected function logged(): bool {
		return false;
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'object_type' => '%s',
			'object_id'   => '%d',
			'user_id'     => '%d',
			'file_name'   => '%s',
			'stored_name' => '%s',
			'mime'        => '%s',
			'size'        => '%d',
			'created_at'  => '%s',
		);
	}

	/**
	 * API shape. The url needs the REST nonce appended by the client.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return array(
			'id'   => (int) $row['id'],
			'name' => (string) $row['file_name'],
			'size' => (int) $row['size'],
			'type' => (string) $row['mime'],
			'url'  => rest_url( 'novawork/v1/attachments/' . (int) $row['id'] . '/file' ),
		);
	}

	/**
	 * Presented attachments grouped by message.
	 *
	 * @param int[] $message_ids Message ids.
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function for_messages( array $message_ids ): array {
		global $wpdb;
		$message_ids = self::ids( $message_ids );
		if ( ! $message_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE object_type = 'message' AND object_id IN (" . implode( ',', array_fill( 0, count( $message_ids ), '%d' ) ) . ') ORDER BY id ASC',
				array_merge( array( $this->table_name() ), $message_ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( self::rows( $rows ) as $row ) {
			$out[ (int) $row['object_id'] ][] = $this->present( $row );
		}
		return $out;
	}

	/**
	 * Attach a member's own unclaimed uploads to an object.
	 *
	 * @param int[]  $ids         Attachment ids.
	 * @param int    $user_id     Uploader.
	 * @param string $object_type Object type, e.g. "message".
	 * @param int    $object_id   Object id.
	 * @return int How many were claimed.
	 */
	public function claim( array $ids, int $user_id, string $object_type, int $object_id ): int {
		$claimed = 0;
		foreach ( $this->find_many( $ids ) as $row ) {
			if ( $user_id !== (int) $row['user_id'] || 0 !== (int) $row['object_id'] ) {
				continue;
			}
			$this->update(
				(int) $row['id'],
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
				)
			);
			++$claimed;
		}
		return $claimed;
	}

	/**
	 * Delete an attachment row and its file.
	 *
	 * @param int $id Attachment id.
	 */
	public function delete( int $id ): void {
		$row = $this->find( $id );
		if ( $row ) {
			Files::remove( (string) $row['stored_name'] );
		}
		parent::delete( $id );
	}

	/**
	 * Delete every attachment of an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 */
	public function delete_for( string $object_type, int $object_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE object_type = %s AND object_id = %d', $this->table_name(), $object_type, $object_id ) );
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->delete( $id );
		}
	}

	/**
	 * Delete uploads that were never sent.
	 *
	 * @param int $older_than_seconds Age in seconds.
	 */
	public function sweep_unclaimed( int $older_than_seconds ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE object_id = 0 AND created_at < %s',
				$this->table_name(),
				gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds )
			)
		);
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->delete( $id );
		}
	}
}
