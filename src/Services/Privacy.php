<?php
/**
 * Personal data: WordPress's exporter, eraser and privacy-policy text.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.

namespace NovaWork\Services;

use NovaWork\Data\ActivityRepository;
use NovaWork\Data\AttachmentRepository;
use NovaWork\Data\CommentRepository;
use NovaWork\Data\Db;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\InviteRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\Repository;
use NovaWork\Data\SubtaskRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Members;
use NovaWork\Settings;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A member's chat messages, comments and time entries are personal data
 * (WordPress.org requirement). Tools → Export Personal Data gets one section
 * per kind; Tools → Erase Personal Data removes what is theirs (messages
 * with files, comments, time, votes, notifications, feed rows, invites,
 * read markers, profile details) and unlinks them from shared work: tasks,
 * projects and decisions they created stay with the team without their
 * name. Both work in pages, as WordPress asks.
 */
final class Privacy {

	private const PAGE = 100;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}

	/**
	 * Register the exporters.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function exporters( array $exporters ): array {
		$sections = array(
			'messages'      => __( 'NovaWork chat messages', 'novawork' ),
			'comments'      => __( 'NovaWork comments', 'novawork' ),
			'time'          => __( 'NovaWork time entries', 'novawork' ),
			'votes'         => __( 'NovaWork votes', 'novawork' ),
			'notifications' => __( 'NovaWork notifications', 'novawork' ),
			'activity'      => __( 'NovaWork team feed', 'novawork' ),
			'profile'       => __( 'NovaWork profile', 'novawork' ),
		);
		foreach ( $sections as $key => $label ) {
			$exporters[ 'novawork-' . $key ] = array(
				'exporter_friendly_name' => $label,
				'callback'               => static fn( string $email, int $page = 1 ): array => self::export( $key, $email, max( 1, $page ) ),
			);
		}
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function erasers( array $erasers ): array {
		$erasers['novawork'] = array(
			'eraser_friendly_name' => __( 'NovaWork', 'novawork' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * One page of one section of a person's data.
	 *
	 * @param string $section Section key.
	 * @param string $email   Email address.
	 * @param int    $page    Page, from 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( string $section, string $email, int $page ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$id     = (int) $user->ID;
		$offset = ( $page - 1 ) * self::PAGE;
		$items  = array();
		$rows   = array();
		$group  = array(
			'messages'      => __( 'NovaWork chat messages', 'novawork' ),
			'comments'      => __( 'NovaWork comments', 'novawork' ),
			'time'          => __( 'NovaWork time entries', 'novawork' ),
			'votes'         => __( 'NovaWork votes', 'novawork' ),
			'notifications' => __( 'NovaWork notifications', 'novawork' ),
			'activity'      => __( 'NovaWork team feed', 'novawork' ),
			'profile'       => __( 'NovaWork profile', 'novawork' ),
		)[ $section ] ?? '';

		switch ( $section ) {
			case 'messages':
				$rows  = self::rows( 'messages', 'user_id', $id, $offset );
				$files = ( new AttachmentRepository() )->for_messages( array_map( static fn( $r ) => (int) $r['id'], $rows ) );
				foreach ( $rows as $r ) {
					$items[] = self::item(
						'novawork-messages',
						$group,
						'message-' . $r['id'],
						array(
							__( 'Message', 'novawork' ) => (string) $r['body'],
							__( 'Sent', 'novawork' )    => self::date( $r['created_at'] ),
							__( 'Files', 'novawork' )   => implode( ', ', array_column( $files[ (int) $r['id'] ] ?? array(), 'name' ) ),
						)
					);
				}
				break;
			case 'comments':
				$rows = self::rows( 'comments', 'user_id', $id, $offset );
				foreach ( $rows as $r ) {
					$items[] = self::item(
						'novawork-comments',
						$group,
						'comment-' . $r['id'],
						array(
							__( 'Comment', 'novawork' ) => (string) $r['body'],
							__( 'On', 'novawork' )      => self::title( (string) $r['object_type'], (int) $r['object_id'] ),
							__( 'Written', 'novawork' ) => self::date( $r['created_at'] ),
						)
					);
				}
				break;
			case 'time':
				$rows = self::rows( 'time_entries', 'user_id', $id, $offset );
				foreach ( $rows as $r ) {
					$end     = $r['ended_at'] ? strtotime( $r['ended_at'] . ' UTC' ) : time();
					$items[] = self::item(
						'novawork-time',
						$group,
						'time-' . $r['id'],
						array(
							__( 'Task', 'novawork' )    => self::title( 'task', (int) $r['task_id'] ),
							__( 'Started', 'novawork' ) => self::date( $r['started_at'] ),
							__( 'Ended', 'novawork' )   => $r['ended_at'] ? self::date( $r['ended_at'] ) : __( 'Still running', 'novawork' ),
							__( 'Minutes', 'novawork' ) => (string) max( 0, (int) round( ( $end - strtotime( $r['started_at'] . ' UTC' ) ) / 60 ) ),
						)
					);
				}
				break;
			case 'votes':
				$rows = self::rows( 'decision_votes', 'user_id', $id, $offset, 'decision_id' );
				foreach ( $rows as $r ) {
					$items[] = self::item(
						'novawork-votes',
						$group,
						'vote-' . $r['decision_id'],
						array(
							__( 'Decision', 'novawork' ) => self::title( 'decision', (int) $r['decision_id'] ),
							__( 'Option', 'novawork' )   => (string) $r['option_key'],
							__( 'When', 'novawork' )     => self::date( $r['updated_at'] ),
						)
					);
				}
				break;
			case 'notifications':
				$rows = self::rows( 'notifications', 'user_id', $id, $offset );
				foreach ( $rows as $r ) {
					$data    = json_decode( (string) $r['data'], true );
					$items[] = self::item(
						'novawork-notifications',
						$group,
						'notification-' . $r['id'],
						array(
							__( 'Kind', 'novawork' )  => (string) $r['type'],
							__( 'About', 'novawork' ) => (string) ( $data['title'] ?? $data['question'] ?? $data['excerpt'] ?? '' ),
							__( 'Sent', 'novawork' )  => self::date( $r['created_at'] ),
							__( 'Read', 'novawork' )  => $r['read_at'] ? self::date( $r['read_at'] ) : __( 'No', 'novawork' ),
						)
					);
				}
				break;
			case 'activity':
				$rows = self::rows( 'activity', 'actor_id', $id, $offset );
				foreach ( $rows as $r ) {
					$data    = json_decode( (string) $r['data'], true );
					$items[] = self::item(
						'novawork-activity',
						$group,
						'activity-' . $r['id'],
						array(
							__( 'What', 'novawork' ) => (string) $r['verb'],
							__( 'On', 'novawork' )   => (string) ( $data['title'] ?? '' ),
							__( 'When', 'novawork' ) => self::date( $r['created_at'] ),
						)
					);
				}
				break;
			case 'profile':
				if ( 1 === $page ) {
					$items[] = self::item(
						'novawork-profile',
						$group,
						'profile-' . $id,
						array(
							__( 'Workspace', 'novawork' ) => Settings::workspace_name(),
							__( 'Member', 'novawork' )    => \NovaWork\Access\Capabilities::is_member( $id ) ? __( 'Yes', 'novawork' ) : __( 'No', 'novawork' ),
							__( 'Colour', 'novawork' )    => (string) get_user_meta( $id, Members::COLOR_META, true ),
							__( 'Last seen', 'novawork' ) => self::ms_date( (int) get_user_meta( $id, Members::SEEN_META, true ) ),
						)
					);
				}
				return array(
					'data' => $items,
					'done' => true,
				);
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE,
		);
	}

	/**
	 * Erase one page of a person's NovaWork data. Called again by WordPress
	 * until it reports done.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: each call removes what is left).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$id      = (int) $user->ID;
		$budget  = 200;
		$removed = 0;

		// What is theirs goes, through the repositories so every open app
		// hears about it.
		$deletions = array(
			array( new MessageRepository(), 'messages', 'user_id' ),
			array( new CommentRepository(), 'comments', 'user_id' ),
			array( new TimeEntryRepository(), 'time_entries', 'user_id' ),
			array( new NotificationRepository(), 'notifications', 'user_id' ),
			array( new ActivityRepository(), 'activity', 'actor_id' ),
		);
		foreach ( $deletions as list( $repo, $table, $column ) ) {
			foreach ( self::ids( $table, $column, $id, $budget ) as $row_id ) {
				$repo->delete( $row_id );
				++$removed;
				--$budget;
			}
			if ( $budget <= 0 ) {
				return self::erased( $removed > 0, false );
			}
		}

		global $wpdb;
		// Votes, read markers, files they uploaded but never sent, invites to
		// their address.
		$removed    += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Db::table( 'decision_votes' ), $id ) );
		$removed    += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Db::table( 'reads' ), $id ) );
		$attachments = new AttachmentRepository();
		foreach ( self::ids( 'attachments', 'user_id', $id, 500 ) as $file_id ) {
			$attachments->delete( $file_id );
			++$removed;
		}
		$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', Db::table( 'invites' ), (string) $user->user_email ) );

		// Shared work stays with the team, no longer naming them.
		( new TaskRepository() )->unassign_everywhere( $id );
		$retained = self::unlink( new TaskRepository(), 'tasks', array( 'created_by' ), $id )
			+ self::unlink( new SubtaskRepository(), 'subtasks', array( 'created_by' ), $id )
			+ self::unlink( new ProjectRepository(), 'projects', array( 'created_by' ), $id )
			+ self::unlink( new DecisionRepository(), 'decisions', array( 'created_by', 'owner_id', 'decided_by' ), $id );
		self::unlink( new NotificationRepository(), 'notifications', array( 'actor_id' ), $id );
		self::unlink( new InviteRepository(), 'invites', array( 'created_by', 'used_by' ), $id );

		foreach ( array( Members::COLOR_META, Members::SEEN_META, Members::ONLINE_SINCE_META ) as $key ) {
			delete_user_meta( $id, $key );
		}

		return self::erased( $removed > 0, $retained > 0, true );
	}

	/**
	 * The eraser's answer.
	 *
	 * @param bool $removed  Something was removed.
	 * @param bool $retained Shared work was kept.
	 * @param bool $done     Nothing left to do.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	private static function erased( bool $removed, bool $retained, bool $done = false ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'NovaWork: tasks, projects and decisions this person created stay with the team, no longer linked to them.', 'novawork' ) ) : array(),
			'done'           => $done,
		);
	}

	/**
	 * Clear a person from some columns of a table's rows.
	 *
	 * @param Repository $repo    Repository (logs each change for the apps).
	 * @param string     $table   Table without prefix.
	 * @param string[]   $columns Columns that may name them.
	 * @param int        $id      The person.
	 * @return int Rows changed.
	 */
	private static function unlink( Repository $repo, string $table, array $columns, int $id ): int {
		global $wpdb;
		$changed = 0;
		foreach ( $columns as $column ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %d', Db::table( $table ), $column, $id ) );
			foreach ( array_map( 'intval', (array) $ids ) as $row_id ) {
				$repo->update( $row_id, array( $column => 0 ) );
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Ids of a person's rows.
	 *
	 * @param string $table  Table without prefix.
	 * @param string $column Column naming the person.
	 * @param int    $id     The person.
	 * @param int    $limit  Most rows.
	 * @return list<int>
	 */
	private static function ids( string $table, string $column, int $id, int $limit ): array {
		global $wpdb;
		if ( $limit <= 0 ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %d ORDER BY id LIMIT %d', Db::table( $table ), $column, $id, $limit ) ) );
	}

	/**
	 * A page of a person's rows.
	 *
	 * @param string $table  Table without prefix.
	 * @param string $column Column naming the person.
	 * @param int    $id     The person.
	 * @param int    $offset First row.
	 * @param string $order  Column to order by.
	 * @return list<array<string, mixed>>
	 */
	private static function rows( string $table, string $column, int $id, int $offset, string $order = 'id' ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d ORDER BY %i LIMIT %d OFFSET %d', Db::table( $table ), $column, $id, $order, self::PAGE, $offset ),
			ARRAY_A
		);
		return array_values( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One export item.
	 *
	 * @param string                $group_id Group id.
	 * @param string                $label    Group label.
	 * @param string                $item_id  Item id.
	 * @param array<string, string> $fields   Name => value.
	 * @return array<string, mixed>
	 */
	private static function item( string $group_id, string $label, string $item_id, array $fields ): array {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		return array(
			'group_id'    => $group_id,
			'group_label' => $label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/**
	 * A stored UTC time in the site's format.
	 *
	 * @param mixed $value DATETIME.
	 */
	private static function date( mixed $value ): string {
		return is_string( $value ) && '' !== $value ? (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $value . ' UTC' ) ) : '';
	}

	/**
	 * Epoch ms in the site's format.
	 *
	 * @param int $ms Milliseconds.
	 */
	private static function ms_date( int $ms ): string {
		return $ms > 0 ? (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), intdiv( $ms, 1000 ) ) : '';
	}

	/**
	 * A task's or decision's title, or a placeholder when it is gone.
	 *
	 * @param string $type "task" or "decision".
	 * @param int    $id   Id.
	 */
	private static function title( string $type, int $id ): string {
		$row = 'decision' === $type ? ( new DecisionRepository() )->find( $id ) : ( new TaskRepository() )->find( $id );
		if ( ! $row ) {
			return __( '(deleted)', 'novawork' );
		}
		return (string) ( $row['title'] ?? $row['question'] ?? '' );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$html  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for a site that uses NovaWork. Edit it to match how you use the workspace.', 'novawork' ) . '</p>';
		$html .= '<h2>' . esc_html__( 'Team workspace', 'novawork' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Members of our team workspace use it to plan work, track time and talk. It stores what members write and do there: tasks and projects, decisions and votes, comments, chat messages and shared files, time tracked, notifications, and when a member was last online. Only signed-in members can see the workspace; files are private to it and never public.', 'novawork' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Notifications are kept for a time set by the site administrator once read; the team activity feed and the change history are kept for a set number of days. Members can ask for an export or erasure of their data through the site administrator.', 'novawork' ) . '</p>';
		$html .= '<p>' . esc_html__( 'The workspace remembers some preferences in the browser (local storage), such as light or dark appearance and the last filters used. These never leave the device.', 'novawork' ) . '</p>';
		$html .= '<h2>' . esc_html__( 'Services from other companies', 'novawork' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Fonts: when the site loads its typefaces from Fontshare (Indian Type Foundry), the browser requests the font files from Fontshare each time the workspace opens, which shares the visitor’s IP address with Fontshare.', 'novawork' ) . ' <a href="https://www.fontshare.com/terms">' . esc_html__( 'Fontshare terms', 'novawork' ) . '</a>.</p>';
		$html .= '<p>' . esc_html__( 'Profile pictures: members’ avatars may come from Gravatar (Automattic), which receives a hashed form of the member’s email address.', 'novawork' ) . ' <a href="https://automattic.com/privacy/">' . esc_html__( 'Automattic privacy policy', 'novawork' ) . '</a>.</p>';
		wp_add_privacy_policy_content( 'NovaWork', wp_kses_post( $html ) );
	}
}
