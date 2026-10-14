<?php
/**
 * Terms settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

/** @var array{id: string, name: string, taxonomies: \WP_Taxonomy[], post_types: array<string, string[]>, selected: array<string, \WP_Term[]>} $args */
?>
<div id="<?php echo esc_attr( $args['id'] ); ?>" class="wire-desk-terms">
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[_]" value="" />
	<?php foreach ( $args['taxonomies'] as $taxonomy ) : ?>
		<?php $input_name = $args['name'] . '[' . $taxonomy->name . '][]'; ?>
		<fieldset class="wire-desk-terms-taxonomy" data-wire-desk-terms data-taxonomy="<?php echo esc_attr( $taxonomy->name ); ?>" data-input-name="<?php echo esc_attr( $input_name ); ?>">
			<legend>
				<?php echo esc_html( $taxonomy->labels->name ); ?>
				<code><?php echo esc_html( $taxonomy->name ); ?></code>
				<span class="wire-desk-terms-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: post type names, such as Posts, Wire items. */
							__( 'on %s', 'wire-desk' ),
							implode( ', ', $args['post_types'][ $taxonomy->name ] ?? [] )
						)
					);

					if ( ! $taxonomy->show_ui ) {
						echo ' · ' . esc_html__( 'no admin screen', 'wire-desk' );
					}
					?>
				</span>
			</legend>
			<ul class="wire-desk-terms-selected" data-wire-desk-selected-terms>
				<?php foreach ( $args['selected'][ $taxonomy->name ] ?? [] as $term ) : ?>
					<li>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) $term->term_id ); ?>" checked />
							<?php echo esc_html( $term->name ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<input
				type="search"
				class="regular-text"
				data-wire-desk-term-search
				placeholder="<?php esc_attr_e( 'Search to add a term', 'wire-desk' ); ?>"
				aria-label="<?php echo esc_attr( $taxonomy->labels->search_items ); ?>"
				autocomplete="off"
			/>
			<ul class="wire-desk-terms-results" data-wire-desk-term-results aria-live="polite"></ul>
		</fieldset>
	<?php endforeach; ?>
	<p class="description"><?php esc_html_e( 'Posts with any of these terms are wire content. Untick a term to remove it.', 'wire-desk' ); ?></p>
</div>
