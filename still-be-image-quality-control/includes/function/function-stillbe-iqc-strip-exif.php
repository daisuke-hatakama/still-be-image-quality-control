<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// Strip EXIF Data without Changing Image Data
//
// JPEG のセグメント構造 (マーカー + 長さ) を正しく辿って APP1 (EXIF / XMP) セグメントのみを
// 取り除く。SOS (Start of Scan) 以降は圧縮データのためそのままコピーする。
// @since 2.0.0 圧縮データ中のバイト列を APP1 マーカーと誤検出しないようにセグメント解析に変更
function stillbe_iqc_strip_exif( $filename ) {

	if( ! file_exists( $filename ) ) {
		return false;
	}

	// Generate a unique filename
	$dir      = dirname( $filename );
	$tempname = tempnam( $dir, STILLBE_IQ_PREFIX );

	// Open the input file for binary reading
	$f1 = fopen( $filename, 'rb' );

	// Open the output file for binary writing
	$f2 = fopen( $tempname, 'wb' );

	if( ! $f1 || ! $f2 ) {
		if( $f1 ) { fclose( $f1 ); }
		if( $f2 ) { fclose( $f2 ); }
		@unlink( $tempname );
		return false;
	}

	// SOI (Start of Image) マーカーの確認
	$soi = fread( $f1, 2 );
	if( "\xFF\xD8" !== $soi ) {
		// JPEG ではない
		fclose( $f1 );
		fclose( $f2 );
		@unlink( $tempname );
		return false;
	}
	fwrite( $f2, $soi );

	$is_parsed_to_sos = false;

	while( ! feof( $f1 ) ) {

		$prefix = fread( $f1, 1 );
		if( '' === $prefix || false === $prefix ) {
			break;
		}

		if( "\xFF" !== $prefix ) {
			// マーカー以外が現れた場合は解析を中断する (破損ファイル等)
			break;
		}

		// パディングの 0xFF を読み飛ばしてマーカー種別を取得する
		$type = fread( $f1, 1 );
		while( "\xFF" === $type ) {
			$type = fread( $f1, 1 );
		}
		if( '' === $type || false === $type ) {
			break;
		}

		$marker = ord( $type );

		if( 0xDA === $marker ) {
			// SOS (Start of Scan); 以降は圧縮データなのでそのままコピーする
			fwrite( $f2, "\xFF". $type );
			while( ! feof( $f1 ) ) {
				$s = fread( $f1, 65536 );
				if( '' === $s || false === $s ) {
					break;
				}
				fwrite( $f2, $s );
			}
			$is_parsed_to_sos = true;
			break;
		}

		if( ( 0xD0 <= $marker && 0xD9 >= $marker ) || 0x01 === $marker ) {
			// 長さを持たない単独マーカー (RSTn / SOI / EOI / TEM)
			fwrite( $f2, "\xFF". $type );
			continue;
		}

		// セグメント長 (長さ自身の 2 バイトを含む)
		$len_bytes = fread( $f1, 2 );
		if( 2 > strlen( (string) $len_bytes ) ) {
			break;
		}
		$len     = unpack( 'ni', $len_bytes )['i'];
		$payload = 2 < $len ? fread( $f1, $len - 2 ) : '';

		if( 0xE1 === $marker ) {
			// APP1 (EXIF / XMP) セグメントは書き込まずにスキップする
			continue;
		}

		fwrite( $f2, "\xFF". $type. $len_bytes. $payload );

	}

	// Closing
	fclose( $f1 );
	fclose( $f2 );

	// SOS まで正しく解析できた場合のみ元ファイルを置き換える
	if( $is_parsed_to_sos && wp_filesize( $tempname ) > 0 ) {
		$result = rename( $tempname, $filename );
		// 親ディレクトリと同じパーミッションにする (実行ビットは落とす)
		// 古い誤ったパーミッションのまま残るのを防ぐため、元ファイルの fileperms は使わない
		if( $result ) {
			$stat = stat( $dir );
			if( false !== $stat ) {
				@chmod( $filename, $stat['mode'] & 0000666 );
			}
		}
		return $result;
	}

	@unlink( $tempname );

	return false;

}





// END
