<?php
/**
 * Ontology_Seed: the entities the graph starts from — the topic vocabulary
 * extraction chooses from, the partners, funders and industry bodies the
 * team follows, and Newspack's own products — and a lens per audience. One
 * table row per entity or lens, so each key is written once. A run writes
 * only the keys the projection does not settle, so an entity a curator has
 * since accepted, rejected, merged or renamed is never reset to what the
 * seed says, while a machine's pending proposal of a seed key is replaced by
 * the seed's creation; a lens any event has saved or removed is left alone.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Table_Unavailable;

\defined( 'ABSPATH' ) || exit;

final class Ontology_Seed {

	/**
	 * Type => slug => [ name, kind => values, roles ]. A kind from
	 * NAME_KINDS adds a name, any other an identifier.
	 */
	private const ENTITIES = [
		'topic'        => [
			'ai'                         => [ 'Artificial intelligence', [ 'acronym' => [ 'AI' ], 'alias' => [ 'Generative AI', 'Machine learning' ] ], [] ],
			'audience-growth'            => [ 'Audience growth', [ 'alias' => [ 'Audience development', 'Reader growth' ] ], [] ],
			'advertising'                => [ 'Advertising', [ 'alias' => [ 'Ad revenue', 'Programmatic advertising' ] ], [] ],
			'awards'                     => [ 'Awards', [ 'alias' => [ 'Journalism prizes', 'Honors' ] ], [] ],
			'community-engagement'       => [ 'Community engagement', [ 'alias' => [ 'Engaged journalism', 'Reader engagement' ] ], [] ],
			'data-journalism'            => [ 'Data journalism', [ 'alias' => [ 'Data reporting', 'Data visualization' ] ], [] ],
			'donations'                  => [ 'Donations', [ 'alias' => [ 'Reader donations', 'Fundraising campaigns' ] ], [] ],
			'elections'                  => [ 'Elections', [ 'alias' => [ 'Election coverage', 'Voter guides' ] ], [] ],
			'events'                     => [ 'Events', [ 'alias' => [ 'Live events', 'Newsroom events' ] ], [] ],
			'grants-and-philanthropy'    => [ 'Grants and philanthropy', [ 'alias' => [ 'Grants', 'Philanthropy' ] ], [] ],
			'investigative-journalism'   => [ 'Investigative journalism', [ 'alias' => [ 'Investigations', 'Accountability reporting' ] ], [] ],
			'layoffs'                    => [ 'Layoffs', [ 'alias' => [ 'Job cuts', 'Newsroom cuts' ] ], [] ],
			'local-news'                 => [ 'Local news', [ 'alias' => [ 'Community news', 'Local journalism' ] ], [] ],
			'membership'                 => [ 'Membership', [ 'alias' => [ 'Member programs', 'Memberships' ] ], [] ],
			'mergers-and-acquisitions'   => [ 'Mergers and acquisitions', [ 'alias' => [ 'Mergers', 'Acquisitions' ] ], [] ],
			'misinformation'             => [ 'Misinformation', [ 'alias' => [ 'Disinformation', 'Fact-checking' ] ], [] ],
			'mobile-apps'                => [ 'Mobile apps', [ 'alias' => [ 'News apps', 'Mobile app' ] ], [] ],
			'newsletters'                => [ 'Newsletters', [ 'alias' => [ 'Email newsletters', 'Newsletter' ] ], [] ],
			'nonprofit-news'             => [ 'Nonprofit news', [ 'alias' => [ 'Nonprofit newsrooms', 'Nonprofit journalism' ] ], [] ],
			'paywalls-and-subscriptions' => [ 'Paywalls and subscriptions', [ 'alias' => [ 'Paywalls', 'Subscriptions', 'Digital subscriptions' ] ], [] ],
			'podcasts-and-audio'         => [ 'Podcasts and audio', [ 'alias' => [ 'Podcasts', 'Audio journalism' ] ], [] ],
			'press-freedom'              => [ 'Press freedom', [ 'alias' => [ 'Freedom of the press', 'Journalist safety' ] ], [] ],
			'privacy-and-regulation'     => [ 'Privacy and regulation', [ 'alias' => [ 'Data privacy', 'Platform regulation' ] ], [] ],
			'product-and-technology'     => [ 'Product and technology', [ 'alias' => [ 'News product', 'Newsroom technology' ] ], [] ],
			'search-and-seo'             => [ 'Search and SEO', [ 'acronym' => [ 'SEO' ], 'alias' => [ 'Search engine optimization', 'Search traffic' ] ], [] ],
			'social-media'               => [ 'Social media', [ 'alias' => [ 'Social platforms', 'Social distribution' ] ], [] ],
			'video'                      => [ 'Video', [ 'alias' => [ 'Video journalism', 'Short-form video' ] ], [] ],
			'accessibility'              => [ 'Accessibility', [ 'alias' => [ 'Web accessibility', 'Inclusive design' ] ], [] ],
			'diversity-and-equity'       => [ 'Diversity and equity', [ 'acronym' => [ 'DEI' ], 'alias' => [ 'Newsroom diversity' ] ], [] ],
			'reader-revenue'             => [ 'Reader revenue', [ 'alias' => [ 'Audience revenue', 'Reader-supported journalism' ] ], [] ],
		],
		'organization' => [
			'nieman-lab'                   => [ 'Nieman Lab', [ 'alias' => [ 'Nieman Journalism Lab' ], 'domain' => [ 'niemanlab.org' ] ], [ 'industry_body' ] ],
			'knight-foundation'            => [ 'Knight Foundation', [ 'alias' => [ 'John S. and James L. Knight Foundation' ], 'domain' => [ 'knightfoundation.org' ] ], [ 'funder' ] ],
			'lenfest-institute'            => [ 'Lenfest Institute for Journalism', [ 'alias' => [ 'Lenfest Institute' ], 'domain' => [ 'lenfestinstitute.org' ] ], [ 'funder' ] ],
			'institute-for-nonprofit-news' => [ 'Institute for Nonprofit News', [ 'acronym' => [ 'INN' ], 'domain' => [ 'inn.org' ] ], [ 'industry_body' ] ],
			'lion-publishers'              => [ 'LION Publishers', [ 'alias' => [ 'Local Independent Online News Publishers' ], 'acronym' => [ 'LION' ], 'domain' => [ 'lionpublishers.com' ] ], [ 'industry_body' ] ],
			'press-forward'                => [ 'Press Forward', [ 'domain' => [ 'pressforward.news' ] ], [ 'funder' ] ],
			'everlit'                      => [ 'Everlit', [ 'domain' => [ 'everlit.audio' ] ], [ 'partner' ] ],
			'pugpig'                       => [ 'Pugpig', [ 'domain' => [ 'pugpig.com' ] ], [ 'partner' ] ],
			'bluelena'                     => [ 'BlueLena', [ 'domain' => [ 'bluelena.io' ] ], [ 'partner' ] ],
			'blue-engine-collaborative'    => [ 'Blue Engine Collaborative', [ 'alias' => [ 'Blue Engine' ], 'domain' => [ 'blueenginecollaborative.org' ] ], [ 'partner' ] ],
			'local-media-association'      => [ 'Local Media Association', [ 'acronym' => [ 'LMA' ], 'domain' => [ 'localmedia.org' ] ], [ 'partner' ] ],
			'news-revenue-hub'             => [ 'News Revenue Hub', [ 'domain' => [ 'fundjournalism.org' ] ], [ 'partner' ] ],
			'parse-ly'                     => [ 'Parse.ly', [ 'alias' => [ 'Parsely' ], 'domain' => [ 'parse.ly' ] ], [ 'partner' ] ],
			'news-product-alliance'        => [ 'News Product Alliance', [ 'acronym' => [ 'NPA' ], 'domain' => [ 'newsproduct.org' ] ], [ 'industry_body' ] ],
			'opennews'                     => [ 'OpenNews', [ 'alias' => [ 'Open News' ], 'domain' => [ 'opennews.org' ] ], [ 'industry_body' ] ],
			'association-of-alternative-newsmedia' => [ 'Association of Alternative Newsmedia', [ 'acronym' => [ 'AAN' ], 'domain' => [ 'aan.org' ] ], [ 'industry_body' ] ],
			'national-newspaper-association' => [ 'National Newspaper Association', [ 'acronym' => [ 'NNA' ], 'domain' => [ 'nnaweb.org' ] ], [ 'industry_body' ] ],
			'americas-newspapers'          => [ "America's Newspapers", [ 'domain' => [ 'newspapers.org' ] ], [ 'industry_body' ] ],
			'online-news-association'      => [ 'Online News Association', [ 'acronym' => [ 'ONA' ], 'domain' => [ 'journalists.org' ] ], [ 'industry_body' ] ],
			'media-forward-fund'           => [ 'Media Forward Fund', [ 'domain' => [ 'mediaforwardfund.org' ] ], [ 'funder' ] ],
			'google-news-initiative'       => [ 'Google News Initiative', [ 'acronym' => [ 'GNI' ], 'domain' => [ 'newsinitiative.withgoogle.com' ] ], [ 'funder' ] ],
			'television-academy'           => [ 'Television Academy', [ 'alias' => [ 'Emmy Awards', 'Academy of Television Arts & Sciences' ], 'domain' => [ 'emmys.com', 'televisionacademy.com' ] ], [ 'industry_body' ] ],
			'pulitzer-prizes'              => [ 'Pulitzer Prizes', [ 'alias' => [ 'Pulitzer Prize' ], 'domain' => [ 'pulitzer.org' ] ], [ 'industry_body' ] ],
			'american-society-of-magazine-editors' => [ 'American Society of Magazine Editors', [ 'acronym' => [ 'ASME' ], 'domain' => [ 'asme1.org' ] ], [ 'industry_body' ] ],
			'investigative-reporters-and-editors' => [ 'Investigative Reporters and Editors', [ 'acronym' => [ 'IRE' ], 'domain' => [ 'ire.org' ] ], [ 'industry_body' ] ],
			'reporters-committee'          => [ 'Reporters Committee for Freedom of the Press', [ 'alias' => [ 'Reporters Committee' ], 'acronym' => [ 'RCFP' ], 'domain' => [ 'rcfp.org' ] ], [ 'industry_body' ] ],
			'nobel-prize'                  => [ 'Nobel Prize', [ 'alias' => [ 'Nobel Prizes' ], 'domain' => [ 'nobelprize.org' ] ], [ 'industry_body' ] ],
		],
		'product'      => [
			'newspack'                => [ 'Newspack', [ 'linear_team' => [ 'NEWS' ] ], [ 'newspack_product' ] ],
			'newspack-plugin'         => [ 'Newspack Plugin', [ 'github_repo' => [ 'automattic/newspack-plugin' ] ], [ 'newspack_product' ] ],
			'newspack-ads'            => [ 'Newspack Ads', [ 'github_repo' => [ 'automattic/newspack-ads' ] ], [ 'newspack_product' ] ],
			'newspack-activecampaign' => [ 'Newspack ActiveCampaign', [ 'github_repo' => [ 'automattic/newspack-activecampaign' ] ], [ 'newspack_product' ] ],
			'newspack-manager'        => [ 'Newspack Manager', [ 'github_repo' => [ 'automattic/newspack-manager' ] ], [ 'newspack_product' ] ],
			'newspack-network'        => [ 'Newspack Network', [ 'github_repo' => [ 'automattic/newspack-network' ] ], [ 'newspack_product' ] ],
		],
	];

	/**
	 * Lens key => [ name, description, the filters it sets over
	 * `Graph_Query::unfiltered()` ].
	 */
	private const LENSES = [
		'leadership' => [
			'Leadership',
			'Where clients and the industry are moving.',
			[
				'roles'  => [ 'client', 'former_client', 'prospect', 'competitor', 'partner', 'funder' ],
				'kinds'  => [ 'launch', 'funding', 'partnership', 'award', 'churn_signal', 'research' ],
				'days'   => 30,
				'by'     => 'topic',
				'bucket' => 'week',
			],
		],
		'support'    => [
			'Support',
			'What clients complain about and ask for.',
			[
				'roles'  => [ 'client' ],
				'kinds'  => [ 'complaint', 'incident', 'feature_request', 'churn_signal', 'praise' ],
				'days'   => 14,
				'by'     => 'kind',
				'bucket' => 'day',
			],
		],
		'developers' => [
			'Developers',
			'What shipped and what broke.',
			[
				'sources' => [ 'github', 'linear' ],
				'kinds'   => [ 'release', 'incident', 'feature_request' ],
				'days'    => 30,
				'by'      => 'kind',
				'bucket'  => 'week',
			],
		],
	];

	/**
	 * The seed as ontology events, under the seed's own machine actor, for
	 * each entity `$present` does not name: its creation, then its names and
	 * identifiers, then its roles, each role asserted from 0, before any
	 * record; then each lens `$present` does not name.
	 *
	 * @param list<string> $present The keys to leave alone, as present() answers.
	 * @return list<array<string,mixed>>
	 */
	public static function events( array $present ): array {
		$skip   = \array_flip( $present );
		$events = [];
		foreach ( self::ENTITIES as $type => $entities ) {
			foreach ( $entities as $slug => [ $name, $facts, $roles ] ) {
				$key = Intel_Event::entity_key( $type, $slug );
				if ( isset( $skip[ $key ] ) ) {
					continue;
				}
				$events[] = self::mint( 'entity_created', [ 'entity_key' => $key, 'type' => $type, 'name' => $name ] );
				foreach ( $facts as $kind => $values ) {
					foreach ( $values as $value ) {
						$events[] = self::mint( \in_array( $kind, Intel_Event::NAME_KINDS, true ) ? 'name_added' : 'identifier_added', [ 'entity_key' => $key, 'kind' => $kind, 'value' => $value ] );
					}
				}
				foreach ( $roles as $role ) {
					$events[] = self::mint( 'role_asserted', [ 'entity_key' => $key, 'role' => $role, 'from_ts' => 0 ] );
				}
			}
		}
		foreach ( self::LENSES as $key => [ $name, $description, $filters ] ) {
			if ( ! isset( $skip[ $key ] ) ) {
				$events[] = self::mint( 'lens_saved', [ 'lens_key' => $key, 'name' => $name, 'description' => $description, 'filters' => \array_replace( Graph_Query::unfiltered(), $filters ) ] );
			}
		}
		return $events;
	}

	/**
	 * One seed event, under the seed's own machine actor.
	 *
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @return array<string,mixed>
	 */
	private static function mint( string $kind, array $fields ): array {
		return Intel_Event::machine_event( 'ontology-seed', $kind, $fields );
	}

	/**
	 * The seed keys the projection settles — an entity in any state but a
	 * machine's pending `proposed`, a lens any `lens_saved` or
	 * `lens_removed` names — in seed order.
	 *
	 * @return list<string>
	 * @throws Table_Unavailable When no projection can be read.
	 */
	public static function present(): array {
		$lenses = \array_keys( self::LENSES );
		$held   = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $lenses ): array {
				$keys = [];
				foreach ( $r->aliases() as $alias ) {
					foreach ( $r->rows( "SELECT entity_key FROM {$alias}.entity_states WHERE state <> ?", [ 'proposed' ] ) as [ $key ] ) {
						$keys[ Core::as_string( $key ) ] = true;
					}
					foreach ( $r->rows( "SELECT subject FROM {$alias}.events WHERE subject IN ( " . Intel_Graph_Row_Schema::marks( $lenses ) . ' ) AND kind IN ( ?, ? )', [ ...$lenses, 'lens_saved', 'lens_removed' ] ) as [ $key ] ) {
						$keys[ Core::as_string( $key ) ] = true;
					}
				}
				return $keys;
			}
		);
		$present = [];
		foreach ( self::ENTITIES as $type => $entities ) {
			foreach ( \array_keys( $entities ) as $slug ) {
				$key = Intel_Event::entity_key( $type, $slug );
				if ( isset( $held[ $key ] ) ) {
					$present[] = $key;
				}
			}
		}
		foreach ( $lenses as $key ) {
			if ( isset( $held[ $key ] ) ) {
				$present[] = $key;
			}
		}
		return $present;
	}
}
