<?php

add_action( 'init', 'asamp_register_dues_payment_post_type' );
function asamp_register_dues_payment_post_type() {
	$labels = array(
		'name'               => __( 'Dues Payments',            'asamp' ),
		'singular_name'      => __( 'Dues Payment',             'asamp' ),
		'menu_name'          => __( 'ASA Portal',               'asamp' ),
		'all_items'          => __( 'Dues Payments',            'asamp' ),
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
		'menu_icon'           => 'dashicons-businessman',
		'supports'            => array( 'title' ),
		'rewrite'             => array(
			'slug'       => 'payments',
			'with_front' => false
		),
	);

	register_post_type( 'dues_payment', $args );
}

add_action( 'cmb2_init', 'asamp_dues_payments_init' );
function asamp_dues_payments_init() {
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

add_action( 'save_post_dues_payment', 'asamp_dues_payments_private', 10, 3 );
function asamp_dues_payments_private( $post_ID, $post, $update ) {
	if ( ! $update ) return;

	if ( 'Payment #' . ( string ) $post->ID !== $post->post_title ) {
		//ASA_Member_Portal::write_log($post);
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

?>
