<?php
declare( strict_types = 1 );

namespace Wikibase\Client\Tests\Unit\ServiceWiring;

use MediaWiki\Title\TitleFactory;
use ObjectCacheFactory;
use Psr\Log\NullLogger;
use Wikibase\Client\Tests\Unit\ServiceWiringTestCase;
use Wikibase\Lib\SettingsArray;
use Wikibase\Lib\Store\CachingPropertyOrderProvider;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * @coversNothing
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PropertyOrderProviderTest extends ServiceWiringTestCase {
	public static function propertyOrderUrlProvider(): array {
		return [
			[ 'page-url' ],
			[ null ],
		];
	}

	/**
	 * @dataProvider propertyOrderUrlProvider
	 */
	public function testConstruction( $url ): void {
		$this->mockService( 'WikibaseClient.Settings',
			new SettingsArray(
				[ 'propertyOrderUrl' => $url ]
			) );
		if ( $url !== null ) {
			$this->mockService( 'WikibaseClient.Logger',
				new NullLogger() );
		}

		$this->serviceContainer->expects( $this->once() )
			->method( 'getWikiPageFactory' );
		$this->serviceContainer->expects( $this->once() )
			->method( 'getTitleFactory' )
			->willReturn( $this->createMock( TitleFactory::class ) );

		$objectCacheFactory = $this->createMock( ObjectCacheFactory::class );
		$objectCacheFactory->expects( $this->once() )
			->method( 'getLocalClusterInstance' )
			->willReturn( $this->createMock( BagOStuff::class ) );
		$this->serviceContainer->expects( $this->once() )
			->method( 'getObjectCacheFactory' )
			->willReturn( $objectCacheFactory );

		$this->assertInstanceOf(
			CachingPropertyOrderProvider::class,
			$this->getService( 'WikibaseClient.PropertyOrderProvider' )
		);
	}
}
