<?php
/**
 * Newsroom context settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\Wire_Desk\Modules\Settings\Settings;

/** @var array{id: string, name: string, value: mixed, description: string} $args */
?>
<textarea
	class="large-text"
	rows="3"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	name="<?php echo esc_attr( $args['name'] ); ?>"
	maxlength="<?php echo esc_attr( (string) Settings::MAX_CONTEXT_LENGTH ); ?>"
><?php echo esc_textarea( is_string( $args['value'] ) ? $args['value'] : '' ); ?></textarea>
<?php if ( '' !== $args['description'] ) : ?>
	<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
<?php endif; ?>
