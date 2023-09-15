/*!
 * Image optimization
 */


import imagemin from 'imagemin';
import imageminJpegtran from "imagemin-jpegtran";
import imageminPngquant from "imagemin-pngquant";
import imageminGifsicle from "imagemin-gifsicle";
import imageminSvgo from "imagemin-svgo";

const [ node, js, src, dest ] = process.argv;


imagemin( [ src + '/**/*.{jpg,png,gif,svg}' ], {
	destination: dest,
	plugins: [
		imageminJpegtran(),
		imageminPngquant( { quality: [ 0.65, 0.8 ] } ),
		imageminGifsicle(),
		imageminSvgo()
	]
} ).then( ( files ) => {
	console.log( 'Images optimized', files.map( file => file.destinationPath ) );
} );
