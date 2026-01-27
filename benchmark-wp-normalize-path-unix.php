<?php
/**
 * Benchmark: wp_normalize_path() - Four Implementations (Unix-only paths)
 *
 * This variant tests with Unix-style paths only (no Windows or UNC paths).
 * This represents a typical Linux/macOS server environment.
 *
 * Path types tested:
 * - Clean Unix paths (already normalized)
 * - Unix paths with multiple slashes (need normalization)
 * - PHP stream wrappers
 *
 * Compares:
 * 1. Original - Current WordPress (regex-based, no cache)
 * 2. Simple Cache - Single array cache (unbounded)
 * 3. Segmented - Hot/warm two-tier cache (bounded)
 * 4. Dennis - Regex-free implementation (no cache)
 *
 * Usage: php benchmark-wp-normalize-path-unix.php
 */

/**
 * Helper: Check if path is a PHP stream wrapper.
 */
function wp_is_stream( $path ) {
	$scheme_separator = strpos( $path, '://' );
	if ( false === $scheme_separator ) {
		return false;
	}
	$stream = substr( $path, 0, $scheme_separator );
	return in_array( $stream, stream_get_wrappers(), true );
}

/**
 * Helper: Estimate memory usage of a string cache array.
 *
 * PHP 8+ on 64-bit systems (approximate):
 * - HashTable base: ~56 bytes
 * - Per bucket overhead: ~56 bytes
 * - Per zend_string overhead: ~40 bytes (header) + string length
 *
 * Each cache entry has: key (string) + value (string) + bucket
 */
function estimate_cache_memory( $cache ) {
	if ( empty( $cache ) ) {
		return 0;
	}

	$hashtable_base    = 56;
	$bucket_overhead   = 56;
	$zend_string_base  = 40;

	$memory = $hashtable_base;

	foreach ( $cache as $key => $value ) {
		// Bucket overhead
		$memory += $bucket_overhead;
		// Key string: header + content
		$memory += $zend_string_base + strlen( $key );
		// Value string: header + content
		$memory += $zend_string_base + strlen( $value );
	}

	return $memory;
}

/**
 * Helper: Estimate memory usage of a compact cache array.
 *
 * Like estimate_cache_memory(), but handles entries where value is `true`
 * (indicating key == normalized value, so no separate value string stored).
 *
 * Boolean `true` in PHP uses 16 bytes (zval structure) vs ~40+ bytes for a string.
 */
function estimate_cache_memory_compact( $cache ) {
	if ( empty( $cache ) ) {
		return 0;
	}

	$hashtable_base    = 56;
	$bucket_overhead   = 56;
	$zend_string_base  = 40;
	$bool_size         = 16;  // zval for boolean

	$memory = $hashtable_base;

	foreach ( $cache as $key => $value ) {
		// Bucket overhead
		$memory += $bucket_overhead;
		// Key string: header + content
		$memory += $zend_string_base + strlen( $key );
		// Value: boolean (16 bytes) or string (header + content)
		if ( true === $value ) {
			$memory += $bool_size;
		} else {
			$memory += $zend_string_base + strlen( $value );
		}
	}

	return $memory;
}

/**
 * ORIGINAL: Current WordPress wp_normalize_path() - regex-based, no cache.
 */
function wp_normalize_path_original( $path ) {
	$path    = (string) $path;
	$wrapper = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	return $wrapper . $path;
}

/**
 * SIMPLE CACHE: Single array cache (unbounded).
 *
 * Pass '__cache_stats__' to get cache statistics instead of normalizing.
 */
function wp_normalize_path_simple( $path ) {
	static $cache = array();

	// Return cache stats when requested.
	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		return $cache[ $path ];
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$cache[ $original_path ] = $wrapper . $path;
	return $cache[ $original_path ];
}

/**
 * SIMPLE COMPACT CACHE: Single array cache with deduplication (unbounded).
 *
 * Stores `true` instead of the normalized value when input equals output,
 * saving ~40 bytes + string length per entry for already-normalized paths.
 *
 * Pass '__cache_stats__' to get cache statistics instead of normalizing.
 */
function wp_normalize_path_simple_compact( $path ) {
	static $cache = array();

	// Return cache stats when requested.
	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		// If true, the path was already normalized (key == value).
		return true === $cache[ $path ] ? $path : $cache[ $path ];
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;

	// Store true if unchanged, otherwise store the normalized value.
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 1: Fast-path detection + compact storage.
 *
 * Checks if path needs normalization before doing any work.
 * For Unix paths: if starts with / and has no \ or //, it's already normalized.
 */
function wp_normalize_path_exp1_fastpath( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		return true === $cache[ $path ] ? $path : $cache[ $path ];
	}

	// Fast-path: Unix paths starting with / that have no \ or // are already normalized.
	if ( isset( $path[0] ) && '/' === $path[0] && false === strpos( $path, '\\' ) && false === strpos( $path, '//' ) ) {
		$cache[ $path ] = true;
		return $path;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 2: Avoid regex when no consecutive slashes.
 *
 * Only runs preg_replace if there are actually consecutive slashes.
 */
function wp_normalize_path_exp2_noregex( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		return true === $cache[ $path ] ? $path : $cache[ $path ];
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );

	// Only run regex if there are consecutive slashes.
	if ( false !== strpos( $path, '//' ) ) {
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
	}

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 3: Combined fast-path + no-regex + inline stream check.
 *
 * Combines multiple optimizations:
 * - Fast-path for already-normalized Unix paths
 * - Avoid regex when no consecutive slashes
 * - Inline stream wrapper check
 * - Skip Windows drive letter check (Unix-only)
 */
function wp_normalize_path_exp3_combined( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		return true === $cache[ $path ] ? $path : $cache[ $path ];
	}

	// Fast-path: Unix paths starting with / that have no \ or // are already normalized.
	if ( isset( $path[0] ) && '/' === $path[0] && false === strpos( $path, '\\' ) && false === strpos( $path, '//' ) ) {
		$cache[ $path ] = true;
		return $path;
	}

	$original_path = $path;
	$wrapper       = '';

	// Inline stream check: look for :// but not at position 1 (Windows drive).
	$scheme_pos = strpos( $path, '://' );
	if ( false !== $scheme_pos && $scheme_pos > 1 ) {
		$scheme = substr( $path, 0, $scheme_pos );
		if ( in_array( $scheme, stream_get_wrappers(), true ) ) {
			$wrapper = $scheme . '://';
			$path    = substr( $path, $scheme_pos + 3 );
		}
	}

	// Replace backslashes.
	if ( false !== strpos( $path, '\\' ) ) {
		$path = str_replace( '\\', '/', $path );
	}

	// Only run regex if there are consecutive slashes.
	if ( false !== strpos( $path, '//' ) ) {
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 4: Replace regex with loop-based slash normalization.
 *
 * Uses a while loop with str_replace instead of preg_replace.
 */
function wp_normalize_path_exp4_loopslash( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		return true === $cache[ $path ] ? $path : $cache[ $path ];
	}

	// Fast-path for already-normalized Unix paths.
	if ( isset( $path[0] ) && '/' === $path[0] && false === strpos( $path, '\\' ) && false === strpos( $path, '//' ) ) {
		$cache[ $path ] = true;
		return $path;
	}

	$original_path = $path;
	$wrapper       = '';

	// Inline stream check.
	$scheme_pos = strpos( $path, '://' );
	if ( false !== $scheme_pos && $scheme_pos > 1 ) {
		$scheme = substr( $path, 0, $scheme_pos );
		if ( in_array( $scheme, stream_get_wrappers(), true ) ) {
			$wrapper = $scheme . '://';
			$path    = substr( $path, $scheme_pos + 3 );
		}
	}

	// Replace backslashes.
	if ( false !== strpos( $path, '\\' ) ) {
		$path = str_replace( '\\', '/', $path );
	}

	// Replace consecutive slashes with loop (preserves leading slash).
	if ( false !== strpos( $path, '//' ) ) {
		// Preserve leading slash for network paths.
		$leading = '';
		if ( isset( $path[0] ) && '/' === $path[0] ) {
			$leading = '/';
			$path = ltrim( $path, '/' );
		}
		// Loop until no more double slashes.
		while ( false !== strpos( $path, '//' ) ) {
			$path = str_replace( '//', '/', $path );
		}
		$path = $leading . $path;
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 5: Optimized cache hit path - single variable lookup.
 *
 * Avoids accessing $cache[$path] twice by storing in local variable.
 */
function wp_normalize_path_exp5_hitopt( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		if ( true === $cached ) {
			return $path;
		}
		return $cached;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 6: Use integer 1 instead of true as sentinel.
 *
 * Tests if integer comparison is faster than boolean.
 */
function wp_normalize_path_exp6_intsentinel( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		if ( 1 === $cached ) {
			return $path;
		}
		return $cached;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? 1 : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 7: Use is_string() check instead of === true.
 *
 * Tests if type check is faster.
 */
function wp_normalize_path_exp7_isstring( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		if ( is_string( $cached ) ) {
			return $cached;
		}
		return $path;  // It's true, meaning path is already normalized
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 8: Optimized compact with fast-path + hit optimization.
 *
 * Combines: single-lookup hit path + fast-path for already-normalized + skip regex when no //.
 */
function wp_normalize_path_exp8_best( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	// Optimized cache hit path - single lookup.
	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		if ( true === $cached ) {
			return $path;
		}
		return $cached;
	}

	// Fast-path: Unix paths starting with / that have no \ or // are already normalized.
	if ( isset( $path[0] ) && '/' === $path[0] && false === strpos( $path, '\\' ) && false === strpos( $path, '//' ) ) {
		$cache[ $path ] = true;
		return $path;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );

	// Only run regex if there are consecutive slashes.
	if ( false !== strpos( $path, '//' ) ) {
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
	}

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 9: Minimal compact - only the hit optimization, nothing else.
 *
 * This isolates the single-lookup optimization to measure its impact alone.
 */
function wp_normalize_path_exp9_minimal( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	// Single lookup optimization.
	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		return true === $cached ? $path : $cached;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 10: Try storing empty string instead of true as sentinel.
 *
 * Empty string check might be faster: if ($cached === '') vs if ($cached === true)
 */
function wp_normalize_path_exp10_emptystr( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		return '' === $cached ? $path : $cached;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? '' : $normalized;

	return $normalized;
}

/**
 * EXPERIMENT 11: Ternary vs if-else comparison.
 *
 * Tests if ternary is faster than if-else for the cache hit path.
 */
function wp_normalize_path_exp11_ternary( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		// Using ternary with single lookup (same as exp9 but explicit ternary)
		return ( $c = $cache[ $path ] ) === true ? $path : $c;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * FINAL BEST: Recommended implementation balancing speed and memory.
 *
 * This is the cleanest compact implementation with no unnecessary complexity.
 * Key insights:
 * - Cache hit path is the hot path after warmup
 * - Single lookup + ternary is clean and fast
 * - Storing true for unchanged paths saves ~20% memory
 * - No need for fast-path detection (adds overhead, doesn't help after warmup)
 */
function wp_normalize_path_best( $path ) {
	static $cache = array();

	if ( '__cache_stats__' === $path ) {
		return array(
			'count'  => count( $cache ),
			'memory' => estimate_cache_memory_compact( $cache ),
		);
	}

	$path = (string) $path;

	if ( isset( $cache[ $path ] ) ) {
		$cached = $cache[ $path ];
		return true === $cached ? $path : $cached;
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$normalized = $wrapper . $path;
	$cache[ $original_path ] = ( $normalized === $original_path ) ? true : $normalized;

	return $normalized;
}

/**
 * SEGMENTED CACHE: Hot/warm two-tier cache implementation (bounded).
 *
 * Pass '__cache_stats__' to get cache statistics instead of normalizing.
 */
function wp_normalize_path_segmented( $path ) {
	static $hot  = array();
	static $warm = array();
	static $max  = 100;

	// Return cache stats when requested.
	if ( '__cache_stats__' === $path ) {
		return array(
			'hot_count'   => count( $hot ),
			'warm_count'  => count( $warm ),
			'count'       => count( $hot ) + count( $warm ),
			'memory'      => estimate_cache_memory( $hot ) + estimate_cache_memory( $warm ),
			'max_per_tier' => $max,
		);
	}

	$path = (string) $path;

	if ( isset( $hot[ $path ] ) ) {
		return $hot[ $path ];
	}

	if ( isset( $warm[ $path ] ) ) {
		$hot[ $path ] = $warm[ $path ];
		unset( $warm[ $path ] );
		return $hot[ $path ];
	}

	$original_path = $path;
	$wrapper       = '';

	if ( wp_is_stream( $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper .= '://';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	$value = $wrapper . $path;

	$hot[ $original_path ] = $value;

	if ( count( $hot ) >= $max ) {
		$warm = $hot;
		$hot  = array();
	}

	return $value;
}

/**
 * Dennis: Regex-free implementation with improved protocol handling.
 */
function wp_normalize_path_dennis( $path ) {
	$path = (string) $path;

	if ( '' === $path ) {
		return '';
	}

	$given_path = $path;

	// Normalize backslashes to forward slashes.
	$path = strtr( $path, '\\', '/' );

	$end           = strlen( $path );
	$at            = 0;
	$was_at        = $at;
	$start_of_path = 0;
	$normalized    = '';
	$has_stream    = false;

	// Check for stream protocol (must be longer than 1 char to avoid Windows drive letters).
	$protocol_length = strspn( $path, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.+-' );
	if ( $protocol_length > 1 && 0 === substr_compare( $given_path, '://', $protocol_length, 3 ) ) {
		$at            = $protocol_length + 3;
		$start_of_path = $at;
		$has_stream    = true;
	}

	// Skip leading double-slash of network shares.
	if ( 0 === substr_compare( $path, '//', $at, 2 ) ) {
		$at += 2;
	}

	// Replace sequences of slashes with single slash.
	while ( $at < $end ) {
		$next_slash_at = strpos( $path, '//', $at );
		if ( false === $next_slash_at ) {
			break;
		}

		$slash_count = strspn( $path, '/', $next_slash_at );
		$at          = $next_slash_at + $slash_count;
		$normalized .= substr( $path, $was_at, $next_slash_at - $was_at + 1 );
		$was_at      = $at;
	}

	if ( $was_at < $end ) {
		$normalized = $was_at > 0
			? ( $normalized . substr( $path, $was_at ) )
			: $path;
	}

	// Uppercase Windows drive letters in appropriate contexts.
	$first_colon_in_path = $end > $start_of_path ? strpos( $given_path, ':', $start_of_path ) : false;
	if ( false !== $first_colon_in_path && ( ! $has_stream || str_starts_with( $given_path, 'file://' ) ) ) {
		$is_long_path = 0 === substr_compare( $given_path, '//?/', $start_of_path, 4 );
		$drive_at     = $start_of_path + ( $is_long_path ? 4 : 0 );
		$drive        = $normalized[ $drive_at ];

		if ( ( $drive_at + 1 === $first_colon_in_path ) && $drive >= 'a' && $drive <= 'z' ) {
			$normalized[ $drive_at ] = strtoupper( $normalized[ $drive_at ] );
		}
	}

	return $normalized;
}

/**
 * Generate test paths - Unix-only (no Windows or UNC paths).
 *
 * Path distribution:
 * - 50% clean Unix paths (already normalized)
 * - 33% Unix paths with multiple slashes (need normalization)
 * - 17% PHP stream wrappers
 */
function generate_paths( $count, $unique_ratio = 0.39 ) {
	$unique_count = (int) ceil( $count * $unique_ratio );
	$unique_paths = array();

	for ( $i = 0; $i < $unique_count; $i++ ) {
		switch ( $i % 6 ) {
			case 0:
				// Clean Unix path - already normalized
				$unique_paths[] = "/var/www/html/wp-content/themes/theme-{$i}/style.css";
				break;
			case 1:
				// Clean Unix path - already normalized (different structure)
				$unique_paths[] = "/home/user/projects/wordpress/wp-includes/class-{$i}.php";
				break;
			case 2:
				// Clean Unix path - already normalized (plugin path)
				$unique_paths[] = "/var/www/html/wp-content/plugins/plugin-{$i}/main.php";
				break;
			case 3:
				// Multiple slashes - needs normalization
				$unique_paths[] = "/home/user///projects//wordpress///file-{$i}.php";
				break;
			case 4:
				// Multiple slashes - needs normalization (different pattern)
				$unique_paths[] = "/var/www//html///wp-content//uploads//image-{$i}.jpg";
				break;
			case 5:
				// PHP stream wrapper
				$unique_paths[] = "php://temp/resource-{$i}";
				break;
		}
	}

	$paths = array();
	$idx   = 0;

	while ( count( $paths ) < $count ) {
		$p       = $unique_paths[ $idx % count( $unique_paths ) ];
		$paths[] = $p;
		if ( count( $paths ) < $count ) {
			$paths[] = $p;
		}
		if ( count( $paths ) < $count ) {
			$paths[] = $p;
		}
		$idx++;
	}

	shuffle( $paths );
	return array_slice( $paths, 0, $count );
}

/**
 * Run benchmark and return elapsed time in milliseconds.
 */
function benchmark( $func, $paths, $runs = 3 ) {
	$times = array();

	for ( $r = 0; $r < $runs; $r++ ) {
		$start = hrtime( true );
		foreach ( $paths as $path ) {
			$func( $path );
		}
		$times[] = ( hrtime( true ) - $start ) / 1e6;
	}

	return array_sum( $times ) / count( $times );
}

/**
 * Format bytes to human readable string.
 */
function format_bytes( $bytes ) {
	if ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, 2 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return number_format( $bytes / 1024, 2 ) . ' KB';
	}
	return $bytes . ' B';
}

// =============================================================================
// MAIN - Experimental Optimization Variants
// =============================================================================

$line = str_repeat( '-', 100 );

echo "$line\n";
echo "Benchmark: wp_normalize_path() - Optimization Experiments (Unix-only Paths)\n";
echo "$line\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Date: " . date( 'Y-m-d H:i:s' ) . "\n";
echo "\n";
echo "Path types (Unix-only, no Windows/UNC):\n";
echo "  - 50% clean Unix paths (already normalized)\n";
echo "  - 33% Unix paths with multiple slashes (need normalization)\n";
echo "  - 17% PHP stream wrappers\n";
echo "\n";
echo "Variants being tested:\n";
echo "  Original = Current WordPress (regex-based, no cache) - BASELINE\n";
echo "  Simple   = Single array cache, stores key=>value (fastest, most memory)\n";
echo "  Compact  = Simple + stores true when unchanged (original compact approach)\n";
echo "  Best     = Recommended: compact with single-lookup optimization\n";
echo "  Dennis   = Regex-free implementation (no cache)\n";
echo "$line\n\n";

$steps = array( 1000, 2000, 4000 );

echo "Running benchmarks...\n\n";

$results = array();

foreach ( $steps as $count ) {
	$paths  = generate_paths( $count );
	$unique = count( array_unique( $paths ) );

	$bench_original = benchmark( 'wp_normalize_path_original', $paths );
	$bench_simple   = benchmark( 'wp_normalize_path_simple', $paths );
	$bench_compact  = benchmark( 'wp_normalize_path_simple_compact', $paths );
	$bench_best     = benchmark( 'wp_normalize_path_best', $paths );
	$bench_dennis   = benchmark( 'wp_normalize_path_dennis', $paths );

	// Get cache stats
	$simple_stats  = wp_normalize_path_simple( '__cache_stats__' );
	$compact_stats = wp_normalize_path_simple_compact( '__cache_stats__' );
	$best_stats    = wp_normalize_path_best( '__cache_stats__' );

	$results[] = array(
		'count'        => $count,
		'unique'       => $unique,
		'original'     => $bench_original,
		'simple'       => $bench_simple,
		'compact'      => $bench_compact,
		'best'         => $bench_best,
		'dennis'       => $bench_dennis,
		'simple_stats' => $simple_stats,
		'compact_stats'=> $compact_stats,
		'best_stats'   => $best_stats,
	);
}

echo "$line\n";
echo "RESULTS (times in milliseconds)\n";
echo "$line\n\n";

printf( "%-6s  %-6s  %-10s  %-10s  %-10s  %-10s  %-10s\n",
	'Paths', 'Unique', 'Original', 'Simple', 'Compact', 'Best', 'Dennis'
);
echo str_repeat( '-', 70 ) . "\n";

foreach ( $results as $r ) {
	printf( "%-6d  %-6d  %-10.3f  %-10.3f  %-10.3f  %-10.3f  %-10.3f\n",
		$r['count'],
		$r['unique'],
		$r['original'],
		$r['simple'],
		$r['compact'],
		$r['best'],
		$r['dennis']
	);
}

echo "\n$line\n";
echo "SPEED COMPARISON VS ORIGINAL (positive = faster)\n";
echo "$line\n\n";

printf( "%-6s  %-16s  %-16s  %-16s  %-16s\n",
	'Paths', 'Simple', 'Compact', 'Best', 'Dennis'
);
echo str_repeat( '-', 72 ) . "\n";

foreach ( $results as $r ) {
	$simple_pct  = ( ( $r['original'] - $r['simple'] ) / $r['original'] ) * 100;
	$compact_pct = ( ( $r['original'] - $r['compact'] ) / $r['original'] ) * 100;
	$best_pct    = ( ( $r['original'] - $r['best'] ) / $r['original'] ) * 100;
	$dennis_pct  = ( ( $r['original'] - $r['dennis'] ) / $r['original'] ) * 100;

	printf( "%-6d  %+.1f%% faster    %+.1f%% faster    %+.1f%% faster    %+.1f%%\n",
		$r['count'],
		$simple_pct,
		$compact_pct,
		$best_pct,
		$dennis_pct
	);
}

echo "\n$line\n";
echo "CACHE MEMORY USAGE (all experiments use compact storage)\n";
echo "$line\n\n";

printf( "%-6s  %-6s  %-14s  %-14s  %-14s\n", 'Paths', 'Unique', 'Simple', 'Compact/Exp*', 'Savings' );
echo str_repeat( '-', 60 ) . "\n";

foreach ( $results as $r ) {
	$simple_mem  = $r['simple_stats']['memory'];
	$compact_mem = $r['compact_stats']['memory'];
	$savings_pct = ( ( $simple_mem - $compact_mem ) / $simple_mem ) * 100;

	printf( "%-6d  %-6d  %-14s  %-14s  %-14s\n",
		$r['count'],
		$r['unique'],
		format_bytes( $simple_mem ),
		format_bytes( $compact_mem ),
		sprintf( '%.1f%% less', $savings_pct )
	);
}

// Final summary
$last = end( $results );
echo "\n$line\n";
echo "FINAL COMPARISON (at " . $last['count'] . " paths)\n";
echo "$line\n\n";

$simple_speed  = ( ( $last['original'] - $last['simple'] ) / $last['original'] ) * 100;
$compact_speed = ( ( $last['original'] - $last['compact'] ) / $last['original'] ) * 100;
$best_speed    = ( ( $last['original'] - $last['best'] ) / $last['original'] ) * 100;
$dennis_speed  = ( ( $last['original'] - $last['dennis'] ) / $last['original'] ) * 100;

$simple_mem  = $last['simple_stats']['memory'];
$compact_mem = $last['compact_stats']['memory'];
$best_mem    = $last['best_stats']['memory'];

$mem_savings = ( ( $simple_mem - $best_mem ) / $simple_mem ) * 100;
$speed_cost  = ( ( $last['best'] - $last['simple'] ) / $last['simple'] ) * 100;

printf( "%-10s  %-12s  %-14s  %-18s\n", 'Variant', 'Time (ms)', 'Memory', 'vs Original' );
echo str_repeat( '-', 58 ) . "\n";
printf( "%-10s  %-12.3f  %-14s  %-18s\n", 'Original', $last['original'], 'N/A (no cache)', 'baseline' );
printf( "%-10s  %-12.3f  %-14s  %+.1f%% faster\n", 'Simple', $last['simple'], format_bytes( $simple_mem ), $simple_speed );
printf( "%-10s  %-12.3f  %-14s  %+.1f%% faster\n", 'Compact', $last['compact'], format_bytes( $compact_mem ), $compact_speed );
printf( "%-10s  %-12.3f  %-14s  %+.1f%% faster\n", 'Best', $last['best'], format_bytes( $best_mem ), $best_speed );
printf( "%-10s  %-12.3f  %-14s  %+.1f%%\n", 'Dennis', $last['dennis'], 'N/A (no cache)', $dennis_speed );

echo "\n";
echo "CONCLUSION:\n";
echo str_repeat( '-', 58 ) . "\n";
echo "  Simple:  Maximum speed (" . sprintf( '+%.0f%%', $simple_speed ) . "), highest memory (" . format_bytes( $simple_mem ) . ")\n";
echo "  Best:    Balanced (" . sprintf( '+%.0f%%', $best_speed ) . " speed, " . sprintf( '%.0f%%', $mem_savings ) . " less memory)\n";
echo "  Dennis:  No cache (" . sprintf( '%+.0f%%', $dennis_speed ) . "), no memory overhead\n";
echo "\n";
echo "  The 'Best' implementation trades ~" . sprintf( '%.0f%%', $speed_cost ) . " speed for ~" . sprintf( '%.0f%%', $mem_savings ) . " memory savings.\n";
echo "  In Unix environments where most paths are already normalized, this is\n";
echo "  a good trade-off for memory-constrained systems.\n";

echo "\n$line\n";
echo "Benchmark complete.\n";
echo "$line\n";
