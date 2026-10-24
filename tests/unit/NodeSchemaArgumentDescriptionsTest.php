<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Node;
use PHPUnit\Framework\TestCase;

/**
 * Every node_schema constructor argument must ship a description — it becomes the
 * argument's tooltip in the topology console, so a blank one is a blank tooltip.
 */
final class NodeSchemaArgumentDescriptionsTest extends TestCase {
	public function test_every_node_schema_argument_has_a_description(): void {
		$classes = $this->node_classes();
		$this->assertContains( 'Newspack_Intelligence\\Resolver_Node', $classes, 'the scan finds the plugin\'s nodes' );
		$missing = [];
		foreach ( $classes as $class ) {
			foreach ( $class::node_schema()['arguments'] ?? [] as $arg ) {
				$desc = $arg['description'] ?? '';
				if ( ! \is_string( $desc ) || '' === \trim( $desc ) ) {
					$missing[] = $class . '::' . (string) ( $arg['name'] ?? '?' );
				}
			}
		}
		$this->assertSame(
			[],
			$missing,
			'node_schema arguments missing a description: ' . \implode( ', ', $missing )
		);
	}

	/**
	 * Every concrete Node class under includes/, read off the classmap.
	 *
	 * @return list<class-string<Node>>
	 */
	private function node_classes(): array {
		$includes = \realpath( \dirname( __DIR__, 2 ) . '/includes' );
		$map      = require \dirname( __DIR__, 2 ) . '/vendor/composer/autoload_classmap.php';
		$classes  = [];
		foreach ( $map as $class => $file ) {
			if ( \str_starts_with( (string) \realpath( $file ), (string) $includes ) && \is_subclass_of( $class, Node::class )
				&& ! ( new \ReflectionClass( $class ) )->isAbstract() ) {
				$classes[] = $class;
			}
		}
		\sort( $classes );
		return $classes;
	}
}
