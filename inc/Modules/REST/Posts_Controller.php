<?php
/**
 * REST endpoints for listing wire posts and acting on them.
 *
 * @package JDeepD\Wire_Desk\Modules\REST
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\REST;

use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Editorial\Post_Actions;
use JDeepD\Wire_Desk\Framework\Contracts\Abstracts\Abstract_REST_Controller;
use WP_Post;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Posts_Controller
 */
final class Posts_Controller extends Abstract_REST_Controller {
	/**
	 * Posts per page when none is asked for.
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'posts';

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Wire post actions.
	 *
	 * @var \JDeepD\Wire_Desk\Editorial\Post_Actions
	 */
	private Post_Actions $actions;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository|null $repository Wire post table.
	 * @param \JDeepD\Wire_Desk\Editorial\Post_Actions|null    $actions    Wire post actions.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null, ?Post_Actions $actions = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
		$this->actions    = $actions ?? new Post_Actions( $this->repository );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$namespace = $this->namespace . $this->version;

		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'args'   => [
					'id' => [
						'description' => __( 'Post ID.', 'wire-desk' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'context' => $this->get_context_param( [ 'default' => 'view' ] ),
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/(?P<action>' . implode( '|', array_keys( Post_Actions::labels() ) ) . ')',
			[
				'args'   => [
					'id'     => [
						'description' => __( 'Post ID.', 'wire-desk' ),
						'type'        => 'integer',
					],
					'action' => [
						'description' => __( 'Action to take.', 'wire-desk' ),
						'type'        => 'string',
						'enum'        => array_keys( Post_Actions::labels() ),
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_action' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Only reviewers can list wire posts and act on them.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the parent.
		if ( current_user_can( Capabilities::REVIEW ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to review wire posts.', 'wire-desk' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Reviewers can read a wire post they can edit.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|\WP_Error {
		$allowed = $this->get_items_permissions_check( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$row = $this->find( (int) $request['id'] );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! current_user_can( 'edit_post', $row->post_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to review this wire post.', 'wire-desk' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * Lists wire posts, newest first, a page at a time.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|\WP_Error {
		$filters = [];

		if ( is_string( $request['state'] ) && isset( Wire_Post::state_slugs()[ $request['state'] ] ) ) {
			$filters['state'] = Wire_Post::state_slugs()[ $request['state'] ];
		}

		if ( is_string( $request['band'] ) && isset( Wire_Post::band_slugs()[ $request['band'] ] ) ) {
			$filters['band'] = Wire_Post::band_slugs()[ $request['band'] ];
		}

		$before = (int) $request['before'];
		$after  = $before ? 0 : (int) $request['after'];

		if ( $before || $after ) {
			$cursor_row = $this->repository->get( $before ?: $after );

			if ( null === $cursor_row ) {
				return new \WP_Error( 'rest_invalid_param', __( 'The post to page from is not a wire post.', 'wire-desk' ), [ 'status' => 400 ] );
			}
		}

		$items = $this->repository->page( $filters, self::cursor( $cursor_row ?? null ), (int) $request['per_page'], $after > 0 );

		_prime_post_caches( array_map( static fn ( Wire_Post $item ): int => $item->post_id, $items ), false, false );

		$data = [];
		foreach ( $items as $item ) {
			$data[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $item, $request ) );
		}

		$response = new WP_REST_Response( $data );

		if ( ! $items ) {
			return $response;
		}

		$first = $items[0];
		$last  = $items[ count( $items ) - 1 ];
		$base  = add_query_arg( urlencode_deep( array_filter( array_intersect_key( $request->get_query_params(), array_flip( [ 'state', 'band', 'per_page', 'context' ] ) ) ) ), rest_url( $this->namespace . $this->version . '/' . $this->rest_base ) );

		if ( $this->repository->page( $filters, self::cursor( $first ), 1, true ) ) {
			$response->link_header( 'prev', add_query_arg( 'after', $first->post_id, $base ) );
		}

		if ( $this->repository->page( $filters, self::cursor( $last ), 1 ) ) {
			$response->link_header( 'next', add_query_arg( 'before', $last->post_id, $base ) );
		}

		return $response;
	}

	/**
	 * Gets one wire post.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|\WP_Error {
		$row = $this->find( (int) $request['id'] );

		return is_wp_error( $row ) ? $row : $this->prepare_item_for_response( $row, $request );
	}

	/**
	 * Publishes, dismisses, restores or re-scores a wire post, then returns it.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_action( $request ): WP_REST_Response|\WP_Error {
		$result = $this->actions->run( (string) $request['action'], (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $this->find( (int) $request['id'] );

		return is_wp_error( $row ) ? $row : $this->prepare_item_for_response( $row, $request );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post       $item    Row.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$post      = get_post( $item->post_id );
		$is_post   = $post instanceof WP_Post;
		$edit_link = $is_post ? get_edit_post_link( $post->ID, 'raw' ) : null;
		$scored    = null !== $item->scored_at;

		$data = [
			'id'          => $item->post_id,
			'title'       => $is_post ? get_the_title( $post ) : '',
			'post_status' => $is_post ? $post->post_status : null,
			'edit_link'   => $edit_link ?: null,
			'date'        => mysql_to_rfc3339( $item->post_date ) ?: null,
			'state'       => (string) array_search( $item->state, Wire_Post::state_slugs(), true ),
			'band'        => (string) array_search( $item->band, Wire_Post::band_slugs(), true ),
			'score'       => $scored ? $item->score : null,
			'model'       => $item->model,
			'cost'        => $item->cost,
			'scored_at'   => $scored ? ( mysql_to_rfc3339( (string) $item->scored_at ) ?: null ) : null,
			'error'       => $item->error,
			'attempts'    => $item->attempts,
			'answers'     => self::answers( $item->details ),
			'actions'     => Post_Actions::available( $item ),
		];

		$context = is_string( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->filter_response_by_context( $this->add_additional_fields_to_object( $data, $request ), $context );

		$response = new WP_REST_Response( $data );
		$response->add_link( 'self', rest_url( $this->namespace . $this->version . '/' . $this->rest_base . '/' . $item->post_id ) );

		if ( $is_post ) {
			$route = rest_get_route_for_post( $post );

			if ( '' !== $route ) {
				$response->add_link( 'https://api.w.org/post', rest_url( $route ), [ 'embeddable' => true ] );
			}
		}

		return $response;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return [
			'context'  => $this->get_context_param( [ 'default' => 'view' ] ),
			'state'    => [
				'description' => __( 'Only posts in this state.', 'wire-desk' ),
				'type'        => 'string',
				'enum'        => array_keys( Wire_Post::state_slugs() ),
			],
			'band'     => [
				'description' => __( 'Only posts in this priority band.', 'wire-desk' ),
				'type'        => 'string',
				'enum'        => array_keys( Wire_Post::band_slugs() ),
			],
			'per_page' => [
				'description' => __( 'Posts per page.', 'wire-desk' ),
				'type'        => 'integer',
				'default'     => self::DEFAULT_PER_PAGE,
				'minimum'     => 1,
				'maximum'     => Wire_Post_Repository::MAX_PAGE_SIZE,
			],
			'before'   => [
				'description' => __( 'Only posts older than this post.', 'wire-desk' ),
				'type'        => 'integer',
				'minimum'     => 1,
			],
			'after'    => [
				'description' => __( 'Only posts newer than this post.', 'wire-desk' ),
				'type'        => 'integer',
				'minimum'     => 1,
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$context = [ 'view', 'edit' ];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wire-desk-post',
			'type'       => 'object',
			'properties' => [
				'id'          => [
					'description' => __( 'Post ID.', 'wire-desk' ),
					'type'        => 'integer',
					'context'     => $context,
					'readonly'    => true,
				],
				'title'       => [
					'description' => __( 'Post title, rendered.', 'wire-desk' ),
					'type'        => 'string',
					'context'     => $context,
					'readonly'    => true,
				],
				'post_status' => [
					'description' => __( 'Post status, or null when the post is gone.', 'wire-desk' ),
					'type'        => [ 'string', 'null' ],
					'context'     => $context,
					'readonly'    => true,
				],
				'edit_link'   => [
					'description' => __( 'Link to edit the post, when the user can.', 'wire-desk' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'uri',
					'context'     => $context,
					'readonly'    => true,
				],
				'date'        => [
					'description' => __( 'Post date, in the site\'s timezone.', 'wire-desk' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => $context,
					'readonly'    => true,
				],
				'state'       => [
					'description' => __( 'Where the post is in the review queue.', 'wire-desk' ),
					'type'        => 'string',
					'enum'        => array_keys( Wire_Post::state_slugs() ),
					'context'     => $context,
					'readonly'    => true,
				],
				'band'        => [
					'description' => __( 'Priority band.', 'wire-desk' ),
					'type'        => 'string',
					'enum'        => array_keys( Wire_Post::band_slugs() ),
					'context'     => $context,
					'readonly'    => true,
				],
				'score'       => [
					'description' => __( 'Score from 0 to 100, or null before the post is scored.', 'wire-desk' ),
					'type'        => [ 'integer', 'null' ],
					'minimum'     => 0,
					'maximum'     => 100,
					'context'     => $context,
					'readonly'    => true,
				],
				'model'       => [
					'description' => __( 'Model that scored the post.', 'wire-desk' ),
					'type'        => 'string',
					'context'     => $context,
					'readonly'    => true,
				],
				'cost'        => [
					'description' => __( 'Cost of the last paid score, in US dollars.', 'wire-desk' ),
					'type'        => 'number',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
				'scored_at'   => [
					'description' => __( 'When the post was last scored, in GMT.', 'wire-desk' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => $context,
					'readonly'    => true,
				],
				'error'       => [
					'description' => __( 'Last scoring error.', 'wire-desk' ),
					'type'        => 'string',
					'context'     => $context,
					'readonly'    => true,
				],
				'attempts'    => [
					'description' => __( 'Failed scoring attempts in a row.', 'wire-desk' ),
					'type'        => 'integer',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
				'answers'     => [
					'description' => __( 'The model\'s answer to each question, or null when none are kept.', 'wire-desk' ),
					'type'        => [ 'array', 'null' ],
					'context'     => $context,
					'readonly'    => true,
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'string' ],
							'question' => [ 'type' => 'string' ],
							'expected' => [
								'type' => 'string',
								'enum' => [ 'yes', 'no' ],
							],
							'weight'   => [ 'type' => 'string' ],
							'required' => [ 'type' => 'boolean' ],
							'yes'      => [
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							],
							'met'      => [ 'type' => 'boolean' ],
						],
					],
				],
				'actions'     => [
					'description' => __( 'Actions the current user can take.', 'wire-desk' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => array_keys( Post_Actions::labels() ),
					],
					'context'     => $context,
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Finds a wire post whose post still exists.
	 *
	 * @param int $post_id Post ID.
	 */
	private function find( int $post_id ): Wire_Post|\WP_Error {
		$row = $this->repository->get( $post_id );

		if ( null === $row || ! get_post( $post_id ) instanceof WP_Post ) {
			return new \WP_Error( 'rest_post_invalid_id', Post_Actions::error_messages()['wire_desk_not_found'], [ 'status' => 404 ] );
		}

		return $row;
	}

	/**
	 * The answers kept for a post, in the schema's shape.
	 *
	 * @param array<int|string, mixed>|null $details Stored details.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function answers( ?array $details ): ?array {
		if ( null === $details ) {
			return null;
		}

		$answers = [];

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$answers[] = [
				'id'       => (string) ( $detail['id'] ?? '' ),
				'question' => (string) ( $detail['question'] ?? '' ),
				'expected' => 'no' === ( $detail['expected'] ?? 'yes' ) ? 'no' : 'yes',
				'weight'   => (string) ( $detail['weight'] ?? '' ),
				'required' => (bool) ( $detail['required'] ?? false ),
				'yes'      => is_numeric( $detail['yes'] ?? null ) ? (float) $detail['yes'] : 0.0,
				'met'      => (bool) ( $detail['met'] ?? false ),
			];
		}

		return $answers;
	}

	/**
	 * The paging cursor for a row.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post|null $item Row.
	 * @return array{post_date: string, post_id: int}|null
	 */
	private static function cursor( ?Wire_Post $item ): ?array {
		return null === $item
			? null
			: [
				'post_date' => $item->post_date,
				'post_id'   => $item->post_id,
			];
	}
}
