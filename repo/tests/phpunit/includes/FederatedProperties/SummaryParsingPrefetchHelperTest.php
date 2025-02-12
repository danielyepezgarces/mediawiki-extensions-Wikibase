<?php

declare( strict_types = 1 );
namespace Wikibase\Repo\Tests\FederatedProperties;

use MediaWiki\Revision\RevisionRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Term\TermTypes;
use Wikibase\Repo\FederatedProperties\ApiRequestException;
use Wikibase\Repo\FederatedProperties\SummaryParsingPrefetchHelper;

/**
 * @covers \Wikibase\Repo\FederatedProperties\SummaryParsingPrefetchHelper
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class SummaryParsingPrefetchHelperTest extends TestCase {

	/** @var PrefetchingTermLookup */
	private $prefetchingLookup;

	protected function setUp(): void {
		parent::setUp();
		$this->prefetchingLookup = $this->createMock( PrefetchingTermLookup::class );
	}

	/**
	 * @dataProvider rowDataProvider
	 */
	public function testParsesAndPrefetchesComments( callable $rowsFactory, array $expectedProperties ) {
		$rows = $rowsFactory( $this );
		$helper = new SummaryParsingPrefetchHelper( $this->prefetchingLookup, new NullLogger() );

		$expectedPropertyIds = [];
		foreach ( $expectedProperties as $propertyString ) {
			$expectedPropertyIds[] = new NumericPropertyId( $propertyString );
		}

		$this->prefetchingLookup->expects( empty( $expectedPropertyIds ) ? $this->never() : $this->once() )
			->method( 'prefetchTerms' )
			->with(
				$expectedPropertyIds,
				[ TermTypes::TYPE_LABEL ],
				[ 'en' ]
			);

		$helper->prefetchFederatedProperties( $rows, [ 'en' ], [ TermTypes::TYPE_LABEL ] );
	}

	public function testShouldNotFatalOnFailedRequests() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'warning' )
			->with( 'Prefetching failed for federated properties: {resultProperties}', self::containsEqual( 'P31' ) );

		$helper = new SummaryParsingPrefetchHelper( $this->prefetchingLookup, $logger );
		$rows = [ (object)[ 'rev_comment_text' => '[[Property:P31]]' ] ];

		$this->prefetchingLookup->expects( $this->once() )
			->method( 'prefetchTerms' )
			->willThrowException( new ApiRequestException( 'oh no!' ) );

		$helper->prefetchFederatedProperties( $rows, [ 'en' ], [ TermTypes::TYPE_LABEL ] );
	}

	/**
	 * @dataProvider rowDataProvider
	 */
	public function testShouldExtractProperties( callable $rowsFactory, array $expectedProperties ) {
		$rows = $rowsFactory( $this );
		$helper = new SummaryParsingPrefetchHelper( $this->prefetchingLookup, new NullLogger() );
		$actualOutput = $helper->extractSummaryProperties( $rows );

		$this->assertSameSize( $expectedProperties, $actualOutput );

		$stringOutput = array_map( function ( $propId ) {
			return $propId->getSerialization();
		}, $actualOutput );

		$this->assertSame( sort( $expectedProperties ), sort( $stringOutput ) );
	}

	public static function rowDataProvider() {
		return [
			'Property:P1' => [
				fn () => [ (object)[ 'rev_comment_text' => '[[Property:P31]]' ] ],
				[ 'P31' ],
			],
			'wdbeta:Special:EntityPage/P123' => [
				fn () => [
					(object)[ 'rev_comment_text' => '[[wdbeta:Special:EntityPage/P123]]' ],
					(object)[ 'rev_comment_text' => '[[Property:P1234]]' ],
				],
				[ 'P123', 'P1234' ],
			],
			'Some other comment not parsed as link' => [
				fn () => [
					(object)[ 'rev_comment_text' => 'Great update /P14 stockholm' ],
					(object)[ 'rc_comment_text' => '[[P31]]' ],
					(object)[ 'rc_comment_text' => 'P31]]' ],
					(object)[ 'rc_comment_text' => '[P31:P31]' ],
				],
				[],
			],
			'Recentchanges object' => [
				fn () => [ (object)[ 'rc_comment_text' => '[[Property:P31]]' ] ],
				[ 'P31' ],
			],
			'RevisionRecord match' => [
				fn ( self $self ) => [ $self->mockRevisionRecord( 'something [[Property:P31]]' ) ],
				[ 'P31' ],
			],
			'RevisionRecord no match' => [
				fn ( self $self ) => [ $self->mockRevisionRecord( 'something [[P31]]' ) ],
				[],
			],
			'null' => [ fn () => [ (object)[ 'rc_comment_text' => null ] ], [] ],
		];
	}

	private function mockRevisionRecord( string $commentString ) {
		$mock = $this->createMock( RevisionRecord::class );
		$mock->method( 'getComment' )->willreturn( (object)[ 'text' => $commentString ] );
		return $mock;
	}

}
