<?php
/**
 * Model settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, value: mixed, models: array<string, string>|\WP_Error} $args */

$wire_desk_value = is_string( $args['value'] ) ? $args['value'] : '';
?>
<?php if ( is_wp_error( $args['models'] ) ) : ?>
	<input
		type="text"
		class="regular-text"
		id="<?php echo esc_attr( $args['id'] ); ?>"
		name="<?php echo esc_attr( $args['name'] ); ?>"
		value="<?php echo esc_attr( $wire_desk_value ); ?>"
	/>
	<p class="description">
		<?php echo esc_html( $args['models']->get_error_message() ); ?>
		<?php esc_html_e( 'Enter an OpenRouter model ID instead, e.g. typesafe/jev-1.13.', 'wire-desk' ); ?>
	</p>
<?php else : ?>
	<select id="<?php echo esc_attr( $args['id'] ); ?>" name="<?php echo esc_attr( $args['name'] ); ?>">
		<?php if ( '' !== $wire_desk_value && ! isset( $args['models'][ $wire_desk_value ] ) ) : ?>
			<option value="<?php echo esc_attr( $wire_desk_value ); ?>" selected>
				<?php
				/* translators: %s: model ID. */
				echo esc_html( sprintf( __( '%s (not in the list)', 'wire-desk' ), $wire_desk_value ) );
				?>
			</option>
		<?php endif; ?>
		<?php foreach ( $args['models'] as $wire_desk_model_id => $wire_desk_model_name ) : ?>
			<option value="<?php echo esc_attr( $wire_desk_model_id ); ?>" <?php selected( $wire_desk_value, $wire_desk_model_id ); ?>><?php echo esc_html( $wire_desk_model_name ); ?></option>
		<?php endforeach; ?>
	</select>
<?php endif; ?>
