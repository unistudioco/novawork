<?php
/**
 * Workspace activity API: older rows of the team feed.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\ActivityRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /activity. The newest rows arrive with /sync; this pages back in time.
 */
final class ActivityController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/activity',
			WP_REST_Server::READABLE,
			array( $this, 'history' ),
			array(
				'beforeId' => self::ref(),
				'limit'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			)
		);
	}

	/**
	 * Rows older than an id, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$repo = new ActivityRepository();
		return self::respond( $repo->present_many( $repo->page( (int) $request->get_param( 'beforeId' ), (int) $request->get_param( 'limit' ) ) ) );
	}
}
