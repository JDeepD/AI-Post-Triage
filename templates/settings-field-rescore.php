<?php
/**
 * Re-score the newest posts settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, description: string, max: int} $args */
?>
<div class="wire-desk-rescore" data-wire-desk-rescore>
	<label for="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Newest', 'wire-desk' ); ?></label>
	<input
		type="number"
		class="small-text"
		id="<?php echo esc_attr( $args['id'] ); ?>"
		min="1"
		max="<?php echo esc_attr( (string) $args['max'] ); ?>"
		step="1"
		value="100"
		data-wire-desk-rescore-limit
	/>
	<?php esc_html_e( 'posts', 'wire-desk' ); ?>
	<button type="button" class="button" data-wire-desk-rescore-submit><?php esc_html_e( 'Re-score', 'wire-desk' ); ?></button>
	<span class="spinner" data-wire-desk-rescore-spinner></span>
	<?php if ( '' !== $args['description'] ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<p class="wire-desk-rescore-result" data-wire-desk-rescore-result aria-live="polite"></p>
</div>
