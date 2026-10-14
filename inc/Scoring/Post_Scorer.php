<?php
/**
 * Scores a post and saves the result.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Scoring;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Decisions\Decision_Client;
use JDeepD\Wire_Desk\Decisions\OpenRouter_Client;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use WP_Post;

/**
 * Class - Post_Scorer
 */
final class Post_Scorer {
	/**
	 * Client that answers the questions.
	 *
	 * @var \JDeepD\Wire_Desk\Decisions\Decision_Client
	 */
	private Decision_Client $client;

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\Wire_Desk\Decisions\Decision_Client|null $client     Client that answers the questions.
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Decision_Client $client = null, ?Wire_Post_Repository $repository = null ) {
		$this->client     = $client ?? new OpenRouter_Client();
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * Scores a post and saves the score, or the error, to the wire post table.
	 *
	 * A post whose input is unchanged since it was last scored isn't sent to the model again. Its
	 * saved answers are scored against the current rubric instead.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Ask the model even if the input is unchanged.
	 */
	public function score( int $post_id, bool $force = false ): Result|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || 'auto-draft' === $post->post_status ) {
			return new \WP_Error( 'wire_desk_no_post', __( 'The post does not exist.', 'wire-desk' ) );
		}

		$row = $this->repository->get( $post->ID );

		if ( null === $row && ! $this->repository->add_queued( $post->ID, $post->post_date ) ) {
			return new \WP_Error( 'wire_desk_db_error', __( 'The post could not be added to the Wire Desk table.', 'wire-desk' ) );
		}

		$settings = Settings::get();
		$rubric   = Rubric::from_settings();
		$state    = State_Builder::for_post( $post );
		$hash     = Scorer::fingerprint( $state, $rubric->questions, $settings['model'] );

		if ( ! $force && $row && $hash === $row->hash && null !== $row->scored_at ) {
			$rescored = $this->rescore( $row, $rubric, $hash );

			if ( $rescored ) {
				return $rescored;
			}
		}

		$decision = $this->client->decide( $state, $rubric->questions, $settings['model'] );

		if ( is_wp_error( $decision ) ) {
			return $this->fail( $post->ID, $decision );
		}

		$score = Scorer::score( $rubric, $decision->answers );

		if ( is_wp_error( $score ) ) {
			return $this->fail( $post->ID, $score );
		}

		if ( ! $this->repository->save_score( $post->ID, $score->band, $score->score, $decision->model, $hash, $decision->cost, $score->details ) ) {
			return new \WP_Error( 'wire_desk_db_error', __( 'The score could not be saved.', 'wire-desk' ) );
		}

		$result = new Result( $post->ID, $score, $decision->model, $decision->cost, false );

		/**
		 * Fires after a post is scored.
		 *
		 * @param \JDeepD\Wire_Desk\Scoring\Result $result The score, the model that answered and the cost.
		 */
		do_action( 'wire_desk/scored', $result );

		return $result;
	}

	/**
	 * Scores a post's saved answers against the current rubric, without asking the model.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $row    The post's row.
	 * @param \JDeepD\Wire_Desk\Scoring\Rubric $rubric Current rubric.
	 * @param string                           $hash   Input fingerprint.
	 * @return ?\JDeepD\Wire_Desk\Scoring\Result Null when the saved answers can't be scored.
	 */
	private function rescore( Wire_Post $row, Rubric $rubric, string $hash ): ?Result {
		$answers = [];

		foreach ( $row->details ?? [] as $detail ) {
			if ( is_array( $detail ) && is_string( $detail['id'] ?? null ) && is_numeric( $detail['yes'] ?? null ) ) {
				$answers[ $detail['id'] ] = (float) $detail['yes'];
			}
		}

		$score = Scorer::score( $rubric, $answers );

		if ( is_wp_error( $score ) ) {
			return null;
		}

		if ( $score->score !== $row->score || $score->band !== $row->band || $score->details !== $row->details || Wire_Post::STATE_FAILED === $row->state ) {
			if ( ! $this->repository->save_score( $row->post_id, $score->band, $score->score, $row->model, $hash, $row->cost, $score->details ) ) {
				return null;
			}
		}

		return new Result( $row->post_id, $score, $row->model, 0.0, true );
	}

	/**
	 * Saves a scoring error.
	 *
	 * @param int       $post_id Post ID.
	 * @param \WP_Error $error   Error.
	 */
	private function fail( int $post_id, \WP_Error $error ): \WP_Error {
		$this->repository->save_error( $post_id, $error->get_error_message() );

		return $error;
	}
}
