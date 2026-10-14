<?php
/**
 * The questions, weights and thresholds a post is scored against.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Scoring;

use JDeepD\Wire_Desk\Modules\Settings\Settings;

/**
 * Class - Rubric
 *
 * @phpstan-import-type Question from \JDeepD\Wire_Desk\Modules\Settings\Settings
 */
final class Rubric {
	/**
	 * Questions asked about each post.
	 *
	 * @var Question[]
	 */
	public readonly array $questions;

	/**
	 * Multiplier for each weight.
	 *
	 * @var array<string, int>
	 */
	public readonly array $weights;

	/**
	 * Lowest score that is High.
	 *
	 * @var int
	 */
	public readonly int $high_threshold;

	/**
	 * Lowest score that is Medium.
	 *
	 * @var int
	 */
	public readonly int $medium_threshold;

	/**
	 * Constructor.
	 *
	 * @param Question[]         $questions        Questions asked about each post.
	 * @param array<string, int> $weights          Multiplier for each weight.
	 * @param int                $high_threshold   Lowest score that is High.
	 * @param int                $medium_threshold Lowest score that is Medium.
	 */
	public function __construct( array $questions, array $weights, int $high_threshold, int $medium_threshold ) {
		$this->questions        = $questions;
		$this->weights          = $weights;
		$this->high_threshold   = $high_threshold;
		$this->medium_threshold = $medium_threshold;
	}

	/**
	 * Builds the rubric from the saved settings.
	 */
	public static function from_settings(): self {
		$settings = Settings::get();

		return new self( $settings['questions'], Settings::weights(), $settings['high_threshold'], $settings['medium_threshold'] );
	}

	/**
	 * The multiplier for a weight. Unknown weights count as 1.
	 *
	 * @param string $weight Weight name.
	 */
	public function multiplier( string $weight ): int {
		return max( 0, $this->weights[ $weight ] ?? 1 );
	}
}
