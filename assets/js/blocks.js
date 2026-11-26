/**
 * Memberkin blocks (editor side). Plain JavaScript on WordPress's wp.* packages, no build step.
 * Metadata (names, attributes, titles) comes from /blocks/{name}/block.json, registered in PHP.
 * Blocks whose output depends on the visitor (checkout, account …) show a description in the editor;
 * the pricing table and level button show a live preview.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var data = window.memberkinBlocks || { levels: [], groups: [] };

	function levelOptions( emptyLabel ) {
		return [ { value: 0, label: emptyLabel } ].concat( data.levels.map( function ( l ) {
			return { value: l.id, label: l.name };
		} ) );
	}

	function levelChecklist( value, onChange, help ) {
		var chosen = value || [];
		return el( c.BaseControl, { label: __( 'Levels', 'memberkin' ), help: help, __nextHasNoMarginBottom: true },
			data.levels.length ? data.levels.map( function ( l ) {
				return el( c.CheckboxControl, {
					key: l.id,
					label: l.name,
					checked: chosen.indexOf( l.id ) !== -1,
					__nextHasNoMarginBottom: true,
					onChange: function ( on ) {
						onChange( on ? chosen.concat( [ l.id ] ) : chosen.filter( function ( id ) { return id !== l.id; } ) );
					}
				} );
			} ) : el( 'p', null, __( 'No levels yet.', 'memberkin' ) )
		);
	}

	function described( name, text ) {
		return function () {
			var props = be.useBlockProps();
			var type = wp.blocks.getBlockType( name );
			return el( 'div', props, el( c.Placeholder, {
				icon: type && type.icon ? type.icon.src : 'id',
				label: type ? type.title : name,
				instructions: text
			} ) );
		};
	}

	function preview( name ) {
		return function ( props ) {
			return el( ServerSideRender, { block: name, attributes: props.attributes } );
		};
	}

	// Pricing table.
	wp.blocks.registerBlockType( 'memberkin/levels', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( 'div', be.useBlockProps(),
				el( be.InspectorControls, null,
					el( c.PanelBody, { title: __( 'Levels to show', 'memberkin' ) },
						el( c.SelectControl, {
							label: __( 'Group', 'memberkin' ),
							value: a.group,
							options: [ { value: 0, label: __( 'All groups', 'memberkin' ) } ].concat( data.groups.map( function ( g ) {
								return { value: g.id, label: g.name };
							} ) ),
							onChange: function ( v ) { set( { group: parseInt( v, 10 ) || 0 } ); },
							__nextHasNoMarginBottom: true
						} ),
						levelChecklist( a.levels, function ( v ) { set( { levels: v } ); }, __( 'Tick levels to show only those, in this order. None ticked: every listed level (of the group).', 'memberkin' ) )
					)
				),
				preview( 'memberkin/levels' )( props )
			);
		},
		save: function () { return null; }
	} );

	// One level's button.
	wp.blocks.registerBlockType( 'memberkin/level', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( 'div', be.useBlockProps(),
				el( be.InspectorControls, null,
					el( c.PanelBody, { title: __( 'Level', 'memberkin' ) },
						el( c.SelectControl, {
							label: __( 'Level', 'memberkin' ),
							value: a.level,
							options: levelOptions( __( 'Choose a level', 'memberkin' ) ),
							onChange: function ( v ) { set( { level: parseInt( v, 10 ) || 0 } ); },
							__nextHasNoMarginBottom: true
						} ),
						el( c.TextControl, {
							label: __( 'Button text', 'memberkin' ),
							help: __( 'Empty: the level’s button text, else “Join now”.', 'memberkin' ),
							value: a.text,
							onChange: function ( v ) { set( { text: v } ); },
							__nextHasNoMarginBottom: true
						} ),
						el( c.ToggleControl, { label: __( 'Show the name', 'memberkin' ), checked: a.showName, onChange: function ( v ) { set( { showName: v } ); }, __nextHasNoMarginBottom: true } ),
						el( c.ToggleControl, { label: __( 'Show the price', 'memberkin' ), checked: a.showPrice, onChange: function ( v ) { set( { showPrice: v } ); }, __nextHasNoMarginBottom: true } )
					)
				),
				a.level ? preview( 'memberkin/level' )( props ) : el( c.Placeholder, { icon: 'button', label: __( 'Level button', 'memberkin' ), instructions: __( 'Choose a level in the block settings.', 'memberkin' ) } )
			);
		},
		save: function () { return null; }
	} );

	// Visitor-dependent pages.
	var pages = {
		checkout: __( 'Shows the checkout form for the level in the link (for example ?level=gold). Use it on your checkout page.', 'memberkin' ),
		confirmation: __( 'Shows the order and next steps after checkout. Use it on your confirmation page.', 'memberkin' ),
		account: __( 'Shows the member’s memberships, subscriptions and payments. Use it on your account page.', 'memberkin' ),
		profile: __( 'Lets members change their name, email, password and profile fields.', 'memberkin' ),
		login: __( 'Login form with a lost-password link.', 'memberkin' )
	};
	Object.keys( pages ).forEach( function ( key ) {
		wp.blocks.registerBlockType( 'memberkin/' + key, {
			edit: described( 'memberkin/' + key, pages[ key ] ),
			save: function () { return null; }
		} );
	} );

	// Members content.
	wp.blocks.registerBlockType( 'memberkin/members-content', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var mode = a.message === 'default' ? 'default' : ( a.message === '' ? 'none' : 'custom' );
			var who = {
				members: __( 'Members', 'memberkin' ),
				'non-members': __( 'Non-members', 'memberkin' ),
				'logged-in': __( 'Logged-in visitors', 'memberkin' ),
				'logged-out': __( 'Logged-out visitors', 'memberkin' )
			};
			var names = data.levels.filter( function ( l ) { return ( a.levels || [] ).indexOf( l.id ) !== -1; } ).map( function ( l ) { return l.name; } );
			var label = who[ a.showTo ] + ( ( a.showTo === 'members' || a.showTo === 'non-members' ) && names.length ? ' (' + names.join( ', ' ) + ')' : '' );
			var props2 = be.useBlockProps( { className: 'memberkin-members-content-editor' } );
			return el( Fragment, null,
				el( be.InspectorControls, null,
					el( c.PanelBody, { title: __( 'Who sees this', 'memberkin' ) },
						el( c.SelectControl, {
							label: __( 'Show to', 'memberkin' ),
							value: a.showTo,
							options: Object.keys( who ).map( function ( k ) { return { value: k, label: who[ k ] }; } ),
							onChange: function ( v ) { set( { showTo: v } ); },
							__nextHasNoMarginBottom: true
						} ),
						( a.showTo === 'members' || a.showTo === 'non-members' ) ? levelChecklist( a.levels, function ( v ) { set( { levels: v } ); }, __( 'None ticked: any level.', 'memberkin' ) ) : null
					),
					el( c.PanelBody, { title: __( 'Everyone else sees', 'memberkin' ) },
						el( c.RadioControl, {
							selected: mode,
							options: [
								{ value: 'default', label: __( 'The standard “members only” message with join links', 'memberkin' ) },
								{ value: 'custom', label: __( 'My own message', 'memberkin' ) },
								{ value: 'none', label: __( 'Nothing', 'memberkin' ) }
							],
							onChange: function ( v ) { set( { message: v === 'default' ? 'default' : ( v === 'none' ? '' : __( 'This part is for members.', 'memberkin' ) ) } ); }
						} ),
						mode === 'custom' ? el( c.TextareaControl, { label: __( 'Message', 'memberkin' ), value: a.message, onChange: function ( v ) { set( { message: v } ); }, __nextHasNoMarginBottom: true } ) : null
					)
				),
				el( 'div', props2,
					el( 'p', { className: 'memberkin-members-content-editor__label' }, el( c.Dashicon, { icon: 'visibility' } ), ' ', __( 'Visible to:', 'memberkin' ) + ' ' + label ),
					el( be.InnerBlocks, { templateLock: false } )
				)
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		}
	} );

}( window.wp ) );
