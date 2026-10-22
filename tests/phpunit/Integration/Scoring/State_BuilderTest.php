<?php
/**
 * Integration tests for State_Builder.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Scoring
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Scoring;

use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Scoring\State_Builder;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - State_BuilderTest
 */
#[CoversClass( State_Builder::class )]
class State_BuilderTest extends TestCase {
	/**
	 * Registers a test shortcode.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_shortcode( 'ai_post_triage_test', static fn (): string => 'SHORTCODE OUTPUT' );
	}

	/**
	 * Removes the test shortcode.
	 */
	protected function tearDown(): void {
		remove_shortcode( 'ai_post_triage_test' );

		parent::tearDown();
	}

	/**
	 * Test that "Title only" sends just the headline.
	 */
	public function test_title_mode_sends_headline(): void {
		$this->set_input( 'title' );

		$post = $this->post( 'Quake hits city', '<p>Body text.</p>', 'An excerpt.' );

		$this->assertSame( [ 'headline' => 'Quake hits city' ], State_Builder::for_post( $post ) );
	}

	/**
	 * Test that "Title and excerpt" sends the excerpt.
	 */
	public function test_excerpt_mode_sends_excerpt(): void {
		$this->set_input( 'title_excerpt' );

		$post = $this->post( 'Quake hits city', '<p>First paragraph.</p><p>Second.</p>', 'A <em>short</em> excerpt.' );

		$this->assertSame(
			[
				'headline' => 'Quake hits city',
				'summary'  => 'A short excerpt.',
			],
			State_Builder::for_post( $post )
		);
	}

	/**
	 * Test that "Title and excerpt" falls back to the first paragraph with text.
	 */
	public function test_excerpt_mode_falls_back_to_first_paragraph(): void {
		$this->set_input( 'title_excerpt' );

		$content = "<!-- wp:image --><figure><img src=\"x.jpg\" alt=\"\" /></figure><!-- /wp:image -->\n\n<!-- wp:paragraph --><p>[ai_post_triage_test]</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>BOGOTÁ (AP) &mdash; A quake struck.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Second paragraph.</p><!-- /wp:paragraph -->";

		$this->assertSame(
			[
				'headline' => 'Quake hits city',
				'summary'  => 'BOGOTÁ (AP) — A quake struck.',
			],
			State_Builder::for_post( $this->post( 'Quake hits city', $content ) )
		);
	}

	/**
	 * Test that classic content splits paragraphs on blank lines.
	 */
	public function test_excerpt_mode_splits_classic_paragraphs(): void {
		$this->set_input( 'title_excerpt' );

		$state = State_Builder::for_post( $this->post( 'Quake', "First line\nstill first.\n\nSecond paragraph." ) );

		$this->assertSame( 'First line still first.', $state['summary'] );
	}

	/**
	 * Test that a post with no excerpt or text sends only the headline.
	 */
	public function test_excerpt_mode_without_text_sends_headline(): void {
		$this->set_input( 'title_excerpt' );

		$this->assertSame( [ 'headline' => 'Quake' ], State_Builder::for_post( $this->post( 'Quake', '<p> </p>' ) ) );
	}

	/**
	 * Test that "Title and body" sends the body as plain text.
	 */
	public function test_content_mode_sends_plain_body(): void {
		$this->set_input( 'title_content' );

		$content = '<h2>Lead</h2><p>One &amp; two.</p><script>alert(1)</script><style>p{}</style><p>[ai_post_triage_test]Three<br>four.</p><ul><li>Five</li><li>Six</li></ul>';

		$this->assertSame(
			[
				'headline' => 'Quake hits city',
				'body'     => 'Lead One & two. Three four. Five Six',
			],
			State_Builder::for_post( $this->post( 'Quake hits city', $content ) )
		);
	}

	/**
	 * Test that long text is cut to its limit.
	 */
	public function test_long_text_is_limited(): void {
		$this->set_input( 'title_content' );

		$state = State_Builder::for_post( $this->post( str_repeat( 'é', State_Builder::HEADLINE_LIMIT + 10 ), '<p>' . str_repeat( 'ñ', State_Builder::BODY_LIMIT + 10 ) . '</p>' ) );

		$this->assertSame( State_Builder::HEADLINE_LIMIT, mb_strlen( $state['headline'] ) );
		$this->assertSame( State_Builder::BODY_LIMIT, mb_strlen( $state['body'] ) );
	}

	/**
	 * Test that a long excerpt is cut to the summary limit.
	 */
	public function test_long_excerpt_is_limited(): void {
		$this->set_input( 'title_excerpt' );

		$state = State_Builder::for_post( $this->post( 'Quake', '', str_repeat( 'a ', State_Builder::SUMMARY_LIMIT ) ) );

		$this->assertLessThanOrEqual( State_Builder::SUMMARY_LIMIT, mb_strlen( $state['summary'] ) );
		$this->assertStringEndsNotWith( ' ', $state['summary'] );
	}

	/**
	 * Test that the headline is the raw title, without title filters or markup.
	 */
	public function test_headline_is_plain_title(): void {
		$this->set_input( 'title' );

		add_filter( 'the_title', static fn (): string => 'Filtered' );

		$state = State_Builder::for_post( $this->post( "  <b>Rock</b> &amp; roll's   \"return\"\t", '' ) );

		$this->assertSame( 'Rock & roll\'s "return"', $state['headline'] );
	}

	/**
	 * Test that the newsroom context is sent first.
	 */
	public function test_newsroom_context_is_sent(): void {
		update_option(
			Settings::OPTION_KEY,
			[
				'input'   => 'title',
				'context' => 'A national daily in Colombia.',
			]
		);

		$this->assertSame(
			[
				'newsroom' => 'A national daily in Colombia.',
				'headline' => 'Quake',
			],
			State_Builder::for_post( $this->post( 'Quake', '' ) )
		);
	}

	/**
	 * Test that the state is filterable and keeps only non-empty strings.
	 */
	public function test_state_is_filterable(): void {
		$this->set_input( 'title' );

		$post = $this->post( 'Quake', '' );

		add_filter(
			'ai_post_triage/state',
			static function ( array $state, \WP_Post $filtered ) use ( $post ): array {
				return $state + [
					'post_id'  => $filtered->ID === $post->ID ? $filtered->ID : 0,
					'section'  => ' World ',
					'empty'    => '',
					'tags'     => [ 'a' ],
					7          => 'numeric key',
					'breaking' => true,
				];
			},
			10,
			2
		);

		$this->assertSame(
			[
				'headline' => 'Quake',
				'post_id'  => (string) $post->ID,
				'section'  => 'World',
				'breaking' => '1',
			],
			State_Builder::for_post( $post )
		);
	}

	/**
	 * Test that a filter returning something other than an array gives no state.
	 */
	public function test_invalid_filter_result_gives_empty_state(): void {
		add_filter( 'ai_post_triage/state', '__return_null' );

		$this->assertSame( [], State_Builder::for_post( $this->post( 'Quake', '' ) ) );
	}

	/**
	 * Test that a headline on its own is cleaned and gets the newsroom context.
	 */
	public function test_for_headline(): void {
		update_option( Settings::OPTION_KEY, [ 'context' => 'Regional paper.' ] );

		$this->assertSame(
			[
				'newsroom' => 'Regional paper.',
				'headline' => 'Quake & aftershocks',
			],
			State_Builder::for_headline( ' Quake &amp; aftershocks ' )
		);
	}

	/**
	 * Saves the input mode.
	 *
	 * @param string $input Input mode.
	 */
	private function set_input( string $input ): void {
		update_option( Settings::OPTION_KEY, [ 'input' => $input ] );
	}

	/**
	 * A post with the given fields, not saved to the database.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $excerpt Excerpt.
	 */
	private function post( string $title, string $content, string $excerpt = '' ): \WP_Post {
		return new \WP_Post(
			(object) [
				'ID'           => 123,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_type'    => 'post',
				'filter'       => 'raw',
			]
		);
	}
}
