<?php
/**
 * Watcher_Node: runs every standing watch on the Router's tick, once each
 * WATCH_INTERVAL_S. A pass waits while the projector has not recorded the
 * end of the watches stream, so it never reads a mark or a match it wrote
 * itself as missing, and asks again on the Router's next tick rather than
 * its next interval. It then reads every watch from the projection and, for
 * each owner in turn, drops the owner's cached user, signs in as them, and
 * signs back in as whoever was signed in before the pass in a `finally`
 * that covers the sign-in itself, so every read answers exactly the tiers
 * that owner holds now and nothing leaks into the next owner. An owner who
 * no longer exists, or no longer holds read, is skipped and logged.
 *
 * Each owner's pass is one projection read that plans it, then the acts.
 * For each watch the read finds, through the path a lens, an entity or a
 * collection reads through (`Graph_Query::arrivals()`), the target's items
 * the watch has not matched, entering it at or after a day (LATE_S) before
 * the watch's high-water mark, or before its saving on a first pass, so an
 * item that joins late, as an extraction retried from `intel:later` does,
 * is still matched; at most PASS_LIMIT, the oldest first, so a backlog
 * drains over passes. It appends a `watch_matched` per item, quiet when the
 * item entered the target before the watch was saved, and a
 * `watch_delivered` `scan` mark when the mark moves; a first pass marks the
 * watch at its saving at least, even when it finds nothing, so nothing older
 * is ever news. A watch asking for instant email is then sent the matches
 * it is owed: each not quiet, recorded after both its last sent email and
 * its saving. Once a day, at the first pass past DIGEST_HOUR_UTC, each
 * owner with a digest watch gets one email of the matches owed since their
 * last digest, and each lens's or entity's signals since then; a digest
 * with nothing in it sends nothing, and either is marked so the day is
 * done. A send that fails is logged and marks nothing, so the next pass
 * sends the same recorded matches again and never matches them again. Every
 * mark is a watches-stream event, so the bookkeeping survives a respawn.
 *
 * Email is plain text, through the one `wp_mail()` seam, to the owner's own
 * address: each match's title as one line of text, its URL only when it is
 * http or https, its source and day, and a link to the watch on the
 * Intelligence page; never an item's body. A failure inside one watch, or
 * one owner, is logged under its own line, naming the owner and the watch,
 * and the next runs; a cooperative stop propagates (ADR-14).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * Runs each standing watch as its owner and emails what it matches.
 *
 * @phpstan-import-type Standing from Graph_Watches
 * @phpstan-import-type Owed from Graph_Watches
 * @phpstan-import-type Item from Graph_Query
 * @phpstan-import-type Graph from Graph_Query
 * @phpstan-type Plan array{arrived:array<string,list<array{at:float,item:Item}>|null>,owed:array<string,list<Owed>>,digest:array{owed:array<string,list<Owed>>,signals:array<string,list<array<string,mixed>>>}|null}
 */
class Watcher_Node extends Timer_Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** Seconds between passes. */
	public const WATCH_INTERVAL_S = 300;

	/** The UTC hour from which a day's digest goes. */
	public const DIGEST_HOUR_UTC = 13;

	/** The most items one watch matches in a pass. */
	public const PASS_LIMIT = Graph_Query::MAX_LIMIT;

	/**
	 * How far behind its mark a watch reads, for an item that joins its
	 * target late: the day `intel:later` parks a retry at most.
	 */
	public const LATE_S = 86400;

	/** The line logged when an owner's read finds no projection. */
	private const UNAVAILABLE = 'watcher: no intel-graph to read an owner\'s watches from; waiting: ';

	/** The most matches an email lists for one watch. */
	private const MAIL_ITEMS = Graph_Query::DEFAULT_LIMIT;

	/** The pipeline stage every event is recorded as. */
	private const STAGE = 'watcher';

	/** Seconds in an hour. */
	private const HOUR_SECONDS = 3600;

	/** The headers every email carries: plain text, nothing else. */
	private const HEADERS = [ 'Content-Type: text/plain; charset=UTF-8' ];

	/**
	 * Mail seam: replaces the one `wp_mail()` call every watch email goes
	 * through. Tests reassign it to capture each email and to choose whether
	 * it sends; null calls `wp_mail()`.
	 * Signature: `function ( string $to, string $subject, string $message, list<string> $headers ): bool`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $wp_mail = null;

	/** The writer every watches-stream event is addressed to. */
	protected string $writer = '';

	/**
	 * Store the writer's name and pass every WATCH_INTERVAL_S on the
	 * Router's tick.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->parse_schema_args( $args );
		$this->set_timer( $this->cadence_ms( self::WATCH_INTERVAL_S ) );
		return $this->arguments;
	}

	/**
	 * One pass over every standing watch, owner by owner, signing back in
	 * after each as whoever was signed in before the pass. While the
	 * projector has not recorded the watches stream's end it asks again on
	 * the Router's next tick rather than its next interval.
	 *
	 * @api Used by substrate.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	protected function fire(): void {
		$stream   = Stream_Writer::stream_dir( Intel_Event::WATCHES );
		$interval = $this->cadence_ms( self::WATCH_INTERVAL_S );
		if ( Projector_Node::behind( $stream ) ) {
			$this->print_less_often( 'waiting for the projector to record the watches stream: ', $stream );
			if ( $interval === $this->interval_ms ) {
				$this->set_timer();
			}
			return;
		}
		if ( $interval !== $this->interval_ms ) {
			$this->set_timer( $interval );
		}
		$standing = Graph_Watches::standing();
		if ( null === $standing ) {
			return;
		}
		$prior = \get_current_user_id();
		foreach ( $standing['owners'] as $owner => $watches ) {
			$this->as_owner( $owner, $watches, $standing['digests'][ $owner ] ?? null, $prior );
		}
	}

	/**
	 * Run one owner's watches, unless the owner is gone, with any failure,
	 * a throwing sign-in among them, logged under their login.
	 *
	 * @param int            $owner    The owner's user id.
	 * @param list<Standing> $watches  Their watches.
	 * @param float|null     $digested When their last digest went, null for never.
	 * @param int            $prior    The user signed in before the pass.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function as_owner( int $owner, array $watches, ?float $digested, int $prior ): void {
		\clean_user_cache( $owner );
		$user = \get_user_by( 'id', $owner );
		if ( ! $user instanceof \WP_User ) {
			$this->print_less_often( "skipping the watches of a user who no longer exists: {$owner}" );
			return;
		}
		$this->guarded( "skipping an owner's watches this pass: {$user->user_login}: ", fn () => $this->signed_in( $user, $watches, $digested, $prior ) );
	}

	/**
	 * Sign in as the owner and, while they hold read, plan their watches
	 * and their digest in one projection read, then act on the plan; sign
	 * back in as the prior user whatever happens, the sign-in included.
	 *
	 * @param \WP_User       $user     The owner.
	 * @param list<Standing> $watches  Their watches.
	 * @param float|null     $digested When their last digest went, null for never.
	 * @param int            $prior    The user signed in before the pass.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function signed_in( \WP_User $user, array $watches, ?float $digested, int $prior ): void {
		try {
			\wp_set_current_user( $user->ID );
			if ( ! Capabilities::can( Capabilities::READ ) ) {
				$this->print_less_often( "skipping the watches of a user who no longer reads: {$user->user_login}" );
				return;
			}
			$due  = $this->digest_due( $digested );
			$plan = Intel_Graph_Row_Schema::read( fn ( Row_Reader $r ): array => $this->plan( $r, $user, $watches, $due ? $digested ?? 0.0 : null ), self::UNAVAILABLE );
			if ( null !== $plan ) {
				$this->act( $user, $watches, $plan );
			}
		} finally {
			\wp_set_current_user( $prior );
		}
	}

	/**
	 * Everything one owner's pass reads, on one open read as that owner:
	 * each watch's arrivals since a day before its mark, or before its
	 * saving on a first pass; the matches its instant email is owed; and,
	 * with the digest due, every digest watch's owed matches and signals.
	 * A watch whose read fails is logged and arrives null.
	 *
	 * @param Row_Reader     $r        The reader.
	 * @param \WP_User       $user     The owner, signed in.
	 * @param list<Standing> $watches  Their watches.
	 * @param float|null     $digested When their last digest went, null when no digest is due.
	 * @return Plan
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function plan( Row_Reader $r, \WP_User $user, array $watches, ?float $digested ): array {
		$g       = Graph_Query::graph( $r );
		$arrived = [];
		$instant = [];
		foreach ( $watches as $watch ) {
			$key             = $watch['watch_key'];
			$arrived[ $key ] = $this->guarded( "skipping a watch this pass: {$user->user_login} {$key}: ", static fn (): array => Graph_Query::arrivals( $r, $g, $watch['kind'], $watch['key'], $key, ( $watch['scan'] ?? $watch['saved'] ) - self::LATE_S, self::PASS_LIMIT ) );
			if ( $watch['instant'] && null !== $arrived[ $key ] ) {
				$instant[] = [ $key, \max( $watch['sent'] ?? 0.0, $watch['saved'] ) ];
			}
		}
		$digest = null;
		$listed = \array_values( \array_filter( $watches, static fn ( array $watch ): bool => $watch['digest'] ) );
		if ( null !== $digested && [] !== $listed ) {
			$signals = [];
			foreach ( $listed as $watch ) {
				$key             = $watch['watch_key'];
				$signals[ $key ] = 'collection' === $watch['kind'] ? [] : $this->guarded( "leaving a watch's signals out of a digest: {$user->user_login} {$key}: ", static fn (): array => Graph_Query::spikes( $r, $g, [ $watch['kind'] => $watch['key'] ], $digested ) ) ?? [];
			}
			$cuts   = \array_map( static fn ( array $watch ): array => [ $watch['watch_key'], \max( $digested, $watch['saved'] ) ], $listed );
			$digest = [ 'owed' => self::by_watch( Graph_Watches::owed( $r, $g, $cuts ) ), 'signals' => $signals ];
		}
		return [ 'arrived' => $arrived, 'owed' => self::by_watch( Graph_Watches::owed( $r, $g, $instant ) ), 'digest' => $digest ];
	}

	/**
	 * Matches grouped by their watch.
	 *
	 * @param list<Owed> $owed The matches.
	 * @return array<string,list<Owed>>
	 */
	private static function by_watch( array $owed ): array {
		$by = [];
		foreach ( $owed as $match ) {
			$by[ $match['watch_key'] ][] = $match;
		}
		return $by;
	}

	/**
	 * Record what each watch's plan found, email each instant watch its
	 * owed matches and this pass's news, then send the digest the plan
	 * holds.
	 *
	 * @param \WP_User       $user    The owner, signed in.
	 * @param list<Standing> $watches Their watches.
	 * @param Plan           $plan    What the read found.
	 */
	private function act( \WP_User $user, array $watches, array $plan ): void {
		$news = [];
		foreach ( $watches as $watch ) {
			$key     = $watch['watch_key'];
			$arrived = $plan['arrived'][ $key ] ?? null;
			if ( null === $arrived ) {
				continue;
			}
			$news[ $key ] = $this->record( $watch, $arrived );
			if ( $watch['instant'] ) {
				$this->instant( $user, $watch, [ ...( $plan['owed'][ $key ] ?? [] ), ...$news[ $key ] ] );
			}
		}
		if ( null !== $plan['digest'] ) {
			$this->digest( $user, $watches, $plan['digest'], $news );
		}
	}

	/**
	 * Run one unit of a pass, logging its failure under its own line, which
	 * names its subject, and answering null, so the next unit runs. A
	 * cooperative stop propagates first (ADR-14).
	 *
	 * @template T
	 * @param string        $line What the log line says, its subject named.
	 * @param \Closure(): T $run  The unit.
	 * @return T|null
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function guarded( string $line, \Closure $run ): mixed {
		try {
			return $run();
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( $line, $e->getMessage() );
		}
		return null;
	}

	/**
	 * Record a watch's arrivals, an item that entered its target before the
	 * watch was saved quiet, and mark how far the watch reached: on a first
	 * pass at least its saving, so nothing older is ever news.
	 *
	 * @param Standing                          $watch   The watch.
	 * @param list<array{at:float,item:Item}>   $arrived Its arrivals.
	 * @return list<Owed> What this pass matched that is news.
	 */
	private function record( array $watch, array $arrived ): array {
		$through = $watch['scan'] ?? $watch['saved'];
		$news    = [];
		foreach ( $arrived as [ 'at' => $at, 'item' => $item ] ) {
			$quiet = $at < $watch['saved'];
			$this->emit( $this->writer, Intel_Event::machine_event( self::STAGE, 'watch_matched', [ 'watch_key' => $watch['watch_key'], 'owner' => $watch['owner'], 'item_key' => $item['item_key'], 'at' => $at, 'retrieved_ts' => $item['retrieved_ts'], 'quiet' => $quiet ] ) );
			$through = \max( $through, $at );
			if ( ! $quiet ) {
				$news[] = Graph_Watches::matched( $watch['watch_key'], $item, Core::$now );
			}
		}
		if ( null === $watch['scan'] || $through > $watch['scan'] ) {
			$this->delivered( 'scan', $watch['watch_key'], $watch['owner'], $through );
		}
		return $news;
	}

	/**
	 * Email a watch its owed matches, marking them sent once the email goes.
	 *
	 * @param \WP_User   $user  The owner, signed in.
	 * @param Standing   $watch The watch.
	 * @param list<Owed> $items Its matches recorded after its last sent email and its saving, and this pass's news.
	 */
	private function instant( \WP_User $user, array $watch, array $items ): void {
		if ( [] !== $items && $this->send( $user, self::line( $watch['name'] ) . ': ' . self::matches( \count( $items ) ), self::section( $watch, $items, [] ), $watch['watch_key'] ) ) {
			$this->delivered( 'instant', $watch['watch_key'], $watch['owner'], Core::$now );
		}
	}

	/**
	 * Whether today's digest is due: the hour has come and the last digest
	 * went before it.
	 *
	 * @param float|null $digested When the last digest went, null for never.
	 */
	private function digest_due( ?float $digested ): bool {
		$hour = \intdiv( (int) Core::$now, Intel_Graph_Row_Schema::DAY_SECONDS ) * Intel_Graph_Row_Schema::DAY_SECONDS + self::DIGEST_HOUR_UTC * self::HOUR_SECONDS;
		return Core::$now >= $hour && ( $digested ?? 0.0 ) < $hour;
	}

	/**
	 * Email an owner one digest across their digest watches: each watch's
	 * owed matches with this pass's news, and its signals; then mark the
	 * day, sent or empty.
	 *
	 * @param \WP_User                 $user    The owner, signed in.
	 * @param list<Standing>           $watches Their watches.
	 * @param array{owed:array<string,list<Owed>>,signals:array<string,list<array<string,mixed>>>} $digest What the plan read for it.
	 * @param array<string,list<Owed>> $news    What this pass matched, by watch.
	 */
	private function digest( \WP_User $user, array $watches, array $digest, array $news ): void {
		$sections = [];
		$counts   = [ 0, 0 ];
		foreach ( $watches as $watch ) {
			$key     = $watch['watch_key'];
			$items   = [ ...( $digest['owed'][ $key ] ?? [] ), ...( $news[ $key ] ?? [] ) ];
			$signals = $digest['signals'][ $key ] ?? [];
			if ( $watch['digest'] && ( [] !== $items || [] !== $signals ) ) {
				$sections[] = self::section( $watch, $items, $signals );
				$counts[0] += \count( $items );
				$counts[1] += \count( $signals );
			}
		}
		/* translators: %d: how many signals. */
		$subject = \__( 'Intelligence digest', 'newspack-intelligence' ) . ': ' . self::matches( $counts[0] ) . ( 0 === $counts[1] ? '' : ', ' . \sprintf( \_n( '%d signal', '%d signals', $counts[1], 'newspack-intelligence' ), $counts[1] ) );
		if ( [] !== $sections && ! $this->send( $user, $subject, \implode( "\n", $sections ), 'digest' ) ) {
			return;
		}
		$this->delivered( 'digest', '', $user->ID, Core::$now );
	}

	/**
	 * Mark how far the watcher has gone for a watch, or an owner's digest.
	 *
	 * @param string $mode    `scan`, `instant` or `digest`.
	 * @param string $key     The watch key, '' for a digest.
	 * @param int    $owner   The owner.
	 * @param float  $through How far.
	 */
	private function delivered( string $mode, string $key, int $owner, float $through ): void {
		$this->emit( $this->writer, Intel_Event::machine_event( self::STAGE, 'watch_delivered', [ 'owner' => $owner, 'mode' => $mode, 'watch_key' => $key, 'through' => $through ] ) );
	}

	/**
	 * One watch's part of an email: its name and how many matches, each
	 * match, each signal with the items it cites, and the link to the watch.
	 *
	 * @param Standing                     $watch   The watch.
	 * @param list<Owed>                   $items   Its matches.
	 * @param list<array<string,mixed>>    $signals Its signals.
	 */
	private static function section( array $watch, array $items, array $signals ): string {
		$text = self::line( $watch['name'] ) . ( [] === $items ? '' : ': ' . self::matches( \count( $items ) ) ) . "\n";
		foreach ( \array_column( \array_slice( $items, 0, self::MAIL_ITEMS ), 'item' ) as $item ) {
			$text .= self::cite( $item['title'], $item['url'] ) . '  ' . self::line( $item['source'] ) . ', ' . \gmdate( 'Y-m-d', Intel_Graph_Row_Schema::item_time( $item['item_ts'], $item['retrieved_ts'] ) ) . "\n";
		}
		if ( \count( $items ) > self::MAIL_ITEMS ) {
			/* translators: %d: how many more matches the email leaves out. */
			$text .= \sprintf( \__( '- and %d more on the Intelligence page', 'newspack-intelligence' ), \count( $items ) - self::MAIL_ITEMS ) . "\n";
		}
		foreach ( $signals as $signal ) {
			/* translators: 1: what the signal counts, 2: the value counted, 3: its count, 4: the first day of its bucket, 5: the mean of the buckets before it. */
			$text .= \sprintf( \__( 'Signal: %1$s %2$s, %3$d from %4$s, against a mean of %5$.1f', 'newspack-intelligence' ), self::line( Core::as_string( $signal['by'] ) ), self::line( Core::as_string( $signal['value'] ) ), Core::as_int( $signal['count'] ), Core::as_string( $signal['bucket'] ), Core::as_float( $signal['baseline'] ) ) . "\n";
			foreach ( Core::arr( $signal['items'] ) as $cited ) {
				$cited = Core::arr( $cited );
				$text .= self::cite( Core::as_string( $cited['title'] ?? '' ), Core::as_string( $cited['url'] ?? '' ) );
			}
		}
		/* translators: %s: the watch's address on the Intelligence page. */
		return $text . \sprintf( \__( 'Open this watch: %s', 'newspack-intelligence' ), \admin_url( 'admin.php?page=' . INTELLIGENCE_MENU_SLUG . '&watch=' . \rawurlencode( $watch['watch_key'] ) ) ) . "\n";
	}

	/**
	 * A count of matches, in words.
	 *
	 * @param int $count How many.
	 */
	private static function matches( int $count ): string {
		/* translators: %d: how many new matches. */
		return \sprintf( \_n( '%d new match', '%d new matches', $count, 'newspack-intelligence' ), $count );
	}

	/**
	 * An item cited by its title, as one line of text, and its URL on the
	 * next when it is http or https.
	 *
	 * @param string $title The title.
	 * @param string $url   The URL.
	 */
	private static function cite( string $title, string $url ): string {
		return '- ' . self::line( $title ) . "\n" . ( 1 === \preg_match( '#^https?://[^\s\p{C}]+$#iu', $url ) ? "  {$url}\n" : '' );
	}

	/**
	 * Email the owner, at their own address alone, with the site's name
	 * leading the subject; a send that fails or an address that is not one
	 * is logged.
	 *
	 * @param \WP_User $user    The owner.
	 * @param string   $subject The subject, after the site's name.
	 * @param string   $message The plain-text body.
	 * @param string   $what    Which watch, or `digest`, for the log.
	 */
	private function send( \WP_User $user, string $subject, string $message, string $what ): bool {
		$to = \is_email( $user->user_email );
		if ( false === $to ) {
			$this->print_less_often( "a watch owner has no valid address; not emailing: {$user->user_login}" );
			return false;
		}
		$mail = self::$wp_mail ?? self::wp_mail( ... );
		$site = self::line( \html_entity_decode( \get_bloginfo( 'name' ), \ENT_QUOTES, 'UTF-8' ) );
		$sent = true === $mail( $to, "[{$site}] {$subject}", $message, self::HEADERS );
		if ( ! $sent ) {
			$this->print_less_often( "a watch email did not send; retrying next pass: {$user->user_login} {$what}" );
		}
		return $sent;
	}

	/**
	 * Text as one line: every run of whitespace or control, format or
	 * invalid characters one space, trimmed.
	 *
	 * @param string $text Any text.
	 */
	private static function line( string $text ): string {
		return \trim( (string) \preg_replace( '/[\s\p{C}]+/u', ' ', \mb_scrub( $text, 'UTF-8' ) ) );
	}

	/**
	 * Send one email through WordPress, what the mail seam stands for.
	 *
	 * @param string       $to      The address.
	 * @param string       $subject The subject.
	 * @param string       $message The plain-text body.
	 * @param list<string> $headers The headers.
	 */
	private static function wp_mail( string $to, string $subject, string $message, array $headers ): bool {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- at most one email a watch a pass and one digest an owner a day, each to its owner alone.
		return \wp_mail( $to, $subject, $message, $headers );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Runs every standing watch as its owner on the Router\'s tick, records each new match and emails it when asked, and sends each owner a daily digest.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The writer every watches-stream event is addressed to: intel:writer.' ],
			],
			'accepts_fill' => false,
			'has_target'   => false,
		];
	}
}
