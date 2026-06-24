<?php
/**
 * REST Radar Endpoint Shield.
 *
 * @package RestRadarEndpointInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-destructive REST endpoint protection layer.
 */
class Rest_Radar_Shield {
	/**
	 * Option name for shield settings.
	 */
	const OPTION_NAME = 'rest_radar_shield_options';

	/**
	 * Option name for recent shield blocks.
	 */
	const LOG_OPTION_NAME = 'rest_radar_shield_logs';

	/**
	 * Initialize shield runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_block_request' ), 5, 3 );
	}

	/**
	 * Get sanitized shield options.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_options() {
		$defaults = array(
			'enabled'        => false,
			'auto_safe_mode' => false,
			'include_core'   => false,
			'log_enabled'    => true,
			'anonymize_ip'   => true,
			'rules'          => array(),
		);

		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options = wp_parse_args( $options, $defaults );
		$options['enabled']        = ! empty( $options['enabled'] );
		$options['auto_safe_mode'] = ! empty( $options['auto_safe_mode'] );
		$options['include_core']   = ! empty( $options['auto_safe_mode'] ) && ! empty( $options['include_core'] );
		$options['log_enabled']    = ! empty( $options['log_enabled'] );
		$options['anonymize_ip']   = ! empty( $options['anonymize_ip'] );
		$options['rules']          = self::sanitize_rules( is_array( $options['rules'] ) ? $options['rules'] : array() );

		return $options;
	}

	/**
	 * Save top-level shield options while preserving rules.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	public static function save_settings( array $settings ) {
		$options = self::get_options();
		$options['enabled']        = ! empty( $settings['enabled'] );
		$options['auto_safe_mode'] = ! empty( $settings['auto_safe_mode'] );
		$options['include_core']   = ! empty( $settings['auto_safe_mode'] ) && ! empty( $settings['include_core'] );
		$options['log_enabled']    = ! empty( $settings['log_enabled'] );
		$options['anonymize_ip']   = ! empty( $settings['anonymize_ip'] );

		update_option( self::OPTION_NAME, $options, false );
	}

	/**
	 * Add a shield rule. Duplicate rules are ignored.
	 *
	 * A duplicate is the same normalized route pattern, method set, protection mode,
	 * and capability. Notes, enabled state, and creation time do not create a new rule.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed> Add result with id and added status.
	 */
	public static function add_rule( array $rule ) {
		$options = self::get_options();
		$rule    = self::sanitize_rule( $rule );

		if ( '' === $rule['pattern'] ) {
			return array(
				'id'    => '',
				'added' => false,
				'error' => 'empty_pattern',
			);
		}

		$new_signature = self::rule_signature( $rule );
		foreach ( $options['rules'] as $existing_rule ) {
			if ( self::rule_signature( $existing_rule ) === $new_signature ) {
				return array(
					'id'        => $existing_rule['id'] ?? '',
					'added'     => false,
					'duplicate' => true,
				);
			}
		}

		if ( empty( $rule['id'] ) ) {
			$rule['id'] = self::generate_rule_id( $rule );
		}

		$options['rules'][] = $rule;
		$options['rules']   = self::sanitize_rules( $options['rules'] );

		update_option( self::OPTION_NAME, $options, false );

		return array(
			'id'    => $rule['id'],
			'added' => true,
		);
	}

	/**
	 * Delete a shield rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool
	 */
	public static function delete_rule( $rule_id ) {
		$rule_id = sanitize_key( (string) $rule_id );
		$options = self::get_options();
		$before  = count( $options['rules'] );

		$options['rules'] = array_values(
			array_filter(
				$options['rules'],
				static function ( $rule ) use ( $rule_id ) {
					return ! isset( $rule['id'] ) || $rule['id'] !== $rule_id;
				}
			)
		);

		update_option( self::OPTION_NAME, $options, false );

		return count( $options['rules'] ) !== $before;
	}

	/**
	 * Sanitize a list of rules.
	 *
	 * @param array<int,array<string,mixed>> $rules Raw rules.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_rules( array $rules ) {
		$sanitized = array();
		$seen      = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$rule = self::sanitize_rule( $rule );
			if ( '' === $rule['pattern'] ) {
				continue;
			}

			$signature = self::rule_signature( $rule );
			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}

			$seen[ $signature ] = true;
			$sanitized[]        = $rule;
		}

		return array_values( $sanitized );
	}

	/**
	 * Sanitize one rule.
	 *
	 * @param array<string,mixed> $rule Raw rule.
	 * @return array<string,mixed>
	 */

	/**
	 * Build a duplicate-detection signature for a shield rule.
	 *
	 * @param array<string,mixed> $rule Sanitized rule.
	 * @return string
	 */
	private static function rule_signature( array $rule ) {
		$methods = isset( $rule['methods'] ) && is_array( $rule['methods'] ) ? $rule['methods'] : array( 'ANY' );
		$methods = array_map( 'strtoupper', array_map( 'sanitize_key', $methods ) );
		$methods = array_values( array_unique( $methods ) );
		sort( $methods );

		$pattern    = isset( $rule['pattern'] ) ? strtolower( trim( (string) $rule['pattern'] ) ) : '';
		$mode       = isset( $rule['mode'] ) ? sanitize_key( (string) $rule['mode'] ) : 'admins_only';
		$capability = isset( $rule['capability'] ) ? sanitize_key( (string) $rule['capability'] ) : '';
		if ( 'capability' !== $mode ) {
			$capability = '';
		}

		return sha1( $pattern . '|' . implode( ',', $methods ) . '|' . $mode . '|' . $capability );
	}

	private static function sanitize_rule( array $rule ) {
		$allowed_modes = array( 'block_guests', 'require_login', 'admins_only', 'capability', 'disable_route' );
		$allowed_methods = array( 'ANY', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

		$methods = isset( $rule['methods'] ) ? $rule['methods'] : array( 'ANY' );
		if ( is_string( $methods ) ) {
			$methods = preg_split( '/[|,\s]+/', $methods );
		}
		$methods = is_array( $methods ) ? $methods : array( 'ANY' );
		$methods = array_map( 'strtoupper', array_map( 'sanitize_key', $methods ) );
		$methods = array_values( array_intersect( $methods, $allowed_methods ) );
		if ( empty( $methods ) ) {
			$methods = array( 'ANY' );
		}

		$mode = isset( $rule['mode'] ) ? sanitize_key( (string) $rule['mode'] ) : 'admins_only';
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'admins_only';
		}

		$pattern = isset( $rule['pattern'] ) ? sanitize_text_field( (string) $rule['pattern'] ) : '';
		$pattern = trim( $pattern );
		if ( '' !== $pattern && '/' !== $pattern[0] ) {
			$pattern = '/' . $pattern;
		}

		$capability = isset( $rule['capability'] ) ? sanitize_key( (string) $rule['capability'] ) : '';
		if ( 'capability' === $mode && '' === $capability ) {
			$capability = 'manage_options';
		}

		return array(
			'id'         => isset( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : '',
			'enabled'    => ! empty( $rule['enabled'] ),
			'pattern'    => $pattern,
			'methods'    => $methods,
			'mode'       => $mode,
			'capability' => $capability,
			'note'       => isset( $rule['note'] ) ? sanitize_text_field( (string) $rule['note'] ) : '',
			'created_at' => isset( $rule['created_at'] ) ? sanitize_text_field( (string) $rule['created_at'] ) : gmdate( 'c' ),
		);
	}

	/**
	 * Generate compact rule ID.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return string
	 */
	private static function generate_rule_id( array $rule ) {
		return substr( sha1( wp_json_encode( $rule ) . '|' . microtime( true ) . '|' . wp_rand() ), 0, 12 );
	}

	/**
	 * Block REST requests when a manual rule or safe mode rule applies.
	 *
	 * @param mixed           $result Current result.
	 * @param WP_REST_Server  $server REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public static function maybe_block_request( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return $result;
		}

		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		foreach ( $options['rules'] as $rule ) {
			if ( empty( $rule['enabled'] ) || ! self::rule_matches_request( $rule, $route, $method ) ) {
				continue;
			}

			if ( self::rule_blocks_current_user( $rule ) ) {
				$reason = self::describe_rule( $rule );
				self::log_block( $route, $method, $reason, $rule['id'], $options );
				return self::blocked_error( $reason );
			}
		}

		if ( ! empty( $options['auto_safe_mode'] ) ) {
			$auto_reason = self::auto_safe_mode_reason( $route, $method, $options );
			if ( $auto_reason ) {
				self::log_block( $route, $method, $auto_reason, 'auto-safe-mode', $options );
				return self::blocked_error( $auto_reason );
			}
		}

		return $result;
	}

	/**
	 * Decide whether a manual rule matches the route/method.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @param string              $route Route.
	 * @param string              $method Method.
	 * @return bool
	 */
	private static function rule_matches_request( array $rule, $route, $method ) {
		$methods = isset( $rule['methods'] ) && is_array( $rule['methods'] ) ? $rule['methods'] : array( 'ANY' );
		if ( ! in_array( 'ANY', $methods, true ) && ! in_array( $method, $methods, true ) ) {
			return false;
		}

		return self::pattern_matches( $rule['pattern'] ?? '', $route );
	}

	/**
	 * Match wildcard pattern against a route.
	 *
	 * @param string $pattern Pattern.
	 * @param string $route Route.
	 * @return bool
	 */
	public static function pattern_matches( $pattern, $route ) {
		$pattern = trim( (string) $pattern );
		$route   = trim( (string) $route );

		if ( '' === $pattern ) {
			return false;
		}

		if ( $pattern === $route ) {
			return true;
		}

		$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
		return 1 === preg_match( $regex, $route );
	}

	/**
	 * Determine if the current user should be blocked by a rule.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return bool
	 */
	private static function rule_blocks_current_user( array $rule ) {
		$mode = isset( $rule['mode'] ) ? (string) $rule['mode'] : 'admins_only';

		if ( 'disable_route' === $mode ) {
			return true;
		}

		if ( 'block_guests' === $mode || 'require_login' === $mode ) {
			return ! is_user_logged_in();
		}

		if ( 'admins_only' === $mode ) {
			return ! current_user_can( 'manage_options' );
		}

		if ( 'capability' === $mode ) {
			$capability = ! empty( $rule['capability'] ) ? (string) $rule['capability'] : 'manage_options';
			return ! current_user_can( $capability );
		}

		return false;
	}

	/**
	 * Return a human-readable rule reason.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return string
	 */
	private static function describe_rule( array $rule ) {
		$mode = isset( $rule['mode'] ) ? (string) $rule['mode'] : 'admins_only';
		$pattern = isset( $rule['pattern'] ) ? (string) $rule['pattern'] : '';

		if ( 'disable_route' === $mode ) {
			return sprintf( __( 'Manual shield rule disabled route pattern %s.', 'rest-radar' ), $pattern );
		}

		if ( 'block_guests' === $mode || 'require_login' === $mode ) {
			return sprintf( __( 'Manual shield rule requires login for route pattern %s.', 'rest-radar' ), $pattern );
		}

		if ( 'capability' === $mode ) {
			return sprintf( __( 'Manual shield rule requires capability %1$s for route pattern %2$s.', 'rest-radar' ), $rule['capability'] ?? 'manage_options', $pattern );
		}

		return sprintf( __( 'Manual shield rule requires administrator access for route pattern %s.', 'rest-radar' ), $pattern );
	}

	/**
	 * Determine if auto safe mode should block the request.
	 *
	 * @param string              $route Route.
	 * @param string              $method Method.
	 * @param array<string,mixed> $options Shield options.
	 * @return string
	 */
	private static function auto_safe_mode_reason( $route, $method, array $options ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '';
		}

		if ( ! class_exists( 'Rest_Radar_Scanner' ) ) {
			return '';
		}

		$cache_key = 'rest_radar_auto_safe_scan_v' . REST_RADAR_VERSION;
		$rows      = get_transient( $cache_key );

		if ( ! is_array( $rows ) ) {
			$rows = Rest_Radar_Scanner::scan();
			set_transient( $cache_key, $rows, 60 );
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['route'] ) || (string) $row['route'] !== (string) $route ) {
				continue;
			}

			$methods = isset( $row['methods'] ) && is_array( $row['methods'] ) ? $row['methods'] : array();
			if ( ! in_array( $method, $methods, true ) ) {
				continue;
			}

			$source_category = isset( $row['source']['category'] ) ? (string) $row['source']['category'] : 'unknown';
			if ( 'core' === $source_category && empty( $options['include_core'] ) ) {
				continue;
			}

			$level = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
			if ( in_array( $level, array( 'critical', 'high' ), true ) ) {
				return sprintf( __( 'REST Radar Safe Mode blocked a %1$s risk endpoint: %2$s.', 'rest-radar' ), $level, $route );
			}
		}

		return '';
	}

	/**
	 * Build a WP_Error for blocked REST requests.
	 *
	 * @param string $reason Reason.
	 * @return WP_Error
	 */
	private static function blocked_error( $reason ) {
		return new WP_Error(
			'rest_radar_shield_blocked',
			__( 'REST Radar Shield blocked this REST API request.', 'rest-radar' ),
			array(
				'status' => 403,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Log a blocked request.
	 *
	 * @param string              $route Route.
	 * @param string              $method Method.
	 * @param string              $reason Reason.
	 * @param string              $rule_id Rule ID.
	 * @param array<string,mixed> $options Options.
	 * @return void
	 */
	private static function log_block( $route, $method, $reason, $rule_id, array $options ) {
		if ( empty( $options['log_enabled'] ) ) {
			return;
		}

		$logs = get_option( self::LOG_OPTION_NAME, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		array_unshift(
			$logs,
			array(
				'time'    => gmdate( 'c' ),
				'route'   => sanitize_text_field( (string) $route ),
				'method'  => sanitize_key( (string) $method ),
				'reason'  => sanitize_text_field( (string) $reason ),
				'rule_id' => sanitize_key( (string) $rule_id ),
				'user_id' => get_current_user_id(),
				'ip'      => self::get_remote_ip( ! empty( $options['anonymize_ip'] ) ),
			)
		);

		$logs = array_slice( $logs, 0, 100 );
		update_option( self::LOG_OPTION_NAME, $logs, false );
	}

	/**
	 * Get recent logs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_logs() {
		$logs = get_option( self::LOG_OPTION_NAME, array() );
		return is_array( $logs ) ? array_slice( $logs, 0, 25 ) : array();
	}

	/**
	 * Clear shield logs.
	 *
	 * @return void
	 */
	public static function clear_logs() {
		delete_option( self::LOG_OPTION_NAME );
	}

	/**
	 * Get remote IP, sanitized and privacy-limited.
	 *
	 * @return string
	 */
	private static function get_remote_ip( $anonymize = true ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = trim( $ip );

		if ( '' === $ip || empty( $anonymize ) ) {
			return $ip;
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return '';
			}

			$bytes = unpack( 'C*', $packed );
			if ( ! is_array( $bytes ) ) {
				return '';
			}

			for ( $i = 9; $i <= 16; $i++ ) {
				$bytes[ $i ] = 0;
			}

			return inet_ntop( pack( 'C*', ...array_values( $bytes ) ) );
		}

		return '';
	}

	/**
	 * Human label for mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		$labels = array(
			'block_guests'  => __( 'Block guests only', 'rest-radar' ),
			'require_login' => __( 'Require logged-in user', 'rest-radar' ),
			'admins_only'   => __( 'Allow administrators only', 'rest-radar' ),
			'capability'    => __( 'Require selected capability', 'rest-radar' ),
			'disable_route' => __( 'Disable route completely', 'rest-radar' ),
		);

		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}
}
