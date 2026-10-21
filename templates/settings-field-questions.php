<?php
/**
 * Questions settings field.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\Wire_Desk\Core\Templates;

/** @var array{id: string, name: string, value: mixed, weights: array<string, int>, max: int} $args */

$wire_desk_questions = is_array( $args['value'] ) ? array_values( $args['value'] ) : [];
?>
<div id="<?php echo esc_attr( $args['id'] ); ?>" class="wire-desk-questions" data-wire-desk-questions-field>
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[_]" value="" />
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Question', 'wire-desk' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expected answer', 'wire-desk' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Weight', 'wire-desk' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required', 'wire-desk' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wire-desk' ); ?></span></th>
			</tr>
		</thead>
		<tbody data-wire-desk-questions>
			<?php foreach ( $wire_desk_questions as $wire_desk_index => $wire_desk_question ) : ?>
				<?php
				Templates::get_template_part(
					'settings-question-row',
					null,
					[
						'name'     => $args['name'],
						'index'    => (string) $wire_desk_index,
						'question' => $wire_desk_question,
						'weights'  => $args['weights'],
					],
					true
				);
				?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<template data-wire-desk-question-template>
		<?php
		Templates::get_template_part(
			'settings-question-row',
			null,
			[
				'name'     => $args['name'],
				'index'    => '__INDEX__',
				'question' => [],
				'weights'  => $args['weights'],
			],
			true
		);
		?>
	</template>
	<p>
		<button type="button" class="button" data-wire-desk-add-question data-max="<?php echo esc_attr( (string) $args['max'] ); ?>">
			<?php esc_html_e( 'Add question', 'wire-desk' ); ?>
		</button>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: %d: maximum number of questions. */
			esc_html__( 'The model answers each question yes or no. A post scores higher the more answers match. If a required question doesn\'t match, the post is Low. Up to %d questions.', 'wire-desk' ),
			(int) $args['max']
		);
		?>
	</p>
</div>
