<?php
/**
 * Context_A8C_Token: the process's one hold on the ContextA8C relay's token,
 * shared by every ContextA8C source reading one token dir.
 *
 * The token lives here, keyed by the dir, and in no node's state, so no dump
 * of a node carries it. One refresh is in flight per dir at a time: the node
 * that claims it refreshes, and every other node finding the token due waits
 * for it to land. A claim re-reads the newest file under an exclusive lock
 * beside it first, so a token the host's relay refreshed in the meantime is
 * taken rather than refreshed again, and a refreshed token is written back
 * under the same lock, through a rename beside the file. A refresh token the
 * endpoint refused is never spent again: the dir waits for the relay to sign
 * in again, which writes a newer file.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

final class Context_A8C_Token {

	/** The token the file holds now is newer than the one held: use it. */
	public const FRESH = 'fresh';

	/** This caller refreshes; it sends `form()` and reports `landed()` or `failed()`. */
	public const REFRESH = 'refresh';

	/** Another node's refresh is in flight. */
	public const WAIT = 'wait';

	/** The endpoint refused this refresh token; the relay must sign in again. */
	public const REFUSED = 'refused';

	/** The token holds no refresh token, or the relay's file names no client. */
	public const UNREFRESHABLE = 'unrefreshable';

	/** The lock beside the token file would not open or hold. */
	public const UNLOCKED = 'unlocked';

	/** The fields a token endpoint's answer may replace in the file. */
	private const ANSWERED = [ 'access_token', 'refresh_token', 'expires_in', 'token_type', 'scope' ];

	/** @var array<string,array{path:string,token:array<array-key,mixed>}> The token held, and the file it came from, by dir. */
	private static array $held = [];

	/** @var array<string,true> The dirs whose refresh is in flight. */
	private static array $refreshing = [];

	/** @var array<string,true> The dirs whose token a server refused before its expiry. */
	private static array $stale = [];

	/** @var array<string,array{0:int,1:int}> Each dir's refused refresh: the token's `obtained_at` and the HTTP status. */
	private static array $refused = [];

	/**
	 * The token held for $dir, the newest file read in when none is held;
	 * null when no file reads as a JSON object carrying an access token.
	 *
	 * @return array{path:string,token:array<array-key,mixed>}|null
	 */
	public static function held( string $dir ): ?array {
		if ( ! isset( self::$held[ $dir ] ) ) {
			$newest = self::newest( $dir );
			if ( null === $newest ) {
				return null;
			}
			self::adopt( $dir, $newest );
		}
		return self::$held[ $dir ];
	}

	/**
	 * Whether the held token wants refreshing: a server refused it, or it
	 * expires within $margin seconds of $now.
	 */
	public static function due( string $dir, float $now, int $margin ): bool {
		$token   = self::$held[ $dir ]['token'] ?? [];
		$expires = self::obtained( $token ) / 1000 + Core::as_int( $token['expires_in'] ?? null );
		return isset( self::$stale[ $dir ] ) || $expires - $margin <= $now;
	}

	/**
	 * Claim the refresh of $dir's token, under the lock: FRESH when the file
	 * holds a newer token, now held; WAIT while another refresh is in flight;
	 * REFUSED when the endpoint refused this refresh token; UNREFRESHABLE with
	 * no refresh token or client; UNLOCKED when the lock would not hold; else
	 * REFRESH, and the caller owns the refresh until it lands or fails.
	 */
	public static function claim( string $dir ): string {
		if ( isset( self::$refreshing[ $dir ] ) ) {
			return self::WAIT;
		}
		$path = self::$held[ $dir ]['path'] ?? '';
		return self::locked(
			$path,
			static function () use ( $dir ): string {
				$newest = self::newest( $dir );
				$held   = self::$held[ $dir ]['token'] ?? [];
				if ( null !== $newest && self::obtained( $newest['token'] ) > self::obtained( $held ) ) {
					self::adopt( $dir, $newest );
					return self::FRESH;
				}
				if ( isset( self::$refused[ $dir ] ) && self::obtained( $held ) <= self::$refused[ $dir ][0] ) {
					return self::REFUSED;
				}
				if ( '' === Core::str( $held['refresh_token'] ?? null ) || '' === self::client_id( $dir ) ) {
					return self::UNREFRESHABLE;
				}
				self::$refreshing[ $dir ] = true;
				return self::REFRESH;
			}
		) ?? self::UNLOCKED;
	}

	/**
	 * Hold $newest for $dir. While a refusal stands, only a token obtained
	 * after the refused one clears it and the stale mark, so a file still
	 * holding an older token, its refresh token spent, never refreshes
	 * again; with none, a token obtained at another moment than the one held
	 * clears the stale mark.
	 *
	 * @param array{path:string,token:array<array-key,mixed>} $newest The token and its file.
	 */
	private static function adopt( string $dir, array $newest ): void {
		$at    = self::obtained( $newest['token'] );
		$fresh = isset( self::$refused[ $dir ] )
			? $at > self::$refused[ $dir ][0]
			: ! isset( self::$held[ $dir ] ) || $at !== self::obtained( self::$held[ $dir ]['token'] );
		if ( $fresh ) {
			unset( self::$stale[ $dir ], self::$refused[ $dir ] );
		}
		self::$held[ $dir ] = $newest;
	}

	/**
	 * The relay's token file obtained last under $dir, by its `obtained_at`,
	 * and what it holds; null when none reads as a JSON object carrying an
	 * access token.
	 *
	 * @return array{path:string,token:array<array-key,mixed>}|null
	 */
	private static function newest( string $dir ): ?array {
		$newest = null;
		foreach ( \glob( self::pattern( $dir ) ) ?: [] as $path ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local token file the operator's relay writes.
			$token = \json_decode( (string) @\file_get_contents( $path ), true );
			if ( ! \is_array( $token ) || '' === Core::str( $token['access_token'] ?? null ) ) {
				continue;
			}
			if ( null === $newest || self::obtained( $token ) > self::obtained( $newest['token'] ) ) {
				$newest = [
					'path'  => $path,
					'token' => $token,
				];
			}
		}
		return $newest;
	}

	/** The path pattern the relay's token files match under $dir. */
	public static function pattern( string $dir ): string {
		return "{$dir}/wordpress-remote-*/" . \md5( Context_A8C_Source_Node::MCP_URL ) . '_tokens.json';
	}

	/**
	 * The refresh's form body, built as it goes out: the held refresh token,
	 * the relay's client and the MCP url as the resource.
	 */
	public static function form( string $dir ): string {
		$form = [
			'grant_type'    => 'refresh_token',
			'refresh_token' => Core::str( ( self::$held[ $dir ]['token'] ?? [] )['refresh_token'] ?? null ),
			'client_id'     => self::client_id( $dir ),
			'resource'      => Context_A8C_Source_Node::MCP_URL,
		];
		return \http_build_query( $form );
	}

	/** The relay's OAuth client, from the client file beside the held token; '' for none. */
	private static function client_id( string $dir ): string {
		$path = \dirname( self::$held[ $dir ]['path'] ?? '' ) . '/' . \md5( Context_A8C_Source_Node::MCP_URL ) . '_client_info.json';
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local client file the operator's relay writes.
		$client = \json_decode( (string) @\file_get_contents( $path ), true );
		return \is_array( $client ) ? Core::as_string( $client['client_id'] ?? null ) : '';
	}

	/**
	 * Take the token endpoint's answer: an access token is merged over the
	 * held token, stamped obtained at $now, and written back under the lock
	 * through a rename beside the file. Answers whether a token landed and
	 * whether it was written; the refresh is over either way.
	 *
	 * @return array{0:bool,1:bool} Whether the answer held an access token, and whether it reached the file.
	 */
	public static function landed( string $dir, string $body, float $now ): array {
		unset( self::$refreshing[ $dir ] );
		$answer = \json_decode( $body, true );
		if ( ! \is_array( $answer ) || '' === Core::str( $answer['access_token'] ?? null ) || ! isset( self::$held[ $dir ] ) ) {
			return [ false, false ];
		}
		$token                = \array_merge( self::$held[ $dir ]['token'], \array_intersect_key( $answer, \array_flip( self::ANSWERED ) ) );
		$token['obtained_at'] = (int) ( $now * 1000 );
		$path                 = self::$held[ $dir ]['path'];
		self::$held[ $dir ]   = [
			'path'  => $path,
			'token' => $token,
		];
		unset( self::$stale[ $dir ] );
		return [ true, true === self::locked( $path, static fn (): bool => self::write( $path, $token ) ) ];
	}

	/**
	 * Write a token over its file: to a temp file beside it, private to its
	 * owner, renamed over it, so the relay never reads half a token.
	 *
	 * @param string                 $path  The token file.
	 * @param array<array-key,mixed> $token What it holds now.
	 */
	private static function write( string $path, array $token ): bool {
		$temp       = $path . '.' . \getmypid() . '.tmp';
		$text       = (string) \wp_json_encode( $token );
		$prev_umask = \umask( 0077 );
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- the relay's own token file; one atomic rename.
			$landed = \strlen( $text ) === @\file_put_contents( $temp, $text ) && @\rename( $temp, $path );
		} finally {
			\umask( $prev_umask );
		}
		if ( ! $landed ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			@\unlink( $temp );
		}
		return $landed;
	}

	/**
	 * What $work answers, run holding an exclusive lock on the file beside
	 * the token file; null when the lock would not open or hold.
	 *
	 * @template T
	 * @param \Closure(): T $work What runs under the lock.
	 * @return T|null
	 */
	private static function locked( string $path, \Closure $work ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a lock file beside the relay's token.
		$lock = '' === $path ? false : @\fopen( "{$path}.lock", 'c' );
		if ( false === $lock ) {
			return null;
		}
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock -- the relay's own token dir, a local workstation's.
			return \flock( $lock, \LOCK_EX ) ? $work() : null;
		} finally {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock
			\flock( $lock, \LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			\fclose( $lock );
		}
	}

	/**
	 * End a refresh that failed: one refused with 400 or 401 marks this
	 * refresh token refused and drops the held token, so the next pass reads
	 * the file again; any other failure lets the next pass try again.
	 *
	 * @param int $status The HTTP status, 0 for a transport failure.
	 */
	public static function failed( string $dir, int $status ): void {
		unset( self::$refreshing[ $dir ] );
		if ( 400 === $status || 401 === $status ) {
			self::$refused[ $dir ] = [ self::obtained( self::$held[ $dir ]['token'] ?? [] ), $status ];
			unset( self::$held[ $dir ] );
		}
	}

	/**
	 * A token's `obtained_at`, in milliseconds.
	 *
	 * @param array<array-key,mixed> $token The token.
	 */
	private static function obtained( array $token ): int {
		return Core::as_int( $token['obtained_at'] ?? null );
	}

	/** The access token held for $dir; '' for none. */
	public static function access( string $dir ): string {
		return Core::str( ( self::$held[ $dir ]['token'] ?? [] )['access_token'] ?? null );
	}

	/** The HTTP status the endpoint refused $dir's refresh token with, 0 for none. */
	public static function refused_status( string $dir ): int {
		return self::$refused[ $dir ][1] ?? 0;
	}

	/** Mark $dir's token refused by a server, so the next pass refreshes it. */
	public static function stale( string $dir ): void {
		self::$stale[ $dir ] = true;
	}
}
