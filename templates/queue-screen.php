<?php
/**
 * Template for the review queue screen.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

use JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table;
use JDeepD\Wire_Desk\Modules\Queue\Queue_Screen;

/** @var array{table: \JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table} $args */
$wire_desk_table = $args['table'];
?>

<div class="wrap wire-desk-queue">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Review queue', 'wire-desk' ); ?></h1>
	<hr class="wp-header-end" />

	<?php $wire_desk_table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Queue_Screen::SCREEN_ID ); ?>" />
		<?php if ( Queue_List_Table::DEFAULT_VIEW !== $wire_desk_table->get_view() ) : ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $wire_desk_table->get_view() ); ?>" />
		<?php endif; ?>
		<?php $wire_desk_table->display(); ?>
	</form>
</div>
