<?php
/**
 * Invites API for the settings screen.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\InviteRepository;
use NovaWork\Services\Invites;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET|POST /invites, DELETE /invites/{id}, administrators only. A new
 * invite's link is returned once; only its hash is kept.
 */
final class InvitesController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->admin_route( '/invites', WP_REST_Server::READABLE, array( $this, 'list' ) );
		$this->admin_route(
			'/invites',
			WP_REST_Server::CREATABLE,
			array( $this, 'create' ),
			array(
				'email' => self::line( 100 ),
				'send'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$this->admin_route( '/invites/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'revoke' ) );
	}

	/**
	 * Recent invites, open ones first.
	 */
	public function list(): WP_REST_Response {
		$repo = new InviteRepository();
		return self::respond( $repo->present_many( $repo->recent() ) );
	}

	/**
	 * Create an invite (and email it when asked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$made = Invites::create( trim( (string) $request->get_param( 'email' ) ), (bool) $request->get_param( 'send' ), self::me() );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		return self::respond(
			array(
				'invite' => ( new InviteRepository() )->present( $made['row'] ),
				'link'   => $made['link'],
				'sent'   => $made['sent'],
			),
			201
		);
	}

	/**
	 * Revoke an open invite.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new InviteRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row ) {
			return self::not_found();
		}
		$repo->delete( (int) $row['id'] );
		return self::respond( array( 'deleted' => (int) $row['id'] ) );
	}
}
