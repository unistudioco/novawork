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
		return self::respond( array( 'entry' => Timer::stop( self::me(), null === $end ? null : (int) $end ) ) );
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
