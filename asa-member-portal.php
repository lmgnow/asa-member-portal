<?php
/**
 * @wordpress-plugin
 * Plugin Name:       ASA Member Portal
 * Plugin URI:        https://github.com/lmgnow/asa-member-portal
 * Description:       Front-end registration and login forms, additional user info fields for members, and member directory.
 * Version:           0.0.1
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
	private $plugin_file_path = null; // string          (with trailing slash) Absolute path to this file.
	private $plugin_dir_path  = null; // string          (with trailing slash) Absolute path to this directory.
	private $plugin_dir_url   = null; // string          (with trailing slash) URL of this directory.
	private $plugin_data      = null; // array
	private $options          = null; // array           CMB2 options for this plugin.
	private $user             = null; // WP_User object  Current logged in user.
	private $is_member        = null; // bool            True if $this->user is a member.

	/**
	 * Constructs object.
	 *
	 * @return null
	 */
	public function __construct() {
		$this->plugin_file_path = __FILE__;
		$this->plugin_dir_path  = plugin_dir_path( $this->plugin_file_path );
		$this->plugin_dir_url   = plugin_dir_url(  $this->plugin_file_path );

		require_once $this->plugin_dir_path . 'includes/vendor/autoload.php';
		require_once $this->plugin_dir_path . 'includes/vendor/webdevstudios/cmb2/init.php';
		require_once $this->plugin_dir_path . 'includes/vendor/rogerlos/cmb2-metatabs-options/cmb2_metatabs_options.php';
		require_once $this->plugin_dir_path . 'html.php';
		require_once $this->plugin_dir_path . 'test.php';

		//require_once $this->plugin_dir_path . 'includes/pallazzio-wordpress-github-updater/pallazzio-wordpress-github-updater.php';
		//new Pallazzio_WordPress_GitHub_Updater( $this->plugin_dir_path . wp_basename( $this->plugin_file_path ), 'pallazzio' );

		$this->options = get_option( 'asa_member_portal' );

		register_activation_hook(   $this->plugin_file_path, array( $this, 'activate'   ) );
		register_deactivation_hook( $this->plugin_file_path, array( $this, 'deactivate' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ), 10, 1 );

		add_action(    'add_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );
		add_action( 'update_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );
		add_action( 'delete_option_asa_member_portal', array( $this, 'create_roles'  ), 10, 2 );

		add_action( 'user_register',  array( $this, 'set_user_options' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'set_user_options' ), 10, 1 );

		add_action( 'save_post_dues_payment', array( $this, 'dues_payment_save' ), 10, 3 );

		add_action( 'cmb2_init',       array( $this, 'user_meta_init'        ) );
		add_action( 'cmb2_init',       array( $this, 'dues_payments_init'    ) );
		add_action( 'cmb2_admin_init', array( $this, 'options_init'          ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_profile' ) );
		add_action( 'cmb2_after_init', array( $this, 'frontend_user_login'   ) );

		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_shortcodes' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file_path ), array( $this, 'add_settings_link' ), 10, 1 );
	}

	/**
	 * Sets up initial plugin settings, data, etc.
	 *
	 * @return null
	 */
	public function activate() {
		$this->create_roles( 'asa_member_portal', array( array( 'name' => $this->get_default_member_type_name() ) ) );
	}

	/**
	 * Deletes plugin settings, data, etc.
	 *
	 * @return null
	 */
	public function deactivate() {
		$this->delete_roles();
	}

	/**
	 * Loads admin scripts and stylesheets.
	 *
	 * @param string $hook
	 *
	 * @return null
	 */
	public function admin_enqueue( $hook ) {
		$this->plugin_data = get_plugin_data( $this->plugin_file_path );
		$hooks = array( 'dues_payment_page_asa_member_portal', 'user-new.php', 'profile.php' );
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
	 * Adds/updates roles.
	 *
	 * @param string $option_name
	 * @param mixed  $option_value
	 *
	 * @return null
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
			$role = 'asamp' . '_' . sanitize_key( $member_type[ 'name' ] );
			$result = add_role( $role, $member_type[ 'name' ], array( 'read' ) );
		}
	}

	/**
	 * Deletes roles.
	 *
	 * @return null
	 */
	private function delete_roles() {
		$roles = get_editable_roles();
		foreach ( $roles as $k => $v ) {
			if ( false !== strpos( $k, 'asamp' . '_' ) ) {
				remove_role( $k );
			}
		}
	}

	/**
	 * Sets user options based on role.
	 *
	 * @param int            $user_id
	 * @param WP_User object $old_user_data
	 *
	 * @return null
	 */
	public function set_user_options( $user_id ) {
		$this->user = get_userdata( $user_id );
		$roles = ( array ) $this->user->roles;
		foreach ( $roles as $role ) {
			if ( false !== strpos( $role, 'asamp' . '_' ) ) {
				update_user_option( $user_id, 'show_admin_bar_front', 'false' );
			}
		}
	}

	/**
	 * Registers shortcodes.
	 *
	 * @return null
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
		$cmb = cmb2_get_metabox( 'asamp_user_edit', $this->user()->ID );

		$output = '';

		if ( ( $error = $cmb->prop( 'submission_error' ) ) && is_wp_error( $error ) ) {
			$output .= '<h3>' . sprintf( __( 'There was an error in the submission: %s', 'asamp' ), '<strong>'. $error->get_error_message() .'</strong>' ) . '</h3>';
		}
		
		if ( 'true' === $_GET[ 'member_updated' ] ) {
			$output .= '<h3>' . __( 'Your profile has been updated.', 'asamp' ) . '</h3>';
		}

		$form_config = array();
		$form_config[ 'save_button' ] = $this->is_member() ? __( 'Update Profile', 'asamp' ) : __( 'Join Now', 'asamp' );

		$output .= cmb2_get_metabox_form( $cmb, $this->user()->ID, $form_config );

		return $output;
	}

	/**
	 * Generates a member status widget or a login form if not logged in.
	 *
	 * @return function asamp_render_login_box()
	 */
	public function shortcode_asamp_member_login_box( $atts = array() ) {
		$output = '';
		if ( $this->is_member() ) {
			$output .= __( 'Welcome', 'asamp' );
		} else {
			$prefix = 'asamp_login_';
			$cmb = new_cmb2_box( array(
				'id'           => $prefix . 'form',
				'object_types' => array( 'post' ),
				'hookup'       => false,
				'save_fields'  => false,
			) );
			$cmb->add_field( array(
				'name' => __( 'Username / Email', 'asamp' ),
				'id'   => $prefix . 'username',
				'type' => 'text',
			) );
			$cmb->add_field( array(
				'name' => __( 'Password', 'asamp' ),
				'id'   => $prefix . 'password',
				'type' => 'text',
			) );
			$cmb->add_hidden_field( array(
				'field_args'  => array(
					'id'      => $prefix . '_nonce',
					'type'    => 'hidden',
					'default' => wp_create_nonce( $prefix . '_nonce' ),
				),
			) );

			$output .= cmb2_get_metabox_form( $prefix . 'form', '', array( 'save_button' => __( 'Sign In', 'asamp' ) ) );
		}

		return $output;
	}

	/**
	 * Generates a payment form.
	 *
	 * @return function asamp_render_payment_form()
	 */
	public function shortcode_asamp_member_payment_form( $atts = array() ) {
		$args = array();
		if ( $this->is_member() ) {
			$args[ 'member_id' ] = $this->user()->ID;
		}
		return asamp_render_payment_form( $args );
	}

	/**
	 * Generates a member directory.
	 *
	 * @return function asamp_render_directory()
	 */
	public function shortcode_asamp_member_directory( $atts = array() ) {
		$args = array();
		if ( $this->is_member() ) {
			$args[ 'member_id' ] = $this->user()->ID;
		}

		return asamp_render_directory( $args );
	}

	/**
	 * Checks to see if the current user is a member.
	 *
	 * @return bool
	 */
	public function is_member() {
		if ( is_bool( $this->is_member ) ) return $this->is_member;

		if ( is_user_logged_in() ) {
			$roles = ( array ) $this->user()->roles;
			foreach ( $roles as $role ) {
				if ( false !== strpos( $role, 'asamp' . '_' ) ) {
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
	 * Builds an array of US State abbreviations and names.
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
	 * Handles user login.
	 *
	 * @return void
	 */
	public function frontend_user_login() {
		$prefix = 'asamp_login_';
		if ( ! $this->verify_cmb_form( $prefix . 'nonce' ) ) return false;


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

		if ( $this->is_member() ) {
			$this->user->user_pass = ! empty( $sanitized_values[ $prefix . 'password' ] ) ? $sanitized_values[ $prefix . 'password' ] : $this->user->user_pass;

			$user_id = wp_update_user( $this->user );
		} else {
			$userdata = array(
				'user_pass'            => $sanitized_values[ $prefix . 'pass' ],
				'user_login'           => $sanitized_values[ $prefix . 'login' ],
				'user_nicename'        => sanitize_html_class( $sanitized_values[ $prefix . 'login' ] ),
				'user_url'             => $sanitized_values[ $prefix . 'company_website' ],
				'user_email'           => $sanitized_values[ $prefix . 'company_contacts' ][ 0 ][ 'email' ],
				'display_name'         => $sanitized_values[ $prefix . 'company_name' ],
				'description'          => $sanitized_values[ $prefix . 'company_description' ],
				'rich_editing'         => false,
				'syntax_highlighting'  => false,
				'show_admin_bar_front' => false,
				'role'                 => $sanitized_values[ $prefix . 'member_type' ],
			);

			$user_id = wp_insert_user( $userdata );

			wp_signon( array( 'user_login' => $userdata[ 'user_login' ], 'user_password' => $userdata[ 'user_pass' ] ), true );
		}

		// If there is a snag, inform the user.
		if ( is_wp_error( $user_id ) ) {
			return $cmb->prop( 'submission_error', $user_id );
		}

		$cmb->save_fields( $user_id, 'user', $sanitized_values );

		$img_id = $this->frontend_image_upload( $prefix . 'company_logo' );

		wp_redirect( esc_url_raw( add_query_arg( 'member_updated', 'true' ) ) );

		exit;
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
	 * Adds tabs on the options page.
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
					'administration',
				),
			),
			array(
				'id'    => 'payment',
				'title' => __( 'Payment', 'asamp' ),
				'boxes' => array(
					'paypal_pro',
					'stripe',
				),
			),
		);
		
		return $tabs;
	}

	/**
	 * Adds options.
	 *
	 * @return null
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
	 * @return null
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
	 * @return null
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
	 * @return null
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
			'public'              => true,
			'publicly_queryable'  => true,
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
			'title'           => __( 'Registration/Profile', 'asamp' ),
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
			'id'              => 'paypal_pro',
			'title'           => __( 'PayPal Pro', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro Enabled', 'asamp' ),
			'id'              => 'paypal_pro_enabled',
			'type'            => 'radio_inline',
			'default'         => 'no',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Username', 'asamp' ),
			'id'              => 'paypal_pro_api_username',
			'type'            => 'text',
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Password', 'asamp' ),
			'id'              => 'paypal_pro_api_password',
			'type'            => 'text',
		) );
		$cmb->add_field( array(
			'name'            => __( 'PayPal Pro API Signature', 'asamp' ),
			'id'              => 'paypal_pro_api_signature',
			'type'            => 'text',
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		$cmb = new_cmb2_box( array(
			'id'              => 'stripe',
			'title'           => __( 'Stripe', 'asamp' ),
			'show_on'         => $show_on,
			'display_cb'      => false,
			'admin_menu_hook' => false,
		) );
		$cmb->add_field( array(
			'name'            => __( 'Stripe Enabled', 'asamp' ),
			'id'              => 'stripe_enabled',
			'type'            => 'radio_inline',
			'default'         => 'no',
			'options' => array(
				'yes' => __( 'Yes', 'asamp' ),
				'no'  => __( 'No',  'asamp' ),
			),
		) );
		$cmb->add_field( array(
			'name'            => __( 'Stripe API Key', 'asamp' ),
			'id'              => 'stripe_api_key',
			'type'            => 'text',
		) );
		$cmb->object_type( 'options-page' );
		$boxes[] = $cmb;
		
		return $boxes;
	}

	/**
	 * Adds member profile fields to user meta.
	 *
	 * @return null
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
			'name'            => __( 'ASA Member Info', 'asamp' ),
			'id'              => $prefix . 'section_member_info',
			'type'            => 'title',
		) );

		$cmb_user->add_field( array(
			'name'            => __( 'Company Name', 'asamp' ),
			'id'              => $prefix . 'company_name',
			'type'            => 'text',
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
			'deefault'        => $this->options[ 'state_default' ],
			'options'         => $this->get_us_states_array( $this->options[ 'state_default' ] ),
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
			'options'         => function(){
				$current_year = date( 'Y' );
				$start_year   = date( 'Y', strtotime( $current_year . ' -100 years' ) );
				$years        = range( $start_year, $current_year );
				return array_combine( $years, $years );
			}
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
			$cmb_user->add_field( array(
				'name'            => ! empty( $this->options[ 'member_type_label' ] ) ? $this->options[ 'member_type_label' ] : $this->get_default_member_type_label(),
				'id'              => $prefix . 'member_type',
				'type'            => 'select',
				//'default'         => '',
				'options'         => function(){
					if ( ! empty( $this->options[ 'member_types' ] ) ) {
						$member_types = $this->options[ 'member_types' ];
					} else {
						$member_types = array( array( 'name' => $this->get_default_member_type_name() ) );
					}
					$output = array();
					foreach ( $member_types as $member_type ) {
						$output[ 'asamp' . '_' . sanitize_key( $member_type[ 'name' ] ) ] = $member_type[ 'name' ] . ' (Dues: $' . $member_type[ 'dues' ] . '/Year)';
					}

					return $output;
				}
			) );

			if ( ! $this->is_member() ) {
				$cmb_user->add_field( array(
					'name'            => __( 'Username', 'asamp' ),
					'id'              => $prefix . 'login',
					'type'            => 'text',
				) );
			}
			
			$cmb_user->add_field( array(
				'name'            => $this->is_member() ? __( 'New Password', 'asamp' ) : __( 'Password', 'asamp' ),
				'id'              => $prefix . 'pass',
				'type'            => 'text',
				'attributes'      => array(
					'type'    => 'password',
				),
			) );

			$cmb_user->add_field( array(
				'name'            => __( 'Confirm Password', 'asamp' ),
				'id'              => $prefix . 'pass_confirm',
				'type'            => 'text',
				'attributes'      => array(
					'type'    => 'password',
				),
			) );

			$cmb_user->add_hidden_field( array(
				'field_args'  => array(
					'id'      => $prefix . 'nonce',
					'type'    => 'hidden',
					'default' => wp_create_nonce( $prefix . 'nonce' ),
				),
			) );
		}
	}

	/**
	 * Writes to error_log.
	 *
	 * @param mixed  $log
	 * @param string $id
	 *
	 * @return null
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
