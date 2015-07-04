<?php

namespace Wikibase\Api;

use ApiBase;
use ApiMain;
use InvalidArgumentException;
use LogicException;
use Status;
use UsageException;
use Wikibase\ChangeOp\ChangeOp;
use Wikibase\ChangeOp\ChangeOpException;
use Wikibase\ChangeOp\ChangeOpValidationException;
use Wikibase\DataModel\Entity\Entity;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\EntityRevision;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\SiteLinkLookup;
use Wikibase\Lib\Store\StorageException;
use Wikibase\Repo\SiteLinkTargetProvider;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\StringNormalizer;
use Wikibase\Summary;

/**
 * Base class for API modules modifying a single entity identified based on id xor a combination of site and page title.
 *
 * @since 0.1
 *
 * @licence GNU GPL v2+
 * @author John Erling Blad < jeblad@gmail.com >
 * @author Daniel Kinzler
 * @author Michał Łazowik
 */
abstract class ModifyEntity extends ApiWikibase {

	/**
	 * @var StringNormalizer
	 */
	protected $stringNormalizer;

	/**
	 * @var SiteLinkTargetProvider
	 */
	protected $siteLinkTargetProvider;

	/**
	 * @var SiteLinkLookup
	 */
	protected $siteLinkLookup;

	/**
	 * @var EntityTitleLookup
	 */
	private $titleLookup;

	/**
	 * @var EntityStore
	 */
	private $entityStore;

	/**
	 * @since 0.5
	 *
	 * @var string[]
	 */
	protected $siteLinkGroups;

	/**
	 * @var string[]
	 */
	protected $badgeItems;

	/**
	 * Flags to pass to EditEntity::attemptSave; use with the EDIT_XXX constants.
	 *
	 * @see EditEntity::attemptSave
	 * @see WikiPage::doEditContent
	 *
	 * @var int
	 */
	protected $flags;

	/**
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param string $modulePrefix
	 *
	 * @see ApiBase::__construct
	 */
	public function __construct( ApiMain $mainModule, $moduleName, $modulePrefix = '' ) {
		parent::__construct( $mainModule, $moduleName, $modulePrefix );

		$wikibaseRepo = WikibaseRepo::getDefaultInstance();
		$settings = $wikibaseRepo->getSettings();

		//TODO: provide a mechanism to override the services
		$this->stringNormalizer = $wikibaseRepo->getStringNormalizer();

		$this->siteLinkTargetProvider = new SiteLinkTargetProvider(
			$wikibaseRepo->getSiteStore(),
			$settings->getSetting( 'specialSiteLinkGroups' )
		);

		$this->entityStore = $wikibaseRepo->getEntityStore();
		$this->titleLookup = $wikibaseRepo->getEntityTitleLookup();
		$this->siteLinkGroups = $settings->getSetting( 'siteLinkGroups' );
		$this->siteLinkLookup = $wikibaseRepo->getStore()->newSiteLinkStore();
		$this->badgeItems = $settings->getSetting( 'badgeItems' );
	}

	/**
	 * @return EntityTitleLookup
	 */
	protected function getTitleLookup() {
		return $this->titleLookup;
	}

	/**
	 * @return EntityStore
	 */
	protected function getEntityStore() {
		return $this->entityStore;
	}

	/**
	 * Get the entity using the id, site and title params passed to the api
	 *
	 * @param array $params
	 *
	 * @return EntityRevision Found existing entity
	 */
	protected function getEntityRevisionFromApiParams( array $params ) {
		$entityRevision = null;
		$entityId = $this->getEntityIdFromParams( $params );

		// Things that use this method assume null means we want a new entity
		if ( $entityId !== null ) {
			$baseRevisionId = isset( $params['baserevid'] ) ? (int)$params['baserevid'] : 0;

			if ( $baseRevisionId === 0 ) {
				$baseRevisionId = EntityRevisionLookup::LATEST_FROM_MASTER;
			}

			try {
				$entityRevision = $this->getEntityRevisionLookup()->getEntityRevision( $entityId, $baseRevisionId );
			} catch ( StorageException $ex ) {
				$this->dieException( $ex, 'no-such-entity' );
			}

			if ( $entityRevision === null ) {
				$this->dieError( "Can't access entity " . $entityId
					. ', revision may have been deleted.', 'no-such-entity' );
			}
		}

		return $entityRevision;
	}

	/**
	 * @param string[] $params
	 *
	 * @return EntityId|null
	 */
	private function getEntityIdFromParams( array $params ) {
		if ( isset( $params['id'] ) ) {
			return $this->getEntityIdFromString( $params['id'] );
		} elseif ( isset( $params['site'] ) && isset( $params['title'] ) ) {
			return $this->getEntityIdFromSiteTitleCombination(
				$params['site'],
				$params['title']
			);
		}

		return null;
	}

	/**
	 * Returns an EntityId object based on the given $id,
	 * or throws a usage exception if the ID is invalid.
	 *
	 * @param string $id
	 *
	 * @throws UsageException
	 * @return EntityId
	 */
	protected function getEntityIdFromString( $id ) {
		try {
			return $this->getIdParser()->parse( $id );
		} catch ( EntityIdParsingException $ex ) {
			$this->dieException( $ex, 'no-such-entity-id' );
		}

		return null;
	}

	/**
	 * Returns the ID of the entity connected to $title on $site, or
	 * throws a usage exception if no such entity is found.
	 *
	 * @param string $site
	 * @param string $title
	 *
	 * @return EntityId
	 */
	protected function getEntityIdFromSiteTitleCombination( $site, $title ) {
		$itemId = $this->siteLinkLookup->getItemIdForLink( $site, $title );

		if ( $itemId === null ) {
			$this->dieError( 'No entity found matching site link ' . $site . ':' . $title,
				'no-such-entity-link' );
		}

		return $itemId;
	}

	/**
	 * Validates badges from params and turns them into an array of ItemIds.
	 *
	 * @param string[] $badgesParams
	 *
	 * @return ItemId[]
	 */
	protected function parseSiteLinkBadges( array $badgesParams ) {
		$badges = array();

		foreach ( $badgesParams as $badgeSerialization ) {
			try {
				$badgeId = new ItemId( $badgeSerialization );
			} catch ( InvalidArgumentException $ex ) {
				$this->dieError( 'Badges: could not parse "' . $badgeSerialization
					. '", the id is invalid', 'invalid-entity-id' );
				continue;
			}

			if ( !array_key_exists( $badgeId->getSerialization(), $this->badgeItems ) ) {
				$this->dieError( 'Badges: item "' . $badgeSerialization . '" is not a badge',
					'not-badge' );
			}

			$itemTitle = $this->getTitleLookup()->getTitleForId( $badgeId );

			if ( is_null( $itemTitle ) || !$itemTitle->exists() ) {
				$this->dieError( 'Badges: no item found matching id "' . $badgeSerialization . '"',
					'no-such-entity' );
			}

			$badges[] = $badgeId;
		}

		return $badges;
	}

	/**
	 * Create the entity.
	 *
	 * @since 0.1
	 *
	 * @param string $entityType
	 *
	 * @return Entity Newly created entity
	 */
	protected function createEntity( $entityType ) {
		$this->dieError( 'Could not find an existing entity', 'no-such-entity' );
	}

	/**
	 * Create a new Summary instance suitable for representing the action performed by this module.
	 *
	 * @param array $params
	 *
	 * @return Summary
	 */
	protected function createSummary( array $params ) {
		$summary = new Summary( $this->getModuleName() );
		$summary->setUserSummary( $params['summary'] );
		return $summary;
	}

	/**
	 * Actually modify the entity.
	 *
	 * @since 0.1
	 *
	 * @param Entity $entity
	 * @param array $params
	 * @param int $baseRevId
	 *
	 * @return Summary|null a summary of the modification, or null to indicate failure.
	 */
	abstract protected function modifyEntity( Entity &$entity, array $params, $baseRevId );

	/**
	 * Applies the given ChangeOp to the given Entity.
	 * Any ChangeOpException is converted into a UsageException with the code 'modification-failed'.
	 *
	 * @since 0.5
	 *
	 * @param ChangeOp $changeOp
	 * @param Entity $entity
	 * @param Summary $summary The summary object to update with information about the change.
	 *
	 * @throws UsageException
	 */
	protected function applyChangeOp( ChangeOp $changeOp, Entity $entity, Summary $summary = null ) {
		try {
			$result = $changeOp->validate( $entity );

			if ( !$result->isValid() ) {
				throw new ChangeOpValidationException( $result );
			}

			$changeOp->apply( $entity, $summary );
		} catch ( ChangeOpException $ex ) {
			$this->dieException( $ex, 'modification-failed' );
		}
	}

	/**
	 * Make sure the required parameters are provided and that they are valid.
	 *
	 * @since 0.1
	 *
	 * @param array $params
	 */
	protected function validateParameters( array $params ) {
		// note that this is changed back and could fail
		if ( !( isset( $params['id'] ) XOR ( isset( $params['site'] ) && isset( $params['title'] ) ) ) ) {
			$this->dieError( 'Either provide the item "id" or pairs of "site" and "title"'
				. ' for a corresponding page', 'param-illegal' );
		}
	}

	/**
	 * @see ApiBase::execute()
	 *
	 * @since 0.1
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();
		$this->flags = 0;

		$this->validateParameters( $params );

		// Try to find the entity or fail and create it, or die in the process
		$entityRev = $this->getEntityRevisionFromApiParams( $params );
		if ( is_null( $entityRev ) ) {
			$entity = $this->createEntity( $params['new'] );
			$entityRevId = 0;

			// HACK: We need to assign an ID early, for things like the ClaimIdGenerator.
			if ( $entity->getId() === null ) {
				$this->getEntityStore()->assignFreshId( $entity );
			}
		} else {
			$entity = $entityRev->getEntity();
			$entityRevId = $entityRev->getRevisionId();
		}

		if ( $entity->getId() === null ) {
			throw new LogicException( 'The Entity should have an ID at this point!' );
		}

		// At this point only change/edit rights should be checked
		$status = $this->checkPermissions( $entity, $user );

		if ( !$status->isOK() ) {
			$this->dieError( 'You do not have sufficient permissions', 'permissiondenied' );
		}

		$summary = $this->modifyEntity( $entity, $params, $entityRevId );

		if ( !$summary ) {
			//XXX: This could rather be used for "silent" failure, i.e. in cases where
			//     there was simply nothing to do.
			$this->dieError( 'Attempted modification of the item failed', 'failed-modify' );
		}

		$this->addFlags( $entity->getId() === null );

		//NOTE: EDIT_NEW will not be set automatically. If the entity doesn't exist, and EDIT_NEW was
		//      not added to $this->flags explicitly, the save will fail.
		$status = $this->attemptSaveEntity(
			$entity,
			$summary,
			$this->flags
		);

		$this->addToOutput( $entity, $status );
	}

	/**
	 * @param bool $entityIsNew
	 */
	protected function addFlags( $entityIsNew ) {
		// if the entity is not up for creation, set the EDIT_UPDATE flags
		if ( !$entityIsNew && ( $this->flags & EDIT_NEW ) === 0 ) {
			$this->flags |= EDIT_UPDATE;
		}

		$params = $this->extractRequestParams();
		$this->flags |= ( $this->getUser()->isAllowed( 'bot' ) && $params['bot'] ) ? EDIT_FORCE_BOT : 0;
	}

	protected function addToOutput( Entity $entity, Status $status ) {
		$this->getResultBuilder()->addBasicEntityInformation( $entity->getId(), 'entity' );
		$this->getResultBuilder()->addRevisionIdFromStatusToResult( $status, 'entity' );

		$params = $this->extractRequestParams();

		if ( isset( $params['site'] ) && isset( $params['title'] ) ) {
			$normTitle = $this->stringNormalizer->trimToNFC( $params['title'] );
			if ( $normTitle !== $params['title'] ) {
				$this->getResultBuilder()->addNormalizedTitle( $params['title'], $normTitle, 'normalized' );
			}
		}

		$this->getResultBuilder()->markSuccess( 1 );
	}

	/**
	 * @see ApiBase::isWriteMode()
	 *
	 * @return bool Always true.
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @see ApiBase::getAllowedParams
	 */
	protected function getAllowedParams() {
		return array_merge(
			parent::getAllowedParams(),
			$this->getAllowedParamsForId(),
			$this->getAllowedParamsForSiteLink(),
			$this->getAllowedParamsForEntity()
		);
	}

	/**
	 * Get allowed params for the identification of the entity
	 * Lookup through an id is common for all entities
	 *
	 * @since 0.1
	 *
	 * @return array
	 */
	protected function getAllowedParamsForId() {
		return array(
			'id' => array(
				ApiBase::PARAM_TYPE => 'string',
			),
		);
	}

	/**
	 * Get allowed params for the identification by a sitelink pair
	 * Lookup through the sitelink object is not used in every subclasses
	 *
	 * @since 0.1
	 *
	 * @return array
	 */
	protected function getAllowedParamsForSiteLink() {
		$sites = $this->siteLinkTargetProvider->getSiteList( $this->siteLinkGroups );
		return array(
			'site' => array(
				ApiBase::PARAM_TYPE => $sites->getGlobalIdentifiers(),
			),
			'title' => array(
				ApiBase::PARAM_TYPE => 'string',
			),
		);
	}

	/**
	 * Get allowed params for the entity in general
	 *
	 * @since 0.1
	 *
	 * @return array
	 */
	protected function getAllowedParamsForEntity() {
		return array(
			'baserevid' => array(
				ApiBase::PARAM_TYPE => 'integer',
			),
			'summary' => array(
				ApiBase::PARAM_TYPE => 'string',
			),
			'token' => null,
			'bot' => false,
		);
	}

}
