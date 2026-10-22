<?php
/**
 * Adds the AI Post Triage panel to the block editor.
 *
 * @package JDeepD\AI_Post_Triage\Modules\Editor
 */

declare(strict_types = 1);

namespace JDeepD\AI_Post_Triage\Modules\Editor;

use JDeepD\AI_Post_Triage\Core\Assets;
use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Modules\Queue\Queue_Screen;
use WP_Post;

/**
 * Class - Editor_Panel
 */
final class Editor_Panel implements Registrable {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * Loads the panel for reviewers editing a post.
	 */
	public function enqueue(): void {
		$post = get_post();

		if ( ! $post instanceof WP_Post || ! current_user_can( Capabilities::REVIEW ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_enqueue_script( Assets::EDITOR_HANDLE );
		wp_enqueue_style( Assets::EDITOR_HANDLE );
		wp_add_inline_script(
			Assets::EDITOR_HANDLE,
			'window.aiPostTriageEditor = ' . wp_json_encode( self::data( null !== $this->repository->get( $post->ID ) ) ) . ';',
			'before'
		);
	}

	/**
	 * Data for the panel script.
	 *
	 * @param bool $is_wire Whether the post is in AI Post Triage.
	 * @return array{isWire: bool, queueUrl: string, bands: array<string, string>, states: array<string, string>}
	 */
	public static function data( bool $is_wire ): array {
		$bands  = [];
		$states = [];

		foreach ( Wire_Post::band_slugs() as $slug => $band ) {
			$bands[ $slug ] = Wire_Post::band_labels()[ $band ];
		}

		foreach ( Wire_Post::state_slugs() as $slug => $state ) {
			$states[ $slug ] = Wire_Post::state_labels()[ $state ];
		}

		return [
			'isWire'   => $is_wire,
			'queueUrl' => admin_url( 'admin.php?page=' . Queue_Screen::SCREEN_ID ),
			'bands'    => $bands,
			'states'   => $states,
		];
	}
}
