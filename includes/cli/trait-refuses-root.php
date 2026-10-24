<?php
/**
 * Refuses_Root: a `wp newspack-intelligence` verb that writes a stream the
 * workers append to refuses to run as root.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

trait Refuses_Root {

	/**
	 * Refuse a root run, through the substrate's effective-uid seam: a root
	 * write leaves the ontology segment or a worker's IPC file root-owned,
	 * which the workers, running as the web user, then cannot append to.
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
