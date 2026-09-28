<?php
declare(strict_types=1);

// Global-namespace WP_CLI stub so the CLI command's `class_exists( '\WP_CLI' )`
// branches (error / success reporting) execute under test. Records messages
// instead of exiting; production hits `return;` after WP_CLI::error() anyway.
// Records each `add_command()` too, name => callable, for the usage-overview
// rule. Guarded so more than one test file can require it.

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<string,mixed> */
		public static array $commands = [];
		/** @var array<int,string> */
		public static array $errors = [];
		/** @var array<int,string> */
		public static array $successes = [];
		public static function add_command( string $name, mixed $callable ): void {
			self::$commands[ $name ] = $callable;
		}
		public static function error( string $message ): void {
			self::$errors[] = $message;
		}
		public static function success( string $message ): void {
			self::$successes[] = $message;
		}
		public static function reset(): void {
			self::$commands  = [];
			self::$errors    = [];
			self::$successes = [];
		}
	}
}
