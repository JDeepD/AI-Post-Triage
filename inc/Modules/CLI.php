<?php
/**
 * WP-CLI Commands for Wire Desk.
 *
 * @package JDeepD\Wire_Desk\Modules
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Modules;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - CLI
 *
 * Registers WP-CLI commands for the plugin.
 */
final class CLI implements Registrable {
	/**
	 * {@inheritDoc}
	 *
	 * @uses \WP_CLI::add_command() instead of a hook.
	 */
	public function register_hooks(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		foreach ( $this->get_commands() as $name => $command ) {
			\WP_CLI::add_command(
				"wire-desk {$name}",
				$command['callback'],
				[
					'shortdesc' => $command['description'],
				]
			);
		}
	}

	/**
	 * Get available CLI commands.
	 *
	 * @return array<string, array{
	 *   callback: callable( array<int, mixed>, array<string, mixed> ): void,
	 *   description: string
	 * }>
	 */
	private function get_commands(): array {
		$commands = [
			CLI\Backfill::class,
			CLI\Healthcheck::class,
			CLI\Prune::class,
			CLI\Rescore::class,
			CLI\Score::class,
			CLI\Stats::class,
		];

		return array_reduce(
			$commands,
			static function ( $carry, $command_class ) {
				$carry[ $command_class::get_name() ] = [
					'callback'    => [ $command_class, 'run' ],
					'description' => $command_class::get_description(),
				];
				return $carry;
			},
			[]
		);
	}
}
