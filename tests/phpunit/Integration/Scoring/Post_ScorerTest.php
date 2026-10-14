<?php
/**
 * Integration tests for Post_Scorer.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Scoring
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Scoring;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Decisions\Decision;
use JDeepD\Wire_Desk\Decisions\Decision_Client;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Scoring\Post_Scorer;
use JDeepD\Wire_Desk\Scoring\Result;
use JDeepD\Wire_Desk\Scoring\Scorer;
use JDeepD\Wire_Desk\Scoring\State_Builder;
use JDeepD\Wire_Desk\Tests\Fakes\Fake_Decision_Client;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Post_ScorerTest
 */
#[CoversClass( Post_Scorer::class )]
#[CoversClass( Result::class )]
class Post_ScorerTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Fake decision client.
	 *
	 * @var \JDeepD\Wire_Desk\Tests\Fakes\Fake_Decision_Client
	 */
	private Fake_Decision_Client $client;

	/**
	 * Results passed to the scored action.
	 *
	 * @var \JDeepD\Wire_Desk\Scoring\Result[]
	 */
	private array $scored = [];

	/**
	 * Saves a two-question rubric.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			Settings::OPTION_KEY,
			[
				'input'            => 'title',
				'questions'        => [
					[
						'id'       => 'q_breaking',
						'question' => 'Is this breaking news?',
						'expected' => 'yes',
						'weight'   => 'high',
						'required' => false,
					],
					[
						'id'       => 'q_advisory',
						'question' => 'Is this an advisory?',
						'expected' => 'no',
						'weight'   => 'low',
						'required' => true,
					],
				],
				'high_threshold'   => 70,
				'medium_threshold' => 40,
				'model'            => 'typesafe/jev-1.13',
			]
		);

		$this->repository = new Wire_Post_Repository();
		$this->client     = new Fake_Decision_Client(
			new Decision(
				[
					'q_breaking' => 0.9,
					'q_advisory' => 0.1,
				],
				'typesafe/jev-1.13-20260917',
				0.00002,
				'gen-1'
			)
		);
		$this->scored     = [];

		add_action( 'wire_desk/scored', [ $this, 'record_scored' ] );
	}

	/**
	 * Records a scored result.
	 *
	 * @param \JDeepD\Wire_Desk\Scoring\Result $result Result.
	 */
	public function record_scored( Result $result ): void {
		$this->scored[] = $result;
	}

	/**
	 * Test that a post is scored and the score saved.
	 */
	public function test_score_saves_score(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title' => 'Quake hits city',
				'post_date'  => '2026-10-14 09:30:00',
			]
		);

		$result = $this->scorer()->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertFalse( $result->skipped );
		$this->assertSame( 90, $result->score->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $result->score->band );
		$this->assertSame( 'typesafe/jev-1.13-20260917', $result->model );
		$this->assertSame( 0.00002, $result->cost );

		$row = $this->repository->get( $post_id );

		$this->assertNotNull( $row );
		$this->assertSame( Wire_Post::STATE_SCORED, $row->state );
		$this->assertSame( '2026-10-14 09:30:00', $row->post_date );
		$this->assertSame( 90, $row->score );
		$this->assertSame( Wire_Post::BAND_HIGH, $row->band );
		$this->assertSame( 'typesafe/jev-1.13-20260917', $row->model );
		$this->assertSame( 0.00002, $row->cost );
		$this->assertSame( '', $row->error );
		$this->assertNotNull( $row->scored_at );
		$this->assertSame( Scorer::fingerprint( [ 'headline' => 'Quake hits city' ], Settings::questions(), 'typesafe/jev-1.13' ), $row->hash );
		$this->assertSame( [ 'q_breaking', 'q_advisory' ], array_column( (array) $row->details, 'id' ) );
	}

	/**
	 * Test that the model is asked about the post with the saved questions and model.
	 */
	public function test_score_asks_about_post(): void {
		$post = self::factory()->post->create_and_get( [ 'post_title' => 'Quake hits city' ] );

		$this->scorer()->score( $post->ID );

		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( State_Builder::for_post( $post ), $this->client->calls[0]['state'] );
		$this->assertSame( Settings::questions(), $this->client->calls[0]['questions'] );
		$this->assertSame( 'typesafe/jev-1.13', $this->client->calls[0]['model'] );
	}

	/**
	 * Test that a queued post keeps its row and becomes scored.
	 */
	public function test_score_updates_queued_row(): void {
		$post_id = self::factory()->post->create();
		$this->repository->add_queued( $post_id, '2026-01-01 00:00:00' );

		$this->scorer()->score( $post_id );

		$row = $this->repository->get( $post_id );

		$this->assertNotNull( $row );
		$this->assertSame( Wire_Post::STATE_SCORED, $row->state );
		$this->assertSame( '2026-01-01 00:00:00', $row->post_date );
	}

	/**
	 * Test that the scored action fires with the result.
	 */
	public function test_score_fires_scored_action(): void {
		$post_id = self::factory()->post->create();

		$result = $this->scorer()->score( $post_id );

		$this->assertSame( [ $result ], $this->scored );
	}

	/**
	 * Test that an unchanged post isn't sent to the model again.
	 */
	public function test_unchanged_post_is_skipped(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		$result = $scorer->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertTrue( $result->skipped );
		$this->assertSame( 0.0, $result->cost );
		$this->assertSame( 90, $result->score->score );
		$this->assertSame( 'typesafe/jev-1.13-20260917', $result->model );
		$this->assertCount( 1, $this->client->calls );
		$this->assertCount( 1, $this->scored );
		$this->assertSame( 0.00002, $this->repository->get( $post_id )?->cost );
	}

	/**
	 * Test that forcing sends an unchanged post to the model again.
	 */
	public function test_force_rescores_unchanged_post(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		$result = $scorer->score( $post_id, true );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertFalse( $result->skipped );
		$this->assertCount( 2, $this->client->calls );
	}

	/**
	 * Test that a changed headline is sent to the model again.
	 */
	public function test_changed_post_is_rescored(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Quake hits city' ] );
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Quake hits city, dozens hurt',
			]
		);
		$result = $scorer->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertFalse( $result->skipped );
		$this->assertCount( 2, $this->client->calls );
	}

	/**
	 * Test that changing a question's wording sends the post to the model again.
	 */
	public function test_changed_question_is_rescored(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		$this->update_settings( [ 'questions' => array_replace( Settings::questions(), [ 0 => [ 'question' => 'Is this urgent news?' ] + Settings::questions()[0] ] ) ] );
		$scorer->score( $post_id );

		$this->assertCount( 2, $this->client->calls );
	}

	/**
	 * Test that changing the model sends the post to the model again.
	 */
	public function test_changed_model_is_rescored(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		$this->update_settings( [ 'model' => 'cloudflare/clef' ] );
		$scorer->score( $post_id );

		$this->assertCount( 2, $this->client->calls );
		$this->assertSame( 'cloudflare/clef', $this->client->calls[1]['model'] );
	}

	/**
	 * Test that rubric changes the model doesn't read are applied without asking it.
	 */
	public function test_threshold_change_rebands_without_asking(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );
		$this->update_settings( [ 'high_threshold' => 95 ] );
		$result = $scorer->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertTrue( $result->skipped );
		$this->assertSame( Wire_Post::BAND_MEDIUM, $result->score->band );
		$this->assertCount( 1, $this->client->calls );

		$row = $this->repository->get( $post_id );

		$this->assertSame( Wire_Post::BAND_MEDIUM, $row?->band );
		$this->assertSame( 0.00002, $row?->cost );
		$this->assertSame( 'typesafe/jev-1.13-20260917', $row?->model );
	}

	/**
	 * Test that a weight change is applied without asking the model.
	 */
	public function test_weight_change_rescores_without_asking(): void {
		$post_id = self::factory()->post->create();
		$scorer  = $this->scorer();

		$scorer->score( $post_id );

		$questions              = Settings::questions();
		$questions[0]['weight'] = 'low';
		$this->update_settings( [ 'questions' => $questions ] );

		$result = $scorer->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertSame( 90, $result->score->score );
		$this->assertSame( 'low', $result->score->details[0]['weight'] );
		$this->assertSame( 'low', $this->repository->get( $post_id )?->details[0]['weight'] ?? null );
		$this->assertCount( 1, $this->client->calls );
	}

	/**
	 * Test that a client error is saved and returned.
	 */
	public function test_client_error_is_saved(): void {
		$post_id      = self::factory()->post->create();
		$this->client = new Fake_Decision_Client( new \WP_Error( Decision_Client::ERROR_RATE_LIMITED, 'OpenRouter returned 429: Rate limit exceeded' ) );

		$result = $this->scorer()->score( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( Decision_Client::ERROR_RATE_LIMITED, $result->get_error_code() );

		$row = $this->repository->get( $post_id );

		$this->assertSame( Wire_Post::STATE_FAILED, $row?->state );
		$this->assertSame( 'OpenRouter returned 429: Rate limit exceeded', $row?->error );
		$this->assertSame( [], $this->scored );
	}

	/**
	 * Test that a failed post is asked again rather than skipped.
	 */
	public function test_failed_post_is_asked_again(): void {
		$post_id = self::factory()->post->create();
		$failing = new Fake_Decision_Client( new \WP_Error( Decision_Client::ERROR_SERVER, 'Server error' ) );

		( new Post_Scorer( $failing, $this->repository ) )->score( $post_id );
		$result = $this->scorer()->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertFalse( $result->skipped );
		$this->assertSame( Wire_Post::STATE_SCORED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that a forced re-score that fails keeps the saved answers and recovers without asking.
	 */
	public function test_failed_forced_rescore_recovers_from_saved_answers(): void {
		$post_id = self::factory()->post->create();

		$this->scorer()->score( $post_id );
		( new Post_Scorer( new Fake_Decision_Client( new \WP_Error( Decision_Client::ERROR_SERVER, 'Server error' ) ), $this->repository ) )->score( $post_id, true );

		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( $post_id )?->state );

		$result = $this->scorer()->score( $post_id );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertTrue( $result->skipped );

		$row = $this->repository->get( $post_id );

		$this->assertSame( Wire_Post::STATE_SCORED, $row?->state );
		$this->assertSame( '', $row?->error );
		$this->assertCount( 1, $this->client->calls );
	}

	/**
	 * Test that a model that skips a question fails the post.
	 */
	public function test_missing_answer_is_saved_as_error(): void {
		$post_id      = self::factory()->post->create();
		$this->client = new Fake_Decision_Client( new Decision( [ 'q_breaking' => 1.0 ], 'typesafe/jev-1.13', 0.00002, '' ) );

		$result = $this->scorer()->score( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wire_desk_missing_answer', $result->get_error_code() );
		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that dismissed and published posts keep their state when scored.
	 */
	public function test_score_keeps_editorial_state(): void {
		$dismissed = self::factory()->post->create();
		$published = self::factory()->post->create();

		foreach ( [ $dismissed, $published ] as $post_id ) {
			$this->repository->add_queued( $post_id, '2026-10-14 00:00:00' );
		}

		$this->repository->set_state( $dismissed, Wire_Post::STATE_DISMISSED );
		$this->repository->set_state( $published, Wire_Post::STATE_PUBLISHED );

		$this->scorer()->score( $dismissed );
		$this->scorer()->score( $published );

		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $dismissed )?->state );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $published )?->state );
		$this->assertSame( 90, $this->repository->get( $dismissed )?->score );
	}

	/**
	 * Test that missing posts, revisions and auto-drafts aren't scored.
	 */
	public function test_score_rejects_non_posts(): void {
		$post_id    = self::factory()->post->create();
		$revision   = wp_save_post_revision( $post_id );
		$auto_draft = self::factory()->post->create( [ 'post_status' => 'auto-draft' ] );

		foreach ( [ 0, PHP_INT_MAX, (int) $revision, $auto_draft ] as $id ) {
			$result = $this->scorer()->score( $id );

			$this->assertWPError( $result );
			$this->assertSame( 'wire_desk_no_post', $result->get_error_code() );
			$this->assertNull( $this->repository->get( $id ) );
		}

		$this->assertSame( [], $this->client->calls );
	}

	/**
	 * A scorer using the fake client.
	 */
	private function scorer(): Post_Scorer {
		return new Post_Scorer( $this->client, $this->repository );
	}

	/**
	 * Saves settings over the current ones.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function update_settings( array $settings ): void {
		update_option( Settings::OPTION_KEY, $settings + Settings::get() );
	}
}
