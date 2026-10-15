<?php
/**
 * Terms settings field: picked terms, a taxonomy dropdown, and a search for that taxonomy's terms.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, taxonomies: \WP_Taxonomy[], post_types: array<string, string[]>, selected: array<string, \WP_Term[]>} $args */
$wire_desk_labels = [];

foreach ( $args['taxonomies'] as $wire_desk_taxonomy ) {
	$wire_desk_labels[ $wire_desk_taxonomy->name ] = (string) $wire_desk_taxonomy->labels->name;
}
?>
<div id="<?php echo esc_attr( $args['id'] ); ?>" class="wire-desk-terms" data-wire-desk-terms data-input-name="<?php echo esc_attr( $args['name'] ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[_]" value="" />

	<ul class="wire-desk-terms-selected" data-wire-desk-selected-terms>
		<?php foreach ( $args['selected'] as $wire_desk_taxonomy_name => $wire_desk_terms ) : ?>
			<?php foreach ( $wire_desk_terms as $wire_desk_term ) : ?>
				<li>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $args['name'] . '[' . $wire_desk_taxonomy_name . '][]' ); ?>" value="<?php echo esc_attr( (string) $wire_desk_term->term_id ); ?>" checked />
						<?php echo esc_html( $wire_desk_term->name ); ?>
						<span class="wire-desk-terms-meta"><?php echo esc_html( $wire_desk_labels[ $wire_desk_taxonomy_name ] ?? $wire_desk_taxonomy_name ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</ul>

	<?php if ( $args['taxonomies'] ) : ?>
		<div class="wire-desk-terms-picker">
			<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-taxonomy"><?php esc_html_e( 'Taxonomy', 'wire-desk' ); ?></label>
			<select id="<?php echo esc_attr( $args['id'] ); ?>-taxonomy" data-wire-desk-term-taxonomy>
				<?php foreach ( $args['taxonomies'] as $wire_desk_taxonomy ) : ?>
					<option value="<?php echo esc_attr( $wire_desk_taxonomy->name ); ?>" data-label="<?php echo esc_attr( $wire_desk_labels[ $wire_desk_taxonomy->name ] ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: taxonomy name, such as Categories, 2: post type names, such as Posts, Wire items. */
								__( '%1$s (%2$s)', 'wire-desk' ),
								$wire_desk_labels[ $wire_desk_taxonomy->name ],
								implode( ', ', $args['post_types'][ $wire_desk_taxonomy->name ] ?? [] )
							)
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-search"><?php esc_html_e( 'Search terms', 'wire-desk' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $args['id'] ); ?>-search"
				class="regular-text"
				data-wire-desk-term-search
				placeholder="<?php esc_attr_e( 'Search to add a term', 'wire-desk' ); ?>"
				autocomplete="off"
			/>
		</div>
		<ul class="wire-desk-terms-results" data-wire-desk-term-results aria-live="polite"></ul>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'Choose a taxonomy, then search its terms. Posts with any of the picked terms are wire content. Untick a term to remove it.', 'wire-desk' ); ?></p>
</div>
