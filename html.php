<?php

function asamp_render_login_box( $args = array() ) {
	if ( is_int( $args[ 'member_id' ] ) ) {
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

function asamp_render_payment_form( $args = array() ) {
	?>
		<form>
			
		</form>
	<?php
}

function asamp_render_directory( $args = array() ) {
	?>
		<div class="asamp-directory">
			
		</div>
	<?php
}

?>
