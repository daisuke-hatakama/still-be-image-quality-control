<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * AVIF の読み書きに対応するエディタ Class を優先順で返す
 *
 * 本番の AVIF 生成 (make_avif) と同じ優先順 (設定の preferred editor)。
 * 選択中のエディタが AVIF 非対応でも、対応エディタがあればそちらへフォールバックするため、
 * 対応可否の判定は必ずこの関数を基準にする。
 *
 * @since 2.1.0
 *
 * @return string[] エディタ Class 名の配列 (対応エディタが無ければ空配列)
 */
function stillbe_iqc_get_avif_capable_editor_classes() {

	static $capable     = null;
	static $cached_pref = null;

	$pref = stillbe_iqc_get_preferred_image_editor_library();

	if( null !== $capable && $cached_pref === $pref ) {
		return $capable;
	}

	$capable     = array();
	$cached_pref = $pref;

	foreach( stillbe_iqc_get_ordered_image_editor_classes() as $editor_class ) {
		if( $editor_class::test() && $editor_class::supports_mime_type( 'image/avif' ) ) {
			$capable[] = $editor_class;
		}
	}

	return $capable;

}




// END of the File
