<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




trait Setting_Main {

	// Initialization of the Settings
	protected function _init_settings() {

		// Curernt Settings
		$this->settings = get_option( self::SETTING_NAME, array() );

		// 
		if( isset( $this->settings['toggle']['enable-interlace'] ) &&  ! isset( $this->settings['toggle']['enable-progressive-jpeg'] ) ) {
			$this->settings['toggle']['enable-progressive-jpeg'] = $this->settings['toggle']['enable-interlace'];
		}
		if( isset( $this->settings['toggle']['enable-interlace'] ) &&  ! isset( $this->settings['toggle']['enable-interlace-png'] ) ) {
			$this->settings['toggle']['enable-interlace-png'] = $this->settings['toggle']['enable-interlace'];
		}

		// Default Settings
		$this->_default = _stillbe_get_quality_level_array();

		// Current Settings
		// When it is Empty, Use the Plugin's Default Settings
		$this->current  = wp_parse_args(
			$this->settings,
			array(
				'quality'              => $this->_default,
				'auto-optimize-target' => 'balance',
				'auto-optimize-concurrency' => STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY,
				'toggle'  => array(
					'safe-name'                     => true,
					'delete-original-large'         => STILLBE_IQ_ENABLE_DELETE_ORIGINAL_LARGE,  // false
					'autoset-alt'                   => STILLBE_IQ_AUTOSET_ALT_FROM_EXIF,         // false
					'optimize-srcset'               => STILLBE_IQ_OPTIMIZE_SRCSET,               // true
					'force-cache-clear'             => STILLBE_IQ_ENABLE_FORCE_CACHE_CLEAR,      // true
					'level-suffix'                  => STILLBE_IQ_ENABLE_QUALITY_VALUE_SUFFIX,   // false
				//	'enable-interlace'              => STILLBE_IQ_ENABLE_INTERLACE,              // true
					'enable-progressive-jpeg'       => STILLBE_IQ_ENABLE_INTERLACE_JPEG,         // true
					'enable-interlace-png'          => STILLBE_IQ_ENABLE_INTERLACE_PNG,          // false
				//	'enable-png8'                   => STILLBE_IQ_ENABLE_INDEX_COLOR,            // GD: false, Iagick: true
					'enable-png8-force'             => STILLBE_IQ_ENABLE_INDEX_COLOR_FORCE,      // false
					'enable-webp'                   => STILLBE_IQ_ENABLE_WEBP,                   // true
					'enable-avif'                   => STILLBE_IQ_ENABLE_AVIF,                   // true
					'enable-cwebp'                  => STILLBE_IQ_ENABLE_CWEBP_LIBRARY,          // true
					'enable-webp-lossless'          => STILLBE_IQ_ENABLE_WEBP_LOSSLESS,          // true
					'enable-webp-near-lossless'     => STILLBE_IQ_ENABLE_WEBP_NEAR_LOSSLESS,     // false
					'enable-decimal-timeout-wpcron' => STILLBE_IQ_ENABLE_DECIMAL_TIMEOUT_WPCRON, // true
					'enable-auto-optimize'          => STILLBE_IQ_ENABLE_AUTO_OPTIMIZE,          // false
					'enable-auto-optimize-on-recompress' => false,
					'enable-purge-delivery-webp-on-recompress' => false,
				),
			)
		);

		// Merge default quality level table settings
		// @since 2.1.0
		$this->current['quality'] = wp_parse_args(
			$this->current['quality'],
			$this->_default
		);

		// Escape for a Note
		$this->allowed_tags_for_note = array(
			'small' => array(
				'class' => array(),
			),
			'em' => array(
				'class' => array(),
			),
			'i'  => array(),
			'br' => array(),
			// for 'pre_kses' hook
			'pre_kses' => array(),
		);
		add_filter( 'pre_kses', function( $string, $allowed_html ) {
			if( isset( $allowed_html['pre_kses'] ) ) {
				return str_replace( array( '&amp;', '&#38;', '&#x26;' ), '&', $string );
			}
			return $string;
		}, 10, 2 );

		add_action( 'admin_init', function() {

			// Image Editor (respect preferred library when both are available)
			$prefer_gd = ( 'gd' === stillbe_iqc_get_preferred_image_editor_library() );

			$imagick_ok = class_exists( Image_Editor_Imagick::class )
			                && Image_Editor_Imagick::test()
			                && Image_Editor_Imagick::supports_mime_type( 'image/webp' );
			$gd_ok      = class_exists( Image_Editor_GD::class )
			                && Image_Editor_GD::test()
			                && Image_Editor_GD::supports_mime_type( 'image/webp' );

			if( $prefer_gd && $gd_ok ) {
				$this->_editor = Image_Editor_GD::class;
				if( ! defined( 'STILLBE_IQ_ENABLE_INDEX_COLOR' ) ) {
					define( 'STILLBE_IQ_ENABLE_INDEX_COLOR', STILLBE_IQ_ENABLE_INDEX_COLOR_GD );
				}
			} elseif( $imagick_ok ) {
				$this->_editor = Image_Editor_Imagick::class;
				if( ! defined( 'STILLBE_IQ_ENABLE_INDEX_COLOR' ) ) {
					define( 'STILLBE_IQ_ENABLE_INDEX_COLOR', STILLBE_IQ_ENABLE_INDEX_COLOR_IMAGICK );
				}
			} elseif( $gd_ok ) {
				$this->_editor = Image_Editor_GD::class;
				if( ! defined( 'STILLBE_IQ_ENABLE_INDEX_COLOR' ) ) {
					define( 'STILLBE_IQ_ENABLE_INDEX_COLOR', STILLBE_IQ_ENABLE_INDEX_COLOR_GD );
				}
			}

			if( ! isset( $this->current['toggle']['enable-png8'] ) ) {
				$this->current['toggle']['enable-png8'] = STILLBE_IQ_ENABLE_INDEX_COLOR;
			}

			// Is Enabled Lossless Compression?
			// 利用可否は \Imagick の有無のみで判定する
			$this->is_lossless_options = class_exists( '\Imagick' )
			                               && apply_filters( 'stillbe_image_quality_control_enable_webp_lossless_for_png_gif', STILLBE_IQ_ENABLE_WEBP_LOSSLESS );

			// Is Enabled Near-Lossless Compression? (cwebp 専用)
			$this->is_near_lossless    = $this->is_lossless_options
			                               && stillbe_iqc_is_extended()
			                               && apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY )
			                               && apply_filters( 'stillbe_image_quality_control_enable_webp_near_lossless', STILLBE_IQ_ENABLE_WEBP_NEAR_LOSSLESS );

			// Add the Notice of Checking Extension Plugin Version
			add_action( 'admin_notices', function() {

			//	$screen  = get_current_screen();
			//	 || empty( $screen->id ) ||
			//	      ( 'settings_page_'. STILLBE_IQ_PREFIX. 'setting-page' ) !== $screen->id

				if( $this->supported_extension_plugin_ver() ) {
					return;
				}

				$ext_ver = defined( 'STILLBE_IQ_EXT_PLUGIN_VER' ) ? STILLBE_IQ_EXT_PLUGIN_VER : '0.0.0 (unknown)';

				echo '<div class="notice notice-warning settings-error is-dismissible">';
				echo   '<p style="font-size: 1.1em; font-weight: bolder;">'. esc_html( sprintf( 'Notice: %s', $this->plugin_name ) ). '</p>';
				echo   '<p>'.  esc_html( sprintf( __( 'The extension plugin version %s is not supported.', 'still-be-image-quality-control' ), $ext_ver ) );
				echo   '<br>'. esc_html( sprintf( __( 'Supported Version: %s+', 'still-be-image-quality-control' ), STILLBE_IQ_REQUIRED_EXT_PLUGIN_VER ) );
				echo   '<small style="margin: 0 1em; font-size: 0.9em; color: #666;">(';
				echo     '<a href="'. esc_url( STILLBE_IQ_REQUIRED_EXT_PLUGIN_URL ). '" download="'. esc_attr( wp_basename( STILLBE_IQ_REQUIRED_EXT_PLUGIN_URL ) ). '" target="_blank">';
				echo       esc_html__( 'Download', 'still-be-image-quality-control' );
				echo     ')</a>';
				echo   '</small></p>';
				echo '</div>';

			} );

		}, 10 );

	}


	// Initialization of the Plugin
	protected function _init_plugin() {

		// Add a Submenu Page
		add_action( 'admin_menu', function() {

		//	add_submenu_page(
		//	Wrapper Function adding submenu page to the Settings main menu.
			add_options_page(
			//	'options-general.php',   // Parent Slug
				esc_html__( 'Image Qualities Setting', 'still-be-image-quality-control' ),   // Page Title
				esc_html__( 'Image Qualities', 'still-be-image-quality-control' ),    // Menu Title
				'manage_options',   // Capability
				STILLBE_IQ_PREFIX. 'setting-page',   // Menu Slug
				array( $this, 'render_setting_page' )   // Rederer
			);

		} );

		// Add settings link to plugin actions
		add_filter( 'plugin_action_links', function( $plugin_actions, $plugin_file ) {

			if( basename( STILLBE_IQ_BASE_DIR ). '/stillbe-image-quality-control.php' !== $plugin_file ) {
				return $plugin_actions;
			}

			return array_merge(
				array(
					'sb_iqc_settings' => sprintf(
						__( '<a href="%s">Settings</a>', 'still-be-image-quality-control' ),
						esc_url( admin_url( 'options-general.php?page='. STILLBE_IQ_PREFIX. 'setting-page' ) )
					),
				),
				$plugin_actions
			);

		}, 10, 2 );

		// Add Sponsor Link
		add_filter( 'plugin_row_meta', function( $plugin_meta, $plugin_file ) {

			if ( basename( STILLBE_IQ_BASE_DIR ). '/stillbe-image-quality-control.php' !== $plugin_file ) {
				return $plugin_meta;
			}

			return array_merge(
				$plugin_meta,
				array(
					'sb_iqc_sponsors' => sprintf(
						'<a href="%1$s"><span class="dashicons dashicons-star-filled" aria-hidden="true" style="font-size:14px;line-height:1.3"></span>%2$s</a>',
						'https://donate.stripe.com/aEUg2Q0iKgzbf0Q9AE',
						esc_html__( 'Sponsor', 'still-be-image-quality-control' )
					)
				)
			);

		}, 10, 2 );

		// Load CSS / Javascript for Admin
		add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {

			wp_enqueue_style(
				'stillbe-iqc-admin-form-common',
				STILLBE_IQ_BASE_URL. 'asset/admin.css',
				array(),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/admin.css' )
			);

		//	if( 'options-general.php' !== $GLOBALS['pagenow'] || STILLBE_IQ_PREFIX. 'setting-page' !== filter_input( INPUT_GET, 'page' ) ) {
			if( ( 'settings_page_'. STILLBE_IQ_PREFIX. 'setting-page' ) !== $hook_suffix ) {
				return;
			}

			// CSS
			wp_enqueue_style(
				'stillbe-iqc-admin-twentytwenty',
				STILLBE_IQ_BASE_URL. 'asset/twentytwenty/css/twentytwenty.css',
				array(),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/twentytwenty/css/twentytwenty.css' )
			);

			// Javascript
			wp_enqueue_script(
				'stillbe-iqc-admin-class-ssim',
				STILLBE_IQ_BASE_URL.'asset/class-ssim.js',
				array(),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/class-ssim.js' )
			);

			// WebGPU SSIM is WP 6.5+ Script Modules only (optional acceleration)
			$webgpu_module = false;
			if( function_exists( 'wp_register_script_module' ) && function_exists( 'wp_enqueue_script_module' ) ) {
				$webgpu_module = true;
				$shader_modules = array(
					'@stillbe-iqc/ssim-shader-luminance'      => 'asset/shaders/ssim-luminance.js',
					'@stillbe-iqc/ssim-shader-blur'           => 'asset/shaders/ssim-blur.js',
					'@stillbe-iqc/ssim-shader-local'          => 'asset/shaders/ssim-local.js',
					'@stillbe-iqc/ssim-shader-reduce-partial' => 'asset/shaders/ssim-reduce-partial.js',
					'@stillbe-iqc/ssim-shader-reduce-final'   => 'asset/shaders/ssim-reduce-final.js',
				);
				$shader_deps = array();
				foreach( $shader_modules as $module_id => $relative_path ) {
					wp_register_script_module(
						$module_id,
						STILLBE_IQ_BASE_URL. $relative_path,
						array(),
						(string) @filemtime( STILLBE_IQ_BASE_DIR. '/'. $relative_path )
					);
					$shader_deps[] = $module_id;
				}
				wp_enqueue_script_module(
					'@stillbe-iqc/ssim-webgpu',
					STILLBE_IQ_BASE_URL. 'asset/class-ssim-webgpu.js',
					$shader_deps,
					(string) @filemtime( STILLBE_IQ_BASE_DIR. '/asset/class-ssim-webgpu.js' )
				);
			}

			wp_enqueue_script(
				'stillbe-iqc-admin-common',
				STILLBE_IQ_BASE_URL.'asset/admin.js',
				array( 'wp-i18n' ),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/admin.js' )
			);
			wp_enqueue_script(
				'stillbe-iqc-admin-jquery-event-move',
				STILLBE_IQ_BASE_URL.'asset/twentytwenty/js/jquery.event.move.js',
				array( 'jquery' ),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/twentytwenty/js/jquery.event.move.js' )
			);
			wp_enqueue_script(
				'stillbe-iqc-admin-twentytwenty',
				STILLBE_IQ_BASE_URL.'asset/twentytwenty/js/jquery.twentytwenty.js',
				array( 'jquery', 'stillbe-iqc-admin-jquery-event-move' ),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/twentytwenty/js/jquery.twentytwenty.js' )
			);
			wp_enqueue_script(
				'stillbe-iqc-admin-recompress',
				STILLBE_IQ_BASE_URL.'asset/admin-recompress.js',
				array( 'wp-i18n', 'wp-api-fetch' ),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/admin-recompress.js' )
			);
			wp_enqueue_script(
				'stillbe-iqc-admin-test-image',
				STILLBE_IQ_BASE_URL.'asset/admin-test-image.js',
				array( 'wp-i18n', 'wp-api-fetch', 'stillbe-iqc-admin-class-ssim' ),
				@filemtime( STILLBE_IQ_BASE_DIR. '/asset/admin-test-image.js' )
			);
			wp_add_inline_script(
				'stillbe-iqc-admin-test-image',
				'window.$stillbe=(window.$stillbe||{});window.$stillbe.admin=(window.$stillbe.admin||{});'
				. 'window.$stillbe.admin.testImage=(window.$stillbe.admin.testImage||{});'
				. 'window.$stillbe.admin.testImage.webgpuModuleAvailable='. ( $webgpu_module ? 'true' : 'false' ). ';',
				'before'
			);

			// Add Script Translations
			wp_set_script_translations( 'stillbe-iqc-admin-common',     'still-be-image-quality-control' );
			wp_set_script_translations( 'stillbe-iqc-admin-recompress', 'still-be-image-quality-control' );
			wp_set_script_translations( 'stillbe-iqc-admin-test-image', 'still-be-image-quality-control' );

			// Enqueues all Media JS APIs
			wp_enqueue_media();

		} );

		// Add Settings
		add_action( 'admin_init', function() {

			// Setting API
			//  * Arg 1 : Group Name using Setting API
			//  * Arg 2 : Saving Key Name in WP Options table (in SQL)
			register_setting( self::SETTING_GROUP, self::SETTING_NAME, array( $this, 'sanitize_setting' ) );

		}, 10 );

	}


	// Render Setting Form Wrapper & Common Style
	public function render_setting_page() {

		// Wrapper
		echo '<div class="wrap">';

			// Title
			echo '<h1>'. esc_html__( 'Quality Level Settings of Image Compression', 'still-be-image-quality-control' ). '</h1>';

			// Setting Form
			echo '<form name="img-quality-control-setting-form" method="POST" action="options.php">';

				// Since it will be sanitized twice at the time of initial setting, add a flag to skip the second sanitize
				//   * This is because the core function "update_option" is sanitized in it if option does not exist,
				//     and then "add_option" is executed and sanitized in it too.
				echo '<input type="hidden" name="'. esc_attr( self::SETTING_NAME. '[not-sanitized]' ). '" value="true">';

				// Add the information using Setting API
				// Group Name / Action Name / Nonce / This Page Path
				settings_fields( self::SETTING_GROUP );

				// Output All Sections
				stillbe_do_settings_sections_tab_style( STILLBE_IQ_PREFIX. 'setting-page' );

				// Submit Button
				submit_button();

			// Closing From
			echo '</form>';

		// Closing Wrapper
		echo '</div>';

		// Javascript Common Settings
		echo '<script type="text/javascript">';
		echo   'window.$stillbe = (window.$stillbe || {});';
		echo   'window.$stillbe.admin = (window.$stillbe.admin || {});';
		echo   'window.$stillbe.admin.restBase     = "'. esc_js( Rest_Api::REST_NAMESPACE ). '";';
		echo   'window.$stillbe.admin.restTimeoutSec = '. absint( STILLBE_IQ_WPCRON_MAX_EXECUTION_TIME ). ';';
		echo   'window.$stillbe.admin.translate    =  '. json_encode( $this->js_translate ). ';';
		echo   'window.$stillbe.admin.wpImageSizes =  '. json_encode( $this->get_all_sizes() ). ';';
		echo '</script>';

	}


}