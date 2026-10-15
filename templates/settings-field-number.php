<?php
/**
 * Number settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, value: mixed, description: string, min: int|float|null, max: int|float|null, step: int|float|null} $args */
?>
<input
	type="number"
	class="small-text"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	name="<?php echo esc_attr( $args['name'] ); ?>"
	value="<?php echo esc_attr( is_scalar( $args['value'] ) ? (string) $args['value'] : '' ); ?>"
	<?php foreach ( [ 'min', 'max', 'step' ] as $attribute ) : ?>
		<?php if ( null !== $args[ $attribute ] ) : ?>
			<?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( (string) $args[ $attribute ] ); ?>"
		<?php endif; ?>
	<?php endforeach; ?>
/>
<?php if ( '' !== $args['description'] ) : ?>
	<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
<?php endif; ?>
