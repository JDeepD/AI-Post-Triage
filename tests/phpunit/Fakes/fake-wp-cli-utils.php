<?php
/**
 * Records WP_CLI\Utils\format_items() calls in Fake_WP_CLI.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Fakes
 */

declare( strict_types = 1 );

namespace WP_CLI\Utils;

use JDeepD\AI_Post_Triage\Tests\Fakes\Fake_WP_CLI;

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Records a table.
	 *
	 * @param string            $format Format.
	 * @param array<int, mixed> $items  Rows.
	 * @param string[]|string   $fields Columns.
	 */
	function format_items( $format, $items, $fields ): void {
		Fake_WP_CLI::$tables[] = [
			'format' => (string) $format,
			'items'  => (array) $items,
			'fields' => is_array( $fields ) ? $fields : explode( ',', (string) $fields ),
		];
	}
}
