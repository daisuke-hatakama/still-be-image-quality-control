<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メタデータ更新時に AVIF 自動生成の WP-Cron を登録するフィルター
 *
 * AVIF の予約は Schedule_Cron::schedule() に集約した。
 * このファイルは読み込み互換のため残している。
 *
 * @since 2.1.0
 * @since 2.2.0 Schedule_Cron::schedule() へ集約
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// END of the File
