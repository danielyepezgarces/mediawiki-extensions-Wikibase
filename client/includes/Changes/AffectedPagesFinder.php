<?php

namespace Wikibase\Client\Changes;

use ArrayIterator;
use Diff\DiffOp\Diff\Diff;
use Diff\DiffOp\DiffOp;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpChange;
use Diff\DiffOp\DiffOpRemove;
use InvalidArgumentException;
use Title;
use Traversable;
use UnexpectedValueException;
use Wikibase\Change;
use Wikibase\Client\Store\TitleFactory;
use Wikibase\Client\Usage\EntityUsage;
use Wikibase\Client\Usage\PageEntityUsages;
use Wikibase\Client\Usage\UsageAspectTransformer;
use Wikibase\Client\Usage\UsageLookup;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Services\Diff\EntityDiff;
use Wikibase\DataModel\Services\Diff\ItemDiff;
use Wikibase\EntityChange;
use Wikibase\ItemChange;
use Wikibase\Lib\Store\StorageException;

/**
 * @license GPL-2.0+
 * @author Daniel Kinzler
 * @author Katie Filbert < aude.wiki@gmail.com >
 */
class AffectedPagesFinder {

	/**
	 * @var UsageLookup
	 */
	private $usageLookup;

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var string
	 */
	private $siteId;

	/**
	 * @var string
	 */
	private $contentLanguageCode;

	/**
	 * @var bool
	 */
	private $checkPageExistence;

	/**
	 * @param UsageLookup $usageLookup
	 * @param TitleFactory $titleFactory
	 * @param string $siteId
	 * @param string $contentLanguageCode
	 * @param bool $checkPageExistence
	 *
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		UsageLookup $usageLookup,
		TitleFactory $titleFactory,
		$siteId,
		$contentLanguageCode,
		$checkPageExistence = true
	) {
		if ( !is_string( $siteId ) ) {
			throw new InvalidArgumentException( '$siteId must be a string' );
		}

		if ( !is_string( $contentLanguageCode ) ) {
			throw new InvalidArgumentException( '$contentLanguageCode must be a string' );
		}

		if ( !is_bool( $checkPageExistence ) ) {
			throw new InvalidArgumentException( '$checkPageExistence must be a boolean' );
		}

		$this->usageLookup = $usageLookup;
		$this->titleFactory = $titleFactory;
		$this->siteId = $siteId;
		$this->contentLanguageCode = $contentLanguageCode;
		$this->checkPageExistence = $checkPageExistence;
	}

	/**
	 * @param Change $change
	 *
	 * @return ArrayIterator of PageEntityUsage
	 */
	public function getAffectedUsagesByPage( Change $change ) {
		if ( $change instanceof EntityChange ) {
			$usages = $this->getAffectedPages( $change );
			return $this->filterUpdates( $usages );
		}

		return new ArrayIterator();
	}

	/**
	 * @param EntityChange $change
	 *
	 * @return string[]
	 */
	public function getChangedAspects( EntityChange $change ) {
		$aspects = [];

		$diff = $change->getDiff();
		$remainingDiffOps = count( $diff ); // this is a "deep" count!

		if ( $remainingDiffOps === 0 ) {
			// HACK: assume an empty diff implies that some "other" aspect of the entity was changed.
			// This is needed since EntityChangeFactory::newFromUpdate suppresses statement, description
			// and alias diffs for performance reasons.
			// For a better solution, see T113468.
			$aspects[] = EntityUsage::OTHER_USAGE;
			return $aspects;
		}

		if ( $diff instanceof ItemDiff && !$diff->getSiteLinkDiff()->isEmpty() ) {
			$siteLinkDiff = $diff->getSiteLinkDiff();

			$aspects[] = EntityUsage::SITELINK_USAGE;
			$remainingDiffOps -= count( $siteLinkDiff );

			if ( isset( $siteLinkDiff[$this->siteId] )
				&& !$this->isBadgesOnlyChange( $siteLinkDiff[$this->siteId] )
			) {
				$aspects[] = EntityUsage::TITLE_USAGE;
			}
		}

		if ( $diff instanceof EntityDiff && !$diff->getLabelsDiff()->isEmpty() ) {
			$labelsDiff = $diff->getLabelsDiff();

			if ( !empty( $labelsDiff ) ) {
				$labelAspects = $this->getChangedLabelAspects( $labelsDiff );
				$aspects = array_merge( $aspects, $labelAspects );
				$remainingDiffOps -= count( $labelAspects );
			}
		}
		// FIXME: EntityChange suppresses various kinds of diffs (see above). T113468.

		if ( $remainingDiffOps > 0 ) {
			$aspects[] = EntityUsage::OTHER_USAGE;
		}

		return $aspects;
	}

	/**
	 * @param Diff $labelsDiff
	 *
	 * @return string[]
	 */
	private function getChangedLabelAspects( Diff $labelsDiff ) {
		$aspects = [];

		foreach ( $labelsDiff as $lang => $diffOp ) {
			$aspects[] = EntityUsage::makeAspectKey( EntityUsage::LABEL_USAGE, $lang );
		}

		return $aspects;
	}

	/**
	 * Returns the page updates implied by the given the change.
	 *
	 * @param EntityChange $change
	 *
	 * @return Traversable of PageEntityUsages
	 */
	private function getAffectedPages( EntityChange $change ) {
		$entityId = $change->getEntityId();
		$changedAspects = $this->getChangedAspects( $change );

		$usages = $this->usageLookup->getPagesUsing(
			// @todo: more than one entity at once!
			[ $entityId ],
			// Look up pages that are marked as either using one of the changed or all aspects
			array_merge( $changedAspects, [ EntityUsage::ALL_USAGE ] )
		);

		// @todo: use iterators throughout!
		$usages = iterator_to_array( $usages, true );

		$usages = $this->transformAllPageEntityUsages( $usages, $entityId, $changedAspects );

		if ( $change instanceof ItemChange && in_array( EntityUsage::TITLE_USAGE, $changedAspects ) ) {
			$siteLinkDiff = $change->getSiteLinkDiff();
			$namesFromDiff = $this->getPagesReferencedInDiff( $siteLinkDiff );
			$titlesFromDiff = $this->getTitlesFromTexts( $namesFromDiff );
			$usagesFromDiff = $this->makeVirtualUsages( $titlesFromDiff, $entityId, [ EntityUsage::SITELINK_USAGE ] );

			//FIXME: we can't really merge if $usages is an iterator, not an array.
			//TODO: Inject $usagesFromDiff "on the fly" while streaming other usages.
			//NOTE: $usages must pass through mergeUsagesInto for re-indexing
			$mergedUsages = [];
			$this->mergeUsagesInto( $usages, $mergedUsages );
			$this->mergeUsagesInto( $usagesFromDiff, $mergedUsages );
			$usages = $mergedUsages;
		}

		return new ArrayIterator( $usages );
	}

	/**
	 * @param PageEntityUsages[] $from
	 * @param PageEntityUsages[] &$into Array to merge into
	 */
	private function mergeUsagesInto( array $from, array &$into ) {
		foreach ( $from as $pageEntityUsages ) {
			$key = $pageEntityUsages->getPageId();

			if ( isset( $into[$key] ) ) {
				$into[$key]->addUsages( $pageEntityUsages->getUsages() );
			} else {
				$into[$key] = $pageEntityUsages;
			}
		}
	}

	/**
	 * @param Diff $siteLinkDiff
	 *
	 * @throws UnexpectedValueException
	 * @return string[]
	 */
	private function getPagesReferencedInDiff( Diff $siteLinkDiff ) {
		$pagesToUpdate = [];

		// $siteLinkDiff changed from containing atomic diffs to
		// containing map diffs. For B/C, handle both cases.
		$siteLinkDiffOp = $siteLinkDiff[$this->siteId];

		if ( $siteLinkDiffOp instanceof Diff && array_key_exists( 'name', $siteLinkDiffOp ) ) {
			$siteLinkDiffOp = $siteLinkDiffOp['name'];
		}

		if ( $siteLinkDiffOp instanceof DiffOpAdd ) {
			$pagesToUpdate[] = $siteLinkDiffOp->getNewValue();
		} elseif ( $siteLinkDiffOp instanceof DiffOpRemove ) {
			$pagesToUpdate[] = $siteLinkDiffOp->getOldValue();
		} elseif ( $siteLinkDiffOp instanceof DiffOpChange ) {
			$pagesToUpdate[] = $siteLinkDiffOp->getNewValue();
			$pagesToUpdate[] = $siteLinkDiffOp->getOldValue();
		} else {
			throw new UnexpectedValueException(
				"Unknown change operation: " . get_class( $siteLinkDiffOp ) . ")"
			);
		}

		return $pagesToUpdate;
	}

	/**
	 * @param DiffOp $siteLinkDiffOp
	 *
	 * @return bool
	 */
	private function isBadgesOnlyChange( DiffOp $siteLinkDiffOp ) {
		return $siteLinkDiffOp instanceof Diff && !array_key_exists( 'name', $siteLinkDiffOp );
	}

	/**
	 * Filters updates based on namespace. This removes duplicates, non-existing pages, and pages from
	 * namespaces that are not considered "enabled" by the namespace checker.
	 *
	 * @param Traversable $usages A traversable of PageEntityUsages.
	 *
	 * @return ArrayIterator of PageEntityUsages
	 */
	private function filterUpdates( Traversable $usages ) {
		$titlesToUpdate = [];

		/** @var PageEntityUsages $pageEntityUsages */
		foreach ( $usages as $pageEntityUsages ) {
			try {
				$title = $this->titleFactory->newFromID( $pageEntityUsages->getPageId() );
			} catch ( StorageException $ex ) {
				// page not found, skip
				continue;
			}

			if ( $this->checkPageExistence && !$title->exists() ) {
				continue;
			}

			$key = $pageEntityUsages->getPageId();
			$titlesToUpdate[$key] = $pageEntityUsages;
		}

		return new ArrayIterator( $titlesToUpdate );
	}

	/**
	 * @param string[] $names
	 *
	 * @return Title[]
	 */
	private function getTitlesFromTexts( array $names ) {
		$titles = [];

		foreach ( $names as $name ) {
			try {
				$titles[] = $this->titleFactory->newFromText( $name );
			} catch ( StorageException $ex ) {
				// Invalid title in the diff? Skip.
			}
		}

		return $titles;
	}

	/**
	 * @param Title[] $titles
	 * @param EntityId $entityId
	 * @param string[] $aspects
	 *
	 * @return PageEntityUsages[]
	 */
	private function makeVirtualUsages( array $titles, EntityId $entityId, array $aspects ) {
		$usagesForItem = [];
		foreach ( $aspects as $aspect ) {
			list( $aspect, $modifier ) = EntityUsage::splitAspectKey( $aspect );
			$usagesForItem[] = new EntityUsage( $entityId, $aspect, $modifier );
		}

		$usagesPerPage = [];
		foreach ( $titles as $title ) {
			$pageId = $title->getArticleID();

			if ( $pageId === 0 ) {
				wfDebugLog( 'WikibaseChangeNotification', __METHOD__ . ': Article ID for '
					. $title->getFullText() . ' is 0.' );

				continue;
			}

			$usagesPerPage[$pageId] = new PageEntityUsages( $pageId, $usagesForItem );
		}

		return $usagesPerPage;
	}

	/**
	 * @param PageEntityUsages[] $usages
	 * @param EntityId $entityId
	 * @param string[] $changedAspects
	 *
	 * @return PageEntityUsages[]
	 */
	private function transformAllPageEntityUsages( array $usages, EntityId $entityId, array $changedAspects ) {
		$aspectTransformer = new UsageAspectTransformer();
		$aspectTransformer->setRelevantAspects( $entityId, $changedAspects );

		$transformed = [];

		foreach ( $usages as $key => $usagesOnPage ) {
			$transformedUsagesOnPage = $aspectTransformer->transformPageEntityUsages( $usagesOnPage );

			if ( !$transformedUsagesOnPage->isEmpty() ) {
				$transformed[$key] = $transformedUsagesOnPage;
			}
		}

		return $transformed;
	}

}
