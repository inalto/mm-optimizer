<?php
/**
 * Lossless optimization of WordPress-generated thumbnails.
 *
 * Only thumbnails. The original file and WordPress's scaled version are never
 * touched: they are the only intact copy left, and rewriting them would make
 * any mistake irreversible.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Thumbnails {

	/**
	 * Optimizes a file, when the conditions allow it.
	 *
	 * @param string $path          Absolute path.
	 * @param string $size_name     Size name.
	 * @param int    $attachment_id Attachment.
	 * @return array {
	 *     @type bool   $ok     True when the file was made smaller.
	 *     @type int    $before Bytes before.
	 *     @type int    $after  Bytes after.
	 *     @type string $reason Code when nothing was done.
	 * }
	 */
	public static function optimize( $path, $size_name = '', $attachment_id = 0 ) {
		$path = wp_normalize_path( (string) $path );

		if ( Conflicts::blocks( 'thumbnails' ) ) {
			return self::result( false, 0, 0, 'conflict' );
		}

		if ( ! self::is_thumbnail( $path, $size_name, $attachment_id ) ) {
			return self::result( false, 0, 0, 'not_a_thumbnail' );
		}

		if ( ! @is_file( $path ) || ! wp_is_writable( $path ) ) {
			return self::result( false, 0, 0, 'unwritable' );
		}

		$mime = Base::sniff_mime( $path );
		$key  = str_replace( 'image/', '', $mime );

		if ( ! Options::get( 'optimize.' . $key ) ) {
			return self::result( false, 0, 0, 'disabled' );
		}

		$caps   = Capabilities::get();
		$engine = isset( $caps['lossless'][ $mime ]['engine'] ) ? $caps['lossless'][ $mime ]['engine'] : '';

		if ( '' === $engine || empty( $caps['lossless'][ $mime ]['available'] ) ) {
			return self::result( false, 0, 0, 'no_tool' );
		}

		$before = (int) filesize( $path );

		// Work on a copy: the live file is replaced only once the result is
		// valid and smaller.
		$temp = trailingslashit( Base::tmp_dir() ) . 'opt-' . wp_generate_password( 10, false ) . '.' . pathinfo( $path, PATHINFO_EXTENSION );

		if ( ! @copy( $path, $temp ) ) {
			return self::result( false, $before, $before, 'copy_failed' );
		}

		$ok = self::run_engine( $engine, $temp, $mime );

		if ( ! $ok || ! @is_file( $temp ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'engine_failed' );
		}

		$after = (int) filesize( $temp );

		// The format has to be unchanged and the file has to be readable: an
		// optimizer that gets it wrong must not be able to replace a valid
		// thumbnail with a broken file.
		if ( Base::sniff_mime( $temp ) !== $mime || ! @getimagesize( $temp ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'invalid_output' );
		}

		if ( $after >= $before || $after < 1 ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'not_smaller' );
		}

		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'replace_failed' );
		}

		Base::inherit_permissions( $path );

		return self::result( true, $before, $after, '' );
	}

	/**
	 * True only for a file WordPress generated.
	 *
	 * The check is against the attachment metadata, not the filename: an
	 * original called "photo-800x600.jpg" must not be mistaken for a thumbnail.
	 *
	 * @param string $path          Path.
	 * @param string $size_name     Size name.
	 * @param int    $attachment_id Attachment.
	 * @return bool
	 */
	public static function is_thumbnail( $path, $size_name = '', $attachment_id = 0 ) {
		if ( 'full' === $size_name || 'original' === $size_name ) {
			return false;
		}

		if ( $attachment_id > 0 ) {
			$original = get_attached_file( $attachment_id );

			if ( $original && wp_normalize_path( $original ) === $path ) {
				return false;
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( ! empty( $metadata['original_image'] ) ) {
				$dir = trailingslashit( dirname( $path ) );

				if ( $dir . $metadata['original_image'] === $path ) {
					return false;
				}
			}

			if ( empty( $metadata['sizes'] ) ) {
				return false;
			}

			$dir   = trailingslashit( dirname( $path ) );
			$known = false;

			foreach ( (array) $metadata['sizes'] as $data ) {
				if ( ! empty( $data['file'] ) && $dir . $data['file'] === $path ) {
					$known = true;
					break;
				}
			}

			return $known;
		}

		// Without an attachment there is no way to be sure this is a
		// thumbnail, so nothing is touched.
		return false;
	}

	/**
	 * Runs the selected optimizer.
	 *
	 * @param string $engine Engine.
	 * @param string $path   Path of the working copy.
	 * @param string $mime   MIME type.
	 * @return bool
	 */
	protected static function run_engine( $engine, $path, $mime ) {
		$caps = Capabilities::get();

		switch ( $engine ) {
			case 'gd':
				// PNG only: GD recompresses the pixels without altering them
				// and drops the metadata. Re-encoding a JPEG with GD would be
				// lossy, so it is not offered.
				if ( 'image/png' !== $mime ) {
					return false;
				}

				$image = @imagecreatefrompng( $path );

				if ( ! $image ) {
					return false;
				}

				imagealphablending( $image, false );
				imagesavealpha( $image, true );

				$ok = @imagepng( $image, $path, 9, PNG_ALL_FILTERS );

				imagedestroy( $image );

				return (bool) $ok;

			case 'jpegoptim':
				Capabilities::run_command( array( $caps['binaries']['jpegoptim']['path'], '--strip-all', '--quiet', $path ), 60 );
				return true;

			case 'jpegtran':
				$out = $path . '.out';
				Capabilities::run_command( array( $caps['binaries']['jpegtran']['path'], '-copy', 'none', '-optimize', '-progressive', '-outfile', $out, $path ), 60 );
				if ( @is_file( $out ) ) {
					return (bool) @rename( $out, $path );
				}
				return false;

			case 'optipng':
				Capabilities::run_command( array( $caps['binaries']['optipng']['path'], '-quiet', '-o2', '-strip', 'all', $path ), 90 );
				return true;

			case 'gifsicle':
				Capabilities::run_command( array( $caps['binaries']['gifsicle']['path'], '-O3', '-b', $path ), 60 );
				return true;
		}

		return false;
	}

	/**
	 * Builds a result.
	 *
	 * @param bool   $ok     Result.
	 * @param int    $before Bytes before.
	 * @param int    $after  Bytes after.
	 * @param string $reason Code.
	 * @return array
	 */
	protected static function result( $ok, $before, $after, $reason ) {
		return array(
			'ok'     => (bool) $ok,
			'before' => (int) $before,
			'after'  => (int) $after,
			'saved'  => max( 0, (int) $before - (int) $after ),
			'reason' => (string) $reason,
		);
	}
}
