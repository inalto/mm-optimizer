<?php
/**
 * Plugin Name:       MM Optimizer
 * Plugin URI:        https://inalto.com/
 * Description:       Image optimization for WordPress. Detects the graphics libraries available on the host, verifies which encoders actually work, and proposes the optimal settings. Generates WebP and AVIF derivatives with discrete optimization levels and delivers them responsively. Never modifies the original files. No paid tier, no limits.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Inalto
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       mm-optimizer
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MM_OPTIMIZER_VERSION', '1.1.0' );
define( 'MM_OPTIMIZER_FILE', __FILE__ );
define( 'MM_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MM_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );
define( 'MM_OPTIMIZER_BASENAME', plugin_basename( __FILE__ ) );

require_once MM_OPTIMIZER_DIR . 'includes/class-base.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-options.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-log.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-presets.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-conflicts.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-probe.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-capabilities.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-recommender.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-paths.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-encoder.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-records.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-thumbnails.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-converter.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-lock.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-queue.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-scanner.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-migrator.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-attachment-hooks.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-htaccess.php';
require_once MM_OPTIMIZER_DIR . 'includes/delivery/class-srcset.php';
require_once MM_OPTIMIZER_DIR . 'includes/delivery/class-picture.php';
require_once MM_OPTIMIZER_DIR . 'includes/delivery/class-delivery.php';
require_once MM_OPTIMIZER_DIR . 'includes/api.php';
require_once MM_OPTIMIZER_DIR . 'includes/class-plugin.php';

if ( is_admin() ) {
	require_once MM_OPTIMIZER_DIR . 'admin/class-report.php';
	require_once MM_OPTIMIZER_DIR . 'admin/class-bulk.php';
	require_once MM_OPTIMIZER_DIR . 'admin/class-settings.php';
	require_once MM_OPTIMIZER_DIR . 'admin/class-ajax.php';
	require_once MM_OPTIMIZER_DIR . 'admin/class-admin.php';
}

register_activation_hook( __FILE__, array( 'MM_Optimizer\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MM_Optimizer\\Plugin', 'deactivate' ) );

MM_Optimizer\Plugin::instance()->boot();
