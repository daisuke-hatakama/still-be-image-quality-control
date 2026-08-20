<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * このサーバーで AVIF を生成できるエディタがあるか
 *
 * @since 2.1.0
 *
 * @return bool
 */
function stillbe_iqc_is_avif_editor_available() {

	return '' !== stillbe_iqc_get_avif_capable_editor_class();

}




// END of the File
