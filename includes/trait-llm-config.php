<?php
/**
 * LLM_Config: shared per-node LLM configuration verbs + client factory.
 *
 * Every LLM-calling node needs the same api_url/vault_id/model/feature/
 * profiles quintet, configured via per-node topology verbs. This
 * trait carries that state, the four `set_*` verbs and the `add_profile` /
 * `remove_profile` pair (declared for node_schema()['commands'] via
 * llm_config_commands(), the pair through `Schema_Reflection::list_verbs()`, so
 * a using class also uses Schema_Reflection), make_llm_client() (answers the
 * `$llm_factory` seam first, else builds the proxy client from the
 * verb-configured state), and relevance_profile() (joins
 * the configured profile lines for prompt assembly). Uses Vault_Secret for
 * the `set_vault_id` verb and the token it resolves.
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

	/**
	 * LLM-client seam replacing the proxy client `make_llm_client()` builds;
	 * each using class holds its own. Tests reassign it to a canned client or
	 * none, and reset it to null in tearDown.
	 * Signature: `function (): ?LLM_Client`.
	 *
	 * @var (\Closure(): ?LLM_Client)|null
	 */
	public static ?\Closure $llm_factory = null;

	/** @var string AI API Proxy base URL, set via set_api_url. */
	protected string $api_url = self::DEFAULT_API_URL;

	/** @var string LLM model id, set via set_model. */
	protected string $model = self::DEFAULT_MODEL;

	/** @var string X-WPCOM-AI-Feature value, set via set_feature. */
	protected string $feature = self::DEFAULT_FEATURE;

	/** @var list<string> Relevance-profile lines add_profile registered and remove_profile left, in call order. */
	protected array $profiles = [];

	/**
	 * The `$llm_factory` seam's client when one is set; otherwise the proxy
	 * client from this node's own verb-configured state, or null when there's
	 * no api_url or no resolvable token (callers fall back to heuristics).
	 */
	protected function make_llm_client(): ?LLM_Client {
		if ( null !== self::$llm_factory ) {
			return ( self::$llm_factory )();
		}
		if ( '' === $this->api_url ) {
			return null;
		}
		$token = $this->vault_secret();
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
	 * @var array<string,true>
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
	 * @param array<array-key,mixed> $args        Bound args; `url` is the AI API Proxy base URL.
	 * @return string Result line.
	 */
	public static function cmd_set_api_url( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_api_url( Core::as_string( $args['url'] ) );
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
	 * @param array<array-key,mixed> $args        Bound args; `model` is the LLM model id.
	 * @return string Result line.
	 */
	public static function cmd_set_model( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_model( Core::as_string( $args['model'] ) );
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
	 * @param array<array-key,mixed> $args        Bound args; `feature` is the X-WPCOM-AI-Feature value.
	 * @return string Result line.
	 */
	public static function cmd_set_feature( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_feature( Core::as_string( $args['feature'] ) );
	}

	/**
	 * Emit the base config plus round-trippable `command_node {name}:config …` lines for
	 * every explicitly-set or non-default verb (a pinned value survives a
	 * future default bump), the `set_vault_id` line through `dump_setters()`,
	 * then one `add_profile` line per profile through
	 * `dump_lists()`. Args go through serialize_args (the serialization anchor)
	 * so a multi-word profile re-tokenizes as ONE argument.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( isset( $this->llm_set['set_api_url'] ) || ( '' !== $this->api_url && self::DEFAULT_API_URL !== $this->api_url ) ) {
			$out .= $this->config_line( 'set_api_url', $this->api_url );
		}
		if ( isset( $this->llm_set['set_model'] ) || self::DEFAULT_MODEL !== $this->model ) {
			$out .= $this->config_line( 'set_model', $this->model );
		}
		if ( isset( $this->llm_set['set_feature'] ) || self::DEFAULT_FEATURE !== $this->feature ) {
			$out .= $this->config_line( 'set_feature', $this->feature );
		}
		return $out . $this->dump_setters() . $this->dump_lists();
	}

	/**
	 * node_schema()['commands'] entries for the six LLM-config verbs. A using
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
			self::vault_id_verb( 'the AI API Proxy token' ),
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
			...self::list_verbs( 'profile', 'profiles', [ 'name' => 'text', 'type' => 'string', 'required' => true, 'variadic' => true ], 'Add a relevance-profile line used to score/compose items: <text>.' ),
		];
	}
}
