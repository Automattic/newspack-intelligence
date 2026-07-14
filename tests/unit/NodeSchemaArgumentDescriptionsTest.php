<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\Scorer_Node;
use Newspack_AI_Newsletter\Summarizer_Node;
use PHPUnit\Framework\TestCase;

/**
 * Every node_schema constructor argument must ship a description — it becomes the
 * argument's tooltip in the topology console, so a blank one is a blank tooltip.
 */
final class NodeSchemaArgumentDescriptionsTest extends TestCase {
	public function test_every_node_schema_argument_has_a_description(): void {
		$missing = [];
		foreach ( [ Digest_Builder_Node::class, Scorer_Node::class, Summarizer_Node::class ] as $class ) {
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
}
