<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * クエリストリングを付け替える
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// 
add_filter( 'wp_calculate_image_srcset', function( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {

	if( empty( $image_meta['sizes'] ) ) {
		return $sources;
	}

	$new_sources = $sources;

	$upload_dir = wp_upload_dir();
	$base_dir   = $upload_dir['basedir'];

	$orig_filename = path_join( $base_dir, $image_meta['file'] );
	$sub_dir       = dirname( $orig_filename );

	$is_forced = apply_filters( 'stillbe_image_quality_control_force_adding_cache_clear_query', STILLBE_IQ_ENABLE_FORCE_CACHE_CLEAR );

	foreach( $image_meta['sizes'] as $image ) {

		if( empty( $new_sources[ $image['width'] ] ) ) {
			continue;
		}

		// メタデータに保存された更新時刻を優先し、
		// 未保存の場合のみファイルアクセス (filemtime) にフォールバックする
		$timestamp = empty( $image['updated'] ) ? '' : trim( strval( $image['updated'] ) );
		if( '' === $timestamp ) {
			if( ! $is_forced ) {
				// 強制付与が無効で更新時刻も未保存ならクエリを付けない
				continue;
			}
			$timestamp = strval( @ filemtime( path_join( $sub_dir, $image['file'] ) ) );
		}

		// Note: この URL はコア側 (wp_image_add_srcset_and_sizes 等) でエスケープされるため、ここではエスケープしない
		$new_sources[ $image['width'] ]['url'] = add_query_arg( '_mod', $timestamp, $new_sources[ $image['width'] ]['url'] );

	}

	return $new_sources;

}, 10, 5 );