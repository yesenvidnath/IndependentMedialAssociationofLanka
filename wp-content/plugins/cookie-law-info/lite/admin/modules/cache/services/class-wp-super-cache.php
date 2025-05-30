<?php
/**
 * Abstract class to handle all the modules on the plugin.
 *
 * @package CookieYes\Lite\Includes
 */

namespace CookieYes\Lite\Admin\Modules\Cache\Services;

use CookieYes\Lite\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
class Wp_Super_Cache extends Services {

	/**
	 * Load hooks of each plugin.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_hooks();
	}

	/**
	 * Check if the the cache service is installed/active;
	 *
	 * @return boolean
	 */
	public function is_active() {
		return function_exists( 'wp_cache_clean_cache' );
	}

	/**
	 * Clear the cache if any.
	 *
	 * @return boolean
	 */
	public function clear_cache( $clear = true ) {
		if ( $clear === false ) {
			return;
		}
		global $file_prefix, $supercachedir;
		if ( empty( $supercachedir ) && function_exists( 'get_supercache_dir' ) ) {
			$supercachedir = get_supercache_dir();
		}
		return wp_cache_clean_cache( $file_prefix );
	}

}
