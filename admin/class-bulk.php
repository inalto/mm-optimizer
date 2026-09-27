<?php
/**
 * Scheda di conversione in blocco.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Bulk {

	/**
	 * Disegna la scheda.
	 *
	 * @return void
	 */
	public static function render() {
		$state     = Queue::state();
		$totals    = Records::totals();
		$formats   = Converter::enabled_formats();
		$library   = Scanner::total_attachments();

		echo '<h2>' . esc_html__( 'Conversione in blocco', 'mm-optimizer' ) . '</h2>';

		if ( empty( $formats ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Nessun formato attivo: non c\'e\' nulla da convertire. Controlla la scheda Diagnostica.', 'mm-optimizer' ) . '</p></div>';
			return;
		}

		echo '<p class="description">';
		printf(
			/* translators: 1: numero di immagini, 2: elenco dei formati. */
			esc_html__( 'La libreria contiene %1$s immagini. Verranno generate le derivate nei formati: %2$s. I file originali non vengono mai modificati.', 'mm-optimizer' ),
			'<strong>' . esc_html( number_format_i18n( $library ) ) . '</strong>',
			'<strong>' . esc_html( strtoupper( implode( ', ', $formats ) ) ) . '</strong>'
		);
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::row( __( 'Derivate generate', 'mm-optimizer' ), number_format_i18n( $totals['done'] ) );
		self::row( __( 'Saltate (nessun guadagno)', 'mm-optimizer' ), number_format_i18n( $totals['skipped'] ) );
		self::row( __( 'Errori', 'mm-optimizer' ), number_format_i18n( $totals['error'] ) );
		self::row( __( 'Spazio risparmiato', 'mm-optimizer' ), size_format( $totals['saved'], 1 ) );
		self::row( __( 'In coda', 'mm-optimizer' ), number_format_i18n( $state['remaining'] ) );
		echo '</tbody></table>';

		echo '<div class="mm-opt-bulk" style="margin-top:20px">';
		echo '<div class="mm-opt-progress"><div class="mm-opt-progress-bar" id="mm-opt-bar" style="width:0"></div></div>';
		echo '<p id="mm-opt-bulk-status" class="description">' . esc_html__( 'Pronto.', 'mm-optimizer' ) . '</p>';
		echo '<button type="button" class="button button-primary" id="mm-opt-bulk-start">' . esc_html__( 'Avvia la conversione', 'mm-optimizer' ) . '</button> ';
		echo '<button type="button" class="button" id="mm-opt-bulk-stop" disabled>' . esc_html__( 'Ferma', 'mm-optimizer' ) . '</button>';
		echo '</div>';

		echo '<p class="description" style="margin-top:14px">' . esc_html__( 'La conversione prosegue finche\' questa pagina resta aperta, e riprende da dove si era interrotta se la chiudi. Su questo sito il cron di WordPress non e\' affidabile, quindi la lavorazione dal browser e\' la strada principale, non un ripiego.', 'mm-optimizer' ) . '</p>';

		self::render_migration();
	}

	/**
	 * Sezione di importazione da un plugin precedente.
	 *
	 * @return void
	 */
	protected static function render_migration() {
		if ( ! Migrator::has_source() ) {
			return;
		}

		$inventory = Migrator::inventory();

		echo '<hr style="margin:30px 0" />';
		echo '<h2>' . esc_html__( 'Importazione da Converter for Media', 'mm-optimizer' ) . '</h2>';

		if ( $inventory['webp'] < 1 && $inventory['avif'] < 1 ) {
			echo '<p class="description">' . esc_html__( 'Sono state trovate le impostazioni del plugin precedente, ma nessun file gia\' convertito.', 'mm-optimizer' ) . '</p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: 1: numero di file WebP, 2: numero di file AVIF, 3: spazio occupato. */
				esc_html__( 'Il plugin precedente ha prodotto %1$s file WebP e %2$s file AVIF, per %3$s complessivi.', 'mm-optimizer' ),
				'<strong>' . esc_html( number_format_i18n( $inventory['webp'] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $inventory['avif'] ) ) . '</strong>',
				'<strong>' . esc_html( size_format( $inventory['bytes'], 1 ) ) . '</strong>'
			);
			echo '</p>';

			if ( $inventory['avif'] < $inventory['webp'] / 10 ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Quasi tutti quei file sono WebP. L\'AVIF, che su questo server rende circa il 20% in piu\' del WebP, e\' praticamente assente: rigenerare da capo conviene piu\' che adottare i file esistenti. L\'adozione resta la strada rapida se vuoi passare subito senza attendere la conversione.', 'mm-optimizer' ) . '</p></div>';
			}
		}

		echo '<p>';
		echo '<button type="button" class="button" id="mm-opt-import-settings">' . esc_html__( 'Importa le impostazioni', 'mm-optimizer' ) . '</button> ';

		if ( $inventory['webp'] > 0 || $inventory['avif'] > 0 ) {
			echo '<button type="button" class="button" id="mm-opt-adopt">' . esc_html__( 'Adotta i file gia\' convertiti', 'mm-optimizer' ) . '</button>';
		}

		echo '</p>';
		echo '<p id="mm-opt-migrate-status" class="description"></p>';
	}

	/**
	 * Una riga della tabella di riepilogo.
	 *
	 * @param string $label Etichetta.
	 * @param string $value Valore.
	 * @return void
	 */
	protected static function row( $label, $value ) {
		echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
