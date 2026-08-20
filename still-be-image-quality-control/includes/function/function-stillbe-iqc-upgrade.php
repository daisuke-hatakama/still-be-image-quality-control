<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * プラグインのバージョンアップ時マイグレーション
 *
 * 通常リクエストでは get_option (キャッシュ済み) と文字列比較のみ。
 * バージョンが変わったときだけマイグレーションを実行する。
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * バージョン変更を検知して必要ならマイグレーションを実行する
 */
function stillbe_iqc_maybe_upgrade() {

	static $ran = false;
	if( $ran ) {
		return;
	}
	$ran = true;

	$installed = get_option( STILLBE_IQ_DB_VERSION_OPTION );

	// 最も多い経路: 変更なし → 文字列比較のみで終了
	if( STILLBE_IQ_VERSION === $installed ) {
		return;
	}

	// ダウングレード時はファイルだけ古い場合があるため DB バージョンは維持
	if( false !== $installed && version_compare( (string) $installed, STILLBE_IQ_VERSION, '>' ) ) {
		return;
	}

	$from = ( false === $installed ) ? '0' : (string) $installed;

	stillbe_iqc_run_upgrade( $from, STILLBE_IQ_VERSION );

	update_option( STILLBE_IQ_DB_VERSION_OPTION, STILLBE_IQ_VERSION, true );

}


/**
 * バージョンアップ時の処理
 *
 * @param string $from_version 更新前のバージョン (未保存の場合は '0')
 * @param string $to_version   更新後のバージョン
 */
function stillbe_iqc_run_upgrade( $from_version, $to_version ) {

	// 現在の WebP / AVIF 設定に合わせて .htaccess ブロックを差し替える
	_stillbe_iqc_htaccess_webp( null );

}




// END of the File

