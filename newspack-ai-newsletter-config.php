<?php
/**
 * Newspack AI Newsletter (application) configuration.
 *
 * App-level overrides layered onto the newspack-nodes substrate defaults. The
 * `<config:logs_dir>` / `<config:offsets_dir>` topology tokens derive from
 * `base_directory`; the partition retention keys feed the `scored` partition.
 *
 * @package Newspack_AI_Newsletter
 */

\defined( 'ABSPATH' ) || exit;

return [
	// Per-application data root; substrate derives logs/ and offsets/ under it.
	'base_directory' => '/tmp/newspack-ai-newsletter',

	// scored retention: count-prune past max_segments; age rule off.
	'num_partitions' => 1,
	'segment_size'   => 64 * 1024 * 1024,
	'min_segments'   => 2,
	'max_segments'   => 2,
	'min_lifetime'   => 86400,
	'max_lifetime'   => 0,
];
