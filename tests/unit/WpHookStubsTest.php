<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap's add_action/add_filter doubles must mirror WordPress, which
 * types $callback loosely and resolves it lazily at do_action time. The plugin
 * registers class-string callables for classes Composer autoloads AFTER
 * plugin-file scope (newspack-intelligence.php), so a stub that demands a
 * resolvable `callable` at registration fatals the whole bootstrap.
 */
final class WpHookStubsTest extends TestCase {

	public function test_add_action_accepts_lazy_class_string_callable(): void {
		$hook = 'npainl_test_lazy_' . __FUNCTION__;
		\add_action( $hook, [ '\\Newspack_Intelligence\\Does_Not_Exist_Yet', 'register' ] );

		$this->assertContains(
			[ '\\Newspack_Intelligence\\Does_Not_Exist_Yet', 'register' ],
			$GLOBALS['_wp_actions'][ $hook ]
		);
	}

	public function test_add_filter_accepts_lazy_class_string_callable(): void {
		$hook = 'npainl_test_lazy_' . __FUNCTION__;
		\add_filter( $hook, [ '\\Newspack_Intelligence\\Does_Not_Exist_Yet', 'filter' ] );

		$this->assertContains(
			[ '\\Newspack_Intelligence\\Does_Not_Exist_Yet', 'filter' ],
			$GLOBALS['_wp_actions'][ $hook ]
		);
	}
}
