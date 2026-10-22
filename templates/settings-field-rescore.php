<?php
/**
 * Re-score the newest posts settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, description: string, max: int} $args */
?>
<div class="ai-post-triage-rescore" data-ai-post-triage-rescore>
	<label for="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Newest', 'ai-post-triage' ); ?></label>
	<input
		type="number"
		class="small-text"
		id="<?php echo esc_attr( $args['id'] ); ?>"
		min="1"
		max="<?php echo esc_attr( (string) $args['max'] ); ?>"
		step="1"
		value="100"
		data-ai-post-triage-rescore-limit
	/>
	<?php esc_html_e( 'posts', 'ai-post-triage' ); ?>
	<button type="button" class="button" data-ai-post-triage-rescore-submit><?php esc_html_e( 'Re-score', 'ai-post-triage' ); ?></button>
	<span class="spinner" data-ai-post-triage-rescore-spinner></span>
	<?php if ( '' !== $args['description'] ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<p class="ai-post-triage-rescore-result" data-ai-post-triage-rescore-result aria-live="polite"></p>
</div>
