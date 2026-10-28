<?php
declare(strict_types=1);

// Global-namespace WP_CLI stub so the CLI command's `class_exists( '\WP_CLI' )`
// branches (error / success reporting) execute under test. Records messages
// instead of exiting; production hits `return;` after WP_CLI::error() anyway.
// Records each `add_command()` too, name => callable, for the usage-overview
// rule, and each `runcommand()`, handing it to `$on_run` when one is set.
// Guarded so more than one test file can require it.

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<string,mixed> */
		public static array $commands = [];
		/** @var array<int,string> */
		public static array $errors = [];
		/** @var array<int,string> */
		public static array $successes = [];
		/** @var array<int,string> */
		public static array $logs = [];
		/** @var list<array{0:string,1:array<string,mixed>}> */
		public static array $ran = [];
		public static ?\Closure $on_run = null;
		public static function add_command( string $name, mixed $callable ): void {
			self::$commands[ $name ] = $callable;
		}
		public static function error( string $message ): void {
			self::$errors[] = $message;
		}
		public static function success( string $message ): void {
			self::$successes[] = $message;
		}
		public static function log( string $message ): void {
			self::$logs[] = $message;
		}
		/**
		 * @param array<string,mixed> $options
		 * @return object{return_code:int,stdout:string,stderr:string} What `$on_run` answers as `[ code, stdout, stderr ]`, a clean exit without one.
		 */
		public static function runcommand( string $command, array $options = [] ): object {
			self::$ran[]                  = [ $command, $options ];
			$run                          = null === self::$on_run ? null : ( self::$on_run )( $command );
			[ $code, $stdout, $stderr ]   = \is_array( $run ) ? $run : [ 0, '', '' ];
			return (object) [ 'return_code' => $code, 'stdout' => $stdout, 'stderr' => $stderr ];
		}
		public static function reset(): void {
			self::$commands  = [];
			self::$errors    = [];
			self::$successes = [];
			self::$logs      = [];
			self::$ran       = [];
			self::$on_run    = null;
		}
	}
}
