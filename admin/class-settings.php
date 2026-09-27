<?php
/**
 * Settings page.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Settings {

	/**
	 * Available tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'diagnostics' => __( 'Diagnostics', 'mm-optimizer' ),
			'formats'     => __( 'Formats and quality', 'mm-optimizer' ),
			'bulk'        => __( 'Bulk conversion', 'mm-optimizer' ),
			'delivery'    => __( 'Delivery', 'mm-optimizer' ),
			'advanced'    => __( 'Advanced', 'mm-optimizer' ),
		);
	}

	/**
	 * Active tab.
	 *
	 * @return string
	 */
	protected static function current_tab() {
		$tabs = self::tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only, nothing is modified.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'diagnostics';

		return isset( $tabs[ $tab ] ) ? $tab : 'diagnostics';
	}

	/**
	 * Draws the page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = self::current_tab();

		echo '<div class="wrap mm-opt-wrap">';
		echo '<h1>' . esc_html__( 'MM Optimizer', 'mm-optimizer' ) . '</h1>';

		settings_errors( Options::KEY );

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=mm-optimizer&tab=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		echo '<div class="mm-opt-tab-body">';

		if ( 'diagnostics' === $current ) {
			Report::render();
		} elseif ( 'bulk' === $current ) {
			Bulk::render();
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			settings_fields( Options::KEY );

			switch ( $current ) {
				case 'formats':
					self::render_formats();
					break;
				case 'delivery':
					self::render_delivery();
					break;
				case 'advanced':
					self::render_advanced();
					break;
			}

			submit_button();
			echo '</form>';
		}

		echo '</div></div>';
	}

	/**
	 * Formats and quality tab.
	 *
	 * @return void
	 */
	protected static function render_formats() {
		$caps    = Capabilities::get();
		$level   = Options::get( 'level', 'balanced' );
		$key     = Options::KEY;

		echo '<h2>' . esc_html__( 'Optimization level', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The savings shown come from real measurements on a 2048 px photograph: they are not convenient estimates.', 'mm-optimizer' ) . '</p>';

		echo '<div class="mm-opt-presets">';

		foreach ( Presets::all() as $slug => $values ) {
			$checked = checked( $level, $slug, false );

			echo '<label class="mm-opt-preset' . ( $level === $slug ? ' is-active' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( $key ) . '[level]" value="' . esc_attr( $slug ) . '" ' . $checked . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns fixed markup.
			echo '<span class="mm-opt-preset-name">' . esc_html( Presets::label( $slug ) ) . '</span>';
			echo '<span class="mm-opt-preset-desc">' . esc_html( Presets::description( $slug ) ) . '</span>';

			if ( ! empty( $values ) ) {
				echo '<span class="mm-opt-preset-nums">';
				printf(
					/* translators: 1: WebP quality, 2: estimated WebP saving, 3: AVIF quality, 4: estimated AVIF saving. */
					esc_html__( 'WebP q%1$d (about -%2$d%%) · AVIF q%3$d (about -%4$d%%)', 'mm-optimizer' ),
					(int) $values['webp_jpeg'],
					(int) Presets::estimated_saving( $slug, 'webp' ),
					(int) $values['avif_jpeg'],
					(int) Presets::estimated_saving( $slug, 'avif' )
				);
				echo '</span>';
			}

			echo '</label>';
		}

		echo '</div>';

		echo '<h2>' . esc_html__( 'Formats to generate', 'mm-optimizer' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( array( 'avif' => 'AVIF', 'webp' => 'WebP' ) as $format => $label ) {
			$mime      = 'image/' . $format;
			$engine    = isset( $caps['encoders'][ $mime ]['engine'] ) ? $caps['encoders'][ $mime ]['engine'] : '';
			$available = '' !== $engine;

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[' . esc_attr( $format ) . '][enabled]" value="1" ' .
				checked( Options::get( $format . '.enabled' ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo esc_html__( 'Generate the derivatives', 'mm-optimizer' ) . '</label><br />';

			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[' . esc_attr( $format ) . '][deliver]" value="1" ' .
				checked( Options::get( $format . '.deliver' ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo esc_html__( 'Deliver to the browser', 'mm-optimizer' ) . '</label>';

			echo '<p class="description">';
			if ( $available ) {
				printf(
					/* translators: %s: name of the encoding engine. */
					esc_html__( 'Engine in use: %s (chosen by detection).', 'mm-optimizer' ),
					'<code>' . esc_html( $engine ) . '</code>'
				);
			} else {
				esc_html_e( 'No encoder on this server supports this format.', 'mm-optimizer' );
			}
			echo '</p></td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Custom values', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Used only when the level is set to Custom.', 'mm-optimizer' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$fields = array(
			'webp_jpeg'  => array( __( 'WebP quality from JPEG', 'mm-optimizer' ), 1, 100 ),
			'webp_png'   => array( __( 'WebP quality from PNG', 'mm-optimizer' ), 1, 100 ),
			'avif_jpeg'  => array( __( 'AVIF quality from JPEG', 'mm-optimizer' ), 1, 100 ),
			'avif_png'   => array( __( 'AVIF quality from PNG', 'mm-optimizer' ), 1, 100 ),
			'avif_speed' => array( __( 'AVIF speed (0 slow, 10 fast)', 'mm-optimizer' ), 0, 10 ),
			'cwebp_m'    => array( __( 'cwebp method (0-6)', 'mm-optimizer' ), 0, 6 ),
			'min_saving' => array( __( 'Minimum saving to keep the file (%)', 'mm-optimizer' ), 0, 50 ),
			'skip_bytes' => array( __( 'Skip images smaller than (bytes)', 'mm-optimizer' ), 0, 10485760 ),
		);

		foreach ( $fields as $name => $spec ) {
			echo '<tr><th scope="row"><label for="mm-opt-' . esc_attr( $name ) . '">' . esc_html( $spec[0] ) . '</label></th><td>';
			echo '<input type="number" class="small-text" id="mm-opt-' . esc_attr( $name ) . '" name="' . esc_attr( $key ) . '[custom][' . esc_attr( $name ) . ']" ';
			echo 'value="' . esc_attr( (string) Options::get( 'custom.' . $name ) ) . '" min="' . esc_attr( (string) $spec[1] ) . '" max="' . esc_attr( (string) $spec[2] ) . '" />';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Delivery tab.
	 *
	 * @return void
	 */
	protected static function render_delivery() {
		$key       = Options::KEY;
		$conflicts = Conflicts::active();

		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: list of conflicting plugins. */
				esc_html__( 'Delivery is suspended: %s already handles this site\'s images. The settings below stay editable and will take effect as soon as the other plugin is deactivated.', 'mm-optimizer' ),
				esc_html( implode( ', ', $conflicts ) )
			);
			echo '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'mm-optimizer' ) . '</th><td>';

		$modes = array(
			'picture'  => __( '&lt;picture&gt; markup (recommended)', 'mm-optimizer' ),
			'htaccess' => __( '.htaccess content negotiation', 'mm-optimizer' ),
			'off'      => __( 'No delivery (visitors get the original files)', 'mm-optimizer' ),
		);

		$mode = Options::get( 'delivery.mode', 'picture' );

		foreach ( $modes as $slug => $label ) {
			echo '<label style="display:block;margin-bottom:6px"><input type="radio" name="' . esc_attr( $key ) . '[delivery][mode]" value="' . esc_attr( $slug ) . '" ' . checked( $mode, $slug, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo wp_kses( $label, array() ) . '</label>';
		}

		echo '<p class="description">' . esc_html__( '<picture> mode puts every variant into the markup and lets the browser choose: it needs no Vary: Accept and leaves page caches and CDNs alone. .htaccess negotiation does not touch the HTML but forces Vary: Accept on every image, which in practice disables CDN caching.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		$checkboxes = array(
			'addtype'        => array( __( 'Declare the MIME types in .htaccess', 'mm-optimizer' ), __( 'Required on many servers: without it .avif files are served as application/octet-stream and Firefox and Safari cannot decode them.', 'mm-optimizer' ) ),
			'buffer'         => array( __( 'Rewrite the whole page', 'mm-optimizer' ), __( 'Only turn this on if the theme produces images that escape WordPress\'s standard filters. It costs something on every request.', 'mm-optimizer' ) ),
			'partial_srcset' => array( __( 'Allow partial srcsets', 'mm-optimizer' ), __( 'Not recommended. If even one derivative is missing the browser can pick a lower resolution than the original, a visible regression that is hard to diagnose.', 'mm-optimizer' ) ),
			'verify_disk'    => array( __( 'Check every derivative on disk', 'mm-optimizer' ), __( 'Safer but slower: it adds hundreds of filesystem calls per page. The database is normally enough.', 'mm-optimizer' ) ),
		);

		foreach ( $checkboxes as $name => $spec ) {
			echo '<tr><th scope="row">' . esc_html( $spec[0] ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[delivery][' . esc_attr( $name ) . ']" value="1" ' . checked( Options::get( 'delivery.' . $name ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo esc_html__( 'Enable', 'mm-optimizer' ) . '</label>';
			echo '<p class="description">' . esc_html( $spec[1] ) . '</p></td></tr>';
		}

		echo '<tr><th scope="row"><label for="mm-opt-exclude">' . esc_html__( 'CSS classes to exclude', 'mm-optimizer' ) . '</label></th><td>';
		echo '<textarea id="mm-opt-exclude" class="large-text code" rows="3" name="' . esc_attr( $key ) . '[delivery][exclude_classes]">';
		echo esc_textarea( implode( "\n", (array) Options::get( 'delivery.exclude_classes', array() ) ) );
		echo '</textarea>';
		echo '<p class="description">' . esc_html__( 'One class per line. Images carrying it will not be wrapped in a <picture>. This is needed when a stylesheet uses direct-child selectors such as .gallery > img, which stop matching once an element is interposed.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Advanced tab.
	 *
	 * @return void
	 */
	protected static function render_advanced() {
		$key = Options::KEY;

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Thumbnail optimization', 'mm-optimizer' ) . '</th><td>';

		$caps = Capabilities::get();

		foreach ( array( 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF' ) as $format => $label ) {
			$mime      = 'image/' . $format;
			$available = ! empty( $caps['lossless'][ $mime ]['available'] );

			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $key ) . '[optimize][' . esc_attr( $format ) . ']" value="1" ' .
				checked( Options::get( 'optimize.' . $format ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo esc_html( $label );

			if ( ! $available ) {
				echo ' <span class="description">' . esc_html__( '(no tool available on this server)', 'mm-optimizer' ) . '</span>';
			}

			echo '</label>';
		}

		echo '<p class="description">' . esc_html__( 'This applies only to thumbnails WordPress generated. Original files are never modified, under any circumstances.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Sizes to convert', 'mm-optimizer' ) . '</th><td>';

		$modes = array(
			'srcset' => __( 'Only the ones used in a srcset (recommended)', 'mm-optimizer' ),
			'all'    => __( 'Every registered size', 'mm-optimizer' ),
		);

		$size_mode = Options::get( 'sizes.mode', 'srcset' );

		foreach ( $modes as $slug => $label ) {
			echo '<label style="display:block"><input type="radio" name="' . esc_attr( $key ) . '[sizes][mode]" value="' . esc_attr( $slug ) . '" ' . checked( $size_mode, $slug, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo esc_html( $label ) . '</label>';
		}

		$count = function_exists( 'wp_get_registered_image_subsizes' ) ? count( wp_get_registered_image_subsizes() ) : 0;

		echo '<p class="description">' . sprintf(
			/* translators: %d: number of registered image sizes. */
			esc_html__( 'This site has %d registered sizes. Converting them all into two formats multiplies the work and the disk space, often for files no page ever requests.', 'mm-optimizer' ),
			(int) $count
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Diagnostic log', 'mm-optimizer' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[log_enabled]" value="1" ' . checked( Options::get( 'log_enabled' ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo esc_html__( 'Enabled', 'mm-optimizer' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'mm-optimizer' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[uninstall_delete]" value="1" ' . checked( Options::get( 'uninstall_delete' ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo esc_html__( 'When the plugin is deleted, remove its settings, tables and generated derivatives', 'mm-optimizer' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default: deactivating the plugin never deletes anything.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}
}
