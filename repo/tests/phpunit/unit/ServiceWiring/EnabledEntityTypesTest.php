<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\Unit\ServiceWiring;

use Wikibase\DataAccess\EntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\Lib\EntityTypeDefinitions;
use Wikibase\Repo\Tests\Unit\ServiceWiringTestCase;

/**
 * @coversNothing
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class EnabledEntityTypesTest extends ServiceWiringTestCase {

	public function testConstruction(): void {
		$entityTypeDefinitions = new EntityTypeDefinitions( [
			'lexeme' => [
				EntityTypeDefinitions::SUB_ENTITY_TYPES => [
					'form',
				],
			],
		] );
		$this->mockService( 'WikibaseRepo.EntitySourceDefinitions',
			new EntitySourceDefinitions( [
				new EntitySource(
					'local',
					false,
					[
						'foo' => [ 'namespaceId' => 200, 'slot' => 'main' ],
						'bar' => [ 'namespaceId' => 220, 'slot' => 'main' ],
					],
					'',
					'',
					'',
					''
				),
				new EntitySource(
					'bazwiki',
					'bazdb',
					[
						'baz' => [ 'namespaceId' => 250, 'slot' => 'main' ],
					],
					'',
					'baz',
					'baz',
					'bazwiki'
				),
				new EntitySource(
					'lexemewiki',
					'bazdb',
					[
						'lexeme' => [ 'namespaceId' => 280, 'slot' => 'main' ],
					],
					'',
					'lex',
					'lex',
					'lexwiki'
				)
			], $entityTypeDefinitions ) );
		$this->mockService( 'WikibaseRepo.EntityTypeDefinitions',
			$entityTypeDefinitions );

		/** @var string[] $enabled */
		$enabled = $this->getService( 'WikibaseRepo.EnabledEntityTypes' );

		$this->assertIsArray( $enabled );
		$this->assertContains( 'foo', $enabled );
		$this->assertContains( 'bar', $enabled );
		$this->assertContains( 'baz', $enabled );
		$this->assertContains( 'lexeme', $enabled );
		$this->assertContains( 'form', $enabled );
	}

}
