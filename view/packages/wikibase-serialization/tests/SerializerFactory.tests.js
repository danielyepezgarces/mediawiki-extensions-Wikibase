/**
 * @licence GNU GPL v2+
 * @author H. Snater < mediawiki@snater.com >
 */

( function( $, wb, util, QUnit ) {
'use strict';

QUnit.module( 'wikibase.serialization.SerializerFactory' );

var SerializerFactory = wb.serialization.SerializerFactory,
	serializerFactory = new SerializerFactory();

QUnit.test( 'registerSerializer(), newSerializerFor()', function( assert ) {
	var testSets = [
		{
			Constructor: function Constructor1() {},
			Serializer: util.inherit( 'WbTestSerializer1', wb.serialization.Serializer, {} )
		}, {
			Constructor: function Constructor2() {},
			Serializer: util.inherit( 'WbTestSerializer2', wb.serialization.Serializer, {} )
		}
	];

	assert.throws(
		function() {
			SerializerFactory.registerSerializer( testSets[0].Serializer );
		},
		'Throwing error when omitting constructor a Serializer should be registered for.'
	);

	assert.throws(
		function() {
			SerializerFactory.registerSerializer( function() {}, testSets[0].Constructor );
		},
		'Throwing error when Serializer is not deriving from Serializer base constructor.'
	);

	SerializerFactory.registerSerializer( testSets[0].Serializer, testSets[0].Constructor );
	SerializerFactory.registerSerializer( testSets[1].Serializer, testSets[1].Constructor );

	assert.ok(
		serializerFactory.newSerializerFor( testSets[0].Constructor ).constructor
			=== testSets[0].Serializer,
		'Retrieved serializer by constructor.'
	);

	assert.ok(
		serializerFactory.newSerializerFor( new( testSets[0].Constructor ) ).constructor
			=== testSets[0].Serializer,
		'Retrieved serializer by object.'
	);

	assert.throws(
		function() {
			serializerFactory.newSerializerFor( 'string' );
		},
		'Throwing error when not passing a valid parameter to newSerializerFor().'
	);

	assert.throws(
		function() {
			serializerFactory.newSerializerFor( function() {} );
		},
		'Throwing error when no Serializer is registered for a constructor.'
	);
} );

}( jQuery, wikibase, util, QUnit ) );
