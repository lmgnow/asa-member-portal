<?php

function render_login_box( $args ) {
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
