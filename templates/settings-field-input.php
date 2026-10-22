<?php
/**
 * What the model reads settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, name: string, value: mixed, modes: array<string, string>} $args */
?>
<fieldset id="<?php echo esc_attr( $args['id'] ); ?>">
	<?php foreach ( $args['modes'] as $mode => $ai_post_triage_label ) : ?>
		<label>
			<input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $args['value'], $mode ); ?> />
			<?php echo esc_html( $ai_post_triage_label ); ?>
		</label>
		<br />
	<?php endforeach; ?>
</fieldset>
