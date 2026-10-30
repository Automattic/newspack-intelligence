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
use Newspack_Nodes\Vault;

trait Drives_Curl {

	/** The target a source's items go to. */
	public const WRITER = 'writer-6127';

	/** Who asks for a TICK, and hears its answer. */
	public const ASKER = 'asker-5513';

	private Capture_Sink_Node $writer;

	private Capture_Sink_Node $asker;

	/** @var list<string> Every url a dispatch was handed, in order. */
	private array $dispatched = [];

	/** @var array<string,array<int,mixed>> The opts each url's last dispatch was handed. */
	private array $opts = [];

	/** @var array<string,\CurlHandle> The handle each url's last dispatch returned. */
	private array $handles = [];

	/** @var array<int,string> The url each handle fetches, by spl_object_id. */
	private array $url_of = [];

	/** @var array<string,array{0:int,1:string}> The status and body each url answers with. */
	private array $answers = [];

	/** @var array<string,string> The Location each url answers a 301 with. */
	private array $locations = [];

	/** @var array<string,true> Urls whose next dispatch throws. */
	private array $refusing = [];

	/** Stand in for libcurl, and build the Router, the interpreter, the writer and the asker. */
	private function install_curl(): void {
		Event_Framework::reset();
		Event_Framework::$curl_dispatch = function ( array $opts ): \CurlHandle {
			$url                = (string) $opts[ \CURLOPT_URL ];
			$this->dispatched[] = $url;
			$this->opts[ $url ] = $opts;
			if ( isset( $this->refusing[ $url ] ) ) {
				unset( $this->refusing[ $url ] );
				throw new \RuntimeException( "dispatch-down-5108 {$url}" );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$easy                                    = \curl_init();
			$this->handles[ $url ]                   = $easy;
			$this->url_of[ \spl_object_id( $easy ) ] = $url;
			return $easy;
		};
		Curl_Node::$curl_result = function ( \CurlHandle $easy ): array {
			$url = $this->url_of[ \spl_object_id( $easy ) ];
			if ( isset( $this->locations[ $url ] ) ) {
				return [
					'code'     => 301,
					'body'     => '',
					'redirect' => $this->locations[ $url ],
				];
			}
			[ $code, $body ] = $this->answers[ $url ];
			return [
				'code'     => $code,
				'body'     => $body,
				'redirect' => '',
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
	 * A source as a topology builds it: named, its arguments given, sinking
	 * into the interpreter, its items addressed to the writer.
	 *
	 * @template T of Source_Node
	 * @param T            $node The source.
	 * @param list<string> $args The make_node arguments.
	 * @return T
	 */
	private function wire( Source_Node $node, string $name, array $args ): Source_Node {
		$node->name( $name );
		$node->arguments( $args );
		$node->sink( \Newspack_Nodes\Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$node->target( self::WRITER );
		return $node;
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

	/** Complete one url's transfer inside a drain, answering a status and a body. */
	private function complete( string $url, string $body, int $code = 200, int $result = \CURLE_OK ): void {
		$this->answers[ $url ] = [ $code, $body ];
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ], $result ) );
	}

	/** Complete one url's transfer inside a drain as a 301 to $location. */
	private function redirect( string $url, string $location ): void {
		$this->locations[ $url ] = $location;
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ] ) );
	}
}
