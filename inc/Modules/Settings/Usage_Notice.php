<?php
/**
 * Tells admins when scoring is paused, and lets them resume it.
 *
 * @package JDeepD\AI_Post_Triage\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\AI_Post_Triage\Modules\Settings;

use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Jobs\Usage;
use JDeepD\AI_Post_Triage\Modules\Queue\Queue_Screen;

/**
 * Class - Usage_Notice
 */
final class Usage_Notice implements Registrable {
	/**
	 * Admin-post action that resumes scoring.
	 */
	public const RESUME_ACTION = 'ai_post_triage_resume';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::RESUME_ACTION, [ $this, 'handle_resume' ] );
	}

	/**
	 * Shows a notice on AI Post Triage's screens while scoring is paused or today's budget is spent.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! self::is_ai_post_triage_screen() ) {
			return;
		}

		$message = self::paused_message();

		if ( '' !== $message ) {
			wp_admin_notice(
				$message . ' <a href="' . esc_url( self::resume_url() ) . '">' . esc_html__( 'Resume now', 'ai-post-triage' ) . '</a>',
				[ 'type' => 'error' ]
			);
			return;
		}

		if ( Usage::budget_reached() ) {
			wp_admin_notice(
				esc_html( self::budget_message() ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Screen::SCREEN_ID ) ) . '">' . esc_html__( 'Change the budget', 'ai-post-triage' ) . '</a>',
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
			wp_die( esc_html__( 'Sorry, you are not allowed to resume scoring.', 'ai-post-triage' ), 403 );
		}

		Usage::resume();

		if ( wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . Admin_Screen::SCREEN_ID ) ) ) {
			exit;
		}
	}

	/**
	 * Whether the current screen is the review queue or the settings screen.
	 */
	public static function is_ai_post_triage_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return str_ends_with( $screen->id, '_page_' . Queue_Screen::SCREEN_ID ) || str_ends_with( $screen->id, '_page_' . Admin_Screen::SCREEN_ID );
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
				__( 'AI Post Triage paused scoring after %1$d failed requests in a row. It tries again at %2$s.', 'ai-post-triage' ),
				$usage['failures'],
				(string) wp_date( (string) get_option( 'time_format' ), $until )
			)
		) . ( '' !== $usage['last_error'] ? ' ' . esc_html(
			sprintf(
				/* translators: %s: error message. */
				__( 'Last error: %s', 'ai-post-triage' ),
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
			__( 'AI Post Triage has spent today\'s budget of $%s. Scoring continues tomorrow.', 'ai-post-triage' ),
			number_format_i18n( Settings::get()['daily_budget'], 2 )
		);
	}
}
