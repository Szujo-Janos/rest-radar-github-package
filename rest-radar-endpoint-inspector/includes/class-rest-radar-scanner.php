<?php
/**
 * REST Radar scanner.
 *
 * @package RestRadarEndpointInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans registered REST API routes.
 */
class Rest_Radar_Scanner {
	/**
	 * Scan registered WordPress REST API routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function scan() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$server = rest_get_server();
		$routes = $server->get_routes();
		$rows   = array();

		foreach ( $routes as $route => $endpoints ) {
			if ( ! is_array( $endpoints ) ) {
				continue;
			}

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) ) {
					continue;
				}

				$methods                    = self::normalize_methods( $endpoint['methods'] ?? array() );
				$callback                   = $endpoint['callback'] ?? null;
				$permission_callback        = $endpoint['permission_callback'] ?? null;
				$callback_label             = self::callback_to_label( $callback );
				$callback_source            = self::callback_to_source( $callback );
				$permission_callback_label  = self::callback_to_label( $permission_callback );
				$permission_callback_source = self::callback_to_source( $permission_callback );
				$namespace                  = self::guess_namespace( (string) $route );
				$source                     = self::detect_source( $callback_source, $permission_callback_source );
				$sensitive_tags             = self::detect_sensitive_tags( (string) $route, $namespace, $callback_label, $permission_callback_label );
				$route_shape                = self::detect_route_shape( (string) $route );
				$risk                       = self::assess_risk( $methods, $permission_callback, $sensitive_tags, $route_shape );

				$route_key                  = self::build_row_key( (string) $route, $methods, $callback_label, $permission_callback_label );

				$rows[] = array(
					'key'                        => $route_key,
					'namespace'                  => $namespace,
					'route'                      => (string) $route,
					'route_shape'                => $route_shape,
					'can_probe_get'              => self::can_probe_get( $methods, $route_shape ),
					'methods'                    => $methods,
					'is_write'                   => self::has_write_methods( $methods ),
					'callback_label'             => $callback_label,
					'callback_source'            => $callback_source,
					'permission_callback_label'  => $permission_callback_label,
					'permission_callback_source' => $permission_callback_source,
					'source'                     => $source,
					'tags'                       => $sensitive_tags,
					'risk'                       => $risk,
					'recommendation'             => self::build_recommendation( $risk, $methods, $permission_callback, $sensitive_tags ),
				);
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$order = array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 2,
					'public'   => 3,
					'low'      => 4,
				);

				$a_score = $order[ $a['risk']['level'] ] ?? 99;
				$b_score = $order[ $b['risk']['level'] ] ?? 99;

				if ( $a_score !== $b_score ) {
					return $a_score <=> $b_score;
				}

				return strcmp( $a['route'], $b['route'] );
			}
		);

		return $rows;
	}


	/**
	 * Build a stable short row key for admin detail/probe actions.
	 *
	 * @param string            $route Route.
	 * @param array<int,string> $methods Methods.
	 * @param string            $callback_label Callback label.
	 * @param string            $permission_callback_label Permission callback label.
	 * @return string
	 */
	private static function build_row_key( $route, array $methods, $callback_label, $permission_callback_label ) {
		return substr( sha1( $route . '|' . implode( ',', $methods ) . '|' . $callback_label . '|' . $permission_callback_label ), 0, 16 );
	}

	/**
	 * Determine if REST Radar can safely offer an anonymous GET probe.
	 *
	 * @param array<int,string>  $methods Methods.
	 * @param array<string,mixed> $route_shape Route shape.
	 * @return bool
	 */
	private static function can_probe_get( array $methods, array $route_shape ) {
		return in_array( 'GET', $methods, true ) && empty( $route_shape['has_params'] );
	}

	/**
	 * Normalize endpoint methods.
	 *
	 * @param mixed $methods Raw methods from WP REST route registration.
	 * @return array<int,string>
	 */
	private static function normalize_methods( $methods ) {
		if ( is_string( $methods ) ) {
			$methods = preg_split( '/[|,\s]+/', $methods );
		} elseif ( is_array( $methods ) ) {
			$normalized = array();
			foreach ( $methods as $key => $value ) {
				if ( is_string( $key ) && true === (bool) $value ) {
					$normalized[] = $key;
				} elseif ( is_string( $value ) ) {
					$normalized[] = $value;
				}
			}
			$methods = $normalized;
		} else {
			$methods = array();
		}

		$methods = array_map( 'strtoupper', array_map( 'trim', $methods ) );
		$methods = array_filter(
			$methods,
			static function ( $method ) {
				return '' !== $method;
			}
		);

		return array_values( array_unique( $methods ) );
	}

	/**
	 * Guess namespace from route path.
	 *
	 * @param string $route REST route.
	 * @return string
	 */
	private static function guess_namespace( $route ) {
		$trimmed = trim( $route, '/' );
		if ( '' === $trimmed ) {
			return 'unknown';
		}

		$parts = explode( '/', $trimmed );
		if ( count( $parts ) >= 2 && preg_match( '/^v\d+$/i', $parts[1] ) ) {
			return $parts[0] . '/' . $parts[1];
		}

		if ( count( $parts ) >= 2 && preg_match( '/^\d+(?:\.\d+)?$/', $parts[1] ) ) {
			return $parts[0] . '/' . $parts[1];
		}

		return $parts[0];
	}

	/**
	 * Detect owner/source from callback paths.
	 *
	 * @param string $callback_source Main callback source.
	 * @param string $permission_source Permission callback source.
	 * @return array{category:string,label:string}
	 */
	private static function detect_source( $callback_source, $permission_source ) {
		$source = $callback_source ? $callback_source : $permission_source;

		if ( '' === $source ) {
			return array(
				'category' => 'unknown',
				'label'    => __( 'Unknown', 'rest-radar' ),
			);
		}

		$normalized = wp_normalize_path( $source );

		if ( false !== strpos( $normalized, 'wp-includes/' ) || false !== strpos( $normalized, 'wp-admin/' ) ) {
			return array(
				'category' => 'core',
				'label'    => __( 'WordPress core', 'rest-radar' ),
			);
		}

		if ( preg_match( '#wp-content/plugins/([^/]+)/#', $normalized, $matches ) ) {
			return array(
				'category' => 'plugin',
				'label'    => sprintf(
					/* translators: %s: plugin folder name. */
					__( 'Plugin: %s', 'rest-radar' ),
					sanitize_text_field( $matches[1] )
				),
			);
		}

		if ( preg_match( '#wp-content/mu-plugins/([^/]+)#', $normalized, $matches ) ) {
			return array(
				'category' => 'mu-plugin',
				'label'    => sprintf(
					/* translators: %s: mu-plugin file or folder name. */
					__( 'MU plugin: %s', 'rest-radar' ),
					sanitize_text_field( $matches[1] )
				),
			);
		}

		if ( preg_match( '#wp-content/themes/([^/]+)/#', $normalized, $matches ) ) {
			return array(
				'category' => 'theme',
				'label'    => sprintf(
					/* translators: %s: theme folder name. */
					__( 'Theme: %s', 'rest-radar' ),
					sanitize_text_field( $matches[1] )
				),
			);
		}

		return array(
			'category' => 'unknown',
			'label'    => __( 'Unknown', 'rest-radar' ),
		);
	}

	/**
	 * Detect potentially sensitive keywords in a route/callback.
	 *
	 * @param string $route Route.
	 * @param string $namespace Namespace.
	 * @param string $callback_label Main callback label.
	 * @param string $permission_callback_label Permission callback label.
	 * @return array<int,string>
	 */
	private static function detect_sensitive_tags( $route, $namespace, $callback_label, $permission_callback_label ) {
		$haystack = strtolower( $route . ' ' . $namespace . ' ' . $callback_label . ' ' . $permission_callback_label );
		$map      = array(
			'user'     => array( 'user', 'users', 'customer', 'member', 'profile', 'account' ),
			'auth'     => array( 'auth', 'login', 'password', 'token', 'secret', 'key', 'nonce', 'session' ),
			'settings' => array( 'setting', 'settings', 'option', 'options', 'config', 'configuration' ),
			'content'  => array( 'private', 'draft', 'media', 'upload', 'download', 'file', 'export', 'import' ),
			'action'   => array( 'delete', 'remove', 'update', 'save', 'create', 'insert' ),
			'business' => array( 'order', 'payment', 'license', 'subscription', 'webhook', 'email' ),
		);

		$tags = array();
		foreach ( $map as $tag => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$tags[] = $tag;
					break;
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Detect route parameters and regex-like route shape.
	 *
	 * @param string $route Route.
	 * @return array{has_params:bool,param_count:int}
	 */
	private static function detect_route_shape( $route ) {
		$param_count = 0;
		if ( preg_match_all( '/\(\?P<[^>]+>/', $route, $matches ) ) {
			$param_count = count( $matches[0] );
		}

		return array(
			'has_params'  => $param_count > 0,
			'param_count' => $param_count,
		);
	}

	/**
	 * Assess a REST endpoint risk level from methods and permission callback.
	 *
	 * @param array<int,string> $methods Methods.
	 * @param mixed             $permission_callback Permission callback.
	 * @param array<int,string> $sensitive_tags Sensitive tags.
	 * @param array<string,mixed> $route_shape Route shape.
	 * @return array{level:string,label:string,message:string}
	 */
	private static function assess_risk( array $methods, $permission_callback, array $sensitive_tags, array $route_shape ) {
		$is_write          = self::has_write_methods( $methods );
		$has_sensitive_tag = ! empty( $sensitive_tags );

		if ( empty( $permission_callback ) ) {
			if ( $is_write ) {
				return array(
					'level'   => 'critical',
					'label'   => __( 'Critical', 'rest-radar' ),
					'message' => __( 'Missing permission_callback on a write-capable route.', 'rest-radar' ),
				);
			}

			return array(
				'level'   => 'critical',
				'label'   => __( 'Critical', 'rest-radar' ),
				'message' => __( 'Missing permission_callback. The route should explicitly define access control.', 'rest-radar' ),
			);
		}

		if ( self::is_public_callback( $permission_callback ) ) {
			if ( $is_write ) {
				return array(
					'level'   => 'high',
					'label'   => __( 'High', 'rest-radar' ),
					'message' => __( 'Public permission_callback on a write-capable route.', 'rest-radar' ),
				);
			}

			if ( $has_sensitive_tag ) {
				return array(
					'level'   => 'medium',
					'label'   => __( 'Review', 'rest-radar' ),
					'message' => __( 'Public read route contains sensitive-looking keywords. Confirm that returned data is intentionally public.', 'rest-radar' ),
				);
			}

			return array(
				'level'   => 'public',
				'label'   => __( 'Public', 'rest-radar' ),
				'message' => __( 'Public read route. This can be valid, but confirm it does not expose private data.', 'rest-radar' ),
			);
		}

		if ( $is_write ) {
			return array(
				'level'   => 'medium',
				'label'   => __( 'Review', 'rest-radar' ),
				'message' => __( 'Write-capable route with a custom permission callback. Verify the capability checks.', 'rest-radar' ),
			);
		}

		if ( $has_sensitive_tag && ! empty( $route_shape['has_params'] ) ) {
			return array(
				'level'   => 'medium',
				'label'   => __( 'Review', 'rest-radar' ),
				'message' => __( 'Read route has permission logic and sensitive-looking parameters. Check whether object-level authorization is enforced.', 'rest-radar' ),
			);
		}

		return array(
			'level'   => 'low',
			'label'   => __( 'Low', 'rest-radar' ),
			'message' => __( 'Permission callback is present.', 'rest-radar' ),
		);
	}

	/**
	 * Build recommended action for the row.
	 *
	 * @param array<string,string> $risk Risk data.
	 * @param array<int,string>   $methods Methods.
	 * @param mixed               $permission_callback Permission callback.
	 * @param array<int,string>   $sensitive_tags Tags.
	 * @return string
	 */
	private static function build_recommendation( array $risk, array $methods, $permission_callback, array $sensitive_tags ) {
		$level    = isset( $risk['level'] ) ? $risk['level'] : 'low';
		$is_write = self::has_write_methods( $methods );

		if ( 'critical' === $level ) {
			return __( 'Add an explicit permission_callback. For private routes, use current_user_can() or a strict object-level permission check.', 'rest-radar' );
		}

		if ( 'high' === $level ) {
			return __( 'Do not use __return_true for write actions. Require a capability check and validate nonce/authentication flow.', 'rest-radar' );
		}

		if ( 'medium' === $level && $is_write ) {
			return __( 'Open the permission callback source and confirm capability checks, object ownership checks, and input validation.', 'rest-radar' );
		}

		if ( 'medium' === $level && ! empty( $sensitive_tags ) ) {
			return __( 'Confirm the response contains no private user, settings, token, file, payment, or business data.', 'rest-radar' );
		}

		if ( 'public' === $level ) {
			return __( 'Public read endpoint. Accept it only if the response is intentionally public and cache-safe.', 'rest-radar' );
		}

		return __( 'No immediate warning from REST Radar. Keep normal code review and testing.', 'rest-radar' );
	}

	/**
	 * Check whether methods contain write methods.
	 *
	 * @param array<int,string> $methods Methods.
	 * @return bool
	 */
	private static function has_write_methods( array $methods ) {
		return ! empty( array_intersect( $methods, array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ) );
	}

	/**
	 * Check whether a permission callback is explicitly public.
	 *
	 * @param mixed $callback Callback.
	 * @return bool
	 */
	private static function is_public_callback( $callback ) {
		if ( ! is_string( $callback ) ) {
			return false;
		}

		$public_callbacks = apply_filters(
			'rest_radar_public_permission_callbacks',
			array( '__return_true' )
		);

		if ( ! is_array( $public_callbacks ) ) {
			$public_callbacks = array( '__return_true' );
		}

		$public_callbacks = array_values( array_filter( array_map( 'strval', $public_callbacks ) ) );
		return in_array( $callback, $public_callbacks, true );
	}

	/**
	 * Convert callback to readable label.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_to_label( $callback ) {
		if ( empty( $callback ) ) {
			return 'missing';
		}

		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( $callback instanceof Closure ) {
			return 'Closure';
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class_or_object = $callback[0];
			$method          = $callback[1];
			$class           = is_object( $class_or_object ) ? get_class( $class_or_object ) : (string) $class_or_object;

			return $class . '::' . $method;
		}

		if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			return get_class( $callback ) . '::__invoke';
		}

		return 'unknown';
	}

	/**
	 * Convert callback to source file and line if possible.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_to_source( $callback ) {
		try {
			$reflection = null;

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			}

			if ( ! $reflection ) {
				return '';
			}

			$file = $reflection->getFileName();
			$line = $reflection->getStartLine();

			if ( ! $file ) {
				return '';
			}

			return self::make_relative_path( $file ) . ':' . $line;
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Make absolute path easier to read.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function make_relative_path( $path ) {
		$base = wp_normalize_path( ABSPATH );
		$path = wp_normalize_path( $path );

		if ( 0 === strpos( $path, $base ) ) {
			return ltrim( substr( $path, strlen( $base ) ), '/' );
		}

		return basename( $path );
	}
}
