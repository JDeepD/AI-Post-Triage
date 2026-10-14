<?php
/**
 * REST endpoint for finding terms on the settings screen.
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Framework\Contracts\Abstracts\Abstract_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

/**
 * Class - Term_Search_Controller
 */
final class Term_Search_Controller extends Abstract_REST_Controller {
	/**
	 * Maximum terms returned.
	 */
	public const MAX_RESULTS = 50;

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'terms';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace . $this->version,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'taxonomy' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static fn ( $value ): bool => is_string( $value ) && taxonomy_exists( sanitize_key( $value ) ),
						],
						'search'   => [
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => self::MAX_RESULTS,
						],
					],
				],
			]
		);
	}

	/**
	 * Only users who manage the settings can search terms.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the parent.
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to search terms.', 'wire-desk' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Finds terms by name.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$terms = get_terms(
			[
				'taxonomy'   => (string) $request['taxonomy'],
				'search'     => (string) $request['search'],
				'number'     => (int) $request['per_page'],
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		$items = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof WP_Term ) {
				$items[] = [
					'id'   => $term->term_id,
					'name' => $term->name,
				];
			}
		}

		return new WP_REST_Response( $items );
	}
}
