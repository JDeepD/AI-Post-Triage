<?php
/**
 * Abstract REST controller class.
 *
 * Includes the shared namespace, version and hook registration.
 *
 * @package JDeepD\AI_Post_Triage\Framework\Contracts\Abstracts
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Framework\Contracts\Abstracts;

use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use WP_REST_Controller;

/**
 * Class - Abstract_REST_Controller
 */
abstract class Abstract_REST_Controller extends WP_REST_Controller implements Registrable {
	/**
	 * Route namespace for the REST API routes.
	 *
	 * Usually at /wp-json/{namespace}/{route}
	 *
	 * @var non-falsy-string
	 */
	protected $namespace = 'ai-post-triage/v';

	/**
	 * Version number for the REST API routes.
	 *
	 * @var non-falsy-string
	 */
	protected string $version = '1';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * We throw an exception here to force the child class to implement this method.
	 *
	 * @throws \Exception If method not implemented.
	 *
	 * @codeCoverageIgnore
	 */
	public function register_routes(): void {
		throw new \Exception( __FUNCTION__ . ' Method not implemented.' );
	}
}
