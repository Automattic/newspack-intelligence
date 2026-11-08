<?php
/**
 * Eval_CLI_Command: `wp newspack-intelligence eval <file> [--check=<kind>]
 * [--dry-run]`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Intelligence\Prompts;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * Replays the review's checks against labelled items: each label asks the
 * decision model its kind's `fits_kind` question, and `about_publisher`
 * when it names a publisher, over the stored item as the extractor states
 * it, through the LLM_Call node Intelligence's topology configures, one
 * call at a time. Each check is scored on whether it keeps the kind, at
 * its bar, against the label's truth.
 *
 * @phpstan-type Label array{line:int,item_key:string,kind:string,fits_kind:bool,about_publisher?:bool,publisher?:string}
 * @phpstan-type Asked array<string,array{question:string,truth:bool,bar:float}>
 * @phpstan-type Item array{source:string,tier:string,title:string,body:string,summary:string}
 */
final class Eval_CLI_Command {
	use Refuses_Root;

	/** The fields a label may name. */
	private const FIELDS = [ 'item_key', 'kind', 'fits_kind', 'about_publisher', 'publisher' ];

	/** The node each answer reaches; its Curl is `<name>:curl`. */
	private const RECEIVER = 'intel:eval';

	/**
	 * Score the review's checks against a labels file, one JSON object a
	 * line: `{ item_key, kind, fits_kind }`, `kind` a kind
	 * `Prompts::KIND_CHECKS` holds and `fits_kind` whether the item fits it,
	 * and optionally `about_publisher` with `publisher`, the client's name.
	 * Prints each check's precision and recall at its bar, then each miss.
	 * A restricted item is never sent to the decision model.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The labels file, JSONL.
	 *
	 * [--check=<kind>]
	 * : Score one check alone: a kind check, or about_publisher.
	 *
	 * [--dry-run]
	 * : Print how many decision calls the eval would make, and make none.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence eval newspack-intelligence-eval.jsonl
	 *     wp newspack-intelligence eval newspack-intelligence-eval.jsonl --check=launch
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>      $args       Positional args.
	 * @param array<string,mixed>    $assoc_args Flags.
	 * @throws Worker_Should_Stop When a cooperative stop reaches the run.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'eval' ) ) {
			return;
		}
		try {
			self::run( Core::as_string( $args[0] ?? '' ), $assoc_args );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Read the labels and their items, then count the calls or make them
	 * and print the scores.
	 *
	 * @param string              $path       The labels file.
	 * @param array<string,mixed> $assoc_args Flags.
	 * @throws \InvalidArgumentException When a label, the check or the items refuse.
	 * @throws \RuntimeException When the topology or the projection cannot be read.
	 */
	private static function run( string $path, array $assoc_args ): void {
		$check  = self::check( $assoc_args );
		$asking = [];
		foreach ( self::labels( $path ) as $label ) {
			$asked = self::asked( $label, $check );
			if ( [] !== $asked ) {
				$asking[] = [ $label, $asked ];
			}
		}
		$items   = self::items( \array_map( static fn ( array $ask ): string => $ask[0]['item_key'], $asking ) );
		$missing = \array_filter( $asking, static fn ( array $ask ): bool => ! isset( $items[ $ask[0]['item_key'] ] ) );
		if ( [] !== $missing ) {
			throw new \InvalidArgumentException( \esc_html( 'The projection holds no item for ' . \implode( ', ', \array_map( static fn ( array $ask ): string => "{$ask[0]['item_key']} (line {$ask[0]['line']})", $missing ) ) . '.' ) );
		}
		$sent    = \array_values( \array_filter( $asking, static fn ( array $ask ): bool => Intel_Tiers::unrestricted( $items[ $ask[0]['item_key'] ] ) ) );
		$skipped = \count( $asking ) - \count( $sent );
		if ( 0 < $skipped ) {
			\WP_CLI::log( 'Skipped ' . self::counted( $skipped, 'restricted label' ) . ': the decision model is never sent a restricted item.' );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( 'Would make ' . self::counted( \count( $sent ), 'decision call' ) . '; made none.' );
			return;
		}
		self::score( $sent, self::decide( $sent, $items ) );
	}

	/**
	 * Ask the decision model each label's questions, one call at a time,
	 * through a Curl under the topology's LLM_Call vault group; each answer
	 * read as the LLM_Call node reads one, by the label's place.
	 *
	 * @param list<array{0:Label,1:Asked}> $sent  The labels to ask, with their checks.
	 * @param array<string,Item>           $items The items, by key.
	 * @return list<array{error:string}|array{content:string}|array{decision:array<string,float|array{choice:string,confidence:float}>}>
	 * @throws \InvalidArgumentException When the topology's LLM_Call cannot make a decision call.
	 * @throws \RuntimeException As `Worker_Route::topology()`.
	 */
	private static function decide( array $sent, array $items ): array {
		[ $group, $api_url, $model, $feature ] = self::llm();
		$inputs   = [];
		$requests = [];
		foreach ( $sent as [ $label, $asked ] ) {
			$item       = $items[ $label['item_key'] ];
			$questions  = \array_column( $asked, 'question' );
			$input      = [ 'summary' => $item['summary'] ]
				+ ( \in_array( Prompts::FITS_KIND, $questions, true ) ? [ 'kind' => $label['kind'] ] : [] )
				+ ( \in_array( Prompts::ABOUT_PUBLISHER, $questions, true ) ? [ 'publisher' => $label['publisher'] ?? '' ] : [] );
			$inputs[]   = $input;
			$requests[] = LLM_Call_Node::curl_request( $api_url, $model, $feature, Prompts::EXTRACT_REVIEW, $item, $input, [] );
		}
		$answers = self::fetch( $group, $requests );
		return \array_map( static fn ( int $at ): array => LLM_Call_Node::outcome( $answers[ $at ], Prompts::EXTRACT_REVIEW, $inputs[ $at ] ), \array_keys( $requests ) );
	}

	/**
	 * POST each request through a Curl under `$group`, the next once the one
	 * before is answered, inside the event loop until every one is.
	 *
	 * @param string                    $group    The vault group.
	 * @param list<array<string,mixed>> $requests What Curl runs, each `{ url, method, … }`.
	 * @return array<int,array<int,mixed>> Curl's answer to each, by its place.
	 * @throws \Throwable What tearing the nodes down threw.
	 */
	private static function fetch( string $group, array $requests ): array {
		/** @var array<int,array<int,mixed>> $answers */
		$answers  = [];
		$receiver = new Callback_Node(
			static function ( array $message ) use ( &$answers ): void {
				/** @var array<int,mixed> $message */
				$answers[ Core::as_int( $message[ Message::KEY ] ) ] = $message;
			}
		);
		$curl     = new Curl_Node();
		try {
			$receiver->name( self::RECEIVER );
			$curl->name( self::RECEIVER . ':curl' );
			$curl->arguments( [ $group ] );
			$curl->sink( Bootstrap::mount_request_graph() );
			$curl->target( self::RECEIVER );
			$next = 0;
			// By reference: an arrow fn holds the empty map it was born with.
			Event_Framework::instance()->drain(
				static function () use ( &$answers, &$next, $curl, $requests ): bool {
					if ( \count( $answers ) === $next && isset( $requests[ $next ] ) ) {
						$message                   = Message::new_message();
						$message[ Message::TYPE ]  = Message::TM_STRUCT;
						$message[ Message::FROM ]  = self::RECEIVER;
						$message[ Message::KEY ]   = (string) $next;
						$message[ Message::VALUE ] = $requests[ $next ];
						++$next;
						$curl->fill( $message );
					}
					return \count( $answers ) < \count( $requests );
				}
			);
		} finally {
			Worker_Should_Stop::raise( Worker_Should_Stop::attempt_each( [ $curl, $receiver ], static fn ( Node $node ) => $node->remove_node() ) );
		}
		return $answers;
	}

	/**
	 * What Intelligence's topology's LLM_Call posts a decision with: its
	 * vault group, api url, decision model and feature.
	 *
	 * @return array{0:string,1:string,2:string,3:string}
	 * @throws \InvalidArgumentException When it declares none, one unset, or a group lending no credential on the api url.
	 * @throws \RuntimeException As `Worker_Route::topology()`.
	 */
	private static function llm(): array {
		$topology = Worker_Route::topology();
		$node     = Topology_Analyzer::nodes_of_type( $topology, LLM_Call_Node::class )[0] ?? throw new \InvalidArgumentException( \esc_html( "{$topology} declares no LLM_Call node to ask the decision model through." ) );
		$name     = Core::as_string( $node['name'] );
		$api_url  = Core::as_string( $node['api_url'] );
		$model    = Core::as_string( $node['decision_model'] );
		$feature  = Core::as_string( $node['feature'] );
		if ( '' === $api_url || '' === $model || '' === $feature ) {
			throw new \InvalidArgumentException( \esc_html( "{$name}: set_api_url, set_decision_model and set_feature before a decision." ) );
		}
		$group = Core::as_string( Worker_Route::line( LLM_Call_Node::class, $node )['vault_group'] );
		if ( ! Curl_Node::lends_credential( $group, $api_url ) ) {
			throw new \InvalidArgumentException( \esc_html( "The {$group} Vault group lends no credential on {$api_url}: lend one before an eval." ) );
		}
		return [ $group, $api_url, $model, $feature ];
	}

	/**
	 * Print each check's precision and recall of keeping the kind, at its
	 * bar, against the labels' truth, then each miss; a failed call is
	 * named, left out, and fails the run.
	 *
	 * @param list<array{0:Label,1:Asked}> $sent     The labels asked, with their checks.
	 * @param list<array<string,mixed>>    $outcomes Each call's outcome, by its place.
	 */
	private static function score( array $sent, array $outcomes ): void {
		$tallies = [];
		$misses  = [];
		$failed  = 0;
		foreach ( $sent as $at => [ $label, $asked ] ) {
			$decision = Core::arr( $outcomes[ $at ]['decision'] ?? null );
			if ( ! isset( $outcomes[ $at ]['decision'] ) ) {
				++$failed;
				\WP_CLI::warning( "{$label['item_key']} (line {$label['line']}): " . Core::as_string( $outcomes[ $at ]['error'] ?? null ) );
				continue;
			}
			foreach ( $asked as $check => $ask ) {
				$noul = Core::as_float( $decision[ $ask['question'] ] );
				$kept = $noul >= $ask['bar'];
				$tally = $tallies[ $check ] ?? [ 'count' => 0, 'kept' => 0, 'wanted' => 0, 'both' => 0, 'bar' => $ask['bar'] ];
				++$tally['count'];
				$tally['kept']   += (int) $kept;
				$tally['wanted'] += (int) $ask['truth'];
				$tally['both']   += (int) ( $kept && $ask['truth'] );
				$tallies[ $check ] = $tally;
				if ( $kept !== $ask['truth'] ) {
					$misses[] = \sprintf( '%s %s answered %.3f wanted %s', $label['item_key'], $check, $noul, $ask['truth'] ? 'keep' : 'drop' );
				}
			}
		}
		foreach ( \array_intersect_key( \array_flip( self::checks() ), $tallies ) as $check => $unused ) {
			$tally = $tallies[ $check ];
			\WP_CLI::log( "{$check}: " . self::counted( $tally['count'], 'label' ) . ', precision ' . self::ratio( $tally['both'], $tally['kept'] ) . ', recall ' . self::ratio( $tally['both'], $tally['wanted'] ) . ' at the bar ' . $tally['bar'] );
		}
		foreach ( $misses as $miss ) {
			\WP_CLI::log( $miss );
		}
		if ( 0 < $failed ) {
			\WP_CLI::error( self::counted( $failed, 'decision call' ) . ' failed; the scores leave ' . ( 1 === $failed ? 'it' : 'them' ) . ' out.' );
			return;
		}
		\WP_CLI::success( 'Scored ' . self::counted( \count( $sent ), 'label' ) . ' in ' . self::counted( \count( $sent ), 'decision call' ) . '.' );
	}

	/** A ratio to two places, `n/a` over nothing. */
	private static function ratio( int $part, int $whole ): string {
		return 0 === $whole ? 'n/a' : \sprintf( '%.2f', $part / $whole );
	}

	/** `$count` and the noun, plural but for one. */
	private static function counted( int $count, string $noun ): string {
		return "{$count} {$noun}" . ( 1 === $count ? '' : 's' );
	}

	/**
	 * The stored items the keys name, across every partition, each with its
	 * extraction's summary, '' when it has none; an item any partition
	 * holds at a tier outside `Intel_Tiers::UNRESTRICTED` keeps that tier,
	 * whichever partition's row reads last, so it is never sent.
	 *
	 * @param list<string> $keys The item keys.
	 * @return array<string,Item>
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection will not open.
	 */
	private static function items( array $keys ): array {
		$rows  = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => $r->rows(
				'SELECT i.item_key, i.source, i.tier, i.title, i.body, COALESCE( e.summary, \'\' ) FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' ) . ' i'
				. ' LEFT JOIN ' . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . ' e ON e.item_key = i.item_key'
				. ' WHERE i.item_key IN ( SELECT value FROM json_each( ? ) )',
				[ \wp_json_encode( \array_values( \array_unique( $keys ) ), \JSON_THROW_ON_ERROR ) ]
			)
		);
		$items = [];
		foreach ( $rows as [ $key, $source, $tier, $title, $body, $summary ] ) {
			$key  = Core::as_string( $key );
			$tier = Core::as_string( $tier );
			if ( isset( $items[ $key ] ) && ! Intel_Tiers::unrestricted( $items[ $key ] ) ) {
				$tier = $items[ $key ]['tier'];
			}
			$items[ $key ] = [
				'source'  => Core::as_string( $source ),
				'tier'    => $tier,
				'title'   => Core::as_string( $title ),
				'body'    => Core::as_string( $body ),
				'summary' => Core::as_string( $summary ),
			];
		}
		return $items;
	}

	/**
	 * The checks a label asks, by name: its kind's `fits_kind`, and
	 * `about_publisher` when it names a publisher, each with its question,
	 * the label's truth and its bar; only `$check` when one is named.
	 *
	 * @param Label $label The label.
	 * @return Asked
	 */
	private static function asked( array $label, ?string $check ): array {
		$asked = [
			$label['kind'] => [
				'question' => Prompts::FITS_KIND,
				'truth'    => $label['fits_kind'],
				'bar'      => Prompts::KIND_CHECKS[ $label['kind'] ]['min'],
			],
		];
		if ( isset( $label['about_publisher'] ) ) {
			$asked[ Prompts::ABOUT_PUBLISHER ] = [
				'question' => Prompts::ABOUT_PUBLISHER,
				'truth'    => $label['about_publisher'],
				'bar'      => Extractor_Node::ABOUT_PUBLISHER_MIN,
			];
		}
		return null === $check ? $asked : \array_intersect_key( $asked, [ $check => true ] );
	}

	/**
	 * The labels a file holds, each with its line; a blank line holds none.
	 *
	 * @return list<Label>
	 * @throws \InvalidArgumentException When the file will not read, holds no label, or a line is not a label.
	 */
	private static function labels( string $path ): array {
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local labels path, the WP-CLI arg, not a remote fetch.
		$text = \is_file( $path ) && \is_readable( $path ) ? \file_get_contents( $path ) : false;
		if ( false === $text ) {
			throw new \InvalidArgumentException( \esc_html( "Cannot read {$path}." ) );
		}
		$labels = [];
		foreach ( \explode( "\n", $text ) as $at => $line ) {
			if ( '' !== \trim( $line ) ) {
				$labels[] = self::label( $at + 1, $line );
			}
		}
		if ( [] === $labels ) {
			throw new \InvalidArgumentException( \esc_html( "{$path} holds no label." ) );
		}
		return $labels;
	}

	/**
	 * One line read as a label.
	 *
	 * @return Label
	 * @throws \InvalidArgumentException Naming the line and what it lacks.
	 */
	private static function label( int $line, string $text ): array {
		$fields = \json_decode( $text, true );
		$refuse = static fn ( string $why ): \InvalidArgumentException => new \InvalidArgumentException( \esc_html( "line {$line}: {$why}." ) );
		if ( ! \is_array( $fields ) || \array_is_list( $fields ) ) {
			throw $refuse( 'not a JSON object' );
		}
		$unknown = \array_diff( \array_map( 'strval', \array_keys( $fields ) ), self::FIELDS );
		if ( [] !== $unknown ) {
			throw $refuse( 'unknown field ' . \implode( ', ', $unknown ) . '; a label names item_key, kind, fits_kind, about_publisher and publisher' );
		}
		$key  = $fields['item_key'] ?? null;
		$kind = $fields['kind'] ?? null;
		if ( ! \is_string( $key ) || '' === $key ) {
			throw $refuse( 'item_key is not a string naming an item' );
		}
		if ( ! \is_string( $kind ) || ! isset( Prompts::KIND_CHECKS[ $kind ] ) ) {
			throw $refuse( 'kind ' . Core::as_string( \is_scalar( $kind ) ? $kind : \wp_json_encode( $kind ) ) . ' is not one of ' . \implode( ', ', \array_keys( Prompts::KIND_CHECKS ) ) );
		}
		if ( ! \is_bool( $fields['fits_kind'] ?? null ) ) {
			throw $refuse( 'fits_kind is not true or false' );
		}
		$label = [
			'line'      => $line,
			'item_key'  => $key,
			'kind'      => $kind,
			'fits_kind' => $fields['fits_kind'],
		];
		if ( ! isset( $fields['about_publisher'] ) && ! isset( $fields['publisher'] ) ) {
			return $label;
		}
		$about     = $fields['about_publisher'] ?? null;
		$publisher = $fields['publisher'] ?? null;
		if ( ! \is_bool( $about ) || ! \is_string( $publisher ) || '' === \trim( $publisher ) ) {
			throw $refuse( 'about_publisher and publisher come together: about_publisher true or false, publisher a name' );
		}
		return $label + [
			'about_publisher' => $about,
			'publisher'       => \trim( $publisher ),
		];
	}

	/**
	 * The one check `--check` names, or null for every check.
	 *
	 * @param array<string,mixed> $assoc_args Flags.
	 * @throws \InvalidArgumentException When it names no check.
	 */
	private static function check( array $assoc_args ): ?string {
		if ( ! isset( $assoc_args['check'] ) ) {
			return null;
		}
		$check = Core::as_string( $assoc_args['check'] );
		if ( ! \in_array( $check, self::checks(), true ) ) {
			throw new \InvalidArgumentException( \esc_html( "--check={$check} is not one of " . \implode( ', ', self::checks() ) . '.' ) );
		}
		return $check;
	}

	/**
	 * Every check, in the order the scores print: each kind check, then
	 * `about_publisher`.
	 *
	 * @return list<string>
	 */
	private static function checks(): array {
		return [ ...\array_keys( Prompts::KIND_CHECKS ), Prompts::ABOUT_PUBLISHER ];
	}
}
