<?php
/**
 * Template for the Admin screen.
 *
 * @package wire-desk
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\Wire_Desk\Modules\Settings\Admin_Screen;
use JDeepD\Wire_Desk\Modules\Settings\Settings;

?>

<div class="wrap wire-desk-settings">
	<h1><?php esc_html_e( 'Wire Desk Settings', 'wire-desk' ); ?></h1>

	<?php settings_errors(); ?>

	<form action="options.php" method="post">
		<?php
		settings_fields( Settings::SETTING_GROUP );
		do_settings_sections( Admin_Screen::SCREEN_ID );
		submit_button();
		?>
	</form>
</div>
