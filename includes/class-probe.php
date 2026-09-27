<?php
/**
 * Functional ("blind") test of the encoders.
 *
 * Declared support lies: Imagick lists WEBP in queryFormats() even when the
 * delegate is broken, and a cwebp on the PATH can be a placeholder script.
 * The only trustworthy check is to actually encode a known sample and
 * inspect the result byte by byte.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Probe {

	/**
	 * Largest acceptable size for the encoded sample.
	 *
	 * The sample is 64x64 RGBA. Measured with GD 2.3.3: WebP q82 = 1,018 B,
	 * AVIF q58 = 1,081 B, PNG z9 = 840 B, JPEG q82 = 1,930 B. The ceilings are
	 * roughly three times the measurement: an encoder that hands back raw
	 * pixels inside a container always exceeds them.
	 *
	 * @var array
	 */
	const CEILINGS = array(
		'image/webp' => 3000,
		'image/avif' => 3500,
		'image/png'  => 3000,
		'image/jpeg' => 5000,
		'image/gif'  => 6000,
	);

	/**
	 * Path to the RGBA sample.
	 *
	 * @return string
	 */
	public static function sample_png() {
		return MM_OPTIMIZER_DIR . 'assets/probe/probe.png';
	}

	/**
	 * Path to the photographic sample used for ranking.
	 *
	 * The 64x64 sample proves that an encoder works, not which one is best: on
	 * an image that small, cwebp -m 6 comes out two bytes larger than -m 4,
	 * while on a real photograph it is about 5% smaller. Ranking belongs here.
	 *
	 * @return string
	 */
	public static function sample_bench() {
		return MM_OPTIMIZER_DIR . 'assets/probe/bench.jpg';
	}

	/**
	 * Path to the JPEG sample padded with junk metadata.
	 *
	 * @return string
	 */
	public static function sample_bloated_jpeg() {
		return MM_OPTIMIZER_DIR . 'assets/probe/probe-bloated.jpg';
	}

	/**
	 * Runs a full test against an encoder.
	 *
	 * @param callable $encoder     Receives (string $source, string $dest) and
	 *                              returns bool.
	 * @param string   $target_mime Expected output MIME type.
	 * @param string   $source      Sample to use; empty for the RGBA one.
	 * @return array {
	 *     @type bool   $verified Overall result.
	 *     @type int    $bytes    Size produced.
	 *     @type float  $ms       Milliseconds taken.
	 *     @type string $reason   Failure code, when present.
	 * }
	 */
	public static function run( callable $encoder, $target_mime, $source = '' ) {
		$result = array(
			'verified' => false,
			'bytes'    => 0,
			'ms'       => 0.0,
			'reason'   => '',
		);

		$source = '' !== $source ? $source : self::sample_png();

		if ( ! @is_readable( $source ) ) {
			$result['reason'] = 'sample_missing';
			return $result;
		}

		$dest = self::temp_path( $target_mime );
		if ( '' === $dest ) {
			$result['reason'] = 'tmp_unwritable';
			return $result;
		}

		$start = microtime( true );

		try {
			$ok = (bool) call_user_func( $encoder, $source, $dest );
		} catch ( \Throwable $e ) {
			$ok               = false;
			$result['reason'] = 'exception';
		}

		$result['ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );

		if ( ! $ok ) {
			$result['reason'] = '' !== $result['reason'] ? $result['reason'] : 'encode_failed';
			self::cleanup( $dest );
			return $result;
		}

		if ( ! @is_file( $dest ) ) {
			$result['reason'] = 'no_output';
			return $result;
		}

		$bytes           = (int) @filesize( $dest );
		$result['bytes'] = $bytes;

		if ( $bytes < 1 ) {
			$result['reason'] = 'empty_output';
			self::cleanup( $dest );
			return $result;
		}

		// Magic bytes, never the extension: a broken encoder can still write a
		// file with the right name.
		if ( Base::sniff_mime( $dest ) !== $target_mime ) {
			$result['reason'] = 'wrong_format';
			self::cleanup( $dest );
			return $result;
		}

		$ceiling = isset( self::CEILINGS[ $target_mime ] ) ? self::CEILINGS[ $target_mime ] : 8000;
		if ( $bytes > $ceiling ) {
			$result['reason'] = 'not_compressed';
			self::cleanup( $dest );
			return $result;
		}

		// Read back: this also proves the decode side, which the
		// "thumbnail from the original" path depends on.
		if ( ! self::decodes( $dest, $target_mime ) ) {
			$result['reason'] = 'decode_failed';
			self::cleanup( $dest );
			return $result;
		}

		self::cleanup( $dest );

		$result['verified'] = true;

		return $result;
	}

	/**
	 * Measures an encoder against a photographic sample, for ranking.
	 *
	 * No thresholds and no format check: only bytes and milliseconds matter
	 * here, and correctness has already been established by run().
	 *
	 * @param callable $encoder     Receives (string $source, string $dest).
	 * @param string   $target_mime Output MIME type.
	 * @return array {
	 *     @type int   $bytes Size produced; zero on failure.
	 *     @type float $ms    Milliseconds taken.
	 * }
	 */
	public static function bench( callable $encoder, $target_mime ) {
		$result = array( 'bytes' => 0, 'ms' => 0.0 );

		$source = self::sample_bench();

		if ( ! @is_readable( $source ) ) {
			return $result;
		}

		$dest = self::temp_path( $target_mime );
		if ( '' === $dest ) {
			return $result;
		}

		$start = microtime( true );

		try {
			call_user_func( $encoder, $source, $dest );
		} catch ( \Throwable $e ) {
			self::cleanup( $dest );
			return $result;
		}

		$result['ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );

		if ( @is_file( $dest ) && Base::sniff_mime( $dest ) === $target_mime ) {
			$result['bytes'] = (int) filesize( $dest );
		}

		self::cleanup( $dest );

		return $result;
	}

	/**
	 * Verifies that the produced file can be read back.
	 *
	 * @param string $path        Path.
	 * @param string $target_mime MIME type.
	 * @return bool
	 */
	protected static function decodes( $path, $target_mime ) {
		$map = array(
			'image/webp' => 'imagecreatefromwebp',
			'image/avif' => 'imagecreatefromavif',
			'image/png'  => 'imagecreatefrompng',
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/gif'  => 'imagecreatefromgif',
		);

		if ( ! isset( $map[ $target_mime ] ) || ! Base::function_exists( $map[ $target_mime ] ) ) {
			// Without the matching reader, the magic bytes are as far as we go.
			return true;
		}

		$image = @call_user_func( $map[ $target_mime ], $path );

		if ( ! $image ) {
			return false;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		imagedestroy( $image );

		return $width > 0 && $height > 0;
	}

	/**
	 * Temporary path inside uploads.
	 *
	 * @param string $target_mime MIME type.
	 * @return string
	 */
	protected static function temp_path( $target_mime ) {
		$dir = Base::tmp_dir();

		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return '';
		}

		$ext = 'bin';
		if ( preg_match( '#^image/([a-z0-9]+)$#', $target_mime, $m ) ) {
			$ext = $m[1];
		}

		return trailingslashit( $dir ) . 'probe-' . wp_generate_password( 8, false ) . '.' . $ext;
	}

	/**
	 * Removes a temporary file.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	protected static function cleanup( $path ) {
		if ( '' !== $path && @is_file( $path ) ) {
			@unlink( $path );
		}
	}
}
