<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intelligence_Blocks;
use Newspack_Nodes\Block_Editor_Bootstrap;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Tests\Helpers\Wp_Includes;

require_once \dirname( __DIR__, 3 ) . '/newspack-nodes/tests/Helpers/WpIncludes.php';
require_once __DIR__ . '/registers-block-types.php';

/**
 * Intelligence's blocks and contexts declared on the substrate's
 * composition filter, as the loader declares them, over WordPress's own
 * block parser and schema validator, with a fresh declarations memo and no
 * editor canvas booted, before and after each test.
 */
trait Declares_Intelligence_Blocks {

	/** Declare Intelligence's compositions, and forget any earlier memo. */
	private function declare_intelligence_blocks(): void {
		Wp_Includes::load( [ 'parse_blocks', 'serialize_blocks', 'rest_validate_value_from_schema', 'rest_sanitize_value_from_schema', 'wp_sprintf_l' ] );
		// An enum refusal words its list through `%l`, which this filter reads.
		\add_filter( 'wp_sprintf', 'wp_sprintf_l', 10, 2 );
		$this->forget_compositions();
		\add_filter( Composition::FILTER, Intelligence_Blocks::declare( ... ) );
	}

	/**
	 * Declare `newspack-intelligence/probe` on `$contexts`, as a screen task
	 * declares a block: a `lens` the check reads, and a `heading` telling
	 * two layouts apart.
	 *
	 * @param string ...$contexts The contexts it stands on.
	 */
	private function declare_probe( string ...$contexts ): void {
		$this->forget_compositions();
		\add_filter(
			Composition::FILTER,
			static function ( array $map ) use ( $contexts ): array {
				$map[ Intelligence_Blocks::NAMESPACE ]['blocks']['newspack-intelligence/probe'] = [
					'title'      => 'Probe',
					'contexts'   => $contexts,
					'attributes' => [
						'lens'    => [ 'type' => 'string', 'default' => '' ],
						'heading' => [ 'type' => 'string', 'default' => 'Desk' ],
					],
				];
				return $map;
			}
		);
	}

	/** Forget the declarations memo and the editor canvas one test booted. */
	private function forget_compositions(): void {
		( new \ReflectionProperty( Composition::class, 'declarations' ) )->setValue( null, null );
		( new \ReflectionProperty( Block_Editor_Bootstrap::class, 'canvas_context' ) )->setValue( null, null );
	}
}
