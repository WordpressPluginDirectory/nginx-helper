<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    nginx-helper
 */

/**
 * Description of Predis_Purger
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 * @author     rtCamp
 */
class Predis_Purger extends Purger {

	/**
	 * Predis api object.
	 *
	 * @since    2.0.0
	 * @access   public
	 * @var      string    $redis_object    Predis api object.
	 */
	public $redis_object;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2.0.0
	 */
	public function __construct() {

		global $nginx_helper_admin;

		if ( ! class_exists( 'Predis\Autoloader' ) ) {
			require_once NGINX_HELPER_BASEPATH . 'admin/predis.php';
		}

		Predis\Autoloader::register();
		
		$predis_args = array();
		
		$username = $nginx_helper_admin->options['redis_username'];
		$password = $nginx_helper_admin->options['redis_password'];
		
		if( ! empty( $nginx_helper_admin->options['redis_unix_socket'] ) ) {
			$predis_args['path'] = $nginx_helper_admin->options['redis_unix_socket'];
		} else {
			$predis_args['host'] = $nginx_helper_admin->options['redis_hostname'];;
			$predis_args['port'] = $nginx_helper_admin->options['redis_port'];
		}
		
		if ( $username && $password ) {
			$predis_args['username'] = $username;
			$predis_args['password'] = $password;
		}
		
		// redis server parameter.
		$this->redis_object = new Predis\Client( $predis_args );

		try {
			$this->redis_object->connect();
			
			if( 0 !== $nginx_helper_admin->options['redis_database'] ) {
				$this->redis_object->select( $nginx_helper_admin->options['redis_database'] );
			}
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
			return;
		}
		

	}

	/**
	 * Purge all.
	 */
	public function purge_all() {

		global $nginx_helper_admin;

		$prefix = trim( $nginx_helper_admin->options['redis_prefix'] );

		$this->log( '* * * * *' );

		// If Purge Cache link click from network admin then purge all.
		if ( is_network_admin() ) {

			$this->delete_keys_by_wildcard( $prefix . '*' );
			$this->log( '* Purged Everything! * ' );

		} else { // Else purge only site specific cache.

			$parse         = wp_parse_url( get_home_url() );
			$parse['path'] = empty( $parse['path'] ) ? '/' : $parse['path'];
			$this->delete_keys_by_wildcard( $prefix . $parse['scheme'] . 'GET' . $parse['host'] . $parse['path'] . '*' );
			$this->log( '* ' . get_home_url() . ' Purged! * ' );

		}

		$this->log( '* * * * *' );

		/**
		 * Fire an action after the Redis cache has been purged.
		 *
		 * @since 2.1.0
		 */
		do_action( 'rt_nginx_helper_after_redis_purge_all' );
	}

	/**
	 * Purge url.
	 *
	 * @param string $url URL.
	 * @param bool   $feed Feed or not.
	 */
	public function purge_url( $url, $feed = true ) {

		global $nginx_helper_admin;

		/**
		 * Filters the URL to be purged.
		 *
		 * @since 2.1.0
		 *
		 * @param string $url URL to be purged.
		 */
		$url = apply_filters( 'rt_nginx_helper_purge_url', $url );

		$this->log( '- Purging URL | ' . $url );

		$parse = wp_parse_url( $url );

		if ( ! isset( $parse['path'] ) ) {
			$parse['path'] = '';
		}

		$prefix          = $nginx_helper_admin->options['redis_prefix'];
		$_url_purge_base = $prefix . $parse['scheme'] . 'GET' . $parse['host'] . $parse['path'];

		/**
		 * To delete device type caches such as `<URL>--mobile`, `<URL>--desktop`, `<URL>--lowend`, etc.
		 * This would need $url above to be changed with this filter `rt_nginx_helper_purge_url` by cache key that Nginx sets while generating cache.
		 *
		 * For example: If page is accessed from desktop, then cache will be generated by appending `--desktop` to current URL.
		 * Add this filter in separate plugin or simply in theme's function.php file:
		 * ```
		 * add_filter( 'rt_nginx_helper_purge_url', function( $url ) {
		 *      $url = $url . '--*';
		 *      return $url;
		 * });
		 * ```
		 *
		 * Regardless of what key / suffix is being to store `$device_type` cache , it will be deleted.
		 *
		 * @since 2.1.0
		 */
		if ( strpos( $_url_purge_base, '*' ) === false ) {

			$status = $this->delete_single_key( $_url_purge_base );

			if ( $status ) {
				$this->log( '- Purge URL | ' . $_url_purge_base );
			} else {
				$this->log( '- Cache Not Found | ' . $_url_purge_base, 'ERROR' );
			}
		} else {

			$status = $this->delete_keys_by_wildcard( $_url_purge_base );

			if ( $status ) {
				$this->log( '- Purge Wild Card URL | ' . $_url_purge_base . ' | ' . $status . ' url purged' );
			} else {
				$this->log( '- Cache Not Found | ' . $_url_purge_base, 'ERROR' );
			}
		}

	}

	/**
	 * Custom purge urls.
	 */
	public function custom_purge_urls() {

		global $nginx_helper_admin;

		$parse           = wp_parse_url( home_url() );
		$prefix          = $nginx_helper_admin->options['redis_prefix'];
		$_url_purge_base = $prefix . $parse['scheme'] . 'GET' . $parse['host'];

		$purge_urls = isset( $nginx_helper_admin->options['purge_url'] ) && ! empty( $nginx_helper_admin->options['purge_url'] ) ?
			explode( "\r\n", $nginx_helper_admin->options['purge_url'] ) : array();

		/**
		 * Allow plugins/themes to modify/extend urls.
		 *
		 * @param array $purge_urls URLs which needs to be purged.
		 * @param bool  $wildcard   If wildcard in url is allowed or not. default true.
		 */
		$purge_urls = apply_filters( 'rt_nginx_helper_purge_urls', $purge_urls, true );

		if ( is_array( $purge_urls ) && ! empty( $purge_urls ) ) {

			foreach ( $purge_urls as $purge_url ) {

				$purge_url = trim( $purge_url );

				if ( strpos( $purge_url, '*' ) === false ) {

					$purge_url = $_url_purge_base . $purge_url;
					$status    = $this->delete_single_key( $purge_url );
					if ( $status ) {
						$this->log( '- Purge URL | ' . $purge_url );
					} else {
						$this->log( '- Not Found | ' . $purge_url, 'ERROR' );
					}
				} else {

					$purge_url = $_url_purge_base . $purge_url;
					$status    = $this->delete_keys_by_wildcard( $purge_url );

					if ( $status ) {
						$this->log( '- Purge Wild Card URL | ' . $purge_url . ' | ' . $status . ' url purged' );
					} else {
						$this->log( '- Not Found | ' . $purge_url, 'ERROR' );
					}
				}
			}
		}

	}

	/**
	 * Single Key Delete Example
	 * e.g. $key can be nginx-cache:httpGETexample.com/
	 *
	 * @param string $key Key to delete cache.
	 *
	 * @return mixed
	 */
	public function delete_single_key( $key ) {

		try {
			return $this->redis_object->executeRaw( array( 'DEL', $key ) );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
		}

	}

	/**
	 * Delete Keys by wildcard.
	 * e.g. $key can be nginx-cache:httpGETexample.com*
	 *
	 * Lua Script block to delete multiple keys using wildcard
	 * Script will return count i.e. number of keys deleted
	 * if return value is 0, that means no matches were found
	 *
	 * Call redis eval and return value from lua script
	 *
	 * @param string $pattern Pattern.
	 *
	 * @return mixed
	 */
	public function delete_keys_by_wildcard( $pattern ) {

		// Lua Script.
		$lua = <<<LUA
local k =  0
for i, name in ipairs(redis.call('KEYS', KEYS[1]))
do
    redis.call('DEL', name)
    k = k+1
end
return k
LUA;

		try {
			return $this->redis_object->eval( $lua, 1, $pattern );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
		}

	}

}
