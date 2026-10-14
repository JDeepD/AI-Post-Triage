<?php
/**
 * Registers the review queue screen.
 *
 * @package JDeepD\Wire_Desk\Modules\Queue
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Queue;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Core\Templates;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Editorial\Post_Actions;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Queue_Screen
 */
final class Queue_Screen implements Registrable {
	/**
	 * The page slug, also the slug of the Wire Desk menu.
	 */
	public const SCREEN_ID = 'wire-desk';

	/**
	 * The screen's hook suffix.
	 */
	public const HOOK_SUFFIX = 'toplevel_page_' . self::SCREEN_ID;

	/**
	 * User option holding rows per page.
	 */
	public const PER_PAGE_OPTION = 'wire_desk_queue_per_page';

	/**
	 * Highest count shown on the menu badge.
	 */
	public const BADGE_MAX = 99;

	/**
	 * Query arguments that report an action's result.
	 */
	public const RESULT_ARGS = [ 'wire_desk_action', 'wire_desk_done', 'wire_desk_failed', 'wire_desk_error' ];

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
	 * The list table, once the screen has loaded.
	 *
	 * @var \JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table|null
	 */
	private ?Queue_List_Table $table = null;

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
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ] );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, [ $this, 'save_per_page' ], 10, 3 );
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );
	}

	/**
	 * The nonce action for one post's row action.
	 *
	 * @param string $action  One of the Post_Actions constants.
	 * @param int    $post_id Post ID.
	 */
	public static function nonce_action( string $action, int $post_id ): string {
		return 'wire-desk-' . $action . '-post_' . $post_id;
	}

	/**
	 * Adds the Wire Desk menu, with the queue as its first page.
	 */
	public function register_screen(): void {
		$hook_suffix = add_menu_page(
			__( 'Wire Desk', 'wire-desk' ),
			__( 'Wire Desk', 'wire-desk' ),
			Capabilities::REVIEW,
			self::SCREEN_ID,
			[ $this, 'render_screen' ],
			'dashicons-rss',
			26
		);

		add_submenu_page(
			self::SCREEN_ID,
			__( 'Review queue', 'wire-desk' ),
			__( 'Review queue', 'wire-desk' ),
			Capabilities::REVIEW,
			self::SCREEN_ID,
			[ $this, 'render_screen' ]
		);

		$this->add_badge();

		add_action( "load-{$hook_suffix}", [ $this, 'load' ], 10, 0 );
	}

	/**
	 * High-priority posts that need review, counting up to one more than BADGE_MAX.
	 */
	public function badge_count(): int {
		return $this->repository->count(
			[
				'state' => Wire_Post::STATE_SCORED,
				'band'  => Wire_Post::BAND_HIGH,
			],
			self::BADGE_MAX + 1
		);
	}

	/**
	 * Runs a row or bulk action, if one was requested, and returns where to go next.
	 *
	 * @param array<string, mixed> $request Request arguments.
	 * @return string|null URL to redirect to, or null when no action was requested.
	 */
	public function handle_action( array $request ): ?string {
		$action = $this->requested_action( $request );

		if ( null === $action ) {
			return isset( $request['_wp_http_referer'] ) ? self::clean_url( $request ) : null;
		}

		$bulk     = is_array( $request['post'] ?? null );
		$post_ids = array_values( array_filter( array_map( 'absint', $bulk ? (array) $request['post'] : [ $request['post'] ?? 0 ] ) ) );

		if ( $bulk ) {
			check_admin_referer( 'bulk-wire-posts' );
		} else {
			check_admin_referer( self::nonce_action( $action, $post_ids[0] ?? 0 ) );
		}

		$done   = 0;
		$failed = 0;
		$error  = '';

		foreach ( array_slice( $post_ids, 0, Wire_Post_Repository::MAX_PAGE_SIZE ) as $post_id ) {
			$result = $this->actions->run( $action, $post_id );

			if ( true === $result ) {
				++$done;
				continue;
			}

			++$failed;
			$error = '' === $error ? $result->get_error_code() : $error;
		}

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SCREEN_ID );

		return add_query_arg(
			array_filter(
				[
					'wire_desk_action' => $action,
					'wire_desk_done'   => $done,
					'wire_desk_failed' => $failed,
					'wire_desk_error'  => is_string( $error ) ? $error : '',
				]
			),
			remove_query_arg( array_merge( [ 'action', 'action2', 'post', '_wpnonce', '_wp_http_referer', 'filter_action' ], self::RESULT_ARGS ), $back )
		);
	}

	/**
	 * Notices for the last action's result.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 * @return array<int, array{type: string, message: string}>
	 */
	public static function notices( array $query ): array {
		$action = is_string( $query['wire_desk_action'] ?? null ) ? $query['wire_desk_action'] : '';
		$done   = absint( $query['wire_desk_done'] ?? 0 );
		$failed = absint( $query['wire_desk_failed'] ?? 0 );
		$code   = is_string( $query['wire_desk_error'] ?? null ) ? $query['wire_desk_error'] : '';

		if ( ! array_key_exists( $action, Post_Actions::labels() ) ) {
			return [];
		}

		$notices = [];
		$count   = number_format_i18n( $done );

		if ( $done ) {
			$notices[] = [
				'type'    => 'success',
				'message' => match ( $action ) {
					/* translators: %s: number of posts. */
					Post_Actions::PUBLISH => sprintf( _n( '%s post published.', '%s posts published.', $done, 'wire-desk' ), $count ),
					/* translators: %s: number of posts. */
					Post_Actions::DISMISS => sprintf( _n( '%s post dismissed.', '%s posts dismissed.', $done, 'wire-desk' ), $count ),
					/* translators: %s: number of posts. */
					Post_Actions::RESTORE => sprintf( _n( '%s post restored to the queue.', '%s posts restored to the queue.', $done, 'wire-desk' ), $count ),
					/* translators: %s: number of posts. */
					default               => sprintf( _n( '%s post will be scored again shortly.', '%s posts will be scored again shortly.', $done, 'wire-desk' ), $count ),
				},
			];
		}

		if ( $failed ) {
			$notices[] = [
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: number of posts. */
					_n( '%s post could not be updated.', '%s posts could not be updated.', $failed, 'wire-desk' ),
					number_format_i18n( $failed )
				) . ( isset( Post_Actions::error_messages()[ $code ] ) ? ' ' . Post_Actions::error_messages()[ $code ] : '' ),
			];
		}

		return $notices;
	}

	/**
	 * Adds the action result arguments to the ones removed from the address bar.
	 *
	 * @param mixed $args Query arguments.
	 * @return string[]
	 */
	public function removable_query_args( $args ): array {
		return array_merge( is_array( $args ) ? array_values( array_filter( $args, 'is_string' ) ) : [], self::RESULT_ARGS );
	}

	/**
	 * Sets up the screen options and the list table, after running any requested action.
	 */
	public function load(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$redirect = $this->handle_action( wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonces are checked in handle_action().

		if ( null !== $redirect && wp_safe_redirect( $redirect ) ) {
			exit;
		}

		add_screen_option(
			'per_page',
			[
				'default' => Queue_List_Table::DEFAULT_PER_PAGE,
				'option'  => self::PER_PAGE_OPTION,
			]
		);

		$this->table = new Queue_List_Table( wp_unslash( $_GET ), $this->repository ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters and paging.
		$this->table->prepare_items();

		wp_enqueue_style( Assets::ADMIN_HANDLE );
	}

	/**
	 * Saves the rows per page screen option.
	 *
	 * @param mixed  $screen_option Value to save, false by default.
	 * @param string $option        Option name.
	 * @param mixed  $value         Submitted value.
	 */
	public function save_per_page( $screen_option, $option, $value ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
		return is_numeric( $value ) ? max( 1, min( Wire_Post_Repository::MAX_PAGE_SIZE, (int) $value ) ) : false;
	}

	/**
	 * Renders the queue.
	 *
	 * @internal Used by register_screen().
	 */
	public function render_screen(): void {
		if ( null === $this->table ) {
			$this->load();
		}

		Templates::get_template_part(
			'queue-screen',
			null,
			[
				'table'   => $this->table,
				'notices' => self::notices( wp_unslash( $_GET ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reports a result.
			]
		);
	}

	/**
	 * The requested action, or null when there is none.
	 *
	 * @param array<string, mixed> $request Request arguments.
	 */
	private function requested_action( array $request ): ?string {
		if ( ! empty( $request['filter_action'] ) ) {
			return null;
		}

		foreach ( [ 'action', 'action2' ] as $key ) {
			$action = $request[ $key ] ?? null;

			if ( is_string( $action ) && array_key_exists( $action, Post_Actions::labels() ) ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * The queue URL for a request, keeping only its view, band and paging.
	 *
	 * @param array<string, mixed> $request Request arguments.
	 */
	private static function clean_url( array $request ): string {
		$args = array_filter(
			array_intersect_key( $request, array_flip( [ 'view', 'band', 'before', 'after' ] ) ),
			static fn ( $value ): bool => is_scalar( $value ) && '' !== (string) $value
		);

		return admin_url( 'admin.php' ) . '?' . http_build_query( [ 'page' => self::SCREEN_ID ] + $args );
	}

	/**
	 * Adds the count of high-priority posts needing review to the menu.
	 */
	private function add_badge(): void {
		global $menu;

		if ( ! current_user_can( Capabilities::REVIEW ) || ! is_array( $menu ) ) {
			return;
		}

		$count = $this->badge_count();

		if ( ! $count ) {
			return;
		}

		$badge = sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			min( $count, self::BADGE_MAX ),
			esc_html( $count > self::BADGE_MAX ? number_format_i18n( self::BADGE_MAX ) . '+' : number_format_i18n( $count ) ),
			esc_html(
				$count > self::BADGE_MAX
					/* translators: %s: highest count shown, such as 99. */
					? sprintf( __( 'More than %s high-priority posts need review', 'wire-desk' ), number_format_i18n( self::BADGE_MAX ) )
					/* translators: %s: number of posts. */
					: sprintf( _n( '%s high-priority post needs review', '%s high-priority posts need review', $count, 'wire-desk' ), number_format_i18n( $count ) )
			)
		);

		foreach ( $menu as $position => $item ) {
			if ( self::SCREEN_ID === ( $item[2] ?? null ) ) {
				$menu[ $position ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adds the badge after the hook name is set.
				break;
			}
		}
	}
}
