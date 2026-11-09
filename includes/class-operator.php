<?php
/**
 * Operator: who runs a deployment and what its graph tracks, as the
 * deployment's topology names them on its LLM_Call node (`set_operator`,
 * `set_tracks`), else generic wording. Every prompt that says whom the
 * graph serves reads them through `spelled()`, and the Intelligence page is
 * localized the same name.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;

\defined( 'ABSPATH' ) || exit;

final class Operator {

	/** Who runs a deployment that names no one. */
	public const DEFAULT_NAME = 'the organization';

	/** What a deployment tracks when it says nothing: its own work, `{operator}` its name. */
	private const DEFAULT_TRACKS = '{operator}\'s own work, the organizations {operator} serves as clients, and their industry';

	/** Who runs the deployment. */
	public readonly string $name;

	/** Whether the deployment named who runs it. */
	public readonly bool $named;

	/** What the deployment's graph tracks, as one phrase. */
	public readonly string $tracks;

	/**
	 * The operator a deployment names, generic where it names nothing.
	 *
	 * @param string $name   Who runs it; '' for DEFAULT_NAME.
	 * @param string $tracks What it tracks; '' for its own work and clients.
	 */
	public function __construct( string $name, string $tracks ) {
		$this->named  = '' !== $name;
		$this->name   = $this->named ? $name : self::DEFAULT_NAME;
		$this->tracks = '' === $tracks ? $this->spelled( self::DEFAULT_TRACKS ) : $tracks;
	}

	/**
	 * The operator the running Intelligence topology's LLM_Call node names;
	 * generic unless the active topologies name the one running Intelligence
	 * and it declares an LLM_Call, the health strip's slices saying why they
	 * cannot name it. `Worker_Route::running()` reads every active graph
	 * first, so a line that will not read, here or in an include, is its
	 * `\RuntimeException`, and the graph read below is already read.
	 */
	public static function configured(): self {
		try {
			$topology = Worker_Route::running();
		} catch ( \RuntimeException ) {
			$topology = null;
		}
		$node = null === $topology ? null : ( Topology_Analyzer::nodes_of_type( $topology, LLM_Call_Node::class )[0] ?? null );
		return null === $node ? new self( '', '' ) : new self( Core::as_string( $node['operator'] ), Core::as_string( $node['tracks'] ) );
	}

	/**
	 * A template with its `{operator}` read as the name and its `{tracks}`
	 * as what the deployment tracks, in one pass, so a name spelling a
	 * placeholder stays as written; `{tracks}` is read only where the
	 * template names it, so the constructor spells the default with it.
	 *
	 * @param string $template The template.
	 */
	public function spelled( string $template ): string {
		return \strtr( $template, [ '{operator}' => $this->name ] + ( \str_contains( $template, '{tracks}' ) ? [ '{tracks}' => $this->tracks ] : [] ) );
	}

	/**
	 * What the Intelligence page shows: a configured name as given, its
	 * heading the same, and the generic one translated, its heading
	 * capitalized.
	 *
	 * @return array{name:string,heading:string}
	 */
	public function shown(): array {
		return $this->named ? [
			'name'    => $this->name,
			'heading' => $this->name,
		] : [
			'name'    => \__( 'the organization', 'newspack-intelligence' ),
			'heading' => \__( 'The organization', 'newspack-intelligence' ),
		];
	}
}
