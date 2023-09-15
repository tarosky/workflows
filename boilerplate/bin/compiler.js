/**
 * Build JS.
 *
 * @see https://github.com/tarosky/workflows/tree/main/boilerplate/bin
 */

const fs = require( 'fs' );
const { glob } = require( 'glob' )
const { exec, execSync } = require( 'child_process' )
const { dumpSetting } = require( '@kunoichi/grab-deps' );

const command = process.argv[ 2 ];

/**
 * @typedef Path
 * @type {object}
 * @property {string} dir    - Parent directory.
 * @property {number} order  - Order. Lower processed first.
 * @property {string[]} path - Array of file path.
 */

/**
 * Add path by order.
 *
 * @param {Path[]} paths
 * @param {string}   newPath
 * @return {void}
 */
const addPath = ( paths, newPath ) => {
	const pathParts = newPath.split( '/' ).filter( path => path.length >= 1 );
	const length = pathParts.length;
	const lastName = pathParts.pop();
	const parentDir = pathParts.join( '/' );
	let index = -1;
	for ( let i = 0; i < paths.length; i++ ) {
		if ( paths[ i ].dir === parentDir ) {
			index = i;
			break;
		}
	}
	if ( 0 <= index ) {
		// Already exists.
		paths[ index ].path.push( [ parentDir, lastName ].join( '/' ) );
	} else {
		// Add new path.
		paths.push( {
			dir: parentDir,
			order: length,
			path: [ [ parentDir, lastName ].join( '/' ) ],
		} );
	}
	paths.sort( ( a, b ) => {
		if ( a.order === b.order ) {
			return 0;
		} else {
			return a.order < b.order ? -1 : 1;
		}
	} );
}

/**
 * Extract license header from JS file.
 *
 * @param {string} path
 * @param {string} src
 * @param {string} dest
 * @return {boolean}
 */
const extractHeader = ( path, src, dest ) => {
	const target = path.replace( src, dest ) + '.LICENSE.txt';
	const content = fs.readFileSync( path, 'utf8' );
	if ( !content ) {
		return false;
	}
	const match = content.match( /^(\/\*{1,2}!.*?\*\/)/ms );
	if ( !match ) {
		return false;
	}
	fs.writeFileSync( target, match[ 1 ] );
}

switch ( command ) {
	case 'env':
		const setting = require( '../.wp-env.json' );
		if ( ! setting.plugins.length ) {
			console.error( 'No plugin found.' );
			return;
		}
		setting.plugins.map( ( plugin ) => {
			if ( ! /^https:\/\//.test( plugin ) ) {
				console.log( `plugin "${plugin}" is not URL.` );
				return;
			}
			console.log( `Installing plugin "${plugin}"...` );
			const lastName = plugin.split( '/' ).pop();
			if ( /wordpress\.org/.test( plugin ) ) {
				// This is public repo.
				exec( `curl ${plugin} -L -o ./${lastName} && unzip ./${lastName} -d ./wordpress/wp-content/plugins && rm -rf ./${lastName}` );
			} else {
				// This is private repo.
				const dir = lastName.replace( '.zip', '' );
				exec( `curl ${plugin} -L -o ./${lastName} && unzip ./${lastName} -d ./wordpress/wp-content/plugins/${dir}/ && rm -rf ./${lastName}` );
			}
		} );
		break;
	case 'dump':
		dumpSetting( 'build' );
		console.log( 'wp-dependencies.json updated.' );
		break;
	case 'block':
		console.log( 'Building blocks...' );
		const blockName = process.argv[ 3 ];
		execSync( `wp-create-block ${ blockName } --no-plugin --no-wp-scripts` );
		execSync( `mv ./${ blockName } src/blocks/${ blockName }` );
		console.log( 'Block Generated.' );
		break;
	case 'js':
	default:
		console.log( 'Building js files...' );
		glob( [ 'src/js/**/*.js' ] ).then( res => {
			/** @type {Path[]} paths */
			const paths = [];
			res.map( ( path ) => {
				addPath( paths, path );
			} );
			paths.forEach( ( p ) => {
				try {
					execSync( `wp-scripts build ${ p.path.join( ' ' ) } --output-path=${ p.dir.replace( 'src/js', 'build/js' ) }` );
				} catch ( e ) {
					console.error( e );
				}
			} );
			// Remove all block json.
			glob( 'build/js/**/blocks' ).then( res => {
				execSync( `rm -rf ${ res.join( ' ' ) }` );
			} );
			// Put license.txt.
			glob( [ 'src/js/**/*.js' ] ).then( res => {
				res.map( ( path ) => {
					extractHeader( path, 'src/js', 'build/js' );
				} );
			} );
			console.log( 'Done.' );
		} );
		break;
}

