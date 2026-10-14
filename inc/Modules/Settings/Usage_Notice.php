<?php
/**
 * Tells admins when scoring is paused, and lets them resume it.
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;
use JDeepD\Wire_Desk\Jobs\Usage;

/**
 * Class - Usage_Notice
 */
final class Usage_Notice implements Registrable {
	/**
	 * Admin-post action that resumes scoring.
	 */
	public const RESUME_ACTION = 'wire_desk_resume';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::RESUME_ACTION, [ $this, 'handle_resume' ] );
	}

	/**
	 * Shows a notice while scoring is paused or today's budget is spent.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$message = self::paused_message();

		if ( '' !== $message ) {
			wp_admin_notice(
				$message . ' <a href="' . esc_url( self::resume_url() ) . '">' . esc_html__( 'Resume now', 'wire-desk' ) . '</a>',
				[ 'type' => 'error' ]
			);
			return;
		}

		if ( Usage::budget_reached() ) {
			wp_admin_notice(
				esc_html( self::budget_message() ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Screen::SCREEN_ID ) ) . '">' . esc_html__( 'Change the budget', 'wire-desk' ) . '</a>',
				[ 'type' => 'warning' ]
			);
		}
	}

	/**
	 * Resumes scoring and returns to the previous screen.
	 */
	public function handle_resume(): void {
		check_admin_referer( self::RESUME_ACTION );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to resume scoring.', 'wire-desk' ), 403 );
		}

		Usage::resume();

		if ( wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . Admin_Screen::SCREEN_ID ) ) ) {
			exit;
		}
	}

	/**
	 * The link that resumes scoring.
	 */
	public static function resume_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESUME_ACTION ), self::RESUME_ACTION );
	}

	/**
	 * Why scoring is paused, as escaped HTML, or an empty string when it isn't.
	 */
	public static function paused_message(): string {
		$until = Usage::paused_until();

		if ( ! $until ) {
			return '';
		}

		$usage = Usage::get();

		return esc_html(
			sprintf(
				/* translators: 1: number of failed requests, 2: time scoring resumes. */
				__( 'Wire Desk paused scoring after %1$d failed requests in a row. It tries again at %2$s.', 'wire-desk' ),
				$usage['failures'],
				(string) wp_date( (string) get_option( 'time_format' ), $until )
			)
		) . ( '' !== $usage['last_error'] ? ' ' . esc_html(
			sprintf(
				/* translators: %s: error message. */
				__( 'Last error: %s', 'wire-desk' ),
				$usage['last_error']
			)
		) : '' );
	}

	/**
	 * The notice for a spent budget.
	 */
	public static function budget_message(): string {
		return sprintf(
			/* translators: %s: daily budget in US dollars. */
			__( 'Wire Desk has spent today\'s budget of $%s. Scoring continues tomorrow.', 'wire-desk' ),
			number_format_i18n( Settings::get()['daily_budget'], 2 )
		);
	}
}
