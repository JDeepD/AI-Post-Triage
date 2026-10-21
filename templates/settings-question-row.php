<?php
/**
 * A row of the questions settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{name: string, index: string, question: array<string, mixed>, weights: array<string, int>} $args */

$wire_desk_question = $args['question'];
$wire_desk_prefix   = $args['name'] . '[' . $args['index'] . ']';
$wire_desk_labels   = [
	'high'   => __( 'High', 'wire-desk' ),
	'medium' => __( 'Medium', 'wire-desk' ),
	'low'    => __( 'Low', 'wire-desk' ),
];
?>
<tr>
	<td>
		<input type="hidden" name="<?php echo esc_attr( $wire_desk_prefix ); ?>[id]" value="<?php echo esc_attr( is_string( $wire_desk_question['id'] ?? null ) ? $wire_desk_question['id'] : '' ); ?>" />
		<input
			type="text"
			class="large-text"
			name="<?php echo esc_attr( $wire_desk_prefix ); ?>[question]"
			value="<?php echo esc_attr( is_string( $wire_desk_question['question'] ?? null ) ? $wire_desk_question['question'] : '' ); ?>"
			aria-label="<?php esc_attr_e( 'Question', 'wire-desk' ); ?>"
		/>
	</td>
	<td>
		<select name="<?php echo esc_attr( $wire_desk_prefix ); ?>[expected]" aria-label="<?php esc_attr_e( 'Expected answer', 'wire-desk' ); ?>">
			<option value="yes" <?php selected( $wire_desk_question['expected'] ?? 'yes', 'yes' ); ?>><?php esc_html_e( 'Yes', 'wire-desk' ); ?></option>
			<option value="no" <?php selected( $wire_desk_question['expected'] ?? 'yes', 'no' ); ?>><?php esc_html_e( 'No', 'wire-desk' ); ?></option>
		</select>
	</td>
	<td>
		<select name="<?php echo esc_attr( $wire_desk_prefix ); ?>[weight]" aria-label="<?php esc_attr_e( 'Weight', 'wire-desk' ); ?>">
			<?php foreach ( array_keys( $args['weights'] ) as $wire_desk_weight ) : ?>
				<option value="<?php echo esc_attr( $wire_desk_weight ); ?>" <?php selected( $wire_desk_question['weight'] ?? 'medium', $wire_desk_weight ); ?>><?php echo esc_html( $wire_desk_labels[ $wire_desk_weight ] ?? $wire_desk_weight ); ?></option>
			<?php endforeach; ?>
		</select>
	</td>
	<td>
		<input type="hidden" name="<?php echo esc_attr( $wire_desk_prefix ); ?>[required]" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $wire_desk_prefix ); ?>[required]" value="1" aria-label="<?php esc_attr_e( 'Required', 'wire-desk' ); ?>" <?php checked( ! empty( $wire_desk_question['required'] ) ); ?> />
	</td>
	<td>
		<button type="button" class="button-link button-link-delete" data-wire-desk-remove-question><?php esc_html_e( 'Remove', 'wire-desk' ); ?></button>
	</td>
</tr>
