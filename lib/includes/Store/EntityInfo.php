<?php

namespace Wikibase\Lib\Store;

use OutOfBoundsException;
use RuntimeException;
use Wikibase\DataModel\Entity\EntityId;

/**
 * A wrapper for a simple array structure representing pre-fetched data about entities.
 *
 * The entity info is represented by a nested array structure. On the top level,
 * entity id strings are used as keys that refer to entity "records".
 *
 * Each record is an associative array with at least the fields "id" and "type".
 * Which other fields are present depends on which methods have been called on
 * the EntityInfoBuilder in order to gather information about the entities.
 *
 * The array structure should be compatible with the structure generated by
 * EntitySerializer and related classes. It should be suitable for serialization,
 * and must thus not contain any objects.
 *
 * @see EntityInfoBuilder
 *
 * @license GPL-2.0+
 * @author Daniel Kinzler
 */
class EntityInfo {

	/**
	 * @var array[]
	 */
	private $info;

	/**
	 * @param array[] $info An array of entity info records.
	 * See the class level documentation for information about the expected structure of this array.
	 */
	public function __construct( array $info ) {
		$this->info = $info;
	}

	/**
	 * Returns the array of entity info records as provided to the constructor.
	 * See the class level documentation for information about the expected structure of this array.
	 *
	 * @return array[]
	 */
	public function asArray() {
		return $this->info;
	}

	/**
	 * @param EntityId $entityId
	 *
	 * @return bool
	 */
	public function hasEntityInfo( EntityId $entityId ) {
		$key = $entityId->getSerialization();

		return array_key_exists( $key, $this->info );
	}

	/**
	 * @param EntityId $entityId
	 *
	 * @throws RuntimeException
	 * @throws OutOfBoundsException If this EntityInfo does not have information about the
	 *         requested Entity. This does say anything about whether the Entity exists in
	 *         the database.
	 * @return array An array structure representing information about the given entity.
	 *         Refer to the class level documentation for information about the structure.
	 */
	public function getEntityInfo( EntityId $entityId ) {
		$key = $entityId->getSerialization();

		if ( !array_key_exists( $key, $this->info ) ) {
			throw new OutOfBoundsException( "Unknown entity $entityId" );
		} elseif ( !is_array( $this->info[$key] ) ) {
			throw new RuntimeException( "$key term record is invalid" );
		}

		return $this->info[$key];
	}

	/**
	 * @param EntityId $entityId
	 * @param string $languageCode
	 *
	 * @throws OutOfBoundsException If nothing is known about the entity or its labels.
	 * @return string|null The entity's label in the given language,
	 *         or null if a label in that language is not known.
	 */
	public function getLabel( EntityId $entityId, $languageCode ) {
		return $this->getTermValue( $entityId, 'labels', $languageCode );
	}

	/**
	 * @param EntityId $entityId
	 * @param string $languageCode
	 *
	 * @throws OutOfBoundsException If nothing is known about the entity or its descriptions.
	 * @return string|null The entity's description in the given language,
	 *         or null if a description in that language is not known.
	 */
	public function getDescription( EntityId $entityId, $languageCode ) {
		return $this->getTermValue( $entityId, 'descriptions', $languageCode );
	}

	/**
	 * @param EntityId $entityId
	 * @param string[]|null $languageCodes
	 *
	 * @throws OutOfBoundsException If nothing is known about the entity or its labels.
	 * @return string[] The given entity's labels, keyed by language.
	 */
	public function getLabels( EntityId $entityId, array $languageCodes = null ) {
		return $this->getTermValues( $entityId, 'labels', $languageCodes );
	}

	/**
	 * @param EntityId $entityId
	 * @param string[]|null $languageCodes
	 *
	 * @throws OutOfBoundsException If nothing is known about the entity or its descriptions.
	 * @return string[] The given entity's descriptions, keyed by language.
	 */
	public function getDescriptions( EntityId $entityId, array $languageCodes = null ) {
		return $this->getTermValues( $entityId, 'descriptions', $languageCodes );
	}

	/**
	 * @param EntityId $entityId
	 * @param string $termField The term field (e.g. 'labels' or 'descriptions').
	 * @param string $languageCode
	 *
	 * @throws RuntimeException
	 * @throws OutOfBoundsException If nothing is known about the entity
	 *         or terms of the requested type.
	 * @return string|null The term value of the requested type and language, or null if a term in
	 * that language is not known.
	 */
	private function getTermValue( EntityId $entityId, $termField, $languageCode ) {
		$entityInfo = $this->getEntityInfo( $entityId );

		if ( !array_key_exists( $termField, $entityInfo ) ) {
			throw new OutOfBoundsException( "No '$termField' in $entityId term record" );
		} elseif ( !is_array( $entityInfo[$termField] ) ) {
			throw new RuntimeException( "$entityId term record is invalid" );
		} elseif ( !array_key_exists( $languageCode, $entityInfo[$termField] ) ) {
			return null;
		} elseif ( !is_array( $entityInfo[$termField][$languageCode] )
			|| !array_key_exists( 'value', $entityInfo[$termField][$languageCode] )
		) {
			throw new RuntimeException( "$entityId term record is missing the 'value' field" );
		}

		return $entityInfo[$termField][$languageCode]['value'];
	}

	/**
	 * @param EntityId $entityId
	 * @param string $termField The term field (e.g. 'labels' or 'descriptions').
	 * @param string[]|null $languages
	 *
	 * @throws RuntimeException
	 * @throws OutOfBoundsException If nothing is known about the entity
	 *         or terms of the requested type.
	 * @return string[] The entity's term values of the requested type, keyed by language.
	 */
	private function getTermValues( EntityId $entityId, $termField, array $languages = null ) {
		$entityInfo = $this->getEntityInfo( $entityId );

		if ( !array_key_exists( $termField, $entityInfo ) ) {
			throw new OutOfBoundsException( "No '$termField' in $entityId term record" );
		} elseif ( !is_array( $entityInfo[$termField] ) ) {
			throw new RuntimeException( "$entityId term record is invalid" );
		}

		if ( $languages !== null ) {
			$languages = array_flip( $languages );
		}

		$values = [];

		foreach ( $entityInfo[$termField] as $key => $term ) {
			if ( !is_array( $term ) || !array_key_exists( 'value', $term ) ) {
				throw new RuntimeException( "$entityId term record is missing the 'value' field" );
			}

			if ( $languages !== null && !isset( $languages[$key] ) ) {
				continue;
			}

			$values[$key] = $term['value'];
		}

		return $values;
	}

}
