<?php
/**
 * Intelligence_Blocks: the blocks Intelligence's pages are composed of and
 * the three contexts they compose, declared on the substrate's composition
 * layer.
 *
 * The Briefing, every team view's page and every profile's sections are
 * each a layout of these blocks, which the browser registers alone, so no
 * post, site or widget editor ever offers one. Every reader reads every
 * layout, so a layout may name only what a reader without the restricted
 * tier could see.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Composition;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * The block table, the contexts, and the check every layout passes.
 *
 * @phpstan-import-type Graph from Graph_Query
 */
final class Intelligence_Blocks {

	/** The namespace every block and context sits under. */
	public const NAMESPACE = 'newspack-intelligence';

	/**
	 * The most blocks one layout holds, core's columns, headings and prose
	 * counted with the rest: room for 24 of Intelligence's blocks, each under
	 * a heading and a line of prose in columns, while each block sends at
	 * most one read of its own.
	 */
	public const MAX_BLOCKS = 64;

	/**
	 * Block name => its declaration, in `Composition`'s shape. Each screen
	 * adds the rows its blocks need.
	 */
	public const BLOCKS = [
		'newspack-intelligence/pulse' => [
			'title'      => 'Pulse line',
			'contexts'   => [ 'newspack-intelligence/briefing', 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'lens' => [
					'type'    => 'string',
					'default' => '',
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/entity-facts' => [
			'title'      => 'Facts',
			'contexts'   => [ 'newspack-intelligence/entity' ],
			'attributes' => [
				'show'  => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => [ 'counts', 'claims', 'details' ],
					],
					'uniqueItems' => true,
					'default'     => [ 'counts', 'claims', 'details' ],
				],
				'limit' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				],
			],
		],
		'newspack-intelligence/related' => [
			'title'      => 'Related',
			'contexts'   => [ 'newspack-intelligence/entity' ],
			'attributes' => [
				'heading' => self::LABEL + [ 'default' => 'Related' ],
				'limit'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 5,
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/timeline' => [
			'title'      => 'Activity',
			'contexts'   => [ 'newspack-intelligence/entity' ],
			'attributes' => [
				'heading'   => self::LABEL + [ 'default' => 'Activity' ],
				'published' => [
					'type'    => 'string',
					'enum'    => [ 'disclosure', 'none', 'only' ],
					'default' => 'disclosure',
				],
				'layout'    => [
					'type'    => 'string',
					'enum'    => [ 'activity', 'list' ],
					'default' => 'activity',
				],
				'limit'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/health' => [
			'title'      => 'Collection status',
			'contexts'   => [ 'newspack-intelligence/briefing', 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'detail' => [
					'type'    => 'string',
					'enum'    => [ 'summary', 'sources' ],
					'default' => 'summary',
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/ask' => [
			'title'      => 'Ask a question',
			'contexts'   => [ 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'label'       => self::LABEL + [ 'default' => 'Ask a question' ],
				'placeholder' => self::LABEL + [ 'default' => 'Ask about anything collected here…' ],
				'examples'    => [
					'type'     => 'array',
					'items'    => self::LABEL,
					'maxItems' => 4,
					'default'  => [ 'What changed last week?', 'What are people asking for this month?' ],
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/since' => [
			'title'      => 'Since you last looked',
			'contexts'   => [ 'newspack-intelligence/briefing' ],
			'attributes' => [
				'kinds' => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => Intelligence_Briefing::SUMMARY_KINDS,
					],
					'uniqueItems' => true,
					'default'     => Intelligence_Briefing::SUMMARY_KINDS,
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/needs-attention' => [
			'title'      => 'Needs attention',
			'contexts'   => [ 'newspack-intelligence/briefing' ],
			'attributes' => [
				'heading' => self::LABEL + [ 'default' => 'Needs attention' ],
				'max'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 8,
				],
				'clients' => [
					'type'    => 'boolean',
					'default' => true,
				],
				'lenses'  => self::LENS_KEYS,
			],
			'multiple'   => false,
		],
		'newspack-intelligence/watches' => [
			'title'      => 'Your watches',
			'contexts'   => [ 'newspack-intelligence/briefing' ],
			'attributes' => [
				'heading' => self::LABEL + [ 'default' => 'Your watches' ],
				'max'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 5,
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/week' => [
			'title'      => 'This week',
			'contexts'   => [ 'newspack-intelligence/briefing' ],
			'attributes' => [
				'heading'    => self::LABEL + [ 'default' => 'This week, most important first' ],
				'top'        => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 50,
					'default' => 10,
				],
				'categories' => self::LENS_KEYS + [ 'maxItems' => Intelligence_Briefing::CATEGORY_MAX ],
				'otherwise'  => self::LABEL + [ 'default' => 'Everything else' ],
				'by_client'  => [
					'type'    => 'boolean',
					'default' => true,
				],
			],
			'multiple'   => false,
		],
		'newspack-intelligence/trend-chart' => [
			'title'      => 'Trend chart',
			'contexts'   => [ 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'heading' => self::LABEL + [ 'default' => 'Last 8 weeks, by %s' ],
				'by'      => [
					'type'    => 'string',
					'enum'    => [ '', 'kind', 'topic', 'theme' ],
					'default' => '',
				],
				'bucket'  => [
					'type'    => 'string',
					'enum'    => [ '', 'day', 'week', 'month' ],
					'default' => '',
				],
				'style'   => [
					'type'    => 'string',
					'enum'    => [ 'stack', 'lines' ],
					'default' => 'stack',
				],
			],
		],
		'newspack-intelligence/item-list' => [
			'title'      => 'Item list',
			'contexts'   => [ 'newspack-intelligence/briefing', 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'heading' => self::LABEL + [ 'default' => '' ],
				'kinds'   => [ 'items' => [ 'type' => 'string', 'enum' => Intel_Event::ITEM_KINDS ] ] + self::UNIQUE,
				'topics'  => [ 'items' => [ 'type' => 'string' ] ] + self::UNIQUE,
				'roles'   => [ 'items' => [ 'type' => 'string', 'enum' => Intel_Event::ROLES ] ] + self::UNIQUE,
				'sources' => [ 'items' => [ 'type' => 'string', 'enum' => Intel_Event::SOURCES ] ] + self::UNIQUE,
				'lens'    => [
					'type'    => 'string',
					'default' => '',
				],
				'group'   => [
					'type'    => 'string',
					'enum'    => [ '', 'theme' ],
					'default' => '',
				],
				'sort'    => [
					'type'    => 'string',
					'enum'    => [ 'importance', 'time' ],
					'default' => 'importance',
				],
				'layout'  => [
					'type'    => 'string',
					'enum'    => [ 'list', 'activity', 'table' ],
					'default' => 'list',
				],
				'limit'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				],
				'tiers'   => [ 'items' => [ 'type' => 'string', 'enum' => Intel_Event::TIERS ] ] + self::UNIQUE,
				'empty'   => self::LABEL + [ 'default' => 'Nothing here yet.' ],
			],
		],
		'newspack-intelligence/folder-board' => [
			'title'      => 'Folders',
			'contexts'   => [ 'newspack-intelligence/briefing', 'newspack-intelligence/lens', 'newspack-intelligence/entity' ],
			'attributes' => [
				'show'    => [
					'type'    => 'string',
					'enum'    => [ 'all', 'unused', ...Intel_Event::MARKS ],
					'default' => 'unused',
				],
				'folders' => [ 'items' => [ 'type' => 'string' ] ] + self::UNIQUE,
			],
			'multiple'   => false,
		],
	];

	/** A list naming each value once, none by default; a row adds its `items`. */
	private const UNIQUE = [
		'type'        => 'array',
		'uniqueItems' => true,
		'default'     => [],
	];

	/** A heading, label or other line of plain text a block shows. */
	private const LABEL = [
		'type'      => 'string',
		'maxLength' => 80,
	];

	/** A list of lens keys, each once, none by default. */
	private const LENS_KEYS = [
		'type'        => 'array',
		'items'       => [ 'type' => 'string' ],
		'uniqueItems' => true,
		'default'     => [],
	];

	/** Context => its default layout and its block cap. */
	public const CONTEXTS = [
		'newspack-intelligence/briefing' => [
			'default'    => __DIR__ . '/../layouts/briefing.html',
			'max_blocks' => self::MAX_BLOCKS,
		],
		'newspack-intelligence/lens'     => [
			'default'    => __DIR__ . '/../layouts/lens.html',
			'max_blocks' => self::MAX_BLOCKS,
		],
		'newspack-intelligence/entity'   => [
			'default'    => __DIR__ . '/../layouts/entity.html',
			'max_blocks' => self::MAX_BLOCKS,
		],
	];

	/** Attribute => what its keys name, wherever a block declares it. */
	private const KEYED = [
		'lens'       => 'lens',
		'lenses'     => 'lens',
		'categories' => 'lens',
		'topics'     => 'entity',
		'folders'    => 'collection',
	];

	/**
	 * Add Intelligence's blocks and contexts to the composition filter's map,
	 * each context checked by `check()`.
	 *
	 * @param array<string,mixed> $compositions Namespace => `{ blocks, contexts }`.
	 * @return array<string,mixed>
	 */
	public static function declare( array $compositions ): array {
		$contexts = [];
		foreach ( self::CONTEXTS as $name => $context ) {
			$contexts[ $name ] = $context + [ 'check' => self::check( ... ) ];
		}
		return $compositions + [
			self::NAMESPACE => [
				'blocks'   => self::BLOCKS,
				'contexts' => $contexts,
			],
		];
	}

	/**
	 * Refuse a block naming a lens never saved, a collection never made, or
	 * an entity or topic a reader without the restricted tier could not see
	 * (`Graph_Query::sees()`), whatever tiers the caller holds, as
	 * `save_lens` refuses one, a key whose merge chain is broken among them.
	 * A block naming no key reads nothing.
	 *
	 * @param string              $block      The block's name.
	 * @param array<string,mixed> $attributes Its validated attributes.
	 * @param string              $context    The context it stands on.
	 * @throws \RuntimeException Naming the block, the attribute and the key.
	 * @throws \Newspack_Nodes\Table_Unavailable When a key is named and the projection will not open.
	 */
	public static function check( string $block, array $attributes, string $context ): void {
		$named = [];
		foreach ( self::KEYED as $attribute => $kind ) {
			foreach ( (array) ( $attributes[ $attribute ] ?? [] ) as $key ) {
				$key = Core::as_string( $key );
				if ( '' !== $key ) {
					$named[] = [ $attribute, $kind, $key ];
				}
			}
		}
		if ( [] === $named ) {
			return;
		}
		$refusal = Graph_Curation::on_graph( static fn ( Row_Reader $r, array $g ): array => self::unseen( $r, $g, $named ) );
		if ( [] !== $refusal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers.
			throw new \RuntimeException( "{$block} {$refusal[0]}: {$refusal[1]}" );
		}
	}

	/**
	 * The first key a reader without the restricted tier could not see, or
	 * that names nothing, as `[ attribute, why ]`, or none.
	 *
	 * @param Row_Reader                        $r     The reader.
	 * @param Graph                             $g     The caller's graph.
	 * @param list<array{string,string,string}> $named Each attribute, what it names and the key.
	 * @return array{}|array{string,string}
	 */
	private static function unseen( Row_Reader $r, array $g, array $named ): array {
		$every = Graph_Query::unrestricted( $r, $g );
		foreach ( $named as [ $attribute, $kind, $key ] ) {
			$seen = match ( $kind ) {
				'lens'       => null !== Graph_Query::saved_lens( $r, $key ),
				'collection' => null !== Graph_Query::collection( $r, $key ),
				default      => Graph_Query::sees( $r, $every, $key ),
			};
			if ( ! $seen ) {
				return [ $attribute, "no {$kind}: {$key}" ];
			}
		}
		return [];
	}

	/**
	 * Each composed page => the context it lays out, the map
	 * `Composition::enqueue_editor()` reads.
	 *
	 * @return array<string,string>
	 */
	public static function page_contexts(): array {
		return \array_combine( self::pages(), \array_keys( self::CONTEXTS ) );
	}

	/**
	 * The pages the contexts lay out, each its context's name less the
	 * namespace.
	 *
	 * @return list<string>
	 */
	public static function pages(): array {
		return \array_map( static fn ( string $context ): string => \substr( $context, \strlen( self::NAMESPACE ) + 1 ), \array_keys( self::CONTEXTS ) );
	}

	/**
	 * A block's attributes at their declared defaults, as the composition
	 * layer holds its declaration.
	 *
	 * @param string $block The block's name.
	 * @return array<string,mixed>
	 */
	public static function defaults( string $block ): array {
		return \array_map( static fn ( array $schema ): mixed => $schema['default'], Composition::declarations()['blocks'][ $block ]['attributes'] );
	}
}
