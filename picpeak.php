<?php
/**
 * Plugin Name:       PicPeak
 * Plugin URI:        https://github.com/Luca-Timo/plugin-wordpress
 * Description:       Import gallery images from a PicPeak instance into the WordPress media library.
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PicPeak
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       picpeak
 * Domain Path:       /languages
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

define( 'PICPEAK_VERSION', '0.1.2' );
define( 'PICPEAK_FILE', __FILE__ );
define( 'PICPEAK_DIR', plugin_dir_path( __FILE__ ) );
define( 'PICPEAK_URL', plugin_dir_url( __FILE__ ) );

require_once PICPEAK_DIR . 'includes/class-settings.php';
require_once PICPEAK_DIR . 'includes/class-client.php';
require_once PICPEAK_DIR . 'includes/class-rest.php';
require_once PICPEAK_DIR . 'includes/class-taxonomy.php';
require_once PICPEAK_DIR . 'includes/class-folders.php';
require_once PICPEAK_DIR . 'includes/class-importer.php';
require_once PICPEAK_DIR . 'includes/class-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'picpeak', false, dirname( plugin_basename( PICPEAK_FILE ) ) . '/languages' );
		Settings::init();
		Rest::init();
		Taxonomy::init();
		Admin::init();
	}
);
