<?php
/**
 * Mentions (@Name) and links (/Title) in chat messages and comments.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Access\Capabilities;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;

/**
 * Owner requests: "@Name" mentions a member; "/Title" links a task, decision
 * or project. The app sends what it inserted; the server keeps only what is
 * really in the text and really exists, and stores the text as typed so a
 * later rename never breaks the message.
 */
final class References {

	public const TYPES = array( 'task', 'decision', 'project' );

	/**
	 * Most links one message or comment may carry.
	 */
	public const MAX_REFS = 10;

	/**
	 * Mentions to keep: members whose "@Name" really is in the text.
	 *
	 * @param string $text Text.
	 * @param mixed  $ids  Member ids the app says were mentioned.
	 * @return list<array{id: int, name: string}>
	 */
	public static function mentions( string $text, mixed $ids ): array {
		$out  = array();
		$seen = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || in_array( $id, $seen, true ) || ! Capabilities::is_member( $id ) ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( $user && false !== mb_stripos( $text, '@' . $user->display_name ) ) {
				$seen[] = $id;
				$out[]  = array(
					'id'   => $id,
					'name' => $user->display_name,
				);
			}
		}
		return $out;
	}

	/**
	 * Links to keep: items that exist and whose "/Title" really is in the text.
	 *
	 * @param string $text Text.
	 * @param mixed  $refs [ { type, id }, … ] as the app sent them.
	 * @return list<array{type: string, id: int, label: string}>
	 */
	public static function links( string $text, mixed $refs ): array {
		$out  = array();
		$seen = array();
		foreach ( (array) $refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				continue;
			}
			$type = (string) ( $ref['type'] ?? '' );
			$id   = (int) ( $ref['id'] ?? 0 );
			$key  = $type . ':' . $id;
			if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) || in_array( $key, $seen, true ) ) {
				continue;
			}
			$label = self::label( $type, $id );
			if ( null === $label || false === mb_stripos( $text, '/' . $label ) ) {
				continue;
			}
			$seen[] = $key;
			$out[]  = array(
				'type'  => $type,
				'id'    => $id,
				'label' => $label,
			);
			if ( count( $out ) >= self::MAX_REFS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Decode a stored list (mentions or links) from a JSON column.
	 *
	 * @param mixed $json Column value.
	 * @return list<array<string, mixed>>
	 */
	public static function decode( mixed $json ): array {
		$list = json_decode( (string) ( $json ?? '[]' ), true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$item['id'] = (int) $item['id'];
				$out[]      = $item;
			}
		}
		return $out;
	}

	/**
	 * Tell the people behind linked items: a task's assignees and a
	 * decision's decider (owner request). Nobody hears twice for one message:
	 * pass whoever was already notified about it.
	 *
	 * @param list<array{type: string, id: int, label: string}> $links   Links.
	 * @param int                                               $author  Who linked.
	 * @param string                                            $excerpt What was said.
	 * @param int[]                                             $skip    Already notified.
	 */
	public static function notify_links( array $links, int $author, string $excerpt, array $skip ): void {
		$tasks     = new TaskRepository();
		$decisions = new DecisionRepository();
		$repo      = new NotificationRepository();
		$told      = array_merge( array( $author ), array_map( 'intval', $skip ) );
		foreach ( $links as $link ) {
			$people = array();
			if ( 'task' === $link['type'] ) {
				$people = $tasks->assignees_of( array( $link['id'] ) )[ $link['id'] ] ?? array();
			} elseif ( 'decision' === $link['type'] ) {
				$row    = $decisions->find( $link['id'] );
				$people = $row && (int) $row['owner_id'] > 0 ? array( (int) $row['owner_id'] ) : array();
			}
			foreach ( $people as $user_id ) {
				if ( in_array( $user_id, $told, true ) || ! Capabilities::is_member( $user_id ) ) {
					continue;
				}
				$told[] = $user_id;
				$repo->insert(
					array(
						'user_id'     => $user_id,
						'type'        => 'link',
						'actor_id'    => $author,
						'object_type' => $link['type'],
						'object_id'   => $link['id'],
						'data'        => (string) wp_json_encode(
							array(
								'title'   => $link['label'],
								'excerpt' => $excerpt,
							)
						),
					)
				);
			}
		}
	}

	/**
	 * The current title of an item, or null when it does not exist.
	 *
	 * @param string $type Item type.
	 * @param int    $id   Item id.
	 */
	private static function label( string $type, int $id ): ?string {
		$row = match ( $type ) {
			'task'     => ( new TaskRepository() )->find( $id ),
			'decision' => ( new DecisionRepository() )->find( $id ),
			default    => ( new ProjectRepository() )->find( $id ),
		};
		if ( ! $row ) {
			return null;
		}
		return (string) match ( $type ) {
			'task'     => $row['title'],
			'decision' => $row['question'],
			default    => $row['name'],
		};
	}
}
