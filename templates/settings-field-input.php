<?php
/**
 * What the model reads settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

/** @var array{id: string, name: string, value: mixed, modes: array<string, string>} $args */
?>
<fieldset id="<?php echo esc_attr( $args['id'] ); ?>">
	<?php foreach ( $args['modes'] as $mode => $label ) : ?>
		<label>
			<input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $args['value'], $mode ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<br />
	<?php endforeach; ?>
</fieldset>
