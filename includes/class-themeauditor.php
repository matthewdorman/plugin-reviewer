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

	/** @var array<int,string> */
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
			array( 'slug' => $stylesheet, 'role' => $stylesheet === $template ? 'parent' : 'child', 'path' => get_stylesheet_directory(), 'version' => (string) $active->get( 'Version' ) ),
		);
		if ( $template !== $stylesheet ) {
			$themes[] = array( 'slug' => $template, 'role' => 'parent', 'path' => get_template_directory(), 'version' => (string) $parent->get( 'Version' ) );
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
			'status' => 'complete', 'themes' => array(), 'findings' => array(),
			'coverage' => array( 'files_discovered' => 0, 'files_scanned' => 0, 'bytes_scanned' => 0, 'skipped' => array(), 'errors' => array(), 'limits' => array() ),
		);
		$seen = array();
		foreach ( $themes as $theme ) {
			$summary = array( 'slug' => $theme['slug'], 'role' => $theme['role'], 'files' => 0, 'loc' => 0, 'namespaces' => 0, 'classes' => 0, 'interfaces' => 0, 'traits' => 0, 'functions' => 0, 'methods' => 0 );
			$files   = $this->discover( $theme, $result['coverage'], $started );
			foreach ( $files as $file ) {
				if ( microtime( true ) - $started > self::MAX_SECONDS ) {
					$result['coverage']['limits'][] = 'Scan time limit reached.';
					break 2;
				}
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only bounded local source inspection.
				$source = @file_get_contents( $file['absolute'] );
				if ( false === $source ) {
					$result['coverage']['errors'][] = $file['relative'] . ': source could not be read';
					continue;
				}
				$analysis = $this->analyze( $source, $file, $theme );
				++$summary['files'];
				$summary['loc'] += substr_count( $source, "\n" ) + 1;
				foreach ( array( 'namespaces', 'classes', 'interfaces', 'traits', 'functions', 'methods' ) as $metric ) {
					$summary[ $metric ] += $analysis['counts'][ $metric ];
				}
				foreach ( $analysis['findings'] as $finding ) {
					$key = implode( '|', array( $theme['role'], $theme['slug'], $finding['file'], $finding['line'], $finding['category'], $finding['name'], $finding['callback'] ) );
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ] = true;
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

	/** Discover contained regular PHP files without following links. */
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
				$files[] = array( 'absolute' => $real, 'relative' => str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) ) ) );
			}
		} catch ( \UnexpectedValueException $error ) {
			$coverage['errors'][] = $theme['slug'] . ': directory traversal failed';
		}
		usort( $files, static function ( $a, $b ) {
			if ( 'functions.php' === $a['relative'] ) { return -1; }
			if ( 'functions.php' === $b['relative'] ) { return 1; }
			return strcmp( $a['relative'], $b['relative'] );
		} );
		return $files;
	}

	/** Tokenize declarations and literal API registrations. */
	private function analyze( $source, $file, $theme ) {
		$tokens = token_get_all( $source );
		$counts = array( 'namespaces' => 0, 'classes' => 0, 'interfaces' => 0, 'traits' => 0, 'functions' => 0, 'methods' => 0 );
		$findings = array();
		$class = '';
		$brace = 0;
		$class_brace = null;
		$apis = array(
			'add_action' => 'hook', 'add_filter' => 'hook', 'add_shortcode' => 'shortcode', 'register_rest_route' => 'rest_route',
			'register_post_type' => 'post_type', 'register_taxonomy' => 'taxonomy', 'register_block_type' => 'block',
			'register_widget' => 'widget', 'wp_schedule_event' => 'cron', 'wp_schedule_single_event' => 'cron',
			'add_menu_page' => 'admin_page', 'add_submenu_page' => 'admin_page', 'get_option' => 'options_api',
			'update_option' => 'options_api', 'add_option' => 'options_api', 'delete_option' => 'options_api',
		);
		$count = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			$t = $tokens[ $i ];
			if ( '{' === $t ) { ++$brace; }
			if ( '}' === $t ) { --$brace; if ( null !== $class_brace && $brace < $class_brace ) { $class = ''; $class_brace = null; } }
			if ( ! is_array( $t ) ) { continue; }
			if ( T_NAMESPACE === $t[0] ) {
				++$counts['namespaces'];
				$findings[] = $this->finding( $theme, $file, $t[2], 'namespace_declaration', $this->next_name( $tokens, $i + 1 ), '', '', 'declaration' );
			}
			if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				$metric = T_CLASS === $t[0] ? 'classes' : ( T_INTERFACE === $t[0] ? 'interfaces' : 'traits' );
				++$counts[ $metric ];
				$class = $this->next_name( $tokens, $i + 1 );
				$class_brace = $brace + 1;
				$declaration_category = 'classes' === $metric ? 'class_declaration' : ( 'interfaces' === $metric ? 'interface_declaration' : 'trait_declaration' );
				$findings[] = $this->finding( $theme, $file, $t[2], $declaration_category, $class, '', '', 'declaration' );
			}
			if ( T_FUNCTION === $t[0] ) {
				$name = $this->next_name( $tokens, $i + 1 );
				++$counts[ '' === $class ? 'functions' : 'methods' ];
				if ( '' !== $name ) {
					$findings[] = $this->finding( $theme, $file, $t[2], '' === $class ? 'function_declaration' : 'method_declaration', $name, '', $class, 'declaration' );
				}
			}
			if ( T_INCLUDE === $t[0] || T_INCLUDE_ONCE === $t[0] || T_REQUIRE === $t[0] || T_REQUIRE_ONCE === $t[0] ) {
				$literal = $this->next_literal( $tokens, $i + 1 );
				$findings[] = $this->finding( $theme, $file, $t[2], 'literal_include', $literal ?: '(dynamic expression)', '', $class, $literal ? 'literal' : 'unresolved' );
			}
			if ( T_VARIABLE === $t[0] && '$wpdb' === $t[1] ) {
				$findings[] = $this->finding( $theme, $file, $t[2], 'database_api', '$wpdb access', '', $class, 'static token evidence' );
			}
			if ( T_STRING === $t[0] && isset( $apis[ strtolower( $t[1] ) ] ) && $this->next_symbol( $tokens, $i + 1 ) === '(' ) {
				$args = $this->call_args( $tokens, $i + 1 );
				$name = isset( $args[0] ) && '' !== $args[0]['literal'] ? $args[0]['literal'] : '(dynamic expression)';
				$callback = isset( $args[1] ) ? $args[1]['callback'] : '';
				$resolution = '' === $callback ? ( isset( $args[1] ) ? 'unresolved' : 'not_applicable' ) : 'literal';
				$category = $apis[ strtolower( $t[1] ) ];
				if ( 'hook' === $category && 0 === strpos( $name, 'wp_ajax_' ) ) { $category = 'ajax'; }
				$findings[] = $this->finding( $theme, $file, $t[2], $category, $name, $callback, $class, $resolution );
			}
		}
		$loc = substr_count( $source, "\n" ) + 1;
		if ( 'functions.php' === $file['relative'] && ( $loc >= 1000 || count( $findings ) >= 25 ) ) {
			$findings[] = $this->finding( $theme, $file, 1, 'descriptive_signal', 'monolithic functions.php', '', '', 'descriptive_only: ' . $loc . ' LOC; ' . count( $findings ) . ' registrations/API calls; not a vulnerability');
		}
		return array( 'counts' => $counts, 'findings' => $findings );
	}

	private function next_name( $tokens, $start ) {
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			if ( is_array( $tokens[ $i ] ) && ( T_STRING === $tokens[ $i ][0] || ( defined( 'T_NAME_QUALIFIED' ) && constant( 'T_NAME_QUALIFIED' ) === $tokens[ $i ][0] ) ) ) { return $tokens[ $i ][1]; }
			if ( '{' === $tokens[ $i ] || '(' === $tokens[ $i ] ) { break; }
		}
		return '';
	}

	private function next_symbol( $tokens, $start ) {
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) { if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; } return $tokens[ $i ]; }
		return '';
	}

	private function next_literal( $tokens, $start ) {
		$expression = array();
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			if ( ';' === $tokens[ $i ] ) { break; }
			$expression[] = $tokens[ $i ];
		}
		$parsed = $this->parse_arg( $expression );
		return $parsed['literal'];
	}

	/** Extract simple top-level call arguments; never evaluates expressions. */
	private function call_args( $tokens, $start ) {
		$args = array(); $depth = 0; $current = array();
		for ( $i = $start, $n = count( $tokens ); $i < $n; ++$i ) {
			$t = $tokens[ $i ];
			if ( '(' === $t || '[' === $t ) { ++$depth; if ( $depth > 1 ) { $current[] = $t; } continue; }
			if ( ')' === $t || ']' === $t ) { --$depth; if ( 0 === $depth ) { $args[] = $this->parse_arg( $current ); break; } $current[] = $t; continue; }
			if ( ',' === $t && 1 === $depth ) { $args[] = $this->parse_arg( $current ); $current = array(); continue; }
			if ( $depth >= 1 ) { $current[] = $t; }
		}
		return $args;
	}

	private function parse_arg( $tokens ) {
		$literals = array(); $text = '';
		foreach ( $tokens as $t ) { $text .= is_array( $t ) ? $t[1] : $t; if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) { $literals[] = trim( $t[1], "'\""); } }
		$trim = trim( $text );
		$literal = ( 1 === count( $literals ) && preg_match( '/^[\s\'\"]*' . preg_quote( $literals[0], '/' ) . '[\s\'\"]*$/', $trim ) ) ? $literals[0] : '';
		$callback = '';
		if ( 1 === count( $literals ) && preg_match( '/^[\s\'\"]/', $trim ) ) { $callback = $literals[0]; }
		if ( count( $literals ) >= 2 && ( false !== strpos( $trim, 'array' ) || false !== strpos( $trim, '[' ) ) ) { $callback = $literals[0] . '::' . $literals[1]; }
		if ( count( $literals ) >= 1 && false !== strpos( $trim, '$this' ) ) { $callback = '$this::' . end( $literals ); }
		return array( 'literal' => $literal, 'callback' => $callback );
	}

	private function finding( $theme, $file, $line, $category, $name, $callback, $owner, $resolution ) {
		return array( 'theme' => $theme['slug'], 'role' => $theme['role'], 'file' => $file['relative'], 'line' => (int) $line, 'category' => $category, 'name' => $name, 'callback' => $callback, 'owner' => $owner, 'resolution' => $resolution );
	}

	/** Link callbacks only when an exact literal declaration exists in the same attributed theme. */
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
			if ( '' === $finding['callback'] ) { continue; }
			$callback = str_replace( '$this::', $finding['owner'] . '::', $finding['callback'] );
			$key = $finding['theme'] . '|' . ( false === strpos( $callback, '::' ) ? 'function|' : 'method|' ) . $callback;
			$finding['resolution'] = isset( $declarations[ $key ] ) ? 'linked declaration at ' . $declarations[ $key ] : 'literal reference; declaration not proven';
		}
		unset( $finding );
	}
}
