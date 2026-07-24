<?php
/**
 * LLM_Config: shared per-node LLM configuration verbs + client factory.
 *
 * Summarizer_Node and Digest_Builder_Node both need the same api_url/vault_id/
 * model/feature/profiles quintet, configured via per-node topology verbs. This
 * trait carries that state, the five `set_*` / `add_profile` verbs (declared for
 * node_schema()['commands'] via llm_config_commands()), make_llm_client() (builds
 * the proxy client from the verb-configured state), and relevance_profile()
 * (joins the configured profile lines for prompt assembly). Uses Vault_Secret for
 * the same '' on blank/unknown/Vault-absent resolution.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

trait LLM_Config {
	use Vault_Secret;

	private const DEFAULT_API_URL = 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1';
	private const DEFAULT_MODEL   = 'gpt-oss-120b';
	private const DEFAULT_FEATURE = 'newspack-intelligence';

	/** @var string AI API Proxy base URL, set via set_api_url. */
	protected string $api_url = self::DEFAULT_API_URL;

	/** @var string Vault entry ID registered via set_vault_id; resolved to the raw token at use time. */
	protected string $vault_id = '';

	/** @var string LLM model id, set via set_model. */
	protected string $model = self::DEFAULT_MODEL;

	/** @var string X-WPCOM-AI-Feature value, set via set_feature. */
	protected string $feature = self::DEFAULT_FEATURE;

	/** @var array<int,string> Relevance-profile lines registered via add_profile, in call order. */
	protected array $profiles = [];

	/**
	 * Build the proxy client from this node's own verb-configured state; null
	 * when there's no api_url or no resolvable token (callers fall back to
	 * heuristics).
	 */
	protected function make_llm_client(): ?LLM_Client {
		if ( '' === $this->api_url ) {
			return null;
		}
		$token = $this->resolve_vault_secret( $this->vault_id );
		if ( '' === $token ) {
			return null;
		}
		return new Proxy_LLM_Client( $this->api_url, $token, $this->model, $this->feature );
	}

	/** The relevance-profile lines, joined for prompt assembly. */
	protected function relevance_profile(): string {
		return \implode( "\n", $this->profiles );
	}

	/**
	 * Verb-set keys. An explicit set dumps even at the default value, so a
	 * pinned choice survives a future default bump on reload.
	 *
	 * @var array<string, true>
	 */
	private array $llm_set = [];

	/** `set_api_url` verb handler — last-write-wins. */
	public function set_api_url( string $args ): string {
		$this->api_url                = \trim( $args );
		$this->llm_set['set_api_url'] = true;
		return 'ok';
	}

	/**
	 * `set_api_url` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key, mixed> $args        The AI API Proxy base URL.
	 * @return string Result line.
	 */
	public static function cmd_set_api_url( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_api_url( Core::as_string( $args[0] ?? '' ) );
	}

	/** `set_vault_id` verb handler — last-write-wins. */
	public function set_vault_id( string $args ): string {
		$this->vault_id = \trim( $args );
		return 'ok';
	}

	/**
	 * `set_vault_id` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key, mixed> $args        The Vault entry ID.
	 * @return string Result line.
	 */
	public static function cmd_set_vault_id( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_vault_id( Core::as_string( $args[0] ?? '' ) );
	}

	/** `set_model` verb handler — last-write-wins. */
	public function set_model( string $args ): string {
		$this->model                = \trim( $args );
		$this->llm_set['set_model'] = true;
		return 'ok';
	}

	/**
	 * `set_model` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key, mixed> $args        The LLM model id.
	 * @return string Result line.
	 */
	public static function cmd_set_model( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_model( Core::as_string( $args[0] ?? '' ) );
	}

	/** `set_feature` verb handler — last-write-wins. */
	public function set_feature( string $args ): string {
		$this->feature                = \trim( $args );
		$this->llm_set['set_feature'] = true;
		return 'ok';
	}

	/**
	 * `set_feature` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key, mixed> $args        The X-WPCOM-AI-Feature value.
	 * @return string Result line.
	 */
	public static function cmd_set_feature( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_feature( Core::as_string( $args[0] ?? '' ) );
	}

	/**
	 * `add_profile` verb handler — appends one relevance-profile line.
	 *
	 * @param string $args The profile line text.
	 * @return string Result line.
	 */
	public function add_profile( string $args ): string {
		$profile = \trim( $args );
		if ( '' === $profile ) {
			return 'error: add_profile requires <text>';
		}
		$this->profiles[] = $profile;
		return 'ok';
	}

	/**
	 * `add_profile` verb dispatch — resolves the patron node and delegates.
	 * All positional tokens join into one line, so an unquoted multi-word
	 * TSL statement reads naturally (like `echo`).
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key, mixed> $args        The profile line tokens.
	 * @return string Result line.
	 */
	public static function cmd_add_profile( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		$tokens = \array_map( static fn ( $a ) => Core::as_string( $a ), $args );
		return $patron->add_profile( \implode( ' ', $tokens ) );
	}

	/**
	 * Emit the base config plus round-trippable `cmd {name}:config …` lines for
	 * every explicitly-set or non-default verb (a pinned value survives a
	 * future default bump). Args go through serialize_args (the serialization
	 * anchor) so a multi-word profile re-tokenizes as ONE argument.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( isset( $this->llm_set['set_api_url'] ) || ( '' !== $this->api_url && self::DEFAULT_API_URL !== $this->api_url ) ) {
			$out .= "cmd {$this->name}:config set_api_url " . self::serialize_args( [ $this->api_url ] ) . "\n";
		}
		if ( '' !== $this->vault_id ) {
			$out .= "cmd {$this->name}:config set_vault_id " . self::serialize_args( [ $this->vault_id ] ) . "\n";
		}
		if ( isset( $this->llm_set['set_model'] ) || self::DEFAULT_MODEL !== $this->model ) {
			$out .= "cmd {$this->name}:config set_model " . self::serialize_args( [ $this->model ] ) . "\n";
		}
		if ( isset( $this->llm_set['set_feature'] ) || self::DEFAULT_FEATURE !== $this->feature ) {
			$out .= "cmd {$this->name}:config set_feature " . self::serialize_args( [ $this->feature ] ) . "\n";
		}
		foreach ( $this->profiles as $profile ) {
			$out .= "cmd {$this->name}:config add_profile " . self::serialize_args( [ $profile ] ) . "\n";
		}
		return $out;
	}

	/**
	 * node_schema()['commands'] entries for the five LLM-config verbs. A using
	 * class merges this into its own commands list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function llm_config_commands(): array {
		return [
			[
				'name'        => 'set_api_url',
				'description' => 'Set the AI API Proxy base URL: <url>.',
				'args'        => [
					[ 'name' => 'url', 'type' => 'string', 'required' => true ],
				],
				'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_set_api_url( $interpreter, $args ),
			],
			[
				'name'        => 'set_vault_id',
				'description' => 'Set the Vault entry ID to resolve the AI API Proxy token from: <vault_id>.',
				'args'        => [
					[ 'name' => 'vault_id', 'type' => 'vault_id', 'required' => true ],
				],
				'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_set_vault_id( $interpreter, $args ),
			],
			[
				'name'        => 'set_model',
				'description' => 'Set the LLM model id: <model>.',
				'args'        => [
					[ 'name' => 'model', 'type' => 'string', 'required' => true ],
				],
				'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_set_model( $interpreter, $args ),
			],
			[
				'name'        => 'set_feature',
				'description' => 'Set the X-WPCOM-AI-Feature value: <feature>.',
				'args'        => [
					[ 'name' => 'feature', 'type' => 'string', 'required' => true ],
				],
				'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_set_feature( $interpreter, $args ),
			],
			[
				'name'        => 'add_profile',
				'description' => 'Add a relevance-profile line used to score/compose items: <text>.',
				'args'        => [
					[ 'name' => 'text', 'type' => 'string', 'required' => true ],
				],
				'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_add_profile( $interpreter, $args ),
				'multiple'    => true,
			],
		];
	}
}
