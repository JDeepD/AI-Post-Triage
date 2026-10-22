<?php
/**
 * Questions settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\AI_Post_Triage\Core\Templates;

/** @var array{id: string, name: string, value: mixed, weights: array<string, int>, max: int} $args */

$ai_post_triage_questions = is_array( $args['value'] ) ? array_values( $args['value'] ) : [];
?>
<div id="<?php echo esc_attr( $args['id'] ); ?>" class="ai-post-triage-questions" data-ai-post-triage-questions-field>
	<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>[_]" value="" />
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Question', 'ai-post-triage' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expected answer', 'ai-post-triage' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Weight', 'ai-post-triage' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required', 'ai-post-triage' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ai-post-triage' ); ?></span></th>
			</tr>
		</thead>
		<tbody data-ai-post-triage-questions>
			<?php foreach ( $ai_post_triage_questions as $ai_post_triage_index => $ai_post_triage_question ) : ?>
				<?php
				Templates::get_template_part(
					'settings-question-row',
					null,
					[
						'name'     => $args['name'],
						'index'    => (string) $ai_post_triage_index,
						'question' => $ai_post_triage_question,
						'weights'  => $args['weights'],
					],
					true
				);
				?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<template data-ai-post-triage-question-template>
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
		<button type="button" class="button" data-ai-post-triage-add-question data-max="<?php echo esc_attr( (string) $args['max'] ); ?>">
			<?php esc_html_e( 'Add question', 'ai-post-triage' ); ?>
		</button>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: %d: maximum number of questions. */
			esc_html__( 'The model answers each question yes or no. A post scores higher the more answers match. If a required question doesn\'t match, the post is Low. Up to %d questions.', 'ai-post-triage' ),
			(int) $args['max']
		);
		?>
	</p>
</div>
