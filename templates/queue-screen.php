<?php
/**
 * Template for the review queue screen.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\AI_Post_Triage\Modules\Queue\Queue_List_Table;
use JDeepD\AI_Post_Triage\Modules\Queue\Queue_Screen;

/** @var array{table: \JDeepD\AI_Post_Triage\Modules\Queue\Queue_List_Table, notices: array<int, array{type: string, message: string}>} $args */
$ai_post_triage_table = $args['table'];
?>

<div class="wrap ai-post-triage-queue">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Review queue', 'ai-post-triage' ); ?></h1>
	<hr class="wp-header-end" />

	<?php
	foreach ( $args['notices'] as $ai_post_triage_notice ) {
		wp_admin_notice(
			esc_html( $ai_post_triage_notice['message'] ),
			[
				'type'        => $ai_post_triage_notice['type'],
				'dismissible' => true,
			]
		);
	}
	?>

	<?php $ai_post_triage_table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Queue_Screen::SCREEN_ID ); ?>" />
		<?php if ( Queue_List_Table::DEFAULT_VIEW !== $ai_post_triage_table->get_view() ) : ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $ai_post_triage_table->get_view() ); ?>" />
		<?php endif; ?>
		<?php $ai_post_triage_table->display(); ?>
	</form>
</div>
