<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// Plugin Slug
define( 'STILLBE_IQ_SLUG', 'still-be-image-quality-control' );

// Plugin Version (stillbe-image-quality-control.php の Version と揃える)
define( 'STILLBE_IQ_VERSION', '2.1.3' );

// 保存済み DB バージョン (オプション名)
define( 'STILLBE_IQ_DB_VERSION_OPTION', 'sb-iqc-db-version' );

// Alt が空の時に Exif を使って自動設定するフラグ
define( 'STILLBE_IQ_AUTOSET_ALT_FROM_EXIF',           false );

// 自動リサイズした画像に圧縮品質を表す suffix を追加するフラグ
define( 'STILLBE_IQ_ENABLE_QUALITY_VALUE_SUFFIX',     false );

// srcset 属性に src 属性の画像サイズ以上を含めないようにするフラグ
define( 'STILLBE_IQ_OPTIMIZE_SRCSET',                 true );

// 画像キャッシュをクリアするクエリパラメータ付与を強制するフラグ
define( 'STILLBE_IQ_ENABLE_FORCE_CACHE_CLEAR',        true );

// インターレース PNG・プログレッシブ JPEG を有効化するフラグ
// ただし、サーバ環境がインターレースに対応している場合に限る
define( 'STILLBE_IQ_ENABLE_INTERLACE',                true  );
define( 'STILLBE_IQ_ENABLE_INTERLACE_JPEG',           true  );
define( 'STILLBE_IQ_ENABLE_INTERLACE_PNG',            false );

// WebP を作成するフラグ
// ただし、サーバ環境が WebP 作成に対応している場合に限る
define( 'STILLBE_IQ_ENABLE_WEBP',                     true  );

// AVIF を作成するフラグ
// ただし、サーバ環境が AVIF 作成に対応している場合に限る
// @since 2.1.0
define( 'STILLBE_IQ_ENABLE_AVIF',                     true  );

// 優先する画像エディタ ('imagick' | 'gd')
// @since 2.1.2
define( 'STILLBE_IQ_PREFERRED_IMAGE_EDITOR',          'imagick' );

// WebP 作成時に cwebp ライブラリを使用するフラグ
//   @since 0.5.1 true -> false
//   @since 0.9.0 false -> true
define( 'STILLBE_IQ_ENABLE_CWEBP_LIBRARY',            true );

// PNG / GIF の WebP 作成時に -lossless (or -near_lossless) 圧縮を有効にするフラグ
// ただし、cwebp が有効の場合に限る
//   @since 0.5.1 true -> false
//   @since 0.9.0 false -> true
define( 'STILLBE_IQ_ENABLE_WEBP_LOSSLESS',            true );

// PNG / GIF の WebP 作成時に -near_lossless オプションが利用可能な場合に使うフラグ
// ただし、cwebp が有効の場合に限る
define( 'STILLBE_IQ_ENABLE_WEBP_NEAR_LOSSLESS',       false );

// 元画像がインデックスカラーの場合、リサイズ画像もインデックスカラーに変換するフラグ
//   @since 1.1.1 ライブラリが GD の場合は透過色が保持されないバグがあるため false とする
define( 'STILLBE_IQ_ENABLE_INDEX_COLOR_GD',           false );
define( 'STILLBE_IQ_ENABLE_INDEX_COLOR_IMAGICK',      true  );

// リサイズ画像を強制的にインデックスカラーに変換するフラグ
define( 'STILLBE_IQ_ENABLE_INDEX_COLOR_FORCE',        false );

// EXIF を削除するフラグ
//   @since 1.4.0
define( 'STILLBE_IQ_ENABLE_STRIP_EXIF',               true  );

// 巨大な元画像を削除するフラグ
//   @since 2.0.0
define( 'STILLBE_IQ_ENABLE_DELETE_ORIGINAL_LARGE',    false  );

// cURL のバージョンが 7.32.0 よりも古い場合に WP-Cron のブロッキング時間が制限されない問題を解消するフラグ
define( 'STILLBE_IQ_ENABLE_DECIMAL_TIMEOUT_WPCRON',   true  );

// WebP をアップロードと同時に保存する処理を有効にするフラグ
define( 'STILLBE_IQ_ENABLE_SAVE_WEBP_SYNC',           false );

// AVIF をアップロードと同時に保存する処理を有効にするフラグ
define( 'STILLBE_IQ_ENABLE_SAVE_AVIF_SYNC',           false );

// 自動で最適化するフラグ
// @since 2.0.0 ベータ機能のため既定は無効 (設定画面からオプトイン)
define( 'STILLBE_IQ_ENABLE_AUTO_OPTIMIZE',            false );

// 自動最適化の同時実行上限 (サーバ負荷に応じて設定画面で変更可)
// @since 2.0.0
define( 'STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY',       2 );

// 自動最適化で探索する品質レベルの下限
// @since 2.0.0
define( 'STILLBE_IQ_SSIM_QUALITY_FLOOR',              40 );

// WP-Cron 処理の一時的な max_execution_time [sec]
// @since 2.0.0
define( 'STILLBE_IQ_WPCRON_MAX_EXECUTION_TIME',       600 );

// SSIM の計算用のパラメータ
define( 'STILLBE_IQ_SSIM_WINDOW_RADIUS',              5    );
define( 'STILLBE_IQ_SSIM_SIGMA',                      1.7  );
define( 'STILLBE_IQ_SSIM_CONSTANT_K1',                0.01 );   // 基本的には変更禁止
define( 'STILLBE_IQ_SSIM_CONSTANT_K2',                0.03 );   // 基本的には変更禁止

// SSIM の目標値 (JPEG Q=72 / WebP Q=76 を基準とした値。上限テーブルで dB 換算して微調整)
// @since 2.0.0
define( 'STILLBE_IQ_SSIM_REF_JPEG_QUALITY',           72    );   // 目標 SSIM を目指す基準にになる JPEG 品質
define( 'STILLBE_IQ_SSIM_REF_WEBP_QUALITY',           84    );   // 目標 SSIM を目指す基準にになる WebP 品質
define( 'STILLBE_IQ_SSIM_REF_AVIF_QUALITY',           70    );   // 目標 SSIM を目指す基準にになる AVIF 品質
define( 'STILLBE_IQ_SSIM_TARGET_STEP_QUALITY_DELTA',  17    );
define( 'STILLBE_IQ_SSIM_TARGET_STEP_VALUE',          0.01  );
define( 'STILLBE_IQ_SSIM_TARGET_MIN',                 0.90  );
define( 'STILLBE_IQ_SSIM_TARGET_MAX',                 0.999 );

// SSIM の目標値 (JPEG Q=72 / WebP Q=84 を基準とした値。上限テーブルで dB 換算して微調整)
// @since 2.0.0
define( 'STILLBE_IQ_SSIM_TARGET_JPEG_EFFICIENCY',     0.959 );
define( 'STILLBE_IQ_SSIM_TARGET_JPEG_BALANCE',        0.970 );
define( 'STILLBE_IQ_SSIM_TARGET_JPEG_QUALITY',        0.980 );
define( 'STILLBE_IQ_SSIM_TARGET_WEBP_EFFICIENCY',     0.966 );
define( 'STILLBE_IQ_SSIM_TARGET_WEBP_BALANCE',        0.976 );
define( 'STILLBE_IQ_SSIM_TARGET_WEBP_QUALITY',        0.982 );
// AVIF 用設定を追加
// とりあえず WebP と同じ値を目標にする
// @since 2.1.0
define( 'STILLBE_IQ_SSIM_TARGET_AVIF_EFFICIENCY',     0.966 );
define( 'STILLBE_IQ_SSIM_TARGET_AVIF_BALANCE',        0.976 );
define( 'STILLBE_IQ_SSIM_TARGET_AVIF_QUALITY',        0.982 );

// SSIM 探索結果の品質がこの値未満のとき Edge Residual Mean (ERE) セカンドオピニオンを行う
// @since 2.1.0
define( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_EFFICIENCY',  60 );
define( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_BALANCE',     65 );
define( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_QUALITY',     70 );

// ERE セカンドオピニオンの目標上限 (この値以下を合格とする)
// 指標は誤差上位バンドの正規化平均 (top_mean。バンド幅は下記の keep/trim 比率で形式別)。
// dB は Edge_Residual_Mean::convert_to_dB() と同じ -10*log10(top_mean)
// @since 2.1.0
define( 'STILLBE_IQ_MAE_TARGET_EFFICIENCY',           0.095 );  // ≈10.2 dB
define( 'STILLBE_IQ_MAE_TARGET_BALANCE',              0.080 );  // ≈11.0 dB
define( 'STILLBE_IQ_MAE_TARGET_QUALITY',              0.065 );  // ≈11.9 dB

// ERE top_mean のバンド比率 (keep = 保持する誤差上位, trim = 除外する外れ値上位。バンド = keep - trim)
// ブロック境界のエッジ残差を拾う想定。WebP(16x16 + デブロッキング) は上位約 1% バンド。
// JPEG はデブロッキングが無く 8x8 のブロック角に残差が集中して高く出るため、上位約 2% に広げて均す。
// AVIF は平坦部でブロックを大きく取る(最大 64x64〜)ため劣化面積が小さく、上位 0.1% バンドに絞る。
// @since 2.1.0
define( 'STILLBE_IQ_ERE_TOP_KEEP_DEFAULT',            0.011 );   // 保持 1.1%
define( 'STILLBE_IQ_ERE_TOP_TRIM_DEFAULT',            0.001 );   // 除外 0.1% -> バンド約 1%
define( 'STILLBE_IQ_ERE_TOP_KEEP_JPEG',               0.022 );   // 保持 2.2%
define( 'STILLBE_IQ_ERE_TOP_TRIM_JPEG',               0.002 );   // 除外 0.2% -> バンド約 2%
define( 'STILLBE_IQ_ERE_TOP_KEEP_AVIF',               0.0011 );  // 保持 0.11%
define( 'STILLBE_IQ_ERE_TOP_TRIM_AVIF',               0.0001 );  // 除外 0.01% -> バンド約 0.1%

// Prefix
define( 'STILLBE_IQ_PREFIX',                          'sb-imgq-' );

// Require the Version of Extends Plugin
define( 'STILLBE_IQ_REQUIRED_EXT_PLUGIN_VER',         '2.0.0' );

// Download URL of Extends Plugin
define( 'STILLBE_IQ_REQUIRED_EXT_PLUGIN_URL',         'https://still-be.com/download/still-be-image-quality-control-extends-v2.0.0.zip' );

// Plugin Base Dir
if( ! defined( 'STILLBE_IQ_BASE_DIR' ) ) {
	define( 'STILLBE_IQ_BASE_DIR', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) );
}

// Plugin Base URL
if( ! defined( 'STILLBE_IQ_BASE_URL' ) ) {
	define( 'STILLBE_IQ_BASE_URL', trailingslashit( plugin_dir_url( dirname( __FILE__ ) ) ) );
}





// END of the File
