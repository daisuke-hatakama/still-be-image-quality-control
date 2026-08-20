<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * SSIM 計算用 Class
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




class SSIM {


	const WINDOW_PARAM_RADIUS = 5;
	const WINDOW_PARAM_SIGMA  = 1.7;

	const SSIM_CONSTANT_K1 = 0.01;
	const SSIM_CONSTANT_K2 = 0.03;

	const PARALLEL_THREADS = 4;


	protected $original = null;
	protected $compare  = null;

	protected $window_coefficient = null;


	// Constructer
	public function __construct( $original = null, $compare = null ) {

		// 元画像
		if( ! empty( $original ) && method_exists( $original, 'get_luminance_array' ) ) {
			$this->original = $original;
		}

		// 比較画像
		if( ! empty( $compare ) && method_exists( $compare, 'get_luminance_array' ) ) {
			$this->compare = $compare;
		}

		$this->window_coefficient = $this->_calc_window_coefficient();

	}


	// Set Original Image
	public function set_original( $original ) {

		// 元画像
		if( ! empty( $original ) && method_exists( $original, 'get_luminance_array' ) ) {
			$this->original = $original;
		}

	}


	// Set Comapre Image
	public function set_compare( $compare ) {

		// 比較画像
		if( ! empty( $compare ) && method_exists( $compare, 'get_luminance_array' ) ) {
			$this->compare = $compare;
		}

	}


	/**
	 * SSIM を計算する
	 * 
	 * @param array $params パラメータ
	 * @return float SSIM の値
	 * 
	 * @refer  https://www.ieice-hbkb.org/files/02/02gun_05hen_09.pdf
	 * 
	 */
	public function calc( $params = null ) {

		if( empty( $this->original ) || empty( $this->compare ) ) {
			return new \WP_Error( 'no_image', __( 'Image not loaded. Please load 2 valid image editor objects.', 'still-be-image-quality-control' ) );
		}

		$original_size = $this->original->get_size();
		$comapre_size  = $this->compare->get_size();

		if( empty( $original_size ) || $original_size !== $comapre_size ) {
			return new \WP_Error( 'different_image_sizes', __( 'The images to be compared are of different sizes. Please specify images of the same size.', 'still-be-image-quality-control' ) );
		}

		$size = $original_size;

		$original_depth = $this->original->get_image_depth();
		$comapre_depth  = $this->compare->get_image_depth();

		if( $original_depth !== $comapre_depth ) {
			return new \WP_Error( 'different_image_depth', __( 'The images to be compared are of different color depth. Please specify images of the same depth.', 'still-be-image-quality-control' ) );
		}

		$depth = $original_depth;

		if( empty( $this->window_coefficient ) ) {
			$this->window_coefficient = $this->_calc_window_coefficient();
		}

		$w = $this->window_coefficient;

		$k1 = $params->k1 ?? self::SSIM_CONSTANT_K1;
		$k2 = $params->k2 ?? self::SSIM_CONSTANT_K2;
		$il = 2 ** $depth - 1;

		$c1 = ( $k1 * $il ) ** 2;
		$c2 = ( $k2 * $il ) ** 2;
		$c3 = $c2 / 2;   // 使用しない

		$r = $params->radius ?? self::WINDOW_PARAM_RADIUS;

		$ssim = 0;
		$min  = 1;
		$max  = 0;
		$n    = 0;

		if( false && class_exists( '\\parallel\\Runtime' ) ) {

			$runtimes = [];
			$interval = ceil( $size['width'] / self::PARALLEL_THREADS );

			for( $i = 0; $i < $size['width']; $i += $interval ) {
				$runtimes[] = ( new \parallel\Runtime() )->run( function() use( $i, $interval, $size, $r, $w, $c1, $c2 ) {
					return $this->_sub_ssim( $i, $interval, $size, $r, $w, $c1, $c2 );
				} );
			}

			foreach( $runtimes as $runtime ) {
				$calc  = $runtime->value();
				$ssim += $calc['mean'];
				$min  = min( $min, $calc['min'] );
				$max  = max( $max, $calc['max'] );
				++$n;
			}

		} else {

			$calc = $this->_sub_ssim( 0, $size['width'], $size, $r, $w, $c1, $c2 );
			$ssim = $calc['mean'];
			$min  = $calc['min'];
			$max  = $calc['max'];
			$n    = 1;

		}

		return array(
			'mean' => $ssim / $n,
			'min'  => $min,
			'max'  => $max,
		);

	}


	private function _sub_ssim( $start_x, $x_interval, $image_size, $r, $w, $C1, $C2 ) {

		$ssim = 0;
		$n    = 0;

		$end_x = min( $start_x + $x_interval, $image_size['width'] );

		$d = $r * 2 + 1;

		// 微笑領域での SIMM の最小値と最大値を保存する
		$dssim_min = 1;
		$dssim_max = 0;

		/**
		 * SSIM の計算負荷を抑えるために X 軸は微小エリアの幅の約半分 ($r + 1) をスキップし、
		 * Y 軸は微小エリアの幅ほど ($d = $r * 2 + 1) スキップする
		 * 
		 * ガウス関数による重み付けで影響が小さくなる微小領域の頂点に近いものも網羅されるように
		 * Y 軸は1回ごとに $r だけオフセットさせる
		 * 
		 */
		$offset = $r;
		for( $x = $start_x; $x < $end_x; $x += $r + 1 ) {
			// X 軸のオフセットを更新 (0 と $r を交互に繰り返す)
			$offset = $r - $offset;
			for( $y = $offset; $y < $image_size['height']; $y += $d ) {
				// ウィンドウ内の輝度値を取得 (一次元配列)
				$ca = $this->original->get_luminance_array( $x, $y, $r );
				$cb = $this->compare ->get_luminance_array( $x, $y, $r );
				// 窓関数を掛けて積算する
				$ui = 0;
				$ua = $ub = 0;
				for( $dx = -$r; $dx <= $r; ++$dx ) {
					for( $dy = -$r; $dy <= $r; ++$dy ) {
						$ua += $w[ $ui ] * $ca[ $ui ];
						$ub += $w[ $ui ] * $cb[ $ui ];
						++$ui;
					}
				}
				// 分散と共分散を計算
				$vi = 0;
				$va = $vb = $sab = 0;
				for( $dx = -$r; $dx <= $r; ++$dx ) {
					for( $dy = -$r; $dy <= $r; ++$dy ) {
						$da   = $ca[ $vi ] - $ua;
						$db   = $cb[ $vi ] - $ub;
						$va  += $w[ $vi ] * ( $da ** 2 );
						$vb  += $w[ $vi ] * ( $db ** 2 );
						$sab += $w[ $vi ] * ( $da *  $db );
						++$vi;
					}
				}
				// SSIM を計算
				$dsimm = ( 2 * $ua * $ub + $C1 ) * ( 2 * $sab + $C2 ) / ( ( $ua ** 2 + $ub ** 2 + $C1 ) * ( $va + $vb + $C2 ) );
				$ssim += $dsimm;
				// 最小と最大を保存する
				$dssim_min = min( $dssim_min, $dsimm );
				$dssim_max = max( $dssim_max, $dsimm );
				// 微小領域の数をインクリメント
				++$n;
			}
		}

		// Mean SSIM を計算
		return array(
			'mean' => $ssim / $n,
			'min'  => $dssim_min,
			'max'  => $dssim_max,
		);

	}


	private function _calc_window_coefficient( $args = null ) {

		$radius = absint( $args->radius ?? self::WINDOW_PARAM_RADIUS );
		$sigma  = abs(    $args->sigma  ?? self::WINDOW_PARAM_SIGMA  );

		$c = 1 / ( sqrt( 2 * M_PI ) * $sigma );
		$t = 2 * ( $sigma ** 2 );

		$size   = $radius * 2 + 1;
		$length = $size * $size;
		$window = new \SplFixedArray( $length );

		for( $x = -$radius; $x <= $radius; ++$x ) {
			for( $y = -$radius; $y <= $radius; ++$y ) {
				$i = ( $y + $radius ) * $size + $x + $radius;
				$window[ $i ] = $this->_calc_gaussian_distribution( $c, $t, $x )
				                  * $this->_calc_gaussian_distribution( $c, $t, $y );
			}
		}
		
		// 窓関数の合計値
		$mm = array_sum( $window->toArray() );

		// 正規化
		for( $i = 0; $i < $length; ++$i ) {
			$window[ $i ] /= $mm;
		}

		return $window;

	}


	private function _calc_gaussian_distribution( $c, $t, $d ) {

		return $c * exp( -( ( $d ** 2 ) / $t ) );

	}


	static public function convert_to_dB( $ssim ) {

		$ssim = (float)$ssim;

		if( 0 > $ssim || 1 < $ssim ) {
			throw new \Exception( 'SSIM value is out of range 0.0-1.0. ' );
		}

		if( 1 <= $ssim ) {
			return 9999;
		}
		
		return -10 * log10( 1 - (float)$ssim );
	}


}





// END of the File



