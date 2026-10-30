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
			'partner-org-25'             => [ 'Partner Org 25', [ 'alias' => [ 'Nonprofit newsrooms', 'Nonprofit journalism' ] ], [] ],
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
			'partnerorg26-lab'                   => [ 'PartnerOrg26 Lab', [ 'alias' => [ 'PartnerOrg26 Journalism Lab' ], 'domain' => [ 'partner-18.example' ] ], [ 'industry_body' ] ],
			'partner-org-1'            => [ 'Partner Org 1', [ 'alias' => [ 'John S. and James L. Partner Org 1' ], 'domain' => [ 'partner-5.example' ] ], [ 'funder' ] ],
			'partnerorg2-institute'            => [ 'PartnerOrg2 Institute for Journalism', [ 'alias' => [ 'PartnerOrg2 Institute' ], 'domain' => [ 'partner-4.example' ] ], [ 'funder' ] ],
			'institute-for-partner-org-25' => [ 'Institute for Partner Org 25', [ 'acronym' => [ 'ORG4' ], 'domain' => [ 'partner-24.example' ] ], [ 'industry_body' ] ],
			'partner-org-24'              => [ 'Partner Org 24', [ 'alias' => [ 'Local Independent Online News Publishers' ], 'acronym' => [ 'ORG3' ], 'domain' => [ 'partner-8.example' ] ], [ 'industry_body' ] ],
			'partner-org-3'                => [ 'Partner Org 3', [ 'domain' => [ 'partner-10.example' ] ], [ 'funder' ] ],
			'partnerorg4'                      => [ 'PartnerOrg4', [ 'domain' => [ 'partner-17.example' ] ], [ 'partner' ] ],
			'partnerorg27'                       => [ 'PartnerOrg27', [ 'domain' => [ 'partner-20.example' ] ], [ 'partner' ] ],
			'publication6'                     => [ 'Publication6', [ 'domain' => [ 'client-150.example' ] ], [ 'partner' ] ],
			'partner-org-5-collaborative'    => [ 'Partner Org 5 Collaborative', [ 'alias' => [ 'Partner Org 5' ], 'domain' => [ 'partner-3.example' ] ], [ 'partner' ] ],
			'partner-org-6'      => [ 'Partner Org 6', [ 'acronym' => [ 'ORG5' ], 'domain' => [ 'partner-15.example' ] ], [ 'partner' ] ],
			'partner-org-7'             => [ 'Partner Org 7', [ 'domain' => [ 'partner-9.example' ] ], [ 'partner' ] ],
			'partner-org-8'                     => [ 'partner-org-8', [ 'alias' => [ 'PartnerOrg8' ], 'domain' => [ 'partner-28.example' ] ], [ 'partner' ] ],
			'partner-org-9'        => [ 'Partner Org 9', [ 'acronym' => [ 'ORG6' ], 'domain' => [ 'partner-12.example' ] ], [ 'industry_body' ] ],
			'partnerorg10'                     => [ 'PartnerOrg10', [ 'alias' => [ 'Partner Org 10' ], 'domain' => [ 'partner-16.example' ] ], [ 'industry_body' ] ],
			'association-of-partner-org-11' => [ 'Association of Partner Org 11', [ 'acronym' => [ 'ORG7' ], 'domain' => [ 'partner-27.example' ] ], [ 'industry_body' ] ],
			'partner-org-12' => [ 'Partner Org 12', [ 'acronym' => [ 'ORG8' ], 'domain' => [ 'partner-23.example' ] ], [ 'industry_body' ] ],
			'americas-newspapers'          => [ "Partner Org 13", [ 'domain' => [ 'partner-14.example' ] ], [ 'industry_body' ] ],
			'partner-org-14'      => [ 'Partner Org 14', [ 'acronym' => [ 'ORG9' ], 'domain' => [ 'partner-13.example' ] ], [ 'industry_body' ] ],
			'partner-org-15'           => [ 'Partner Org 15', [ 'domain' => [ 'partner-6.example' ] ], [ 'funder' ] ],
			'partner-org-16'       => [ 'Partner Org 16', [ 'acronym' => [ 'ORG10' ], 'domain' => [ 'partner-1.example' ] ], [ 'funder' ] ],
			'partner-org-17'           => [ 'Partner Org 17', [ 'alias' => [ 'PartnerOrg19 Awards', 'Academy of Television Arts & Sciences' ], 'domain' => [ 'partner-21.example', 'partner-7.example' ] ], [ 'industry_body' ] ],
			'partnerorg18-prizes'              => [ 'PartnerOrg18 Prizes', [ 'alias' => [ 'PartnerOrg18 Prize' ], 'domain' => [ 'partner-19.example' ] ], [ 'industry_body' ] ],
			'american-society-of-partner-org-20' => [ 'American Society of Partner Org 20', [ 'acronym' => [ 'ORG2' ], 'domain' => [ 'partner-22.example' ] ], [ 'industry_body' ] ],
			'partner-org-21-and-editors' => [ 'Partner Org 21 and Editors', [ 'acronym' => [ 'ORG11' ], 'domain' => [ 'partner-25.example' ] ], [ 'industry_body' ] ],
			'partner-org-22'          => [ 'Partner Org 22 for Freedom of the Press', [ 'alias' => [ 'Partner Org 22' ], 'acronym' => [ 'ORG1' ], 'domain' => [ 'partner-26.example' ] ], [ 'industry_body' ] ],
			'partnerorg23-prize'                  => [ 'PartnerOrg23 Prize', [ 'alias' => [ 'PartnerOrg23 Prizes' ], 'domain' => [ 'partner-11.example' ] ], [ 'industry_body' ] ],
		],
		'product'      => [
			'newspack'                => [ 'Newspack', [ 'linear_team' => [ 'TEAMA' ] ], [ 'newspack_product' ] ],
			'newspack-plugin'         => [ 'Newspack Plugin', [ 'github_repo' => [ 'automattic/newspack-plugin' ] ], [ 'newspack_product' ] ],
			'newspack-ads'            => [ 'Newspack Ads', [ 'github_repo' => [ 'automattic/newspack-ads' ] ], [ 'newspack_product' ] ],
			'newspack-activecampaign' => [ 'Newspack ActiveCampaign', [ 'github_repo' => [ 'automattic/newspack-activecampaign' ] ], [ 'newspack_product' ] ],
			'private-repo-1'        => [ 'Newspack Manager', [ 'github_repo' => [ 'automattic/private-repo-1' ] ], [ 'newspack_product' ] ],
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
