<?php
/**
 * From detected capabilities to recommended settings.
 *
 * Every proposal carries the reason that produced it: without an explanation,
 * a recommendation is just another default.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Recommender {

	/**
	 * Analyses the capabilities and proposes a configuration.
	 *
	 * @param array|null $caps Capabilities; read when absent.
	 * @return array {
	 *     @type string $level    Recommended level.
	 *     @type array  $settings Partial set of options.
	 *     @type array  $reasons  Reasons, each with a code and a level.
	 *     @type array  $blockers Obstacles that limit the proposal.
	 *     @type int    $score    Host suitability index, 0-100.
	 * }
	 */
	public static function analyze( $caps = null ) {
		$caps = is_array( $caps ) ? $caps : Capabilities::get();
		$caps = $caps + Capabilities::blank();

		$reasons  = array();
		$blockers = array();
		$settings = array();

		$webp_engine = isset( $caps['encoders']['image/webp']['engine'] ) ? $caps['encoders']['image/webp']['engine'] : '';
		$avif_engine = isset( $caps['encoders']['image/avif']['engine'] ) ? $caps['encoders']['image/avif']['engine'] : '';

		// R1 - output formats.
		$settings['webp'] = array( 'enabled' => $webp_engine ? 1 : 0, 'engine' => 'auto', 'deliver' => $webp_engine ? 1 : 0 );
		$settings['avif'] = array( 'enabled' => $avif_engine ? 1 : 0, 'engine' => 'auto', 'deliver' => $avif_engine ? 1 : 0 );

		if ( ! $webp_engine && ! $avif_engine ) {
			$blockers[] = self::reason(
				'no_modern_encoder',
				'error',
				__( 'This server can produce neither WebP nor AVIF: no graphics library passed the functional test. Ask your host to enable GD with WebP and AVIF support, or the Imagick extension.', 'mm-optimizer' )
			);
		}

		// R2 - engine per format, decided by measurement rather than preference.
		foreach ( array( 'image/webp' => 'WebP', 'image/avif' => 'AVIF' ) as $mime => $label ) {
			$entry = isset( $caps['encoders'][ $mime ] ) ? $caps['encoders'][ $mime ] : array();

			if ( empty( $entry['engine'] ) ) {
				continue;
			}

			$reasons[] = self::reason(
				'engine_selected',
				'good',
				sprintf(
					/* translators: 1: image format, 2: engine name, 3: bytes produced, 4: explanation of the choice. */
					__( '%1$s: chose %2$s (%3$s on the test sample) — %4$s', 'mm-optimizer' ),
					$label,
					$entry['engine'],
					size_format( (int) $entry['bytes'] ),
					self::why_text( $entry['why'], $entry['alternatives'] )
				)
			);
		}

		if ( ! $avif_engine && $webp_engine ) {
			$reasons[] = self::reason(
				'avif_unavailable',
				'warning',
				__( 'AVIF is unavailable: no encoder on this server supports it. It is the format that pays off most (around 67% smaller against WebP\'s 45%), so it is worth asking your host for a PHP build with AVIF-enabled GD, or the avifenc package.', 'mm-optimizer' )
			);
		}

		// R3 - level, according to the resources available.
		$memory   = isset( $caps['env']['memory_limit'] ) ? (int) $caps['env']['memory_limit'] : 0;
		$max_exec = isset( $caps['env']['max_execution_time'] ) ? (int) $caps['env']['max_execution_time'] : 0;

		$tight = ( $memory > 0 && $memory < 134217728 ) || ( $max_exec > 0 && $max_exec < 60 );

		$settings['level'] = $tight ? 'conservative' : 'balanced';

		if ( $tight ) {
			$reasons[] = self::reason(
				'level_conservative',
				'warning',
				sprintf(
					/* translators: 1: memory limit, 2: maximum execution time in seconds. */
					__( 'Conservative level: resources are tight (memory %1$s, time limit %2$d s). Very large images will be skipped rather than allowed to fail the request.', 'mm-optimizer' ),
					$memory > 0 ? size_format( $memory ) : __( 'not declared', 'mm-optimizer' ),
					$max_exec
				)
			);
		} else {
			$reasons[] = self::reason(
				'level_balanced',
				'good',
				__( 'Balanced level: resources are sufficient. Around 45% smaller with WebP and 66% smaller with AVIF, with no visible difference on photographs.', 'mm-optimizer' )
			);
		}

		// R5 - lossless thumbnail optimization.
		$settings['optimize'] = array(
			'jpeg' => ! empty( $caps['lossless']['image/jpeg']['available'] ) ? 1 : 0,
			'png'  => ! empty( $caps['lossless']['image/png']['available'] ) ? 1 : 0,
			'gif'  => ! empty( $caps['lossless']['image/gif']['available'] ) ? 1 : 0,
		);

		if ( empty( $caps['lossless']['image/jpeg']['available'] ) ) {
			$reasons[] = self::reason(
				'no_jpeg_lossless_tool',
				'warning',
				__( 'Lossless JPEG optimization is unavailable: no PHP library can recompress a JPEG without degrading it, which takes a dedicated binary. Ask your host for the jpegoptim package. In the meantime all the gain comes from the WebP and AVIF derivatives.', 'mm-optimizer' )
			);
		}

		if ( Conflicts::blocks( 'thumbnails' ) ) {
			$settings['optimize'] = array( 'jpeg' => 0, 'png' => 0, 'gif' => 0 );
			$blockers[]           = self::reason(
				'thumbnails_conflict',
				'warning',
				__( 'Thumbnail optimization is switched off: EWWW Image Optimizer is active and rewrites the same files. Two optimizers working on one file is a real corruption risk.', 'mm-optimizer' )
			);
		}

		// R6 - delivery mode. With a competitor active the mode is still set
		// to <picture>: Conflicts::blocks() holds it back while the other
		// plugin runs, and it arms itself once that plugin is gone. Saving
		// "off" here would leave it off for good.
		$conflicts            = Conflicts::active();
		$settings['delivery'] = array( 'mode' => 'picture' );

		if ( ! empty( $conflicts ) ) {
			$blockers[] = self::reason(
				'conflict_active',
				'warning',
				sprintf(
					/* translators: %s: list of conflicting plugins. */
					__( 'Delivery is held back: %s already handles this site\'s images. Conversion stays on, so the derivatives are ready: delivery will arm itself once you deactivate the other plugin.', 'mm-optimizer' ),
					implode( ', ', $conflicts )
				)
			);
		} else {
			$reasons[] = self::reason(
				'delivery_picture',
				'good',
				__( 'Delivery via <picture>: the markup carries every variant and the browser chooses. Unlike .htaccess negotiation it needs no Vary: Accept and leaves page caches and CDNs alone.', 'mm-optimizer' )
			);
		}

		// The .avif MIME type: without it, in <picture> mode Firefox and Safari
		// fail with no fallback to the <img>.
		$mime_ok = isset( $caps['delivery']['avif_mime_registered'] ) ? $caps['delivery']['avif_mime_registered'] : null;

		if ( $avif_engine && false === $mime_ok ) {
			if ( ! empty( $caps['delivery']['htaccess_writable'] ) ) {
				$settings['delivery']['addtype'] = 1;
				$reasons[]                       = self::reason(
					'avif_mime_addtype',
					'warning',
					__( 'This server delivers .avif files with the wrong MIME type (it is missing from /etc/mime.types). An AddType line will be written into .htaccess: without it, Firefox and Safari pick the AVIF source and then fail to decode it, with no fallback to the original image.', 'mm-optimizer' )
				);
			} else {
				$settings['avif']['deliver'] = 0;
				$blockers[]                  = self::reason(
					'avif_mime_blocked',
					'error',
					__( 'AVIF is generated but not delivered: the server returns it with the wrong MIME type and .htaccess is not writable. Delivering a source the browser picks and then cannot decode is worse than not delivering it. Add "AddType image/avif .avif" to the server configuration to enable it.', 'mm-optimizer' )
				);
			}
		}

		// R7 - which sizes to convert.
		$subsizes = function_exists( 'wp_get_registered_image_subsizes' ) ? count( wp_get_registered_image_subsizes() ) : 0;

		$settings['sizes'] = array( 'mode' => $subsizes > 10 ? 'srcset' : 'all' );

		if ( $subsizes > 10 ) {
			$reasons[] = self::reason(
				'sizes_srcset',
				'good',
				sprintf(
					/* translators: %d: number of registered image sizes. */
					_n(
						'%d image size is registered: only the ones that actually appear in a srcset will be converted.',
						'%d image sizes are registered: converting them all into two formats would multiply the work for no benefit, so only the ones that actually appear in a srcset will be converted.',
						$subsizes,
						'mm-optimizer'
					),
					$subsizes
				)
			);
		}

		// R8 - batch size and queue driver.
		$settings['bulk'] = array(
			'driver' => '' !== $caps['env']['managed_host'] ? 'cron' : 'auto',
			'batch'  => $tight ? 2 : 5,
		);

		if ( '' !== $caps['env']['managed_host'] ) {
			$reasons[] = self::reason(
				'managed_host',
				'info',
				sprintf(
					/* translators: %s: name of the managed host. */
					__( 'Managed host detected (%s): binary discovery was skipped because they would not be executable, and the queue will use cron rather than loopback requests.', 'mm-optimizer' ),
					$caps['env']['managed_host']
				)
			);
		}

		// Environment warnings that do not change the proposal but need saying.
		if ( empty( $caps['env']['file_uploads'] ) ) {
			$blockers[] = self::reason(
				'file_uploads_off',
				'error',
				__( 'The PHP file_uploads directive is disabled: no Media Library upload can work, with or without this plugin. Bulk conversion of the images already present works regardless.', 'mm-optimizer' )
			);
		}

		if ( empty( $caps['env']['uploads_writable'] ) ) {
			$blockers[] = self::reason(
				'uploads_unwritable',
				'error',
				__( 'The uploads directory is not writable: no derivative can be created.', 'mm-optimizer' )
			);
		}

		foreach ( (array) $caps['warnings'] as $warning ) {
			if ( 0 === strpos( $warning, 'claimed_not_verified:' ) ) {
				$parts     = explode( ':', $warning );
				$blockers[] = self::reason(
					'claimed_not_verified',
					'error',
					sprintf(
						/* translators: 1: library name, 2: MIME type. */
						__( '%1$s claims to support %2$s but the encoding test failed. The format stays off on purpose: this is exactly the situation that produces silently corrupted files.', 'mm-optimizer' ),
						$parts[1],
						$parts[2]
					)
				);
			}
		}

		return array(
			'level'    => $settings['level'],
			'settings' => $settings,
			'reasons'  => $reasons,
			'blockers' => $blockers,
			'score'    => self::score( $caps ),
		);
	}

	/**
	 * Applies the proposal, going through option sanitization.
	 *
	 * @param array|null $caps Capabilities.
	 * @return array Resulting options.
	 */
	public static function apply( $caps = null ) {
		$analysis = self::analyze( $caps );

		Log::add( __( 'Recommended configuration applied.', 'mm-optimizer' ), 'info', array( 'level' => $analysis['level'] ) );

		return Options::apply_partial( $analysis['settings'] );
	}

	/**
	 * Single suitability index for the host.
	 *
	 * @param array $caps Capabilities.
	 * @return int 0-100.
	 */
	protected static function score( array $caps ) {
		$score = 0;

		if ( ! empty( $caps['encoders']['image/webp']['engine'] ) ) {
			$score += 35;
		}
		if ( ! empty( $caps['encoders']['image/avif']['engine'] ) ) {
			$score += 35;
		}
		if ( ! empty( $caps['lossless']['image/jpeg']['available'] ) ) {
			$score += 10;
		}
		if ( ! empty( $caps['env']['uploads_writable'] ) ) {
			$score += 10;
		}

		$mime_ok = isset( $caps['delivery']['avif_mime_registered'] ) ? $caps['delivery']['avif_mime_registered'] : null;

		if ( false !== $mime_ok || ! empty( $caps['delivery']['htaccess_writable'] ) ) {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Text explaining why an engine was chosen.
	 *
	 * @param string $why          Code.
	 * @param array  $alternatives Engines that lost.
	 * @return string
	 */
	protected static function why_text( $why, array $alternatives ) {
		switch ( $why ) {
			case 'only_candidate':
				return __( 'the only encoder available.', 'mm-optimizer' );

			case 'tie_on_bytes_faster':
				return sprintf(
					/* translators: %s: list of engines that lost. */
					__( 'tied on bytes with %s, but faster.', 'mm-optimizer' ),
					implode( ', ', $alternatives )
				);

			case 'smallest_output':
				return sprintf(
					/* translators: %s: list of engines that lost. */
					__( 'produced the smallest file, beating %s.', 'mm-optimizer' ),
					implode( ', ', $alternatives )
				);
		}

		return '';
	}

	/**
	 * Builds a reason.
	 *
	 * @param string $code  Symbolic code.
	 * @param string $level 'good', 'info', 'warning' or 'error'.
	 * @param string $text  Already-translated text.
	 * @return array
	 */
	protected static function reason( $code, $level, $text ) {
		return array(
			'code'  => $code,
			'level' => $level,
			'text'  => $text,
		);
	}
}
