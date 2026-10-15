<?php
/**
 * Try a headline settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, description: string} $args */
?>
<div class="wire-desk-try" data-wire-desk-try>
	<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Headline', 'wire-desk' ); ?></label>
	<textarea
		id="<?php echo esc_attr( $args['id'] ); ?>"
		class="large-text"
		rows="2"
		maxlength="500"
		placeholder="<?php esc_attr_e( 'Paste a wire headline', 'wire-desk' ); ?>"
		data-wire-desk-try-headline
	></textarea>
	<p>
		<button type="button" class="button" data-wire-desk-try-submit><?php esc_html_e( 'Try it', 'wire-desk' ); ?></button>
		<span class="spinner" data-wire-desk-try-spinner></span>
	</p>
	<?php if ( '' !== $args['description'] ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<div class="wire-desk-try-result" data-wire-desk-try-result aria-live="polite"></div>
</div>
