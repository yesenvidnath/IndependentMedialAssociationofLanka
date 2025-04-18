<?php
/**
 * File: PgCache_Environment.php
 *
 * @package W3TC
 */

namespace W3TC;

/**
 * Class PgCache_Environment
 *
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
 */
class PgCache_Environment {
	/**
	 * Fixes environment settings on WP admin request.
	 *
	 * @param Config $config         Configuration object containing environment settings.
	 * @param bool   $force_all_checks Whether to force all checks regardless of current configuration.
	 *
	 * @return void
	 *
	 * @throws \Util_Environment_Exceptions If there are unresolved exceptions during the fixing process.
	 */
	public function fix_on_wpadmin_request( $config, $force_all_checks ) {
		$exs             = new Util_Environment_Exceptions();
		$pgcache_enabled = $config->get_boolean( 'pgcache.enabled' );
		$engine          = $config->get_string( 'pgcache.engine' );

		if ( ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
			try {
				$this->wp_config_add_directive();
			} catch ( Util_WpFile_FilesystemOperationException $ex ) {
				$exs->push( $ex );
			}
		}

		$this->fix_folders( $config, $exs );

		if ( $config->get_boolean( 'config.check' ) || $force_all_checks ) {
			if ( $this->is_rules_required( $config ) ) {
				$this->rules_core_add( $config, $exs );
				$this->rules_cache_add( $config, $exs );
			} else {
				$this->rules_core_remove( $exs );
				$this->rules_cache_remove( $exs );
			}
		}

		// if no errors so far - check if rewrite actually works.
		if ( count( $exs->exceptions() ) <= 0 ) {
			try {
				if ( $pgcache_enabled && 'file_generic' === $engine ) {
					$this->verify_file_generic_compatibility();

					if ( $config->get_boolean( 'pgcache.debug' ) ) {
						$this->verify_file_generic_rewrite_working();
					}
				}
			} catch ( \Exception $ex ) {
				$exs->push( $ex );
			}
		}

		if ( count( $exs->exceptions() ) > 0 ) {
			throw $exs;
		}
	}

	/**
	 * Adjusts settings based on a specific event.
	 *
	 * @param Config      $config     W3TC Config containing relevant settings.
	 * @param string      $event      The name of the event triggering the adjustment.
	 * @param Config|null $old_config Optional. Previous W3TC Config object for comparison.
	 *
	 * @return void
	 */
	public function fix_on_event( $config, $event, $old_config = null ) {
		$pgcache_enabled = $config->get_boolean( 'pgcache.enabled' );
		$engine          = $config->get_string( 'pgcache.engine' );

		// Schedules events.
		if ( $pgcache_enabled && ( 'file' === $engine || 'file_generic' === $engine ) ) {
			$new_interval = $config->get_integer( 'pgcache.file.gc' );
			$old_interval = $old_config ? $old_config->get_integer( 'pgcache.file.gc' ) : -1;

			if ( null !== $old_config && $new_interval !== $old_interval ) {
				$this->unschedule_gc();
			}

			if ( ! wp_next_scheduled( 'w3_pgcache_cleanup' ) ) {
				wp_schedule_event( time(), 'w3_pgcache_cleanup', 'w3_pgcache_cleanup' );
			}
		} else {
			$this->unschedule_gc();
		}

		// Schedule prime event.
		if ( $pgcache_enabled && $config->get_boolean( 'pgcache.prime.enabled' ) ) {
			$new_interval = $config->get_integer( 'pgcache.prime.interval' );
			$old_interval = $old_config ? $old_config->get_integer( 'pgcache.prime.interval' ) : -1;

			if ( null !== $old_config && $new_interval !== $old_interval ) {
				$this->unschedule_prime();
			}

			if ( ! wp_next_scheduled( 'w3_pgcache_prime' ) ) {
				wp_schedule_event( time(), 'w3_pgcache_prime', 'w3_pgcache_prime' );
			}
		} else {
			$this->unschedule_prime();
		}
	}

	/**
	 * Fixes the environment after plugin deactivation.
	 *
	 * @return void
	 *
	 * @throws \Util_Environment_Exceptions If exceptions occur during cleanup.
	 */
	public function fix_after_deactivation() {
		$exs = new Util_Environment_Exceptions();

		try {
			$this->wp_config_remove_directive( $exs );
		} catch ( Util_WpFile_FilesystemOperationException $ex ) {
			$exs->push( $ex );
		}

		$this->rules_core_remove( $exs );
		$this->rules_cache_remove( $exs );

		$this->unschedule_gc();
		$this->unschedule_prime();
		$this->unschedule_purge_wpcron();

		if ( count( $exs->exceptions() ) > 0 ) {
			throw $exs;
		}
	}

	/**
	 * Determines if rules are required for the configuration.
	 *
	 * @since 0.9.7.3
	 *
	 * @param Config $c W3TC Config containing relevant settings.
	 *
	 * @return bool True if rules are required, false otherwise.
	 */
	private function is_rules_required( $c ) {
		$e = $c->get_string( 'pgcache.engine' );

		return $c->get_boolean( 'pgcache.enabled' ) && ( 'file_generic' === $e || 'nginx_memcached' === $e );
	}

	/**
	 * Retrieves the required rules for the environment.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 *
	 * @return array|null Array of required rules or null if none are required.
	 */
	public function get_required_rules( $config ) {
		if ( ! $this->is_rules_required( $config ) ) {
			return null;
		}

		$rewrite_rules           = array();
		$pgcache_rules_core_path = Util_Rule::get_pgcache_rules_core_path();
		$rewrite_rules[]         = array(
			'filename' => $pgcache_rules_core_path,
			'content'  => $this->rules_core_generate( $config ),
			'priority' => 1000,
		);

		$pgcache_rules_cache_path = Util_Rule::get_pgcache_rules_cache_path();
		$rewrite_rules[]          = array(
			'filename' => $pgcache_rules_cache_path,
			'content'  => $this->rules_cache_generate( $config ),
		);

		return $rewrite_rules;
	}

	/**
	 * Ensures folders meet the required settings for caching.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 * @param object $exs    Exception handler object.
	 *
	 * @return void
	 */
	private function fix_folders( $config, $exs ) {
		if ( ! $config->get_boolean( 'pgcache.enabled' ) ) {
			return;
		}

		// folder that we delete if exists and not writeable.
		if ( 'file_generic' === $config->get_string( 'pgcache.engine' ) ) {
			$dir = W3TC_CACHE_PAGE_ENHANCED_DIR;
		} elseif ( 'file' !== $config->get_string( 'pgcache.engine' ) ) {
			$dir = W3TC_CACHE_DIR . '/page';
		} else {
			return;
		}

		try {
			if ( file_exists( $dir ) && ! is_writeable( $dir ) ) {
				Util_WpFile::delete_folder( $dir, '', isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
			}
		} catch ( Util_WpFile_FilesystemRmdirException $ex ) {
			$exs->push( $ex );
		}
	}

	/**
	 * Verifies compatibility for file-based generic caching.
	 *
	 * @return void
	 *
	 * @throws \Util_Environment_Exception If the permalink structure is incompatible.
	 */
	private function verify_file_generic_compatibility() {
		$permalink_structure = get_option( 'permalink_structure' );

		if ( empty( $permalink_structure ) ) {
			throw new Util_Environment_Exception( 'Disk Enhanced mode can\'t work with "Default" permalinks structure' );
		}
	}

	/**
	 * Validates the functionality of file-based rewrite rules.
	 *
	 * @return void
	 *
	 * @throws \Util_Environment_Exception If rewrite rules are not functioning as expected.
	 */
	private function verify_file_generic_rewrite_working() {
		$url = get_home_url() . '/w3tc_rewrite_test' . wp_rand();
		if ( ! $this->test_rewrite( $url ) ) {
			$key    = sprintf( 'w3tc_rewrite_test_result_%s', substr( md5( $url ), 0, 16 ) );
			$result = get_transient( $key );

			$home_url = get_home_url();

			$tech_message =
				( Util_Environment::is_nginx() ? 'nginx configuration file' : '.htaccess file' ) .
				' contains rules to rewrite url ' . $home_url . '/w3tc_rewrite_test into ' .
				$home_url . '/?w3tc_rewrite_test which, if handled by plugin, return "OK" message.<br/>' .
				'The plugin made a request to ' . $home_url . '/w3tc_rewrite_test but received: <br />' .
				$result . '<br />instead of "OK" response. <br />';

			$error = '<strong>W3 Total Cache error:</strong> It appears Page Cache ' .
				'<acronym title="Uniform Resource Locator">URL</acronym> rewriting is not working. ';

			if ( Util_Environment::is_preview_mode() ) {
				$error .= ' This could be due to using Preview mode. <a href="' . $url .
					'">Click here</a> to manually verify its working. It should say OK. <br />';
			}

			if ( Util_Environment::is_nginx() ) {
				$error .= 'Please verify that all configuration files are ' .
					'included in the configuration file (and that you have reloaded / restarted nginx).';
			} else {
				$error .= 'Please verify that the server configuration allows .htaccess';
			}

			$error .= '<br />Unfortunately disk enhanced page caching will ' .
				'not function without custom rewrite rules. ' .
				'Please ask your server administrator for assistance. Also refer to <a href="' .
				admin_url( 'admin.php?page=w3tc_install' ) .
				'">the install page</a>  for the rules for your server.';

			throw new Util_Environment_Exception(
				esc_html( $error ),
				esc_html( $tech_message )
			);
		}
	}

	/**
	 * Tests the rewrite rule functionality.
	 *
	 * @param string $url URL to test.
	 *
	 * @return bool True if the rewrite works, false otherwise.
	 */
	private function test_rewrite( $url ) {
		$key    = sprintf( 'w3tc_rewrite_test_%s', substr( md5( $url ), 0, 16 ) );
		$result = get_transient( $key );

		if ( false === $result ) {

			$response = Util_Http::get( $url );

			$result = (
				! is_wp_error( $response ) &&
				200 === $response['response']['code'] &&
				'OK' === trim( $response['body'] )
			);

			if ( $result ) {
				set_transient( $key, $result, 30 );
			} else {
				$key_result = sprintf( 'w3tc_rewrite_test_result_%s', substr( md5( $url ), 0, 16 ) );
				set_transient(
					$key_result,
					is_wp_error( $response ) ? $response->get_error_message() : implode( ' ', $response['response'] ),
					30
				);
			}
		}

		return $result;
	}

	/**
	 * Unschedules the garbage collection task.
	 *
	 * @return void
	 */
	private function unschedule_gc() {
		if ( wp_next_scheduled( 'w3_pgcache_cleanup' ) ) {
			wp_clear_scheduled_hook( 'w3_pgcache_cleanup' );
		}
	}

	/**
	 * Unschedules the page cache priming task.
	 *
	 * @return void
	 */
	private function unschedule_prime() {
		if ( wp_next_scheduled( 'w3_pgcache_prime' ) ) {
			wp_clear_scheduled_hook( 'w3_pgcache_prime' );
		}
	}

	/**
	 * Unschedules the purge WP Cron task.
	 *
	 * @since 2.8.0
	 *
	 * @return void
	 */
	private function unschedule_purge_wpcron() {
		if ( wp_next_scheduled( 'w3tc_pgcache_purge_wpcron' ) ) {
			wp_clear_scheduled_hook( 'w3tc_pgcache_purge_wpcron' );
		}
	}

	/**
	 * Adds required directives to the wp-config.php file.
	 *
	 * @return void
	 *
	 * @throws \Util_WpFile_FilesystemModifyException If modifications to the file fail.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function wp_config_add_directive() {
		$config_path = Util_Environment::wp_config_path();

		$config_data = @file_get_contents( $config_path );
		if ( false === $config_data ) {
			return;
		}

		$new_config_data = $this->wp_config_remove_from_content( $config_data );
		$new_config_data = preg_replace(
			'~<\?(php)?~',
			"\\0\r\n" . $this->wp_config_addon(),
			$new_config_data,
			1
		);

		if ( $new_config_data !== $config_data ) {
			try {
				Util_WpFile::write_to_file( $config_path, $new_config_data );
			} catch ( Util_WpFile_FilesystemOperationException $ex ) {
				throw new Util_WpFile_FilesystemModifyException(
					$ex->getMessage(),
					$ex->credentials_form(),
					'Edit file <strong>' . $config_path . '</strong> and add next lines:',
					$config_path,
					$this->wp_config_addon()
				);
			}
		}
		// that file was in opcache for sure and it may take time to
		// start execution of new modified now version.
		$o = Dispatcher::component( 'SystemOpCache_Core' );
		$o->flush();
	}

	/**
	 * Removes added directives from the wp-config.php file.
	 *
	 * @return void
	 *
	 * @throws \Util_WpFile_FilesystemModifyException If modifications to the file fail.
	 */
	private function wp_config_remove_directive() {
		$config_path = Util_Environment::wp_config_path();

		$config_data = @file_get_contents( $config_path );
		if ( false === $config_data ) {
			return;
		}

		$new_config_data = $this->wp_config_remove_from_content( $config_data );
		if ( $new_config_data !== $config_data ) {
			try {
				Util_WpFile::write_to_file( $config_path, $new_config_data );
			} catch ( Util_WpFile_FilesystemOperationException $ex ) {
				throw new Util_WpFile_FilesystemModifyException(
					$ex->getMessage(),
					$ex->credentials_form(),
					'Edit file <strong>' . $config_path . '</strong> and remove next lines:',
					$config_path,
					$this->wp_config_addon()
				);
			}
		}
	}

	/**
	 * Generates the addon directive content for wp-config.php.
	 *
	 * @return string The directive content.
	 */
	private function wp_config_addon() {
		return "/** Enable W3 Total Cache */\r\ndefine('WP_CACHE', true); // Added by W3 Total Cache\r\n";
	}

	/**
	 * Removes W3TC directives from wp-config.php content.
	 *
	 * @param string $config_data The content of wp-config.php.
	 *
	 * @return string Modified content without W3TC directives.
	 */
	private function wp_config_remove_from_content( $config_data ) {
		$config_data = preg_replace(
			"~\\/\\*\\* Enable W3 Total Cache \\*\\*?\\/.*?\\/\\/ Added by W3 Total Cache(\r\n)*~s",
			'',
			$config_data
		);

		$config_data = preg_replace(
			"~(\\/\\/\\s*)?define\\s*\\(\\s*['\"]?WP_CACHE['\"]?\\s*,.*?\\)\\s*;+\\r?\\n?~is",
			'',
			$config_data
		);

		return $config_data;
	}

	/**
	 * Adds core rules to the configuration.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 * @param object $exs    Exception handler object.
	 *
	 * @return void
	 */
	private function rules_core_add( $config, $exs ) {
		Util_Rule::add_rules(
			$exs,
			Util_Rule::get_pgcache_rules_core_path(),
			$this->rules_core_generate( $config ),
			W3TC_MARKER_BEGIN_PGCACHE_CORE,
			W3TC_MARKER_END_PGCACHE_CORE,
			array(
				W3TC_MARKER_BEGIN_WORDPRESS        => 0,
				W3TC_MARKER_END_MINIFY_CORE        => strlen( W3TC_MARKER_END_MINIFY_CORE ) + 1,
				W3TC_MARKER_END_BROWSERCACHE_CACHE => strlen( W3TC_MARKER_END_BROWSERCACHE_CACHE ) + 1,
				W3TC_MARKER_END_PGCACHE_CACHE      => strlen( W3TC_MARKER_END_PGCACHE_CACHE ) + 1,
				W3TC_MARKER_END_MINIFY_CACHE       => strlen( W3TC_MARKER_END_MINIFY_CACHE ) + 1,
			),
			true
		);
	}

	/**
	 * Removes core rules from the configuration.
	 *
	 * @param object $exs Exception handler object.
	 *
	 * @return void
	 */
	private function rules_core_remove( $exs ) {
		Util_Rule::remove_rules(
			$exs,
			Util_Rule::get_pgcache_rules_core_path(),
			W3TC_MARKER_BEGIN_PGCACHE_CORE,
			W3TC_MARKER_END_PGCACHE_CORE
		);
	}

	/**
	 * Generates the core rules based on the server environment.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 *
	 * @return string Generated rules as a string.
	 */
	private function rules_core_generate( $config ) {
		switch ( true ) {
			case Util_Environment::is_apache():
			case Util_Environment::is_litespeed():
				return $this->rules_core_generate_apache( $config );

			case Util_Environment::is_nginx():
				return $this->rules_core_generate_nginx( $config );
		}

		return '';
	}

	/**
	 * Generates Apache rewrite rules for page caching.
	 *
	 * @param \W3TC\Config $config Configuration object containing various settings for the cache.
	 *
	 * @return string The generated Apache rewrite rules.
	 */
	private function rules_core_generate_apache( $config ) {
		$rewrite_base        = Util_Environment::network_home_url_uri();
		$cache_dir           = Util_Environment::normalize_path( W3TC_CACHE_PAGE_ENHANCED_DIR );
		$permalink_structure = get_option( 'permalink_structure' );

		$current_user = wp_get_current_user();

		// Auto reject cookies.
		$reject_cookies = array(
			'comment_author',
			'wp-postpass',
		);

		$reject_cookies[] = 'w3tc_logged_out';

		// Reject cache for logged in users OR Reject cache for roles if any.
		if ( $config->get_boolean( 'pgcache.reject.logged' ) ) {
			$reject_cookies = array_merge(
				$reject_cookies,
				array(
					'wordpress_logged_in',
				)
			);
		} elseif ( $config->get_boolean( 'pgcache.reject.logged_roles' ) ) {
			$new_cookies = array();
			foreach ( $config->get_array( 'pgcache.reject.roles' ) as $role ) {
				$new_cookies[] = 'w3tc_logged_' . md5( NONCE_KEY . $role );
			}

			$reject_cookies = array_merge( $reject_cookies, $new_cookies );
		}

		// Custom config.
		$reject_cookies = array_merge( $reject_cookies, $config->get_array( 'pgcache.reject.cookie' ) );
		Util_Rule::array_trim( $reject_cookies );

		$reject_user_agents = $config->get_array( 'pgcache.reject.ua' );
		if ( $config->get_boolean( 'pgcache.compatibility' ) ) {
			$reject_user_agents = array_merge( array( W3TC_POWERED_BY ), $reject_user_agents );
		}

		Util_Rule::array_trim( $reject_user_agents );

		// Generate directives.
		$env_W3TC_UA     = '';
		$env_W3TC_REF    = '';
		$env_W3TC_COOKIE = '';
		$env_W3TC_SSL    = '';
		$env_W3TC_ENC    = '';

		$rules  = '';
		$rules .= W3TC_MARKER_BEGIN_PGCACHE_CORE . "\n";
		$rules .= "<IfModule mod_rewrite.c>\n";
		$rules .= "    RewriteEngine On\n";
		$rules .= '    RewriteBase ' . $rewrite_base . "\n";

		if ( $config->get_boolean( 'pgcache.debug' ) ) {
			$rules .= "    RewriteRule ^(.*\\/)?w3tc_rewrite_test([0-9]+)/?$ $1?w3tc_rewrite_test=1 [L]\n";
		}

		// Set accept query strings.
		$w3tc_query_strings = apply_filters(
			'w3tc_pagecache_rules_apache_accept_qs',
			$config->get_array( 'pgcache.accept.qs' )
		);
		Util_Rule::array_trim( $w3tc_query_strings );

		if ( ! empty( $w3tc_query_strings ) ) {
			$w3tc_query_strings = str_replace( ' ', '+', $w3tc_query_strings );
			$w3tc_query_strings = array_map( array( '\W3TC\Util_Environment', 'preg_quote' ), $w3tc_query_strings );

			$rules .= "    RewriteRule ^ - [E=W3TC_QUERY_STRING:%{QUERY_STRING}]\n";

			foreach ( $w3tc_query_strings as $query ) {
				$query_rules = array();
				if ( strpos( $query, '=' ) === false ) {
					$query_rules[] = 'RewriteCond %{ENV:W3TC_QUERY_STRING} ^(.*?&|)' .
						$query . '(=[^&]*)?(&.*|)$ [NC]';
					$query_rules[] = 'RewriteRule ^ - [E=W3TC_QUERY_STRING:%1%3]';
				} else {
					$query_rules[] = 'RewriteCond %{ENV:W3TC_QUERY_STRING} ^(.*?&|)' .
						$query . '(&.*|)$ [NC]';
					$query_rules[] = 'RewriteRule ^ - [E=W3TC_QUERY_STRING:%1%2]';
				}

				$query_rules = apply_filters(
					'w3tc_pagecache_rules_apache_accept_qs_rules',
					$query_rules,
					$query
				);

				$rules .= '    ' . implode( "\n    ", $query_rules ) . "\n";
			}

			$rules .= "    RewriteCond %{ENV:W3TC_QUERY_STRING} ^&+$\n";
			$rules .= "    RewriteRule ^ - [E=W3TC_QUERY_STRING]\n";
		}

		// Check for mobile redirect.
		if ( $config->get_boolean( 'mobile.enabled' ) ) {
			$mobile_groups = $config->get_array( 'mobile.rgroups' );

			foreach ( $mobile_groups as $mobile_group => $mobile_config ) {
				$mobile_enabled  = ( isset( $mobile_config['enabled'] ) ? (bool) $mobile_config['enabled'] : false );
				$mobile_agents   = ( isset( $mobile_config['agents'] ) ? (array) $mobile_config['agents'] : '' );
				$mobile_redirect = ( isset( $mobile_config['redirect'] ) ? $mobile_config['redirect'] : '' );

				if ( $mobile_enabled && count( $mobile_agents ) && $mobile_redirect ) {
					$rules .= '    RewriteCond %{HTTP_USER_AGENT} (' . implode( '|', $mobile_agents ) . ") [NC]\n";
					$rules .= '    RewriteRule .* ' . $mobile_redirect . " [R,L]\n";
				}
			}
		}

		// Check for referrer redirect.
		if ( $config->get_boolean( 'referrer.enabled' ) ) {
			$referrer_groups = $config->get_array( 'referrer.rgroups' );

			foreach ( $referrer_groups as $referrer_group => $referrer_config ) {
				$referrer_enabled   = ( isset( $referrer_config['enabled'] ) ? (bool) $referrer_config['enabled'] : false );
				$referrer_referrers = ( isset( $referrer_config['referrers'] ) ? (array) $referrer_config['referrers'] : '' );
				$referrer_redirect  = ( isset( $referrer_config['redirect'] ) ? $referrer_config['redirect'] : '' );

				if ( $referrer_enabled && count( $referrer_referrers ) && $referrer_redirect ) {
					$rules .= '    RewriteCond %{HTTP_COOKIE} w3tc_referrer=.*(' . implode( '|', $referrer_referrers ) . ") [NC]\n";
					$rules .= '    RewriteRule .* ' . $referrer_redirect . " [R,L]\n";
				}
			}
		}

		// Set mobile groups.
		if ( $config->get_boolean( 'mobile.enabled' ) ) {
			$mobile_groups = array_reverse( $config->get_array( 'mobile.rgroups' ) );

			foreach ( $mobile_groups as $mobile_group => $mobile_config ) {
				$mobile_enabled  = ( isset( $mobile_config['enabled'] ) ? (bool) $mobile_config['enabled'] : false );
				$mobile_agents   = ( isset( $mobile_config['agents'] ) ? (array) $mobile_config['agents'] : '' );
				$mobile_redirect = ( isset( $mobile_config['redirect'] ) ? $mobile_config['redirect'] : '' );

				if ( $mobile_enabled && count( $mobile_agents ) && ! $mobile_redirect ) {
					$rules      .= '    RewriteCond %{HTTP_USER_AGENT} (' . implode( '|', $mobile_agents ) . ") [NC]\n";
					$rules      .= '    RewriteRule .* - [E=W3TC_UA:_' . $mobile_group . "]\n";
					$env_W3TC_UA = '%{ENV:W3TC_UA}';
				}
			}
		}

		// Set referrer groups.
		if ( $config->get_boolean( 'referrer.enabled' ) ) {
			$referrer_groups = array_reverse( $config->get_array( 'referrer.rgroups' ) );

			foreach ( $referrer_groups as $referrer_group => $referrer_config ) {
				$referrer_enabled   = ( isset( $referrer_config['enabled'] ) ? (bool) $referrer_config['enabled'] : false );
				$referrer_referrers = ( isset( $referrer_config['referrers'] ) ? (array) $referrer_config['referrers'] : '' );
				$referrer_redirect  = ( isset( $referrer_config['redirect'] ) ? $referrer_config['redirect'] : '' );

				if ( $referrer_enabled && count( $referrer_referrers ) && ! $referrer_redirect ) {
					$rules       .= '    RewriteCond %{HTTP_COOKIE} w3tc_referrer=.*(' . implode( '|', $referrer_referrers ) . ") [NC]\n";
					$rules       .= '    RewriteRule .* - [E=W3TC_REF:_' . $referrer_group . "]\n";
					$env_W3TC_REF = '%{ENV:W3TC_REF}';
				}
			}
		}

		// Set cookie group.
		if ( $config->get_boolean( 'pgcache.cookiegroups.enabled' ) ) {
			$cookie_groups = $config->get_array( 'pgcache.cookiegroups.groups' );

			foreach ( $cookie_groups as $group_name => $g ) {
				if ( isset( $g['enabled'] ) && $g['enabled'] ) {
					$cookies = array();
					foreach ( $g['cookies'] as $cookie ) {
						$cookie = trim( $cookie );
						if ( ! empty( $cookie ) ) {
							$cookie = str_replace( '+', ' ', $cookie );
							$cookie = Util_Environment::preg_quote( $cookie );
							if ( strpos( $cookie, '=' ) === false ) {
								$cookie .= '=.*';
							}
							$cookies[] = $cookie;
						}
					}

					if ( count( $cookies ) > 0 ) {
						$cookies_regexp  = '^(.*;\s*)?(' . implode( '|', $cookies ) . ')(\s*;.*)?$';
						$rules          .= "    RewriteCond %{HTTP_COOKIE} $cookies_regexp [NC]\n";
						$rules          .= '    RewriteRule .* - [E=W3TC_COOKIE:_' . $group_name . "]\n";
						$env_W3TC_COOKIE = '%{ENV:W3TC_COOKIE}';
					}
				}
			}
		}

		// Set HTTPS.
		if ( $config->get_boolean( 'pgcache.cache.ssl' ) ) {
			$rules       .= "    RewriteCond %{HTTPS} =on\n";
			$rules       .= "    RewriteRule .* - [E=W3TC_SSL:_ssl]\n";
			$rules       .= "    RewriteCond %{SERVER_PORT} =443\n";
			$rules       .= "    RewriteRule .* - [E=W3TC_SSL:_ssl]\n";
			$rules       .= "    RewriteCond %{HTTP:X-Forwarded-Proto} =https [NC]\n";
			$rules       .= "    RewriteRule .* - [E=W3TC_SSL:_ssl]\n";
			$env_W3TC_SSL = '%{ENV:W3TC_SSL}';
		}

		$cache_path = str_replace( Util_Environment::document_root(), '', $cache_dir );

		// Set Accept-Encoding gzip.
		if ( $config->get_boolean( 'browsercache.enabled' ) && $config->get_boolean( 'browsercache.html.compression' ) ) {
			$rules       .= "    RewriteCond %{HTTP:Accept-Encoding} gzip\n";
			$rules       .= "    RewriteRule .* - [E=W3TC_ENC:_gzip]\n";
			$env_W3TC_ENC = '%{ENV:W3TC_ENC}';
		}

		// Set Accept-Encoding brotli.
		if ( $config->get_boolean( 'browsercache.enabled' ) && $config->get_boolean( 'browsercache.html.brotli' ) ) {
			$rules       .= "    RewriteCond %{HTTP:Accept-Encoding} br\n";
			$rules       .= "    RewriteRule .* - [E=W3TC_ENC:_br]\n";
			$env_W3TC_ENC = '%{ENV:W3TC_ENC}';
		}

		$rules           .= "    RewriteCond %{HTTP_COOKIE} w3tc_preview [NC]\n";
		$rules           .= "    RewriteRule .* - [E=W3TC_PREVIEW:_preview]\n";
		$env_W3TC_PREVIEW = '%{ENV:W3TC_PREVIEW}';

		$rules         .= "    RewriteCond %{REQUEST_URI} \\/$\n";
		$rules         .= "    RewriteRule .* - [E=W3TC_SLASH:_slash]\n";
		$env_W3TC_SLASH = '%{ENV:W3TC_SLASH}';

		$use_cache_rules = '';

		// Don't accept POSTs.
		$use_cache_rules .= "    RewriteCond %{REQUEST_METHOD} !=POST\n";

		// Query string should be empty.
		$use_cache_rules .= empty( $w3tc_query_strings ) ?
			"    RewriteCond %{QUERY_STRING} =\"\"\n" :
			"    RewriteCond %{ENV:W3TC_QUERY_STRING} =\"\"\n";

		// Check for rejected cookies.
		$use_cache_rules .= '    RewriteCond %{HTTP_COOKIE} !(' . implode(
			'|',
			array_map(
				array(
					'\W3TC\Util_Environment',
					'preg_quote',
				),
				$reject_cookies
			)
		) . ") [NC]\n";

		// Check for rejected user agents.
		if ( count( $reject_user_agents ) ) {
			$use_cache_rules .= '    RewriteCond %{HTTP_USER_AGENT} !(' . implode(
				'|',
				array_map(
					array(
						'\W3TC\Util_Environment',
						'preg_quote',
					),
					$reject_user_agents
				)
			) . ") [NC]\n";
		}

		$use_cache_rules = apply_filters(
			'w3tc_pagecache_rules_apache_rewrite_cond',
			$use_cache_rules
		);

		// Make final rewrites for specific files.
		$uri_prefix = $cache_path . '/%{HTTP_HOST}/%{REQUEST_URI}/_index' . $env_W3TC_SLASH .
			$env_W3TC_UA . $env_W3TC_REF . $env_W3TC_COOKIE . $env_W3TC_SSL . $env_W3TC_PREVIEW;
		$uri_prefix = apply_filters( 'w3tc_pagecache_rules_apache_uri_prefix', $uri_prefix );

		$switch = ' -' . ( $config->get_boolean( 'pgcache.file.nfs' ) ? 'F' : 'f' );

		$document_root = Util_Rule::apache_docroot_variable();

		// write rule to rewrite to .html/.xml file.
		$exts = array( '.html' );
		if ( $config->get_boolean( 'pgcache.cache.nginx_handle_xml' ) ) {
			$exts[] = '.xml';
		}

		/**
		 * Filter: Allow adding additional rules at the end of the PGCACHE_CORE block, before the last rule.
		 *
		 * @since 2.7.1
		 *
		 * @param string $rules           Additional rules.
		 * @param string $use_cache_rules Rewrite conditions for non-POST, empty query string, rejected cookies, and rejected user agents.
		 * @param string $document_root   Document root.
		 * @param string $uri_prefix      URI prefix, after the "w3tc_pagecache_rules_apache_uri_prefix" WP filter.
		 * @param array  $exts            File extensions used; iterate to use them all.
		 * @param string $env_W3TC_ENC    Encoding string: "", "_br", or "_gzip".
		 */
		$rules = \apply_filters(
			'w3tc_pgcache_rules_apache_last',
			$rules,
			$use_cache_rules,
			$document_root,
			$uri_prefix,
			$env_W3TC_ENC
		);

		foreach ( $exts as $ext ) {
			$rules .= $use_cache_rules;

			$rules .= '    RewriteCond "' . $document_root . $uri_prefix . $ext . $env_W3TC_ENC .
				'"' . $switch . "\n";
			$rules .= '    RewriteRule .* "' . $uri_prefix . $ext . $env_W3TC_ENC . "\" [L]\n";
		}

		$rules .= "</IfModule>\n";

		$rules .= W3TC_MARKER_END_PGCACHE_CORE . "\n";

		return $rules;
	}

	/**
	 * Generates NGINX configuration rules for page cache.
	 *
	 * @param \W3TC\Config $config Configuration object containing various settings for the cache.
	 *
	 * @return string NGINX rules for page cache.
	 */
	private function rules_core_generate_nginx( $config ) {
		$is_network = Util_Environment::is_wpmu();

		$cache_dir           = Util_Environment::normalize_path( W3TC_CACHE_PAGE_ENHANCED_DIR );
		$permalink_structure = get_option( 'permalink_structure' );
		$pgcache_engine      = $config->get_string( 'pgcache.engine' );

		// Auto reject cookies.
		$reject_cookies = array(
			'comment_author',
			'wp-postpass',
		);

		if ( 'file_generic' === $pgcache_engine ) {
			$reject_cookies[] = 'w3tc_logged_out';
		}

		// Reject cache for logged in users OR Reject cache for roles if any.
		if ( $config->get_boolean( 'pgcache.reject.logged' ) ) {
			$reject_cookies = array_merge(
				$reject_cookies,
				array(
					'wordpress_logged_in',
				)
			);
		} elseif ( $config->get_boolean( 'pgcache.reject.logged_roles' ) ) {
			$new_cookies = array();
			foreach ( $config->get_array( 'pgcache.reject.roles' ) as $role ) {
				$new_cookies[] = 'w3tc_logged_' . md5( NONCE_KEY . $role );
			}
			$reject_cookies = array_merge( $reject_cookies, $new_cookies );
		}

		// Custom config.
		$reject_cookies = array_merge( $reject_cookies, $config->get_array( 'pgcache.reject.cookie' ) );
		Util_Rule::array_trim( $reject_cookies );

		$reject_user_agents = $config->get_array( 'pgcache.reject.ua' );
		if ( $config->get_boolean( 'pgcache.compatibility' ) ) {
			$reject_user_agents = array_merge( array( W3TC_POWERED_BY ), $reject_user_agents );
		}

		Util_Rule::array_trim( $reject_user_agents );

		// Generate rules.
		$env_w3tc_ua     = '';
		$env_w3tc_ref    = '';
		$env_w3tc_cookie = '';
		$env_w3tc_ssl    = '';
		$env_w3tc_ext    = '';
		$env_w3tc_enc    = '';
		$env_request_uri = '$request_uri';

		$rules  = '';
		$rules .= W3TC_MARKER_BEGIN_PGCACHE_CORE . "\n";
		if ( $config->get_boolean( 'pgcache.debug' ) ) {
			$rules .= "rewrite ^(.*\\/)?w3tc_rewrite_test([0-9]+)/?$ $1?w3tc_rewrite_test=1 last;\n";
		}

		// Set accept query strings.
		$w3tc_query_strings = apply_filters(
			'w3tc_pagecache_rules_nginx_accept_qs',
			$config->get_array( 'pgcache.accept.qs' )
		);

		Util_Rule::array_trim( $w3tc_query_strings );

		if ( ! empty( $w3tc_query_strings ) ) {
			$w3tc_query_strings = str_replace( ' ', '+', $w3tc_query_strings );
			$w3tc_query_strings = array_map( array( '\W3TC\Util_Environment', 'preg_quote' ), $w3tc_query_strings );

			$rules .= "set \$w3tc_query_string \$query_string;\n";

			foreach ( $w3tc_query_strings as $query ) {
				$query_rules = array();
				if ( strpos( $query, '=' ) === false ) {
					$query_rules[] = 'if ($w3tc_query_string ~* "^(.*?&|)' . $query . '(=[^&]*)?(&.*|)$") {';
					$query_rules[] = '    set $w3tc_query_string $1$3;';
					$query_rules[] = '}';
				} else {
					$query_rules[] = 'if ($w3tc_query_string ~* "^(.*?&|)' . $query . '(&.*|)$") {';
					$query_rules[] = '    set $w3tc_query_string $1$2;';
					$query_rules[] = '}';
				}

				$query_rules = apply_filters(
					'w3tc_pagecache_rules_nginx_accept_qs_rules',
					$query_rules,
					$query
				);

				$rules .= implode( "\n", $query_rules ) . "\n";
			}

			$rules .= "if (\$w3tc_query_string ~ ^[?&]+$) {\n";
			$rules .= "    set \$w3tc_query_string \"\";\n";
			$rules .= "}\n";

			$rules .= "set \$w3tc_request_uri \$request_uri;\n";
			$rules .= "if (\$w3tc_request_uri ~* \"^([^?]+)\?\") {\n";
			$rules .= "    set \$w3tc_request_uri \$1;\n";
			$rules .= "}\n";

			$env_request_uri = '$w3tc_request_uri';
		}

		// Check for mobile redirect.
		if ( $config->get_boolean( 'mobile.enabled' ) ) {
			$mobile_groups = $config->get_array( 'mobile.rgroups' );

			foreach ( $mobile_groups as $mobile_group => $mobile_config ) {
				$mobile_enabled  = ( isset( $mobile_config['enabled'] ) ? (bool) $mobile_config['enabled'] : false );
				$mobile_agents   = ( isset( $mobile_config['agents'] ) ? (array) $mobile_config['agents'] : '' );
				$mobile_redirect = ( isset( $mobile_config['redirect'] ) ? $mobile_config['redirect'] : '' );

				if ( $mobile_enabled && count( $mobile_agents ) && $mobile_redirect ) {
					$rules .= 'if ($http_user_agent ~* "(' . implode( '|', $mobile_agents ) . ")\") {\n";
					$rules .= '    rewrite .* ' . $mobile_redirect . " last;\n";
					$rules .= "}\n";
				}
			}
		}

		// Check for referrer redirect.
		if ( $config->get_boolean( 'referrer.enabled' ) ) {
			$referrer_groups = $config->get_array( 'referrer.rgroups' );

			foreach ( $referrer_groups as $referrer_group => $referrer_config ) {
				$referrer_enabled   = ( isset( $referrer_config['enabled'] ) ? (bool) $referrer_config['enabled'] : false );
				$referrer_referrers = ( isset( $referrer_config['referrers'] ) ? (array) $referrer_config['referrers'] : '' );
				$referrer_redirect  = ( isset( $referrer_config['redirect'] ) ? $referrer_config['redirect'] : '' );

				if ( $referrer_enabled && count( $referrer_referrers ) &&
					$referrer_redirect ) {
					$rules .= 'if ($http_cookie ~* "w3tc_referrer=.*(' . implode( '|', $referrer_referrers ) . ")\") {\n";
					$rules .= '    rewrite .* ' . $referrer_redirect . " last;\n";
					$rules .= "}\n";
				}
			}
		}

		// Don't accept POSTs.
		$rules .= "set \$w3tc_rewrite 1;\n";
		$rules .= "if (\$request_method = POST) {\n";
		$rules .= "    set \$w3tc_rewrite 0;\n";
		$rules .= "}\n";

		// Query string should be empty.
		$querystring_variable = ( empty( $w3tc_query_strings ) ? '$query_string' : '$w3tc_query_string' );

		$rules .= 'if (' . $querystring_variable . " != \"\") {\n";
		$rules .= "    set \$w3tc_rewrite 0;\n";
		$rules .= "}\n";

		$rules .= "set \$w3tc_slash \"\";\n";
		$rules .= "if ($env_request_uri ~ \\/$) {\n";
		$rules .= "    set \$w3tc_slash _slash;\n";
		$rules .= "}\n";

		$env_w3tc_slash = '$w3tc_slash';

		// Check for rejected cookies.
		$rules .= 'if ($http_cookie ~* "(' . implode(
			'|',
			array_map(
				array(
					'\W3TC\Util_Environment',
					'preg_quote',
				),
				$reject_cookies
			)
		) . ")\") {\n";
		$rules .= "    set \$w3tc_rewrite 0;\n";
		$rules .= "}\n";

		// Check for rejected user agents.
		if ( count( $reject_user_agents ) ) {
			$rules .= 'if ($http_user_agent ~* "(' . implode(
				'|',
				array_map(
					array(
						'\W3TC\Util_Environment',
						'preg_quote',
					),
					$reject_user_agents
				)
			) . ")\") {\n";
			$rules .= "    set \$w3tc_rewrite 0;\n";
			$rules .= "}\n";
		}

		// Check mobile groups.
		if ( $config->get_boolean( 'mobile.enabled' ) ) {
			$mobile_groups = array_reverse( $config->get_array( 'mobile.rgroups' ) );
			$set_ua_var    = true;

			foreach ( $mobile_groups as $mobile_group => $mobile_config ) {
				$mobile_enabled  = ( isset( $mobile_config['enabled'] ) ? (bool) $mobile_config['enabled'] : false );
				$mobile_agents   = ( isset( $mobile_config['agents'] ) ? (array) $mobile_config['agents'] : '' );
				$mobile_redirect = ( isset( $mobile_config['redirect'] ) ? $mobile_config['redirect'] : '' );

				if ( $mobile_enabled && count( $mobile_agents ) && ! $mobile_redirect ) {
					if ( $set_ua_var ) {
						$rules     .= "set \$w3tc_ua \"\";\n";
						$set_ua_var = false;
					}
					$rules .= 'if ($http_user_agent ~* "(' . implode( '|', $mobile_agents ) . ")\") {\n";
					$rules .= '    set $w3tc_ua _' . $mobile_group . ";\n";
					$rules .= "}\n";

					$env_w3tc_ua = '$w3tc_ua';
				}
			}
		}

		// Check for preview cookie.
		$rules .= "set \$w3tc_preview \"\";\n";
		$rules .= "if (\$http_cookie ~* \"(w3tc_preview)\") {\n";
		$rules .= "    set \$w3tc_preview _preview;\n";
		$rules .= "}\n";

		$env_w3tc_preview = '$w3tc_preview';

		// Check referrer groups.
		if ( $config->get_boolean( 'referrer.enabled' ) ) {
			$referrer_groups = array_reverse( $config->get_array( 'referrer.rgroups' ) );
			$set_ref_var     = true;
			foreach ( $referrer_groups as $referrer_group => $referrer_config ) {
				$referrer_enabled   = ( isset( $referrer_config['enabled'] ) ? (bool) $referrer_config['enabled'] : false );
				$referrer_referrers = ( isset( $referrer_config['referrers'] ) ? (array) $referrer_config['referrers'] : '' );
				$referrer_redirect  = ( isset( $referrer_config['redirect'] ) ? $referrer_config['redirect'] : '' );

				if ( $referrer_enabled && count( $referrer_referrers ) && ! $referrer_redirect ) {
					if ( $set_ref_var ) {
						$rules      .= "set \$w3tc_ref \"\";\n";
						$set_ref_var = false;
					}

					$rules .= 'if ($http_cookie ~* "w3tc_referrer=.*(' . implode( '|', $referrer_referrers ) . ")\") {\n";
					$rules .= '    set $w3tc_ref _' . $referrer_group . ";\n";
					$rules .= "}\n";

					$env_w3tc_ref = '$w3tc_ref';
				}
			}
		}

		// Set cookie group.
		if ( $config->get_boolean( 'pgcache.cookiegroups.enabled' ) ) {
			$cookie_groups  = $config->get_array( 'pgcache.cookiegroups.groups' );
			$set_cookie_var = true;

			foreach ( $cookie_groups as $group_name => $g ) {
				if ( isset( $g['enabled'] ) && $g['enabled'] ) {
					$cookies = array();
					foreach ( $g['cookies'] as $cookie ) {
						$cookie = trim( $cookie );
						if ( ! empty( $cookie ) ) {
							$cookie = str_replace( '+', ' ', $cookie );
							$cookie = Util_Environment::preg_quote( $cookie );
							if ( false === strpos( $cookie, '=' ) ) {
								$cookie .= '=.*';
							}

							$cookies[] = $cookie;
						}
					}

					if ( count( $cookies ) > 0 ) {
						$cookies_regexp = '"^(.*;)?(' . implode( '|', $cookies ) . ')(;.*)?$"';

						if ( $set_cookie_var ) {
							$rules         .= "set \$w3tc_cookie \"\";\n";
							$set_cookie_var = false;
						}

						$rules .= "if (\$http_cookie ~* $cookies_regexp) {\n";
						$rules .= '    set $w3tc_cookie _' . $group_name . ";\n";
						$rules .= "}\n";

						$env_w3tc_cookie = '$w3tc_cookie';
					}
				}
			}
		}

		if ( $config->get_boolean( 'pgcache.cache.ssl' ) ) {
			$rules .= "set \$w3tc_ssl \"\";\n";

			$rules .= "if (\$scheme = https) {\n";
			$rules .= "    set \$w3tc_ssl _ssl;\n";
			$rules .= "}\n";
			$rules .= "if (\$http_x_forwarded_proto = 'https') {\n";
			$rules .= "    set \$w3tc_ssl _ssl;\n";
			$rules .= "}\n";

			$env_w3tc_ssl = '$w3tc_ssl';
		}

		if ( $config->get_boolean( 'browsercache.enabled' ) && $config->get_boolean( 'browsercache.html.compression' ) ) {
			$rules .= "set \$w3tc_enc \"\";\n";

			$rules .= "if (\$http_accept_encoding ~ gzip) {\n";
			$rules .= "    set \$w3tc_enc _gzip;\n";
			$rules .= "}\n";

			$env_w3tc_enc = '$w3tc_enc';
		}

		if ( $config->get_boolean( 'browsercache.enabled' ) && $config->get_boolean( 'browsercache.html.brotli' ) ) {
			$rules .= "set \$w3tc_enc \"\";\n";

			$rules .= "if (\$http_accept_encoding ~ br) {\n";
			$rules .= "    set \$w3tc_enc _br;\n";
			$rules .= "}\n";

			$env_w3tc_enc = '$w3tc_enc';
		}

		$key_postfix = $env_w3tc_slash . $env_w3tc_ua . $env_w3tc_ref . $env_w3tc_cookie . $env_w3tc_ssl . $env_w3tc_preview;

		/**
		 * Filter: Allow modifying the key_postfix string used in the PGCACHE_CORE block.
		 *
		 * @since 2.7.1
		 *
		 * @param string $key_postfix Key postfix string.
		 */
		$key_postfix = \apply_filters( 'w3tc_pgcache_postfix_nginx', $key_postfix );

		if ( 'file_generic' === $pgcache_engine ) {
			$rules .= $this->for_file_generic(
				$config,
				$cache_dir,
				$env_request_uri,
				$key_postfix,
				$env_w3tc_enc
			);
		} elseif ( 'nginx_memcached' === $pgcache_engine ) {
			$rules .= $this->for_nginx_memcached(
				$config,
				$cache_dir,
				$env_request_uri,
				$key_postfix,
				$env_w3tc_enc
			);
		}

		$rules .= W3TC_MARKER_END_PGCACHE_CORE . "\n";

		return $rules;
	}

	/**
	 * Generates the cache rules for file-based generic cache handling.
	 *
	 * @param Config $config          W3TC Config containing relevant settings.
	 * @param string $cache_dir       The directory where cache files are stored.
	 * @param string $env_request_uri The request URI for the environment.
	 * @param string $key_postfix     The key postfix to be used in cache keys.
	 * @param string $env_w3tc_enc    The encoded string to append to cache file names.
	 *
	 * @return string The generated cache rules for nginx.
	 */
	private function for_file_generic( $config, $cache_dir, $env_request_uri, $key_postfix, $env_w3tc_enc ) {
		$rules = '';

		$cache_path = str_replace( Util_Environment::document_root(), '', $cache_dir );
		$uri_prefix = "$cache_path/\$http_host/$env_request_uri/_index$key_postfix";
		$uri_prefix = apply_filters( 'w3tc_pagecache_rules_nginx_uri_prefix', $uri_prefix );

		if ( ! $config->get_boolean( 'pgcache.cache.nginx_handle_xml' ) ) {
			$env_w3tc_ext = '.html';

			$rules .= 'if (!-f "$document_root' . $uri_prefix . '.html' . $env_w3tc_enc . '") {' . "\n";
			$rules .= '  set $w3tc_rewrite 0;' . "\n";
			$rules .= "}\n";
		} else {
			$env_w3tc_ext = '$w3tc_ext';

			$rules .= 'set $w3tc_ext "";' . "\n";
			$rules .= 'if (-f "$document_root' . $uri_prefix . '.html' . $env_w3tc_enc . '") {' . "\n";
			$rules .= '  set $w3tc_ext .html;' . "\n";
			$rules .= "}\n";

			$rules .= 'if (-f "$document_root' . $uri_prefix . '.xml' . $env_w3tc_enc . '") {' . "\n";
			$rules .= '    set $w3tc_ext .xml;' . "\n";
			$rules .= "}\n";

			$rules .= 'if ($w3tc_ext = "") {' . "\n";
			$rules .= '    set $w3tc_rewrite 0;' . "\n";
			$rules .= "}\n";
		}

		$rules .= 'if ($w3tc_rewrite = 1) {' . "\n";
		$rules .= '    rewrite .* "' . $uri_prefix . $env_w3tc_ext . $env_w3tc_enc . '" last;' . "\n";
		$rules .= "}\n";

		return $rules;
	}

	/**
	 * Generates the cache rules for nginx with memcached support.
	 *
	 * @param Config $config          W3TC Config containing relevant settings.
	 * @param string $cache_dir       The directory where cache files are stored.
	 * @param string $env_request_uri The request URI for the environment.
	 * @param string $key_postfix     The key postfix to be used in cache keys.
	 * @param string $env_w3tc_enc    The encoded string to append to cache file names.
	 *
	 * @return string The generated cache rules for nginx with memcached support.
	 */
	private function for_nginx_memcached( $config, $cache_dir, $env_request_uri, $key_postfix, $env_w3tc_enc ) {
		$rules  = "set \$request_uri_noslash $env_request_uri;\n";
		$rules .= "if ($env_request_uri ~ \"(.*?)(/+)$\") {\n";
		$rules .= '    set $request_uri_noslash $1;' . "\n";
		$rules .= "}\n";

		$cache_path = str_replace( Util_Environment::document_root(), '', $cache_dir );

		$rules .= 'location ~ ".*(?<!php)$" {' . "\n";
		$rules .= '  set $memcached_key "$http_host$request_uri_noslash/' . $key_postfix . $env_w3tc_enc . '";' . "\n";

		if ( $config->get_boolean( 'browsercache.enabled' ) && $config->get_boolean( 'browsercache.html.compression' ) ) {
			$rules .= '  memcached_gzip_flag 65536;' . "\n";
		}

		$rules .= '  default_type text/html;' . "\n";

		$memcached_servers = $config->get_array( 'pgcache.memcached.servers' );
		$memcached_pass    = ! empty( $memcached_servers ) ? array_values( $memcached_servers )[0] : 'localhost:11211';

		$rules .= '  if ($w3tc_rewrite = 1) {' . "\n";
		$rules .= '    memcached_pass ' . $memcached_pass . ';' . "\n";
		$rules .= "  }\n";
		$rules .= '  error_page     404 502 504 = @fallback;' . "\n";
		$rules .= "}\n";

		$rules .= 'location @fallback {' . "\n";
		$rules .= '  try_files $uri $uri/ $uri.html /index.php?$args;' . "\n";
		$rules .= "}\n";

		return $rules;
	}

	/**
	 * Adds cache rules to a given set of existing rules.
	 *
	 * @param Config                      $config W3TC Config containing relevant settings.
	 * @param Util_Environment_Exceptions $exs    The existing set of rules to which new cache rules will be added.
	 *
	 * @return void
	 */
	private function rules_cache_add( $config, $exs ) {
		Util_Rule::add_rules(
			$exs,
			Util_Rule::get_pgcache_rules_cache_path(),
			$this->rules_cache_generate( $config ),
			W3TC_MARKER_BEGIN_PGCACHE_CACHE,
			W3TC_MARKER_END_PGCACHE_CACHE,
			array(
				W3TC_MARKER_BEGIN_BROWSERCACHE_CACHE => 0,
				W3TC_MARKER_BEGIN_MINIFY_CORE        => 0,
				W3TC_MARKER_BEGIN_PGCACHE_CORE       => 0,
				W3TC_MARKER_BEGIN_WORDPRESS          => 0,
				W3TC_MARKER_END_MINIFY_CACHE         => strlen( W3TC_MARKER_END_MINIFY_CACHE ) + 1,
			)
		);
	}

	/**
	 * Removes cache rules from a given set of existing rules.
	 *
	 * @param Util_Environment_Exceptions $exs The existing set of rules from which cache rules will be removed.
	 *
	 * @return void
	 */
	private function rules_cache_remove( $exs ) {
		// apache's cache files are not used when core rules disabled.
		if ( ! Util_Environment::is_nginx() ) {
			return;
		}

		Util_Rule::remove_rules(
			$exs,
			Util_Rule::get_pgcache_rules_cache_path(),
			W3TC_MARKER_BEGIN_PGCACHE_CACHE,
			W3TC_MARKER_END_PGCACHE_CACHE
		);
	}

	/**
	 * Generates cache rules based on the environment (Apache or Nginx).
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 *
	 * @return string The generated cache rules based on the server environment.
	 */
	public function rules_cache_generate( $config ) {
		switch ( true ) {
			case Util_Environment::is_apache():
			case Util_Environment::is_litespeed():
				return $this->rules_cache_generate_apache( $config );

			case Util_Environment::is_nginx():
				return $this->rules_cache_generate_nginx( $config );
		}

		return '';
	}

	/**
	 * Generates cache rules for Apache-based environments.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 *
	 * @return string The generated cache rules for Apache-based environments.
	 */
	private function rules_cache_generate_apache( $config ) {
		$charset      = get_option( 'blog_charset' );
		$pingback_url = get_bloginfo( 'pingback_url' );

		$browsercache  = $config->get_boolean( 'browsercache.enabled' );
		$brotli        = ( $browsercache && $config->get_boolean( 'browsercache.html.brotli' ) );
		$compression   = ( $browsercache && $config->get_boolean( 'browsercache.html.compression' ) );
		$expires       = ( $browsercache && $config->get_boolean( 'browsercache.html.expires' ) );
		$lifetime      = ( $browsercache ? $config->get_integer( 'browsercache.html.lifetime' ) : 0 );
		$cache_control = ( $browsercache && $config->get_boolean( 'browsercache.html.cache.control' ) );
		$etag          = ( $browsercache && $config->get_integer( 'browsercache.html.etag' ) );
		$w3tc          = ( $browsercache && $config->get_integer( 'browsercache.html.w3tc' ) );
		$compatibility = $config->get_boolean( 'pgcache.compatibility' );

		$rules  = '';
		$rules .= W3TC_MARKER_BEGIN_PGCACHE_CACHE . "\n";
		if ( $compatibility ) {
			$rules .= "Options -MultiViews\n";

			// allow to read files by apache if they are blocked at some level above.
			$rules .= "<Files ~ \"\.(html|html_gzip|html_br|xml|xml_gzip|xml_br)$\">\n";

			if ( version_compare( Util_Environment::get_server_version(), '2.4', '>=' ) ) {
				$rules .= "  Require all granted\n";
			} else {
				$rules .= "  Order Allow,Deny\n";
				$rules .= "  Allow from all\n";
			}

			$rules .= "</Files>\n";

			if ( ! $etag ) {
				$rules .= "FileETag None\n";
			}
		}

		if ( $config->get_boolean( 'pgcache.file.nfs' ) ) {
			$rules .= "EnableSendfile Off \n";
		}

		if ( ! $config->get_boolean( 'pgcache.remove_charset' ) ) {
			$rules .= 'AddDefaultCharset ' . ( $charset ? $charset : 'utf-8' ) . "\n";
		}

		if ( $etag ) {
			$rules .= "FileETag MTime Size\n";
		}

		if ( $brotli ) {
			$rules .= "<IfModule mod_mime.c>\n";
			$rules .= "    AddType text/html .html_br\n";
			$rules .= "    AddEncoding br .html_br\n";
			$rules .= "    AddType text/xml .xml_br\n";
			$rules .= "    AddEncoding br .xml_br\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule mod_setenvif.c>\n";
			$rules .= "    SetEnvIfNoCase Request_URI \\.html_br$ no-brotli\n";
			$rules .= "    SetEnvIfNoCase Request_URI \\.xml_br$ no-brotli\n";
			$rules .= "</IfModule>\n";
		}

		if ( $compression ) {
			$rules .= "<IfModule mod_mime.c>\n";
			$rules .= "    AddType text/html .html_gzip\n";
			$rules .= "    AddEncoding gzip .html_gzip\n";
			$rules .= "    AddType text/xml .xml_gzip\n";
			$rules .= "    AddEncoding gzip .xml_gzip\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule mod_setenvif.c>\n";
			$rules .= "    SetEnvIfNoCase Request_URI \\.html_gzip$ no-gzip\n";
			$rules .= "    SetEnvIfNoCase Request_URI \\.xml_gzip$ no-gzip\n";
			$rules .= "</IfModule>\n";
		}

		if ( $expires ) {
			$rules .= "<IfModule mod_expires.c>\n";
			$rules .= "    ExpiresActive On\n";
			$rules .= '    ExpiresByType text/html M' . $lifetime . "\n";
			$rules .= "</IfModule>\n";
		}

		$header_rules = '';

		if ( $compatibility ) {
			$header_rules .= '    Header set X-Pingback "' . $pingback_url . "\"\n";
		}

		if ( $w3tc ) {
			$header_rules .= '    Header set X-Powered-By "' . Util_Environment::w3tc_header() . "\"\n";
		}

		if ( $expires ) {
			$header_rules .= "    Header set Vary \"Accept-Encoding, Cookie\"\n";
		}

		$set_last_modified = $config->get_boolean( 'browsercache.html.last_modified' );

		if ( ! $set_last_modified && $config->get_boolean( 'browsercache.enabled' ) ) {
			$header_rules .= "    Header unset Last-Modified\n";
		}

		if ( $cache_control ) {
			$cache_policy = $config->get_string( 'browsercache.html.cache.policy' );

			switch ( $cache_policy ) {
				case 'cache':
					$header_rules .= "    Header set Pragma \"public\"\n";
					$header_rules .= "    Header set Cache-Control \"public\"\n";
					break;

				case 'cache_public_maxage':
					$header_rules .= "    Header set Pragma \"public\"\n";

					if ( $expires ) {
						$header_rules .= "    Header append Cache-Control \"public\"\n";
					} else {
						$header_rules .= '    Header set Cache-Control "max-age=' . $lifetime . ", public\"\n";
					}
					break;

				case 'cache_validation':
					$header_rules .= "    Header set Pragma \"public\"\n";
					$header_rules .= "    Header set Cache-Control \"public, must-revalidate, proxy-revalidate\"\n";
					break;

				case 'cache_noproxy':
					$header_rules .= "    Header set Pragma \"public\"\n";
					$header_rules .= "    Header set Cache-Control \"private, must-revalidate\"\n";
					break;

				case 'cache_maxage':
					$header_rules .= "    Header set Pragma \"public\"\n";

					if ( $expires ) {
						$header_rules .= "    Header append Cache-Control \"public, must-revalidate, proxy-revalidate\"\n";
					} else {
						$header_rules .= '    Header set Cache-Control "max-age=' . $lifetime . ", public, must-revalidate, proxy-revalidate\"\n";
					}
					break;

				case 'no_cache':
					$header_rules .= "    Header set Pragma \"no-cache\"\n";
					$header_rules .= "    Header set Cache-Control \"private, no-cache\"\n";
					break;

				case 'no_store':
					$header_rules .= "    Header set Pragma \"no-store\"\n";
					$header_rules .= "    Header set Cache-Control \"no-store\"\n";
					break;
			}
		}

		if ( strlen( $header_rules ) > 0 ) {
			$rules .= "<IfModule mod_headers.c>\n";
			$rules .= $header_rules;
			$rules .= "</IfModule>\n";
		}

		$rules .= W3TC_MARKER_END_PGCACHE_CACHE . "\n";

		return $rules;
	}

	/**
	 * Generates cache rules for Nginx-based environments.
	 *
	 * @param Config $config W3TC Config containing relevant settings.
	 *
	 * @return string The generated cache rules for Nginx-based environments.
	 */
	private function rules_cache_generate_nginx( $config ) {
		if ( 'file_generic' !== $config->get_string( 'pgcache.engine' ) ) {
			return '';
		}

		$cache_root = Util_Environment::normalize_path( W3TC_CACHE_PAGE_ENHANCED_DIR );
		$cache_dir  = rtrim( str_replace( Util_Environment::document_root(), '', $cache_root ), '/' );

		if ( Util_Environment::is_wpmu() ) {
			$cache_dir = preg_replace( '~/w3tc.*?/~', '/w3tc.*?/', $cache_dir, 1 );
		}

		$browsercache = $config->get_boolean( 'browsercache.enabled' );
		$brotli       = ( $browsercache && $config->get_boolean( 'browsercache.html.brotli' ) );
		$compression  = ( $browsercache && $config->get_boolean( 'browsercache.html.compression' ) );

		$common_rules_a = Dispatcher::nginx_rules_for_browsercache_section( $config, 'html', true );
		$common_rules   = '';
		if ( ! empty( $common_rules_a ) ) {
			$common_rules = '    ' . implode( "\n    ", $common_rules_a ) . "\n";
		}

		$rules  = '';
		$rules .= W3TC_MARKER_BEGIN_PGCACHE_CACHE . "\n";

		if ( $brotli ) {
			$maybe_xml = '';
			if ( $config->get_boolean( 'pgcache.cache.nginx_handle_xml' ) ) {
				$maybe_xml = "\n        text/xml xml_br;\n    ";
			}

			$rules .= 'location ~ ' . $cache_dir . ".*br$ {\n";
			$rules .= "    brotli off;\n";
			$rules .= '    types {' . $maybe_xml . "}\n";
			$rules .= "    default_type text/html;\n";
			$rules .= "    add_header Content-Encoding br;\n";
			$rules .= $common_rules;
			$rules .= "}\n";
		}

		if ( $compression ) {
			$maybe_xml = '';
			if ( $config->get_boolean( 'pgcache.cache.nginx_handle_xml' ) ) {
				$maybe_xml = "\n        text/xml xml_gzip;\n    ";
			}

			$rules .= 'location ~ ' . $cache_dir . ".*gzip$ {\n";
			$rules .= "    gzip off;\n";
			$rules .= '    types {' . $maybe_xml . "}\n";
			$rules .= "    default_type text/html;\n";
			$rules .= "    add_header Content-Encoding gzip;\n";
			$rules .= $common_rules;
			$rules .= "}\n";
		}

		$rules .= W3TC_MARKER_END_PGCACHE_CACHE . "\n";

		return $rules;
	}
}
