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
	 * Save an edit of a chat message or comment: the new text, its mentions
	 * and links (kept only when really in the text), and the edit time. The
	 * people and items it newly mentions or links are told, as for a new post.
	 *
	 * @param \NovaWork\Data\CommentRepository|\NovaWork\Data\MessageRepository $repo   Repository.
	 * @param array<string, mixed>                                              $row    Row before the edit.
	 * @param string                                                            $text   New text.
	 * @param mixed                                                             $people Mentioned member ids sent.
	 * @param mixed                                                             $links  Links sent.
	 * @return array<string, mixed> The row after the edit.
	 */
	public static function save_edit( $repo, array $row, string $text, mixed $people, mixed $links ): array {
		if ( (string) $row['body'] === $text ) {
			return $row;
		}
		$mentions = self::mentions( $text, $people ?? array() );
		$refs     = self::links( $text, $links ?? array() );
		$repo->update(
			(int) $row['id'],
			array(
				'body'      => $text,
				'mentions'  => (string) wp_json_encode( $mentions ),
				'refs'      => (string) wp_json_encode( $refs ),
				'edited_at' => \NovaWork\Data\Db::now(),
			)
		);
		$after = (array) $repo->find( (int) $row['id'] );

		$before_people = array_column( self::decode( $row['mentions'] ), 'id' );
		$new_people    = array_values( array_diff( array_column( $mentions, 'id' ), $before_people ) );
		$kind          = $repo instanceof \NovaWork\Data\CommentRepository ? 'comment' : 'message';
		if ( $new_people ) {
			\NovaWork\Services\Notifier::mentioned( $kind, $after, $new_people );
		}
		$before_links = array_map( static fn( $l ) => $l['type'] . ':' . $l['id'], self::decode( $row['refs'] ) );
		$new_links    = array_values( array_filter( $refs, static fn( $l ) => ! in_array( $l['type'] . ':' . $l['id'], $before_links, true ) ) );
		if ( $new_links ) {
			self::notify_links( $new_links, (int) $row['user_id'], $text, array_column( $mentions, 'id' ) );
		}
		return $after;
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
