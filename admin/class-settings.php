<?php
/**
 * Pagina delle impostazioni.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Settings {

	/**
	 * Schede disponibili.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'diagnostica' => __( 'Diagnostica', 'mm-optimizer' ),
			'formati'     => __( 'Formati e qualita\'', 'mm-optimizer' ),
			'blocco'      => __( 'Conversione in blocco', 'mm-optimizer' ),
			'consegna'    => __( 'Consegna', 'mm-optimizer' ),
			'avanzate'    => __( 'Avanzate', 'mm-optimizer' ),
		);
	}

	/**
	 * Scheda attiva.
	 *
	 * @return string
	 */
	protected static function current_tab() {
		$tabs = self::tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola navigazione fra schede, nessuna modifica.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'diagnostica';

		return isset( $tabs[ $tab ] ) ? $tab : 'diagnostica';
	}

	/**
	 * Disegna la pagina.
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

		if ( 'diagnostica' === $current ) {
			Report::render();
		} elseif ( 'blocco' === $current ) {
			Bulk::render();
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			settings_fields( Options::KEY );

			switch ( $current ) {
				case 'formati':
					self::render_formats();
					break;
				case 'consegna':
					self::render_delivery();
					break;
				case 'avanzate':
					self::render_advanced();
					break;
			}

			submit_button();
			echo '</form>';
		}

		echo '</div></div>';
	}

	/**
	 * Scheda Formati e qualita'.
	 *
	 * @return void
	 */
	protected static function render_formats() {
		$caps    = Capabilities::get();
		$level   = Options::get( 'level', 'balanced' );
		$key     = Options::KEY;

		echo '<h2>' . esc_html__( 'Livello di ottimizzazione', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'I risparmi indicati derivano da misure reali su una fotografia da 2048 px: non sono stime di comodo.', 'mm-optimizer' ) . '</p>';

		echo '<div class="mm-opt-presets">';

		foreach ( Presets::all() as $slug => $values ) {
			$checked = checked( $level, $slug, false );

			echo '<label class="mm-opt-preset' . ( $level === $slug ? ' is-active' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( $key ) . '[level]" value="' . esc_attr( $slug ) . '" ' . $checked . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() restituisce markup fisso.
			echo '<span class="mm-opt-preset-name">' . esc_html( Presets::label( $slug ) ) . '</span>';
			echo '<span class="mm-opt-preset-desc">' . esc_html( Presets::description( $slug ) ) . '</span>';

			if ( ! empty( $values ) ) {
				echo '<span class="mm-opt-preset-nums">';
				printf(
					/* translators: 1: qualita' WebP, 2: risparmio stimato WebP, 3: qualita' AVIF, 4: risparmio stimato AVIF. */
					esc_html__( 'WebP q%1$d (circa -%2$d%%) · AVIF q%3$d (circa -%4$d%%)', 'mm-optimizer' ),
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

		echo '<h2>' . esc_html__( 'Formati da generare', 'mm-optimizer' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( array( 'avif' => 'AVIF', 'webp' => 'WebP' ) as $format => $label ) {
			$mime      = 'image/' . $format;
			$engine    = isset( $caps['encoders'][ $mime ]['engine'] ) ? $caps['encoders'][ $mime ]['engine'] : '';
			$available = '' !== $engine;

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[' . esc_attr( $format ) . '][enabled]" value="1" ' .
				checked( Options::get( $format . '.enabled' ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo esc_html__( 'Genera le derivate', 'mm-optimizer' ) . '</label><br />';

			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[' . esc_attr( $format ) . '][deliver]" value="1" ' .
				checked( Options::get( $format . '.deliver' ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo esc_html__( 'Consegna al browser', 'mm-optimizer' ) . '</label>';

			echo '<p class="description">';
			if ( $available ) {
				printf(
					/* translators: %s: nome del motore di codifica. */
					esc_html__( 'Motore in uso: %s (scelto dal rilevamento).', 'mm-optimizer' ),
					'<code>' . esc_html( $engine ) . '</code>'
				);
			} else {
				esc_html_e( 'Nessun codificatore disponibile su questo server per questo formato.', 'mm-optimizer' );
			}
			echo '</p></td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Valori personalizzati', 'mm-optimizer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Usati soltanto quando il livello e\' impostato su Personalizzato.', 'mm-optimizer' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$fields = array(
			'webp_jpeg'  => array( __( 'Qualita\' WebP da JPEG', 'mm-optimizer' ), 1, 100 ),
			'webp_png'   => array( __( 'Qualita\' WebP da PNG', 'mm-optimizer' ), 1, 100 ),
			'avif_jpeg'  => array( __( 'Qualita\' AVIF da JPEG', 'mm-optimizer' ), 1, 100 ),
			'avif_png'   => array( __( 'Qualita\' AVIF da PNG', 'mm-optimizer' ), 1, 100 ),
			'avif_speed' => array( __( 'Velocita\' AVIF (0 lento, 10 rapido)', 'mm-optimizer' ), 0, 10 ),
			'cwebp_m'    => array( __( 'Metodo cwebp (0-6)', 'mm-optimizer' ), 0, 6 ),
			'min_saving' => array( __( 'Risparmio minimo per conservare il file (%)', 'mm-optimizer' ), 0, 50 ),
			'skip_bytes' => array( __( 'Ignora le immagini sotto (byte)', 'mm-optimizer' ), 0, 10485760 ),
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
	 * Scheda Consegna.
	 *
	 * @return void
	 */
	protected static function render_delivery() {
		$key       = Options::KEY;
		$conflicts = Conflicts::active();

		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: elenco dei plugin in conflitto. */
				esc_html__( 'La consegna e\' sospesa: %s gestisce gia\' le immagini di questo sito. Le impostazioni qui sotto restano modificabili e avranno effetto appena l\'altro plugin sara\' disattivato.', 'mm-optimizer' ),
				esc_html( implode( ', ', $conflicts ) )
			);
			echo '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Modalita\'', 'mm-optimizer' ) . '</th><td>';

		$modes = array(
			'picture'  => __( 'Markup &lt;picture&gt; (consigliata)', 'mm-optimizer' ),
			'htaccess' => __( 'Negoziazione via .htaccess', 'mm-optimizer' ),
			'off'      => __( 'Nessuna consegna', 'mm-optimizer' ),
		);

		$mode = Options::get( 'delivery.mode', 'picture' );

		foreach ( $modes as $slug => $label ) {
			echo '<label style="display:block;margin-bottom:6px"><input type="radio" name="' . esc_attr( $key ) . '[delivery][mode]" value="' . esc_attr( $slug ) . '" ' . checked( $mode, $slug, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo wp_kses( $label, array() ) . '</label>';
		}

		echo '<p class="description">' . esc_html__( 'La modalita\' <picture> inserisce tutte le varianti nel markup e lascia scegliere al browser: non richiede Vary: Accept e non disturba la cache di pagina ne\' la CDN. La negoziazione via .htaccess non tocca l\'HTML ma impone Vary: Accept su ogni immagine, cosa che di fatto disabilita la cache CDN.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		$checkboxes = array(
			'addtype'        => array( __( 'Dichiara i tipi MIME nel .htaccess', 'mm-optimizer' ), __( 'Necessario su molti server: senza, i file .avif vengono serviti come application/octet-stream e Firefox e Safari non li decodificano.', 'mm-optimizer' ) ),
			'buffer'         => array( __( 'Riscrivi l\'intera pagina', 'mm-optimizer' ), __( 'Attivalo solo se il tema produce immagini che sfuggono ai filtri standard di WordPress. Ha un costo su ogni richiesta.', 'mm-optimizer' ) ),
			'partial_srcset' => array( __( 'Consenti srcset parziali', 'mm-optimizer' ), __( 'Sconsigliato. Se manca anche una sola derivata il browser puo\' scegliere una risoluzione piu\' bassa di quella originale, con un peggioramento visibile e difficile da diagnosticare.', 'mm-optimizer' ) ),
			'verify_disk'    => array( __( 'Verifica su disco ogni derivata', 'mm-optimizer' ), __( 'Piu\' sicuro ma piu\' lento: aggiunge centinaia di accessi al filesystem per pagina. Normalmente basta il database.', 'mm-optimizer' ) ),
		);

		foreach ( $checkboxes as $name => $spec ) {
			echo '<tr><th scope="row">' . esc_html( $spec[0] ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[delivery][' . esc_attr( $name ) . ']" value="1" ' . checked( Options::get( 'delivery.' . $name ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo esc_html__( 'Attiva', 'mm-optimizer' ) . '</label>';
			echo '<p class="description">' . esc_html( $spec[1] ) . '</p></td></tr>';
		}

		echo '<tr><th scope="row"><label for="mm-opt-exclude">' . esc_html__( 'Classi CSS da escludere', 'mm-optimizer' ) . '</label></th><td>';
		echo '<textarea id="mm-opt-exclude" class="large-text code" rows="3" name="' . esc_attr( $key ) . '[delivery][exclude_classes]">';
		echo esc_textarea( implode( "\n", (array) Options::get( 'delivery.exclude_classes', array() ) ) );
		echo '</textarea>';
		echo '<p class="description">' . esc_html__( 'Una classe per riga. Le immagini che la portano non verranno racchiuse in <picture>. Serve quando un foglio di stile usa selettori figlio diretto come .galleria > img, che smettono di funzionare se si interpone un elemento.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Scheda Avanzate.
	 *
	 * @return void
	 */
	protected static function render_advanced() {
		$key = Options::KEY;

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Ottimizzazione delle miniature', 'mm-optimizer' ) . '</th><td>';

		$caps = Capabilities::get();

		foreach ( array( 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF' ) as $format => $label ) {
			$mime      = 'image/' . $format;
			$available = ! empty( $caps['lossless'][ $mime ]['available'] );

			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $key ) . '[optimize][' . esc_attr( $format ) . ']" value="1" ' .
				checked( Options::get( 'optimize.' . $format ), 1, false ) . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
				disabled( $available, false, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo esc_html( $label );

			if ( ! $available ) {
				echo ' <span class="description">' . esc_html__( '(nessuno strumento disponibile su questo server)', 'mm-optimizer' ) . '</span>';
			}

			echo '</label>';
		}

		echo '<p class="description">' . esc_html__( 'Riguarda solo le miniature generate da WordPress. I file originali non vengono mai modificati, in nessun caso.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Dimensioni da convertire', 'mm-optimizer' ) . '</th><td>';

		$modes = array(
			'srcset' => __( 'Solo quelle usate nel srcset (consigliato)', 'mm-optimizer' ),
			'all'    => __( 'Tutte le dimensioni registrate', 'mm-optimizer' ),
		);

		$size_mode = Options::get( 'sizes.mode', 'srcset' );

		foreach ( $modes as $slug => $label ) {
			echo '<label style="display:block"><input type="radio" name="' . esc_attr( $key ) . '[sizes][mode]" value="' . esc_attr( $slug ) . '" ' . checked( $size_mode, $slug, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
			echo esc_html( $label ) . '</label>';
		}

		$count = function_exists( 'wp_get_registered_image_subsizes' ) ? count( wp_get_registered_image_subsizes() ) : 0;

		echo '<p class="description">' . sprintf(
			/* translators: %d: numero di dimensioni registrate. */
			esc_html__( 'Questo sito ha %d dimensioni registrate. Convertirle tutte in due formati moltiplica il lavoro e lo spazio occupato, spesso per file che nessuna pagina richiede mai.', 'mm-optimizer' ),
			(int) $count
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Registro diagnostico', 'mm-optimizer' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[log_enabled]" value="1" ' . checked( Options::get( 'log_enabled' ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
		echo esc_html__( 'Attivo', 'mm-optimizer' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Disinstallazione', 'mm-optimizer' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[uninstall_delete]" value="1" ' . checked( Options::get( 'uninstall_delete' ), 1, false ) . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup fisso.
		echo esc_html__( 'Alla rimozione del plugin elimina impostazioni, tabelle e derivate generate', 'mm-optimizer' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disattivo per impostazione predefinita: disattivare il plugin non cancella mai nulla.', 'mm-optimizer' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}
}
