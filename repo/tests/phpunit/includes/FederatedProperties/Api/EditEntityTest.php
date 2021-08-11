<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\FederatedProperties\Api;

use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * @covers \Wikibase\Repo\Api\EditEntity
 *
 * @group API
 * @group Database
 * @group Wikibase
 * @group WikibaseAPI
 *
 * @group medium
 *
 * @license GPL-2.0-or-later
 * @author Tobias Andersson
 */
class EditEntityTest extends FederatedPropertiesApiTestCase {

	public function testUpdatingAFederatedPropertyShouldFail(): void {
		$id = $this->newFederatedPropertyIdFromPId( 'P666' );

		$this->setExpectedApiException( wfMessage( 'wikibase-federated-properties-local-property-api-error-message' ) );

		$this->doApiRequestWithToken( [
			'action' => 'wbeditentity',
			'id' => $id->getSerialization(),
			'data' => json_encode( [
				'labels' => [
					'en' => [ 'language' => 'en', 'value' => 'im a feddy prop' ],
				],
			] ),
		] );
	}

	// We want updating local Properties to work eventually
	public function testUpdatingAPropertyShouldFail(): void {
		$entity = new Property( new PropertyId( 'P123' ), null, 'string' ); // needs to be saved via EntityStore
		$entityId = $entity->getId();

		$params = [
			'action' => 'wbeditentity',
			'id' => $entityId->getSerialization(),
			'data' => '{"datatype":"string"}'
		];

		$this->setExpectedApiException( wfMessage( 'wikibase-federated-properties-local-property-api-error-message' ) );
		$this->doApiRequestWithToken( $params );
	}

	public function testCreatingANewLocalProperty(): void {
		$expectedLabel = 'local prop';

		[ $result, ] = $this->doApiRequestWithToken( [
			'action' => 'wbeditentity',
			'new' => 'property',
			'data' => json_encode( [
				'datatype' => 'string',
				'labels' => [
					'en' => [ 'language' => 'en', 'value' => $expectedLabel ],
				],
			] ),
		] );

		$this->assertArrayHasKey(
			'success',
			$result
		);
		$this->assertSame(
			$expectedLabel,
			$result['entity']['labels']['en']['value']
		);
	}
}
