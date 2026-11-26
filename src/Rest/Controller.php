<?php
/**
 * Shared REST plumbing.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use NovaWork\Data\Repository;
use NovaWork\Members;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Every route lives under novawork/v1 and needs a logged-in member. WordPress
 * REST cookie auth requires the wp_rest nonce (X-WP-Nonce header); without it
 * the request runs as a visitor and is refused here.
 *
 * Object-level rules are checked in each handler: members share projects,
 * tasks, decisions and chat; time entries, notifications and pending uploads
 * belong to one member.
 */
abstract class Controller {

	public const NAMESPACE = 'novawork/v1';

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback for every route.
	 */
	public function can_access(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'novawork_signed_out', __( 'Sign in to use NovaWork.', 'novawork' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Capabilities::ACCESS ) ) {
			return new WP_Error( 'novawork_forbidden', __( 'You aren’t a member of this NovaWork workspace.', 'novawork' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback for administrators' routes (NovaWork's settings).
	 */
	public function can_manage(): bool|WP_Error {
		$member = $this->can_access();
		if ( true !== $member ) {
			return $member;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'novawork_forbidden', __( 'Only an administrator can change NovaWork’s settings.', 'novawork' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * The member making the request.
	 */
	protected static function me(): int {
		return get_current_user_id();
	}

	/**
	 * Register one route.
	 *
	 * @param string                              $route   Route pattern.
	 * @param string                              $methods HTTP methods.
	 * @param callable                            $handler Callback.
	 * @param array<string, array<string, mixed>> $args    Argument schema.
	 */
	protected function route( string $route, string $methods, callable $handler, array $args = array() ): void {
		register_rest_route(
			self::NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => $handler,
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Register one route for administrators only.
	 *
	 * @param string                              $route   Route pattern.
	 * @param string                              $methods HTTP methods.
	 * @param callable                            $handler Callback.
	 * @param array<string, array<string, mixed>> $args    Argument schema.
	 */
	protected function admin_route( string $route, string $methods, callable $handler, array $args = array() ): void {
		register_rest_route(
			self::NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => $handler,
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * The id from a route like /tasks/(?P<id>\d+).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	protected static function id( WP_REST_Request $request ): int {
		return (int) $request['id'];
	}

	/**
	 * 403 for a member who may not change this row (someone else's message).
	 *
	 * @param string $message What the member sees.
	 */
	protected static function forbidden( string $message ): WP_Error {
		return new WP_Error( 'novawork_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * 404 for an object that does not exist.
	 */
	protected static function not_found(): WP_Error {
		return new WP_Error( 'novawork_not_found', __( 'That item no longer exists.', 'novawork' ), array( 'status' => 404 ) );
	}

	/**
	 * 403 for work a member may not change (setting edit_rule).
	 *
	 * @param string $kind task, project or decision.
	 */
	protected static function not_your_work( string $kind ): WP_Error {
		$messages = array(
			'task'     => __( 'You can change tasks you created or are assigned to. Ask an administrator to change this one.', 'novawork' ),
			'project'  => __( 'You can change projects you created. Ask an administrator to change this one.', 'novawork' ),
			'decision' => __( 'You can change decisions you created. Ask an administrator to change this one.', 'novawork' ),
		);
		return new WP_Error( 'novawork_not_your_work', $messages[ $kind ] ?? $messages['task'], array( 'status' => 403 ) );
	}

	/**
	 * A JSON response.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status HTTP status.
	 */
	protected static function respond( mixed $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * The params the client actually sent (PATCH only touches these).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string[]        $keys    Allowed keys.
	 * @return array<string, mixed>
	 */
	protected static function sent( WP_REST_Request $request, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( $request->has_param( $key ) ) {
				$out[ $key ] = $request->get_param( $key );
			}
		}
		return $out;
	}

	/**
	 * Members among a list of user ids.
	 *
	 * @param mixed $ids Candidate ids.
	 * @return list<int>
	 */
	protected static function members_only( mixed $ids ): array {
		return array_values( array_filter( Repository::ids( (array) $ids ), array( Capabilities::class, 'is_member' ) ) );
	}

	/**
	 * Schema: a single-line string.
	 *
	 * @param int  $max      Most characters.
	 * @param bool $required Whether it must be sent.
	 * @return array<string, mixed>
	 */
	protected static function line( int $max, bool $required = false ): array {
		return array(
			'type'              => 'string',
			'maxLength'         => $max,
			'required'          => $required,
			// A custom sanitizer replaces WordPress's schema check, so ask for it explicitly.
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => static fn( $value ) => sanitize_text_field( (string) $value ),
		);
	}

	/**
	 * Schema: multi-line plain text.
	 *
	 * @param int  $max      Most characters.
	 * @param bool $required Whether it must be sent.
	 * @return array<string, mixed>
	 */
	protected static function text( int $max, bool $required = false ): array {
		return array(
			'type'              => 'string',
			'maxLength'         => $max,
			'required'          => $required,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => static fn( $value ) => sanitize_textarea_field( (string) $value ),
		);
	}

	/**
	 * Schema: one of a list.
	 *
	 * @param string[] $values Allowed values.
	 * @return array<string, mixed>
	 */
	protected static function choice( array $values ): array {
		return array(
			'type' => 'string',
			'enum' => $values,
		);
	}

	/**
	 * Schema: a Y-m-d date, or '' to clear it.
	 *
	 * @return array<string, mixed>
	 */
	protected static function date(): array {
		return array(
			'type'              => 'string',
			'validate_callback' => static function ( $value ) {
				if ( '' === $value || ( is_string( $value ) && Db::is_date( $value ) ) ) {
					return true;
				}
				return new WP_Error( 'rest_invalid_param', __( 'Use a date like 2026-12-31.', 'novawork' ), array( 'status' => 400 ) );
			},
		);
	}

	/**
	 * Schema: an id (0 for none).
	 *
	 * @param bool $required Whether it must be sent.
	 * @return array<string, mixed>
	 */
	protected static function ref( bool $required = false ): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 0,
			'required' => $required,
		);
	}

	/**
	 * Schema: a list of ids.
	 *
	 * @param int $max Most ids.
	 * @return array<string, mixed>
	 */
	protected static function refs( int $max = 50 ): array {
		return array(
			'type'     => 'array',
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'maxItems' => $max,
		);
	}

	/**
	 * Schema for /move routes: the id to sit before, or null for the end.
	 *
	 * @return array<string, mixed>
	 */
	protected static function before(): array {
		return array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 1,
		);
	}

	/**
	 * Schema: agent keys mentioned with "@Name" (1.3.0).
	 *
	 * @return array<string, mixed>
	 */
	protected static function agent_keys(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 5,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => 64,
			),
		);
	}

	/**
	 * Schema: /links as the app sends them ([ { type, id } ]).
	 *
	 * @return array<string, mixed>
	 */
	protected static function links_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 20,
			'items'    => array(
				'type'       => 'object',
				'properties' => array(
					'type' => array(
						'type' => 'string',
						'enum' => \NovaWork\Services\References::TYPES,
					),
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
		);
	}

	/**
	 * Schema: a colour key.
	 *
	 * @return array<string, mixed>
	 */
	protected static function color(): array {
		return self::choice( Members::COLORS );
	}
}
