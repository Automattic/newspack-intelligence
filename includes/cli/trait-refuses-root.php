<?php
/**
 * Refuses_Root: every `wp newspack-intelligence` verb refuses to run as
 * root, as the `wp nodes` verbs do.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

trait Refuses_Root {

	/**
	 * Refuse a root run, through the substrate's effective-uid seam: a root
	 * write leaves the ontology segment, a worker's IPC file or a log segment
	 * a stderr line reaches root-owned, which the workers, running as the web
	 * user, then cannot append to.
	 *
	 * @param string $command The command and verb, for the message.
	 * @return bool Whether it refused.
	 */
	private static function refused_root( string $command ): bool {
		if ( 0 !== CLI::uid() ) {
			return false;
		}
		\WP_CLI::error( "wp newspack-intelligence {$command} must run as the same user as the workers, not root." );
		return true;
	}
}
