<?php
/**
 * Interface for Registrable classes.
 *
 * Registrable classes are those that register hooks (actions/filters) with WordPress.
 *
 * @package JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces;

/**
 * Interface - Registrable
 */
interface Registrable {
	/**
	 * Registers class methods to WordPress.
	 *
	 * WordPress actions/filters should be included here.
	 */
	public function register_hooks(): void;
}
