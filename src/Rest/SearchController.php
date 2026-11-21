<?php
/**
 * Quick search API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\DecisionRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /search?q= across tasks, projects, decisions and chat, grouped and
 * capped the way the quick-search panel shows them.
 */
final class SearchController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/search', WP_REST_Server::READABLE, array( $this, 'search' ), array( 'q' => self::line( 100, true ) ) );
	}

	/**
	 * Search everything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $q ) {
			return self::respond(
				array(
					'tasks'     => array(),
					'projects'  => array(),
					'decisions' => array(),
					'messages'  => array(),
				)
			);
		}
		$tasks     = new TaskRepository();
		$projects  = new ProjectRepository();
		$decisions = new DecisionRepository();
		$messages  = new MessageRepository();
		return self::respond(
			array(
				'tasks'     => $tasks->present_many( $tasks->search( $q, 6 ) ),
				'projects'  => $projects->present_many( $projects->search( $q, 4 ) ),
				'decisions' => $decisions->present_many( $decisions->search( $q, 4 ) ),
				'messages'  => array_reverse( $messages->present_many( $messages->page( 0, 4, $q ) ) ),
			)
		);
	}
}
