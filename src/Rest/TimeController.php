<?php
/**
 * Timer and tracked-time API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Services\TimeEdits;
use NovaWork\Services\Timer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /timer/start, POST /timer/stop, GET /time-entries.
 *
 * Members can see everyone's tracked time (the Time view compares people),
 * but only start and stop their own timer.
 */
final class TimeController extends Controller {

	/**
	 * Longest range /time-entries returns, in days (a year plus a margin).
	 */
	private const MAX_RANGE_DAYS = 400;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/timer/start', WP_REST_Server::CREATABLE, array( $this, 'start' ), array( 'taskId' => array_merge( self::ref( true ), array( 'minimum' => 1 ) ) ) );
		$this->route(
			'/timer/stop',
			WP_REST_Server::CREATABLE,
			array( $this, 'stop' ),
			array(
				'endedAt' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				// The app stopped it on its own (no answer to "Still working?",
				// or the device slept): ask the member later (1.2.0).
				'reason'  => array(
					'type' => 'string',
					'enum' => array( 'idle', 'sleep' ),
				),
			)
		);
		// "Were you working?" (1.2.0): put back time after an automatic stop, or not.
		$this->route(
			'/timer/recover',
			WP_REST_Server::CREATABLE,
			array( $this, 'recover' ),
			array(
				'until' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'keep'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$this->route( '/timer/dismiss', WP_REST_Server::CREATABLE, array( $this, 'dismiss' ) );
		// Administrators fix time by hand (1.2.0).
		$span = array(
			'taskId' => array_merge( self::ref(), array( 'minimum' => 1 ) ),
			'start'  => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'end'    => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/time-entries',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_entry' ),
				'permission_callback' => array( $this, 'can_edit_time' ),
				'args'                => array_merge( $span, array( 'userId' => array_merge( self::ref( true ), array( 'minimum' => 1 ) ) ) ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/time-entries/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_entry' ),
					'permission_callback' => array( $this, 'can_edit_time' ),
					'args'                => $span,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => array( $this, 'can_edit_time' ),
				),
			)
		);
		$this->route(
			'/time-entries',
			WP_REST_Server::READABLE,
			array( $this, 'entries' ),
			array(
				'from' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'to'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'user' => self::ref(),
			)
		);
	}

	/**
	 * Start tracking a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Timer::start( self::me(), (int) $request->get_param( 'taskId' ) );
		return $result instanceof WP_Error ? $result : self::respond( $result );
	}

	/**
	 * Stop tracking. endedAt (epoch ms) ends the session at the last activity
	 * after an idle pause, so away time is never counted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function stop( WP_REST_Request $request ): WP_REST_Response {
		$end = $request->get_param( 'endedAt' );
		return self::respond( array( 'entry' => Timer::stop( self::me(), null === $end ? null : (int) $end, (string) $request->get_param( 'reason' ) ) ) );
	}

	/**
	 * "Count it": put back time NovaWork stopped on its own.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function recover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = TimeEdits::recover( self::me(), (int) $request->get_param( 'until' ), (bool) $request->get_param( 'keep' ) );
		return $session instanceof WP_Error ? $session : self::respond( $session );
	}

	/**
	 * "Don't count it".
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		TimeEdits::dismiss( self::me() );
		return self::respond( array( 'dismissed' => true ) );
	}

	/**
	 * Only administrators change tracked time by hand.
	 */
	public function can_edit_time(): bool|WP_Error {
		$member = $this->can_access();
		if ( true !== $member ) {
			return $member;
		}
		return current_user_can( Capabilities::MANAGE )
			? true
			: new WP_Error( 'novawork_forbidden', __( 'Only an administrator can change tracked time.', 'novawork' ), array( 'status' => 403 ) );
	}

	/**
	 * An administrator adds a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_entry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = (int) $request->get_param( 'userId' );
		if ( ! Capabilities::is_member( $user ) ) {
			return new WP_Error( 'novawork_bad_member', __( 'That person isn’t a member.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( null === $request->get_param( 'start' ) || null === $request->get_param( 'end' ) || ! $request->get_param( 'taskId' ) ) {
			return new WP_Error( 'novawork_required', __( 'Pick a task, a start and an end.', 'novawork' ), array( 'status' => 400 ) );
		}
		$session = TimeEdits::create( $user, (int) $request->get_param( 'taskId' ), (int) $request->get_param( 'start' ), (int) $request->get_param( 'end' ) );
		return $session instanceof WP_Error ? $session : self::respond( $session, 201 );
	}

	/**
	 * An administrator changes a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_entry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fields = array();
		foreach ( array( 'taskId', 'start', 'end' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$fields[ $key ] = (int) $request->get_param( $key );
			}
		}
		$session = TimeEdits::change( self::id( $request ), $fields );
		return $session instanceof WP_Error ? $session : self::respond( $session );
	}

	/**
	 * An administrator deletes a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_entry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gone = TimeEdits::remove( self::id( $request ) );
		return $gone instanceof WP_Error ? $gone : self::respond(
			array(
				'deleted' => $gone['id'],
				'session' => $gone,
			)
		);
	}

	/**
	 * Entries overlapping a range (epoch ms), for the Time view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$from = (int) $request->get_param( 'from' );
		$to   = (int) ( $request->get_param( 'to' ) ?? Db::now_ms() );
		if ( $to <= $from ) {
			return new WP_Error( 'rest_invalid_param', __( 'The end of the range must come after its start.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( $to - $from > self::MAX_RANGE_DAYS * DAY_IN_SECONDS * 1000 ) {
			return new WP_Error( 'rest_invalid_param', __( 'Ask for a year or less at a time.', 'novawork' ), array( 'status' => 400 ) );
		}
		$user = (int) $request->get_param( 'user' );
		if ( $user > 0 && ! Capabilities::is_member( $user ) ) {
			return new WP_Error( 'novawork_bad_member', __( 'That person isn’t a member.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo = new TimeEntryRepository();
		return self::respond( $repo->present_many( $repo->overlapping( Db::from_ms( $from ), Db::from_ms( $to ), $user > 0 ? $user : null ) ) );
	}
}
