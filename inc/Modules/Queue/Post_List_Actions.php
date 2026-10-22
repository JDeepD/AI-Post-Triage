<?php
/**
 * Adds "Add to review queue" to the post lists of the wire post types.
 *
 * @package JDeepD\AI_Post_Triage\Modules\Queue
 */

declare(strict_types = 1);

namespace JDeepD\AI_Post_Triage\Modules\Queue;

use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Decisions\OpenRouter_Client;
use JDeepD\AI_Post_Triage\Editorial\Post_Actions;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Modules\Settings\Admin_Screen;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use WP_Post;
use WP_Query;

/**
 * Class - Post_List_Actions
 */
final class Post_List_Actions implements Registrable {
	/**
	 * Row and bulk action name.
	 */
	public const ACTION = 'ai_post_triage_add';

	/**
	 * Query arguments that report the result.
	 */
	public const RESULT_ARGS = [ 'ai_post_triage_added', 'ai_post_triage_add_failed' ];

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Wire post actions.
	 *
	 * @var \JDeepD\AI_Post_Triage\Editorial\Post_Actions
	 */
	private Post_Actions $actions;

	/**
	 * Whether the rows of the current list have been loaded from the table.
	 *
	 * @var bool
	 */
	private bool $primed = false;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository|null $repository Wire post table.
	 * @param \JDeepD\AI_Post_Triage\Editorial\Post_Actions|null    $actions    Wire post actions.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null, ?Post_Actions $actions = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
		$this->actions    = $actions ?? new Post_Actions( $this->repository );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'load-edit.php', [ $this, 'load' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_row_action' ] );
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );
	}

	/**
	 * Whether the actions are offered on a post type's list.
	 *
	 * @param string $post_type Post type.
	 */
	public static function is_enabled( string $post_type ): bool {
		return in_array( $post_type, Settings::get()['post_types'], true ) && current_user_can( Capabilities::REVIEW );
	}

	/**
	 * Hooks into the post list when it lists a wire post type.
	 */
	public function load(): void {
		$screen    = get_current_screen();
		$post_type = $screen ? (string) $screen->post_type : '';

		if ( '' === $post_type || ! self::is_enabled( $post_type ) ) {
			return;
		}

		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( "bulk_actions-edit-{$post_type}", [ $this, 'bulk_actions' ] );
		add_filter( "handle_bulk_actions-edit-{$post_type}", [ $this, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Adds "Add to review queue" under posts that aren't in AI Post Triage yet, and a link to the queue under those that are.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : [];

		if ( ! $post instanceof WP_Post || ! self::is_enabled( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) || 'trash' === $post->post_status ) {
			return $actions;
		}

		$this->prime();

		$row = $this->repository->get( $post->ID );

		if ( null !== $row ) {
			$actions[ self::ACTION ] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=' . Queue_Screen::SCREEN_ID . '&view=all' ) ),
				esc_html__( 'In review queue', 'ai-post-triage' )
			);

			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION,
					'post'   => $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $post->ID )
		);

		$actions[ self::ACTION ] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Add &#8220;%s&#8221; to the review queue', 'ai-post-triage' ), wp_strip_all_tags( _draft_or_post_title( $post ) ) ) ),
			esc_html__( 'Add to review queue', 'ai-post-triage' )
		);

		return $actions;
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return array<string, mixed>
	 */
	public function bulk_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : [];

		$actions[ self::ACTION ] = __( 'Add to review queue', 'ai-post-triage' );

		return $actions;
	}

	/**
	 * Adds the chosen posts to AI Post Triage. Core checks the bulk nonce before this runs.
	 *
	 * @param mixed $redirect Where to go next.
	 * @param mixed $action   Bulk action.
	 * @param mixed $post_ids Post IDs.
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ): string {
		$redirect = is_string( $redirect ) ? $redirect : admin_url( 'edit.php' );

		if ( self::ACTION !== $action ) {
			return $redirect;
		}

		return $this->add_posts( array_map( 'intval', (array) $post_ids ), $redirect );
	}

	/**
	 * Adds one post from its row action, then goes back to the list.
	 */
	public function handle_row_action(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.

		check_admin_referer( self::nonce_action( $post_id ) );

		$post = get_post( $post_id );
		$back = wp_get_referer() ?: admin_url( 'edit.php' . ( $post instanceof WP_Post && 'post' !== $post->post_type ? '?post_type=' . $post->post_type : '' ) );

		if ( wp_safe_redirect( $this->add_posts( [ $post_id ], $back ) ) ) {
			exit;
		}
	}

	/**
	 * The nonce action for one post's row action.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function nonce_action( int $post_id ): string {
		return 'ai-post-triage-add-post_' . $post_id;
	}

	/**
	 * Shows how many posts were added.
	 */
	public function notice(): void {
		$added  = isset( $_GET['ai_post_triage_added'] ) ? absint( $_GET['ai_post_triage_added'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reports a result.
		$failed = isset( $_GET['ai_post_triage_add_failed'] ) ? absint( $_GET['ai_post_triage_add_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reports a result.

		if ( $added ) {
			$message = esc_html(
				sprintf(
					/* translators: %s: number of posts. */
					_n( '%s post added to the review queue. It is scored in the background.', '%s posts added to the review queue. They are scored in the background.', $added, 'ai-post-triage' ),
					number_format_i18n( $added )
				)
			) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Queue_Screen::SCREEN_ID . '&view=waiting' ) ) . '">' . esc_html__( 'View the review queue', 'ai-post-triage' ) . '</a>';

			if ( '' === OpenRouter_Client::api_key() ) {
				$message .= '<br />' . esc_html__( 'No OpenRouter API key is set, so scoring will fail.', 'ai-post-triage' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Screen::SCREEN_ID ) ) . '">' . esc_html__( 'Add a key', 'ai-post-triage' ) . '</a>';
			}

			wp_admin_notice(
				$message,
				[
					'type'        => '' === OpenRouter_Client::api_key() ? 'warning' : 'success',
					'dismissible' => true,
				]
			);
		}

		if ( $failed ) {
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: number of posts. */
						_n( '%s post could not be added to the review queue.', '%s posts could not be added to the review queue.', $failed, 'ai-post-triage' ),
						number_format_i18n( $failed )
					)
				),
				[
					'type'        => 'error',
					'dismissible' => true,
				]
			);
		}
	}

	/**
	 * Adds the result arguments to the ones removed from the address bar.
	 *
	 * @param mixed $args Query arguments.
	 * @return string[]
	 */
	public function removable_query_args( $args ): array {
		return array_merge( is_array( $args ) ? array_values( array_filter( $args, 'is_string' ) ) : [], self::RESULT_ARGS );
	}

	/**
	 * Adds posts and returns the address to go back to, with the result.
	 *
	 * @param int[]  $post_ids Post IDs.
	 * @param string $back     Address to go back to.
	 */
	private function add_posts( array $post_ids, string $back ): string {
		$added  = 0;
		$failed = 0;

		foreach ( array_slice( array_filter( $post_ids ), 0, Wire_Post_Repository::MAX_PAGE_SIZE * 10 ) as $post_id ) {
			if ( true === $this->actions->add( $post_id ) ) {
				++$added;
			} else {
				++$failed;
			}
		}

		return add_query_arg(
			array_filter(
				[
					'ai_post_triage_added'      => $added,
					'ai_post_triage_add_failed' => $failed,
				]
			),
			remove_query_arg( self::RESULT_ARGS, $back )
		);
	}

	/**
	 * Loads the listed posts' rows in one query.
	 */
	private function prime(): void {
		global $wp_query;

		if ( $this->primed ) {
			return;
		}

		$this->primed = true;

		if ( $wp_query instanceof WP_Query && is_array( $wp_query->posts ) ) {
			$this->repository->get_many( array_map( static fn ( $post ): int => $post instanceof WP_Post ? $post->ID : (int) $post, $wp_query->posts ) );
		}
	}
}
