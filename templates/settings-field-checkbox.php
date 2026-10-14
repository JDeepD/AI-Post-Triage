<?php
/**
 * Checkbox settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

/** @var array{id: string, name: string, value: mixed, label: string, description: string} $args */
?>
<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" />
<label for="<?php echo esc_attr( $args['id'] ); ?>">
	<input type="checkbox" id="<?php echo esc_attr( $args['id'] ); ?>" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( (bool) $args['value'] ); ?> />
	<?php echo esc_html( $args['label'] ); ?>
</label>
<?php if ( '' !== $args['description'] ) : ?>
	<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
<?php endif; ?>
