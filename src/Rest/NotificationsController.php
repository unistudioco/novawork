<?php
/**
 * Notifications API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\NotificationRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /notifications/read. A member can only touch their own notifications;
 * the repository query is scoped to them. Reads come through /sync.
 */
final class NotificationsController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/notifications/read',
			WP_REST_Server::CREATABLE,
			array( $this, 'read' ),
			array(
				'ids' => self::refs( 500 ),
				'all' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
	}

	/**
	 * Mark some or all of the member's notifications read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		$ids     = $request->get_param( 'all' ) ? null : array_map( 'intval', (array) ( $request->get_param( 'ids' ) ?? array() ) );
		$changed = ( new NotificationRepository() )->mark_read( self::me(), $ids );
		return self::respond( array( 'marked' => $changed ) );
	}
}
