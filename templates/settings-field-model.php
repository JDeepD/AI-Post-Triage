<?php
/**
 * Model settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, value: mixed, models: array<string, string>|\WP_Error} $args */

$ai_post_triage_value = is_string( $args['value'] ) ? $args['value'] : '';
?>
<?php if ( is_wp_error( $args['models'] ) ) : ?>
	<input
		type="text"
		class="regular-text"
		id="<?php echo esc_attr( $args['id'] ); ?>"
		name="<?php echo esc_attr( $args['name'] ); ?>"
		value="<?php echo esc_attr( $ai_post_triage_value ); ?>"
	/>
	<p class="description">
		<?php echo esc_html( $args['models']->get_error_message() ); ?>
		<?php esc_html_e( 'Enter an OpenRouter model ID instead, e.g. typesafe/jev-1.13.', 'ai-post-triage' ); ?>
	</p>
<?php else : ?>
	<select id="<?php echo esc_attr( $args['id'] ); ?>" name="<?php echo esc_attr( $args['name'] ); ?>">
		<?php if ( '' !== $ai_post_triage_value && ! isset( $args['models'][ $ai_post_triage_value ] ) ) : ?>
			<option value="<?php echo esc_attr( $ai_post_triage_value ); ?>" selected>
				<?php
				/* translators: %s: model ID. */
				echo esc_html( sprintf( __( '%s (not in the list)', 'ai-post-triage' ), $ai_post_triage_value ) );
				?>
			</option>
		<?php endif; ?>
		<?php foreach ( $args['models'] as $ai_post_triage_model_id => $ai_post_triage_model_name ) : ?>
			<option value="<?php echo esc_attr( $ai_post_triage_model_id ); ?>" <?php selected( $ai_post_triage_value, $ai_post_triage_model_id ); ?>><?php echo esc_html( $ai_post_triage_model_name ); ?></option>
		<?php endforeach; ?>
	</select>
<?php endif; ?>
