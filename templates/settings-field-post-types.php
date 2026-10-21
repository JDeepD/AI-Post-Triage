<?php
/**
 * Post types settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, value: mixed, post_types: \WP_Post_Type[]} $args */

$wire_desk_selected = is_array( $args['value'] ) ? $args['value'] : [];
?>
<fieldset id="<?php echo esc_attr( $args['id'] ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[]" value="" />
	<?php foreach ( $args['post_types'] as $post_type ) : ?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $args['name'] ); ?>[]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $wire_desk_selected, true ) ); ?> />
			<?php echo esc_html( $post_type->labels->name ); ?>
			<code><?php echo esc_html( $post_type->name ); ?></code>
		</label>
		<br />
	<?php endforeach; ?>
	<p class="description"><?php esc_html_e( 'Every post of these types is wire content.', 'wire-desk' ); ?></p>
</fieldset>
