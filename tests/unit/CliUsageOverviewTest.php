<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Tests\ListsEveryCliCommand;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once \dirname( __DIR__, 3 ) . '/newspack-nodes/tests/Helpers/ListsEveryCliCommand.php';

/**
 * Every command this plugin registers lists in its namespace's usage overview.
 */
final class CliUsageOverviewTest extends TestCase {
	use ListsEveryCliCommand;

	public function test_every_command_lists_in_its_usage_overview(): void {
		\WP_CLI::reset();

		\Newspack_Intelligence\register_cli_commands();

		$this->assert_every_cli_command_listed( \WP_CLI::$commands );
	}
}
