<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCaseRequestValidation;

use Generator;
use PHPUnit\Framework\TestCase;
use Wikibase\Repo\RestApi\Application\Serialization\AliasesInLanguageDeserializer;
use Wikibase\Repo\RestApi\Application\UseCaseRequestValidation\ItemAliasesInLanguageEditRequest;
use Wikibase\Repo\RestApi\Application\UseCaseRequestValidation\ItemAliasesInLanguageEditRequestValidatingDeserializer;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Application\Validation\AliasesInLanguageValidator;
use Wikibase\Repo\RestApi\Application\Validation\ValidationError;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCaseRequestValidation\ItemAliasesInLanguageEditRequestValidatingDeserializer
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ItemAliasesInLanguageEditRequestValidatingDeserializerTest extends TestCase {

	/**
	 * @dataProvider provideValidAliases
	 */
	public function testGivenValidRequest_returnsAliases( array $aliases, array $expectedDeserializedAliases ): void {
		$request = $this->createStub( ItemAliasesInLanguageEditRequest::class );
		$request->method( 'getLanguageCode' )->willReturn( 'en' );
		$request->method( 'getAliasesInLanguage' )->willReturn( $aliases );

		$this->assertSame(
			$expectedDeserializedAliases,
			$this->newRequestValidatingDeserializer()->validateAndDeserialize( $request )
		);
	}

	public function provideValidAliases(): Generator {
		yield 'valid aliases pass validation' => [
			[ 'first alias', 'second alias' ],
			[ 'first alias', 'second alias' ],
		];

		yield 'white space is trimmed from aliases' => [
			[ ' space at the start', "\ttab at the start", 'space at end ', "tab at end\t", "\t  multiple spaces and tabs \t" ],
			[ 'space at the start', 'tab at the start', 'space at end', 'tab at end', 'multiple spaces and tabs' ],
		];

		yield 'duplicates are removed' => [
			[ 'first alias', 'second alias', 'third alias', 'second alias' ],
			[ 'first alias', 'second alias', 'third alias' ],
		];
	}

	/**
	 * @dataProvider invalidAliasesProvider
	 */
	public function testWithInvalidAliases(
		UseCaseError $expectedException,
		array $aliases,
		ValidationError $validationError = null
	): void {
		$request = $this->createStub( ItemAliasesInLanguageEditRequest::class );
		$request->method( 'getLanguageCode' )->willReturn( 'en' );
		$request->method( 'getAliasesInLanguage' )->willReturn( $aliases );

		try {
			$this->newRequestValidatingDeserializer( $validationError )->validateAndDeserialize( $request );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $error ) {
			$this->assertEquals( $expectedException, $error );
		}
	}

	public static function invalidAliasesProvider(): Generator {
		yield 'alias list is associative array' => [
			UseCaseError::newInvalidValue( '/aliases' ),
			[ 'not' => 'a', 'sequential' => 'array' ],
		];

		yield 'alias list is empty' => [
			UseCaseError::newInvalidValue( '/aliases' ),
			[],
		];

		yield 'alias at position 0 is not a string' => [
			UseCaseError::newInvalidValue( '/aliases/0' ),
			[ 5675 ],
		];

		yield 'alias at position 1 is not a string' => [
			UseCaseError::newInvalidValue( '/aliases/1' ),
			[ 'alias', 1085 ],
		];

		yield 'alias at position 0 is empty' => [
			UseCaseError::newInvalidValue( '/aliases/0' ),
			[ '' ],
		];

		yield 'alias at position 1 is empty' => [
			UseCaseError::newInvalidValue( '/aliases/1' ),
			[ 'aka', '' ],
		];

		$alias = 'alias that is too long...';
		$limit = 40;
		yield 'alias too long' => [
			UseCaseError::newValueTooLong( '/aliases/0', $limit ),
			[ $alias ],
			new ValidationError(
				AliasesInLanguageValidator::CODE_TOO_LONG,
				[
					AliasesInLanguageValidator::CONTEXT_VALUE => $alias,
					AliasesInLanguageValidator::CONTEXT_LIMIT => $limit,
				]
			),
		];

		$invalidAlias = "tab characters \t not allowed";
		yield 'alias invalid' => [
			UseCaseError::newInvalidValue( '/aliases/0' ),
			[ $invalidAlias ],
			new ValidationError(
				AliasesInLanguageValidator::CODE_INVALID,
				[ AliasesInLanguageValidator::CONTEXT_VALUE => $invalidAlias ]
			),
		];
	}

	private function newRequestValidatingDeserializer(
		ValidationError $validationError = null
	): ItemAliasesInLanguageEditRequestValidatingDeserializer {
		$aliasesValidator = $this->createStub( AliasesInLanguageValidator::class );
		$aliasesValidator->method( 'validate' )->willReturn( $validationError );

		return new ItemAliasesInLanguageEditRequestValidatingDeserializer(
			new AliasesInLanguageDeserializer(),
			$aliasesValidator
		);
	}

}
