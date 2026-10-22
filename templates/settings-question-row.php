<?php
/**
 * A row of the questions settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{name: string, index: string, question: array<string, mixed>, weights: array<string, int>} $args */

$ai_post_triage_question = $args['question'];
$ai_post_triage_prefix   = $args['name'] . '[' . $args['index'] . ']';
$ai_post_triage_labels   = [
	'high'   => __( 'High', 'ai-post-triage' ),
	'medium' => __( 'Medium', 'ai-post-triage' ),
	'low'    => __( 'Low', 'ai-post-triage' ),
];
?>
<tr>
	<td>
		<input type="hidden" name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[id]" value="<?php echo esc_attr( is_string( $ai_post_triage_question['id'] ?? null ) ? $ai_post_triage_question['id'] : '' ); ?>" />
		<input
			type="text"
			class="large-text"
			name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[question]"
			value="<?php echo esc_attr( is_string( $ai_post_triage_question['question'] ?? null ) ? $ai_post_triage_question['question'] : '' ); ?>"
			aria-label="<?php esc_attr_e( 'Question', 'ai-post-triage' ); ?>"
		/>
	</td>
	<td>
		<select name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[expected]" aria-label="<?php esc_attr_e( 'Expected answer', 'ai-post-triage' ); ?>">
			<option value="yes" <?php selected( $ai_post_triage_question['expected'] ?? 'yes', 'yes' ); ?>><?php esc_html_e( 'Yes', 'ai-post-triage' ); ?></option>
			<option value="no" <?php selected( $ai_post_triage_question['expected'] ?? 'yes', 'no' ); ?>><?php esc_html_e( 'No', 'ai-post-triage' ); ?></option>
		</select>
	</td>
	<td>
		<select name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[weight]" aria-label="<?php esc_attr_e( 'Weight', 'ai-post-triage' ); ?>">
			<?php foreach ( array_keys( $args['weights'] ) as $ai_post_triage_weight ) : ?>
				<option value="<?php echo esc_attr( $ai_post_triage_weight ); ?>" <?php selected( $ai_post_triage_question['weight'] ?? 'medium', $ai_post_triage_weight ); ?>><?php echo esc_html( $ai_post_triage_labels[ $ai_post_triage_weight ] ?? $ai_post_triage_weight ); ?></option>
			<?php endforeach; ?>
		</select>
	</td>
	<td>
		<input type="hidden" name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[required]" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $ai_post_triage_prefix ); ?>[required]" value="1" aria-label="<?php esc_attr_e( 'Required', 'ai-post-triage' ); ?>" <?php checked( ! empty( $ai_post_triage_question['required'] ) ); ?> />
	</td>
	<td>
		<button type="button" class="button-link button-link-delete" data-ai-post-triage-remove-question><?php esc_html_e( 'Remove', 'ai-post-triage' ); ?></button>
	</td>
</tr>
