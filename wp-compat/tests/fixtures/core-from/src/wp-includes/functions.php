<?php
function wp_will_be_removed() {}

/**
 * @access private
 */
function wp_private_removed() {}

function wp_will_be_deprecated() {}

class WP_Thing {
	public function stays() {}
	public function goes() {}
	private function hidden() {}
}

class WP_Child extends WP_Thing {
	public function moved_to_parent() {}
}

function fire() {
	do_action( 'old_action' );
	apply_filters( 'kept_filter', 1 );
	apply_filters( "dynamic_{$x}", 1 );
	$f = function () {};
}
