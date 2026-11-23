<?php
/**
 * Welcome tour API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /tour/seen: the member finished or skipped the welcome tour, so it
 * no longer starts by itself (owner, 2026-11-23). It is remembered per
 * member, not per device, so a second laptop does not show it again. Anyone
 * can take the tour again from their menu.
 */
final class TourController extends Controller {

	/**
	 * User meta: when the member last saw the tour (epoch seconds).
	 */
	public const SEEN_META = 'novawork_tour_seen';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/tour/seen', WP_REST_Server::CREATABLE, array( $this, 'seen' ) );
	}

	/**
	 * Whether the tour should start by itself for a member: the setting is
	 * on and they have not finished or skipped it before.
	 *
	 * @param int $user_id Member.
	 */
	public static function should_start( int $user_id ): bool {
		return (bool) Settings::get( 'show_tour' ) && ! get_user_meta( $user_id, self::SEEN_META, true );
	}

	/**
	 * Remember that the member has seen the tour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function seen( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		update_user_meta( self::me(), self::SEEN_META, time() );
		return self::respond( array( 'seen' => true ) );
	}
}
