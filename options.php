<?php

add_action( 'cmb2_admin_init', 'asamp_options_init' );
function asamp_options_init() {
	$options_key = 'asa_member_portal';

	new Cmb2_Metatabs_Options( array(
		'key'      => $options_key,
		'title'    => __( 'ASA Member Portal Settings', 'asamp' ),
		'topmenu'  => 'edit.php?post_type=dues_payment',
		'resettxt' => __( 'Restore Defaults', 'asamp' ),
		'boxes'    => asamp_options_add_boxes( $options_key ),
		'tabs'     => asamp_options_add_tabs(),
		'menuargs' => array(
			'menu_title'      => __( 'Settings', 'asamp' ),
			'capability'      => 'manage_options',
			'view_capability' => 'manage_options',
		),
	) );
}


/**
 * This is typical CMB2, but note two crucial extra items:
 * - the ['show_on'] property is configured
 * - a call to object_type method
 *
 * @param  string $options_key
 * @return array  $boxes
 */
function asamp_options_add_boxes( $options_key ) {
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
		'options'         => ASA_Member_Portal::get_us_states_array(),
	) );
	/*$cmb->add_field( array(
		'name'            => __( 'Number of Contacts Allowed', 'asamp' ),
		'desc'            => __( 'Please be careful. If a user already has multiple contacts, then you decrease this to a number that is lower than what they have, some of their contacts may disappear.', 'asamp' ),
		'id'              => 'num_contacts',
		'type'            => 'text',
		'sanitization_cb' => 'absint',
		'default'         => ASA_Member_Portal::get_default_num_contacts(),
		'attributes'      => array(
			'type'    => 'number',
			'pattern' => '\d*',
			'min'     => 0,
		),
	) );*/
	$cmb->add_field( array(
		'name'            => __( 'Trades', 'asamp' ),
		'desc'            => __( 'Add one trade per line. Please be careful. If a user already has a trade selected, then you remove the trade, their selection for that trade may be lost.', 'asamp' ),
		'id'              => 'trades',
		'type'            => 'textarea',
		'default'         => function(){
			$r = ASA_Member_Portal::get_default_trades_array();
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
		'default'         => ASA_Member_Portal::get_default_member_type_name(),
		'attributes'      => array(
			'required' => 'required',
		),
	) );
	$cmb->add_group_field( 'member_types', array(
		'name'            => __( 'Dues Amount', 'asamp' ),
		'id'              => 'dues',
		'type'            => 'text_money',
		'default'         => ASA_Member_Portal::get_default_member_type_dues(),
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
 * Adds tabs.
 * Tabs are completely optional and removing them would result in the option metaboxes displaying sequentially.
 * If tabs are configured, all boxes whose context is "normal" or "advanced" must be in a tab to display.
 *
 * @return array $tabs
 */
function asamp_options_add_tabs() {
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

?>
