/**
 * Smoke test of assets/js/blocks.js without a browser: a minimal stand-in for the wp.* packages records what the
 * editor code builds. Checks that every block registers, every edit() and save() runs, and the expected controls
 * appear. Run: node tests/js/blocks.test.js
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );

const registered = {};
const comp = ( name ) => name; // Components are identified by name in the tree.
const el = ( type, props, ...children ) => ( { type, props: props || {}, children: children.flat() } );
const meta = {};
for ( const dir of fs.readdirSync( path.join( __dirname, '../../blocks' ) ) ) {
	const json = JSON.parse( fs.readFileSync( path.join( __dirname, '../../blocks', dir, 'block.json' ), 'utf8' ) );
	meta[ json.name ] = json;
}
global.window = {
	memberkinBlocks: { levels: [ { id: 3, name: 'Gold' }, { id: 4, name: 'Silver' } ], groups: [ { id: 1, name: 'Plans' } ], modules: { drip: true } },
	wp: {
		element: { createElement: el, Fragment: 'Fragment' },
		i18n: { __: ( s ) => s },
		blocks: {
			registerBlockType: ( name, settings ) => { registered[ name ] = settings; },
			getBlockType: ( name ) => ( { title: meta[ name ].title, icon: { src: meta[ name ].icon } } )
		},
		blockEditor: {
			useBlockProps: ( extra ) => Object.assign( { className: 'block' }, extra || {} ),
			InspectorControls: comp( 'InspectorControls' ),
			InnerBlocks: Object.assign( comp( 'InnerBlocks' ), { Content: comp( 'InnerBlocks.Content' ) } )
		},
		components: new Proxy( {}, { get: ( t, name ) => name } ),
		serverSideRender: comp( 'ServerSideRender' )
	}
};
global.wp = window.wp;
require( '../../assets/js/blocks.js' );

const text = ( node ) => {
	if ( node === null || node === undefined || node === false ) {
		return '';
	}
	if ( typeof node !== 'object' ) {
		return String( node );
	}
	const own = JSON.stringify( node.props, ( k, v ) => ( typeof v === 'function' ? undefined : v ) );
	return node.type + ' ' + own + ' ' + node.children.map( text ).join( ' ' );
};

// Every block.json has a client registration, and the other way round.
assert.deepStrictEqual( Object.keys( registered ).sort(), Object.keys( meta ).sort() );

const defaults = ( name ) => Object.fromEntries( Object.entries( meta[ name ].attributes || {} ).map( ( [ k, v ] ) => [ k, v.default ] ) );
const checks = {
	'memberkin/levels': [ 'ServerSideRender', 'All groups', 'Gold', 'Silver' ],
	'memberkin/level': [ 'Choose a level in the block settings', 'Button text', 'Show the price' ],
	'memberkin/checkout': [ 'Placeholder', 'checkout form' ],
	'memberkin/account': [ 'Placeholder', 'memberships, subscriptions' ],
	'memberkin/members-content': [ 'InnerBlocks', 'Visible to: Members', 'standard “members only” message', 'Gold' ],
	'memberkin/upcoming': [ 'Placeholder', 'Number of items' ]
};
for ( const name of Object.keys( registered ) ) {
	let set = {};
	const tree = registered[ name ].edit( { attributes: defaults( name ), setAttributes: ( v ) => { set = v; } } );
	const out = text( tree );
	for ( const needle of checks[ name ] || [ 'Placeholder' ] ) {
		assert.ok( out.includes( needle ), name + ' editor should contain "' + needle + '"' );
	}
	const saved = registered[ name ].save();
	if ( name === 'memberkin/members-content' ) {
		assert.strictEqual( saved.type, 'InnerBlocks.Content', 'members content saves its inner blocks' );
	} else {
		assert.strictEqual( saved, null, name + ' is rendered on the server' );
	}
	void set;
}

// A chosen level switches the level block to a live preview; members content names the chosen levels.
const level = text( registered[ 'memberkin/level' ].edit( { attributes: Object.assign( defaults( 'memberkin/level' ), { level: 3 } ), setAttributes: () => {} } ) );
assert.ok( level.includes( 'ServerSideRender' ) );
const mc = text( registered[ 'memberkin/members-content' ].edit( { attributes: Object.assign( defaults( 'memberkin/members-content' ), { levels: [ 4 ], showTo: 'non-members', message: '' } ), setAttributes: () => {} } ) );
assert.ok( mc.includes( 'Visible to: Non-members (Silver)' ) );
assert.ok( ! mc.includes( 'Only after (days of membership)' ), 'the delay applies to members only' );

console.log( 'blocks.js: ' + Object.keys( registered ).length + ' blocks registered, all editors render' );
