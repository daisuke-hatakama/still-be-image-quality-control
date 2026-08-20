<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




trait Setting_Section_Advanced_Others {


	// 
	protected function add_section_advanced_others() {

		// Add some Setting Sections
		add_action( 'admin_init', function() {

			// * Advanced Settings Section
			add_settings_section(
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section ID (Slug)
				esc_html( __( 'Advanced Settings', 'still-be-image-quality-control' ). ' 2' ),   // Section Title
				array( $this, 'render_sd_advanced_others' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page'   // Rendering Page
			);

		}, 11 );

		//////////////////////////
		// Add some Setting Fields
		// * Advanced Settings Section
		add_action( 'admin_init', function() {

			// Preferred Image Editor
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-preferred-image-editor',   // Field ID (Slug)
				esc_html__( 'Preferred Image Editor', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_preferred_image_editor' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()   // Arguments for Renderer Function
			);

			// Change the Big Image Size
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-big-threshold',   // Field ID (Slug)
				esc_html__( 'Big Image Threshold', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_big_threshold' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()   // Arguments for Renderer Function
			);

			// Auto optimize concurrency limit
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-auto-optimize-concurrency',   // Field ID (Slug)
				esc_html__( 'Auto Optimize Concurrency', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_auto_optimize_concurrency' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()
			);

			// Purge delivery WebP when scheduling auto-optimize after re-compression
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-enable-purge-delivery-webp-on-recompress',   // Field ID (Slug)
				esc_html__( 'Delete Delivery WebP on Re-Compression', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_toggle_options' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array(
					'field'       => 'enable-purge-delivery-webp-on-recompress',
					'description' => array(
						esc_html__( 'When Optimize after Re-Compression is enabled, delete existing delivery WebP files immediately after re-compression.', 'still-be-image-quality-control' ),
						esc_html__( 'If disabled (default), the previous WebP files remain available until automatic optimization replaces them.', 'still-be-image-quality-control' ),
					),
					'default'     => false,
				)
			);

			// Quality Level for Site Icon
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-quality-level-site-icon',   // Field ID (Slug)
				esc_html__( 'Quality Level of Compression for Site Icon', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_quality_level_table_site_icon' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()   // Arguments for Renderer Function
			);

			// Reset Settings
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-reset-settings',   // Field ID (Slug)
				esc_html__( 'Reset Settings', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_reset_settings' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()   // Arguments for Renderer Function
			);

			// Show Settings
			add_settings_field(
				STILLBE_IQ_PREFIX. 'sf-show-settings',   // Field ID (Slug)
				esc_html__( 'Show Settings', 'still-be-image-quality-control' ),   // Field Label
				array( $this, 'render_show_settings' ),   // Rederer
				STILLBE_IQ_PREFIX. 'setting-page',   // Rendering Page
				STILLBE_IQ_PREFIX. 'ss-advanced-others',   // Section
				array()   // Arguments for Renderer Function
			);

		}, 16 );

	}


	// Section Description; Advanced Setting
	public function render_sd_advanced_others() {

		echo '<p>'. esc_html__( 'Other advanced settings.', 'still-be-image-quality-control' ). '<br>';
		echo esc_html__( 'If you are not sure, do not change it unnecessarily.', 'still-be-image-quality-control' ). '</p>';

	}


	// Preferred Image Editor
	public function render_preferred_image_editor( $args ) {

		$default = defined( 'STILLBE_IQ_PREFERRED_IMAGE_EDITOR' ) ? STILLBE_IQ_PREFERRED_IMAGE_EDITOR : 'imagick';
		$current = isset( $this->current['preferred-image-editor'] ) ? $this->current['preferred-image-editor'] : $default;
		$current = ( 'gd' === $current ) ? 'gd' : 'imagick';

		$imagick_ok = class_exists( Image_Editor_Imagick::class ) && Image_Editor_Imagick::test();
		$gd_ok      = class_exists( Image_Editor_GD::class ) && Image_Editor_GD::test();

		echo '<div class="field-line">';
		echo   '<select name="'. esc_attr( self::SETTING_NAME. '[preferred-image-editor]' ). '">';
		echo     '<option value="imagick"'. selected( $current, 'imagick', false ). '>Imagick</option>';
		echo     '<option value="gd"'. selected( $current, 'gd', false ). '>GD</option>';
		echo   '</select>';
		echo '</div>';
		echo '<p>'. esc_html__( 'Choose which image editor library to prefer for compression and WebP / AVIF generation.', 'still-be-image-quality-control' ). '</p>';
		echo '<p>'. esc_html__( 'If the preferred editor is unavailable or does not support the requested format, the other editor is used as a fallback.', 'still-be-image-quality-control' ). '</p>';
		echo '<p><small class="your-server-status">'. esc_html__( 'Availability on your server:', 'still-be-image-quality-control' ). ' ';
		echo   (
			$imagick_ok ?
				'<em class="available">'. esc_html__( 'Available', 'still-be-image-quality-control' ) :
				'<em class="unavailable">'. esc_html__( 'Unavailable', 'still-be-image-quality-control' )
		). '</em><i>Imagick</i> / ';
		echo   (
			$gd_ok ?
				'<em class="available">'. esc_html__( 'Available', 'still-be-image-quality-control' ) :
				'<em class="unavailable">'. esc_html__( 'Unavailable', 'still-be-image-quality-control' )
		). '</em><i>GD</i></small></p>';
		echo '<p>'. esc_html__( 'Default: Imagick', 'still-be-image-quality-control' ). '</p>';

	}


	// Big Image Threshold
	public function render_big_threshold( $args ) {

		// Setting
		$threshold = isset( $this->current['big-threshold'] ) ? absint( $this->current['big-threshold'] ) : null;

		// Render HTML
		echo '<div class="field-line">';
		echo   '<input type="number" name="'. esc_attr( self::SETTING_NAME. '[big-threshold]' ). '" value="'. esc_attr( $threshold ). '">';
		echo   '<span class="unit-px">px</span>';
		echo '</div>';
		echo '<p>'.  esc_html__( 'Larger images are automatically scaled down when you upload the image to WP.', 'still-be-image-quality-control' );
		echo '<br>'. esc_html__( 'Change this threshold. The default is 2560px.', 'still-be-image-quality-control' ). '</p>';
		echo '<p>'.  esc_html__( 'Set to 0 to remove the limit.', 'still-be-image-quality-control' ). '</p>';
		echo '<p>'.  esc_html__( '* If not set, use the default value.', 'still-be-image-quality-control' ). '</p>';

	}


	// Auto Optimize Concurrency
	public function render_auto_optimize_concurrency( $args ) {

		$default = defined( 'STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY' ) ? (int) STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY : 2;
		$value   = isset( $this->current['auto-optimize-concurrency'] ) ? absint( $this->current['auto-optimize-concurrency'] ) : $default;
		if( $value < 1 ) {
			$value = $default;
		}

		echo '<div class="field-line">';
		echo   '<input type="number" min="1" step="1" name="'. esc_attr( self::SETTING_NAME. '[auto-optimize-concurrency]' ). '" value="'. esc_attr( $value ). '">';
		echo '</div>';
		echo '<p>'. esc_html__( 'Maximum number of automatic optimizations that may run at the same time.', 'still-be-image-quality-control' ). '</p>';
		echo '<p>'. esc_html__( 'Lower this on shared or low-memory hosts. Raise it only when the server has enough CPU and memory.', 'still-be-image-quality-control' ). '</p>';
		echo '<p>'. esc_html( sprintf(
			/* translators: %d: default concurrency */
			__( 'Default: %d', 'still-be-image-quality-control' ),
			$default
		) ). '</p>';

	}


	// Render Quality Level Table for Site Icon
	public function render_quality_level_table_site_icon( $args ) {

		// Site Icon Class
		require_once( ABSPATH. 'wp-admin/includes/class-wp-site-icon.php' );
		$site_icon  = new \WP_Site_Icon;
		$icon_sizes = apply_filters( 'intermediate_image_sizes_advanced', $site_icon->additional_sizes(), array(), 0 );
		uasort( $icon_sizes, function( $a, $b ) {
			if( $a['width'] !== $b['width'] ) {
				return $a['width'] - $b['width'];
			}
			if( $a['height'] !== $b['height'] ) {
				return $a['height'] - $b['height'];
			}
			return (int) $a['crop'] - (int) $b['crop'];
		} );

		// Current Setting
		$qualities = empty( $this->current['quality'] ) ? array() : $this->current['quality'];

		// Checked Mark SVG
		$checked = '<img src="'. ( STILLBE_IQ_BASE_URL. 'asset/icon-checked.svg' ). '" style="width: auto; height: 1.2em;">';
		$allowed_img_tag = array(
			'img' => array(
				'src'   => array(),
				'style' => array(),
			),
		);

		// Render HTML
		echo '<p>'.  esc_html__( 'Set the quality level for the site icon.', 'still-be-image-quality-control' );
		echo '<br>'. esc_html__( 'If not set, use the "Default Quality Level" on the "General Settings" tab.', 'still-be-image-quality-control' ). '</p>';
		echo '<div class="scroll-table-wrapper">';
		echo   '<table class="quality-level-table">';
		echo     '<thead>';
		echo       '<tr>';
		echo         '<th>'. esc_html__( 'Size Name', 'still-be-image-quality-control' ). '</th>';
		echo         '<th>'. esc_html__( 'Max Width', 'still-be-image-quality-control' ). '</th>';
		echo         '<th>'. esc_html__( 'Max Height', 'still-be-image-quality-control' ). '</th>';
		echo         '<th>'. esc_html__( 'Cropping', 'still-be-image-quality-control' ). '</th>';
		echo         '<th>JPEG</th>';
		echo         '<th>PNG</th>';
		echo         '<th>WebP</th>';
		echo       '</tr>';
		echo     '</thead>';
		echo     '<tbody id="quality_level_table_body">';
		foreach( $icon_sizes as $name => $size ) {
			$q_jpeg = empty( $qualities[( $name. '_jpeg' )] ) ? '' : $qualities[( $name. '_jpeg' )];
			$q_png  = empty( $qualities[( $name. '_png'  )] ) ? '' : $qualities[( $name. '_png'  )];
			$q_webp = empty( $qualities[( $name. '_webp' )] ) ? '' : $qualities[( $name. '_webp' )];
			$_class = 'width-'. intval( $size['width'] ). ' height-'. intval( $size['height'] );
			echo   '<tr class="'. esc_attr( $_class ). '">';
			echo     '<th class="embed-image-size-name">'. esc_html( $name ). '</th>';
			echo     '<th>'. esc_html( ( empty( $size['width']  ) ? __( '(No Limit)', 'still-be-image-quality-control' ) : $size['width'].  'px' ) ). '</th>';
			echo     '<th>'. esc_html( ( empty( $size['height'] ) ? __( '(No Limit)', 'still-be-image-quality-control' ) : $size['height']. 'px' ) ). '</th>';
			echo     '<th>'. ( empty( $size['crop'] ) ? '-' : wp_kses( $checked, $allowed_img_tag ) ). '</th>';
			echo     '<td><input type="number" name="'. esc_attr( self::SETTING_NAME. '[quality]['. $name. '][jpeg]' ). '" value="'. esc_attr( $q_jpeg ). '"></td>';
			echo     '<td><input type="number" name="'. esc_attr( self::SETTING_NAME. '[quality]['. $name. '][png]'  ). '" value="'. esc_attr( $q_png  ). '"></td>';
			echo     '<td><input type="number" name="'. esc_attr( self::SETTING_NAME. '[quality]['. $name. '][webp]' ). '" value="'. esc_attr( $q_webp ). '"></td>';
			echo   '</tr>';
		}
		echo     '</tbody>';
		echo   '</table>';
		echo '</div>';

	}


	// Reset Settings
	public function render_reset_settings( $args ) {

		// Note
		echo '<p>'. esc_html__( 'Reset all settings to their default values.', 'still-be-image-quality-control' ). '</p>';

		// Render HTML
		echo '<button type="button" id="reset_settings" style="margin-top: 8px;">'. esc_html__( 'Restore to default value', 'still-be-image-quality-control' ). '</button>';

	}


	// Show Settings
	public function render_show_settings( $args ) {

		// Note
		echo '<p>'. esc_html__( 'Display the JSON of the settings. It cannot be edited directlly.', 'still-be-image-quality-control' ). '</p>';

		// Render HTML
		echo '<textarea readonly style="margin-top: 1em; width: 100%; height: 160px; font-size: 0.8em;" onclick="this.select()">';
		echo   esc_html( json_encode( $this->current, JSON_PRETTY_PRINT ) );
		echo '</textarea>';

	}


}