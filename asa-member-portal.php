<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Member Portal
 * Plugin URI:        https://github.com/lmgnow/asa-member-portal
 * Description:       Front-end registration and login forms, additional user info fields for members, and member directory.
 * Version:           1.1.7
 * Author:            Jeremy Kozan
 * Author URI:        https://www.lmgnow.com/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       asamp
 * Domain Path:       /languages
 */

// if this file is called directly, abort
if ( ! defined( 'WPINC' ) ) die();
use Omnipay\Omnipay;
use League\Csv\Reader;
use League\Csv\Writer;

$asamp = new ASA_Member_Portal();
class ASA_Member_Portal {
	private $version          = '1.1.7';      // str             Current version.
	private $td               = 'asamp';      // str             Text Domain.
	private $pu               = '_user_';     // str             Prefix for user meta fields.
	private $viewer           = 'non-member'; // str             Current page viewer type.
	private $plugin_file_path = '';           // str             Absolute path to this file.      (with trailing slash)
	private $plugin_dir_path  = '';           // str             Absolute path to this directory. (with trailing slash)
	private $plugin_dir_url   = '';           // str             URL of this directory.           (with trailing slash)
	private $plugin_data      = array();      // array
	private $options          = array();      // array           CMB2 options for this plugin.
	private $user             = null;         // WP_User  object Current logged in user.
	private $user_meta        = null;         // stdClass object Current logged in user's user_meta data.
	private $is_member        = false;        // bool            true if $this->user is a member.
	private $fieldset_open    = false;        // bool            true if fieldset is already open.
	private $notices          = null;         // object          Used to store notices.

	/**
	 * Constructs object.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->plugin_file_path = __FILE__;
		$this->plugin_dir_path  = plugin_dir_path( $this->plugin_file_path );
		$this->plugin_dir_url   = plugin_dir_url(  $this->plugin_file_path );
		$this->pu               = $this->td . $this->pu;
		$this->viewer           = is_admin() ? 'admin' : 'non-member';
		$this->notices          = new ASAMP_One_Time_Notices();

		require_once $this->plugin_dir_path . 'includes/vendor/autoload.php';
		require_once $this->plugin_dir_path . 'includes/vendor/webdevstudios/cmb2/init.php';
		require_once $this->plugin_dir_path . 'includes/vendor/rogerlos/cmb2-metatabs-options/cmb2_metatabs_options.php';

		require_once $this->plugin_dir_path . 'includes/pallazzio-wpghu/pallazzio-wpghu.php';
		new Pallazzio_WPGHU( $this->plugin_dir_path . wp_basename( $this->plugin_file_path ), 'lmgnow' );

		$this->options = get_option( 'asamp' );

		register_activation_hook(   $this->plugin_file_path, array( $this, 'activate'   ) );
		register_deactivation_hook( $this->plugin_file_path, array( $this, 'deactivate' ) );

		add_action( 'wp_enqueue_scripts',    array( $this, 'asamp_enqueue' )        );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ), 10, 1 );

		add_action(    'add_option_asamp', array( $this, 'create_roles' ), 10, 2 );
		add_action( 'update_option_asamp', array( $this, 'create_roles' ), 10, 2 );
		add_action( 'delete_option_asamp', array( $this, 'create_roles' ), 10, 2 );

		add_action( 'user_register',  array( $this, 'set_user_options' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'set_user_options' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'profile_update'   ), 10, 1 );
		add_action( 'set_user_role',  array( $this, 'set_member_type'  ), 10, 1 );

		add_action( 'save_post_asamp_dues_payment', array( $this, 'dues_payment_save' ), 10, 3 );

		add_action( 'cmb2_init',       array( $this, 'user_meta_init'        ) );
		add_action( 'cmb2_init',       array( $this, 'payment_form_init'     ) );
		add_action( 'cmb2_init',       array( $this, 'login_form_init'       ) );
		add_action( 'cmb2_init',       array( $this, 'dues_payments_init'    ) );
		add_action( 'cmb2_admin_init', array( $this, 'options_init'          ) );
		add_action( 'cmb2_admin_init', array( $this, 'members_only_init'     ) );
		add_action( 'cmb2_admin_init', array( $this, 'import_members'        ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_profile' ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_login'   ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_dues_payment' ) );

		add_action( 'init',       array( $this, 'disallow_dashboard_access' ) );
		add_action( 'init',       array( $this, 'register_post_types'       ) );
		add_action( 'init',       array( $this, 'register_shortcodes'       ) );
		add_action( 'admin_init', array( $this, 'get_this_plugin_data' ) );
		add_action( 'admin_init', array( $this, 'export_members'       ) );

		add_action( 'admin_notices', array( $this, 'admin_notices'           ) );
		add_action( 'admin_footer',  array( $this, 'add_export_members_link' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file_path ), array( $this, 'add_settings_link' ), 10, 1 );

		add_filter( 'the_content', array( $this, 'hide_content' ), 99, 1 );
	}

	/**
	 * Sets up initial plugin settings, data, etc.
	 *
	 * @return void
	 */
	public function activate() {
		$this->create_roles( 'asamp', array( array( 'name' => $this->get_default_member_type_name() ) ) );
	}

	/**
	 * Removes roles created by this plugin.
	 *
	 * @return void
	 */
	public function deactivate() {
		$this->delete_roles();
	}

	/**
	 * Puts plugin data in a property for use throughout the class.
	 *
	 * @return void
	 */
	public function get_this_plugin_data() {
		$this->plugin_data = get_plugin_data( $this->plugin_file_path );
		$this->version     = $this->plugin_data[ 'Version' ];
	}

	/**
	 * Loads frontend scripts and stylesheets.
	 *
	 * @return void
	 */
	public function asamp_enqueue() {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(  'asamp_style',  $this->plugin_dir_url . 'css/asamp-style.css', array(          ), $this->version, 'screen' );
		wp_enqueue_script( 'asamp_script', $this->plugin_dir_url .  'js/asamp-script.js', array( 'jquery' ), $this->version, true     );
	}

	/**
	 * Loads admin scripts and stylesheets.
	 *
	 * @param string $hook
	 *
	 * @return void
	 */
	public function admin_enqueue( $hook ) {
		$hooks = array( 'settings_page_asamp', 'users_page_asamp_import_members', 'user-new.php', 'profile.php' );
		foreach ( $hooks as $v ) {
			if ( $v === $hook ) {
				wp_enqueue_style(  'asamp_admin_style',  $this->plugin_dir_url . 'css/asamp-admin-style.css', array(          ), $this->version, 'screen' );
				wp_enqueue_script( 'asamp_admin_script', $this->plugin_dir_url .  'js/asamp-admin-script.js', array( 'jquery' ), $this->version, true     );
			}
		}
	}

	/**
	 * Adds a settings link to the plugins list page.
	 *
	 * @param array $links
	 *
	 * @return array $links
	 */
	public function add_settings_link( $links ) {
		$import_link = '<a href="users.php?page=asamp_import_members">' . __( 'Import Members', 'asamp' ) . '</a>';
		array_unshift( $links, $import_link );
		$settings_link = '<a href="options-general.php?page=asamp&tab=opt-tab-general">' . __( 'Settings', 'asamp' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Redirects members who try to visit the admin dashboard.
	 *
	 * @return void
	 */
	public function disallow_dashboard_access() {
		if ( 'admin' === $this->viewer() && $this->is_member() && $this->get_member_role() ) {
			$pp = ! empty( $this->options[ 'page_profile' ] ) ? get_the_permalink( $this->options[ 'page_profile' ] ) : home_url();
			wp_redirect( $pp );
			exit();
		}
	}

	/**
	 * Outputs notices to admin screens.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( $bad_address = get_option( 'asamp_bad_address_notice' ) ) {
			?><div id="asamp-bad-address" class="error notice"><p>One of your members has a bad address. Google does not recognize it. It may be an invalid/non-existant address, or it may just be poorly formatted. <a href="<?php echo admin_url( 'user-edit.php?user_id=' . $bad_address );; ?>">Review Member Profile</a>.</p></div><?php
		}
	}

	/**
	 * Returns current member's asamp role.
	 *
	 * @return str $role
	 */
	private function get_member_role() {
		if ( $this->is_member() ) {
			$role = array_intersect( $this->user()->roles, array_keys( $this->get_asamp_roles() ) );
			return reset( $role );
		}
		return false;
	}

	/**
	 * Sets the current user's asamp role.
	 *
	 * @param str $role
	 *
	 * @return void
	 */
	private function set_member_role( $role ) {
		$roles = (array) $this->user()->roles;
		foreach ( $roles as $k => $v ) {
			if ( false !== strpos( $v, 'asamp_' ) ) {
				$this->user->remove_role( $v );
			}
		}

		$this->user->add_role( $role );
	}

	/**
	 * Adds/updates roles.
	 *
	 * @param string $option_name
	 * @param mixed  $option_value
	 *
	 * @return void
	 */
	public function create_roles( $option_name = null, $option_value = null ) {
		$this->delete_roles();

		$this->options = get_option( 'asamp' );
		if ( ! empty( $this->options[ 'member_types' ] ) ) {
			$member_types = $this->options[ 'member_types' ];
		} else {
			$member_types = array( array( 'name' => $this->get_default_member_type_name() ) );
		}
		foreach ( $member_types as $member_type ) {
			$member_type[ 'name' ] = trim( $member_type[ 'name' ] );
			$role = 'asamp_' . sanitize_key( $member_type[ 'name' ] );
			$result = add_role( $role, $member_type[ 'name' ], array( 'read' ) );
		}
	}

	/**
	 * Deletes roles.
	 *
	 * @return void
	 */
	private function delete_roles() {
		$roles = $this->get_asamp_roles();
		foreach ( $roles as $k => $v ) {
			remove_role( $k );
		}
	}

	/**
	 * Returns an array of the roles created by this plugin.
	 *
	 * @return array $roles
	 */
	private function get_asamp_roles() {
		$roles = get_editable_roles();
		foreach ( $roles as $k => $v ) {
			if ( false === strpos( $k, 'asamp_' ) ) {
				unset( $roles[ $k ] );
			}
		}

		return $roles;
	}

	/**
	 * Returns an associative array of the roles created by this plugin.
	 * Includes slug, label, and price. To be used in a select field on the frontend.
	 *
	 * @param bool $with_price Optional. Default = true
	 *
	 * @return array $roles
	 */
	private function get_asamp_roles_select( $with_price = false ) {
		if ( ! empty( $this->options[ 'member_types' ] ) ) {
			$member_types = $this->options[ 'member_types' ];
		} else {
			$member_types = array( array( 'name' => $this->get_default_member_type_name() ) );
		}
		$roles = array();
		foreach ( $member_types as $member_type ) {
			$str = $with_price ? sprintf( __( '(Dues: $%s/Year)', 'asamp' ), $member_type[ 'dues' ] ) : '';
			$roles[ 'asamp_' . sanitize_key( $member_type[ 'name' ] ) ] = $member_type[ 'name' ] . ' ' . $str;
		}

		return $roles;
	}

	/**
	 * Returns an associative array of the roles created by this plugin with price.
	 *
	 * @return array $roles
	 */
	private function get_asamp_roles_select_with_price() {
		return $this->get_asamp_roles_select( true );
	}

	/**
	 * Returns an associative array of the user accounts created by this plugin.
	 * Includes id and label. To be used in a select field on the frontend.
	 *
	 * @return array $members
	 */
	private function get_asamp_user_list_select() {
		$args = array(
			'role__in' => array_keys( $this->get_asamp_roles() ),
		);
		$user_query = new WP_User_Query( $args );
		$users = $user_query->get_results();

		$members = array( '' => 'Choose&hellip;' );
		foreach ( $users as $user ) {
			$members[ $user->ID ] = $user->data->display_name;
		}

		return $members;
	}

	/**
	 * Sets user options based on role.
	 *
	 * @param int            $user_id
	 * @param WP_User object $old_user_data
	 *
	 * @return void
	 */
	public function set_user_options( $user_id ) {
		$this->user = get_userdata( $user_id );
		$roles = (array) $this->user->roles;
		foreach ( $roles as $role ) {
			if ( false !== strpos( $role, 'asamp_' ) ) {
				update_user_option( $user_id, 'show_admin_bar_front', 'false' );
			}
		}
	}

	/**
	 * Registers shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		add_shortcode( 'asamp_member_profile',      array( $this, 'shortcode_asamp_member_profile'      ) );
		add_shortcode( 'asamp_member_login_box',    array( $this, 'shortcode_asamp_member_login_box'    ) );
		add_shortcode( 'asamp_member_payment_form', array( $this, 'shortcode_asamp_member_payment_form' ) );
		add_shortcode( 'asamp_member_directory',    array( $this, 'shortcode_asamp_member_directory'    ) );
		add_shortcode( 'asamp_member_map',          array( $this, 'shortcode_asamp_member_map'          ) );
	}

	/**
	 * Generates a member registration/profile form.
	 *
	 * @param array $atts Shortcode atts.
	 *
	 * @return string $output
	 */
	public function shortcode_asamp_member_profile( $atts = array() ) {
		$form_id = $this->pu;
		$cmb = cmb2_get_metabox( $form_id, $this->user()->ID );

		$output = '';

		if ( ( $error = $cmb->prop( 'submission_error' ) ) && is_wp_error( $error ) ) {
			$output .= '<div class="alert alert-danger asamp-submission-error-message">' . sprintf( __( 'There was an error in the submission: %s', 'asamp' ), '<strong>'. $error->get_error_message() .'</strong>' ) . '</div>';
		}
		
		if ( 'true' === $_GET[ 'member_updated' ] ) {
			$output .= '<h3>' . __( 'Your profile has been updated.', 'asamp' ) . '</h3>';
		}

		$form_config = array();
		$form_config[ 'save_button' ] = $this->is_member() ? __( 'Update Profile', 'asamp' ) : __( 'Join Now', 'asamp' );

		$output .= cmb2_get_metabox_form( $cmb, $this->user()->ID, $form_config );

		if ( $validation_errors = $cmb->prop( 'validation_errors' ) ) {
			ob_start();
			// TODO: enqueue this script properly with jQuery as a dependency.
			?>
				<script>
					(function($){
						'use strict';
						$(document).ready(function(){
							var asampMPVE = <?php echo json_encode( $validation_errors ); ?>;
							$.each(asampMPVE, function(key, data){
								$('form#<?php echo $form_id; ?> #'+key).addClass('asamp-validation-error').after('<div class="asamp-validation-error-message alert alert-danger">'+data+'</div>');
							});
							$('form#<?php echo $form_id; ?>').on('click', '.asamp-validation-error', function(){
								$(this).removeClass('asamp-validation-error');
							});
						});
					})(jQuery);
				</script>
			<?php
			$output .= ob_get_clean();
		}

		/*if ( ! $this->is_member() ) {
			ob_start();
			// TODO: enqueue this script properly.
			?>
				<script src="https://www.google.com/recaptcha/api.js?render=<?php echo $this->options[ 'google_recaptcha_site_key' ]; ?>"></script>
				<script>
					grecaptcha.ready(function(){
						grecaptcha.execute('<?php echo $this->options[ 'google_recaptcha_site_key' ]; ?>', {action: 'asamp_user_edit'}).then(function(token){
							
						});
					});
				</script>
			<?php
			$output .= ob_get_clean();
		}*/

		return $output;
	}

	/**
	 * Generates a member status widget or a login form if not logged in.
	 *
	 * @param array $atts
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_login_box( $atts = array() ) {
		$prefix = 'asamp_login_';
		$output = '';

		$instance = shortcode_atts( array(
			'hide' => false,
			'link' => __( 'Sign In', 'asamp' ),
		), $atts, 'asamp_member_login_box' );

		$instance[ 'hide' ] = 'false' === $instance[ 'hide' ] || ! $instance[ 'hide' ] ? false : true;
		$instance[ 'link' ] = sanitize_text_field( $instance[ 'link' ] );

		$output .= $instance[ 'hide' ] ? '<a href="#" class="asamp-login-box-activator">' . $instance[ 'link' ] . '</a>' : '';

		$hidden = $instance[ 'hide' ] ? ' hidden over' : '';
		$output .= '<div class="asamp-login-box' . $hidden . '"><div>';

			if ( $this->is_member() ) {
				$output .= '<p>' . __( 'Welcome', 'asamp' ) . ' ' . $this->user_meta()->asamp_user_company_name . '</p>';

				$status = $this->user_meta()->asamp_user_member_status;
				if ( 'active' !== $status ) {
					$output .= '<div class="alert alert-danger">' . __( 'ASA Membership Inactive', 'asamp' ) . '</div>';
					$output .= ! empty( $ppf = $this->options[ 'page_payment_form' ] ) ? '<a class="btn btn-success button button-success" href="' . get_the_permalink( $ppf ) . '">' . __( 'Activate Now', 'asamp' ) . '</a>' : '';
				}

				$output .= ! empty( $pp = $this->options[ 'page_profile' ] ) ? '<a class="btn btn-primary button button-primary" href="' . get_the_permalink( $pp ) . '">' . __( 'Manage Profile', 'asamp' ) . '</a>' : '';

				global $wp;
				$output .= '<a class="btn btn-danger button button-danger" href="' . wp_logout_url( add_query_arg( 'member_logged_out', 'true', home_url( '/' ) . $wp->request ) ) . '">' . __( 'Log Out', 'asamp' ) . '</a>';
			} else {
				$cmb = cmb2_get_metabox( $prefix . 'form' );

				if ( ( $error = $cmb->prop( 'submission_error' ) ) && is_wp_error( $error ) ) {
					$output .= '<div class="alert alert-danger asamp-submission-error-message">' . sprintf( __( 'There was an error in the submission: %s', 'asamp' ), '<strong>' . $error->get_error_message() . '</strong>' ) . '</div>';
				}

				if ( 'true' === $_GET[ 'member_logged_out' ] ) {
					$output .= '<div class="alert alert-info">' . __( 'You have been logged out.', 'asamp' ) . '</div>';
				}

				$output .= cmb2_get_metabox_form( $cmb, '', array( 'save_button' => __( 'Sign In', 'asamp' ) ) );

				$output .= ! empty( $pp = $this->options[ 'page_profile' ] ) ? '<span class="asamp-not-a-member">' . __( 'Not a Member?', 'asamp' ) . '</span> <a class="btn btn-success button button-success asamp-register-btn" href="' . get_the_permalink( $pp ) . '">' . __( 'Register', 'asamp' ) . '</a>' : '';
				
				$output .= '<a class="asamp-forgot-pw" href="' . wp_lostpassword_url() . '">' . __( 'Forgot Password?', 'asamp' ) . '</a>';
			}

		$output .= '</div></div>';

		return $output;
	}

	/**
	 * Generates a payment form.
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_payment_form( $atts = array() ) {
		$form_id = 'asamp_payment_form';
		$output = '';

		$cmb = cmb2_get_metabox( $form_id );

		if ( $error = $cmb->prop( 'submission_error' ) ) {
			$output .= '<div class="alert alert-danger asamp-submission-error-message">' . sprintf( __( 'There was an error in the submission: %s', 'asamp' ), '<strong>' . $error . '</strong>' ) . '</div>';
		} else {
			if ( 'true' === $_GET[ 'payment_received' ] ) {
				$output .= '<div class="alert alert-success asamp-submission-success-message">' . __( 'Thank you, your payment has been recieved.', 'asamp' ) . '</div>';
			}

			if ( 'true' === $_GET[ 'email_sent' ] ) {
				$output .= '<div class="alert alert-success asamp-submission-success-message">' . __( 'A receipt has been sent to the company email address we have on file.', 'asamp' ) . '</div>';
			} else if ( 'false' === $_GET[ 'email_sent' ] ) {
				$output .= '<div class="alert alert-warning asamp-submission-warning-message">' . __( 'There was a problem sending email. Please contact us for a receipt.', 'asamp' ) . '</div>';
			}
		}

		$output .= cmb2_get_metabox_form( $cmb, '', array( 'save_button' => __( 'Submit Payment', 'asamp' ) ) );

		if ( $validation_errors = $cmb->prop( 'validation_errors' ) ) {
			ob_start();
			// TODO: enqueue this script properly with jQuery as a dependency.
			?>
				<script>
					(function($){
						'use strict';
						$(document).ready(function(){
							var asampMPVE = <?php echo json_encode( $validation_errors ); ?>;
							$.each(asampMPVE, function(key, data){
								$('form#<?php echo $form_id; ?> #'+key).addClass('asamp-validation-error').after('<div class="asamp-validation-error-message alert alert-danger">'+data+'</div>');
							});
							$('form#<?php echo $form_id; ?>').on('click', '.asamp-validation-error', function(){
								$(this).removeClass('asamp-validation-error');
							});
						});
					})(jQuery);
				</script>
			<?php
			$output .= ob_get_clean();
		}

		return $output;
	}

	/**
	 * Generates an <svg> tag.
	 *
	 * @param str $str
	 *
	 * @return str $str
	 */
	private function svg( $str ) {
		return '<svg><use xlink:href="' . $this->plugin_dir_url . 'images/asamp-icons.svg#' . $str . '"></use></svg>';
	}

	/**
	 * Generates a member directory.
	 *
	 * @param array $atts
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_directory( $atts = array() ) {
		$show = $this->options[ 'profiles_public' ];
		if ( 'none' === $show && ! $this->is_member() ) return __( 'Please log in or register. Only members can see info about other members.', 'asamp' );

		$output = '';
		$args = array(
			'role__in'   => array_keys( $this->get_asamp_roles() ),
			'meta_key'   => 'asamp_user_member_status',
			'meta_value' => 'active',
		);
		$user_query = new WP_User_Query( $args );
		$users      = $user_query->get_results();

		if ( empty( $users ) ) return __( 'No active members found.', 'asamp' );

		if ( 'no' !== $this->options[ 'members_grouped_by_type' ] ) {
			$order = $this->get_asamp_roles_select();
			//$this->write_log( $order, 'oooorder' );
			usort( $users, function( $a, $b ) {
				$strcmp = strcmp( reset( $a->roles ), reset( $b->roles ) );
				if ( 0 === $strcmp ) return 1;
				return $strcmp;
			} );
		}

		$show = $this->is_member() ? 'all' : $show;

		ob_start();
		?>
			<ul class="asamp-dir">
				<?php foreach ( $users as $user ) : ?>
					<?php $meta = $this->flatten_array( get_user_meta( $user->ID ) ); ?>
					<li class="asamp-member">
						<h2 class="asamp-company-name"><?php echo $meta[ 'asamp_user_company_name' ]; ?></h2>
						<?php if ( ! empty( $meta[ 'asamp_user_company_website' ] ) && 'all' === $show ) : ?>
							<a class="asamp-company-website" href="<?php echo $meta[ 'asamp_user_company_website' ]; ?>" target="_blank" rel="noopener"><?php echo $this->svg( 'link' ) . ' ' . $meta[ 'asamp_user_company_website' ]; ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $meta[ 'asamp_user_company_description' ] ) ) : ?>
							<div class="asamp-company-description"><?php echo wpautop( $meta[ 'asamp_user_company_description' ] ); ?></div>
						<?php endif; ?>
						<?php if ( ! empty( $meta[ 'asamp_user_company_business_type' ] ) || ! empty( $meta[ 'asamp_user_company_business_type_other' ] ) ) : ?>
							<ul class="asamp-company-business-types">
								<?php
									$business_types = maybe_unserialize( $meta[ 'asamp_user_company_business_type' ] );
									if ( is_array( $business_types ) ) {
										foreach ( $business_types as $type ) {
											?><li><a href="#"><?php echo $type; ?></a></li><?php
										}
									}
									$business_types_other = maybe_unserialize( $meta[ 'asamp_user_company_business_type_other' ] );
									if ( is_array( $business_types_other ) ) {
										foreach ( $business_types_other as $type ) {
											?><li><?php echo $type; ?></li><?php
										}
									}
								?>
							</ul>
						<?php endif; ?>
						<?php if ( 'all' === $show ) : ?>
							
							<?php if ( ! empty( $meta[ 'asamp_user_company_phone' ] ) || ! empty( $meta[ 'asamp_user_company_fax' ] ) || ! empty( $meta[ 'asamp_user_company_email' ] ) || ! empty( $meta[ 'asamp_user_company_street' ] ) || ! empty( $meta[ 'asamp_user_company_city' ] ) || ! empty( $meta[ 'asamp_user_company_state' ] ) || ! empty( $meta[ 'asamp_user_company_zip' ] ) ) : ?>
								<ul class="asamp-company-contact-info">
									<?php if ( ! empty( $meta[ 'asamp_user_company_phone' ] ) ) : ?>
										<li><a class="asamp-company-phone" href="tel:<?php echo $this->format_tel( $meta[ 'asamp_user_company_phone' ] ); ?>"><?php echo $this->svg( 'phone' ) . ' ' . $meta[ 'asamp_user_company_phone' ]; ?></a></li>
									<?php endif; ?>
									<?php if ( ! empty( $meta[ 'asamp_user_company_fax' ] ) ) : ?>
										<li><a class="asamp-company-fax" href="tel:<?php echo $this->format_tel( $meta[ 'asamp_user_company_fax' ] ); ?>"><?php echo $this->svg( 'fax' ) . ' ' . $meta[ 'asamp_user_company_fax' ]; ?></a></li>
									<?php endif; ?>
									<?php if ( ! empty( $meta[ 'asamp_user_company_email' ] ) ) : ?>
										<li><a class="asamp-company-email" href="mailto:<?php echo $meta[ 'asamp_user_company_email' ]; ?>"><?php echo $this->svg( 'email' ) . ' ' . $meta[ 'asamp_user_company_email' ]; ?></a></li>
									<?php endif; ?>
									<?php if ( ! empty( $meta[ 'asamp_user_company_street' ] ) || ! empty( $meta[ 'asamp_user_company_city' ] ) || ! empty( $meta[ 'asamp_user_company_state' ] ) || ! empty( $meta[ 'asamp_user_company_zip' ] ) ) : ?>
										<li><a href="#"><?php echo $this->svg( 'address' ); ?><address class="asamp-company-address"><?php echo $meta[ 'asamp_user_company_street' ]; ?> <br /><?php echo $meta[ 'asamp_user_company_city' ] . ', ' . $meta[ 'asamp_user_company_state' ] . ' ' . $meta[ 'asamp_user_company_zip' ]; ?></address></a></li>
									<?php endif; ?>
								</ul>
							<?php endif; ?>

							<?php $contacts = maybe_unserialize( $meta[ 'asamp_user_company_contacts' ] ); ?>
							<?php if ( is_array( $contacts ) ) : ?>
								<h3>Contacts:</h3>
								<ol class="asamp-contacts">
									<?php $n = 0; ?>
									<?php foreach ( $contacts as $contact ) : ?>
										<?php
											$n++;
											if ( $n > $this->options[ 'num_contacts' ] ) break;
										?>
										<li class="asamp-contact">
											<?php if ( ! empty( $contact[ 'name_first' ] ) || ! empty( $contact[ 'name_last' ] ) ) : ?>
												<h4 class="asamp-contact-name"><?php echo $contact[ 'name_first' ] . ' ' . $contact[ 'name_last' ]; ?></h4>
											<?php endif; ?>
											<?php if ( ! empty( $contact[ 'title' ] ) ) : ?>
												<p class="asamp-contact-title"><em><?php echo $contact[ 'title' ]; ?></em></p>
											<?php endif; ?>
											<?php if ( ! empty( $contact[ 'asa_position' ] ) ) : ?>
												<p class="asamp-contact-position"><?php _e( 'ASA Position:', 'asamp' ); ?> <?php echo $contact[ 'asa_position' ]; ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $contact[ 'phone' ] ) || ! empty( $contact[ 'fax' ] ) || ! empty( $contact[ 'email' ] ) ) : ?>
											<ul>
												<?php if ( ! empty( $contact[ 'phone' ] ) ) : ?>
													<li><a href="tel:<?php echo $this->format_tel( $contact[ 'phone' ] ); ?>"><?php echo $this->svg( 'phone' ) . ' ' . $contact[ 'phone' ]; ?></a></li>
												<?php endif; ?>
												<?php if ( ! empty( $contact[ 'fax' ] ) ) : ?>
													<li><a href="tel:<?php echo $this->format_tel( $contact[ 'fax' ] ); ?>"><?php echo $this->svg( 'fax' ) . ' ' . $contact[ 'fax' ]; ?></a></li>
												<?php endif; ?>
												<?php if ( ! empty( $contact[ 'email' ] ) ) : ?>
													<li><a href="mailto:<?php echo $contact[ 'email' ]; ?>"><?php echo $this->svg( 'email' ) . ' ' . $contact[ 'email' ]; ?></a></li>
												<?php endif; ?>
											</ul>
											<?php endif; ?>
										</li><!-- /.asamp-contact -->
									<?php endforeach; ?>
								</ol><!-- /.asamp-contacts -->
							<?php endif; ?>

						<?php endif; ?>
					</li><!-- /.asamp-member -->
				<?php endforeach; ?>
			</ul><!-- /.asamp-dir -->
		<?php
		$output .= ob_get_clean();

		return $output;
	}

	/**
	 * Generates a member map.
	 *
	 * @param array $atts
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_map( $atts = array() ) {
		$show = $this->options[ 'profiles_public' ];
		if ( 'none' === $show && ! $this->is_member() ) return __( 'Please log in or register. Only members can see info about other members.', 'asamp' );

		$output = '';
		$args = array(
			'role__in'   => array_keys( $this->get_asamp_roles() ),
			'meta_key'   => $this->pu . 'member_status',
			'meta_value' => 'active',
		);
		$user_query = new WP_User_Query( $args );
		$users      = $user_query->get_results();

		if ( empty( $users ) ) return __( 'No active members found.', 'asamp' );

		$locations = array();
		foreach ( $users as $user ) {
			$meta = $this->flatten_array( get_user_meta( $user->ID ) );
			if ( empty( $meta[ $this->pu . 'lat' ] ) || empty( $meta[ $this->pu . 'lng' ] ) ) {
				if ( ! empty( $meta[ $this->pu . 'company_street' ] ) ) {
					$meta = $this->geocode( $user->ID, $meta );
				}
			}

			if ( empty( $meta[ $this->pu . 'lat' ] ) || empty( $meta[ $this->pu . 'lng' ] ) ) continue;

			$locations[] = array(
				'<h3>' . $meta[ $this->pu . 'company_name' ] . '</h3><address>' . $meta[ $this->pu . 'company_street' ] . ' <br />' . $meta[ $this->pu . 'company_city' ] . ', ' . $meta[ $this->pu . 'company_state' ] . '  ' . $meta[ $this->pu . 'company_zip' ] . '</address>',
				$meta[ $this->pu . 'lat' ],
				$meta[ $this->pu . 'lng' ],
			);
		}

		if ( empty( $locations ) ) return __( 'No valid map locations found.', 'asamp' );

		$lat_start  = ! empty( $this->options[ 'google_maps_center_lat' ] )   ? $this->options[ 'google_maps_center_lat' ]   : '37.09024';
		$lng_start  = ! empty( $this->options[ 'google_maps_center_lng' ] )   ? $this->options[ 'google_maps_center_lng' ]   : '-95.712891';
		$zoom_start = ! empty( $this->options[ 'google_maps_default_zoom' ] ) ? $this->options[ 'google_maps_default_zoom' ] : '4';

		ob_start();
		?>
			<div id="asamp-member-map" style="width: 100%; height: 600px; margin-bottom: 1em;"></div>
			<script>
			function initMap() {
				var map = new google.maps.Map(document.getElementById('asamp-member-map'), {
					zoom: <?php echo $zoom_start; ?>,
					center: new google.maps.LatLng(<?php echo $lat_start; ?>,<?php echo $lng_start; ?>),
					mapTypeId: google.maps.MapTypeId.ROADMAP
				});
				
				var locations = <?php echo json_encode( $locations ); ?>;
				var infowindow = new google.maps.InfoWindow();
				var marker, i;

				for(i = 0; i < locations.length; i++){
					marker = new google.maps.Marker({
						position: new google.maps.LatLng(locations[i][1], locations[i][2]),
						map: map,
					});

					google.maps.event.addListener(marker, 'click', (function(marker, i){
						return function(){
							infowindow.setContent(locations[i][0]);
							infowindow.open(map, marker);
						}
					})(marker, i));
				}
			}
			</script>
			<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo $this->options[ 'google_maps_api_key' ]; ?>&callback=initMap"></script>
		<?php
		$output = ob_get_clean();

		return $output;
	}

	/**
	 * Geocodes an address.
	 *
	 * @param int   $user_id
	 * @param array $user_meta
	 *
	 * @return array $user_meta
	 */
	private function geocode( $user_id, $user_meta ) {
		$geocode_api_interval = 60 * 60 * 1;
		if ( $geocode_api_interval > time() - get_option( 'asamp_geocode_api_limit_reached' ) ) return $user_meta;
		if ( $geocode_api_interval > time() - $user_meta[ $this->pu . 'geocode_fail_time' ]     ) return $user_meta;
		if ( 3 < $user_meta[ $this->pu . 'geocode_fail_count' ] ) {
			update_option( 'asamp_bad_address_notice', $user_id );
			return $user_meta;
		}

		$url  = 'http://maps.google.com/maps/api/geocode/json?address=';
		$url .=        $user_meta[ $this->pu . 'company_street' ];
		$url .= ', ' . $user_meta[ $this->pu . 'company_city'   ];
		$url .= ', ' . $user_meta[ $this->pu . 'company_state'  ];
		$url .= '  ' . $user_meta[ $this->pu . 'company_zip'    ];

		$request  = wp_remote_get( $url );
		$response = wp_remote_retrieve_body( $request );

		if ( 200 !== wp_remote_retrieve_response_code( $request ) ) return $user_meta;

		$response = json_decode( $response, true );

		if ( 'OVER_QUERY_LIMIT' === $response[ 'status' ] ) {
			update_option( 'asamp_geocode_api_limit_reached', time() );
			return $user_meta;
		}

		if ( 'ZERO_RESULTS' === $response[ 'status' ] ) {
			update_user_meta( $user_id, $this->pu . 'geocode_fail_time', time() );
			update_user_meta( $user_id, $this->pu . 'geocode_fail_count', $user_meta[ $this->pu . 'geocode_fail_count' ] + 1 );
			return $user_meta;
		}

		if ( 'OK' === $response[ 'status' ] ) {
			$this->profile_update( $user_id );

			$lat = $response[ 'results' ][ 0 ][ 'geometry' ][ 'location' ][ 'lat' ];
			update_user_meta( $user_id, $this->pu . 'lat', $lat );
			$user_meta[ $this->pu . 'lat' ] = $lat;

			$lng = $response[ 'results' ][ 0 ][ 'geometry' ][ 'location' ][ 'lng' ];
			update_user_meta( $user_id, $this->pu . 'lng', $lng );
			$user_meta[ $this->pu . 'lng' ] = $lng;
		}

		return $user_meta;
	}

	/**
	 * Updates some user meta fields anytime user profile is updated.
	 *
	 * @param int $user_id
	 *
	 * @return void
	 */
	public function profile_update( $user_id ) {
		update_user_meta( $user_id, $this->pu . 'lat',                '' );
		update_user_meta( $user_id, $this->pu . 'lng',                '' );
		update_user_meta( $user_id, $this->pu . 'geocode_fail_time',  '' );
		update_user_meta( $user_id, $this->pu . 'geocode_fail_count', '' );

		if ( get_option( 'asamp_bad_address_notice' ) == $user_id ) update_option( 'asamp_bad_address_notice', '' );
	}

	/**
	 * Updates user meta field member_type.
	 *
	 * @param int $user_id
	 *
	 * @return void
	 */
	public function set_member_type( $user_id ) {
		$user_meta   = get_userdata( $user_id );
		$member_type = array_intersect( $user_meta->roles, array_keys( $this->get_asamp_roles() ) );
		update_user_meta( $user_id, $this->pu . 'member_type', reset( $member_type ) );
	}

	/**
	 * Generates HTML to open a fieldset element in a CMB2 form.
	 * For use on field of type: 'title'.
	 *
	 * @param bool $field_args
	 * @param bool $field
	 *
	 * @return void
	 */
	public function open_fieldset( $field_args, $field ) {
		if ( $this->fieldset_open ) echo '</fieldset>'; $this->fieldset_open = true;
		echo '<fieldset><legend>' . $field->args( 'name' ) . '</legend>';
	}

	/**
	 * Closes a fieldset.
	 * For use on field of type: 'title'.
	 *
	 * @param bool $field_args
	 * @param bool $field
	 *
	 * @return void
	 */
	public function close_fieldset( $field_args, $field ) {
		echo '</fieldset>';
	}

	/**
	 * Generates HTML to render a main header as part of a CMB2 form.
	 * For use on field of type: 'title'.
	 *
	 * @param bool $field_args
	 * @param bool $field
	 *
	 * @return void
	 */
	public function form_heading( $field_args, $field ) {
		echo '<h2>' . $field->args( 'name' ) . '</h2>';
	}

	/**
	 * Checks to see if the current user is a member.
	 *
	 * @param bool $force_check
	 *
	 * @return bool
	 */
	public function is_member( $force_check = false ) {
		if ( $this->is_member && ! $force_check ) return $this->is_member;

		if ( is_user_logged_in() ) {
			$roles = (array) $this->user()->roles;
			foreach ( $roles as $role ) {
				if ( false !== strpos( $role, 'asamp_' ) ) {
					return $this->is_member = true;
				}
			}
		}

		return $this->is_member = false;
	}

	/**
	 * Returns the current logged in user.
	 *
	 * @return WP_User object $this->user
	 */
	public function user() {
		if ( isset( $this->user ) ) return $this->user;

		return $this->user = wp_get_current_user();
	}

	/**
	 * Returns the current logged in user's user_meta data.
	 *
	 * @return object $this->user_meta
	 */
	public function user_meta() {
		if ( is_object( $this->user_meta ) || ! is_user_logged_in() ) return $this->user_meta;

		$user_meta = get_user_meta( $this->user()->ID );

		$user_meta = $this->flatten_array( $user_meta );

		return $this->user_meta = (object) $user_meta;
	}

	/**
	 * Converts an array of arrays to a flat array.
	 * Only applies to array elements whose value is an array with a single element.
	 *
	 * @param array $r
	 *
	 * @return array $r
	 */
	public function flatten_array( $r ) {
		foreach ( $r as $k => $v ) {
			if ( is_array( $v ) && 1 === count( $v ) ) {
				$r[ $k ] = reset( $v );
			}
		}

		return $r;
	}

	/**
	 * Returns an associative array of US State abbreviations and names.
	 *
	 * @param string $default
	 *
	 * @return array $r
	 */
	public function get_us_states_array( $default = null ) {
		$r = array( '' => 'Choose&hellip;', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusets', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Vitginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming' );
		if ( isset( $default ) && array_key_exists( $default, $r ) ) {
			$r = array( $default => $r[ $default ] ) + $r;
		}

		return $r;
	}

	/**
	 * Returns an associative array of month numbers and names.
	 *
	 * @param int $start_month
	 *
	 * @return array $r
	 */
	public function get_months_array( $start_month = null ) {
		$r = array( '01' => '01 - January', '02' => '02 - February', '03' => '03 - March', '04' => '04 - April', '05' => '05 - May', '06' => '06 - June', '07' => '07 - July', '08' => '08 - August', '09' => '09 - September', '10' => '10 - October', '11' => '11 - November', '12' => '12 - December' );

		$start_month = absint( $start_month );
		if ( 0 < $start_month && 13 > $start_month ) {
			$i = array_search( $start_month, array_keys( $r ) );
			$s = array_slice( $r, $i );
			$t = array_slice( 0, $i );

			$r = array_merge( $s, $t );
		}

		return $r;
	}

	/**
	 * Returns an associative array of year numbers.
	 *
	 * @param int $start_year
	 * @param int $end_year optional
	 *
	 * @return array $r
	 */
	public function get_years_array( $start_year, $end_year = null ) {
		$start_year = absint( $start_year );
		$end_year   = isset( $end_year ) ? absint( $end_year ) : date( 'Y' );

		$r = range( $start_year, $end_year );

		$r = array_combine( $r, $r );

		return $r;
	}

	/**
	 * Returns an associative array of year numbers.
	 *
	 * @return array $r
	 */
	public function get_year_founded_array() {
		return $this->get_years_array( date( 'Y', strtotime( date( 'Y' ) . ' -100 years' ) ) );
	}

	/**
	 * Returns an associative array of pages or posts.
	 *
	 * @param str $post_type
	 * @param int $parent Post ID.
	 * @param int $indent
	 *
	 * @return array $r
	 */
	public function get_pages_array( $post_type = 'page', $parent = 0, $indent = 0 ) {
		$r = array();
		$pages = get_pages( array(
			'post_type' => $post_type,
			'parent'    => $parent,
		) );
		if ( empty( $pages ) ) return $r;

		foreach ( $pages as $page ) {
			$r[ $page->ID ] = ' ' . str_pad( '', $indent, '-' ) . $page->post_title;

			$s = $this->get_pages_array( $post_type, $page->ID, $indent + 1 );
			if ( is_array( $s ) ) {
				foreach ( $s as $k => $v ) {
					$r[ $k ] = $v;
				}
			}
		}

		return $r;
	}

	/**
	 * Returns a default array of trades / buisiness types.
	 *
	 * @return array
	 */
	public function get_default_trades_array() {
		return array( __( 'Accounting', 'asamp' ), __( 'Architectural', 'asamp' ), __( 'Asbestos Abatement', 'asamp' ), __( 'Attorney / Construction', 'asamp' ), __( 'Banking / Financial', 'asamp' ), __( 'Bonding / Insurance', 'asamp' ), __( 'Carpentry (MLWK)', 'asamp' ), __( 'Communications', 'asamp' ), __( 'Computer Facilities', 'asamp' ), __( 'Concrete', 'asamp' ), __( 'Conveying Systems', 'asamp' ), __( 'Countertops', 'asamp' ), __( 'Doors & Hardware', 'asamp' ), __( 'Drywall / Plaster / Acoustic', 'asamp' ), __( 'Electrical', 'asamp' ), __( 'Elevator / Escalator', 'asamp' ), __( 'Environmental', 'asamp' ), __( 'Excavating / Earth Moving', 'asamp' ), __( 'Fence', 'asamp' ), __( 'Fire Proofing', 'asamp' ), __( 'Fire Protection', 'asamp' ), __( 'Fire Sprinkling', 'asamp' ), __( 'Flooring', 'asamp' ), __( 'Foundation Drilling', 'asamp' ), __( 'Glass & Glazing', 'asamp' ), __( 'HVAC', 'asamp' ), __( 'Insulation', 'asamp' ), __( 'Landscaping', 'asamp' ), __( 'Lumber', 'asamp' ), __( 'Masonry', 'asamp' ), __( 'Mechanical', 'asamp' ), __( 'Mechanical Insulation', 'asamp' ), __( 'Metal Deck', 'asamp' ), __( 'Metals', 'asamp' ), __( 'Miscellaneous', 'asamp' ), __( 'Newspaper', 'asamp' ), __( 'Paint / Decorate', 'asamp' ), __( 'Paving', 'asamp' ), __( 'Plumbing', 'asamp' ), __( 'Professional Service', 'asamp' ), __( 'Publishing', 'asamp' ), __( 'Rebar', 'asamp' ), __( 'Rentals', 'asamp' ), __( 'Rigging / Hauling', 'asamp' ), __( 'Roofing', 'asamp' ), __( 'Sales', 'asamp' ), __( 'Scaffolding', 'asamp' ), __( 'Security Systems', 'asamp' ), __( 'Sheet Metal / Fabrication', 'asamp' ), __( 'Steel', 'asamp' ), __( 'Supplier', 'asamp' ), __( 'Tile / Terrazzo / Marble', 'asamp' ), __( 'Transportation', 'asamp' ), __( 'Trucking', 'asamp' ), __( 'Water Well Drilling', 'asamp' ), __( 'Waterproofing', 'asamp' ), __( 'Woodwork (Interior)', 'asamp' ), __( 'Wrecking / Demolition', 'asamp' ) );
	}

	/**
	 * Returns an associative array of trades / buisiness types.
	 *
	 * @return array
	 */
	public function get_trades_array() {
		$trades = array();
		if ( ! empty( $this->options[ 'trades' ] ) ) {
			$trades = explode( "\r\n", $this->options[ 'trades' ] );
		} else {
			$trades = $this->get_default_trades_array();
		}

		$r = array();
		foreach ( $trades as $v ) {
			$r[ esc_attr__( $v ) ] = $v;
		}

		return $r;
	}

	/**
	 * Returns the default member type label.
	 *
	 * @return string
	 */
	public function get_default_member_type_label() {
		return __( 'Member Type', 'asamp' );
	}

	/**
	 * Returns the default member type name.
	 *
	 * @return string
	 */
	public function get_default_member_type_name() {
		return __( 'Standard ASA Member', 'asamp' );
	}

	/**
	 * Returns the default member type dues amount.
	 *
	 * @return int
	 */
	public function get_default_member_type_dues() {
		return 900;
	}

	/**
	 * Returns the default number of contacts allowed.
	 *
	 * @return int
	 */
	public function get_default_num_contacts() {
		return 3;
	}

	/**
	 * Returns the default value for the default state option.
	 *
	 * @return str
	 */
	public function get_default_state_default() {
		return '';
	}

	/**
	 * Returns the user defined value of an option or its default value.
	 *
	 * @param str $option
	 *
	 * @return mixed
	 */
	private function get_option( $option ) {
		return ! empty( $this->options[ $option ] ) ? $this->options[ $option ] : call_user_func( array( $this, 'get_default_' . $option ) );
	}

	/**
	 * TODO write docs
	 *
	 * @return str $this->viewer
	 */
	private function viewer() {
		if ( 'admin'  === $this->viewer                       ) return $this->viewer;
		if ( 'member' === $this->viewer || $this->is_member() ) return $this->viewer = 'member';
		return $this->viewer;
	}

	/**
	 * Checks which form is being handled.
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	private function verify_cmb_form( $key ) {
		$key = $key . 'nonce';
		if ( empty( $_POST ) )                                          return false;
		if ( ! isset( $_POST[ 'submit-cmb' ], $_POST[ 'object_id' ] ) ) return false;
		if ( ! wp_verify_nonce( $_POST[ $key ], $key ) )                return false;
		if ( 'admin' === $this->viewer() )                              return false;

		return true;
	}

	/**
	 * Removes non-numeric characters from a string.
	 *
	 * @param str $str
	 *
	 * @return str $str
	 */
	private function format_tel( $str ) {
		return preg_replace( '/[^0-9]/', '', $str );
	}

	/**
	 * Handles dues payment form submission and payment processing.
	 *
	 * @return error or success or redirect
	 */
	public function frontend_dues_payment() {
		$prefix = 'asamp_payment_';
		if ( ! $this->verify_cmb_form( $prefix ) ) return false;

		$cmb = cmb2_get_metabox( $prefix . 'form' );

		if ( ! isset( $_POST[ $cmb->nonce() ] ) || ! wp_verify_nonce( $_POST[ $cmb->nonce() ], $cmb->nonce() ) ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'security_fail', __( 'Security check failed.', 'asamp' ) ) );
		}

		$sanitized_values = $cmb->get_sanitized_values( $_POST );

		if ( $validation_errors = $this->validate_dues_payment_form( $sanitized_values, $_POST, $prefix ) ) {
			$cmb->prop( 'submission_error', __( 'Please correct the errors below.', 'asamp' ) );
			$cmb->prop( 'validation_errors', $validation_errors );
			return $cmb;
		}

		$payment_processor = '';
		foreach ( $this->options as $k => $v ) {
			if ( 0 === strpos( $k, 'payment_' ) && false !== strpos( $k, '_enabled' ) && 'yes' === $v ) {
				$payment_processor = str_replace( '_enabled', '', $k );
				break;
			}
		}

		if ( empty( $payment_processor ) ) return $cmb->prop( 'submission_error', __( 'We cannot process payments online at this time. Please try again later or contact us.', 'asamp' ) );

		$gateway_init = array();
		foreach ( $this->options as $k => $v ) {
			if ( 0 === strpos( $k, $payment_processor ) && false === strpos( $k, '_enabled' ) ) {
				$k = end( explode( '_', $k ) );
				$gateway_init[ $k ] = 'testMode' === $k ? 1 : $v;
			}
		}

		$gateway = Omnipay::create( str_replace( 'payment_', '', $payment_processor ) );
		$gateway->initialize( $gateway_init );

		$payment_amount = $this->get_dues_amount_from_role_slug( $sanitized_values[ $prefix . 'member_type' ] );

		try {
			$response = $gateway->purchase( array(
				'amount'   => $payment_amount,
				'currency' => 'USD',
				'card'     => array(
					'number'      => $sanitized_values[ $prefix . 'cc_number' ],
					'expiryMonth' => $sanitized_values[ $prefix . 'cc_month'  ],
					'expiryYear'  => $sanitized_values[ $prefix . 'cc_year'   ],
					'cvv'         => $sanitized_values[ $prefix . 'cc_cvv'    ],
				),
			) )->send();

			if ( $response->isSuccessful() /*|| '4111111111111111' === $sanitized_values[ $prefix . 'cc_number' ]*/ ) {
				$payment_id = wp_insert_post( array(
					'post_type'   => 'asamp_dues_payment',
					'post_author' => 0,
				), true );

				wp_update_post( get_post( $payment_id ) );
				update_post_meta( $payment_id, '_asamp_dues_amount',    $payment_amount );
				update_post_meta( $payment_id, '_asamp_dues_cc_name',   $sanitized_values[ $prefix . 'firstname' ] . ' ' . $sanitized_values[ $prefix . 'lastname' ] );
				update_post_meta( $payment_id, '_asamp_dues_cc_type',   $this->get_cc_type( $sanitized_values[ $prefix . 'cc_number' ] ) );
				update_post_meta( $payment_id, '_asamp_dues_cc_number', '************' . substr( $sanitized_values[ $prefix . 'cc_number' ], -4 ) );
				update_post_meta( $payment_id, '_asamp_dues_cc_expiry', $sanitized_values[ $prefix . 'cc_year'  ] . '-' . $sanitized_values[ $prefix . 'cc_month'  ] );
				
				if ( ! $this->is_member() ) $this->user = wp_set_current_user( $sanitized_values[ $prefix . 'member_account' ] );

				update_post_meta( $payment_id, '_asamp_dues_member_account', $this->user()->user_login );
				$this->set_member_role( $sanitized_values[ $prefix . 'member_type' ] );
				wp_update_user( $this->user );
				update_user_meta( $this->user()->ID, 'asamp_user_member_type', $sanitized_values[ $prefix . 'member_type' ] );
				update_user_meta( $this->user()->ID, 'asamp_user_member_status', 'active' );
				if ( strtotime( $this->user_meta()->asamp_user_member_expiry ) > strtotime( date( 'Y-m-d' ) ) ) {
					update_user_meta( $this->user()->ID, 'asamp_user_member_expiry', date( 'Y-m-d', strtotime( $this->user_meta()->asamp_user_member_expiry . ' + 1 Year' ) ) );
				} else {
					update_user_meta( $this->user()->ID, 'asamp_user_member_expiry', date( 'Y-m-d', strtotime( date( 'Y-m-d' ) . ' + 1 Year' ) ) );
				}

				$blog_name = get_bloginfo( 'name' );
				$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
				$to        = array();
				$contacts  = $this->options[ 'admin_contacts' ];
				if ( is_array( $contacts ) ) {
					foreach ( $contacts as $contact ) {
						/*if ( ! empty( $contact[ 'name' ] ) ) {
							$contact[ 'email' ] = array( $contact[ 'name' ], $contact[ 'email' ] );
						}*/
						if ( $contact[ 'type' ] === 'to' ) {
							$to[] = $contact[ 'email' ];
						} else {
							$headers[] = $contact[ 'type' ] . ': ' . $contact[ 'email' ];
						}
					}
				}

				$subject = __( 'New Dues Payment From: ', 'asamp' ) . $this->user_meta()->asamp_user_company_name . ' - ' . $blog_name;
				$headers = array_unique( $headers );
				$to      = array_unique( $to );
				if ( empty( $to ) ) {
					$to = array( $blog_name . ' <' . get_bloginfo( 'admin_email' ) . '>' );
				}
				ob_start();
				?>
					<p><?php _e( 'Dues Payment From:', 'asamp' ) ?> <strong><?php echo $this->user_meta()->asamp_user_company_name; ?></strong></p>
					<p><strong><?php _e( 'Member Information', 'asamp' ); ?></strong></p>
					<dl>
						<dt><?php _e( 'Company Name', 'asamp' ); ?>: </dt><dd><?php echo $this->user_meta()->asamp_user_company_name; ?></dd>
					</dl>
					<p><strong><?php _e( 'Credit Card Information', 'asamp' ); ?></strong></p>
					<dl>
						<dt><?php _e( 'Amount', 'asamp' ); ?>: </dt><dd>$<?php echo $payment_amount; ?></dd>
						<dt><?php _e( 'Name on Card', 'asamp' ); ?>: </dt><dd><?php echo $sanitized_values[ $prefix . 'firstname' ] . ' ' . $sanitized_values[ $prefix . 'lastname' ]; ?></dd>
						<dt><?php _e( 'Credit Card', 'asamp' ); ?>: </dt><dd>****-****-****-<?php echo substr( $sanitized_values[ $prefix . 'cc_number' ], -4 ); ?></dd>
						<dt><?php _e( 'Date', 'asamp' ); ?>: </dt><dd><?php echo date( 'F jS, Y' ); ?></dd>
					</dl>
				<?php
				$message = ob_get_clean();

				// admin email
				wp_mail( $to, $subject, $message, $headers );

				// user email
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				//$to      = $this->user_meta()->asamp_user_company_name . ' <' . $this->user_meta()->asamp_user_company_email . '>';
				$to      = $this->user_meta()->asamp_user_company_email;
				$subject = __( 'Receipt for your Dues Payment to: ', 'asamp' ) . $blog_name;
				$sent    = false;
				if ( wp_mail( $to, $subject, $message, $headers ) ) {
					$sent = true;
				}

				$redirect = ! empty( $this->options[ 'page_payment_form' ] ) ? get_the_permalink( $this->options[ 'page_payment_form' ] ) : home_url();
				if ( ! is_wp_error( $payment_id ) ) {
					$redirect = add_query_arg( 'payment_received', 'true', $redirect );
				}
				if ( $sent ) {
					$redirect = add_query_arg( 'email_sent', 'true', $redirect );
				} else {
					$redirect = add_query_arg( 'email_sent', 'false', $redirect );
				}
				wp_redirect( esc_url_raw( $redirect ) );
				exit();
			} elseif ( $response->isRedirect() ) {
				// TODO: find out how this works.
				$response->redirect();
			} else {
				// Payment failed
				return $cmb->prop( 'submission_error', $response->getMessage() );
			}
		} catch ( \Exception $e ) {
			return $cmb->prop( 'submission_error', $e->getMessage() );
		}

		return $cmb->prop( 'submission_error', __( 'We are experiencing technical difficulties. Please try again later or contact us.', 'asamp' ) );
	}

	/**
	 * Returns credit card type.
	 *
	 * @param str $cc
	 *
	 * @return str
	 */
	function get_cc_type( $cc ) {
		if ( empty( $cc ) ) return false;

		$patterns = array(
			'Visa'             => '/^4[0-9]{12}(?:[0-9]{3})?$/',
			'MasterCard'       => '/^5[1-5][0-9]{14}$/',
			'American Express' => '/^3[47][0-9]{13}$/',
			'Diners Club'      => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
			'Discover'         => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
			'JCB'              => '/^(?:2131|1800|35\d{3})\d{11}$/',
			'Other'            => '/^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|6(?:011|5[0-9][0-9])[0-9]{12}|3[47][0-9]{13}|3(?:0[0-5]|[68][0-9])[0-9]{11}|(?:2131|1800|35\d{3})\d{11})$/',
		);

		foreach ( $patterns as $k => $v ) {
			if ( preg_match( $v, $cc ) ) {
				return $k;
			}
		}

		return 'Other';
	}

	/**
	 * Returns the dues price for the given role slug or false.
	 *
	 * @param str $role_slug
	 *
	 * @return str (currency formatted) or false
	 */
	private function get_dues_amount_from_role_slug( $role_slug ) {
		$roles = get_editable_roles();
		$role_name = $roles[ $role_slug ][ 'name' ];
		foreach ( $this->options[ 'member_types' ] as $type ) {
			if ( $role_name === $type[ 'name' ] ) {
				return $type[ 'dues' ];
			}
		}

		return false;
	}

	/**
	 * Handles user login.
	 *
	 * @return void
	 */
	public function frontend_user_login() {
		$prefix = 'asamp_login_';
		if ( ! $this->verify_cmb_form( $prefix ) ) return false;

		$cmb = cmb2_get_metabox( $prefix . 'form' );

		if ( ! isset( $_POST[ $cmb->nonce() ] ) || ! wp_verify_nonce( $_POST[ $cmb->nonce() ], $cmb->nonce() ) ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'security_fail', __( 'Security check failed.', 'asamp' ) ) );
		}

		$sanitized_values = $cmb->get_sanitized_values( $_POST );

		$creds = array(
			'user_login'    => $sanitized_values[ $prefix . 'login' ],
			'user_password' => $sanitized_values[ $prefix . 'pass' ],
			'remember'      => true,
		);

		$user = wp_signon( $creds, true );

		if ( is_wp_error( $user ) ) return $cmb->prop( 'submission_error', $user );

		wp_redirect( esc_url_raw( add_query_arg( 'member_logged_in', 'true' ) ) );
		exit();
	}

	/**
	 * Handles user profile form submission.
	 *
	 * @return void
	 */
	public function frontend_user_profile() {
		if ( ! $this->verify_cmb_form( $this->pu ) ) return false;

		$cmb = cmb2_get_metabox( $this->pu, $this->user()->ID );

		if ( ! isset( $_POST[ $cmb->nonce() ] ) || ! wp_verify_nonce( $_POST[ $cmb->nonce() ], $cmb->nonce() ) ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'security_fail', __( 'Security check failed.', 'asamp' ) ) );
		}

		$sanitized_values = $cmb->get_sanitized_values( $_POST );

		if ( $validation_errors = $this->validate_user_profile( $sanitized_values, $_POST, $this->pu ) ) {
			$cmb->prop( 'submission_error', new WP_Error( 'validation_fail', __( 'Please correct the errors below.', 'asamp' ) ) );
			$cmb->prop( 'validation_errors', $validation_errors );
			return $cmb;
		}

		/*$post_data = http_build_query(
			array(
				'secret'   => $this->options[ 'google_recaptcha_secret_key' ],
				'response' => $_POST[ 'g-recaptcha-response' ],
				'remoteip' => $_SERVER[ 'REMOTE_ADDR' ],
			)
		);
		$opts = array(
			'http' => array(
				'method'  => 'POST',
				'header'  => 'Content-type: application/x-www-form-urlencoded',
				'content' => $post_data,
			),
		);
		$context  = stream_context_create( $opts );
		$response = file_get_contents( 'https://www.google.com/recaptcha/api/siteverify', false, $context );
		$result   = json_decode( $response );
		if ( ! $result->success ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'recaptcha_fail', __( 'You are a robot.', 'asamp' ) ) );
		}*/

		$dest = add_query_arg( 'member_updated', 'true' );

		if ( $this->is_member() ) {
			$this->user()->user_pass     = ! empty( $sanitized_values[ $this->pu . 'pass' ] )                ? $sanitized_values[ $this->pu . 'pass' ]                : $this->user()->user_pass;
			$this->user()->user_nicename = ! empty( $sanitized_values[ $this->pu . 'company_name' ] )        ? $sanitized_values[ $this->pu . 'company_name' ]        : $this->user()->user_nicename;
			$this->user()->user_url      = ! empty( $sanitized_values[ $this->pu . 'company_website' ] )     ? $sanitized_values[ $this->pu . 'company_website' ]     : '';
			$this->user()->user_email    = ! empty( $sanitized_values[ $this->pu . 'company_email' ] )       ? $sanitized_values[ $this->pu . 'company_email' ]       : $this->user()->user_email;
			$this->user()->display_name  = ! empty( $sanitized_values[ $this->pu . 'company_name' ] )        ? $sanitized_values[ $this->pu . 'company_name' ]        : $this->user()->display_name;
			$this->user()->description   = ! empty( $sanitized_values[ $this->pu . 'company_description' ] ) ? $sanitized_values[ $this->pu . 'company_description' ] : '';

			$user_id = wp_update_user( $this->user() );

			update_user_meta( $user_id, 'first_name', $sanitized_values[ $this->pu . 'company_name' ] );
			update_user_meta( $user_id, 'nickname',   $sanitized_values[ $this->pu . 'company_name' ] );
		} else {
			$userdata = array(
				'user_login'           => $sanitized_values[ $this->pu . 'login' ],
				'user_pass'            => $sanitized_values[ $this->pu . 'pass' ],
				'user_nicename'        => sanitize_html_class( $sanitized_values[ $this->pu . 'company_name' ] ),
				'user_url'             => $sanitized_values[ $this->pu . 'company_website' ],
				'user_email'           => $sanitized_values[ $this->pu . 'company_email' ],
				'display_name'         => $sanitized_values[ $this->pu . 'company_name' ],
				'description'          => $sanitized_values[ $this->pu . 'company_description' ],
				'rich_editing'         => false,
				'syntax_highlighting'  => false,
				'show_admin_bar_front' => false,
				'role'                 => $sanitized_values[ $this->pu . 'member_type' ],
			);

			$user_id = wp_insert_user( $userdata );

			update_user_meta( $user_id, 'first_name',                   $sanitized_values[ $this->pu . 'company_name' ] );
			update_user_meta( $user_id, 'nickname',                     $sanitized_values[ $this->pu . 'company_name' ] );
			update_user_meta( $user_id, $this->pu . 'member_date_joined', date( 'Y-m-d' ) );
			update_user_meta( $user_id, $this->pu . 'member_expiry',      date( 'Y-m-d' ) );
			update_user_meta( $user_id, $this->pu . 'member_status',     'inactive' );

			wp_signon( array(
				'user_login'    => $userdata[ 'user_login' ],
				'user_password' => $userdata[ 'user_pass' ],
				'remember'      => false,
			), true );

			$dest = ! empty( $this->options[ 'page_payment_form' ] ) ? get_the_permalink( $this->options[ 'page_payment_form' ] ) : $dest;
		}

		// If there is a snag, inform the user.
		if ( is_wp_error( $user_id ) ) return $cmb->prop( 'submission_error', $user_id );

		// Make sure unhashed passwords are never stored in the database.
		unset( $sanitized_values[ $this->pu . 'pass' ], $sanitized_values[ $this->pu . 'pass_confirm' ] );
		
		$cmb->save_fields( $user_id, 'user', $sanitized_values );

		$img_id = $this->frontend_image_upload( $this->pu . 'company_logo' );

		//$this->profile_update( $user_id );

		wp_redirect( esc_url_raw( $dest ) );
		exit();
	}

	/**
	 * Handles uploading an image from a frontend form.
	 *
	 * @param string $key
	 *
	 * @return int $attachment_id
	 */
	private function frontend_image_upload( $key = '' ) {
		if (
			   empty( $_FILES )
			|| ! isset( $_FILES[ $key ] )
			|| isset( $_FILES[ $key ][ 'error' ] ) && 0 !== $_FILES[ $key ][ 'error' ]
		) {
			return;
		}

		if ( empty( array_filter( $_FILES[ $key ] ) ) ) return;

		// Include the WordPress media uploader API.
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		
		$attachment_id = media_handle_upload( $key, 0 );
		return $attachment_id;
	}

	/**
	 * Validates that the user filled out certain payment fields with appropriate values.
	 *
	 * @param array $posted_values
	 * @param array $sanitized_values
	 * @param array $prefix (optional)
	 *
	 * @return array $bad_fields OR false
	 */
	private function validate_dues_payment_form( $posted_values, $sanitized_values, $prefix = '' ) {
		$bad_fields = array();

		if ( ! $this->is_member() && empty( $sanitized_values[ $prefix . 'member_account' ] ) ) {
			$bad_fields[ $prefix . 'member_account' ] = __( 'Please choose a Member whose account you wish to activate or renew.', 'asamp' );
		}

		return ! empty( $bad_fields ) ? $bad_fields : false;
	}

	/**
	 * Validates that the user filled out certain profile fields with appropriate values.
	 *
	 * @param array $posted_values
	 * @param array $sanitized_values
	 * @param array $prefix (optional)
	 *
	 * @return array $bad_fields OR false
	 */
	private function validate_user_profile( $posted_values, $sanitized_values, $prefix = '' ) {
		$bad_fields = array();

		if ( empty( $sanitized_values[ $prefix . 'company_name' ] ) ) {
			$bad_fields[ $prefix . 'company_name' ] = 'Please enter a Company Name.';
		}

		if ( ! preg_match( '/^([0-9]{5})(-[0-9]{4})?$/i', $sanitized_values[ $prefix . 'company_zip' ] ) && ! empty( $sanitized_values[ $prefix . 'company_zip' ] ) ) {
			$bad_fields[ $prefix . 'company_zip' ] = 'Please enter a valid US Zip Code.';
		}

		$phone = $this->format_tel( $sanitized_values[ $prefix . 'company_phone' ] );
		if ( strlen( $phone ) === 11) $phone = preg_replace( '/^1/', '', $phone );
		if ( strlen( $phone ) !== 10 && ! empty( $sanitized_values[ $prefix . 'company_phone' ] ) ) {
			$bad_fields[ $prefix . 'company_phone' ] = 'Please enter a 10-digit phone number.';
		}

		$fax = $this->format_tel( $sanitized_values[ $prefix . 'company_fax' ] );
		if ( strlen( $fax ) === 11) $fax = preg_replace( '/^1/', '', $fax );
		if ( strlen( $fax ) !== 10 && ! empty( $sanitized_values[ $prefix . 'company_fax' ] ) ) {
			$bad_fields[ $prefix . 'company_fax' ] = 'Please enter a 10-digit fax number.';
		}

		if ( ! is_email( $sanitized_values[ $prefix . 'company_email' ] ) ) {
			$bad_fields[ $prefix . 'company_email' ] = 'Please enter a valid email address.';
		}

		if ( is_array( $sanitized_values[ $prefix . 'company_contacts' ] ) ) {
			foreach ( $sanitized_values[ $prefix . 'company_contacts' ] as $k => $contact ) {
				unset( $phone );
				$phone = $this->format_tel( $contact[ 'phone' ] );
				if ( strlen( $phone ) === 11) $phone = preg_replace( '/^1/', '', $phone );
				if ( strlen( $phone ) !== 10 && ! empty( $contact[ 'phone' ] ) ) {
					$bad_fields[ $prefix . 'company_contacts_' . $k . '_phone' ] = 'Please enter a 10-digit phone number.';
				}

				unset( $fax );
				$fax = $this->format_tel( $contact[ 'fax' ] );
				if ( strlen( $fax ) === 11) $fax = preg_replace( '/^1/', '', $fax );
				if ( strlen( $fax ) !== 10 && ! empty( $contact[ 'fax' ] ) ) {
					$bad_fields[ $prefix . 'company_contacts_' . $k . '_fax' ] = 'Please enter a 10-digit fax number.';
				}

				if ( ! is_email( $contact[ 'email' ] ) && ! empty( $contact[ 'email' ] ) ) {
					$bad_fields[ $prefix . 'company_contacts_' . $k . '_email' ] = 'Please enter a valid email address.';
				}
			}
		}

		if ( ! validate_username( $sanitized_values[ $prefix . 'login' ] ) || strpos( $sanitized_values[ $prefix . 'login' ], ' ' ) ) {
			if ( ! $this->is_member() ) {
				$bad_fields[ $prefix . 'login' ] = 'Please choose a unique username that contains only alphanumeric characters.';
			}
		}

		if ( $this->is_member() ) {
			$sim = similar_text( $this->user()->user_login, $sanitized_values[ $prefix . 'pass' ], $percent1 );
			$sim = similar_text( $sanitized_values[ $prefix . 'pass' ], $this->user()->user_login, $percent2 );
		} else {
			$sim = similar_text( $sanitized_values[ $prefix . 'login' ], $sanitized_values[ $prefix . 'pass' ], $percent1 );
			$sim = similar_text( $sanitized_values[ $prefix . 'pass' ], $sanitized_values[ $prefix . 'login' ], $percent2 );
		}

		if (
			   strlen( $sanitized_values[ $prefix . 'pass' ] ) < 8
			|| ! preg_match( '/[A-Z]/', $sanitized_values[ $prefix . 'pass' ] )
			|| ! preg_match( '/[a-z]/', $sanitized_values[ $prefix . 'pass' ] )
			|| ! preg_match( '/[0-9]/', $sanitized_values[ $prefix . 'pass' ] )
			|| 75 < ( $percent1 + $percent2 ) / 2
		) {
			$bad_fields[ $prefix . 'pass' ] = 'Password Requirements: <ul><li>At least 8 characters</li><li>Contain one uppercase letter</li><li>Contain one lowercase letter</li><li>Contain one number</li><li>Cannot be too similar to your username.</li></ul>';
		}
		if ( $this->is_member() && empty( $sanitized_values[ $prefix . 'pass' ] ) ) unset( $bad_fields[ $prefix . 'pass' ] );

		if ( $sanitized_values[ $prefix . 'pass_confirm' ] !== $sanitized_values[ $prefix . 'pass' ] ) {
			$bad_fields[ $prefix . 'pass_confirm' ] = 'Passwords must match.';
		}

		return ! empty( $bad_fields ) ? $bad_fields : false;
	}

	/**
	 * Adds a link to the users table footer.
	 *
	 * @return void
	 */
	public function add_export_members_link() {
		$screen = get_current_screen();
		if ( 'users' !== $screen->id || ! current_user_can( 'manage_options' ) ) return;
		?>
			<script>
				(function($){
					'use strict';
					$(document).ready(function(){
						$('.tablenav.bottom .clear').before('<form method="post" style="float: right; margin-right: 1em;"><input type="hidden" id="asamp_export_members" name="asamp_export_members" value="1" /><input class="button button-primary asamp-export-members-button" style="margin-top:3px;" type="submit" value="<?php _e( 'Export All ASA Members', 'asamp' ); ?>" /></form>');
					});
				})(jQuery);
			</script>
		<?php
	}

	/**
	 * Creates a csv string from an array.
	 *
	 * @return void
	 */
	public function export_members() {
		if ( empty( $_POST[ 'asamp_export_members' ] ) || ! current_user_can( 'manage_options' ) ) return;

		$roles = $this->get_asamp_roles_select();

		$keepers = array(
			$this->pu . 'login'                       => '',
			$this->pu . 'member_status'               => '',
			$this->pu . 'member_type'                 => '',
			$this->pu . 'member_date_joined'          => '',
			$this->pu . 'member_expiry'               => '',
			$this->pu . 'company_name'                => '',
			$this->pu . 'company_description'         => '',
			$this->pu . 'company_website'             => '',
			$this->pu . 'company_phone'               => '',
			$this->pu . 'company_fax'                 => '',
			$this->pu . 'company_email'               => '',
			$this->pu . 'company_street'              => '',
			$this->pu . 'company_city'                => '',
			$this->pu . 'company_state'               => '',
			$this->pu . 'company_zip'                 => '',
			$this->pu . 'company_year_founded'        => '',
			$this->pu . 'company_num_employees'       => '',
			$this->pu . 'company_contacts'            => '',
			$this->pu . 'company_business_type'       => '',
			$this->pu . 'company_business_type_other' => '',
		);
		$serialized = array(
			$this->pu . 'company_contacts'            => 0,
			$this->pu . 'company_business_type'       => 0,
			$this->pu . 'company_business_type_other' => 0,
		);
		$serialized_groups = array(
			$this->pu . 'company_contacts' => array(
				'name_first',
				'name_last',
				'phone',
				'fax',
				'email',
				'title',
				'asa_position',
			),
		);

		$args = array(
			'role__in' => array_keys( $this->get_asamp_roles() ),
		);
		$user_query = new WP_User_Query( $args );
		$users = $user_query->get_results();

		foreach ( $users as $key => $value ) {
			$user = get_user_meta( $value->ID );
			foreach ( $serialized as $k => $v ) {
				if ( ! empty( $user[ $k ] ) ) {
					$c = ltrim( reset( $user[ $k ] ), 'a:' );
					$c = strstr( $c, ':', true );
					$serialized[ $k ] = $c > $serialized[ $k ] ? $c : $serialized[ $k ];
				}
			}
		}

		foreach ( $serialized_groups as $k => $v ) {
			if ( $serialized[ $k ] > 0 ) {
				for ( $i = 1; $i < $serialized[ $k ] + 1; $i++ ) {
					foreach ( $v as $f ) {
						$keepers[ $k . '_' . $i . '_' . $f ] = '';
					}
				}
			}
		}

		$serialized[ $this->pu . 'company_business_type' ] = $serialized[ $this->pu . 'company_business_type' ] + $serialized[ $this->pu . 'company_business_type_other' ];
		if ( $serialized[ $this->pu . 'company_business_type' ] > 0 ) {
			for ( $i = 1; $i < $serialized[ $this->pu . 'company_business_type' ] + 1; $i++ ) {
				$keepers[ $this->pu . 'company_business_type' . '_' . $i ] = '';
			}
		}

		foreach ( $users as $key => $value ) {
			$user = $this->flatten_array( get_user_meta( $value->ID ) );

			$user[ $this->pu . 'login' ] = $value->data->user_login;

			$user[ $this->pu . 'member_type' ] = $roles[ $user[ $this->pu . 'member_type' ] ];
			
			if ( ! empty( $user[ $this->pu . 'company_contacts' ] ) ) {
				$contacts = unserialize( $user[ $this->pu . 'company_contacts' ] );
				foreach ( $contacts as $i => $contact ) {
					foreach ( $contact as $k => $v ) {
						$user[ $this->pu . 'company_contacts_' . ( $i + 1 ) . '_' . $k ] = $v;
					}
				}
			}
			
			if ( ! empty( $user[ $this->pu . 'company_business_type' ] ) || ! empty( $user[ $this->pu . 'company_business_type_other' ] ) ) {
				$types       = unserialize( $user[ $this->pu . 'company_business_type'       ] );
				$other_types = unserialize( $user[ $this->pu . 'company_business_type_other' ] );
				$n = 0;
				foreach ( $types as $i => $type ) {
					$n++;
					$user[ $this->pu . 'company_business_type_' . $n ] = $type;
				}
				foreach ( $other_types as $i => $type ) {
					$n++;
					$user[ $this->pu . 'company_business_type_' . $n ] = $type;
				}
			}

			$user = array_intersect_key( $user, $keepers );
			$user = array_replace( $keepers, $user );
			$users[ $key ] = $user;
		}

		unset( $keepers[ $this->pu . 'company_contacts' ], $keepers[ $this->pu . 'company_business_type' ], $keepers[ $this->pu . 'company_business_type_other' ] );
		foreach ( $users as $i => $user ) {
			unset( $user[ $this->pu . 'company_contacts' ], $user[ $this->pu . 'company_business_type' ], $user[ $this->pu . 'company_business_type_other' ] );
			$users[ $i ] = $user;
		}

		$csv = Writer::createFromString( '' );
		$csv->insertOne( str_replace( $this->pu, '', array_keys( $keepers ) ) );
		$csv->insertAll( $users );
		$csv->output( sanitize_key( get_bloginfo( 'name' ) ) . '_members_' . date( 'Y-m-d' ) . '.csv' );
		exit();
	}

	/**
	 * Inserts a new user or updates an existing one.
	 *
	 * @param array $userdata
	 * @param int   $try
	 *
	 * @return int $user_id
	 */
	public function insert_update_member( $member, $userdata, $try = 1 ) {
		$user = get_user_by( 'login', $userdata[ 'user_login' ] );

		if ( ! $user ) {
			$userdata[ 'user_pass' ] = ! empty( $member[ 'pass' ] ) ? $member[ 'pass' ] : wp_generate_password( rand( 12, 15 ) );
			$user_id = wp_insert_user( $userdata );
		} else {
			$userdata[ 'ID' ] = $user->ID;
			if ( ! empty( $member[ 'pass' ] ) ) $userdata[ 'user_pass' ] = wp_hash_password( $member[ 'pass' ] );
			$user_id = wp_insert_user( $userdata );
		}

		if ( is_wp_error( $user_id ) ) {
			$message = $user_id->get_error_message();
			$this->notices->add_error( sprintf( __( 'Error on row with login: "%s". %s Importing aborted. This row and subsequent rows were not processed.', 'asamp' ), $member[ 'login' ], '<strong>' . $message . '</strong>' ) );
			wp_redirect( esc_url_raw( add_query_arg( 'members_import_successful', 'partial' ) ) );
			exit();
		}
		
		return $user_id;
	}

	/**
	 * Creates/updates member accounts from a .csv file.
	 *
	 * @return void
	 */
	public function import_members() {
		if ( 'asamp_import_members' !== $_POST[ 'object_id' ] || empty( $_POST[ 'upload_file_id' ] ) ) return;
		
		$roles   = $this->get_asamp_roles_select();
		$csv     = Reader::createFromPath( get_attached_file( $_POST[ 'upload_file_id' ] ), 'r' );
		$headers = $csv->fetchOne();
		$members = $csv->setOffset( 1 )->fetchAssoc( $headers );

		wp_delete_attachment( $_POST[ 'upload_file_id' ] );

		$required = array(
			'login',
			'company_name',
			'company_email',
			'member_status',
			'member_type',
			'member_date_joined',
			'member_expiry',
		);
		foreach ( $members as $member ) {
			foreach ( $member as $k => $v ) {
				$member[ $k ] = trim( $v );
			}

			foreach ( $required as $field ) {
				if ( empty( $member[ $field ] ) ) {
					$missing[ $field ] = true;
				}
			}

			if ( ! array_search( $member[ 'member_type' ] . ' ', $roles ) ) {
				$invalid_member_types[] = $member[ 'member_type' ];
			}
		}

		$members_import_successful = true;
		
		if ( ! empty( $missing ) ) {
			$members_import_successful = false;
			foreach ( $missing as $k => $v ) {
				$this->notices->add_error( sprintf( __( 'Invalid file. One or more of your rows is missing the required field: "%s".', 'asamp' ), $k ) );
			}
		}
		
		if ( ! empty( $invalid_member_types ) ) {
			$members_import_successful = false;
			$invalid_member_types = array_unique( $invalid_member_types );
			foreach ( $invalid_member_types as $k => $v ) {
				$this->notices->add_error( sprintf( __( 'Invalid file. One or more of your rows has the "member_type" column set to: "%s". "%s" is not a recognized member type.', 'asamp' ), $v, $v ) );
			}
		}

		if ( ! $members_import_successful ) {
			wp_redirect( esc_url_raw( add_query_arg( 'members_import_successful', 'false' ) ) );
			exit();
		}

		$n = 0;
		foreach ( $members as $member ) {
			$n++;
			foreach ( $member as $k => $v ) {
				$member[ $k ] = trim( $v );
			}

			$userdata = array(
				'user_login'    => $member[ 'login' ],
				'user_email'    => strtolower( $member[ 'company_email' ] ),
				'user_nicename' => sanitize_html_class( $member[ 'company_name' ] ),
				'display_name'  => $member[ 'company_name' ],
			);

			if ( ! empty( $member[ 'pass' ] ) )                $userdata[ 'user_pass' ]    = $member[ 'pass' ];
			if ( ! empty( $member[ 'company_website' ] ) )     $userdata[ 'user_url' ]     = strtolower( $member[ 'company_website' ] );
			if ( ! empty( $member[ 'company_description' ] ) ) $userdata[ 'description' ]  = $member[ 'company_description' ];

			$user_id = $this->insert_update_member( $member, $userdata );

			$role = array_search( $member[ 'member_type' ] . ' ', $roles );
			$u = new WP_User( $user_id );
			$u->add_role( $role );
			$u->remove_role( 'subscriber' );

			update_user_option( $user_id, 'show_admin_bar_front', 'false' );

			$user_meta = array(
				'first_name'                              => $member[ 'company_name' ],
				'nickname'                                => $member[ 'company_name' ],
				$this->pu . 'member_date_joined'          => $member[ 'member_date_joined' ],
				$this->pu . 'member_expiry'               => $member[ 'member_expiry' ],
				$this->pu . 'member_status'               => $member[ 'member_status' ],
				$this->pu . 'company_name'                => $member[ 'company_name' ],
				$this->pu . 'member_type'                 => $role,
				$this->pu . 'company_year_founded'        => $member[ 'company_year_founded' ],
				$this->pu . 'company_num_employees'       => $member[ 'company_num_employees' ],
				$this->pu . 'company_website'             => strtolower( $member[ 'company_website' ] ),
				$this->pu . 'company_email'               => strtolower( $member[ 'company_email' ] ),
				$this->pu . 'company_phone'               => $member[ 'company_phone' ],
				$this->pu . 'company_fax'                 => $member[ 'company_fax' ],
				$this->pu . 'company_street'              => $member[ 'company_street' ],
				$this->pu . 'company_city'                => $member[ 'company_city' ],
				$this->pu . 'company_state'               => $member[ 'company_state' ],
				$this->pu . 'company_zip'                 => $member[ 'company_zip' ],
				$this->pu . 'company_contacts'            => $this->get_contacts_from_csv( $member, 'company_contacts' ),
				$this->pu . 'company_business_type'       => $this->get_business_types_from_csv( $member, 'company_business_type' ),
				$this->pu . 'company_business_type_other' => $this->get_business_types_from_csv( $member, 'company_business_type', true ),
			);

			foreach ( $user_meta as $k => $v ) {
				update_user_meta( $user_id, $k, $v );
			}
		}

		$this->notices->add_success( sprintf( __( 'Successfully imported and/or updated %s members.', 'asamp' ), $n ) );
		wp_redirect( esc_url_raw( add_query_arg( 'members_import_successful', 'true' ) ) );
		exit();
	}

	/**
	 * Returns a multi-dimensinal array.
	 *
	 * @param array $member
	 * @param str   $prefix
	 *
	 * @return array $contacts
	 */
	private function get_contacts_from_csv( $member, $prefix ) {
		$contacts = array();

		foreach ( $member as $k => $v ) {
			if ( false === strpos( $k, $prefix ) ) continue;

			$k = str_replace( $prefix . '_', '', $k );
			$n = substr( $k, 0, strpos( $k, '_') );
			$k = str_replace( $n . '_', '', $k );

			$contacts[ intval( $n ) - 1 ][ $k ] = $v;
		}

		return $contacts;
	}

	/**
	 * Returns an array.
	 *
	 * @param array $member
	 * @param str   $prefix
	 * @param bool  $other
	 *
	 * @return array $types
	 */
	private function get_business_types_from_csv( $member, $prefix, $other = false ) {
		$types = array();
		foreach ( $member as $k => $v ) {
			if ( false === strpos( $k, $prefix ) ) continue;
			$types[] = htmlspecialchars( htmlspecialchars_decode( $v ) );
		}

		$trades = array();
		if ( ! empty( $this->options[ 'trades' ] ) ) {
			$trades = explode( "\r\n", $this->options[ 'trades' ] );
		} else {
			$trades = $this->get_default_trades_array();
		}

		if ( $other ) {
			$types = array_diff( $types, $trades );
		} else {
			$types = array_intersect( $types, $trades );
		}

		foreach ( $types as $k => $v ) {
			$types[ $k ] = esc_attr__( $v );
		}
		
		return $types;
	}

	/**
	 * Adds custom fields to custom post type 'asamp_dues_payment'.
	 *
	 * @return void
	 */
	public function dues_payments_init() {
		$prefix = '_asamp_dues_';

		$cmb = new_cmb2_box( array(
			'id'           => $prefix . 'edit',
			'title'        => __( 'Payment Details', 'asamp' ),
			'object_types' => array( 'asamp_dues_payment' ),
			'show_names'   => true,
		) );

		$cmb->add_field( array(
			'name'        => __( 'Amount', 'asamp' ),
			'id'          => $prefix . 'amount',
			'type'        => 'text_money',
		) );

		$cmb->add_field( array(
			'name'        => __( 'Name on Card', 'asamp' ),
			'id'          => $prefix . 'cc_name',
			'type'        => 'text',
		) );

		$cmb->add_field( array(
			'name'        => __( 'Member Account', 'asamp' ),
			'description' => 'This must be an exact and valid username.',
			'id'          => $prefix . 'member_account',
			'type'        => 'text',
		) );

		$cmb->add_field( array(
			'name'        => __( 'Card Type', 'asamp' ),
			'id'          => $prefix . 'cc_type',
			'type'        => 'text',
		) );

		$cmb->add_field( array(
			'name'        => __( 'Card Number', 'asamp' ),
			'id'          => $prefix . 'cc_number',
			'type'        => 'text',
		) );

		$cmb->add_field( array(
			'name'        => __( 'Card Expiration', 'asamp' ),
			'id'          => $prefix . 'cc_expiry',
			'type'        => 'text',
		) );

	}

	/**
	 * For post type 'asamp_dues_payment'. Sets post_title, post_name, and post_status.
	 *
	 * @param int  $post_id
	 * @param post $post The post object.
	 * @param bool $update Whether this is an existing post being updated or not.
	 *
	 * @return void
	 */
	public function dues_payment_save( $post_ID, $post, $update ) {
		if ( ! $update ) return;

		if ( 'Payment #' . ( string ) $post->ID !== $post->post_title ) {
			$post->post_title = 'Payment #' . ( string ) $post->ID;
			$post->post_name  = 'payment-'  . ( string ) $post->ID;
			wp_update_post( $post );
			return;
		}

		if ( 'private' !== $post->post_status && 'trash' !== $post->post_status ) {
			$post->post_status = 'private';
			wp_update_post( $post );
			return;
		}
	}

	/**
	 * Registers custom post types.
	 *
	 * @return void
	 */
	public function register_post_types() {
		$labels = array(
			'name'               => __( 'Dues Payments',            'asamp' ),
			'singular_name'      => __( 'Dues Payment',             'asamp' ),
			'menu_name'          => __( 'Dues Payments',            'asamp' ),
			'all_items'          => __( 'All Payments',             'asamp' ),
			'not_found'          => __( 'No Payments',              'asamp' ),
			'not_found_in_trash' => __( 'No Payments in the Trash', 'asamp' ),
			'search_items'       => __( 'Search Payments',          'asamp' ),
			'add_new'            => __( 'Add Payment',              'asamp' ),
		);

		$args = array(
			'label'               => __( 'Dues Payments', 'asamp' ),
			'labels'              => $labels,
			'description'         => '',
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'rest_base'           => '',
			'has_archive'         => false,
			'show_in_menu'        => true,
			'exclude_from_search' => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'query_var'           => true,
			'menu_position'       => 71,
			'menu_icon'           => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAzMiAzMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBmaWxsPSIjOWVhM2E4IiBkPSJNMTggMjJjLTEuMTA0IDAtMi0wLjg5Ni0yLTJ2LTJjMC0xLjEwNSAwLjg5Ni0yIDItMmgxMnY2aC0xMnpNMTkuNSAxNy41ODNjLTAuODI4IDAtMS41IDAuNjcyLTEuNSAxLjUgMCAwLjgyOSAwLjY3MiAxLjUgMS41IDEuNSAwLjgyOSAwIDEuNS0wLjY3MSAxLjUtMS41IDAtMC44MjgtMC42NzEtMS41LTEuNS0xLjV6TTE1IDE4djJjMCAxLjY1NiAxLjM0NCAzIDMgM2gxMHY0YzAgMS42NTYtMS4zNDMgMy0zIDNoLTIxYy0xLjY1NiAwLTMtMS4zNDQtMy0zdi0xOWMwLTEuNjU3IDEuMzQ0LTMgMy0zaDkuNjMybC0yLjM0OSAwLjk3OWgtNy43ODJjLTAuODI4IDAtMS41IDAuNjcyLTEuNSAxLjVzMC42NzIgMS41IDEuNSAxLjVoMjQuNDk5djYuMDIxaC0xMGMtMS42NTYgMC0zIDEuMzQzLTMgM3pNNS4wMDEgMTFoLTJ2Mmgxdi0xaDF2LTF6TTUuMDAxIDE0aC0ydjJoMS4wNDFsLTAuMDQxLTEgMSAwLjAyMXYtMS4wMjF6TTUuMDAxIDE3aC0ydjJoMS4wMjFsLTAuMDIxLTFoMXYtMXpNNS4wMDEgMjBoLTJ2MmgxLjAyMWwtMC4wMjEtMSAxIDAuMDIxdi0xLjAyMXpNNS4wMDEgMjNoLTJ2Mmgxdi0xaDF2LTF6TTUuMDAxIDI2aC0ydjJoMS4wMjFsLTAuMDIxLTAuOTc5aDF2LTEuMDIxek05LjAwMSA3LjkzOGwxMS43NS00LjkzOCAyLjI1IDQuOTM4aC0xNHpNMjIuNjQ2IDVoMi4zNTRjMC44NzYgMCAxLjY1NiAwLjM4MSAyLjIwNSAwLjk3OWgtNC4wODFsLTAuNDc4LTAuOTc5eiIgLz48L3N2Zz4=',
			'supports'            => array( 'title' ),
			'rewrite'             => array(
				'slug'       => 'payments',
				'with_front' => false
			),
		);

		register_post_type( 'asamp_dues_payment', $args );
	}

	/**
	 * Registers a frontend login form.
	 *
	 * @return void
	 */
	public function login_form_init() {
		$prefix = 'asamp_login_';
		$cmb = new_cmb2_box( array(
			'id'           => $prefix . 'form',
			'object_types' => array( 'post' ),
			'hookup'       => false,
			'save_fields'  => false,
		) );
		$cmb->add_field( array(
			'name' => __( 'Username / Email', 'asamp' ),
			'id'   => $prefix . 'login',
			'type' => 'text',
		) );
		$cmb->add_field( array(
			'name' => __( 'Password', 'asamp' ),
			'id'   => $prefix . 'pass',
			'type' => 'text',
			'attributes' => array(
				'type' => 'password',
			),
		) );
		$cmb->add_hidden_field( array(
			'field_args'  => array(
				'id'      => $prefix . 'nonce',
				'type'    => 'hidden',
				'default' => wp_create_nonce( $prefix . 'nonce' ),
			),
		) );
	}

	/**
	 * Registers a "Members Only" metabox on posts and pages.
	 *
	 * @return void
	 */
	public function members_only_init() {
		$prefix = '_asamp_members_only_';

		$options = $this->get_asamp_roles_select();
		$options[ 'everyone' ] = 'Everyone';

		$cmb = new_cmb2_box( array(
			'id'           => $prefix . 'form',
			'title'        => __( 'Content Access Settings', 'asamp' ),
			'object_types' => array( 'post', 'page' ),
			'context'      => 'side',
			'priority'     => 'default',
		) );
		$cmb->add_field( array(
			'name'    => __( 'Show Content To:', 'asamp' ),
			'id'      => $prefix . 'level',
			'type'    => 'multicheck',
			'default' => 'everyone',
			'options' => $options,
		) );
	}

	/**
	 * Displays a "content restricted" message if content is restricted and active member is not logged in.
	 *
	 * @return str $content
	 */
	public function hide_content( $content ) {
		if ( is_singular() && is_main_query() ) {

			$access_level = get_post_meta( get_the_ID(), '_asamp_members_only_level', true );

			if ( empty( $access_level ) || in_array( 'everyone', $access_level ) ) return $content;

			if ( ! $this->is_member() ) return '<div class="asamp-submission-error-message"><p>' . __( 'This content is restricted. Only members may view it. Please log in or register.', 'asamp' ) . '</p></div>';

			if ( 'inactive' === $this->user_meta()->asamp_user_member_status ) return '<div class="asamp-submission-error-message"><p>' . __( 'Your membership is not active. Please activate or renew your membership.', 'asamp' ) . '</p></div>';

			if ( ! in_array( $this->user_meta()->asamp_user_member_type, $access_level ) ) {
				$member_types = $this->get_asamp_roles_select();
				$content = '<div class="asamp-submission-error-message"><p>' . __( 'This content is restricted. Only the following member types may view it:', 'asamp' ) . '</p><ul>';
				foreach ( $access_level as $level ) {
					$content .= '<li>' . $member_types[ $level ] . '</li>';
				}
				$content .= '</ul><p>' . __( 'Please upgrade your membership.', 'asamp' ) . '</p></div>';
				return $content;
			}
			
		}

		return $content;
	}

	/**
	 * Registers a frontend payment form.
	 *
	 * @return void
	 */
	public function payment_form_init() {
		$prefix = 'asamp_payment_';
		$cmb = new_cmb2_box( array(
			'id'           => $prefix . 'form',
			'object_types' => array( 'post' ),
			//'hookup'       => false,
			'save_fields'  => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Membership', 'asamp' ),
			'id'              => $prefix . 'section_member_type',
			'type'            => 'title',
			'render_row_cb'   => array( $this, 'open_fieldset' ),
		) );
		if ( ! $this->is_member() ) {
			$cmb->add_field( array(
				'name'       => __( 'Member Account', 'asamp' ),
				'id'         => $prefix . 'member_account',
				'type'       => 'select',
				'options'    => $this->get_asamp_user_list_select(),
			) );
		}
		$cmb->add_field( array(
			'name'       => ! empty( $this->options[ 'member_type_label' ] ) ? $this->options[ 'member_type_label' ] : $this->get_default_member_type_label(),
			'id'         => $prefix . 'member_type',
			'type'       => 'select',
			'default'    => $this->get_member_role(),
			'options'    => $this->get_asamp_roles_select_with_price(),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Credit Card Info', 'asamp' ),
			'id'              => $prefix . 'section_cc_info',
			'type'            => 'title',
			'render_row_cb'   => array( $this, 'open_fieldset' ),
		) );
		$cmb->add_field( array(
			'name'       => __( 'First Name', 'asamp' ),
			'id'         => $prefix . 'firstname',
			'type'       => 'text',
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Last Name', 'asamp' ),
			'id'         => $prefix . 'lastname',
			'type'       => 'text',
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Credit Card Number', 'asamp' ),
			'id'         => $prefix . 'cc_number',
			'type'       => 'text',
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Month', 'asamp' ),
			'id'         => $prefix . 'cc_month',
			'type'       => 'select',
			'options'    => $this->get_months_array(),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Year', 'asamp' ),
			'id'         => $prefix . 'cc_year',
			'type'       => 'select',
			'options'    => $this->get_years_array( date( 'Y' ), date( 'Y', strtotime( date( 'Y' ) . ' +9 years' ) ) ),
		) );
		$cmb->add_field( array(
			'name'       => __( 'CVV', 'asamp' ),
			'id'         => $prefix . 'cc_cvv',
			'type'       => 'text',
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Billing Address', 'asamp' ),
			'id'         => $prefix . 'cc_address',
			'type'       => 'text',
			'default'    => $this->user_meta()->asamp_user_company_street,
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'City', 'asamp' ),
			'id'         => $prefix . 'cc_city',
			'type'       => 'text',
			'default'    => $this->user_meta()->asamp_user_company_city,
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'State', 'asamp' ),
			'id'         => $prefix . 'cc_state',
			'type'       => 'select',
			'default'    => ! empty( $this->user_meta()->asamp_user_company_state ) ? $this->user_meta()->asamp_user_company_state : $this->options[ 'state_default' ],
			'options'    => $this->get_us_states_array(),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Zip Code', 'asamp' ),
			'id'         => $prefix . 'cc_zip',
			'type'       => 'text',
			'default'    => $this->user_meta()->asamp_user_company_zip,
			'attributes' => array(
				'required' => 'required',
			),
		) );
		$cmb->add_hidden_field( array(
			'field_args'  => array(
				'id'      => $prefix . 'nonce',
				'type'    => 'hidden',
				'default' => wp_create_nonce( $prefix . 'nonce' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'End Form', 'asamp' ),
			'id'              => $prefix . 'section_end_form',
			'type'            => 'title',
			'render_row_cb'   => array( $this, 'close_fieldset' ),
		) );
	}

	/**
	 * Adds plugin options.
	 *
	 * @return void
	 */
	public function options_init() {
		new Cmb2_Metatabs_Options( array(
			'key'      => 'asamp_options',
			'title'    => __( 'Member Portal Settings', 'asamp' ),
			'topmenu'  => 'options-general.php',
			'resettxt' => '',
			'boxes'    => $this->options_add_boxes( 'asamp_options' ),
			'tabs'     => $this->options_add_tabs(),
			'menuargs' => array(
				'menu_title'      => __( 'Membership', 'asamp' ),
				'capability'      => 'manage_options',
				'view_capability' => 'manage_options',
			),
		) );

		$import_members_key = 'asamp_import_members';
		new Cmb2_Metatabs_Options( array(
			'key'      => $import_members_key,
			'title'    => __( 'Import Members', 'asamp' ),
			'topmenu'  => 'users.php',
			'resettxt' => '',
			'boxes'    => $this->import_members_add_boxes( $import_members_key ),
			'menuargs' => array(
				'menu_title'      => __( 'Import Members', 'asamp' ),
				'capability'      => 'manage_options',
				'view_capability' => 'manage_options',
			),
		) );
	}

	/**
	 * Adds tabs to the plugin options page.
	 *
	 * @return array $tabs
	 */
	private function options_add_tabs() {
		$tabs = array();

		$tabs[ 'general' ] = array(
			'priority' => 10,
			'name'     => __( 'General', 'asamp' ),
		);
		$tabs[ 'payment' ] = array(
			'priority' => 20,
			'name'     => __( 'Payment', 'asamp' ),
		);
		$tabs[ 'usage' ] = array(
			'priority' => 30,
			'name'     => __( 'Usage', 'asamp' ),
		);

		$tabs = apply_filters( $this->td . '_options_tabs', $tabs );
		
		return $tabs;
	}

	/**
	 * Adds boxes to plugin options.
	 *
	 * This is typical CMB2, but note two crucial extra items:
	 * - the ['show_on'] property is configured
	 * - a call to object_type method
	 *
	 * @return array $boxes
	 */
	private function options_add_boxes() {
		$boxes = array();
		
		$boxes[ 'registration' ] = array(
			'priority' => 10,
			'title'    => __( 'Profile/Registration', 'asamp' ),
			'tab'      => 'general',
			'fields'   => array(
				'state_default' => array(
					'priority'        => 100,
					'name'            => __( 'Default State', 'asamp' ),
					'desc'            => __( 'Which State should be selected by default on the registration form?', 'asamp' ),
					'type'            => 'select',
					'options'         => 'get_us_states_array',
				),
				'num_contacts' => array(
					'priority'        => 120,
					'name'            => __( 'Number of Contacts Allowed', 'asamp' ),
					'desc'            => __( 'Please be careful. If a user already has multiple contacts, then you decrease this to a number that is lower than what they have, some of their contacts may disappear.', 'asamp' ),
					'type'            => 'text',
					'sanitization_cb' => 'absint',
					'default'         => 'opt_num_contacts',
					'attributes'      => array(
						'type' => 'number',
						'min'  => 0,
					),
				),
				'trades' => array(
					'priority'        => 140,
					'name'            => __( 'Trades', 'asamp' ),
					'desc'            => __( 'Add one trade per line. Please be careful. If a user already has a trade selected, then you remove the trade, their selection for that trade may be lost.', 'asamp' ),
					'type'            => 'textarea',
					'default'         => 'opt_trades_array',
				),
				'trades_other' => array(
					'priority'        => 160,
					'name'            => __( 'Include "Other" Trades Option', 'asamp' ),
					'desc'            => __( 'Please be careful. If a user already has any "Other Trades" saved, then you remove the "Other Trade" option, their "Other Trades" may be lost.', 'asamp' ),
					'type'            => 'radio_inline',
					'default'         => 'yes',
					'options'         => array(
						'yes' => __( 'Yes', 'asamp' ),
						'no'  => __( 'No',  'asamp' ),
					),
				),
				'google_recaptcha_site_key' => array(
					'priority'        => 180,
					'name'            => __( 'Google reCAPTCHA Site Key', 'asamp' ),
					'type'            => 'text',
				),
				'google_recaptcha_secret_key' => array(
					'priority'        => 200,
					'name'            => __( 'Google reCAPTCHA Secret Key', 'asamp' ),
					'type'            => 'text',
				),
				'member_type_label' => array(
					'priority'        => 220,
					'name'            => __( 'Member Type Label', 'asamp' ),
					'type'            => 'text',
					'default'         => 'opt_member_type_label',
				),
				'member_types' => array(
					'priority'        => 240,
					'name'            => __( 'Member Type(s)', 'asamp' ),
					'desc'            => __( 'Please be careful. If a user is already set as a certain member type, then you remove that type, their entire user account may be lost.', 'asamp' ),
					'type'            => 'group',
					'options'         => array(
						'sortable'      => true,
						'group_title'   => 'Member Type #{#}',
						'add_button'    => __( 'Add Member Type',    'asamp' ),
						'remove_button' => __( 'Remove Member Type', 'asamp' ),
					),
				),
				'name' => array(
					'priority'        => 260,
					'parent'          => 'member_types',
					'name'            => __( 'Name', 'asamp' ),
					'type'            => 'text',
					'default'         => 'opt_member_type_name',
					'attributes'      => array(
						'required' => 'required',
					),
				),
				'dues' => array(
					'priority'        => 280,
					'parent'          => 'member_types',
					'name'            => __( 'Dues Amount', 'asamp' ),
					'type'            => 'text_money',
					'default'         => 'opt_member_type_dues',
					'attributes'      => array(
						'required' => 'required',
					),
				),
			),
		);

		$boxes[ 'directory' ] = array(
			'priority' => 20,
			'title'    => __( 'Member Directory', 'asamp' ),
			'tab'      => 'general',
			'fields'   => array(
				'profiles_public' => array(
					'priority'      => 10,
					'name'          => __( 'Member Info Security', 'asamp' ),
					'desc'          => __( 'Choose amount of member info to show to non-members.', 'asamp' ),
					'type'          => 'radio_inline',
					'default'       => 'basic',
					'options'       => array(
						'all'   => __( 'All',   'asamp' ),
						'basic' => __( 'Basic', 'asamp' ),
						'none'  => __( 'None',  'asamp' ),
					),
				),
				'members_grouped_by_type' => array(
					'priority'      => 20,
					'name'          => __( 'List Member Types Grouped Separately', 'asamp' ),
					'type'          => 'radio_inline',
					'default'       => 'yes',
					'options'       => array(
						'yes' => __( 'Yes', 'asamp' ),
						'no'  => __( 'No',  'asamp' ),
					),
				),
			),
		);

		$boxes[ 'map' ] = array(
			'priority' => 30,
			'title'    => __( 'Member Map', 'asamp' ),
			'tab'      => 'general',
			'fields'   => array(
				'google_maps_api_key' => array(
					'priority'      => 10,
					'name'          => __( 'Google Maps API Key', 'asamp' ),
					'type'          => 'text',
				),
				'google_maps_center_lat' => array(
					'priority'      => 20,
					'name'          => __( 'Latitude', 'asamp' ),
					'type'          => 'text',
					'default'       => '37.09024',
				),
				'google_maps_center_lng' => array(
					'priority'      => 30,
					'name'          => __( 'Longitude', 'asamp' ),
					'type'          => 'text',
					'default'       => '-95.712891',
				),
				'google_maps_default_zoom' => array(
					'priority'      => 40,
					'name'          => __( 'Zoom', 'asamp' ),
					'type'          => 'text',
					'default'       => 4,
					'attributes'    => array(
						'type' => 'number',
						'min'  => 1,
						'max'  => 21,
					),
				),
			),
		);

		$boxes[ 'pages' ] = array(
			'priority' => 40,
			'title'    => __( 'Pages', 'asamp' ),
			'tab'      => 'general',
			'fields'   => array(
				'page_profile' => array(
					'priority'      => 10,
					'name'          => __( 'Profile/Registration Form', 'asamp' ),
					'desc'          => __( 'The page that contains the shortcode: [asamp_member_profile]', 'asamp' ),
					'type'          => 'select',
					'options'       => 'get_pages_array',
				),
				'page_payment_form' => array(
					'priority'      => 20,
					'name'          => __( 'Payment Form', 'asamp' ),
					'desc'          => __( 'The page that contains the shortcode: [asamp_member_payment_form]', 'asamp' ),
					'type'          => 'select',
					'options'       => 'get_pages_array',
				),
			),
		);

		$boxes[ 'administration' ] = array(
			'priority' => 50,
			'title'    => __( 'Administration', 'asamp' ),
			'tab'      => 'general',
			'fields'   => array(
				'admin_contacts' => array(
					'priority'      => 10,
					'name'          => __( 'Admin Contact(s)', 'asamp' ),
					'desc'          => __( 'Send all emails generated by this plugin to the following recipients.', 'asamp' ),
					'type'          => 'group',
					'options'       => array(
						'sortable'      => true,
						'group_title'   => 'Contact #{#}',
						'add_button'    => __( 'Add Contact',    'asamp' ),
						'remove_button' => __( 'Remove Contact', 'asamp' ),
					),
				),
				'name' => array(
					'priority'      => 20,
					'parent'        => 'admin_contacts',
					'name'          => __( 'Name', 'asamp' ),
					'type'          => 'text',
					'default'       => get_bloginfo( 'name' ),
					'attributes'    => array(
						'required' => 'required',
					),
				),
				'email' => array(
					'priority'      => 30,
					'parent'        => 'admin_contacts',
					'name'          => __( 'Address', 'asamp' ),
					'type'          => 'text_email',
					'default'       => get_bloginfo( 'admin_email' ),
					'attributes'    => array(
						'required' => 'required',
					),
				),
				'type' => array(
					'priority'      => 40,
					'parent'        => 'admin_contacts',
					'name'          => __( 'Type', 'asamp' ),
					'type'          => 'radio_inline',
					'default'       => 'to',
					'options'         => array(
						'to'  => __( 'To',  'asamp' ),
						'cc'  => __( 'CC',  'asamp' ),
						'bcc' => __( 'BCC', 'asamp' ),
					),
				),
			),
		);

		$boxes[ 'payment_PayPal_Pro' ] = array(
			'priority' => 60,
			'title'    => __( 'PayPal Pro', 'asamp' ),
			'tab'      => 'payment',
			'fields'   => array(
				'payment_PayPal_Pro_enabled' => array(
					'priority'      => 10,
					'name'          => __( 'PayPal Pro Enabled', 'asamp' ),
					'type'          => 'radio_inline',
					'default'       => 'no',
					'options'       => array(
						'yes' => __( 'Yes', 'asamp' ),
						'no'  => __( 'No',  'asamp' ),
					),
				),
				'payment_PayPal_Pro_username' => array(
					'priority'      => 20,
					'name'          => __( 'PayPal Pro API Username', 'asamp' ),
					'type'          => 'text',
				),
				'payment_PayPal_Pro_password' => array(
					'priority'      => 30,
					'name'          => __( 'PayPal Pro API Password', 'asamp' ),
					'type'          => 'text',
				),
				'payment_PayPal_Pro_signature' => array(
					'priority'      => 40,
					'name'          => __( 'PayPal Pro API Signature', 'asamp' ),
					'type'          => 'text',
				),
				'payment_PayPal_Pro_testMode' => array(
					'priority'      => 50,
					'name'          => __( 'Use PayPal Pro in Test Mode', 'asamp' ),
					'type'          => 'checkbox',
				),
			),
		);

		$boxes[ 'payment_Stripe' ] = array(
			'priority' => 70,
			'title'    => __( 'Stripe', 'asamp' ),
			'tab'      => 'payment',
			'fields'   => array(
				'payment_Stripe_enabled' => array(
					'priority'      => 10,
					'name'          => __( 'Stripe Enabled', 'asamp' ),
					'type'          => 'radio_inline',
					'default'       => 'no',
					'options'       => array(
						'yes' => __( 'Yes', 'asamp' ),
						'no'  => __( 'No',  'asamp' ),
					),
				),
				'payment_Stripe_apiKey' => array(
					'priority'      => 20,
					'name'          => __( 'Stripe API Key', 'asamp' ),
					'type'          => 'text',
				),
			),
		);

		$boxes[ 'instructions' ] = array(
			'priority' => 80,
			'title'    => __( 'Usage Instructions', 'asamp' ),
			'tab'      => 'usage',
			'fields'   => array(
				'usage_instructions' => array(
					'priority'      => 10,
					'name'          => __( 'Usage Instructions', 'asamp' ),
					'type'          => 'title',
					'render_row_cb' => 'usage_instructions',
				),
			),
		);

		$boxes = apply_filters( $this->td . '_options', $boxes );

		foreach ( $boxes as $id => $box ) {
			add_filter( $this->td . '_options_tabs', function(){
				$tabs[ $box[ 'tab' ] ][ 'boxes' ][] = $id;
			} );

			$box[ 'id' ]              = $id;
			$box[ 'display_cb' ]      = false;
			$box[ 'admin_menu_hook' ] = false;
			$box[ 'show_on' ]         = array(
				'key'   => 'options-page',
				'value' => array( $this->td . '_options' ),
			);

			$boxes[ $id ] = $this->build_box( $box, $box[ 'fields' ] );

			$boxes[ $id ]->object_type( 'options-page' );
		}
		
		return $boxes;
	}

	/**
	 * Adds boxes to plugin options.
	 *
	 * This is typical CMB2, but note two crucial extra items:
	 * - the ['show_on'] property is configured
	 * - a call to object_type method
	 *
	 * @param  string $member_import_key
	 *
	 * @return array $boxes
	 */
	private function import_members_add_boxes( $member_import_key ) {
		//holds all CMB2 box objects
		$boxes = array();
		
		//add this to all boxes
		$show_on = array(
			'key'   => 'options-page',
			'value' => array( $member_import_key ),
		);
		
		$cmb = new_cmb2_box( array(
			'id'              => 'asamp_member_import',
			'title'           => __( 'Import Members', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
			'hookup'          => false,
			'save_fields'     => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Upload File', 'asamp' ),
			'desc'            => __( 'File must be formatted correctly or you will badly break your website. See an <a href="' . $this->plugin_dir_url . 'includes/members_example.csv" target="_blank">example</a>.', 'asamp' ),
			'id'              => 'upload_file',
			'type'            => 'file',
			'options' => array(
				//'url' => false,
			),
			'text' => array(
				'add_upload_file_text' => 'Choose File',
			),
			'query_args' => array(
				'type' => 'text/csv',
			),
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		return $boxes;
	}

	/**
	 * Adds member profile fields to user meta.
	 *
	 * @return void
	 */
	public function user_meta_init() {
		$fields = array();
		$fields = apply_filters( $this->td . '_user_meta', $fields );
		uasort( $fields, function( $a, $b ){
			return $a[ 'priority' ] - $b[ 'priority' ];
		} );

		$box = new_cmb2_box( array(
			'id'               => $this->pu,
			'object_types'     => array( 'user' ),
			'show_names'       => true,
			'new_user_section' => 'add-new-user',
		) );

		$this->build_box( $box, $fields, $this->pu );
	}

	/**
	 * TODO write docs
	 *
	 * @param obj   $box CMB2 box object
	 * @param array $fields
	 * @param str   $key
	 *
	 * @return void
	 */
	private function build_box( $box, $fields, $key = '' ) {
		$box->add_hidden_field( array(
			'field_args'  => array(
				'id'      => $key . 'nonce',
				'type'    => 'hidden',
				'default' => wp_create_nonce( $key . 'nonce' ),
			),
		) );

		$group_ids = array();
		foreach ( $fields as $id => $field ) {
			if ( isset( $field[ 'visibility' ] ) ) {
				foreach ( $field[ 'visibility' ] as $k => $v ) {
					if ( is_array( $v ) ) {
						if ( isset( $v[ 'option' ] ) && $v[ 'value' ] === $this->get_option( $v[ 'option' ] ) ) {
							if ( $v[ 'negate' ] ) continue 2;
						}
						unset( $field[ 'visibility' ][ $k ] );
					}
				}
				if ( ! empty( $field[ 'visibility' ] ) && ! in_array( $this->viewer(), $field[ 'visibility' ] ) ) continue;
				//unset( $field[ 'visibility' ] );
			}

			if ( is_array( $field[ 'name' ] ) ) $field[ 'name' ] = $field[ 'name' ][ $this->viewer() ];

			if ( is_string( $field[ 'options' ] ) ) $field[ 'options' ] = call_user_func( array( $this, $field[ 'options' ] ) );

			foreach ( $field as $k => $v ) {
				if (                    false !== strpos( $k, '_cb' ) )  $field[ $k ] = array( $this, $v );
				if ( is_string( $v ) && false !== strpos( $v, 'opt_' ) ) $field[ $k ] = $this->get_option( ltrim( $v, 'opt_' ) );
			}

			if ( 'member' === $this->viewer() && 'password' === $field[ 'attributes' ][ 'type' ] ) unset( $field[ 'attributes' ][ 'required' ] );

			if ( isset( $field[ 'parent' ] ) ) {
				$field[ 'id' ] = $id;
				$parent = $field[ 'parent' ];
				//unset( $field[ 'parent' ] );
				$box->add_group_field( $group_ids[ $parent ], $field );
			} else {
				$field[ 'id' ] = $key . $id;
				$field_id = $box->add_field( $field );
				if ( 'group' === $field[ 'type' ] ) $group_ids[ $id ] = $field_id;
			}
		}

		return $box;
	}

	/**
	 * Prints output.
	 * For use on field of type: 'title'.
	 *
	 * @param bool $field_args
	 * @param bool $field
	 *
	 * @return void
	 */
	public function usage_instructions( $field_args, $field ) {
		?>
			<div class="asamp-instructions">
				<h3>Shortcodes:</h3>
				<dl>
					<dt>[asamp_member_profile]</dt>
					<dd>
						Renders a form for non-members to register. Members use this form to update their profile.
					</dd>
					<dt>[asamp_member_map]</dt>
					<dd>
						Renders a google map with a pin for each member's address.
					</dd>
					<dt>[asamp_member_login_box hide="true" link="Text"]</dt>
					<dd>
						Renders a login box. Parameters <code>hide</code> and <code>link</code> are optional.
					</dd>
					<dt>[asamp_member_payment_form]</dt>
					<dd>
						Renders a form for users to activate or renew their membership.
					</dd>
					<dt>[asamp_member_directory]</dt>
					<dd>
						Renders a list of all members.
					</dd>
				</dl>
				<p>Note: After adding shortcodes to your pages, remember to come back to the settings and tell me which pages your shortcodes are on.</p>
				<h3>Roles:</h3>
				<p>Other plugins like "Contact Listing for WP Job Manager" may need to be configured with a role slug. Your roles are listed here.</p>
				<dl>
					<?php
						$roles = $this->get_asamp_roles();
						foreach ( $roles as $k => $v ) {
							echo '<dt>' . $v[ 'name' ] . '</dt><dd>' . $k . '</dd>';
						}
					?>
				</dl>
			</div>
		<?php
	}

	/**
	 * Writes to error_log.
	 *
	 * @param mixed  $log
	 * @param string $id
	 *
	 * @return void
	 */
	public function write_log( $log, $id = '' ) {
		error_log( '************* ' . $id . ' *************' );
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}

}

/**
 * One_Time_Notice Handling
 */
class ASAMP_One_Time_Notices {
	public $errors   = array();
	public $warnings = array();
	public $success  = array();

	/**
	 * Constructs the object.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'output_notices' ) );
		add_action( 'shutdown',      array( $this, 'save_notices'   ) );
	}

	/**
	 * Adds an error.
	 */
	public function add_error( $text ) {
		$this->errors[] = $text;
	}

	/**
	 * Adds a warning.
	 */
	public function add_warning( $text ) {
		$this->warnings[] = $text;
	}

	/**
	 * Adds a success message.
	 */
	public function add_success( $text ) {
		$this->success[] = $text;
	}

	/**
	 * Saves notices to an option.
	 */
	public function save_notices() {
		$notices = array(
			'error'   => $this->errors,
			'warning' => $this->warnings,
			'updated' => $this->success,
		);

		update_option( 'asamp_one_time_notices', $notices );
	}

	/**
	 * Shows any stored error messages.
	 */
	public function output_notices() {
		$notices = maybe_unserialize( get_option( 'asamp_one_time_notices' ) );

		foreach ( $notices as $k => $v ) {
			if ( ! empty( $v ) ) {
				
				foreach ( $v as $notice ) {
					echo '<div id="asamp-' . $k . '" class="' . $k . ' notice is-dismissible">';
						echo '<p>' . wp_kses_post( $notice ) . '</p>';
					echo '</div>';
				}
				
			}
		}

		delete_option( 'asamp_one_time_notices' );
	}

}

add_filter( 'asamp_options', 'asamp_asa_specific_options' );
function asamp_asa_specific_options( $options ) {
	return $options;
}

add_filter( 'asamp_user_meta', 'asamp_asa_specific_user_meta' );
function asamp_asa_specific_user_meta( $fields ) {
	$fields[ 'member_status' ] = array(
		'export'          => true,
		'priority'        => 100,
		'name'            => __( 'ASA Membership Status', 'asamp' ),
		'type'            => 'radio_inline',
		'default'         => 'inactive',
		'visibility'      => array( 'admin' ),
		'options'         => array(
			'active'   => __( 'Active',   'asamp' ),
			'inactive' => __( 'Inactive', 'asamp' ),
		),
	);

	$fields[ 'member_date_joined' ] = array(
		'export'          => true,
		'priority'        => 120,
		'name'            => __( 'ASA Membership Join Date', 'asamp' ),
		'type'            => 'text_date',
		'default'         => date( 'Y-m-d' ),
		'visibility'      => array( 'admin' ),
		'date_format'     => 'Y-m-d',
	);

	$fields[ 'member_expiry' ] = array(
		'export'          => true,
		'priority'        => 140,
		'name'            => __( 'ASA Membership Expiration Date', 'asamp' ),
		'type'            => 'text_date',
		'default'         => date( 'Y-m-d' ),
		'visibility'      => array( 'admin' ),
		'date_format'     => 'Y-m-d',
	);

	$fields[ 'form_title' ] = array(
		'priority'        => 160,
		'name'            => array(
			'admin'      => __( 'Update ASA Member Profile', 'asamp' ),
			'member'     => __( 'Update ASA Member Profile', 'asamp' ),
			'non-member' => __( 'Create New ASA Member Profile', 'asamp' ),
		),
		'type'            => 'title',
		'render_row_cb'   => 'form_heading',
	);

	$fields[ 'section_company_info' ] = array(
		'priority'        => 180,
		'name'            => __( 'Company Info', 'asamp' ),
		'type'            => 'title',
		'visibility'      => array( 'member', 'non-member' ),
		'render_row_cb'   => 'open_fieldset',
	);

	$fields[ 'company_name' ] = array(
		'export'          => true,
		'priority'        => 200,
		'name'            => __( 'Company Name', 'asamp' ),
		'type'            => 'text',
		'attributes'      => array(
			'required' => 'required',
		),
	);

	$fields[ 'company_description' ] = array(
		'export'          => true,
		'priority'        => 220,
		'name'            => __( 'Company Description', 'asamp' ),
		'type'            => 'textarea',
	);

	$fields[ 'company_street' ] = array(
		'export'          => true,
		'priority'        => 240,
		'name'            => __( 'Company Address', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'company_city' ] = array(
		'export'          => true,
		'priority'        => 260,
		'name'            => __( 'City', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'company_state' ] = array(
		'export'          => true,
		'priority'        => 280,
		'name'            => __( 'State', 'asamp' ),
		'type'            => 'select',
		'default'         => 'opt_state_default',
		'options'         => 'get_us_states_array',
		'attributes'      => array(
			'required' => 'required',
		),
	);

	$fields[ 'company_zip' ] = array(
		'export'          => true,
		'priority'        => 300,
		'name'            => __( 'Zip', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'company_phone' ] = array(
		'export'          => true,
		'priority'        => 320,
		'name'            => __( 'Company Phone', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'company_fax' ] = array(
		'export'          => true,
		'priority'        => 340,
		'name'            => __( 'Company Fax', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'company_email' ] = array(
		'export'          => true,
		'priority'        => 360,
		'name'            => __( 'Company Email', 'asamp' ),
		'type'            => 'text_email',
		'attributes'  => array(
			'required' => 'required',
		),
	);

	$fields[ 'company_logo' ] = array(
		'priority'        => 380,
		'name'            => __( 'Company Logo', 'asamp' ),
		'type'            => 'text',
		'visibility'      => array( 'member' ),
		'attributes'  => array(
			'type' => 'file',
		),
	);

	$fields[ 'company_website' ] = array(
		'export'          => true,
		'priority'        => 400,
		'name'            => __( 'Website', 'asamp' ),
		'type'            => 'text_url',
		'protocols'       => array( 'http', 'https' ),
	);

	$fields[ 'company_year_founded' ] = array(
		'export'          => true,
		'priority'        => 420,
		'name'            => __( 'Year Founded', 'asamp' ),
		'type'            => 'select',
		'default'         => date( 'Y', strtotime( date( 'Y' ) . ' -5 years' ) ),
		'options'         => 'get_year_founded_array',
		'sanitization_cb' => 'absint',
	);

	$fields[ 'company_num_employees' ] = array(
		'export'          => true,
		'priority'        => 440,
		'name'            => __( 'Number of Employees', 'asamp' ),
		'type'            => 'text',
		'default'         => 3,
		'sanitization_cb' => 'absint',
		'attributes'      => array(
			'type'    => 'number',
			'min'     => 1,
		),
	);

	$fields[ 'company_business_type' ] = array(
		'export'          => true,
		'priority'        => 460,
		'name'            => __( 'Business Type/Trade', 'asamp' ),
		'type'            => 'multicheck_inline',
		'options'         => 'get_trades_array',
	);

	$fields[ 'company_business_type_other' ] = array(
		'export'          => true,
		'priority'        => 480,
		'name'            => __( 'Business Type/Trade Other', 'asamp' ),
		'type'            => 'text',
		'visibility'      => array(
			array(
				'option' => 'trades_other',
				'value'  => 'no',
				'negate' => true,
			),
		),
		'show_names'      => false,
		'repeatable'      => true,
		'attributes'      => array(
			'placeholder' => 'Other',
		),
		'text'            => array(
			'add_row_text' => __( 'Add Another "Other" Type/Trade', 'asamp' ),
		),
	);

	$fields[ 'section_company_contacts' ] = array(
		'priority'        => 500,
		'name'            => __( 'Contacts', 'asamp' ),
		'type'            => 'title',
		'visibility'      => array( 'member', 'non-member' ),
		'render_row_cb'   => 'open_fieldset',
	);

	$fields[ 'company_contacts' ] = array(
		'export'          => true,
		'priority'        => 520,
		'type'            => 'group',
		'options'         => array(
			'group_title'   => __( 'Contact #{#}', 'asamp' ),
			'add_button'    => __( 'Add Another Contact', 'asamp' ),
			'remove_button' => __( 'Remove Contact', 'asamp' ),
			'sortable'      => true,
		),
	);

	$fields[ 'name_first' ] = array(
		'export'          => true,
		'priority'        => 540,
		'parent'          => 'company_contacts',
		'name'            => __( 'First Name', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'name_last' ] = array(
		'export'          => true,
		'priority'        => 560,
		'parent'          => 'company_contacts',
		'name'            => __( 'Last Name', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'phone' ] = array(
		'export'          => true,
		'priority'        => 580,
		'parent'          => 'company_contacts',
		'name'            => __( 'Phone', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'fax' ] = array(
		'export'          => true,
		'priority'        => 600,
		'parent'          => 'company_contacts',
		'name'            => __( 'Fax', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'email' ] = array(
		'export'          => true,
		'priority'        => 620,
		'parent'          => 'company_contacts',
		'name'            => __( 'Email', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'title' ] = array(
		'export'          => true,
		'priority'        => 640,
		'parent'          => 'company_contacts',
		'name'            => __( 'Title', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'asa_position' ] = array(
		'export'          => true,
		'priority'        => 660,
		'parent'          => 'company_contacts',
		'name'            => __( 'ASA Position', 'asamp' ),
		'type'            => 'text',
	);

	$fields[ 'section_member_type' ] = array(
		'priority'        => 680,
		'name'            => __( 'Membership', 'asamp' ),
		'type'            => 'title',
		'visibility'      => array( 'non-member' ),
		'render_row_cb'   => 'open_fieldset',
	);

	$fields[ 'member_type' ] = array(
		'export'          => true,
		'priority'        => 700,
		'name'            => 'opt_member_type_label',
		'type'            => 'select',
		'visibility'      => array( 'non-member' ),
		'options'         => 'get_asamp_roles_select_with_price',
	);

	$fields[ 'section_login_info' ] = array(
		'priority'        => 720,
		'name'            => array(
			'member'     => __( 'Change Password', 'asamp' ),
			'non-member' => __( 'Login Info', 'asamp' ),
		),
		'type'            => 'title',
		'visibility'      => array( 'member', 'non-member' ),
		'render_row_cb'   => 'open_fieldset',
	);

	$fields[ 'login' ] = array(
		'export'          => true,
		'priority'        => 740,
		'name'            => __( 'Username', 'asamp' ),
		'type'            => 'text',
		'visibility'      => array( 'non-member' ),
		'attributes'      => array(
			'required' => 'required',
		),
	);

	$fields[ 'pass' ] = array(
		'export'          => true,
		'priority'        => 760,
		'name'            => array(
			'member'     => __( 'New Password', 'asamp' ),
			'non-member' => __( 'Password', 'asamp' ),
		),
		'type'            => 'text',
		'visibility'      => array( 'member', 'non-member' ),
		'attributes'      => array(
			'type'     => 'password',
			'required' => 'required',
		),
	);

	$fields[ 'pass_confirm' ] = array(
		'priority'        => 780,
		'name'            => __( 'Confirm Password', 'asamp' ),
		'type'            => 'text',
		'visibility'      => array( 'member', 'non-member' ),
		'attributes'      => array(
			'type'     => 'password',
			'required' => 'required',
		),
	);

	$fields[ 'section_end_form' ] = array(
		'priority'        => 800,
		'name'            => __( 'End Form', 'asamp' ),
		'type'            => 'title',
		'render_row_cb'   => 'close_fieldset',
	);

	return $fields;
}

?>
