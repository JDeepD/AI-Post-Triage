<?php
/**
 * Today's usage settings field.
 *
 * @package ai-post-triage
 */

declare(strict_types = 1);

defined( 'ABSPATH' ) || exit;

/** @var array{id: string, cost: float, calls: int, budget: float, paused: string, resume_url: string} $args */
?>
<p id="<?php echo esc_attr( $args['id'] ); ?>">
	<?php
	echo esc_html(
		$args['budget'] > 0
			? sprintf(
				/* translators: 1: amount spent today in US dollars, 2: daily budget in US dollars, 3: posts scored today. */
				_n( '$%1$s of $%2$s spent, %3$s post scored.', '$%1$s of $%2$s spent, %3$s posts scored.', $args['calls'], 'ai-post-triage' ),
				number_format_i18n( $args['cost'], 4 ),
				number_format_i18n( $args['budget'], 2 ),
				number_format_i18n( $args['calls'] )
			)
			: sprintf(
				/* translators: 1: amount spent today in US dollars, 2: posts scored today. */
				_n( '$%1$s spent, %2$s post scored.', '$%1$s spent, %2$s posts scored.', $args['calls'], 'ai-post-triage' ),
				number_format_i18n( $args['cost'], 4 ),
				number_format_i18n( $args['calls'] )
			)
	);
	?>
</p>
<?php if ( '' !== $args['paused'] ) : ?>
	<p class="ai-post-triage-paused"><?php echo wp_kses_post( $args['paused'] ); ?></p>
	<p><a class="button" href="<?php echo esc_url( $args['resume_url'] ); ?>"><?php esc_html_e( 'Resume now', 'ai-post-triage' ); ?></a></p>
<?php endif; ?>
