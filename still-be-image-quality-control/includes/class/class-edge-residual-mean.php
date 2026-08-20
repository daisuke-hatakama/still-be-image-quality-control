<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * Edge Residual Mean (ERE) 計算用 Class
 *
 * カラー差分 → グレースケール化 → エッジ強調 → 絶対値平均。
 * 諧調がなだらかな画像で SSIM 探索が品質を下げすぎる場合のセカンドオピニオン用途。
 *
 * - Imagick: COMPOSITE_DIFFERENCE → GRAY → edgeImage → 絶対値平均
 * - GD: RGB 絶対値差分 → GRAYSCALE → EDGEDETECT → 全画素の絶対値平均
 *
 * 全画素平均 (mean) は平坦部に薄まって局所劣化を拾いにくいため、
 * 形式別の誤差上位バンドから外れ値を除外した平均 (top_mean) も算出する。
 * 小画像では安定性のため採用バンドを最低100サンプルまで広げ、判定にはこちらを使う。
 *
 * Imagick / GD とも全画素を対象にし、要素数差で閾値感度が変わらないようにする。
 *
 * エディタは cwebp を無効化した wp_get_image_editor() で取得すること (Imagick 優先)。
 *
 * @since 2.1.0
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




class Edge_Residual_Mean {


	/**
	 * top_mean 用の既定: 保持する誤差上位の割合 (1.1%)
	 *
	 * ブロック境界のエッジ残差を拾う想定。JPEG(8x8) / WebP(16x16) は劣化が密に分布するため
	 * 上位約 1% バンドが妥当。AVIF のように大ブロック化で劣化面積が小さい形式は
	 * calc() に狭い比率を渡して上位に絞り込む。
	 */
	const TOP_KEEP_RATIO = 0.011;

	/**
	 * top_mean 用の既定: 外れ値として除外する誤差上位の割合 (0.1%)
	 */
	const TOP_TRIM_RATIO = 0.001;

	/**
	 * top_mean の採用バンドに確保する最低サンプル数
	 *
	 * 小画像では比率だけだと数画素になって不安定なため、取得可能な範囲で100サンプルを確保する。
	 */
	const TOP_MIN_SAMPLES = 100;


	protected $original = null;
	protected $compare  = null;

	/**
	 * デバッグ PNG ファイル名用のサフィックス (例: -medium-72-jpeg-imagick)
	 *
	 * @var string
	 */
	protected $debug_name_suffix = '';

	/**
	 * デバッグ PNG ファイル名用の候補トークン (sb-iqc-cand-{token} と共有)
	 *
	 * @var string
	 */
	protected $debug_token = '';


	/**
	 * @param object|null $original export_image_copy を持つ Image Editor
	 * @param object|null $compare  同上
	 */
	public function __construct( $original = null, $compare = null ) {

		$this->set_original( $original );
		$this->set_compare( $compare );

	}


	/**
	 * 元画像 (参照) を設定する
	 *
	 * @param object $original
	 */
	public function set_original( $original ) {

		if( ! empty( $original ) && method_exists( $original, 'export_image_copy' ) ) {
			$this->original = $original;
		}

	}


	/**
	 * 比較画像を設定する
	 *
	 * @param object $compare
	 */
	public function set_compare( $compare ) {

		if( ! empty( $compare ) && method_exists( $compare, 'export_image_copy' ) ) {
			$this->compare = $compare;
		}

	}


	/**
	 * 残差エッジ強度 (Edge Residual Mean) を計算する
	 *
	 * @param object|null $params {
	 *   @type bool   $normalize      true なら mean/min/max/top_mean を 0-1 に正規化する
	 *   @type float  $top_keep_ratio top_mean で保持する誤差上位の割合
	 *   @type float  $top_trim_ratio top_mean で外れ値として除外する誤差上位の割合
	 *   @type string $token          デバッグ PNG ファイル名用。候補一時画像 sb-iqc-cand-{token} と揃える
	 *   @type string $size           デバッグ PNG ファイル名用のサイズ名 (medium|large|...)
	 *   @type int    $quality        デバッグ PNG ファイル名用の品質レベル
	 *   @type string $type           デバッグ PNG ファイル名用の形式 (jpeg|webp|avif)
	 *   @type string $library        デバッグ PNG ファイル名用のライブラリ (gd|imagick|cwebp)
	 * }
	 * @return array|\WP_Error {
	 *   @type float $mean     全サンプル平均
	 *   @type float $min      サンプル最小 (Imagick は mean と同値)
	 *   @type float $max      サンプル最大 (Imagick は mean と同値)
	 *   @type float $top_mean 形式別の誤差上位バンドから外れ値を除外した平均 (最低100サンプル)
	 * }
	 */
	public function calc( $params = null ) {

		if( empty( $this->original ) || empty( $this->compare ) ) {
			return new \WP_Error( 'no_image', __( 'Image not loaded. Please load 2 valid image editor objects.', 'still-be-image-quality-control' ) );
		}

		$original_size = $this->original->get_size();
		$compare_size  = $this->compare->get_size();

		if( empty( $original_size ) || $original_size !== $compare_size ) {
			return new \WP_Error( 'different_image_sizes', __( 'The images to be compared are of different sizes. Please specify images of the same size.', 'still-be-image-quality-control' ) );
		}

		$original_depth = $this->original->get_image_depth();
		$compare_depth  = $this->compare->get_image_depth();

		if( $original_depth !== $compare_depth ) {
			return new \WP_Error( 'different_image_depth', __( 'The images to be compared are of different color depth. Please specify images of the same depth.', 'still-be-image-quality-control' ) );
		}

		$normalize = is_object( $params ) ? ! empty( $params->normalize ) : false;

		// top_mean のバンド比率 (形式ごとに calc() 呼び出し側から上書き可能)
		$keep_ratio = ( is_object( $params ) && isset( $params->top_keep_ratio ) ) ? (float) $params->top_keep_ratio : self::TOP_KEEP_RATIO;
		$trim_ratio = ( is_object( $params ) && isset( $params->top_trim_ratio ) ) ? (float) $params->top_trim_ratio : self::TOP_TRIM_RATIO;

		$this->debug_name_suffix = $this->_build_debug_name_suffix( $params );
		$this->debug_token       = $this->_resolve_debug_token( $params );

		$is_imagick = false;
		if( $this->original instanceof Image_Editor_Imagick && $this->compare instanceof Image_Editor_Imagick ) {
			$is_imagick = true;
			$result     = $this->_calc_imagick( $keep_ratio, $trim_ratio );
		} elseif( $this->original instanceof Image_Editor_GD && $this->compare instanceof Image_Editor_GD ) {
			$result = $this->_calc_gd( $keep_ratio, $trim_ratio );
		} else {
			return new \WP_Error(
				'editor_mismatch',
				__( 'Both image editors must be the same type (Imagick or GD) for Edge Residual Mean.', 'still-be-image-quality-control' )
			);
		}

		if( is_wp_error( $result ) ) {
			return $result;
		}

		// Imagick の channel mean / top_mean は量子化範囲で正規化済み (おおむね 0-1)
		// GD の画素値は 0-255 スケールなので normalize 時だけ割る
		if( $normalize && ! $is_imagick ) {
			$il = ( 2 ** (int) $original_depth ) - 1;
			if( 1 > $il ) {
				$il = 255;
			}
			$result['mean']     /= $il;
			$result['min']      /= $il;
			$result['max']      /= $il;
			$result['top_mean'] /= $il;
		}

		return $result;

	}


	/**
	 * ERE (正規化 0-1 想定) を損失の dB 表現へ変換する
	 *
	 * @param float $ere 0-1 に正規化した Edge Residual Mean
	 * @return float
	 */
	static public function convert_to_dB( $ere ) {

		$ere = (float) $ere;

		if( 0 > $ere || 1 < $ere ) {
			throw new \Exception( 'Normalized Edge Residual Mean value is out of range 0.0-1.0.' );
		}

		if( 0 >= $ere ) {
			return 9999;
		}

		return -10 * log10( $ere );

	}


	/**
	 * Imagick: |RGB 差分| → GRAY → edge → 絶対値平均
	 *
	 * 色差分を先に取り、その後グレースケール化することで輝度・色差の両方を拾う。
	 *
	 * @param float $keep_ratio top_mean で保持する誤差上位の割合
	 * @param float $trim_ratio top_mean で除外する誤差上位の割合
	 * @return array|\WP_Error
	 */
	private function _calc_imagick( $keep_ratio = self::TOP_KEEP_RATIO, $trim_ratio = self::TOP_TRIM_RATIO ) {

		$img_a = null;
		$img_b = null;
		$diff  = null;

		try {
			$img_a = $this->original->export_image_copy();
			if( is_wp_error( $img_a ) ) {
				return $img_a;
			}
			$img_b = $this->compare->export_image_copy();
			if( is_wp_error( $img_b ) ) {
				return $img_b;
			}

			$diff = clone $img_a;
			// チャネルごとの絶対値差分
			$diff->compositeImage( $img_b, \Imagick::COMPOSITE_DIFFERENCE, 0, 0 );
			$this->_dump_debug_png_imagick( $diff, 'diff' );

			$diff->transformImageColorspace( \Imagick::COLORSPACE_GRAY );
			$this->_dump_debug_png_imagick( $diff, 'gray' );

			$diff->edgeImage( 1 );

			// edge 後の符号付き成分を絶対値へ
			if( is_callable( array( $diff, 'evaluateImage' ) ) ) {
				$diff->evaluateImage( \Imagick::EVALUATE_ABS, 1 );
			} elseif( is_callable( array( $diff, 'fxImage' ) ) ) {
				$diff->fxImage( 'abs(u)' );
			}
			$this->_dump_debug_png_imagick( $diff, 'edge' );

			$stats = $diff->getImageChannelMean( \Imagick::CHANNEL_ALL );
			if( ! is_array( $stats ) || ! isset( $stats['mean'] ) ) {
				return new \WP_Error( 'imagick_ere_failed', __( 'Imagick getImageChannelMean did not return a mean.', 'still-be-image-quality-control' ) );
			}

			$quantum = \Imagick::getQuantumRange();
			$qmax    = isset( $quantum['quantumRangeLong'] ) ? (float) $quantum['quantumRangeLong'] : 0.0;
			$mean    = (float) $stats['mean'];
			if( $qmax > 0 ) {
				$mean /= $qmax;
			}

			// 8bit ヒストグラムから上位バンド平均を算出 (0-1 へ正規化)
			$size     = $diff->getImageGeometry();
			$hist     = $this->_imagick_abs_histogram( $diff, (int) $size['width'], (int) $size['height'] );
			$top_mean = $this->_trimmed_top_mean_from_histogram( $hist, $keep_ratio, $trim_ratio ) / 255;

			return array(
				'mean'     => $mean,
				'min'      => $mean,
				'max'      => $mean,
				'top_mean' => $top_mean,
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'imagick_ere_exception', $e->getMessage() );
		} finally {
			if( $diff instanceof \Imagick ) {
				$diff->clear();
				$diff->destroy();
			}
			if( $img_a instanceof \Imagick ) {
				$img_a->clear();
				$img_a->destroy();
			}
			if( $img_b instanceof \Imagick ) {
				$img_b->clear();
				$img_b->destroy();
			}
		}

	}


	/**
	 * GD: RGB 絶対値差分 → GRAYSCALE → EDGEDETECT → 全画素の絶対値平均
	 *
	 * @param float $keep_ratio top_mean で保持する誤差上位の割合
	 * @param float $trim_ratio top_mean で除外する誤差上位の割合
	 * @return array|\WP_Error
	 */
	private function _calc_gd( $keep_ratio = self::TOP_KEEP_RATIO, $trim_ratio = self::TOP_TRIM_RATIO ) {

		$img_a = $this->original->export_image_copy();
		if( is_wp_error( $img_a ) ) {
			return $img_a;
		}
		$img_b = $this->compare->export_image_copy();
		if( is_wp_error( $img_b ) ) {
			$this->_destroy_gd_image( $img_a );
			return $img_b;
		}

		$size   = $this->original->get_size();
		$width  = (int) $size['width'];
		$height = (int) $size['height'];

		$diff = imagecreatetruecolor( $width, $height );
		if( false === $diff ) {
			$this->_destroy_gd_image( $img_a );
			$this->_destroy_gd_image( $img_b );
			return new \WP_Error( 'gd_diff_failed', __( 'Failed to create GD residual image for Edge Residual Mean.', 'still-be-image-quality-control' ) );
		}

		// チャネルごとの絶対値差分画像を作成してからグレースケール化
		for( $y = 0; $y < $height; ++$y ) {
			for( $x = 0; $x < $width; ++$x ) {
				$ca = imagecolorat( $img_a, $x, $y );
				$cb = imagecolorat( $img_b, $x, $y );
				$dr = abs( ( ( $ca >> 16 ) & 0xFF ) - ( ( $cb >> 16 ) & 0xFF ) );
				$dg = abs( ( ( $ca >> 8 ) & 0xFF ) - ( ( $cb >> 8 ) & 0xFF ) );
				$db = abs( ( $ca & 0xFF ) - ( $cb & 0xFF ) );
				imagesetpixel( $diff, $x, $y, ( $dr << 16 ) | ( $dg << 8 ) | $db );
			}
		}

		$this->_destroy_gd_image( $img_a );
		$this->_destroy_gd_image( $img_b );

		$this->_dump_debug_png_gd( $diff, 'diff' );

		imagefilter( $diff, IMG_FILTER_GRAYSCALE );
		$this->_dump_debug_png_gd( $diff, 'gray' );

		imagefilter( $diff, IMG_FILTER_EDGEDETECT );
		$this->_dump_debug_png_gd( $diff, 'edge' );

		$sum  = 0.0;
		$n    = 0;
		$min  = PHP_FLOAT_MAX;
		$max  = 0.0;
		$hist = array_fill( 0, 256, 0 );

		for( $y = 0; $y < $height; ++$y ) {
			for( $x = 0; $x < $width; ++$x ) {
				$v = abs( ( imagecolorat( $diff, $x, $y ) & 0xFF ) - 127 );
				$sum += $v;
				if( $v < $min ) {
					$min = $v;
				}
				if( $v > $max ) {
					$max = $v;
				}
				++$hist[ $v ];
				++$n;
			}
		}

		$this->_destroy_gd_image( $diff );

		if( 1 > $n ) {
			return array(
				'mean'     => 0.0,
				'min'      => 0.0,
				'max'      => 0.0,
				'top_mean' => 0.0,
			);
		}

		return array(
			'mean'     => $sum / $n,
			'min'      => ( PHP_FLOAT_MAX === $min ) ? 0.0 : (float) $min,
			'max'      => (float) $max,
			'top_mean' => $this->_trimmed_top_mean_from_histogram( $hist, $keep_ratio, $trim_ratio ),
		);

	}


	/**
	 * SB_IQC_DEBUG_IMAGE 時のみ ERE 中間画像を sb-temp へ PNG 出力するかどうか
	 *
	 * @return bool
	 */
	private function _should_dump_debug_images() {

		return defined( 'SB_IQC_DEBUG_IMAGE' ) && SB_IQC_DEBUG_IMAGE;

	}


	/**
	 * デバッグ PNG 用の候補トークンを解決する
	 *
	 * @param object|null $params
	 * @return string
	 */
	private function _resolve_debug_token( $params ) {

		if( is_object( $params ) && ! empty( $params->token ) ) {
			$token = preg_replace( '/[^a-z0-9]/i', '', (string) $params->token );
			if( '' !== $token ) {
				return $token;
			}
		}

		// フォールバック: 候補と紐付け不能な場合のみ新規発行
		return wp_generate_password( 6, false );

	}


	/**
	 * デバッグ PNG ファイル名用サフィックスを組み立てる
	 *
	 * @param object|null $params
	 * @return string 例: -medium-72-jpeg-imagick / -large-80-webp / ''
	 */
	private function _build_debug_name_suffix( $params ) {

		if( ! is_object( $params ) ) {
			return '';
		}

		$parts = array();

		if( ! empty( $params->size ) ) {
			$size = preg_replace( '/[^a-z0-9_-]/i', '', (string) $params->size );
			if( '' !== $size ) {
				$parts[] = strtolower( $size );
			}
		}

		if( isset( $params->quality ) && '' !== $params->quality && null !== $params->quality ) {
			$parts[] = (string) absint( $params->quality );
		}

		if( ! empty( $params->type ) ) {
			$type = preg_replace( '/[^a-z0-9]/i', '', (string) $params->type );
			if( '' !== $type ) {
				$parts[] = strtolower( $type );
			}
		}

		if( ! empty( $params->library ) ) {
			$library = preg_replace( '/[^a-z0-9]/i', '', (string) $params->library );
			if( '' !== $library ) {
				$parts[] = strtolower( $library );
			}
		}

		return empty( $parts ) ? '' : ( '-' . implode( '-', $parts ) );

	}


	/**
	 * ERE デバッグ PNG の出力パスを返す
	 *
	 * 形式: sb-iqc-ere-{stage}-{token}[-{size}-{quality}-{type}-{library}].png
	 * token は候補一時画像 sb-iqc-cand-{token}.* と同じ。
	 *
	 * @param string $stage diff|gray|edge
	 * @return string|\WP_Error
	 */
	private function _get_debug_dump_path( $stage ) {

		if( ! class_exists( __NAMESPACE__ . '\\Auto_Optimize' ) ) {
			return new \WP_Error( 'temp_dir_unavailable', 'Auto_Optimize is not loaded.' );
		}

		$dir = Auto_Optimize::get_plugin_temp_dir();
		if( is_wp_error( $dir ) ) {
			return $dir;
		}

		$stage = preg_replace( '/[^a-z0-9_-]/i', '', (string) $stage );
		if( '' === $stage ) {
			$stage = 'debug';
		}

		$token = '' !== $this->debug_token ? $this->debug_token : wp_generate_password( 6, false );

		$name = wp_unique_filename(
			$dir,
			'sb-iqc-ere-' . $stage . '-' . $token . $this->debug_name_suffix . '.png'
		);

		return trailingslashit( $dir ) . $name;

	}


	/**
	 * Imagick 画像を sb-temp へ PNG 出力する (SB_IQC_DEBUG_IMAGE 時のみ)
	 *
	 * @param \Imagick $imagick
	 * @param string   $stage
	 */
	private function _dump_debug_png_imagick( $imagick, $stage ) {

		if( ! $this->_should_dump_debug_images() || ! ( $imagick instanceof \Imagick ) ) {
			return;
		}

		$path = $this->_get_debug_dump_path( $stage );
		if( is_wp_error( $path ) ) {
			return;
		}

		try {
			$dump = clone $imagick;
			$dump->setImageFormat( 'png' );
			$dump->writeImage( $path );
			$dump->clear();
			$dump->destroy();
		} catch ( \Exception $e ) {
			// デバッグ出力の失敗は本処理に影響させない
		}

	}


	/**
	 * GD 画像を sb-temp へ PNG 出力する (SB_IQC_DEBUG_IMAGE 時のみ)
	 *
	 * @param resource|\GdImage $image
	 * @param string            $stage
	 */
	private function _dump_debug_png_gd( $image, $stage ) {

		if( ! $this->_should_dump_debug_images() || empty( $image ) ) {
			return;
		}

		$path = $this->_get_debug_dump_path( $stage );
		if( is_wp_error( $path ) ) {
			return;
		}

		@imagepng( $image, $path );

	}


	/**
	 * Imagick のエッジ残差画像を 8bit 階調のヒストグラムへ集計する
	 *
	 * exportImagePixels を行チャンクで呼び、メモリ使用量を一定に抑える。
	 *
	 * @param \Imagick $imagick グレースケール前提 (R チャンネルを使用)
	 * @param int      $width
	 * @param int      $height
	 * @return int[] 添字 0-255 の度数配列
	 */
	private function _imagick_abs_histogram( $imagick, $width, $height ) {

		$hist = array_fill( 0, 256, 0 );

		if( 1 > $width || 1 > $height ) {
			return $hist;
		}

		// 1 チャンクあたり約 100 万画素
		$chunk_rows = max( 1, (int) ( 1000000 / $width ) );

		for( $y = 0; $y < $height; $y += $chunk_rows ) {
			$rows   = min( $chunk_rows, $height - $y );
			$pixels = $imagick->exportImagePixels( 0, $y, $width, $rows, 'R', \Imagick::PIXEL_CHAR );
			foreach( $pixels as $v ) {
				++$hist[ $v & 0xFF ];
			}
			unset( $pixels );
		}

		return $hist;

	}


	/**
	 * ヒストグラムから「誤差上位 keep_ratio のうち上位 trim_ratio を外れ値として除外したバンド」の平均を返す
	 *
	 * 単発の外れ値 (枠際のエッジ応答など) に引きずられず、局所劣化の強さを表す値になる。
	 * 比率から求めた採用数が100未満の場合は、安定性を優先して採用バンドを100サンプルまで広げる。
	 * 総サンプル数が不足する場合は、外れ値除外後に取得できる全サンプルを使う。
	 * 戻り値のスケールはヒストグラムの階調値そのまま (呼び出し側で正規化する)。
	 *
	 * @param int[] $hist       添字 = 階調値 (昇順)、値 = 度数
	 * @param float $keep_ratio 保持する誤差上位の割合
	 * @param float $trim_ratio 除外する誤差上位の割合
	 * @return float
	 */
	private function _trimmed_top_mean_from_histogram( $hist, $keep_ratio = self::TOP_KEEP_RATIO, $trim_ratio = self::TOP_TRIM_RATIO ) {

		$total = (int) array_sum( $hist );
		if( 1 > $total ) {
			return 0.0;
		}

		$keep_ratio = max( 0.0, (float) $keep_ratio );
		$trim_ratio = max( 0.0, min( (float) $trim_ratio, $keep_ratio ) );

		$keep = max( 1, (int) ceil( $total * $keep_ratio ) );
		$trim = min( (int) floor( $total * $trim_ratio ), $keep - 1 );

		// 採用対象 [trim, keep) を最低100サンプル確保する。
		// 小画像では比率より下限を優先し、総数が不足する場合は取得可能な全数まで広げる。
		$min_samples = min( self::TOP_MIN_SAMPLES, $total - $trim );
		$keep        = min( $total, max( $keep, $trim + $min_samples ) );

		$seen  = 0;   // 上位側から数えた消化済みサンプル数
		$sum   = 0.0;
		$count = 0;

		for( $v = count( $hist ) - 1; 0 <= $v && $seen < $keep; --$v ) {
			$c = (int) $hist[ $v ];
			if( 1 > $c ) {
				continue;
			}
			// この階調のサンプルは順位 [seen, seen+c) を占める。採用対象は順位 [trim, keep)
			$overlap = min( $seen + $c, $keep ) - max( $seen, $trim );
			if( 0 < $overlap ) {
				$sum   += $overlap * $v;
				$count += $overlap;
			}
			$seen += $c;
		}

		return ( 0 < $count ) ? $sum / $count : 0.0;

	}


	/**
	 * @param resource|\GdImage|null $image
	 */
	private function _destroy_gd_image( $image ) {

		if( empty( $image ) ) {
			return;
		}
		if( is_resource( $image ) || ( is_object( $image ) && $image instanceof \GdImage ) ) {
			imagedestroy( $image );
		}

	}


}





// END of the File
