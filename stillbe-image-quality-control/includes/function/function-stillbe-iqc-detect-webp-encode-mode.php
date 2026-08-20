<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Detect whether a WebP file is lossy or lossless from its bitstream.
 *
 * wp_get_webp_info() only classifies the first chunk; VP8X (alpha / ICC)
 * files are reported as animated-alpha even when the payload is lossless.
 *
 * @since 2.1.2
 *
 * @param string $filename Absolute path to a WebP file.
 * @return string 'lossy', 'lossless', or '' if unknown.
 */
function stillbe_iqc_detect_webp_encode_mode( $filename ) {

	$filename = (string) $filename;
	if( '' === $filename || ! is_readable( $filename ) ) {
		return '';
	}

	if( function_exists( 'wp_get_webp_info' ) ) {
		$info = wp_get_webp_info( $filename );
		if( ! empty( $info['type'] ) && in_array( $info['type'], array( 'lossy', 'lossless' ), true ) ) {
			return $info['type'];
		}
	}

	$bytes = @file_get_contents( $filename, false, null, 0, 512 * 1024 );
	if( false === $bytes || strlen( $bytes ) < 16 ) {
		return '';
	}

	if( 0 !== strpos( $bytes, 'RIFF' ) || false === strpos( substr( $bytes, 0, 16 ), 'WEBP' ) ) {
		return '';
	}

	$head = substr( $bytes, 12, 4 );
	if( 'VP8L' === $head ) {
		return 'lossless';
	}
	if( 'VP8 ' === $head ) {
		return 'lossy';
	}

	// Extended container (VP8X): find the actual bitstream chunk.
	$offset = 12;
	$len    = strlen( $bytes );
	while( ( $offset + 8 ) <= $len ) {
		$fourcc = substr( $bytes, $offset, 4 );
		$size   = unpack( 'V', substr( $bytes, $offset + 4, 4 ) );
		$size   = isset( $size[1] ) ? (int) $size[1] : 0;
		if( 'VP8L' === $fourcc ) {
			return 'lossless';
		}
		if( 'VP8 ' === $fourcc ) {
			return 'lossy';
		}
		// Chunk payload is padded to even length.
		$offset += 8 + $size + ( $size & 1 );
		if( $size < 0 || $offset <= 12 ) {
			break;
		}
	}

	return '';

}


/**
 * Resolve AVIF encode mode for UI / meta.
 *
 * Only ImageMagick treats quality &gt;= 100 as lossless AVIF.
 * GD imageavif() has no lossless mode — quality 100 remains lossy.
 *
 * @since 2.1.2
 *
 * @param int|string $quality Compression quality.
 * @param string     $library Editor library slug ('imagick', 'gd', or empty).
 * @return string 'lossless' or 'lossy'.
 */
function stillbe_iqc_resolve_avif_encode_mode( $quality, $library = '' ) {

	$library = strtolower( (string) $library );
	if( 'imagick' === $library && (int) $quality >= 100 ) {
		return 'lossless';
	}

	return 'lossy';

}


/**
 * Copy encode-mode from make_webp / make_avif result into sb-iqc meta.
 *
 * @since 2.1.2
 *
 * @param array  $sb_iqc Meta array (by reference).
 * @param array  $data   Result from make_webp / make_avif.
 * @param string $type   'webp' or 'avif'.
 */
function stillbe_iqc_merge_encode_method_meta( &$sb_iqc, $data, $type = 'webp' ) {

	if( ! is_array( $sb_iqc ) || ! is_array( $data ) ) {
		return;
	}

	if( 'avif' === $type ) {
		if( ! empty( $data['method'] ) ) {
			$sb_iqc['avif-method'] = $data['method'];
		}
		return;
	}

	if( ! empty( $data['method'] ) ) {
		$sb_iqc['webp-method'] = $data['method'];
	} elseif( isset( $data['cwebp']['method'] ) ) {
		$sb_iqc['webp-method'] = $data['cwebp']['method'];
	}

	if( isset( $data['lossless_level'] ) && '' !== $data['lossless_level'] && null !== $data['lossless_level'] ) {
		$sb_iqc['webp-lossless-level'] = absint( $data['lossless_level'] );
	} elseif( isset( $data['cwebp']['q'] ) ) {
		$sb_iqc['webp-lossless-level'] = absint( $data['cwebp']['q'] );
	}

}


/**
 * Map PNG compression level (1-9) to lossless WebP quality, matching cwebp.
 *
 * cwebp: min( absint( $png_level * 10 + 6 ), 100 )
 * Example: PNG level 9 → 96 (100 is avoided as it is very slow).
 *
 * @since 2.1.2
 *
 * @param int $png_level PNG compression level (1-9).
 * @return int Quality 16-96 (capped at 100).
 */
function stillbe_iqc_png_level_to_lossless_quality( $png_level ) {

	$png_level = absint( $png_level );
	if( 1 > $png_level ) {
		$png_level = 9;
	}
	if( 9 < $png_level ) {
		$png_level = 9;
	}

	return min( $png_level * 10 + 6, 100 );

}
