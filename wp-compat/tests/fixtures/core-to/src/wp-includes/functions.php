<?php
/**
 * @deprecated 9.9.0
 */
function wp_will_be_deprecated() {
	_deprecated_function( __FUNCTION__, '9.9.0', 'wp_new' );
}

class WP_Thing {
	public function stays() {}
	public function moved_to_parent() {}
}

class WP_Child extends WP_Thing {
	public function legacy() {
		_deprecated_function( __METHOD__, '9.9.0' );
	}
}

function fire() {
	apply_filters( 'kept_filter', 1 );
	apply_filters_deprecated( 'old_filter', [ 1 ], '9.9.0', 'kept_filter' );
}
