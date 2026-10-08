<?php
/**
 * Plugin Name: Fixture
 */

add_filter( 'plugin_locale', 'fixture_locale', 10, 2 );
add_filter( 'get_next_post_where', 'fixture_where' );

load_plugin_textdomain( 'fixture' );

if ( ! function_exists( 'removed_core_function' ) ) {
	function removed_core_function() {
		return true;
	}
}

removed_core_function();
