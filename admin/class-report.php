<?php
/**
 * Resa della matrice delle capacita' e della configurazione consigliata.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Report {

	/**
	 * Disegna l'intera scheda Diagnostica.
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
	 * Intestazione con punteggio e pulsanti.
	 *
	 * @param array $caps     Capacita'.
	 * @param array $analysis Analisi.
	 * @return void
	 */
	protected static function render_summary( array $caps, array $analysis ) {
		$score = (int) $analysis['score'];
		$class = $score >= 80 ? 'mm-opt-good' : ( $score >= 50 ? 'mm-opt-warn' : 'mm-opt-bad' );

		echo '<div class="mm-opt-summary ' . esc_attr( $class ) . '">';
		echo '<div class="mm-opt-score"><strong>' . esc_html( $score ) . '</strong><span>/100</span></div>';
		echo '<div class="mm-opt-score-text">';
		echo '<h2>' . esc_html__( 'Idoneita\' di questo server', 'mm-optimizer' ) . '</h2>';
		echo '<p>' . esc_html( self::score_text( $score ) ) . '</p>';
		echo '<p class="description">' . sprintf(
			/* translators: 1: data e ora dell'ultimo rilevamento, 2: durata in millisecondi. */
			esc_html__( 'Ultimo rilevamento: %1$s (%2$d ms).', 'mm-optimizer' ),
			esc_html( wp_date( 'j F Y, H:i', (int) $caps['generated'] ) ),
			(int) $caps['duration_ms']
		) . '</p>';
		echo '</div>';
		echo '<div class="mm-opt-score-actions">';
		echo '<button type="button" class="button" id="mm-opt-detect">' . esc_html__( 'Riesegui il rilevamento', 'mm-optimizer' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="mm-opt-apply">' . esc_html__( 'Applica la configurazione consigliata', 'mm-optimizer' ) . '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Testo sintetico del punteggio.
	 *
	 * @param int $score Punteggio.
	 * @return string
	 */
	protected static function score_text( $score ) {
		if ( $score >= 80 ) {
			return __( 'Questo server sa fare tutto il necessario: le immagini possono essere ottimizzate senza compromessi.', 'mm-optimizer' );
		}
		if ( $score >= 50 ) {
			return __( 'Questo server puo\' ottimizzare le immagini, ma qualcosa manca. Leggi le motivazioni qui sotto: indicano cosa chiedere all\'hosting.', 'mm-optimizer' );
		}

		return __( 'Questo server non dispone di quasi nulla di quello che serve. Le motivazioni qui sotto elencano i pacchetti da richiedere all\'hosting.', 'mm-optimizer' );
	}

	/**
	 * Matrice formati x librerie.
	 *
	 * @param array $caps Capacita'.
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

		echo '<h2>' . esc_html__( 'Librerie grafiche rilevate', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ogni cella riporta l\'esito di una prova reale: il plugin codifica un campione noto e ne verifica i byte prodotti. Il supporto soltanto dichiarato non basta, perche\' e\' proprio quello che produce file corrotti senza avvisare.', 'mm-optimizer' ) . '</p>';

		echo '<table class="widefat striped mm-opt-matrix"><thead><tr>';
		echo '<th>' . esc_html__( 'Formato', 'mm-optimizer' ) . '</th>';

		$columns = array();
		foreach ( array( 'gd' => 'GD', 'imagick' => 'Imagick', 'gmagick' => 'Gmagick' ) as $key => $label ) {
			$columns[ $key ] = $label;
			$present         = ! empty( $caps['libraries'][ $key ]['present'] );
			$version         = $present && ! empty( $caps['libraries'][ $key ]['version'] ) ? ' ' . $caps['libraries'][ $key ]['version'] : '';

			echo '<th>' . esc_html( $label . $version );
			if ( ! $present ) {
				echo ' <span class="mm-opt-absent">' . esc_html__( '(assente)', 'mm-optimizer' ) . '</span>';
			}
			echo '</th>';
		}

		echo '<th>' . esc_html__( 'Binari di sistema', 'mm-optimizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		$binary_for = array( 'image/webp' => 'cwebp', 'image/avif' => 'avifenc' );

		foreach ( $formats as $mime => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';

			foreach ( $columns as $key => $unused ) {
				$entry = isset( $caps['libraries'][ $key ]['formats'][ $mime ] ) ? $caps['libraries'][ $key ]['formats'][ $mime ] : null;
				echo '<td>' . self::cell( $entry ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() restituisce markup gia' sanificato.
			}

			$binary = isset( $binary_for[ $mime ] ) ? $binary_for[ $mime ] : '';
			$entry  = ( '' !== $binary && isset( $caps['binaries'][ $binary ] ) ) ? $caps['binaries'][ $binary ] : null;

			echo '<td>';
			if ( null === $entry ) {
				echo '<span class="mm-opt-na">&mdash;</span>';
			} else {
				echo '<span class="mm-opt-binname">' . esc_html( $binary ) . '</span> ';
				echo self::cell( array( 'claimed' => '' !== $entry['path'], 'verified' => $entry['verified'], 'bytes' => $entry['bytes'], 'ms' => $entry['ms'], 'reason' => $entry['reason'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' sanificato.
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Una cella della matrice.
	 *
	 * @param array|null $entry Dati del formato.
	 * @return string Markup gia' sanificato.
	 */
	protected static function cell( $entry ) {
		if ( ! is_array( $entry ) ) {
			return '<span class="mm-opt-na">&mdash;</span>';
		}

		if ( ! empty( $entry['verified'] ) ) {
			$title = sprintf(
				/* translators: 1: byte prodotti, 2: millisecondi impiegati. */
				__( 'Prova superata: %1$d byte in %2$s ms.', 'mm-optimizer' ),
				(int) $entry['bytes'],
				(string) $entry['ms']
			);

			return '<span class="mm-opt-ok" title="' . esc_attr( $title ) . '">&#10003; ' . esc_html__( 'verificato', 'mm-optimizer' ) . '</span>';
		}

		if ( ! empty( $entry['claimed'] ) ) {
			$title = self::reason_text( isset( $entry['reason'] ) ? $entry['reason'] : '' );

			return '<span class="mm-opt-warn-cell" title="' . esc_attr( $title ) . '">~ ' . esc_html__( 'dichiarato', 'mm-optimizer' ) . '</span>';
		}

		return '<span class="mm-opt-no">&#10007;</span>';
	}

	/**
	 * Traduce un codice di fallimento.
	 *
	 * @param string $reason Codice.
	 * @return string
	 */
	protected static function reason_text( $reason ) {
		$map = array(
			'not_claimed'      => __( 'Il formato non e\' dichiarato da questa libreria.', 'mm-optimizer' ),
			'encode_failed'    => __( 'La chiamata di codifica e\' fallita.', 'mm-optimizer' ),
			'no_output'        => __( 'Nessun file prodotto.', 'mm-optimizer' ),
			'empty_output'     => __( 'File prodotto vuoto.', 'mm-optimizer' ),
			'wrong_format'     => __( 'Il file prodotto non e\' nel formato richiesto.', 'mm-optimizer' ),
			'not_compressed'   => __( 'Il file prodotto e\' troppo grande: la libreria non sta comprimendo.', 'mm-optimizer' ),
			'decode_failed'    => __( 'Il file prodotto non e\' rileggibile.', 'mm-optimizer' ),
			'exception'        => __( 'La libreria ha sollevato un\'eccezione.', 'mm-optimizer' ),
			'sample_missing'   => __( 'Campione di prova non trovato.', 'mm-optimizer' ),
			'tmp_unwritable'   => __( 'Cartella temporanea non scrivibile.', 'mm-optimizer' ),
			'not_found'        => __( 'Binario non presente sul server.', 'mm-optimizer' ),
			'exec_unavailable' => __( 'Le funzioni di esecuzione di PHP sono disattivate.', 'mm-optimizer' ),
			'managed_host_skip' => __( 'Hosting gestito: la ricerca dei binari e\' stata saltata.', 'mm-optimizer' ),
			'no_reduction'     => __( 'Il binario non ha ridotto il campione di prova.', 'mm-optimizer' ),
			'probe_deadline'   => __( 'Tempo massimo di rilevamento superato.', 'mm-optimizer' ),
			'not_probed'       => __( 'Non sottoposto a prova.', 'mm-optimizer' ),
		);

		return isset( $map[ $reason ] ) ? $map[ $reason ] : $reason;
	}

	/**
	 * Tabella comparativa dei motori sul campione fotografico.
	 *
	 * @param array $caps Capacita'.
	 * @return void
	 */
	protected static function render_bench( array $caps ) {
		if ( empty( $caps['bench'] ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Confronto fra i motori', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Misure reali su una fotografia di prova da 512x384. Il campione minuscolo usato per la verifica non basta a stabilire chi comprime meglio: su un\'immagine di quelle dimensioni le differenze fra i motori spariscono.', 'mm-optimizer' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Formato', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Motore', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Dimensione', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Tempo', 'mm-optimizer' ) . '</th>';
		echo '<th>' . esc_html__( 'Esito', 'mm-optimizer' ) . '</th>';
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
					? '<strong>' . esc_html__( 'scelto', 'mm-optimizer' ) . '</strong>'
					: esc_html__( 'alternativa', 'mm-optimizer' ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Configurazione consigliata con le motivazioni.
	 *
	 * @param array $analysis Analisi.
	 * @return void
	 */
	protected static function render_recommendation( array $analysis ) {
		echo '<h2>' . esc_html__( 'Configurazione consigliata', 'mm-optimizer' ) . '</h2>';

		if ( ! empty( $analysis['blockers'] ) ) {
			echo '<div class="mm-opt-blockers">';
			echo '<h3>' . esc_html__( 'Da risolvere', 'mm-optimizer' ) . '</h3><ul>';
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
	 * Dati d'ambiente, in coda.
	 *
	 * @param array $caps Capacita'.
	 * @return void
	 */
	protected static function render_environment( array $caps ) {
		$env = $caps['env'];

		$rows = array(
			__( 'Versione di PHP', 'mm-optimizer' )         => $env['php'],
			__( 'Sistema operativo', 'mm-optimizer' )       => $env['os'],
			__( 'Limite di memoria', 'mm-optimizer' )       => $env['memory_limit'] > 0 ? size_format( $env['memory_limit'] ) : __( 'illimitato', 'mm-optimizer' ),
			__( 'Tempo massimo di esecuzione', 'mm-optimizer' ) => $env['max_execution_time'] > 0 ? $env['max_execution_time'] . ' s' : __( 'illimitato', 'mm-optimizer' ),
			__( 'Esecuzione di comandi', 'mm-optimizer' )   => $env['exec_available'] ? __( 'disponibile', 'mm-optimizer' ) : __( 'non disponibile', 'mm-optimizer' ),
			__( 'Hosting gestito', 'mm-optimizer' )         => '' !== $env['managed_host'] ? $env['managed_host'] : __( 'no', 'mm-optimizer' ),
			__( 'open_basedir', 'mm-optimizer' )            => '' !== $env['open_basedir'] ? $env['open_basedir'] : __( 'non impostato', 'mm-optimizer' ),
			__( 'Cartella upload scrivibile', 'mm-optimizer' ) => $env['uploads_writable'] ? __( 'si', 'mm-optimizer' ) : __( 'no', 'mm-optimizer' ),
			__( 'Caricamento file PHP', 'mm-optimizer' )    => $env['file_uploads'] ? __( 'attivo', 'mm-optimizer' ) : __( 'DISATTIVATO', 'mm-optimizer' ),
			__( 'Tipo MIME per .avif', 'mm-optimizer' )     => self::mime_text( $caps['delivery']['avif_mime_registered'] ),
			__( '.htaccess scrivibile', 'mm-optimizer' )    => $caps['delivery']['htaccess_writable'] ? __( 'si', 'mm-optimizer' ) : __( 'no', 'mm-optimizer' ),
		);

		echo '<h2>' . esc_html__( 'Ambiente', 'mm-optimizer' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Descrizione dell'esito della sonda MIME.
	 *
	 * @param bool|null $registered Esito.
	 * @return string
	 */
	protected static function mime_text( $registered ) {
		if ( null === $registered ) {
			return __( 'non verificabile', 'mm-optimizer' );
		}

		return $registered
			? __( 'corretto', 'mm-optimizer' )
			: __( 'ERRATO: il server non conosce image/avif', 'mm-optimizer' );
	}
}
