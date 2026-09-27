<?php
/**
 * Bulk conversion tab.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Bulk {

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		$state     = Queue::state();
		$queued    = Queue::remaining();
		$totals    = Records::totals();
		$formats   = Converter::enabled_formats();
		$library   = Scanner::total_attachments();

		echo '<h2>' . esc_html__( 'Bulk conversion', 'mm-optimizer' ) . '</h2>';

		if ( empty( $formats ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No format is active, so there is nothing to convert. Check the Diagnostics tab.', 'mm-optimizer' ) . '</p></div>';
			return;
		}

		echo '<p class="description">';
		printf(
			/* translators: 1: number of images, 2: list of formats. */
			esc_html__( 'The library holds %1$s images. Derivatives will be generated in these formats: %2$s. Original files are never modified.', 'mm-optimizer' ),
			'<strong>' . esc_html( number_format_i18n( $library ) ) . '</strong>',
			'<strong>' . esc_html( strtoupper( implode( ', ', $formats ) ) ) . '</strong>'
		);
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::row( __( 'Derivatives generated', 'mm-optimizer' ), number_format_i18n( $totals['done'] ) );
		self::row( __( 'Skipped (no gain)', 'mm-optimizer' ), number_format_i18n( $totals['skipped'] ) );
		self::row( __( 'Errors', 'mm-optimizer' ), number_format_i18n( $totals['error'] ) );
		self::row( __( 'Space saved', 'mm-optimizer' ), size_format( $totals['saved'], 1 ) );
		self::row( __( 'Queued', 'mm-optimizer' ), number_format_i18n( $queued ) );
		echo '</tbody></table>';

		echo '<div class="mm-opt-bulk" style="margin-top:20px">';
		echo '<div class="mm-opt-progress"><div class="mm-opt-progress-bar" id="mm-opt-bar" style="width:0"></div></div>';
		echo '<p id="mm-opt-bulk-status" class="description">' . esc_html__( 'Ready.', 'mm-optimizer' ) . '</p>';

		// A queue left over from an earlier run resumes as it is: rescanning
		// would throw it away and walk the whole library again.
		if ( $queued > 0 ) {
			echo '<button type="button" class="button button-primary" id="mm-opt-bulk-resume">';
			printf(
				/* translators: %s: number of files still queued. */
				esc_html__( 'Resume (%s queued)', 'mm-optimizer' ),
				esc_html( number_format_i18n( $queued ) )
			);
			echo '</button> ';
			echo '<button type="button" class="button" id="mm-opt-bulk-start">' . esc_html__( 'Rescan the library', 'mm-optimizer' ) . '</button> ';
		} else {
			echo '<button type="button" class="button button-primary" id="mm-opt-bulk-start">' . esc_html__( 'Start conversion', 'mm-optimizer' ) . '</button> ';
		}

		echo '<button type="button" class="button" id="mm-opt-bulk-stop" disabled>' . esc_html__( 'Stop', 'mm-optimizer' ) . '</button>';
		echo '</div>';

		echo '<p class="description" style="margin-top:14px">' . esc_html__( 'Conversion continues while this page stays open, and picks up where it left off if you close it. WordPress cron is not reliable on every host, so running it from the browser is the main route rather than a fallback.', 'mm-optimizer' ) . '</p>';

		if ( $queued > 0 && $state['last_tick'] > 0 && ( time() - (int) $state['last_tick'] ) > 5 * MINUTE_IN_SECONDS ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: human-readable time since the last batch. */
				esc_html__( 'The last batch ran %s ago: the queue is idle. Resume picks it up from where it stopped.', 'mm-optimizer' ),
				esc_html( human_time_diff( (int) $state['last_tick'] ) )
			);
			echo '</p>';
		}

		self::render_migration();
	}

	/**
	 * Import section for a previous plugin.
	 *
	 * @return void
	 */
	protected static function render_migration() {
		if ( ! Migrator::has_source() ) {
			return;
		}

		$inventory = Migrator::inventory();

		echo '<hr style="margin:30px 0" />';
		echo '<h2>' . esc_html__( 'Import from Converter for Media', 'mm-optimizer' ) . '</h2>';

		if ( $inventory['webp'] < 1 && $inventory['avif'] < 1 ) {
			echo '<p class="description">' . esc_html__( 'The previous plugin\'s settings were found, but no already-converted files.', 'mm-optimizer' ) . '</p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: 1: number of WebP files, 2: number of AVIF files, 3: space occupied. */
				esc_html__( 'The previous plugin produced %1$s WebP files and %2$s AVIF files, taking %3$s in total.', 'mm-optimizer' ),
				'<strong>' . esc_html( number_format_i18n( $inventory['webp'] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $inventory['avif'] ) ) . '</strong>',
				'<strong>' . esc_html( size_format( $inventory['bytes'], 1 ) ) . '</strong>'
			);
			echo '</p>';

			if ( $inventory['avif'] < $inventory['webp'] / 10 ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Nearly all of those files are WebP. AVIF, which on this server pays off around 20% more than WebP, is effectively absent: regenerating from scratch is worth more than adopting the existing files. Adoption is still the quick route if you want to switch over now without waiting for the conversion.', 'mm-optimizer' ) . '</p></div>';
			}
		}

		echo '<p>';
		echo '<button type="button" class="button" id="mm-opt-import-settings">' . esc_html__( 'Import the settings', 'mm-optimizer' ) . '</button> ';

		if ( $inventory['webp'] > 0 || $inventory['avif'] > 0 ) {
			echo '<button type="button" class="button" id="mm-opt-adopt">' . esc_html__( 'Adopt the converted files', 'mm-optimizer' ) . '</button>';
		}

		echo '</p>';
		echo '<p id="mm-opt-migrate-status" class="description"></p>';
	}

	/**
	 * One row of the summary table.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	protected static function row( $label, $value ) {
		echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
