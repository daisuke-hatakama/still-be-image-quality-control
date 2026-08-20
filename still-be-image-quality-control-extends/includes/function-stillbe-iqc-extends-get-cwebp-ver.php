<?php


/**
 * Get the "cwebp" Version
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function stillbe_iqc_extends_get_cwebp_ver() {

	return trim( @shell_exec( 'cwebp -version' ) ?: '' );

}






// END of the File



