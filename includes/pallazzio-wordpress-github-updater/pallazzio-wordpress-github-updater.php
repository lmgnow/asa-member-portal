<?php
/**
 * Allows WordPress plugins hosted on GitHub to be updated automatically
 *
 * @link https://github.com/pallazzio/pallazzio-wordpress-github-updater/
 */

// if this file is called directly, abort
if ( ! defined( 'WPINC' ) ) die;

if ( ! class_exists( 'Pallazzio_WordPress_GitHub_Updater' ) ) :

class Pallazzio_WordPress_GitHub_Updater {
	private $plugin          = null; // string e.g. 'plugin-dir/plugin-file.php'
	private $plugin_path     = null; // string e.g. '/home/user/public_html/wp-content/plugins/plugin-dir'
	private $plugin_file     = null; // string e.g. '/home/user/public_html/wp-content/plugins/plugin-dir/plugin-file.php'
	private $github_user     = null; // string e.g. 'pallazzio'
	private $github_repo     = null; // string e.g. 'plugin-dir'
	private $github_response = null; // array  Info about new version from GitHub.
	private $access_token    = null; // string Optional. For private GitHub repo.
	private $plugin_data     = null; // array  Info about currently installed version.
	private $plugin_active   = null; // bool

	/**
	 * Class constructor.
	 *
	 * @param  string $plugin_file
	 * @param  string $github_user
	 * @param  string $access_token Optional.
	 */
	function __construct( $plugin_file, $github_user, $access_token = null ) {
		$this->plugin       = plugin_basename( $plugin_file );
		$this->plugin_file  = $plugin_file;
		$this->github_user  = $github_user;
		$this->github_repo  = current( explode( '/', $this->plugin ) );
		$this->access_token = $access_token;

		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ),      10, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
		add_filter( 'upgrader_pre_install',                  array( $this, 'pre_install'  ),     10, 3 );
		add_filter( 'upgrader_post_install',                 array( $this, 'post_install' ),     10, 3 );
	}

	/**
	 * Queries the GitHub API for information about the latest release.
	 *
	 * @param  string $github_user
	 * @param  string $github_repo
	 * @param  string $access_token Optional.
	 * @return object $github_response
	 */
	private function github_api_fetch( $github_user, $github_repo, $access_token = null ) {
		$url = 'https://api.github.com/repos/' . $github_user . '/' . $github_repo . '/releases';

		$url = ! empty( $access_token ) ? add_query_arg( array( 'access_token' => $access_token ), $url ) : $url;

		$github_response = json_decode( wp_remote_retrieve_body( wp_remote_get( $url ) ) );
		error_log( $url );

		$github_response = is_array( $github_response ) ? current( $github_response ) : $github_response;

		$matches = null;
		preg_match( '/tested:\s([\d\.]+)/i', $github_response->body, $matches );
		if ( is_array( $matches ) && count( $matches ) > 1 ) {
			$github_response->tested = $matches[ 1 ];
		}

		return $github_response;
	}

	/**
	 * Displays plugin info in the 'View Details' popup.
	 *
	 * @param  object $result
	 * @return object
	 */
	public function plugin_info( $result, $action = null, $args = null ) {
		// TODO: add plugin info for 'View Details' popup
		return $result;
	}

	/**
	 * Adds info to the plugin update transient.
	 *
	 * @param  object $transient
	 * @return object $transient
	 */
	public function modify_transient( $transient ) {
		// if it was already set, don't do it again ( because this function can be called multiple times during a sigle page load )
		if ( isset( $transient->response[ $this->plugin ] ) ) return $transient;

		$last_github_call_time = get_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater_Time' );

		if ( $last_github_call_time && time() - $last_github_call_time < 60 * 60 * 6 ) { // don't query github more than once every six hours

			if ( ! empty( get_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater' ) ) ) {

				// use the stored info rather than querying GitHub
				$transient->response[ $this->plugin ] = json_decode( get_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater' ) );

			} else {
				
				unset( $transient->response[ $this->plugin ] );

			}

		} else {

			$this->plugin_data     = get_plugin_data( $this->plugin_file );
			$this->github_response = empty( $this->github_response ) ? $this->github_api_fetch( $this->github_user, $this->github_repo, $this->access_token ) : null;

			update_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater_Time', time() );

			if ( 1 !== version_compare( $this->github_response->tag_name, $this->plugin_data[ 'Version' ] ) ) {

				// clear stored info because it may still contain the old version
				update_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater', '' );
				return $transient;

			}

			$obj              = new stdClass();
			$obj->slug        = $this->github_repo;
			$obj->plugin      = $this->plugin;
			$obj->url         = $this->plugin_data[ 'PluginURI' ];
			$obj->new_version = $this->github_response->tag_name;
			$obj->package     = $this->github_response->zipball_url;
			$obj->tested      = isset( $this->github_response->tested ) ? $this->github_response->tested : '';

			// add this plugin to the site transient
			$transient->response[ $this->plugin ] = $obj;

			// store this plugin transient object locally so it can be used again without querying GitHub
			update_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater', wp_json_encode( $obj ) );

		}

		return $transient;
	}

	/**
	 * Registers the state of the plugin before updating so that it can be set to the same state afterwards.
	 *
	 * @return null
	 */
	public function pre_install( $true, $args ) {
		$this->plugin_active = is_plugin_active( $this->plugin );
	}

	/**
	 * Modifies the internal location pointer and moves the files from the GitHub dir name to the WordPress dir name.
	 *
	 * @param  array $result
	 * @return array
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$this->plugin_path = rtrim( plugin_dir_path( $this->plugin_file ), '/' );
		$wp_filesystem->move( $result[ 'destination' ], $this->plugin_path );
		$result[ 'destination' ] = $this->plugin_path;

		// get any submodules that may be part of the plugin
		$gitmodules_file = $this->plugin_path . '/.gitmodules';
		if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
			$this->get_modules( $modules );
		}

		// reactivate plugin if necessary
		if ( $this->plugin_active ) {
			$activate = activate_plugin( $this->plugin );
		}
		
		//switch_theme( 'skeleton' );

		// clear stored info so it won't still contain the old version
		update_option( $this->github_repo . '_Pallazzio_WordPress_GitHub_Updater', '' );

		return $result;
	}

	/**
	 * Downloads, unzips, and moves GitHub submodules to thier proper location.
	 * This only works with public GitHub repos.
	 *
	 * @param  array  $modules
	 * @param  string $module_path Optional.
	 * @return null
	 */
	private function get_modules( $modules, $module_path = null ) {
		global $wp_filesystem;

		foreach ( $modules as $module ) {
			$module_r    = explode( '/', $module[ 'url' ] );
			$github_repo = array_pop( $module_r );
			$github_user = array_pop( $module_r );

			$github_response = $this->github_api_fetch( $github_user, $github_repo );

			$temp_filename = $this->plugin_path . '/' . $github_repo . '.zip';

			wp_remote_get( $github_response->zipball_url, array(
				'stream'   => true,
				'filename' => $temp_filename,
			) );

			// prepend path if submodule nesting level is deeper than 1
			$module[ 'path' ] = ! empty ( $module_path ) ? $module_path . '/' . $module[ 'path' ] : $module[ 'path' ];

			// unzip and rename dir
			$destination = $this->plugin_path . '/' . substr( $module[ 'path' ], 0, strrpos( $module[ 'path' ], '/' ) ); // no trailing slash
			unzip_file( $temp_filename, $destination );
			$wp_filesystem->delete( $temp_filename );
			$dirs = glob( $destination . '/*', GLOB_ONLYDIR );
			foreach ( $dirs as $dir ) {
				if ( false !== strpos( $dir, $github_user . '-' . $github_repo ) ) {
					$wp_filesystem->move( $dir, $destination . '/' . $github_repo, true );
				}
			}

			// yo dawg, I heard you like submodules, so I submoduled some submodules into your submodule so you can submodule while you submodule
			// recurse if the submodule has submodules of its own
			$gitmodules_file = $this->plugin_path . '/' . $module[ 'path' ] . '/.gitmodules';
			if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
				$this->get_modules( $modules, $module[ 'path' ] );
			}
		}
	}

}

endif;
