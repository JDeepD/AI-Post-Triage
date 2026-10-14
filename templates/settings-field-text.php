<?php
/**
 * Text settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

/** @var array{id: string, name: string, value: mixed, description: string} $args */
?>
<input
	type="text"
	class="regular-text"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	name="<?php echo esc_attr( $args['name'] ); ?>"
	value="<?php echo esc_attr( is_scalar( $args['value'] ) ? (string) $args['value'] : '' ); ?>"
/>
<?php if ( '' !== $args['description'] ) : ?>
	<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
<?php endif; ?>
