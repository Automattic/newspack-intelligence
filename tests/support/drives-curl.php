<?php
/**
 * Drives_Curl: a source's real graph — a Router, an interpreter, the source's
 * Curl sibling, a writer its items reach and an asker its TICKs answer —
 * with only libcurl stood in for, through the substrate's dispatch and
 * result seams.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Vault;

trait Drives_Curl {

	/** The target a source's items go to. */
	public const WRITER = 'writer-6127';

	/** Who asks for a TICK, and hears its answer. */
	public const ASKER = 'asker-5513';

	/** The worker partition a source publishes its status from. */
	public const PARTITION = 6;

	/** The address every transfer connected to, a public one, unless a test names another. */
	public const CONNECTED = '93.184.215.14';

	private Capture_Sink_Node $writer;

	private Capture_Sink_Node $asker;

	/** @var list<string> Every url a dispatch was handed, in order. */
	private array $dispatched = [];

	/** @var array<string,array<int,mixed>> The opts each url's last dispatch was handed. */
	private array $opts = [];

	/** @var list<array<int,mixed>> The opts every dispatch was handed, in order. */
	private array $sent = [];

	/** @var array<string,\CurlHandle> The handle each url's last dispatch returned. */
	private array $handles = [];

	/** @var array<int,string> The url each handle fetches, by spl_object_id. */
	private array $url_of = [];

	/** @var list<\CurlHandle> Every handle a dispatch returned, in dispatch order. */
	private array $easy = [];

	/** @var array<string,array{0:int,1:string}> The status and body each url answers with. */
	private array $answers = [];

	/** @var array<int,array{0:int,1:string}> The status and body a handle answers with, by spl_object_id, over its url's. */
	private array $answer_of = [];

	/** @var array<string,string> The Location each url answers a 301 with. */
	private array $locations = [];

	/** @var array<string,true> Urls whose next dispatch throws. */
	private array $refusing = [];

	/** @var array<string,string> The address each url's transfer connected to, where not CONNECTED. */
	private array $connected = [];

	/**
	 * Stand in for libcurl, give the test a base of its own, where each
	 * source's Table keeps its file, and build the Router, the interpreter,
	 * the writer and the asker.
	 */
	private function install_curl(): void {
		$this->use_base_dir( $this->make_temp_dir( 'intel-sources-' ) );
		Event_Framework::reset();
		Event_Framework::$curl_dispatch = function ( array $opts ): \CurlHandle {
			$url                = (string) $opts[ \CURLOPT_URL ];
			$this->dispatched[] = $url;
			$this->opts[ $url ] = $opts;
			$this->sent[]       = $opts;
			if ( isset( $this->refusing[ $url ] ) ) {
				unset( $this->refusing[ $url ] );
				throw new \RuntimeException( "dispatch-down-5108 {$url}" );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$easy                                    = \curl_init();
			$this->handles[ $url ]                   = $easy;
			$this->easy[]                            = $easy;
			$this->url_of[ \spl_object_id( $easy ) ] = $url;
			return $easy;
		};
		Curl_Node::$curl_result = function ( \CurlHandle $easy ): array {
			$url = $this->url_of[ \spl_object_id( $easy ) ];
			$ip = $this->connected[ $url ] ?? self::CONNECTED;
			if ( isset( $this->locations[ $url ] ) ) {
				return [
					'code'     => 301,
					'body'     => '',
					'redirect' => $this->locations[ $url ],
					'ip'       => $ip,
				];
			}
			[ $code, $body ] = $this->answer_of[ \spl_object_id( $easy ) ] ?? $this->answers[ $url ];
			return [
				'code'     => $code,
				'body'     => $body,
				'redirect' => '',
				'ip'       => $ip,
			];
		};
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$this->writer = new Capture_Sink_Node();
		$this->writer->name( self::WRITER );
		$this->asker = new Capture_Sink_Node();
		$this->asker->name( self::ASKER );
	}

	/** Restore libcurl, and empty the Vault a test seeded. */
	private function uninstall_curl(): void {
		Event_Framework::$curl_dispatch = null;
		Curl_Node::$curl_result         = null;
		\delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
	}

	/**
	 * Add a Vault entry in $group on $origin, beside any already stored,
	 * lending each request there `<header>: [<scheme> ]<secret>`.
	 */
	private function lend( string $group, string $origin, string $header = 'X-Lent-Key', string $secret = 'lent-secret-3390', string $scheme = '' ): void {
		$seal                       = new \ReflectionMethod( Vault::class, 'encrypt' );
		$servers                    = \Newspack_Nodes\Core::arr( \get_option( Vault::OPTION_KEY, [] ) );
		$servers[ "{$group}-lent" ] = [
			'url'           => $origin,
			'group'         => $group,
			'auth_password' => $seal->invoke( null, $secret ),
			'auth_header'   => $header,
			'auth_scheme'   => $scheme,
		];
		\update_option( Vault::OPTION_KEY, $servers, false );
		Vault::get_instance()->reset_cache();
	}

	/** Run $work where the worker's partition is bound, as a topology load binds it. */
	private function bound( \Closure $work ): void {
		$bound = \Newspack_Nodes\Core::$var['partition'] ?? null;
		\Newspack_Nodes\Core::$var['partition'] = (string) self::PARTITION;
		try {
			$work();
		} finally {
			\Newspack_Nodes\Core::$var['partition'] = $bound;
			if ( null === $bound ) {
				unset( \Newspack_Nodes\Core::$var['partition'] );
			}
		}
	}

	/**
	 * The process after a recycle: the stopped source leaves the graph, and
	 * $successor takes its name and its arguments, and so its Table.
	 *
	 * @template T of Source_Node
	 * @param T $successor The source the next process builds.
	 * @return T
	 */
	private function respawn( Source_Node $stopped, Source_Node $successor ): Source_Node {
		$name = $stopped->name();
		$args = $stopped->arguments();
		$stopped->remove_node();
		return $this->wire( $successor, $name, $args );
	}

	/**
	 * A source as a topology builds it: named, its arguments given where the
	 * worker's partition is bound, so it builds its frontier Table there,
	 * sinking into the interpreter, its items addressed to the writer.
	 *
	 * @template T of Source_Node
	 * @param T            $node The source.
	 * @param list<string> $args The make_node arguments.
	 * @return T
	 */
	private function wire( Source_Node $node, string $name, array $args ): Source_Node {
		$node->name( $name );
		$this->bound( static fn () => $node->arguments( $args ) );
		$node->sink( \Newspack_Nodes\Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$node->target( self::WRITER );
		return $node;
	}

	/**
	 * Bind a worker partition and a shared cache, as a worker runs, so a
	 * source publishes its status.
	 */
	private function publishing(): InMemoryMemcached {
		$memd                                    = new InMemoryMemcached();
		\Newspack_Nodes\Core::$memd             = $memd;
		\Newspack_Nodes\Core::$var['partition'] = (string) self::PARTITION;
		return $memd;
	}

	/**
	 * The status a source published, its keys sorted.
	 *
	 * @return array<array-key,mixed>|null
	 */
	private function published( string $name ): ?array {
		$status = Source_Node::read_status( $name, self::PARTITION );
		if ( null !== $status ) {
			\ksort( $status );
		}
		return $status;
	}

	/**
	 * A status snapshot as a source writes it, every key it can carry named,
	 * those $fields leaves out null, its keys sorted.
	 *
	 * @param array<string,mixed> $fields The keys the state carries.
	 * @return array<string,mixed>
	 */
	private static function snapshot( array $fields ): array {
		$status = [ ...Source_Node::BLANK_STATUS, ...$fields ];
		\ksort( $status );
		return $status;
	}

	/** Ask the source for a request inside a drain, as the asker. */
	private function tick( Source_Node $node, string $verb = 'TICK' ): void {
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_REQUEST;
		$request[ Message::FROM ]  = self::ASKER;
		$request[ Message::ID ]    = '4:812:97';
		$request[ Message::KEY ]   = 'tick-key-31';
		$request[ Message::VALUE ] = $verb;
		$this->in_drain( static fn () => $node->fill( $request ) );
	}

	/** @return list<array<string,mixed>> What the asker heard. */
	private function answers(): array {
		return \array_column( $this->asker->captured, Message::VALUE );
	}

	/** @return array<string,array<string,mixed>> Each recorded item's event, by item_key. */
	private function recorded(): array {
		$out = [];
		foreach ( $this->writer->captured as $message ) {
			$out[ $message[ Message::VALUE ]['item_key'] ] = $message[ Message::VALUE ];
		}
		return $out;
	}

	/** @return list<string> Each recorded item's key, in order, repeats kept. */
	private function recorded_keys(): array {
		return \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->writer->captured );
	}

	/** Run $work once inside a running drain loop, where a fetch may start. */
	private function in_drain( \Closure $work ): void {
		$done = false;
		Event_Framework::instance()->drain(
			static function () use ( $work, &$done ): bool {
				if ( ! $done ) {
					$done = true;
					$work();
				}
				return false;
			}
		);
	}

	/**
	 * Complete one url's transfer inside a drain, answering a status and a
	 * body, after feeding its header callback the lines given, as libcurl does.
	 *
	 * @param list<string> $header_lines Response header lines, each with its CRLF.
	 */
	private function complete( string $url, string $body, int $code = 200, int $result = \CURLE_OK, array $header_lines = [] ): void {
		foreach ( $header_lines as $line ) {
			$this->opts[ $url ][ \CURLOPT_HEADERFUNCTION ]( $this->handles[ $url ], $line );
		}
		$this->answers[ $url ] = [ $code, $body ];
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ], $result ) );
	}

	/**
	 * Complete the transfer the $n-th dispatch started, counting from 0,
	 * inside a drain: what a url cannot name when every request shares one.
	 */
	private function complete_nth( int $n, string $body, int $code = 200, int $result = \CURLE_OK ): void {
		$easy                                       = $this->easy[ $n ];
		$this->answer_of[ \spl_object_id( $easy ) ] = [ $code, $body ];
		$this->in_drain( fn () => $this->complete_curl( $easy, $result ) );
	}

	/** Complete one url's transfer inside a drain as a 301 to $location. */
	private function redirect( string $url, string $location ): void {
		$this->locations[ $url ] = $location;
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ] ) );
	}

	/**
	 * Each value is refused by `add_<noun>`, naming it and what it is not,
	 * and the list stays empty.
	 *
	 * @param list<string> $values The values to refuse.
	 */
	private function assert_add_refuses( Source_Node $node, string $noun, string $property, array $values, string $kind ): void {
		foreach ( $values as $value ) {
			try {
				$node->interpreter()->dispatch( "add_{$noun}", [ $value ] );
				$this->fail( "add_{$noun} accepted {$value}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "add_{$noun}: '{$value}' is not {$kind}", $e->getMessage() );
			}
		}
		$this->assertSame( [], $this->read_private( $node, $property ) );
	}
}
