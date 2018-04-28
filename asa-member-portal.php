<?php
/**
 * @wordpress-plugin
 * Plugin Name:       ASA Member Portal
 * Plugin URI:        https://github.com/lmgnow/asa-member-portal
 * Description:       Front-end registration and login forms, additional user info fields for members, and member directory.
 * Version:           0.1.2
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

$asamp = new ASA_Member_Portal();
class ASA_Member_Portal {
	private $plugin_file_path = '';      // str             (with trailing slash) Absolute path to this file.
	private $plugin_dir_path  = '';      // str             (with trailing slash) Absolute path to this directory.
	private $plugin_dir_url   = '';      // str             (with trailing slash) URL of this directory.
	private $plugin_data      = array(); // array
	private $options          = array(); // array           CMB2 options for this plugin.
	private $user             = null;    // WP_User object  Current logged in user.
	private $user_meta        = null;    // object          Current logged in user's user_meta data.
	private $is_member        = false;   // bool            true if $this->user is a member.
	private $fieldset_open    = false;   // bool            true if fieldset is already open.

	/**
	 * Constructs object.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->plugin_file_path = __FILE__;
		$this->plugin_dir_path  = plugin_dir_path( $this->plugin_file_path );
		$this->plugin_dir_url   = plugin_dir_url(  $this->plugin_file_path );

		require_once $this->plugin_dir_path . 'includes/vendor/autoload.php';
		require_once $this->plugin_dir_path . 'includes/vendor/webdevstudios/cmb2/init.php';
		require_once $this->plugin_dir_path . 'includes/vendor/rogerlos/cmb2-metatabs-options/cmb2_metatabs_options.php';

		require_once $this->plugin_dir_path . 'includes/pallazzio-wpghu/pallazzio-wpghu.php';
		new Pallazzio_WPGHU( $this->plugin_dir_path . wp_basename( $this->plugin_file_path ), 'lmgnow' );

		$this->options = get_option( 'asa_member_portal' );

		register_activation_hook(   $this->plugin_file_path, array( $this, 'activate'   ) );
		register_deactivation_hook( $this->plugin_file_path, array( $this, 'deactivate' ) );

		add_action( 'wp_enqueue_scripts',    array( $this, 'asamp_enqueue' )        );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ), 10, 1 );

		add_action(    'add_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );
		add_action( 'update_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );
		add_action( 'delete_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );

		add_action( 'user_register',  array( $this, 'set_user_options' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'set_user_options' ), 10, 1 );

		add_action( 'save_post_dues_payment', array( $this, 'dues_payment_save' ), 10, 3 );

		add_action( 'cmb2_init',       array( $this, 'user_meta_init'        ) );
		add_action( 'cmb2_init',       array( $this, 'payment_form_init'     ) );
		add_action( 'cmb2_init',       array( $this, 'login_form_init'       ) );
		add_action( 'cmb2_init',       array( $this, 'dues_payments_init'    ) );
		add_action( 'cmb2_admin_init', array( $this, 'options_init'          ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_profile' ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_login'   ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_dues_payment' ) );

		add_action( 'init', array( $this, 'disallow_dashboard_access' ) );
		add_action( 'init', array( $this, 'get_this_plugin_data'      ) );
		add_action( 'init', array( $this, 'register_post_types'       ) );
		add_action( 'init', array( $this, 'register_shortcodes'       ) );

		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file_path ), array( $this, 'add_settings_link' ), 10, 1 );
	}

	/**
	 * Sets up initial plugin settings, data, etc.
	 *
	 * @return void
	 */
	public function activate() {
		$this->create_roles( 'asa_member_portal', array( array( 'name' => $this->get_default_member_type_name() ) ) );
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
	}

	/**
	 * Loads frontend scripts and stylesheets.
	 *
	 * @return void
	 */
	public function asamp_enqueue() {
		wp_enqueue_style(  'asamp_style',  $this->plugin_dir_url . 'css/asamp-style.css', array(), $this->plugin_data[ 'Version' ], 'screen' );
		wp_enqueue_script( 'asamp_script', $this->plugin_dir_url .  'js/asamp-script.js', array(), $this->plugin_data[ 'Version' ], true     );
	}

	/**
	 * Loads admin scripts and stylesheets.
	 *
	 * @param string $hook
	 *
	 * @return void
	 */
	public function admin_enqueue( $hook ) {
		$hooks = array( 'settings_page_asa_member_portal', 'user-new.php', 'profile.php' );
		foreach ( $hooks as $v ) {
			if ( $v === $hook ) {
				wp_enqueue_style(  'asamp_admin_style',  $this->plugin_dir_url . 'css/asamp-admin-style.css', array(), $this->plugin_data[ 'Version' ], 'screen' );
				wp_enqueue_script( 'asamp_admin_script', $this->plugin_dir_url .  'js/asamp-admin-script.js', array(), $this->plugin_data[ 'Version' ], true     );
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
		$settings_link = '<a href="options-general.php?page=asa_member_portal&tab=opt-tab-general">' . __( 'Settings', 'asamp' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Redirects members who try to visit the admin dashboard.
	 *
	 * @return void
	 */
	public function disallow_dashboard_access() {
		if ( is_admin() && $this->is_member() && in_array( $this->user()->roles[ 0 ], array_keys( $this->get_asamp_roles() ) ) ) {
			$pp = ! empty( $this->options[ 'page_profile' ] ) ? get_the_permalink( $this->options[ 'page_profile' ] ) : home_url();
			wp_redirect( $pp );
			exit();
		}
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

		$this->options = get_option( 'asa_member_portal' );
		if ( ! empty( $this->options[ 'member_types' ] ) ) {
			$member_types = $this->options[ 'member_types' ];
		} else {
			$member_types = array( array( 'name' => $this->get_default_member_type_name() ) );
		}
		foreach ( $member_types as $member_type ) {
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
	 * @return array $roles
	 */
	private function get_asamp_role_select() {
		if ( ! empty( $this->options[ 'member_types' ] ) ) {
			$member_types = $this->options[ 'member_types' ];
		} else {
			$member_types = array( array( 'name' => $this->get_default_member_type_name() ) );
		}
		$roles = array();
		foreach ( $member_types as $member_type ) {
			$str = sprintf( __( '(Dues: $%s/Year)', 'asamp' ), $member_type[ 'dues' ] );
			$roles[ 'asamp_' . sanitize_key( $member_type[ 'name' ] ) ] = $member_type[ 'name' ] . ' ' . $str;
		}

		return $roles;
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
		$roles = ( array ) $this->user->roles;
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
	}

	/**
	 * Generates a member registration/profile form.
	 *
	 * @param array $atts Shortcode atts.
	 *
	 * @return string $output
	 */
	public function shortcode_asamp_member_profile( $atts = array() ) {
		$form_id = 'asamp_user_edit';
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
			?>
				<script>
					(function($){
						"use strict";
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
	 * Generates a member status widget or a login form if not logged in.
	 *
	 * @param array $atts
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_login_box( $atts = array() ) {
		$prefix = 'asamp_login_';
		$output = '';

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

			$output .= ! empty( $pp = $this->options[ 'page_profile' ] ) ? '<a class="btn btn-success button button-success" href="' . get_the_permalink( $pp ) . '">' . __( 'Register', 'asamp' ) . '</a>' : '';
		}

		return $output;
	}

	/**
	 * Generates a payment form.
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_payment_form( $atts = array() ) {
		$prefix = 'asamp_payment_';
		$output = '';

		//$output .= '<pre>' . print_r( get_editable_roles(), true ) . '</pre>';
		//$output .= '<pre>' . print_r( $this->options, true ) . '</pre>';

		$cmb = cmb2_get_metabox( $prefix . 'form' );

		if ( ( $error = $cmb->prop( 'submission_error' ) ) ) {
			$output .= '<div class="alert alert-danger asamp-submission-error-message">' . sprintf( __( 'There was an error in the submission: %s', 'asamp' ), '<strong>' . $error . '</strong>' ) . '</div>';
		}

		$output .= cmb2_get_metabox_form( $cmb, '', array( 'save_button' => __( 'Submit Payment', 'asamp' ) ) );

		return $output;
	}

	/**
	 * Generates a member directory.
	 *
	 * @return str $output
	 */
	public function shortcode_asamp_member_directory( $atts = array() ) {
		$output = '';
		$args = array(
			'role__in'   => array_keys( $this->get_asamp_roles() ),
			'meta_key'   => 'asamp_user_member_status',
			'meta_value' => 'active',
		);
		$user_query = new WP_User_Query( $args );

		//$output .= '<pre>' . print_r( $user_query, true ) . '</pre>';
		$show = $this->options[ 'profiles_public' ];

		if ( 'none' === $show && ! $this->is_member() )     return __( 'Please log in or register. Only members can see info about other members.', 'asamp' );
		if ( empty( $users = $user_query->get_results() ) ) return __( 'No active members found.', 'asamp' );

		$show = $this->is_member() ? 'all' : $show;

		ob_start();
		?>
			<ul class="asamp-dir">
				<?php foreach ( $users as $user ) : ?>
					<?php $meta = $this->flatten_array( get_user_meta( $user->ID ) ); ?>
					<li class="asamp-member">
						<h3 class="asamp-company-name"><?php echo $meta[ 'asamp_user_company_name' ]; ?></h3>
						<?php if ( 'all' === $show ) : ?>
							<p><a href="<?php echo $meta[ 'asamp_user_company_website' ]; ?>" target="_blank" rel="noopener"><?php echo $meta[ 'asamp_user_company_website' ]; ?></a></p>
						<?php endif; ?>
						<p><?php echo $meta[ 'asamp_user_company_description' ]; ?></p>
						<p>
							<?php
								$business_types = maybe_unserialize( $meta[ 'asamp_user_company_business_type' ] );
								if ( is_array( $business_types ) ) {
									$str = '';
									foreach ( $business_types as $type ) {
										$str .= $type . ', ';
									}
									$business_types = rtrim( $str, ', ' );
								}
								echo $business_types;
							?>
						</p>
						<?php if ( 'all' === $show ) : ?>
							
							<p><a href="tel:<?php echo $meta[ 'asamp_user_company_phone' ]; ?>"><?php echo $meta[ 'asamp_user_company_phone' ]; ?></a></p>
							<p><a href="mailto:<?php echo $meta[ 'asamp_user_company_email' ]; ?>"><?php echo $meta[ 'asamp_user_company_email' ]; ?></a></p>
							<address>
								<?php echo $meta[ 'asamp_user_company_street' ]; ?> <br />
								<?php echo $meta[ 'asamp_user_company_city' ] . ', ' . $meta[ 'asamp_user_company_state' ] . ' ' . $meta[ 'asamp_user_company_zip' ]; ?>
							</address>

							<?php $contacts = maybe_unserialize( $meta[ 'asamp_user_company_contacts' ] ); ?>
							<?php if ( is_array( $contacts ) ) : ?>
								<ol class="asamp-contacts">
									<?php foreach ( $contacts as $contact ) : ?>
										<li class="asamp-contact">
											<h4><?php echo $contact[ 'name_first' ] . ' ' . $contact[ 'name_last' ]; ?></h4>
											<p><a href="tel:<?php echo $contact[ 'phone' ]; ?>"><?php echo $contact[ 'phone' ]; ?></a></p>
											<p><?php echo $contact[ 'fax' ]; ?></p>
											<p><a href="mailto:<?php echo $contact[ 'email' ]; ?>"><?php echo $contact[ 'email' ]; ?></a></p>
											<p><?php echo $contact[ 'title' ]; ?></p>
											<p><?php echo $contact[ 'asa_position' ]; ?></p>
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
			$roles = ( array ) $this->user()->roles;
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
				$r[ $k ] = $v[ 0 ];
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
		$r = array( 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusets', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Vitginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming' );
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
	 * Checks which form is being handled.
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	private function verify_cmb_form( $key ) {
		if ( empty( $_POST ) )                                          return false;
		if ( ! isset( $_POST[ 'submit-cmb' ], $_POST[ 'object_id' ] ) ) return false;
		if ( ! wp_verify_nonce( $_POST[ $key ], $key ) )                return false;
		if ( is_admin() )                                               return false;

		return true;
	}

	/**
	 * Handles dues payment form submission and payment processing.
	 *
	 * @return void
	 */
	public function frontend_dues_payment() {
		$prefix = 'asamp_payment_';
		if ( ! $this->verify_cmb_form( $prefix . 'nonce' ) ) return false;

		$cmb = cmb2_get_metabox( $prefix . 'form' );

		if ( ! isset( $_POST[ $cmb->nonce() ] ) || ! wp_verify_nonce( $_POST[ $cmb->nonce() ], $cmb->nonce() ) ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'security_fail', __( 'Security check failed.', 'asamp' ) ) );
		}

		$sanitized_values = $cmb->get_sanitized_values( $_POST );

		foreach ( $this->options as $k => $v ) {
			if ( 0 === strpos( $k, 'payment_' ) && false !== strpos( $k, '_enabled' ) && $v === 'yes' ) {
				$payment_processor = str_replace( '_enabled', '', $k );
				break;
			}
		}

		$gateway_init = array();
		foreach ( $this->options as $k => $v ) {
			if ( 0 === strpos( $k, $payment_processor ) && false === strpos( $k, '_enabled' ) ) {
				$k = end( explode( '_', $k ) );
				$gateway_init[ $k ] = 'testMode' === $k ? 1 : $v;
			}
		}

		$gateway = Omnipay::create( str_replace( 'payment_', '', $payment_processor ) );
		$gateway->initialize( $gateway_init );

		try {
			$response = $gateway->purchase( array(
				'amount'   => $this->get_dues_amount_from_role_slug( $sanitized_values[ $prefix . 'member_type' ] ),
				'currency' => 'USD',
				'card'     => array(
					'number'      => $sanitized_values[ $prefix . 'cc_number' ],
					'expiryMonth' => $sanitized_values[ $prefix . 'cc_month'  ],
					'expiryYear'  => $sanitized_values[ $prefix . 'cc_year'   ],
					'cvv'         => $sanitized_values[ $prefix . 'cc_cvv'    ],
				),
			) )->send();

			if ( $response->isSuccessful() ) {
				wp_redirect( esc_url_raw( add_query_arg( 'payment_received', 'true' ) ) );
				exit();
			} elseif ( $response->isRedirect() ) {
				$response->redirect();
			} else {
				// Payment failed
				return $cmb->prop( 'submission_error', $response->getMessage() );
			}
		} catch ( \Exception $e ) {
			return $cmb->prop( 'submission_error', $e->getMessage() );
		}
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
		if ( ! $this->verify_cmb_form( $prefix . 'nonce' ) ) return false;

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
		$prefix = 'asamp_user_';
		if ( ! $this->verify_cmb_form( $prefix . 'nonce' ) ) return false;
		
		$cmb = cmb2_get_metabox( $prefix . 'edit', $this->user()->ID );

		if ( ! isset( $_POST[ $cmb->nonce() ] ) || ! wp_verify_nonce( $_POST[ $cmb->nonce() ], $cmb->nonce() ) ) {
			return $cmb->prop( 'submission_error', new WP_Error( 'security_fail', __( 'Security check failed.', 'asamp' ) ) );
		}

		$sanitized_values = $cmb->get_sanitized_values( $_POST );

		if ( $validation_errors = $this->validate_user_profile( $sanitized_values, $_POST, $prefix ) ) {
			$cmb->prop( 'submission_error', new WP_Error( 'validation_fail', __( 'Please correct the errors below.', 'asamp' ) ) );
			$cmb->prop( 'validation_errors', $validation_errors );
			return $cmb;
		}

		$dest = add_query_arg( 'member_updated', 'true' );

		if ( $this->is_member() ) {
			$this->user()->user_pass = ! empty( $sanitized_values[ $prefix . 'pass' ] ) ? $sanitized_values[ $prefix . 'pass' ] : $this->user()->user_pass;

			$user_id = wp_update_user( $this->user() );
		} else {
			$userdata = array(
				'user_pass'            => $sanitized_values[ $prefix . 'pass' ],
				'user_login'           => $sanitized_values[ $prefix . 'login' ],
				'user_nicename'        => sanitize_html_class( $sanitized_values[ $prefix . 'login' ] ),
				'user_url'             => $sanitized_values[ $prefix . 'company_website' ],
				'user_email'           => $sanitized_values[ $prefix . 'company_email' ],
				'display_name'         => $sanitized_values[ $prefix . 'company_name' ],
				'description'          => $sanitized_values[ $prefix . 'company_description' ],
				'rich_editing'         => false,
				'syntax_highlighting'  => false,
				'show_admin_bar_front' => false,
				'role'                 => $sanitized_values[ $prefix . 'member_type' ],
			);

			$user_id = wp_insert_user( $userdata );

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
		unset( $sanitized_values[ $prefix . 'pass' ], $sanitized_values[ $prefix . 'pass_confirm' ] );
		
		$cmb->save_fields( $user_id, 'user', $sanitized_values );

		$img_id = $this->frontend_image_upload( $prefix . 'company_logo' );

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

		$phone = preg_replace( '/[^0-9]/', '', $sanitized_values[ $prefix . 'company_phone' ] );
		if ( strlen( $phone ) === 11) $phone = preg_replace( '/^1/', '', $phone );
		if ( strlen( $phone ) !== 10 && ! empty( $sanitized_values[ $prefix . 'company_phone' ] ) ) {
			$bad_fields[ $prefix . 'company_phone' ] = 'Please enter a 10-digit phone number.';
		}

		if ( ! is_email( $sanitized_values[ $prefix . 'company_email' ] ) ) {
			$bad_fields[ $prefix . 'company_email' ] = 'Please enter a valid email address.';
		}

		/*if ( false === filter_var( $sanitized_values[ $prefix . 'company_website' ], FILTER_VALIDATE_URL ) ) {
			$bad_fields[ $prefix . 'company_website' ] = 'Please enter a valid web url.';
		}*/

		if ( is_array( $sanitized_values[ $prefix . 'company_contacts' ] ) ) {
			foreach ( $sanitized_values[ $prefix . 'company_contacts' ] as $k => $contact ) {
				unset( $phone );
				$phone = preg_replace( '/[^0-9]/', '', $contact[ 'phone' ] );
				if ( strlen( $phone ) === 11) $phone = preg_replace( '/^1/', '', $phone );
				if ( strlen( $phone ) !== 10 && ! empty( $contact[ 'phone' ] ) ) {
					$bad_fields[ $prefix . 'company_contacts_' . $k . '_phone' ] = 'Please enter a 10-digit phone number.';
				}

				unset( $fax );
				$fax = preg_replace( '/[^0-9]/', '', $contact[ 'fax' ] );
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
	 * Adds tabs to the plugin options page.
	 *
	 * @return array $tabs
	 */
	private function options_add_tabs() {
		$tabs = array(
			array(
				'id'    => 'general',
				'title' => __( 'General', 'asamp' ),
				'boxes' => array(
					'registration',
					'directory',
					'pages',
					'administration',
				),
			),
			array(
				'id'    => 'payment',
				'title' => __( 'Payment', 'asamp' ),
				'boxes' => array(
					'payment_PayPal_Pro',
					'payment_Stripe',
				),
			),
		);
		
		return $tabs;
	}

	/**
	 * Adds plugin options.
	 *
	 * @return void
	 */
	public function options_init() {
		$options_key = 'asa_member_portal';

		new Cmb2_Metatabs_Options( array(
			'key'      => $options_key,
			'title'    => __( 'ASA Member Portal Settings', 'asamp' ),
			'topmenu'  => 'options-general.php',
			'resettxt' => ''/*__( 'Restore Defaults', 'asamp' )*/,
			'boxes'    => $this->options_add_boxes( $options_key ),
			'tabs'     => $this->options_add_tabs(),
			'menuargs' => array(
				'menu_title'      => __( 'ASA Membership', 'asamp' ),
				'capability'      => 'manage_options',
				'view_capability' => 'manage_options',
			),
		) );
	}

	/**
	 * Adds custom fields to custom post type 'dues_payment'.
	 *
	 * @return void
	 */
	public function dues_payments_init() {
		$prefix = '_asamp_dues_';

		$cmb = new_cmb2_box( array(
			'id'           => $prefix . 'edit',
			'title'        => __( 'Payment Details', 'asamp' ),
			'object_types' => array( 'dues_payment' ),
			'show_names'   => true,
		) );

		$cmb->add_field( array(
			'name' => __( 'Amount', 'asamp' ),
			'id'   => $prefix . 'amount',
			'type' => 'text_money',
		) );

		$cmb->add_field( array(
			'name' => __( 'Name on Card', 'asamp' ),
			'id'   => $prefix . 'cc_name',
			'type' => 'text',
		) );

	}

	/**
	 * For post type 'dues_payment'. Sets post_title, post_name, and post_status.
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

		register_post_type( 'dues_payment', $args );
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
			'name'       => ! empty( $this->options[ 'member_type_label' ] ) ? $this->options[ 'member_type_label' ] : $this->get_default_member_type_label(),
			'id'         => $prefix . 'member_type',
			'type'       => 'select',
			'default'    => $this->user()->roles[ 0 ],
			'options'    => $this->get_asamp_role_select(),
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
	}

	/**
	 * Adds boxes to plugin options.
	 *
	 * This is typical CMB2, but note two crucial extra items:
	 * - the ['show_on'] property is configured
	 * - a call to object_type method
	 *
	 * @param  string $options_key
	 *
	 * @return array $boxes
	 */
	private function options_add_boxes( $options_key ) {
		//holds all CMB2 box objects
		$boxes = array();
		
		//add this to all boxes
		$show_on = array(
			'key'   => 'options-page',
			'value' => array( $options_key ),
		);

		//some default values
		$admin_name  = get_bloginfo( 'name' );
		$admin_email = get_bloginfo( 'admin_email' );
		
		$cmb = new_cmb2_box( array(
			'id'              => 'registration',
			'title'           => __( 'Profile/Registration', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Default State', 'asamp' ),
			'desc'            => __( 'Which State should be selected by default on the registration form?', 'asamp' ),
			'id'              => 'state_default',
			'type'            => 'select',
			'options'         => $this->get_us_states_array(),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Number of Contacts Allowed', 'asamp' ),
			'desc'            => __( 'Please be careful. If a user already has multiple contacts, then you decrease this to a number that is lower than what they have, some of their contacts may disappear.', 'asamp' ),
			'id'              => 'num_contacts',
			'type'            => 'text',
			'sanitization_cb' => 'absint',
			'default'         => $this->get_default_num_contacts(),
			'attributes'      => array(
				'type'    => 'number',
				'pattern' => '\d*',
				'min'     => 0,
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Trades', 'asamp' ),
			'desc'            => __( 'Add one trade per line. Please be careful. If a user already has a trade selected, then you remove the trade, their selection for that trade may be lost.', 'asamp' ),
			'id'              => 'trades',
			'type'            => 'textarea',
			'default'         => function(){
				$r = $this->get_default_trades_array();
				$trades = '';
				foreach ( $r as $v ) {
					$trades .= $v . "\r\n";
				}
				return rtrim( $trades, "\r\n" );
			},
		) );
		$cmb->add_field( array(
			'name'            => __( 'Include "Other" Trades Option', 'asamp' ),
			'desc'            => __( 'Please be careful. If a user already has any "Other Trades" saved, then you remove the "Other Trade" option, their "Other Trades" may be lost.', 'asamp' ),
			'id'              => 'trades_other',
			'type'            => 'radio_inline',
			'default'         => 'yes',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$has_recaptcha = get_option( 'gglcptch_options' );
		if ( empty( $has_recaptcha[ 'public_key' ] ) && empty( $has_recaptcha[ 'private_key' ] ) ) {
			$cmb->add_field( array(
				'name'            => __( 'Google reCaptcha Site Key', 'asamp' ),
				'id'              => 'google_recaptcha_site_key',
				'type'            => 'text',
			) );
			$cmb->add_field( array(
				'name'            => __( 'Google reCaptcha Secret Key', 'asamp' ),
				'id'              => 'google_recaptcha_secret_key',
				'type'            => 'text',
			) );
		}
		$cmb->add_field( array(
			'name'            => __( 'Member Type Label', 'asamp' ),
			'id'              => 'member_type_label',
			'type'            => 'text',
			'default'         => $this->get_default_member_type_label(),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Member Type(s)', 'asamp' ),
			'desc'            => __( 'Please be careful. If a user is already set as a certain member type, then you remove that type, their entire user account may be lost.', 'asamp' ),
			'id'              => 'member_types',
			'type'            => 'group',
			'options'         => array(
				'sortable'      => true,
				'group_title'   => 'Member Type #{#}',
				'add_button'    => __( 'Add Member Type',    'asamp' ),
				'remove_button' => __( 'Remove Member Type', 'asamp' ),
			),
		) );
		$cmb->add_group_field( 'member_types', array(
			'name'            => __( 'Name', 'asamp' ),
			'id'              => 'name',
			'type'            => 'text',
			'default'         => $this->get_default_member_type_name(),
			'attributes'      => array(
				'required' => 'required',
			),
		) );
		$cmb->add_group_field( 'member_types', array(
			'name'            => __( 'Dues Amount', 'asamp' ),
			'id'              => 'dues',
			'type'            => 'text_money',
			'default'         => $this->get_default_member_type_dues(),
			'attributes'      => array(
				'required' => 'required',
			),
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'directory',
			'title'           => __( 'Member Directory', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Member Info Security', 'asamp' ),
			'desc'            => __( 'Choose amount of member info to show to non-members.', 'asamp' ),
			'id'              => 'profiles_public',
			'type'            => 'radio_inline',
			'default'         => 'basic',
			'options'         => array(
				'all'   => __( 'All',   'asamp' ),
				'basic' => __( 'Basic', 'asamp' ),
				'none'  => __( 'None',  'asamp' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'List Member Types Grouped Separately', 'asamp' ),
			'id'              => 'members_grouped_by_type',
			'type'            => 'radio_inline',
			'default'         => 'yes',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'pages',
			'title'           => __( 'Pages', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Profile/Registration Form', 'asamp' ),
			'desc'            => __( 'The page that contains the shortcode: [asamp_member_profile]', 'asamp' ),
			'id'              => 'page_profile',
			'type'            => 'select',
			'options'         => $this->get_pages_array(),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Payment Form', 'asamp' ),
			'desc'            => __( 'The page that contains the shortcode: [asamp_member_payment_form]', 'asamp' ),
			'id'              => 'page_payment_form',
			'type'            => 'select',
			'options'         => $this->get_pages_array(),
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'administration',
			'title'           => __( 'Administration', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Admin Contact(s)', 'asamp' ),
			'desc'            => __( 'Send all emails generated by this plugin to the following recipients.', 'asamp' ),
			'id'              => 'admin_contacts',
			'type'            => 'group',
			'options'         => array(
				'sortable'      => true,
				'group_title'   => 'Contact #{#}',
				'add_button'    => __( 'Add Contact',    'asamp' ),
				'remove_button' => __( 'Remove Contact', 'asamp' ),
			),
		) );
		$cmb->add_group_field( 'admin_contacts', array(
			'name'            => __( 'Name', 'asamp' ),
			'id'              => 'name',
			'type'            => 'text',
			'default'         => $admin_name,
			'attributes'      => array(
				'required' => 'required',
			),
		) );
		$cmb->add_group_field( 'admin_contacts', array(
			'name'            => __( 'Address', 'asamp' ),
			'id'              => 'email',
			'type'            => 'text_email',
			'default'         => $admin_email,
			'attributes'      => array(
				'required' => 'required',
			),
		) );
		$cmb->add_group_field( 'admin_contacts', array(
			'name'            => __( 'Type', 'asamp' ),
			'id'              => 'type',
			'type'            => 'radio_inline',
			'default'         => 'to',
			'options'         => array(
				'to'  => __( 'To',  'asamp' ),
				'cc'  => __( 'CC',  'asamp' ),
				'bcc' => __( 'BCC', 'asamp' ),
			),
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'payment_PayPal_Pro',
			'title'           => __( 'PayPal Pro', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro Enabled', 'asamp' ),
			'id'              => 'payment_PayPal_Pro_enabled',
			'type'            => 'radio_inline',
			'default'         => 'no',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Username', 'asamp' ),
			'id'              => 'payment_PayPal_Pro_username',
			'type'            => 'text',
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Password', 'asamp' ),
			'id'              => 'payment_PayPal_Pro_password',
			'type'            => 'text',
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Signature', 'asamp' ),
			'id'              => 'payment_PayPal_Pro_signature',
			'type'            => 'text',
		) );
		$cmb->add_field( array(
			'name'            => __( 'Use PayPal Pro in Test Mode', 'asamp' ),
			'id'              => 'payment_PayPal_Pro_testMode',
			'type'            => 'checkbox',
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'payment_Stripe',
			'title'           => __( 'Stripe', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Stripe Enabled', 'asamp' ),
			'id'              => 'payment_Stripe_enabled',
			'type'            => 'radio_inline',
			'default'         => 'no',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Stripe API Key', 'asamp' ),
			'id'              => 'payment_Stripe_apiKey',
			'type'            => 'text',
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
		$prefix = 'asamp_user_';

		$cmb_user = new_cmb2_box( array(
			'id'               => $prefix . 'edit',
			'object_types'     => array( 'user' ),
			'show_names'       => true,
			'new_user_section' => 'add-new-user',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'ASA Membership Status', 'asamp' ),
			'id'              => $prefix . 'member_status',
			'type'            => 'radio_inline',
			'on_front'        => false,
			'default'         => 'inactive',
			'options'         => array(
				'active'   => __( 'Active',   'asamp' ),
				'inactive' => __( 'Inactive', 'asamp' ),
			),
		) );

		$cmb_user->add_field( array(
			'name'            => $this->is_member() || is_admin() ? __( 'Update ASA Member Profile', 'asamp' ) : __( 'Create New ASA Member Profile', 'asamp' ),
			'id'              => $prefix . 'form_title',
			'type'            => 'title',
			'render_row_cb'   => array( $this, 'form_heading' ),
		) );

		if ( ! is_admin() ) {
			$cmb_user->add_field( array(
				'name'            => __( 'Company Info', 'asamp' ),
				'id'              => $prefix . 'section_company_info',
				'type'            => 'title',
				'render_row_cb'   => array( $this, 'open_fieldset' ),
			) );
		}

		$cmb_user->add_field( array(
			'name'            => __( 'Company Name', 'asamp' ),
			'id'              => $prefix . 'company_name',
			'type'            => 'text',
			'attributes'      => array(
				'required' => 'required',
			),
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Company Description', 'asamp' ),
			'id'              => $prefix . 'company_description',
			'type'            => 'textarea',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Company Address', 'asamp' ),
			'id'              => $prefix . 'company_street',
			'type'            => 'text',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'City', 'asamp' ),
			'id'              => $prefix . 'company_city',
			'type'            => 'text',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'State', 'asamp' ),
			'id'              => $prefix . 'company_state',
			'type'            => 'select',
			'default'         => $this->options[ 'state_default' ],
			'options'         => $this->get_us_states_array(),
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Zip', 'asamp' ),
			'id'              => $prefix . 'company_zip',
			'type'            => 'text',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Company Phone', 'asamp' ),
			'id'              => $prefix . 'company_phone',
			'type'            => 'text',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Company Email', 'asamp' ),
			'id'              => $prefix . 'company_email',
			'type'            => 'text_email',
			'attributes'  => array(
				'required' => 'required',
			),
		) );

		if ( $this->is_member() ) {
			$cmb_user->add_field( array(
				'name'            => 'Company Logo',
				'id'              => $prefix . 'company_logo',
				'type'            => 'text',
				'attributes'      => array(
					'type' => 'file',
				),
			) );
		}

		$cmb_user->add_field( array(
			'name'            => __( 'Website', 'asamp' ),
			'id'              => $prefix . 'company_website',
			'type'            => 'text_url',
			'protocols'       => array( 'http', 'https' ),
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Year Founded', 'asamp' ),
			'id'              => $prefix . 'company_year_founded',
			'type'            => 'select',
			'sanitization_cb' => 'absint',
			'default'         => date( 'Y', strtotime( date( 'Y' ) . ' -5 years' ) ),
			'options'         => $this->get_years_array( date( 'Y', strtotime( date( 'Y' ) . ' -100 years' ) ) ),
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Number of Employees', 'asamp' ),
			'id'              => $prefix . 'company_num_employees',
			'type'            => 'text',
			'sanitization_cb' => 'absint',
			'default'         => 3,
			'attributes'      => array(
				'type'    => 'number',
				'pattern' => '\d*',
				'min'     => 1,
			),
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Business Type/Trade', 'asamp' ),
			'id'              => $prefix . 'company_business_type',
			'type'            => 'multicheck_inline',
			'options'         => function(){
				$asamp_member_portal_trades = array();
				if ( ! empty( $this->options[ 'trades' ] ) ) {
					$asamp_member_portal_trades = explode( "\r\n", $this->options[ 'trades' ] );
				} else {
					$asamp_member_portal_trades = $this->get_default_trades_array();
				}

				$r = array();
				foreach ( $asamp_member_portal_trades as $v ) {
					$r[ esc_attr__( $v ) ] = $v;
				}

				return $r;
			},
		) );

		if ( 'no' !== $this->options[ 'trades_other' ] ) {
			$cmb_user->add_field( array(
				'name'            => __( 'Business Type/Trade Other', 'asamp' ),
				'id'              => $prefix . 'company_business_type_other',
				'type'            => 'text',
				'show_names'      => false,
				'repeatable'      => true,
				'attributes'      => array(
					'placeholder' => 'Other',
				),
				'text'            => array(
					'add_row_text' => __( 'Add Another "Other" Type/Trade', 'asamp' ),
				),
			) );
		}

		if ( ! is_admin() ) {
			$cmb_user->add_field( array(
				'name'            => __( 'Contacts', 'asamp' ),
				'id'              => $prefix . 'section_company_contacts',
				'type'            => 'title',
				'render_row_cb'   => array( $this, 'open_fieldset' ),
			) );
		}

		$group_field_id = $cmb_user->add_field( array(
			'id'              => $prefix . 'company_contacts',
			'type'            => 'group',
			'options'         => array(
				'group_title'   => __( 'Contact #{#}', 'asamp' ),
				'add_button'    => __( 'Add Another Contact', 'asamp' ),
				'remove_button' => __( 'Remove Contact', 'asamp' ),
				'sortable'      => true,
			),
		) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'First Name', 'asamp' ),
				'id'              => 'name_first',
				'type'            => 'text',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'Last Name', 'asamp' ),
				'id'              => 'name_last',
				'type'            => 'text',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'Phone', 'asamp' ),
				'id'              => 'phone',
				'type'            => 'text',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'Fax', 'asamp' ),
				'id'              => 'fax',
				'type'            => 'text',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'Email', 'asamp' ),
				'id'              => 'email',
				'type'            => 'text_email',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'Title', 'asamp' ),
				'id'              => 'title',
				'type'            => 'text',
			) );

			$cmb_user->add_group_field( $group_field_id, array(
				'name'            => __( 'ASA Position', 'asamp' ),
				'id'              => 'asa_position',
				'type'            => 'text',
			) );

		if ( ! is_admin() ) {
			if ( ! $this->is_member() ) {

				$cmb_user->add_field( array(
					'name'            => __( 'Membership', 'asamp' ),
					'id'              => $prefix . 'section_member_type',
					'type'            => 'title',
					'render_row_cb'   => array( $this, 'open_fieldset' ),
				) );

				$cmb_user->add_field( array(
					'name'            => ! empty( $this->options[ 'member_type_label' ] ) ? $this->options[ 'member_type_label' ] : $this->get_default_member_type_label(),
					'id'              => $prefix . 'member_type',
					'type'            => 'select',
					'options'         => $this->get_asamp_role_select(),
				) );

				$cmb_user->add_field( array(
					'name'            => __( 'Login Info', 'asamp' ),
					'id'              => $prefix . 'section_login_info',
					'type'            => 'title',
					'render_row_cb'   => array( $this, 'open_fieldset' ),
				) );

				$cmb_user->add_field( array(
					'name'            => __( 'Username', 'asamp' ),
					'id'              => $prefix . 'login',
					'type'            => 'text',
					'attributes'      => array(
						'required' => 'required',
					),
				) );
			}

			if ( $this->is_member() ) {
				$cmb_user->add_field( array(
					'name'            => __( 'Login Info', 'asamp' ),
					'id'              => $prefix . 'section_login_info',
					'type'            => 'title',
					'render_row_cb'   => array( $this, 'open_fieldset' ),
				) );
			}
			
			$args = array(
				'name'            => $this->is_member() ? __( 'New Password', 'asamp' ) : __( 'Password', 'asamp' ),
				'id'              => $prefix . 'pass',
				'type'            => 'text',
				'attributes'      => array(
					'type'     => 'password',
				),
			);
			if ( ! $this->is_member() ) $args[ 'attributes' ][ 'required' ] = 'required';
			$cmb_user->add_field( $args );

			$args = array(
				'name'            => __( 'Confirm Password', 'asamp' ),
				'id'              => $prefix . 'pass_confirm',
				'type'            => 'text',
				'attributes'      => array(
					'type'     => 'password',
				),
			);
			if ( ! $this->is_member() ) $args[ 'attributes' ][ 'required' ] = 'required';
			$cmb_user->add_field( $args );

			$cmb_user->add_hidden_field( array(
				'field_args'  => array(
					'id'      => $prefix . 'nonce',
					'type'    => 'hidden',
					'default' => wp_create_nonce( $prefix . 'nonce' ),
				),
			) );

			$cmb_user->add_field( array(
				'name'            => __( 'End Form', 'asamp' ),
				'id'              => $prefix . 'section_end_form',
				'type'            => 'title',
				'render_row_cb'   => array( $this, 'close_fieldset' ),
			) );
		}
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

?>
