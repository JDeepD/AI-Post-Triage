<?php
/**
 * A row of the wire post table.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Data;

/**
 * Class - Wire_Post
 */
final class Wire_Post {
	/**
	 * States.
	 */
	public const STATE_QUEUED    = 1;
	public const STATE_SCORED    = 2;
	public const STATE_FAILED    = 3;
	public const STATE_DISMISSED = 4;
	public const STATE_PUBLISHED = 5;

	/**
	 * Bands.
	 */
	public const BAND_NONE   = 0;
	public const BAND_LOW    = 1;
	public const BAND_MEDIUM = 2;
	public const BAND_HIGH   = 3;

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public readonly int $post_id;

	/**
	 * Post date.
	 *
	 * @var string
	 */
	public readonly string $post_date;

	/**
	 * One of the STATE_* constants.
	 *
	 * @var int
	 */
	public readonly int $state;

	/**
	 * One of the BAND_* constants.
	 *
	 * @var int
	 */
	public readonly int $band;

	/**
	 * Score from 0 to 100.
	 *
	 * @var int
	 */
	public readonly int $score;

	/**
	 * Model ID.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Input fingerprint, as 32 hex characters.
	 *
	 * @var ?string
	 */
	public readonly ?string $hash;

	/**
	 * Cost in US dollars.
	 *
	 * @var float
	 */
	public readonly float $cost;

	/**
	 * When it was last scored (GMT).
	 *
	 * @var ?string
	 */
	public readonly ?string $scored_at;

	/**
	 * Last scoring error.
	 *
	 * @var string
	 */
	public readonly string $error;

	/**
	 * Per-question answers.
	 *
	 * @var ?array<int|string, mixed>
	 */
	public readonly ?array $details;

	/**
	 * Failed scoring attempts in a row.
	 *
	 * @var int
	 */
	public readonly int $attempts;

	/**
	 * Constructor.
	 *
	 * @param int                       $post_id   Post ID.
	 * @param string                    $post_date Post date.
	 * @param int                       $state     One of the STATE_* constants.
	 * @param int                       $band      One of the BAND_* constants.
	 * @param int                       $score     Score from 0 to 100.
	 * @param string                    $model     Model ID.
	 * @param ?string                   $hash      Input fingerprint, as 32 hex characters.
	 * @param float                     $cost      Cost in US dollars.
	 * @param ?string                   $scored_at When it was last scored (GMT).
	 * @param string                    $error     Last scoring error.
	 * @param ?array<int|string, mixed> $details   Per-question answers.
	 * @param int                       $attempts  Failed scoring attempts in a row.
	 */
	public function __construct(
		int $post_id,
		string $post_date,
		int $state,
		int $band,
		int $score,
		string $model,
		?string $hash,
		float $cost,
		?string $scored_at,
		string $error,
		?array $details,
		int $attempts = 0
	) {
		$this->post_id   = $post_id;
		$this->post_date = $post_date;
		$this->state     = $state;
		$this->band      = $band;
		$this->score     = $score;
		$this->model     = $model;
		$this->hash      = $hash;
		$this->cost      = $cost;
		$this->scored_at = $scored_at;
		$this->error     = $error;
		$this->details   = $details;
		$this->attempts  = $attempts;
	}

	/**
	 * Band names, keyed by BAND_* constant.
	 *
	 * @return array<int, string>
	 */
	public static function band_labels(): array {
		return [
			self::BAND_NONE   => __( 'Not scored', 'ai-post-triage' ),
			self::BAND_LOW    => __( 'Low', 'ai-post-triage' ),
			self::BAND_MEDIUM => __( 'Medium', 'ai-post-triage' ),
			self::BAND_HIGH   => __( 'High', 'ai-post-triage' ),
		];
	}

	/**
	 * States, keyed by slug.
	 *
	 * @return array<string, int>
	 */
	public static function state_slugs(): array {
		return [
			'queued'    => self::STATE_QUEUED,
			'scored'    => self::STATE_SCORED,
			'failed'    => self::STATE_FAILED,
			'dismissed' => self::STATE_DISMISSED,
			'published' => self::STATE_PUBLISHED,
		];
	}

	/**
	 * Bands, keyed by slug.
	 *
	 * @return array<string, int>
	 */
	public static function band_slugs(): array {
		return [
			'high'       => self::BAND_HIGH,
			'medium'     => self::BAND_MEDIUM,
			'low'        => self::BAND_LOW,
			'not-scored' => self::BAND_NONE,
		];
	}

	/**
	 * State names, keyed by STATE_* constant.
	 *
	 * @return array<int, string>
	 */
	public static function state_labels(): array {
		return [
			self::STATE_QUEUED    => __( 'Waiting to be scored', 'ai-post-triage' ),
			self::STATE_SCORED    => __( 'Needs review', 'ai-post-triage' ),
			self::STATE_FAILED    => __( 'Scoring failed', 'ai-post-triage' ),
			self::STATE_DISMISSED => __( 'Dismissed', 'ai-post-triage' ),
			self::STATE_PUBLISHED => __( 'Published', 'ai-post-triage' ),
		];
	}

	/**
	 * Builds a Wire_Post from a database row.
	 *
	 * @param array<string, mixed> $row Row selected by Wire_Post_Repository.
	 */
	public static function from_row( array $row ): self {
		$details = isset( $row['details'] ) ? json_decode( (string) $row['details'], true ) : null;

		return new self(
			(int) $row['post_id'],
			(string) $row['post_date'],
			(int) $row['state'],
			(int) $row['band'],
			(int) $row['score'],
			(string) $row['model'],
			isset( $row['hash'] ) ? strtolower( (string) $row['hash'] ) : null,
			(float) $row['cost'],
			isset( $row['scored_at'] ) ? (string) $row['scored_at'] : null,
			(string) $row['error'],
			is_array( $details ) ? $details : null,
			(int) ( $row['attempts'] ?? 0 ),
		);
	}
}
