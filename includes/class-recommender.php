<?php
/**
 * Dalle capacita' rilevate alle impostazioni consigliate.
 *
 * Ogni proposta porta con se' la ragione che l'ha prodotta: senza spiegazione
 * un consiglio e' solo un altro valore predefinito.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Recommender {

	/**
	 * Analizza le capacita' e propone una configurazione.
	 *
	 * @param array|null $caps Capacita'; se assenti vengono lette.
	 * @return array {
	 *     @type string $level    Livello consigliato.
	 *     @type array  $settings Insieme parziale di opzioni.
	 *     @type array  $reasons  Motivazioni, ciascuna con codice e livello.
	 *     @type array  $blockers Impedimenti che limitano la proposta.
	 *     @type int    $score    Indice di idoneita' dell'host, 0-100.
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

		// R1 - formati di uscita.
		$settings['webp'] = array( 'enabled' => $webp_engine ? 1 : 0, 'engine' => 'auto', 'deliver' => $webp_engine ? 1 : 0 );
		$settings['avif'] = array( 'enabled' => $avif_engine ? 1 : 0, 'engine' => 'auto', 'deliver' => $avif_engine ? 1 : 0 );

		if ( ! $webp_engine && ! $avif_engine ) {
			$blockers[] = self::reason(
				'no_modern_encoder',
				'error',
				__( 'Questo server non sa produrre ne\' WebP ne\' AVIF: nessuna libreria grafica ha superato la prova funzionale. Chiedi all\'hosting di abilitare GD con supporto WebP e AVIF, oppure l\'estensione Imagick.', 'mm-optimizer' )
			);
		}

		// R2 - motore per formato, deciso dalla misura, non da una preferenza.
		foreach ( array( 'image/webp' => 'WebP', 'image/avif' => 'AVIF' ) as $mime => $label ) {
			$entry = isset( $caps['encoders'][ $mime ] ) ? $caps['encoders'][ $mime ] : array();

			if ( empty( $entry['engine'] ) ) {
				continue;
			}

			$reasons[] = self::reason(
				'engine_selected',
				'good',
				sprintf(
					/* translators: 1: formato immagine, 2: nome del motore, 3: byte prodotti, 4: spiegazione della scelta. */
					__( '%1$s: scelto %2$s (%3$s sul campione di prova) — %4$s', 'mm-optimizer' ),
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
				__( 'AVIF non disponibile: nessun codificatore lo supporta. E\' il formato che rende di piu\' (circa il 67% in meno contro il 45% del WebP), quindi vale la pena chiedere all\'hosting un PHP con GD compilato con AVIF, oppure il pacchetto avifenc.', 'mm-optimizer' )
			);
		}

		// R3 - livello, in base alle risorse disponibili.
		$memory   = isset( $caps['env']['memory_limit'] ) ? (int) $caps['env']['memory_limit'] : 0;
		$max_exec = isset( $caps['env']['max_execution_time'] ) ? (int) $caps['env']['max_execution_time'] : 0;

		$tight = ( $memory > 0 && $memory < 134217728 ) || ( $max_exec > 0 && $max_exec < 60 );

		$settings['level'] = $tight ? 'conservative' : 'balanced';

		if ( $tight ) {
			$reasons[] = self::reason(
				'level_conservative',
				'warning',
				sprintf(
					/* translators: 1: limite di memoria, 2: tempo massimo di esecuzione in secondi. */
					__( 'Livello Conservativo: le risorse sono strette (memoria %1$s, tempo massimo %2$d s). Le immagini molto grandi verranno saltate invece di far fallire la richiesta.', 'mm-optimizer' ),
					$memory > 0 ? size_format( $memory ) : __( 'non dichiarata', 'mm-optimizer' ),
					$max_exec
				)
			);
		} else {
			$reasons[] = self::reason(
				'level_balanced',
				'good',
				__( 'Livello Bilanciato: risorse sufficienti. Circa il 45% in meno con WebP e il 66% in meno con AVIF, senza differenze visibili sulle fotografie.', 'mm-optimizer' )
			);
		}

		// R5 - ottimizzazione senza perdita delle miniature.
		$settings['optimize'] = array(
			'jpeg' => ! empty( $caps['lossless']['image/jpeg']['available'] ) ? 1 : 0,
			'png'  => ! empty( $caps['lossless']['image/png']['available'] ) ? 1 : 0,
			'gif'  => ! empty( $caps['lossless']['image/gif']['available'] ) ? 1 : 0,
		);

		if ( empty( $caps['lossless']['image/jpeg']['available'] ) ) {
			$reasons[] = self::reason(
				'no_jpeg_lossless_tool',
				'warning',
				__( 'Ottimizzazione JPEG senza perdita non disponibile: nessuna libreria PHP sa ricomprimere un JPEG senza degradarlo, serve un binario dedicato. Chiedi all\'hosting il pacchetto jpegoptim. Nel frattempo il guadagno arriva tutto dalle derivate WebP e AVIF.', 'mm-optimizer' )
			);
		}

		if ( Conflicts::blocks( 'thumbnails' ) ) {
			$settings['optimize'] = array( 'jpeg' => 0, 'png' => 0, 'gif' => 0 );
			$blockers[]           = self::reason(
				'thumbnails_conflict',
				'warning',
				__( 'Ottimizzazione delle miniature disattivata: EWWW Image Optimizer e\' attivo e riscrive gli stessi file. Due ottimizzatori sullo stesso file sono un rischio concreto di corruzione.', 'mm-optimizer' )
			);
		}

		// R6 - modalita' di consegna.
		$conflicts = Conflicts::active();

		if ( ! empty( $conflicts ) ) {
			$settings['delivery'] = array( 'mode' => 'off' );
			$blockers[]           = self::reason(
				'conflict_active',
				'warning',
				sprintf(
					/* translators: %s: elenco dei plugin in conflitto. */
					__( 'Consegna disattivata: %s gestisce gia\' le immagini di questo sito. La conversione resta attiva, cosi\' le derivate sono pronte: la consegna si accendera\' da sola quando disattiverai l\'altro plugin.', 'mm-optimizer' ),
					implode( ', ', $conflicts )
				)
			);
		} else {
			$settings['delivery'] = array( 'mode' => 'picture' );
			$reasons[]            = self::reason(
				'delivery_picture',
				'good',
				__( 'Consegna con <picture>: il markup contiene tutte le varianti e sceglie il browser. A differenza della negoziazione via .htaccess non richiede Vary: Accept e non disturba la cache di pagina o la CDN.', 'mm-optimizer' )
			);
		}

		// Il MIME di .avif: senza, in modalita' <picture> Firefox e Safari
		// falliscono senza ripiegare sull'<img>.
		$avif_mime = isset( $caps['delivery']['avif_mime_registered'] ) ? $caps['delivery']['avif_mime_registered'] : null;

		if ( $avif_engine && false === $avif_mime ) {
			if ( ! empty( $caps['delivery']['htaccess_writable'] ) ) {
				$settings['delivery']['addtype'] = 1;
				$reasons[]                       = self::reason(
					'avif_mime_addtype',
					'warning',
					__( 'Questo server consegna i file .avif con il tipo MIME sbagliato (manca da /etc/mime.types). Verra\' scritta una riga AddType nel .htaccess: senza, Firefox e Safari scelgono la sorgente AVIF e poi non la decodificano, senza ripiegare sull\'immagine originale.', 'mm-optimizer' )
				);
			} else {
				$settings['avif']['deliver'] = 0;
				$blockers[]                  = self::reason(
					'avif_mime_blocked',
					'error',
					__( 'AVIF generato ma non consegnato: il server lo restituisce con il tipo MIME sbagliato e il .htaccess non e\' scrivibile. Consegnare una sorgente che il browser sceglie e poi non sa decodificare e\' peggio che non consegnarla. Aggiungi "AddType image/avif .avif" alla configurazione del server per abilitarla.', 'mm-optimizer' )
				);
			}
		}

		// R7 - quali dimensioni convertire.
		$subsizes = function_exists( 'wp_get_registered_image_subsizes' ) ? count( wp_get_registered_image_subsizes() ) : 0;

		$settings['sizes'] = array( 'mode' => $subsizes > 10 ? 'srcset' : 'all' );

		if ( $subsizes > 10 ) {
			$reasons[] = self::reason(
				'sizes_srcset',
				'good',
				sprintf(
					/* translators: %d: numero di dimensioni registrate. */
					_n(
						'Sono registrata %d dimensione immagine: verranno convertite solo quelle che compaiono davvero nel srcset.',
						'Sono registrate %d dimensioni immagine: convertirle tutte in due formati moltiplicherebbe il lavoro senza vantaggi, quindi verranno convertite solo quelle che compaiono davvero nel srcset.',
						$subsizes,
						'mm-optimizer'
					),
					$subsizes
				)
			);
		}

		// R8 - dimensione del lotto e motore di coda.
		$settings['bulk'] = array(
			'driver' => '' !== $caps['env']['managed_host'] ? 'cron' : 'auto',
			'batch'  => $tight ? 2 : 5,
		);

		if ( '' !== $caps['env']['managed_host'] ) {
			$reasons[] = self::reason(
				'managed_host',
				'info',
				sprintf(
					/* translators: %s: nome dell'hosting gestito. */
					__( 'Hosting gestito rilevato (%s): la ricerca dei binari e\' stata saltata perche\' non sarebbero eseguibili, e la coda usera\' il cron invece delle richieste in loopback.', 'mm-optimizer' ),
					$caps['env']['managed_host']
				)
			);
		}

		// Avvertenze d'ambiente che non cambiano la proposta ma vanno dette.
		if ( empty( $caps['env']['file_uploads'] ) ) {
			$blockers[] = self::reason(
				'file_uploads_off',
				'error',
				__( 'La direttiva PHP file_uploads e\' disattivata: nessun caricamento nella Libreria media puo\' funzionare, indipendentemente da questo plugin. La conversione in blocco delle immagini gia\' presenti funziona comunque.', 'mm-optimizer' )
			);
		}

		if ( empty( $caps['env']['uploads_writable'] ) ) {
			$blockers[] = self::reason(
				'uploads_unwritable',
				'error',
				__( 'La cartella degli upload non e\' scrivibile: non e\' possibile creare nessuna derivata.', 'mm-optimizer' )
			);
		}

		foreach ( (array) $caps['warnings'] as $warning ) {
			if ( 0 === strpos( $warning, 'claimed_not_verified:' ) ) {
				$parts     = explode( ':', $warning );
				$blockers[] = self::reason(
					'claimed_not_verified',
					'error',
					sprintf(
						/* translators: 1: nome della libreria, 2: tipo MIME. */
						__( '%1$s dichiara di supportare %2$s ma la prova di codifica e\' fallita. Il formato resta disattivato: e\' esattamente la situazione che produce file corrotti in silenzio.', 'mm-optimizer' ),
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
	 * Applica la proposta passando dalla sanificazione delle opzioni.
	 *
	 * @param array|null $caps Capacita'.
	 * @return array Opzioni risultanti.
	 */
	public static function apply( $caps = null ) {
		$analysis = self::analyze( $caps );

		Log::add( __( 'Applicata la configurazione consigliata.', 'mm-optimizer' ), 'info', array( 'level' => $analysis['level'] ) );

		return Options::apply_partial( $analysis['settings'] );
	}

	/**
	 * Indice sintetico di idoneita' dell'host.
	 *
	 * @param array $caps Capacita'.
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
	 * Spiegazione testuale della scelta di un motore.
	 *
	 * @param string $why          Codice.
	 * @param array  $alternatives Motori scartati.
	 * @return string
	 */
	protected static function why_text( $why, array $alternatives ) {
		switch ( $why ) {
			case 'only_candidate':
				return __( 'unico codificatore disponibile.', 'mm-optimizer' );

			case 'tie_on_bytes_faster':
				return sprintf(
					/* translators: %s: elenco dei motori scartati. */
					__( 'a parita\' di byte con %s, ma piu\' veloce.', 'mm-optimizer' ),
					implode( ', ', $alternatives )
				);

			case 'smallest_output':
				return sprintf(
					/* translators: %s: elenco dei motori scartati. */
					__( 'ha prodotto il file piu\' piccolo, battendo %s.', 'mm-optimizer' ),
					implode( ', ', $alternatives )
				);
		}

		return '';
	}

	/**
	 * Costruisce una motivazione.
	 *
	 * @param string $code  Codice simbolico.
	 * @param string $level 'good', 'info', 'warning' oppure 'error'.
	 * @param string $text  Testo gia' tradotto.
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
