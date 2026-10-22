<?php
/**
 * Try a headline settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, description: string} $args */
?>
<div class="ai-post-triage-try" data-ai-post-triage-try>
	<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Headline', 'ai-post-triage' ); ?></label>
	<textarea
		id="<?php echo esc_attr( $args['id'] ); ?>"
		class="large-text"
		rows="2"
		maxlength="500"
		placeholder="<?php esc_attr_e( 'Paste a wire headline', 'ai-post-triage' ); ?>"
		data-ai-post-triage-try-headline
	></textarea>
	<p>
		<button type="button" class="button" data-ai-post-triage-try-submit><?php esc_html_e( 'Try it', 'ai-post-triage' ); ?></button>
		<span class="spinner" data-ai-post-triage-try-spinner></span>
	</p>
	<?php if ( '' !== $args['description'] ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<div class="ai-post-triage-try-result" data-ai-post-triage-try-result aria-live="polite"></div>
</div>
