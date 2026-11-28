<?php
/**
 * "Away until…" API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Members;
use NovaWork\Services\Away;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * PUT /away {until, note, userId?} sets it; DELETE /away {userId?} ends it.
 * A member changes their own; administrators anyone's (owner, 2026-11-28).
 * Both answer with the member as the app shows them (with "away").
 */
final class AwayController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$who = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$this->route(
			'/away',
			WP_REST_Server::EDITABLE,
			array( $this, 'set' ),
			array(
				'until'  => self::date() + array( 'required' => true ),
				'note'   => self::line( Away::NOTE_MAX ),
				'userId' => $who,
			)
		);
		$this->route( '/away', WP_REST_Server::DELETABLE, array( $this, 'clear' ), array( 'userId' => $who ) );
	}

	/**
	 * Set someone away.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = $this->target( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$away = Away::set( $user, (string) $request->get_param( 'until' ), (string) $request->get_param( 'note' ), self::me() );
		return is_wp_error( $away ) ? $away : self::respond( Members::present( $user ) );
	}

	/**
	 * End someone's away status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function clear( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = $this->target( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		Away::clear( $user, self::me() );
		return self::respond( Members::present( $user ) );
	}

	/**
	 * Whose status: your own, or (administrators) another member's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function target( WP_REST_Request $request ): int|WP_Error {
		$me   = self::me();
		$user = (int) $request->get_param( 'userId' );
		if ( $user <= 0 || $user === $me ) {
			return $me;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'novawork_forbidden', __( 'Only administrators can change someone else’s away status.', 'novawork' ), array( 'status' => 403 ) );
		}
		if ( ! Capabilities::is_member( $user ) ) {
			return new WP_Error( 'novawork_not_member', __( 'That person isn’t a member of the workspace.', 'novawork' ), array( 'status' => 400 ) );
		}
		return $user;
	}
}
