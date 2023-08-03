<?php
/**
 * Setup WordPress files.
 *
 * @package tarosky/workflow
 */

$files = [
	'.editorconfig'         => 'https://raw.githubusercontent.com/ntwb/wordpress/master/.editorconfig',
	'phpcs.ruleset.xml'     => '',
	'phpunit.xml.dist'      => '',
	'.browserslistrc'       => '',
	'.eslintrc.json'        => '',
	'.gitignore'            => '',
	'.stylelintrc.json'     => '',
	'.wp-env.json'          => '',
	'tests/bootstrap.php'   => '',
	'tests/test-sample.php' => '',
	'package.json'          => '',
	'composer.json'         => '',
];

// Ensure directory exists.
if ( ! is_dir( 'tests' ) ) {
	if ( ! mkdir( 'tests', 0755, true ) ) {
		die( 'Failed to create tests directory.' );
	}
}

// Download all.
foreach ( $files as $name => $url ) {
	if ( file_exists( $name ) ) {
		printf( '%s already exists.' . PHP_EOL, $name );
		continue;
	}
	$prefix = 'https://raw.githubusercontent.com/tarosky/workflows/main/boilerplate/';
	if ( ! $url ) {
		$url = $prefix . $name;
	}
	file_put_contents( $name, file_get_contents( $url ) );
	printf( '%s created.' . PHP_EOL, $name );
}

// Replace project name.
$project_name = basename( __DIR__ );
foreach ( [ 'package.json', 'composer.json', 'tests/bootstrap.php' ] as $config ) {
	echo "Changing name...";
	if ( ! file_exists( $config ) ) {
		printf( '%s not found.' . PHP_EOL, $config );
		continue;
	}
	file_put_contents( $config, str_replace( '$PROJECT$', $project_name, file_get_contents( $config ) ) );
}
