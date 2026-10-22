<?php
/**
 * Tests for Scorer.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Scoring
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Scoring;

use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Scoring\Rubric;
use JDeepD\AI_Post_Triage\Scoring\Score;
use JDeepD\AI_Post_Triage\Scoring\Scorer;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class - ScorerTest
 *
 * @phpstan-import-type Question from \JDeepD\AI_Post_Triage\Modules\Settings\Settings
 */
#[CoversClass( Scorer::class )]
#[CoversClass( Score::class )]
#[CoversClass( Rubric::class )]
class ScorerTest extends TestCase {
	/**
	 * Test that answers matching every expectation score 100 and are High.
	 */
	public function test_score_all_met_is_high(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes' ),
				self::make_question( 'b', 'no' ),
			],
			[
				'a' => 1.0,
				'b' => 0.0,
			]
		);

		$this->assertSame( 100, $score->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $score->band );
	}

	/**
	 * Test that answers matching no expectation score 0 and are Low.
	 */
	public function test_score_none_met_is_low(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes' ),
				self::make_question( 'b', 'no' ),
			],
			[
				'a' => 0.0,
				'b' => 1.0,
			]
		);

		$this->assertSame( 0, $score->score );
		$this->assertSame( Wire_Post::BAND_LOW, $score->band );
	}

	/**
	 * Test that a "no" expectation scores the probability of "no".
	 */
	public function test_score_inverts_expected_no(): void {
		$score = $this->score( [ self::make_question( 'a', 'no' ) ], [ 'a' => 0.2 ] );

		$this->assertSame( 80, $score->score );
	}

	/**
	 * Test that the score is the weighted share of matches.
	 */
	public function test_score_applies_weights(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes', 'high' ),
				self::make_question( 'b', 'yes', 'low' ),
			],
			[
				'a' => 1.0,
				'b' => 0.0,
			]
		);

		$this->assertSame( 75, $score->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $score->band );
	}

	/**
	 * Test that the score is rounded to the nearest whole number.
	 */
	public function test_score_rounds(): void {
		$questions = [
			self::make_question( 'a', 'yes' ),
			self::make_question( 'b', 'yes' ),
			self::make_question( 'c', 'yes' ),
		];

		$this->assertSame(
			67,
			$this->score(
				$questions,
				[
					'a' => 1.0,
					'b' => 1.0,
					'c' => 0.0,
				]
			)->score
		);
		$this->assertSame(
			33,
			$this->score(
				$questions,
				[
					'a' => 1.0,
					'b' => 0.0,
					'c' => 0.0,
				]
			)->score
		);
	}

	/**
	 * Test that a score falls in the band its thresholds set.
	 *
	 * @param int $score Score.
	 * @param int $band  Expected band.
	 */
	#[DataProvider( 'data_bands' )]
	public function test_band_uses_thresholds( int $score, int $band ): void {
		$this->assertSame( $band, Scorer::band( new Rubric( [], [], 70, 40 ), $score ) );
	}

	/**
	 * Scores around the 70 and 40 thresholds.
	 *
	 * @return array<string, array{int, int}>
	 */
	public static function data_bands(): array {
		return [
			'100'          => [ 100, Wire_Post::BAND_HIGH ],
			'on high'      => [ 70, Wire_Post::BAND_HIGH ],
			'below high'   => [ 69, Wire_Post::BAND_MEDIUM ],
			'on medium'    => [ 40, Wire_Post::BAND_MEDIUM ],
			'below medium' => [ 39, Wire_Post::BAND_LOW ],
			'0'            => [ 0, Wire_Post::BAND_LOW ],
		];
	}

	/**
	 * Test that a score exactly on a threshold is in the higher band.
	 */
	public function test_score_on_threshold_is_in_higher_band(): void {
		$score = $this->score( [ self::make_question( 'a', 'yes' ) ], [ 'a' => 0.7 ], 70, 40 );

		$this->assertSame( 70, $score->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $score->band );
	}

	/**
	 * Test that a missed required question makes a high score Low.
	 */
	public function test_score_missed_required_question_is_low(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes', 'high' ),
				self::make_question( 'b', 'yes', 'high' ),
				self::make_question( 'c', 'yes', 'high' ),
				self::make_question( 'advisory', 'no', 'low', true ),
			],
			[
				'a'        => 1.0,
				'b'        => 1.0,
				'c'        => 1.0,
				'advisory' => 0.9,
			]
		);

		$this->assertSame( 91, $score->score );
		$this->assertSame( Wire_Post::BAND_LOW, $score->band );
		$this->assertFalse( $score->details[3]['met'] );
	}

	/**
	 * Test that a required question met exactly at the boundary doesn't lower the band.
	 */
	public function test_score_required_question_met_at_boundary(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes', 'high' ),
				self::make_question( 'advisory', 'no', 'low', true ),
			],
			[
				'a'        => 1.0,
				'advisory' => 0.5,
			]
		);

		$this->assertTrue( $score->details[1]['met'] );
		$this->assertSame( 88, $score->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $score->band );
	}

	/**
	 * Test where a question is met.
	 *
	 * @param string $expected Expected answer.
	 * @param float  $yes      Probability of "yes".
	 * @param bool   $met      Whether it is met.
	 */
	#[DataProvider( 'data_met' )]
	public function test_score_marks_met_questions( string $expected, float $yes, bool $met ): void {
		$score = $this->score( [ self::make_question( 'a', $expected ) ], [ 'a' => $yes ] );

		$this->assertSame( $met, $score->details[0]['met'] );
	}

	/**
	 * Answers around the met boundary.
	 *
	 * @return array<string, array{string, float, bool}>
	 */
	public static function data_met(): array {
		return [
			'yes at half'   => [ 'yes', 0.5, true ],
			'yes below'     => [ 'yes', 0.49, false ],
			'no at half'    => [ 'no', 0.5, true ],
			'no above half' => [ 'no', 0.51, false ],
		];
	}

	/**
	 * Test that the details hold each question and its answer, in order.
	 */
	public function test_score_details(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes', 'high', true ),
				self::make_question( 'b', 'no', 'low' ),
			],
			[
				'b' => 0.25,
				'a' => 0.75,
			]
		);

		$this->assertSame(
			[
				[
					'id'       => 'a',
					'question' => 'Question a?',
					'expected' => 'yes',
					'weight'   => 'high',
					'required' => true,
					'yes'      => 0.75,
					'met'      => true,
				],
				[
					'id'       => 'b',
					'question' => 'Question b?',
					'expected' => 'no',
					'weight'   => 'low',
					'required' => false,
					'yes'      => 0.25,
					'met'      => true,
				],
			],
			$score->details
		);
	}

	/**
	 * Test that answers outside 0 to 1 are clamped.
	 */
	public function test_score_clamps_answers(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes' ),
				self::make_question( 'b', 'yes' ),
			],
			[
				'a' => 1.5,
				'b' => -0.5,
			]
		);

		$this->assertSame( 50, $score->score );
		$this->assertSame( 1.0, $score->details[0]['yes'] );
		$this->assertSame( 0.0, $score->details[1]['yes'] );
	}

	/**
	 * Test that answers to questions not in the rubric are ignored.
	 */
	public function test_score_ignores_extra_answers(): void {
		$score = $this->score(
			[ self::make_question( 'a', 'yes' ) ],
			[
				'a'     => 1.0,
				'other' => 0.0,
			]
		);

		$this->assertSame( 100, $score->score );
		$this->assertCount( 1, $score->details );
	}

	/**
	 * Test that an unknown weight counts once.
	 */
	public function test_score_unknown_weight_counts_once(): void {
		$score = $this->score(
			[
				self::make_question( 'a', 'yes', 'high' ),
				self::make_question( 'b', 'yes', 'unknown' ),
			],
			[
				'a' => 0.0,
				'b' => 1.0,
			]
		);

		$this->assertSame( 25, $score->score );
	}

	/**
	 * Test that questions with no weight score 0.
	 */
	public function test_score_without_weight_is_zero(): void {
		$rubric = new Rubric( [ self::make_question( 'a', 'yes', 'none' ) ], [ 'none' => 0 ], 70, 40 );
		$score  = Scorer::score( $rubric, [ 'a' => 1.0 ] );

		$this->assertInstanceOf( Score::class, $score );
		$this->assertSame( 0, $score->score );
		$this->assertSame( Wire_Post::BAND_LOW, $score->band );
	}

	/**
	 * Test that a rubric without questions can't score.
	 */
	public function test_score_requires_questions(): void {
		$result = Scorer::score( new Rubric( [], [ 'medium' => 2 ], 70, 40 ), [ 'a' => 1.0 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_post_triage_no_questions', $result->get_error_code() );
	}

	/**
	 * Test that a question without an answer is an error, not a "no".
	 */
	public function test_score_missing_answer_is_error(): void {
		$result = Scorer::score(
			$this->rubric(
				[
					self::make_question( 'a', 'yes' ),
					self::make_question( 'b', 'no' ),
				]
			),
			[ 'a' => 1.0 ]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ai_post_triage_missing_answer', $result->get_error_code() );
		$this->assertStringContainsString( 'Question b?', $result->get_error_message() );
	}

	/**
	 * Test that the rubric is built from the saved settings.
	 */
	public function test_rubric_from_settings(): void {
		update_option(
			'ai_post_triage_settings',
			[
				'questions'        => [ self::make_question( 'a', 'no', 'low', true ) ],
				'high_threshold'   => 80,
				'medium_threshold' => 20,
			]
		);

		$rubric = Rubric::from_settings();

		$this->assertSame( [ self::make_question( 'a', 'no', 'low', true ) ], $rubric->questions );
		$this->assertSame(
			[
				'high'   => 3,
				'medium' => 2,
				'low'    => 1,
			],
			$rubric->weights
		);
		$this->assertSame( 80, $rubric->high_threshold );
		$this->assertSame( 20, $rubric->medium_threshold );
	}

	/**
	 * Test that the fingerprint is stable and 32 hex characters.
	 */
	public function test_fingerprint_is_stable(): void {
		$state     = [ 'headline' => 'Quake hits city' ];
		$questions = [ self::make_question( 'a', 'yes' ) ];

		$fingerprint = Scorer::fingerprint( $state, $questions, 'typesafe/jev-1.13' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $fingerprint );
		$this->assertSame( $fingerprint, Scorer::fingerprint( $state, $questions, 'typesafe/jev-1.13' ) );
	}

	/**
	 * Test that the order of facts and questions doesn't change the fingerprint.
	 */
	public function test_fingerprint_ignores_order(): void {
		$this->assertSame(
			Scorer::fingerprint(
				[
					'headline' => 'Quake hits city',
					'newsroom' => 'National daily',
				],
				[ self::make_question( 'a', 'yes' ), self::make_question( 'b', 'no' ) ],
				'typesafe/jev-1.13'
			),
			Scorer::fingerprint(
				[
					'newsroom' => 'National daily',
					'headline' => 'Quake hits city',
				],
				[ self::make_question( 'b', 'no' ), self::make_question( 'a', 'yes' ) ],
				'typesafe/jev-1.13'
			)
		);
	}

	/**
	 * Test that the fingerprint changes with anything the model reads.
	 *
	 * @param array<string, string> $state     Facts about the post.
	 * @param Question[]            $questions Questions asked.
	 * @param string                $model     Model ID.
	 */
	#[DataProvider( 'data_fingerprint_changes' )]
	public function test_fingerprint_changes_with_model_input( array $state, array $questions, string $model ): void {
		$this->assertNotSame(
			Scorer::fingerprint( [ 'headline' => 'Quake hits city' ], [ self::make_question( 'a', 'yes' ) ], 'typesafe/jev-1.13' ),
			Scorer::fingerprint( $state, $questions, $model )
		);
	}

	/**
	 * Inputs that differ in something the model reads.
	 *
	 * @return array<string, array{array<string, string>, array<int, mixed>, string}>
	 */
	public static function data_fingerprint_changes(): array {
		$renamed             = self::make_question( 'a', 'yes' );
		$renamed['question'] = 'Is this urgent?';

		return [
			'headline'      => [ [ 'headline' => 'Quake hits town' ], [ self::make_question( 'a', 'yes' ) ], 'typesafe/jev-1.13' ],
			'context'       => [
				[
					'headline' => 'Quake hits city',
					'newsroom' => 'National daily',
				],
				[ self::make_question( 'a', 'yes' ) ],
				'typesafe/jev-1.13',
			],
			'question text' => [ [ 'headline' => 'Quake hits city' ], [ $renamed ], 'typesafe/jev-1.13' ],
			'question id'   => [ [ 'headline' => 'Quake hits city' ], [ self::make_question( 'b', 'yes' ) ], 'typesafe/jev-1.13' ],
			'extra'         => [ [ 'headline' => 'Quake hits city' ], [ self::make_question( 'a', 'yes' ), self::make_question( 'b', 'yes' ) ], 'typesafe/jev-1.13' ],
			'model'         => [ [ 'headline' => 'Quake hits city' ], [ self::make_question( 'a', 'yes' ) ], 'cloudflare/clef' ],
		];
	}

	/**
	 * Test that rubric changes the model doesn't read keep the fingerprint.
	 */
	public function test_fingerprint_ignores_scoring_only_changes(): void {
		$state = [ 'headline' => 'Quake hits city' ];

		$this->assertSame(
			Scorer::fingerprint( $state, [ self::make_question( 'a', 'yes', 'medium', false ) ], 'typesafe/jev-1.13' ),
			Scorer::fingerprint( $state, [ self::make_question( 'a', 'no', 'high', true ) ], 'typesafe/jev-1.13' )
		);
	}

	/**
	 * Scores answers against a rubric of the given questions.
	 *
	 * @param Question[]           $questions Questions.
	 * @param array<string, float> $answers   Answers.
	 * @param int                  $high      High threshold.
	 * @param int                  $medium    Medium threshold.
	 */
	private function score( array $questions, array $answers, int $high = 70, int $medium = 40 ): Score {
		$score = Scorer::score( $this->rubric( $questions, $high, $medium ), $answers );

		$this->assertInstanceOf( Score::class, $score );

		return $score;
	}

	/**
	 * A rubric with the default weights.
	 *
	 * @param Question[] $questions Questions.
	 * @param int        $high      High threshold.
	 * @param int        $medium    Medium threshold.
	 */
	private function rubric( array $questions, int $high = 70, int $medium = 40 ): Rubric {
		return new Rubric(
			$questions,
			[
				'high'   => 3,
				'medium' => 2,
				'low'    => 1,
			],
			$high,
			$medium
		);
	}

	/**
	 * A question.
	 *
	 * @param string     $id       ID.
	 * @param 'yes'|'no' $expected Expected answer.
	 * @param string     $weight   Weight.
	 * @param bool       $required Whether it must be met.
	 * @return array{id: string, question: string, expected: 'yes'|'no', weight: string, required: bool}
	 */
	private static function make_question( string $id, string $expected, string $weight = 'medium', bool $required = false ): array {
		return [
			'id'       => $id,
			'question' => 'Question ' . $id . '?',
			'expected' => $expected,
			'weight'   => $weight,
			'required' => $required,
		];
	}
}
