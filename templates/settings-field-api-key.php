<?php
/**
 * OpenRouter API key settings field. The saved key is never printed.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

use JDeepD\AI_Post_Triage\Decisions\OpenRouter_Client;

/** @var array{id: string, name: string, source: string, constant: string, saved: string, broken: bool} $args */
$ai_post_triage_locked = OpenRouter_Client::SOURCE_CONSTANT === $args['source'];
?>
<input
	type="password"
	class="regular-text"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	name="<?php echo esc_attr( $args['name'] ); ?>[key]"
	value=""
	autocomplete="new-password"
	spellcheck="false"
	placeholder="<?php echo esc_attr( '' !== $args['saved'] ? __( 'Enter a new key to replace the saved one', 'ai-post-triage' ) : 'sk-or-…' ); ?>"
	<?php disabled( $ai_post_triage_locked ); ?>
/>

<?php if ( $ai_post_triage_locked ) : ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: PHP constant name. */
			esc_html__( 'The key is set by the %s constant, which takes precedence over this field.', 'ai-post-triage' ),
			'<code>' . esc_html( $args['constant'] ) . '</code>'
		);
		?>
	</p>
<?php elseif ( '' !== $args['saved'] ) : ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: last characters of the saved key, such as …a1b2. */
			esc_html__( 'A key ending in %s is saved, encrypted.', 'ai-post-triage' ),
			'<code>' . esc_html( $args['saved'] ) . '</code>'
		);
		?>
	</p>
	<p>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $args['name'] ); ?>[remove]" value="1" />
			<?php esc_html_e( 'Remove the saved key', 'ai-post-triage' ); ?>
		</label>
	</p>
<?php elseif ( $args['broken'] ) : ?>
	<p class="description ai-post-triage-paused"><?php esc_html_e( 'The saved key could not be decrypted, probably because the site\'s security keys changed. Enter it again.', 'ai-post-triage' ); ?></p>
<?php elseif ( OpenRouter_Client::SOURCE_CONNECTOR === $args['source'] ) : ?>
	<p class="description"><?php esc_html_e( 'Using the key from the OpenRouter connector. A key saved here takes precedence.', 'ai-post-triage' ); ?></p>
<?php else : ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: PHP constant name. */
			esc_html__( 'Saved encrypted. You can also define the %s constant in wp-config.php instead.', 'ai-post-triage' ),
			'<code>' . esc_html( $args['constant'] ) . '</code>'
		);
		?>
	</p>
<?php endif; ?>
