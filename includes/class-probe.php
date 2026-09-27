<?php
/**
 * Prova funzionale ("blind test") dei codificatori.
 *
 * Il supporto dichiarato mente: Imagick elenca WEBP in queryFormats() anche
 * quando il delegato e' rotto, e un cwebp nel PATH puo' essere uno script
 * segnaposto. L'unica verifica attendibile e' codificare davvero un campione
 * noto e controllare il risultato byte per byte.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Probe {

	/**
	 * Dimensione massima accettabile per il campione codificato.
	 *
	 * Il campione e' 64x64 RGBA. Misurato con GD 2.3.3: WebP q82 = 1.018 B,
	 * AVIF q58 = 1.081 B, PNG z9 = 840 B, JPEG q82 = 1.930 B. I tetti sono
	 * circa tre volte la misura: un codificatore che restituisce i pixel
	 * grezzi dentro un contenitore li supera sempre.
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
	 * Percorso del campione RGBA.
	 *
	 * @return string
	 */
	public static function sample_png() {
		return MM_OPTIMIZER_DIR . 'assets/probe/probe.png';
	}

	/**
	 * Percorso del campione fotografico usato per la classifica.
	 *
	 * Il campione 64x64 serve a verificare che un codificatore funzioni, non a
	 * stabilire quale sia il migliore: su un'immagine cosi' piccola cwebp -m 6
	 * risulta perfino due byte piu' grande di -m 4, mentre su una fotografia
	 * reale e' circa il 5% piu' piccolo. La classifica va fatta su questo.
	 *
	 * @return string
	 */
	public static function sample_bench() {
		return MM_OPTIMIZER_DIR . 'assets/probe/bench.jpg';
	}

	/**
	 * Percorso del campione JPEG con metadati superflui.
	 *
	 * @return string
	 */
	public static function sample_bloated_jpeg() {
		return MM_OPTIMIZER_DIR . 'assets/probe/probe-bloated.jpg';
	}

	/**
	 * Esegue una prova completa su un codificatore.
	 *
	 * @param callable $encoder     Riceve (string $source, string $dest) e
	 *                              restituisce bool.
	 * @param string   $target_mime Tipo MIME atteso in uscita.
	 * @param string   $source      Campione da usare; vuoto per quello RGBA.
	 * @return array {
	 *     @type bool   $verified Esito complessivo.
	 *     @type int    $bytes    Dimensione prodotta.
	 *     @type float  $ms       Millisecondi impiegati.
	 *     @type string $reason   Codice di fallimento, se presente.
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

		// I magic bytes, mai l'estensione: un encoder rotto sa comunque
		// scrivere un file con il nome giusto.
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

		// Riletura: verifica anche il lato decodifica, che serve al percorso
		// "miniatura generata dall'originale".
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
	 * Misura un codificatore su un campione fotografico, per la classifica.
	 *
	 * Nessuna soglia e nessuna verifica di formato: qui interessano solo byte
	 * e millisecondi, e la correttezza e' gia' stata accertata da run().
	 *
	 * @param callable $encoder     Riceve (string $source, string $dest).
	 * @param string   $target_mime Tipo MIME di uscita.
	 * @return array {
	 *     @type int   $bytes Dimensione prodotta; zero in caso di fallimento.
	 *     @type float $ms    Millisecondi impiegati.
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
	 * Verifica che il file prodotto sia rileggibile.
	 *
	 * @param string $path        Percorso.
	 * @param string $target_mime Tipo MIME.
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
			// Senza il lettore corrispondente ci si ferma ai magic bytes.
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
	 * Percorso temporaneo dentro gli upload.
	 *
	 * @param string $target_mime Tipo MIME.
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
	 * Rimuove un file temporaneo.
	 *
	 * @param string $path Percorso.
	 * @return void
	 */
	protected static function cleanup( $path ) {
		if ( '' !== $path && @is_file( $path ) ) {
			@unlink( $path );
		}
	}
}
