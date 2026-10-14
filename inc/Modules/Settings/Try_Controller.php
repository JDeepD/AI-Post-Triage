<?php
/**
 * REST endpoint for trying a headline on the settings screen.
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Decisions\Decision_Client;
use JDeepD\Wire_Desk\Decisions\OpenRouter_Client;
use JDeepD\Wire_Desk\Framework\Contracts\Abstracts\Abstract_REST_Controller;
use JDeepD\Wire_Desk\Scoring\Rubric;
use JDeepD\Wire_Desk\Scoring\Scorer;
use JDeepD\Wire_Desk\Scoring\State_Builder;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Try_Controller
 */
final class Try_Controller extends Abstract_REST_Controller {
	/**
	 * Maximum headline length.
	 */
	public const MAX_HEADLINE_LENGTH = 500;

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'try';

	/**
	 * Client that answers the questions.
	 *
	 * @var ?\JDeepD\Wire_Desk\Decisions\Decision_Client
	 */
	private ?Decision_Client $client;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\Wire_Desk\Decisions\Decision_Client|null $client Client that answers the questions.
	 */
	public function __construct( ?Decision_Client $client = null ) {
		$this->client = $client;
	}

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
						'headline' => [
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => self::MAX_HEADLINE_LENGTH,
						],
						'model'    => [
							'type'              => 'string',
							'validate_callback' => static fn ( $value ): bool => is_string( $value ) && 1 === preg_match( Settings::MODEL_PATTERN, $value ),
						],
					],
				],
			]
		);
	}

	/**
	 * Only users who manage the settings can try headlines.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the parent.
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to try headlines.', 'wire-desk' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Asks the model the saved questions about a headline.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function create_item( $request ): WP_REST_Response|\WP_Error {
		$settings = Settings::get();
		$headline = sanitize_text_field( (string) $request['headline'] );
		$model    = is_string( $request['model'] ) && '' !== $request['model'] ? $request['model'] : $settings['model'];

		if ( '' === $headline ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Enter a headline.', 'wire-desk' ), [ 'status' => 400 ] );
		}

		$decision = ( $this->client ?? new OpenRouter_Client() )->decide( State_Builder::for_headline( $headline ), $settings['questions'], $model );

		if ( is_wp_error( $decision ) ) {
			return new \WP_Error(
				$decision->get_error_code(),
				$decision->get_error_message(),
				[ 'status' => $this->error_status( (string) $decision->get_error_code() ) ]
			);
		}

		$score = Scorer::score( Rubric::from_settings(), $decision->answers );

		if ( is_wp_error( $score ) ) {
			return new \WP_Error( $score->get_error_code(), $score->get_error_message(), [ 'status' => 502 ] );
		}

		return new WP_REST_Response(
			[
				'model'      => $decision->model,
				'score'      => $score->score,
				'band'       => $score->band,
				'band_label' => Wire_Post::band_labels()[ $score->band ],
				'answers'    => array_map(
					static fn ( array $detail ): array => [
						'id'       => $detail['id'],
						'question' => $detail['question'],
						'expected' => $detail['expected'],
						'yes'      => $detail['yes'],
						'met'      => $detail['met'],
					],
					$score->details
				),
			]
		);
	}

	/**
	 * The REST status for a client error.
	 *
	 * @param string $code Error code.
	 */
	private function error_status( string $code ): int {
		return match ( $code ) {
			Decision_Client::ERROR_NO_KEY, Decision_Client::ERROR_BAD_REQUEST => 400,
			Decision_Client::ERROR_RATE_LIMITED => 429,
			default => 502,
		};
	}
}
