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
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

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
	 */
	public function __construct( ?Wire_Post_Repository $repository = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ] );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, [ $this, 'save_per_page' ], 10, 3 );
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
	 * Sets up the screen options and the list table.
	 */
	public function load(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

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

		Templates::get_template_part( 'queue-screen', null, [ 'table' => $this->table ] );
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
