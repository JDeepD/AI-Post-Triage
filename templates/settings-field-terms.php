<?php
/**
 * Terms settings field: picked terms, a taxonomy dropdown, and a search for that taxonomy's terms.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, taxonomies: \WP_Taxonomy[], post_types: array<string, string[]>, selected: array<string, \WP_Term[]>} $args */
$ai_post_triage_labels = [];

foreach ( $args['taxonomies'] as $ai_post_triage_taxonomy ) {
	$ai_post_triage_labels[ $ai_post_triage_taxonomy->name ] = (string) $ai_post_triage_taxonomy->labels->name;
}
?>
<div id="<?php echo esc_attr( $args['id'] ); ?>" class="ai-post-triage-terms" data-ai-post-triage-terms data-input-name="<?php echo esc_attr( $args['name'] ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[_]" value="" />

	<ul class="ai-post-triage-terms-selected" data-ai-post-triage-selected-terms>
		<?php foreach ( $args['selected'] as $ai_post_triage_taxonomy_name => $ai_post_triage_terms ) : ?>
			<?php foreach ( $ai_post_triage_terms as $ai_post_triage_term ) : ?>
				<li>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $args['name'] . '[' . $ai_post_triage_taxonomy_name . '][]' ); ?>" value="<?php echo esc_attr( (string) $ai_post_triage_term->term_id ); ?>" checked />
						<?php echo esc_html( $ai_post_triage_term->name ); ?>
						<span class="ai-post-triage-terms-meta"><?php echo esc_html( $ai_post_triage_labels[ $ai_post_triage_taxonomy_name ] ?? $ai_post_triage_taxonomy_name ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</ul>

	<?php if ( $args['taxonomies'] ) : ?>
		<div class="ai-post-triage-terms-picker">
			<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-taxonomy"><?php esc_html_e( 'Taxonomy', 'ai-post-triage' ); ?></label>
			<select id="<?php echo esc_attr( $args['id'] ); ?>-taxonomy" data-ai-post-triage-term-taxonomy>
				<?php foreach ( $args['taxonomies'] as $ai_post_triage_taxonomy ) : ?>
					<option value="<?php echo esc_attr( $ai_post_triage_taxonomy->name ); ?>" data-label="<?php echo esc_attr( $ai_post_triage_labels[ $ai_post_triage_taxonomy->name ] ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: taxonomy name, such as Categories, 2: post type names, such as Posts, Wire items. */
								__( '%1$s (%2$s)', 'ai-post-triage' ),
								$ai_post_triage_labels[ $ai_post_triage_taxonomy->name ],
								implode( ', ', $args['post_types'][ $ai_post_triage_taxonomy->name ] ?? [] )
							)
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-search"><?php esc_html_e( 'Search terms', 'ai-post-triage' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $args['id'] ); ?>-search"
				class="regular-text"
				data-ai-post-triage-term-search
				placeholder="<?php esc_attr_e( 'Search to add a term', 'ai-post-triage' ); ?>"
				autocomplete="off"
			/>
		</div>
		<ul class="ai-post-triage-terms-results" data-ai-post-triage-term-results aria-live="polite"></ul>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'Choose a taxonomy, then search its terms. Posts with any of the picked terms are wire content. Untick a term to remove it.', 'ai-post-triage' ); ?></p>
</div>
