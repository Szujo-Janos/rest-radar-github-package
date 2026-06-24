<?php
/**
 * Plugin Name: REST Radar - Endpoint Inspector
 * Plugin URI:  https://github.com/Szujo-Janos
 * Description: REST API endpoint inspector and non-destructive shield for WordPress. Lists risky routes, exports QA-ready findings, and can apply protective endpoint rules.
 * Version:     0.9.1
 * Author:      Szujó János
 * Author URI:  https://github.com/Szujo-Janos
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rest-radar
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 *
 * @package RestRadarEndpointInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REST_RADAR_VERSION', '0.9.1' );
define( 'REST_RADAR_FILE', __FILE__ );
define( 'REST_RADAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'REST_RADAR_URL', plugin_dir_url( __FILE__ ) );

require_once REST_RADAR_PATH . 'includes/class-rest-radar-scanner.php';
require_once REST_RADAR_PATH . 'includes/class-rest-radar-shield.php';
require_once REST_RADAR_PATH . 'includes/class-rest-radar-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		Rest_Radar_Shield::init();
		Rest_Radar_Plugin::instance();
	}
);
