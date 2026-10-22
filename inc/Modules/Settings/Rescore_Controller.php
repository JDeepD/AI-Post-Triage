<?php
/**
 * REST endpoint for re-scoring the newest posts from the settings screen.
 *
 * @package JDeepD\AI_Post_Triage\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\AI_Post_Triage\Modules\Settings;

use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Framework\Contracts\Abstracts\Abstract_REST_Controller;
use JDeepD\AI_Post_Triage\Jobs\Rescore;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Rescore_Controller
 */
final class Rescore_Controller extends Abstract_REST_Controller {
	/**
	 * Most posts queued from the settings screen.
	 */
	public const MAX_LIMIT = 1000;

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'rescore';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace . $this->version,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'limit' => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
							'maximum'  => self::MAX_LIMIT,
						],
					],
				],
			]
		);
	}

	/**
	 * Only users who manage the settings can re-score posts.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the parent.
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to re-score posts.', 'ai-post-triage' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Queues the newest posts to be scored again.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function create_item( $request ): WP_REST_Response {
		return new WP_REST_Response( [ 'queued' => ( new Rescore() )->queue( (int) $request['limit'] ) ] );
	}
}
