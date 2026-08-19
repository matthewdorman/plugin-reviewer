<?php
/**
 * Bounded, read-only theme source inventory.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventories PHP source without loading or executing theme code.
 */
class ThemeAuditor {
	const CACHE_VERSION   = '1';
	const MAX_FILES       = 1000;
	const MAX_FILE_BYTES  = 1048576;
	const MAX_TOTAL_BYTES = 10485760;
	const MAX_SECONDS     = 8;

	/**
	 * Directory basenames intentionally excluded from traversal.
	 *
	 * @var array<int,string>
	 */
	private $excluded = array( '.git', 'node_modules', 'vendor', 'dist', 'build', 'cache' );

	/**
	 * Audit the active child and parent themes separately.
	 *
	 * @return array<string,mixed>
	 */
	public function audit() {
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$active     = wp_get_theme( $stylesheet );
		$parent     = wp_get_theme( $template );
		$themes     = array(
			array(
				'slug'    => $stylesheet,
				'role'    => $stylesheet === $template ? 'parent' : 'child',
				'path'    => get_stylesheet_directory(),
				'version' => (string) $active->get( 'Version' ),
			),
		);
		if ( $template !== $stylesheet ) {
			$themes[] = array(
				'slug'    => $template,
				'role'    => 'parent',
				'path'    => get_template_directory(),
				'version' => (string) $parent->get( 'Version' ),
			);
		}

		$cache_key = 'plugin_reviewer_theme_' . md5( self::CACHE_VERSION . '|' . wp_json_encode( $themes ) . '|' . PLUGIN_REVIEWER_VERSION );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->audit_roots( $themes );
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Scan explicit roots. Public for deterministic fixture testing.
	 *
	 * @param array<int,array<string,string>> $themes Theme roots.
	 * @return array<string,mixed>
	 */
	public function audit_roots( $themes ) {
		$started = microtime( true );
		$result  = array(
			'status'   => 'complete',
			'themes'   => array(),
			'findings' => array(),
			'coverage' => array(
				'files_discovered' => 0,
				'files_scanned'    => 0,
				'bytes_scanned'    => 0,
				'skipped'          => array(),
				'errors'           => array(),
				'limits'           => array(),
			),
		);
		$seen    = array();
		foreach ( $themes as $theme ) {
			$summary = array(
				'slug'       => $theme['slug'],
				'role'       => $theme['role'],
				'files'      => 0,
				'loc'        => 0,
				'namespaces' => 0,
				'classes'    => 0,
				'interfaces' => 0,
				'traits'     => 0,
				'functions'  => 0,
				'methods'    => 0,
			);
			$files   = $this->discover( $theme, $result['coverage'], $started );
			foreach ( $files as $file ) {
				if ( microtime( true ) - $started > self::MAX_SECONDS ) {
					$result['coverage']['limits'][] = 'Scan time limit reached.';
					break 2;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is converted to explicit coverage evidence.
				$size = @filesize( $file['absolute'] );
				if ( false === $size ) {
					$result['coverage']['errors'][] = $file['relative'] . ': size could not be read';
					continue;
				}
				if ( $size > self::MAX_FILE_BYTES ) {
					$result['coverage']['skipped'][] = $file['relative'] . ': larger than 1 MiB';
					continue;
				}
				if ( $result['coverage']['bytes_scanned'] + $size > self::MAX_TOTAL_BYTES ) {
					$result['coverage']['limits'][] = '10 MiB total source limit reached.';
					break 2;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Read-only bounded local source inspection; failure is reported.
				$source = @file_get_contents( $file['absolute'] );
				if ( false === $source ) {
					$result['coverage']['errors'][] = $file['relative'] . ': source could not be read';
					continue;
				}
				try {
					$analysis = $this->analyze( $source, $file, $theme );
				} catch ( \ParseError $error ) {
					$result['coverage']['errors'][] = $file['relative'] . ': PHP parse failure';
					continue;
				}
				++$summary['files'];
				$summary['loc'] += substr_count( $source, "\n" ) + 1;
				foreach ( array( 'namespaces', 'classes', 'interfaces', 'traits', 'functions', 'methods' ) as $metric ) {
					$summary[ $metric ] += $analysis['counts'][ $metric ];
				}
				foreach ( $analysis['findings'] as $finding ) {
					$key = implode( '|', array( $theme['role'], $theme['slug'], $finding['file'], $finding['line'], $finding['category'], $finding['name'], $finding['callback'] ) );
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ]         = true;
						$result['findings'][] = $finding;
					}
				}
				++$result['coverage']['files_scanned'];
				$result['coverage']['bytes_scanned'] += $size;
			}
			$result['themes'][] = $summary;
		}
		if ( $result['coverage']['skipped'] || $result['coverage']['errors'] || $result['coverage']['limits'] ) {
			$result['status'] = 'incomplete';
		}
		$this->link_literal_callbacks( $result['findings'] );
		return $result;
	}

	/**
	 * Discover contained regular PHP files without following links.
	 *
	 * @param array<string,string> $theme    Theme identity and root.
	 * @param array<string,mixed>  $coverage Coverage evidence, updated by reference.
	 * @param float                $started  Scan start timestamp.
	 * @return array<int,array<string,string>>
	 */
	private function discover( $theme, &$coverage, $started ) {
		$root = realpath( $theme['path'] );
		if ( false === $root || ! is_dir( $root ) ) {
			$coverage['errors'][] = $theme['slug'] . ': theme root unavailable';
			return array();
		}
		$root  = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$files = array();
		$flags = \FilesystemIterator::SKIP_DOTS;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $root, $flags ),
					function ( $current ) use ( &$coverage ) {
						if ( $current->isLink() ) {
							$coverage['skipped'][] = $current->getFilename() . ': symbolic link excluded';
							return false;
						}
						if ( $current->isDir() && in_array( $current->getFilename(), $this->excluded, true ) ) {
							$coverage['skipped'][] = $current->getFilename() . ': excluded directory';
							return false;
						}
						return true;
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $item ) {
				if ( microtime( true ) - $started > self::MAX_SECONDS ) {
					$coverage['limits'][] = 'Scan time limit reached during discovery.';
					break;
				}
				if ( ! $item->isFile() || 'php' !== strtolower( $item->getExtension() ) ) {
					continue;
				}
				$real = $item->getRealPath();
				if ( false === $real || 0 !== strpos( $real, $root ) ) {
					$coverage['skipped'][] = $item->getFilename() . ': outside theme root';
					continue;
				}
				if ( $coverage['files_discovered'] >= self::MAX_FILES ) {
					$coverage['limits'][] = '1,000 PHP file limit reached.';
					break;
				}
				++$coverage['files_discovered'];
				$files[] = array(
					'absolute' => $real,
					'relative' => str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) ) ),
				);
			}
		} catch ( \UnexpectedValueException $error ) {
			$coverage['errors'][] = $theme['slug'] . ': directory traversal failed';
		}
		usort(
			$files,
			static function ( $a, $b ) {
				if ( 'functions.php' === $a['relative'] ) {
					return -1; }
				if ( 'functions.php' === $b['relative'] ) {
					return 1; }
				return strcmp( $a['relative'], $b['relative'] );
			}
		);
		return $files;
	}

	/**
	 * Tokenize declarations and literal API registrations.
	 *
	 * @param string               $source Theme PHP source.
	 * @param array<string,string> $file   File identity.
	 * @param array<string,string> $theme  Theme identity.
	 * @return array<string,mixed>
	 */
	private function analyze( $source, $file, $theme ) {
		$tokens      = token_get_all( $source, TOKEN_PARSE );
		$counts      = array(
			'namespaces' => 0,
			'classes'    => 0,
			'interfaces' => 0,
			'traits'     => 0,
			'functions'  => 0,
			'methods'    => 0,
		);
		$findings    = array();
		$class       = '';
		$namespace   = '';
		$brace       = 0;
		$class_brace = null;
		$apis        = array(
			'add_action'               => array(
				'category' => 'hook',
				'name'     => 0,
				'callback' => 1,
			),
			'add_filter'               => array(
				'category' => 'hook',
				'name'     => 0,
				'callback' => 1,
			),
			'add_shortcode'            => array(
				'category' => 'shortcode',
				'name'     => 0,
				'callback' => 1,
			),
			'register_rest_route'      => array(
				'category' => 'rest_route',
				'name'     => 'rest',
			),
			'register_post_type'       => array(
				'category' => 'post_type',
				'name'     => 0,
			),
			'register_taxonomy'        => array(
				'category' => 'taxonomy',
				'name'     => 0,
			),
			'register_block_type'      => array(
				'category' => 'block',
				'name'     => 0,
			),
			'register_widget'          => array(
				'category' => 'widget',
				'name'     => 0,
			),
			'register_sidebar'         => array(
				'category' => 'widget_area',
				'name'     => 0,
			),
			'wp_schedule_event'        => array(
				'category' => 'cron',
				'name'     => 2,
			),
			'wp_schedule_single_event' => array(
				'category' => 'cron',
				'name'     => 1,
			),
			'add_menu_page'            => array(
				'category' => 'admin_page',
				'name'     => 1,
				'callback' => 4,
			),
			'add_submenu_page'         => array(
				'category' => 'admin_page',
				'name'     => 2,
				'callback' => 5,
			),
			'get_option'               => array(
				'category' => 'options_api',
				'name'     => 0,
			),
			'update_option'            => array(
				'category' => 'options_api',
				'name'     => 0,
			),
			'add_option'               => array(
				'category' => 'options_api',
				'name'     => 0,
			),
			'delete_option'            => array(
				'category' => 'options_api',
				'name'     => 0,
			),
		);
		$count       = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			$t = $tokens[ $i ];
			if ( '{' === $t ) {
				++$brace; }
			if ( '}' === $t ) {
				--$brace;
				if ( null !== $class_brace && $brace < $class_brace ) {
					$class       = '';
					$class_brace = null; }
			}
			if ( ! is_array( $t ) ) {
				continue; }
			if ( T_NAMESPACE === $t[0] ) {
				++$counts['namespaces'];
				$namespace  = trim( $this->next_namespace( $tokens, $i + 1 ), '\\' );
				$findings[] = $this->finding( $theme, $file, $t[2], 'namespace_declaration', $namespace, '', '', 'declaration' );
			}
			if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				$metric = T_CLASS === $t[0] ? 'classes' : ( T_INTERFACE === $t[0] ? 'interfaces' : 'traits' );
				++$counts[ $metric ];
				$class = $this->next_name( $tokens, $i + 1 );
				if ( '' !== $namespace && '' !== $class ) {
					$class = $namespace . '\\' . $class; }
				$class_brace          = $brace + 1;
				$declaration_category = 'classes' === $metric ? 'class_declaration' : ( 'interfaces' === $metric ? 'interface_declaration' : 'trait_declaration' );
				$findings[]           = $this->finding( $theme, $file, $t[2], $declaration_category, $class, '', '', 'declaration' );
			}
			if ( T_FUNCTION === $t[0] ) {
				$name = $this->next_name( $tokens, $i + 1 );
				++$counts[ '' === $class ? 'functions' : 'methods' ];
				if ( '' !== $name ) {
					$findings[] = $this->finding( $theme, $file, $t[2], '' === $class ? 'function_declaration' : 'method_declaration', $name, '', $class, 'declaration' );
				}
			}
			if ( T_INCLUDE === $t[0] || T_INCLUDE_ONCE === $t[0] || T_REQUIRE === $t[0] || T_REQUIRE_ONCE === $t[0] ) {
				$literal    = $this->next_literal( $tokens, $i + 1 );
				$findings[] = $this->finding( $theme, $file, $t[2], 'literal_include', $literal ? $literal : '(dynamic expression)', '', $class, $literal ? 'literal' : 'unresolved' );
			}
			if ( T_VARIABLE === $t[0] && '$wpdb' === $t[1] ) {
				$findings[] = $this->finding( $theme, $file, $t[2], 'database_api', '$wpdb access', '', $class, 'static token evidence' );
			}
			$api_name = strtolower( $t[1] );
			if ( T_STRING === $t[0] && isset( $apis[ $api_name ] ) && ! $this->is_non_function_call( $tokens, $i - 1 ) && $this->next_symbol( $tokens, $i + 1 ) === '(' ) {
				$args   = $this->call_args( $tokens, $i + 1 );
				$config = $apis[ $api_name ];
				if ( 'rest' === $config['name'] ) {
					$namespace_arg = isset( $args[0] ) ? $args[0]['literal'] : '';
					$route_arg     = isset( $args[1] ) ? $args[1]['literal'] : '';
					$name          = $namespace_arg && $route_arg ? rtrim( $namespace_arg, '/' ) . '/' . ltrim( $route_arg, '/' ) : '(dynamic expression)';
				} else {
					$name_arg = $config['name'];
					$name     = isset( $args[ $name_arg ] ) && '' !== $args[ $name_arg ]['literal'] ? $args[ $name_arg ]['literal'] : '(dynamic expression)';
				}
				$callback_arg = isset( $config['callback'] ) ? $config['callback'] : null;
				$callback     = null !== $callback_arg && isset( $args[ $callback_arg ] ) ? $args[ $callback_arg ]['callback'] : '';
				$resolution   = null === $callback_arg ? 'not_applicable' : ( '' === $callback ? 'unresolved' : 'literal' );
				$category     = $config['category'];
				if ( 'hook' === $category && 0 === strpos( $name, 'wp_ajax_' ) ) {
					$category = 'ajax'; }
				$findings[] = $this->finding( $theme, $file, $t[2], $category, $name, $callback, $class, $resolution );
			}
		}
		$loc = substr_count( $source, "\n" ) + 1;
		if ( 'functions.php' === $file['relative'] && ( $loc >= 1000 || count( $findings ) >= 25 ) ) {
			$findings[] = $this->finding( $theme, $file, 1, 'descriptive_signal', 'monolithic functions.php', '', '', 'descriptive_only: ' . $loc . ' LOC; ' . count( $findings ) . ' registrations/API calls; not a vulnerability' );
		}
		return array(
			'counts'   => $counts,
			'findings' => $findings,
		);
	}

	/**
	 * Return the next declaration name token.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Starting index.
	 */
	private function next_name( $tokens, $start ) {
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			if ( is_array( $tokens[ $i ] ) && ( T_STRING === $tokens[ $i ][0] || ( defined( 'T_NAME_QUALIFIED' ) && constant( 'T_NAME_QUALIFIED' ) === $tokens[ $i ][0] ) ) ) {
				return $tokens[ $i ][1]; }
			if ( '{' === $tokens[ $i ] || '(' === $tokens[ $i ] ) {
				break; }
		}
		return '';
	}

	/**
	 * Return a complete namespace name across PHP token formats.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Starting index.
	 */
	private function next_namespace( $tokens, $start ) {
		$name = '';
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			$t = $tokens[ $i ];
			if ( ';' === $t || '{' === $t ) {
				break; }
			if ( is_array( $t ) && ( T_STRING === $t[0] || T_NS_SEPARATOR === $t[0] || ( defined( 'T_NAME_QUALIFIED' ) && constant( 'T_NAME_QUALIFIED' ) === $t[0] ) ) ) {
				$name .= $t[1]; }
		}
		return $name;
	}

	/**
	 * Return the next non-trivia token.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Starting index.
	 */
	private function next_symbol( $tokens, $start ) {
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			} return $tokens[ $i ]; }
		return '';
	}

	/**
	 * Determine whether a matching name is a method call or function declaration.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Token before the matching name.
	 */
	private function is_non_function_call( $tokens, $start ) {
		for ( $i = $start; $i >= 0; --$i ) {
			$token = $tokens[ $i ];
			$id    = is_array( $token ) ? $token[0] : $token;
			if ( is_array( $token ) && in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( T_OBJECT_OPERATOR === $id || T_DOUBLE_COLON === $id || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) === $id ) ) {
				return true;
			}
			if ( '&' === $id || ( defined( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) && constant( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) === $id ) || ( defined( 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ) && constant( 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ) === $id ) ) {
				continue;
			}
			return T_FUNCTION === $id;
		}
		return false;
	}

	/**
	 * Return a literal include operand, or an empty string for expressions.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Starting index.
	 */
	private function next_literal( $tokens, $start ) {
		$expression = array();
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			if ( ';' === $tokens[ $i ] ) {
				break; }
			$expression[] = $tokens[ $i ];
		}
		$parsed = $this->parse_arg( $expression );
		return $parsed['literal'];
	}

	/**
	 * Extract simple top-level call arguments; never evaluates expressions.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  Opening-parenthesis position.
	 * @return array<int,array<string,string>>
	 */
	private function call_args( $tokens, $start ) {
		$args    = array();
		$depth   = 0;
		$current = array();
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			$t = $tokens[ $i ];
			if ( '(' === $t || '[' === $t ) {
				++$depth;
				if ( $depth > 1 ) {
					$current[] = $t;
				} continue; }
			if ( ')' === $t || ']' === $t ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $this->parse_arg( $current );
					break;
				} $current[] = $t;
				continue; }
			if ( ',' === $t && 1 === $depth ) {
				$args[]  = $this->parse_arg( $current );
				$current = array();
				continue; }
			if ( $depth >= 1 ) {
				$current[] = $t; }
		}
		return $args;
	}

	/**
	 * Parse literal evidence from one argument token slice.
	 *
	 * @param array<int,mixed> $tokens Argument tokens.
	 */
	private function parse_arg( $tokens ) {
		$literals = array();
		$text     = '';
		foreach ( $tokens as $t ) {
			$text .= is_array( $t ) ? $t[1] : $t;
			if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$literals[] = $this->decode_literal( $t[1] ); }
		}
		$trim     = trim( $text );
		$literal  = ( 1 === count( $literals ) && preg_match( '/^[\s\'\"]*' . preg_quote( $literals[0], '/' ) . '[\s\'\"]*$/', $trim ) ) ? $literals[0] : '';
		$callback = '';
		if ( 1 === count( $literals ) && preg_match( '/^[\s\'\"]/', $trim ) ) {
			$callback = $literals[0]; }
		if ( count( $literals ) >= 2 && ( false !== strpos( $trim, 'array' ) || false !== strpos( $trim, '[' ) ) ) {
			$callback = $literals[0] . '::' . $literals[1]; }
		if ( count( $literals ) >= 1 && false !== strpos( $trim, '$this' ) ) {
			$callback = '$this::' . end( $literals ); }
		if ( count( $literals ) >= 1 && preg_match( '/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/', $trim, $matches ) ) {
			$callback = '@class:' . trim( $matches[1], '\\' ) . '::' . end( $literals ); }
		return array(
			'literal'  => $literal,
			'callback' => $callback,
		);
	}

	/**
	 * Decode a PHP quoted string token without evaluating code.
	 *
	 * @param string $literal Quoted token text.
	 */
	private function decode_literal( $literal ) {
		$quote = substr( $literal, 0, 1 );
		$value = substr( $literal, 1, -1 );
		if ( "'" === $quote ) {
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $value ); }
		return stripcslashes( $value );
	}

	/**
	 * Build a normalized finding row.
	 *
	 * @param array<string,string> $theme Theme identity.
	 * @param array<string,string> $file File identity.
	 * @param int                  $line Source line.
	 * @param string               $category Finding category.
	 * @param string               $name Evidence name.
	 * @param string               $callback Literal callback.
	 * @param string               $owner Owning declaration.
	 * @param string               $resolution Resolution evidence.
	 */
	private function finding( $theme, $file, $line, $category, $name, $callback, $owner, $resolution ) {
		return array(
			'theme'      => $theme['slug'],
			'role'       => $theme['role'],
			'file'       => $file['relative'],
			'line'       => (int) $line,
			'category'   => $category,
			'name'       => $name,
			'callback'   => $callback,
			'owner'      => $owner,
			'resolution' => $resolution,
		);
	}

	/**
	 * Link callbacks only when an exact literal declaration exists in the same theme.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings, updated by reference.
	 */
	private function link_literal_callbacks( &$findings ) {
		$declarations = array();
		foreach ( $findings as $finding ) {
			if ( 'function_declaration' === $finding['category'] ) {
				$declarations[ $finding['theme'] . '|function|' . $finding['name'] ] = $finding['file'] . ':' . $finding['line'];
			} elseif ( 'method_declaration' === $finding['category'] ) {
				$declarations[ $finding['theme'] . '|method|' . $finding['owner'] . '::' . $finding['name'] ] = $finding['file'] . ':' . $finding['line'];
			}
		}
		foreach ( $findings as &$finding ) {
			if ( '' === $finding['callback'] ) {
				continue; }
			$callback = str_replace( '$this::', $finding['owner'] . '::', $finding['callback'] );
			if ( 0 === strpos( $callback, '@class:' ) ) {
				$callback   = substr( $callback, 7 );
				$class_name = strstr( $callback, '::', true );
				if ( false !== $class_name && false === strpos( $class_name, '\\' ) && false !== strrpos( $finding['owner'], '\\' ) ) {
					$callback = substr( $finding['owner'], 0, strrpos( $finding['owner'], '\\' ) + 1 ) . $callback;
				}
				$finding['callback'] = $callback;
			}
			$key                   = $finding['theme'] . '|' . ( false === strpos( $callback, '::' ) ? 'function|' : 'method|' ) . $callback;
			$finding['resolution'] = isset( $declarations[ $key ] ) ? 'linked declaration at ' . $declarations[ $key ] : 'literal reference; declaration not proven';
		}
		unset( $finding );
	}
}
