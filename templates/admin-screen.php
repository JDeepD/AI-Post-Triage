<?php
/**
 * Template for the Admin screen.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\AI_Post_Triage\Modules\Settings\Admin_Screen;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;

?>

<div class="wrap ai-post-triage-settings">
	<h1><?php esc_html_e( 'AI Post Triage Settings', 'ai-post-triage' ); ?></h1>

	<?php settings_errors(); ?>

	<form action="options.php" method="post">
		<?php
		settings_fields( Settings::SETTING_GROUP );
		do_settings_sections( Admin_Screen::SCREEN_ID );
		submit_button();
		?>
	</form>
</div>
