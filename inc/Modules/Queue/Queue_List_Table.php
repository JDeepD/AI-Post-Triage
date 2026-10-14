<?php
/**
 * Lists wire posts on the review queue screen.
 *
 * @package JDeepD\Wire_Desk\Modules\Queue
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Queue;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use WP_List_Table;
use WP_Post;

/**
 * Class - Queue_List_Table
 */
final class Queue_List_Table extends WP_List_Table {
	/**
	 * The view shown by default.
	 */
	public const DEFAULT_VIEW = 'needs-review';

	/**
	 * Rows per page when the user hasn't chosen.
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Highest count shown on a view.
	 */
	public const COUNT_MAX = 1000;

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Current view.
	 *
	 * @var string
	 */
	private string $view;

	/**
	 * Band filter slug, or an empty string for every band.
	 *
	 * @var string
	 */
	private string $band;

	/**
	 * Post ID to show older posts than.
	 *
	 * @var int
	 */
	private int $before;

	/**
	 * Post ID to show newer posts than.
	 *
	 * @var int
	 */
	private int $after;

	/**
	 * Whether there are newer posts than this page.
	 *
	 * @var bool
	 */
	private bool $has_newer = false;

	/**
	 * Whether there are older posts than this page.
	 *
	 * @var bool
	 */
	private bool $has_older = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                             $query      Query arguments: view, band, before and after.
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( array $query = [], ?Wire_Post_Repository $repository = null ) {
		parent::__construct(
			[
				'plural'   => 'wire-posts',
				'singular' => 'wire-post',
				'ajax'     => false,
				'screen'   => Queue_Screen::HOOK_SUFFIX,
			]
		);

		$this->repository = $repository ?? new Wire_Post_Repository();

		$view  = is_string( $query['view'] ?? null ) ? $query['view'] : '';
		$band  = is_string( $query['band'] ?? null ) ? $query['band'] : '';
		$views = self::view_states();

		$this->view   = array_key_exists( $view, $views ) ? $view : self::DEFAULT_VIEW;
		$this->band   = array_key_exists( $band, self::band_slugs() ) ? $band : '';
		$this->before = is_numeric( $query['before'] ?? null ) ? max( 0, (int) $query['before'] ) : 0;
		$this->after  = $this->before ? 0 : ( is_numeric( $query['after'] ?? null ) ? max( 0, (int) $query['after'] ) : 0 );
	}

	/**
	 * The state each view shows, keyed by view slug. All has no state.
	 *
	 * @return array<string, int|null>
	 */
	public static function view_states(): array {
		return [
			'needs-review' => Wire_Post::STATE_SCORED,
			'waiting'      => Wire_Post::STATE_QUEUED,
			'failed'       => Wire_Post::STATE_FAILED,
			'published'    => Wire_Post::STATE_PUBLISHED,
			'dismissed'    => Wire_Post::STATE_DISMISSED,
			'all'          => null,
		];
	}

	/**
	 * View names, keyed by view slug.
	 *
	 * @return array<string, string>
	 */
	public static function view_labels(): array {
		return [
			'needs-review' => __( 'Needs review', 'wire-desk' ),
			'waiting'      => __( 'Waiting', 'wire-desk' ),
			'failed'       => __( 'Failed', 'wire-desk' ),
			'published'    => __( 'Published', 'wire-desk' ),
			'dismissed'    => __( 'Dismissed', 'wire-desk' ),
			'all'          => __( 'All', 'wire-desk' ),
		];
	}

	/**
	 * Bands, keyed by slug.
	 *
	 * @return array<string, int>
	 */
	public static function band_slugs(): array {
		return [
			'high'       => Wire_Post::BAND_HIGH,
			'medium'     => Wire_Post::BAND_MEDIUM,
			'low'        => Wire_Post::BAND_LOW,
			'not-scored' => Wire_Post::BAND_NONE,
		];
	}

	/**
	 * The current view.
	 */
	public function get_view(): string {
		return $this->view;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), get_hidden_columns( $this->screen ), [], 'title' ];

		$per_page = max( 1, min( Wire_Post_Repository::MAX_PAGE_SIZE, $this->get_items_per_page( Queue_Screen::PER_PAGE_OPTION, self::DEFAULT_PER_PAGE ) ) );
		$filters  = $this->filters( $this->view );
		$cursor   = $this->cursor( $this->repository->get( $this->before ?: $this->after ) );
		$newer    = null !== $cursor && $this->after > 0;
		$items    = $this->repository->page( $filters, $cursor, $per_page, $newer );

		if ( $newer && count( $items ) < $per_page ) {
			$items = $this->repository->page( $filters, null, $per_page );
		}

		$this->items = $items;

		if ( ! $items ) {
			return;
		}

		$this->has_newer = (bool) $this->repository->page( $filters, $this->cursor( $items[0] ), 1, true );
		$this->has_older = (bool) $this->repository->page( $filters, $this->cursor( $items[ count( $items ) - 1 ] ), 1 );

		_prime_post_caches( array_map( static fn ( Wire_Post $item ): int => $item->post_id, $items ), false, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'title' => __( 'Title', 'wire-desk' ),
			'band'  => __( 'Priority', 'wire-desk' ),
			'state' => __( 'Status', 'wire-desk' ),
			'model' => __( 'Model', 'wire-desk' ),
			'date'  => __( 'Date', 'wire-desk' ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No wire posts found.', 'wire-desk' );
	}

	/**
	 * The title column.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 */
	public function column_title( Wire_Post $item ): string {
		$post = get_post( $item->post_id );

		if ( ! $post instanceof WP_Post ) {
			return '<strong>' . esc_html__( '(deleted)', 'wire-desk' ) . '</strong>';
		}

		$title = _draft_or_post_title( $post );
		$link  = get_edit_post_link( $post->ID );

		return '<strong>'
			. ( $link ? '<a class="row-title" href="' . esc_url( $link ) . '">' . $title . '</a>' : $title )
			. _post_states( $post, false )
			. '</strong>';
	}

	/**
	 * The priority column.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 */
	public function column_band( Wire_Post $item ): string {
		$labels = Wire_Post::band_labels();
		$slug   = (string) array_search( $item->band, self::band_slugs(), true );
		$html   = '<span class="wire-desk-band wire-desk-band-' . esc_attr( $slug ) . '">' . esc_html( $labels[ $item->band ] ?? $labels[ Wire_Post::BAND_NONE ] ) . '</span>';

		if ( null !== $item->scored_at ) {
			$html .= ' <span class="wire-desk-score">' . esc_html( number_format_i18n( $item->score ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * The status column.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 */
	public function column_state( Wire_Post $item ): string {
		$html = esc_html( Wire_Post::state_labels()[ $item->state ] ?? '' );

		if ( '' !== $item->error && in_array( $item->state, [ Wire_Post::STATE_FAILED, Wire_Post::STATE_QUEUED ], true ) ) {
			$html .= '<br /><span class="wire-desk-error">' . esc_html( $item->error ) . '</span>';
		}

		return $html;
	}

	/**
	 * The model column.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 */
	public function column_model( Wire_Post $item ): string {
		if ( '' === $item->model ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Not scored', 'wire-desk' ) . '</span>';
		}

		return esc_html( $item->model ) . '<br /><span class="wire-desk-muted">$' . esc_html( number_format_i18n( $item->cost, 4 ) ) . '</span>';
	}

	/**
	 * The date column.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 */
	public function column_date( Wire_Post $item ): string {
		$html = esc_html(
			sprintf(
				/* translators: 1: post date, 2: post time. */
				__( '%1$s at %2$s', 'wire-desk' ),
				mysql2date( (string) get_option( 'date_format' ), $item->post_date ),
				mysql2date( (string) get_option( 'time_format' ), $item->post_date )
			)
		);

		if ( null !== $item->scored_at ) {
			$html .= '<br /><span class="wire-desk-muted">' . esc_html(
				sprintf(
					/* translators: %s: time since the post was scored, such as 5 mins. */
					__( 'Scored %s ago', 'wire-desk' ),
					human_time_diff( (int) strtotime( $item->scored_at . ' UTC' ) )
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object|array<string, mixed> $item        Row.
	 * @param string                      $column_name Column.
	 */
	public function column_default( $item, $column_name ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by WP_List_Table.
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$views  = [];
		$labels = self::view_labels();

		foreach ( array_keys( self::view_states() ) as $view ) {
			$count   = $this->repository->count( $this->filters( $view ), self::COUNT_MAX + 1 );
			$current = $view === $this->view;

			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $this->url( [ 'view' => $view ] ) ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html( $labels[ $view ] ),
				esc_html( $count > self::COUNT_MAX ? number_format_i18n( self::COUNT_MAX ) . '+' : number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label for="wire-desk-band-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by priority', 'wire-desk' ); ?></label>
			<select name="band" id="wire-desk-band-filter">
				<option value=""><?php esc_html_e( 'All priorities', 'wire-desk' ); ?></option>
				<?php foreach ( self::band_slugs() as $slug => $band ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $this->band, $slug ); ?>><?php echo esc_html( Wire_Post::band_labels()[ $band ] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'wire-desk' ), '', 'filter_action', false, [ 'id' => 'wire-desk-filter-submit' ] ); ?>
		</div>
		<?php
	}

	/**
	 * Newer and older links, paged from the first and last rows shown.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function pagination( $which ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by WP_List_Table.
		if ( ! $this->has_newer && ! $this->has_older ) {
			return;
		}

		$first = reset( $this->items );
		$last  = end( $this->items );

		$links = [
			$this->has_newer
				? $this->page_link( $this->url(), 'first-page', __( 'Newest posts', 'wire-desk' ), '&laquo;' )
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>',
			$this->has_newer && $first instanceof Wire_Post
				? $this->page_link( $this->url( [ 'after' => $first->post_id ] ), 'prev-page', __( 'Newer posts', 'wire-desk' ), '&lsaquo;' )
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>',
			$this->has_older && $last instanceof Wire_Post
				? $this->page_link( $this->url( [ 'before' => $last->post_id ] ), 'next-page', __( 'Older posts', 'wire-desk' ), '&rsaquo;' )
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>',
		];

		echo '<div class="tablenav-pages"><span class="pagination-links">' . implode( ' ', $links ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page_link().
	}

	/**
	 * A link to another page of the table.
	 *
	 * @param string $url    Page URL.
	 * @param string $button Button class.
	 * @param string $label  Screen reader label.
	 * @param string $arrow  Arrow entity.
	 */
	private function page_link( string $url, string $button, string $label, string $arrow ): string {
		return sprintf(
			'<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a>',
			esc_attr( $button ),
			esc_url( $url ),
			esc_html( $label ),
			$arrow
		);
	}

	/**
	 * The queue URL for the current view and band, with other arguments.
	 *
	 * @param array<string, int|string> $args Arguments to add or replace.
	 */
	private function url( array $args = [] ): string {
		$args = array_merge(
			[
				'view' => $this->view,
				'band' => $this->band,
			],
			$args
		);

		if ( self::DEFAULT_VIEW === $args['view'] ) {
			unset( $args['view'] );
		}

		return add_query_arg(
			[ 'page' => Queue_Screen::SCREEN_ID ] + array_filter( $args, static fn ( $value ): bool => '' !== $value ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Repository filters for a view and the current band.
	 *
	 * @param string $view View slug.
	 * @return array{state?: int, band?: int}
	 */
	private function filters( string $view ): array {
		$filters = [];
		$state   = self::view_states()[ $view ] ?? null;

		if ( null !== $state ) {
			$filters['state'] = $state;
		}

		if ( '' !== $this->band ) {
			$filters['band'] = self::band_slugs()[ $this->band ];
		}

		return $filters;
	}

	/**
	 * The paging cursor for a row.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post|null $item Row.
	 * @return array{post_date: string, post_id: int}|null
	 */
	private function cursor( ?Wire_Post $item ): ?array {
		return null === $item
			? null
			: [
				'post_date' => $item->post_date,
				'post_id'   => $item->post_id,
			];
	}
}
