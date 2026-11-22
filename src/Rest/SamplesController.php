<?php
/**
 * Sample items API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Services\Samples;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * DELETE /samples removes the first run's sample items; administrators only.
 */
final class SamplesController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->admin_route( '/samples', WP_REST_Server::DELETABLE, array( $this, 'remove' ) );
	}

	/**
	 * Remove them all.
	 */
	public function remove(): WP_REST_Response {
		return self::respond( array( 'removed' => Samples::remove() ) );
	}
}
