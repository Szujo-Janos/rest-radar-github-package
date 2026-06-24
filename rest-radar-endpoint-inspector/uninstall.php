<?php
/**
 * REST Radar uninstall cleanup.
 *
 * @package RestRadarEndpointInspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_option( 'rest_radar_options', array() );

if ( is_array( $options ) && ! empty( $options['cleanup_on_uninstall'] ) ) {
	delete_option( 'rest_radar_options' );
	delete_option( 'rest_radar_shield_options' );
	delete_option( 'rest_radar_shield_logs' );
	delete_option( 'rest_radar_snapshots' );
	delete_option( 'rest_radar_endpoint_reviews' );
}
