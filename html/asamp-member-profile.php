<?php

function asamp_render_profile( $args ) {
	$form = cmb2_get_metabox_form( 'asamp_user_edit', $args[ 'member_id' ] );
	return $form;
}

function asamp_render_login_box( $args ) {
	if ( $args[ 'logged_in' ] ) {
		ob_start();
		?>
			<p>Welcome</p>
		<?php
		return ob_get_clean();
	}
	
	ob_start();
	?>
		<form>
			
		</form>
	<?php
	return ob_get_clean();
}

?>
