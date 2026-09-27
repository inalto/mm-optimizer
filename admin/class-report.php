<?php
/**
 * Renders the capability matrix and the recommended configuration.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Report {

	/**
	 * Draws the whole Diagnostics tab.
	 *
	 * @return void
	 */
	public static function render() {
		$caps     = Capabilities::get();
		$analysis = Recommender::analyze( $caps );

		self::render_summary( $caps, $analysis );
		self::render_matrix( $caps );
		self::render_bench( $caps );
		self::render_recommendation( $analysis );
		self::render_environment( $caps );
	}

	/**
	 * Header with the score and the buttons.
	 *
	 * @param array $caps     Capabilities.
	 * @param array $analysis Analysis.
	 * @return void
	 */
	protected static function render_summary( array $caps, array $analysis ) {
		$score = (int) $analysis['score'];
		$class = $score >= 80 ? 'mm-opt-good' : ( $score >= 50 ? 'mm-opt-warn' : 'mm-opt-bad' );

		echo '<div class="mm-opt-summary ' . esc_attr( $class ) . '">';
		echo '<div class="mm-opt-score"><strong>' . esc_html( $score ) . '</strong><span>/100</span></div>';
		echo '<div class="mm-opt-score-text">';
		echo '<h2>' . esc_html__( 'How suitable this server is', 'mm-optimizer' ) . '</h2>';
		echo '<p>' . esc_html( self::score_text( $score ) ) . '</p>';
		echo '<p class="description">' . sprintf(
			/* translators: 1: date and time of the last detection run, 2: duration in milliseconds. */
			esc_html__( 'Last detection: %1$s (%2$d ms).', 'mm-optimizer' ),
			esc_html( wp_date( 'j F Y, H:i', (int) $caps['generated'] ) ),
			(int) $caps['duration_ms']
		) . '</p>';
		echo '</div>';
		echo '<div class="mm-opt-score-actions">';
		echo '<button type="button" class="button" id="mm-opt-detect">' . esc_html__( 'Run detection again', 'mm-optimizer' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="mm-opt-apply">' . esc_html__( 'Apply the recommended configuration', 'mm-optimizer' ) . '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Short summary of the score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	protected static function score_text( $score ) {
		if ( $score >= 80 ) {
			return __( 'This server can do everything that is needed: images can be optimized without compromises.', 'mm-optimizer' );
		}
		if ( $score >= 50 ) {
			return __( 'This server can optimize images, but something is missing. Read the reasons below: they say what to ask your host for.', 'mm-optimizer' );
		}

		return __( 'This server has almost none of what is required. The reasons below list the packages to request from your host.', 'mm-optimizer' );
	}

	/**
	 * Formats-by-libraries matrix.
	 *
	 * @param array $caps Capabilities.
	 * @return void
	 */
	protected static function render_matrix( array $caps ) {
		$formats = array(
			'image/avif' => 'AVIF',
			'image/webp' => 'WebP',
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
		);

		echo '<h2>' . esc_html__( 'Graphics libraries detected', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each cell reports the outcome of a real test: the plugin encodes a known sample and checks the bytes it produced. Merely claimed support is not enough, because that is exactly what produces corrupted files without warning.', 'mm-optimizer' ) . '</p>';

		echo '<table class="widefat striped mm-opt-matrix"><thead><tr>';
		echo '<th>' . esc_html__( 'Format', 'mm-optimizer' ) . '</th>';

		$columns = array();
		foreach ( array( 'gd' => 'GD', 'imagick' => 'Imagick', 'gmagick' => 'Gmagick' ) as $key => $label ) {
			$columns[ $key ] = $label;
			$present         = ! empty( $caps['libraries'][ $key ]['present'] );
			$version         = $present && ! empty( $caps['libraries'][ $key ]['version'] ) ? ' ' . $caps['libraries'][ $key ]['version'] : '';

			echo '<th>' . esc_html( $label . $version );
			if ( ! $present ) {
				echo ' <span class="mm-opt-absent">' . esc_html__( '(absent)', 'mm-optimizer' ) . '</span>';
			}
			echo '</th>';
		}

		echo '<th>' . esc_html__( 'System binaries', 'mm-optimizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		$binary_for = array( 'image/webp' => 'cwebp', 'image/avif' => 'avifenc' );

		foreach ( $formats as $mime => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';

			foreach ( $columns as $key => $unused ) {
				$entry = isset( $caps['libraries'][ $key ]['formats'][ $mime ] ) ? $caps['libraries'][ $key ]['formats'][ $mime ] : null;
				echo '<td>' . self::cell( $entry ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns markup that is already escaped.
			}

			$binary = isset( $binary_for[ $mime ] ) ? $binary_for[ $mime ] : '';
			$entry  = ( '' !== $binary && isset( $caps['binaries'][ $binary ] ) ) ? $caps['binaries'][ $binary ] : null;

			echo '<td>';
			if ( null === $entry ) {
				echo '<span class="mm-opt-na">&mdash;</span>';
			} else {
				echo '<span class="mm-opt-binname">' . esc_html( $binary ) . '</span> ';
				echo self::cell( array( 'claimed' => '' !== $entry['path'], 'verified' => $entry['verified'], 'bytes' => $entry['bytes'], 'ms' => $entry['ms'], 'reason' => $entry['reason'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup.
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One cell of the matrix.
	 *
	 * @param array|null $entry Format data.
	 * @return string Already-escaped markup.
	 */
	protected static function cell( $entry ) {
		if ( ! is_array( $entry ) ) {
			return '<span class="mm-opt-na">&mdash;</span>';
		}

		if ( ! empty( $entry['verified'] ) ) {
			$title = sprintf(
				/* translators: 1: bytes produced, 2: milliseconds taken. */
				__( 'Test passed: %1$d bytes in %2$s ms.', 'mm-optimizer' ),
				(int) $entry['bytes'],
				(string) $entry['ms']
			);

			return '<span class="mm-opt-ok" title="' . esc_attr( $title ) . '">&#10003; ' . esc_html__( 'verified', 'mm-optimizer' ) . '</span>';
		}

		if ( ! empty( $entry['claimed'] ) ) {
			$title = self::reason_text( isset( $entry['reason'] ) ? $entry['reason'] : '' );

			return '<span class="mm-opt-warn-cell" title="' . esc_attr( $title ) . '">~ ' . esc_html__( 'claimed', 'mm-optimizer' ) . '</span>';
		}

		return '<span class="mm-opt-no">&#10007;</span>';
	}

	/**
	 * Translates a failure code.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	protected static function reason_text( $reason ) {
		$map = array(
			'not_claimed'       => __( 'This library does not claim the format.', 'mm-optimizer' ),
			'encode_failed'     => __( 'The encoding call failed.', 'mm-optimizer' ),
			'no_output'         => __( 'No file was produced.', 'mm-optimizer' ),
			'empty_output'      => __( 'The file produced was empty.', 'mm-optimizer' ),
			'wrong_format'      => __( 'The file produced is not in the requested format.', 'mm-optimizer' ),
			'not_compressed'    => __( 'The file produced is too large: the library is not compressing.', 'mm-optimizer' ),
			'decode_failed'     => __( 'The file produced cannot be read back.', 'mm-optimizer' ),
			'exception'         => __( 'The library threw an exception.', 'mm-optimizer' ),
			'sample_missing'    => __( 'Test sample not found.', 'mm-optimizer' ),
			'tmp_unwritable'    => __( 'The temporary directory is not writable.', 'mm-optimizer' ),
			'not_found'         => __( 'The binary is not present on this server.', 'mm-optimizer' ),
			'exec_unavailable'  => __( 'PHP execution functions are disabled.', 'mm-optimizer' ),
			'managed_host_skip' => __( 'Managed host: binary discovery was skipped.', 'mm-optimizer' ),
			'no_reduction'      => __( 'The binary did not shrink the test sample.', 'mm-optimizer' ),
			'probe_deadline'    => __( 'The detection time limit was reached.', 'mm-optimizer' ),
			'not_probed'        => __( 'Not tested.', 'mm-optimizer' ),
		);

		return isset( $map[ $reason ] ) ? $map[ $reason ] : $reason;
	}

	/**
	 * Engine comparison against the photographic sample.
	 *
	 * @param array $caps Capabilities.
	 * @return void
	 */
	protected static function render_bench( array $caps ) {
		if ( empty( $caps['bench'] ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Engine comparison', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Real measurements against a 512x384 test photograph. The tiny sample used for verification is not enough to establish which engine compresses best: at that size the differences between engines disappear.', 'mm-optimizer' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Format', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Engine', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Outcome', 'mm-optimizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $caps['bench'] as $mime => $engines ) {
			$chosen = isset( $caps['encoders'][ $mime ]['engine'] ) ? $caps['encoders'][ $mime ]['engine'] : '';
			$label  = 'image/avif' === $mime ? 'AVIF' : 'WebP';

			foreach ( $engines as $engine => $measure ) {
				if ( empty( $measure['bytes'] ) ) {
					continue;
				}

				echo '<tr>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td><code>' . esc_html( $engine ) . '</code></td>';
				echo '<td>' . esc_html( size_format( (int) $measure['bytes'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $measure['ms'], 1 ) ) . ' ms</td>';
				echo '<td>' . ( $engine === $chosen
					? '<strong>' . esc_html__( 'chosen', 'mm-optimizer' ) . '</strong>'
					: esc_html__( 'alternative', 'mm-optimizer' ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Recommended configuration with its reasons.
	 *
	 * @param array $analysis Analysis.
	 * @return void
	 */
	protected static function render_recommendation( array $analysis ) {
		echo '<h2>' . esc_html__( 'Recommended configuration', 'mm-optimizer' ) . '</h2>';

		if ( ! empty( $analysis['blockers'] ) ) {
			echo '<div class="mm-opt-blockers">';
			echo '<h3>' . esc_html__( 'To resolve', 'mm-optimizer' ) . '</h3><ul>';
			foreach ( $analysis['blockers'] as $blocker ) {
				echo '<li class="mm-opt-level-' . esc_attr( $blocker['level'] ) . '">' . esc_html( $blocker['text'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( ! empty( $analysis['reasons'] ) ) {
			echo '<ul class="mm-opt-reasons">';
			foreach ( $analysis['reasons'] as $reason ) {
				echo '<li class="mm-opt-level-' . esc_attr( $reason['level'] ) . '">' . esc_html( $reason['text'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Environment data, at the bottom.
	 *
	 * @param array $caps Capabilities.
	 * @return void
	 */
	protected static function render_environment( array $caps ) {
		$env = $caps['env'];

		$rows = array(
			__( 'PHP version', 'mm-optimizer' )              => $env['php'],
			__( 'Operating system', 'mm-optimizer' )         => $env['os'],
			__( 'Memory limit', 'mm-optimizer' )             => $env['memory_limit'] > 0 ? size_format( $env['memory_limit'] ) : __( 'unlimited', 'mm-optimizer' ),
			__( 'Maximum execution time', 'mm-optimizer' )   => $env['max_execution_time'] > 0 ? $env['max_execution_time'] . ' s' : __( 'unlimited', 'mm-optimizer' ),
			__( 'Command execution', 'mm-optimizer' )        => $env['exec_available'] ? __( 'available', 'mm-optimizer' ) : __( 'unavailable', 'mm-optimizer' ),
			__( 'Managed host', 'mm-optimizer' )             => '' !== $env['managed_host'] ? $env['managed_host'] : __( 'no', 'mm-optimizer' ),
			__( 'open_basedir', 'mm-optimizer' )             => '' !== $env['open_basedir'] ? $env['open_basedir'] : __( 'not set', 'mm-optimizer' ),
			__( 'Uploads directory writable', 'mm-optimizer' ) => $env['uploads_writable'] ? __( 'yes', 'mm-optimizer' ) : __( 'no', 'mm-optimizer' ),
			__( 'PHP file uploads', 'mm-optimizer' )         => $env['file_uploads'] ? __( 'enabled', 'mm-optimizer' ) : __( 'DISABLED', 'mm-optimizer' ),
			__( 'MIME type for .avif', 'mm-optimizer' )      => self::mime_text( $caps['delivery']['avif_mime_registered'] ),
			__( '.htaccess writable', 'mm-optimizer' )       => $caps['delivery']['htaccess_writable'] ? __( 'yes', 'mm-optimizer' ) : __( 'no', 'mm-optimizer' ),
		);

		echo '<h2>' . esc_html__( 'Environment', 'mm-optimizer' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Describes the outcome of the MIME probe.
	 *
	 * @param bool|null $registered Outcome.
	 * @return string
	 */
	protected static function mime_text( $registered ) {
		if ( null === $registered ) {
			return __( 'could not be checked', 'mm-optimizer' );
		}

		return $registered
			? __( 'correct', 'mm-optimizer' )
			: __( 'WRONG: the server does not know image/avif', 'mm-optimizer' );
	}
}
