<?php
/**
 * Grants the plugin's capabilities.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Core;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Capabilities
 */
final class Capabilities implements Registrable {
	/**
	 * Manage the plugin's settings.
	 */
	public const MANAGE = 'wire_desk_manage';

	/**
	 * Review the queue: view, dismiss, restore and re-score posts.
	 */
	public const REVIEW = 'wire_desk_review';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_filter( 'user_has_cap', [ $this, 'grant' ], 10, 3 );
	}

	/**
	 * The capability each plugin capability is granted from.
	 *
	 * @return array<string, string> Plugin capability => capability that grants it.
	 */
	public static function map(): array {
		$defaults = [
			self::MANAGE => 'manage_options',
			self::REVIEW => 'edit_others_posts',
		];

		/**
		 * Filters the capability each Wire Desk capability is granted from.
		 *
		 * @param array<string, string> $map Wire Desk capability => capability that grants it.
		 */
		$map = apply_filters( 'wire_desk/capability_map', $defaults );

		if ( ! is_array( $map ) ) {
			return $defaults;
		}

		foreach ( $defaults as $capability => $default ) {
			$defaults[ $capability ] = isset( $map[ $capability ] ) && is_string( $map[ $capability ] ) && '' !== $map[ $capability ] ? $map[ $capability ] : $default;
		}

		return $defaults;
	}

	/**
	 * Grants plugin capabilities to users who have the capability they map to.
	 *
	 * A capability set on the user or their role directly is left as it is.
	 *
	 * @param array<string, bool> $allcaps The user's capabilities.
	 * @param string[]            $caps    Primitive capabilities being checked.
	 * @param mixed[]             $args    Arguments passed to the capability check.
	 * @return array<string, bool>
	 */
	public function grant( $allcaps, $caps, $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
		$allcaps = (array) $allcaps;

		if ( ! array_intersect( (array) $caps, [ self::MANAGE, self::REVIEW ] ) ) {
			return $allcaps;
		}

		foreach ( self::map() as $capability => $required ) {
			if ( ! isset( $allcaps[ $capability ] ) ) {
				$allcaps[ $capability ] = ! empty( $allcaps[ $required ] );
			}
		}

		return $allcaps;
	}
}
