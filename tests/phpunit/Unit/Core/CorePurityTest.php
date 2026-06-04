<?php
/**
 * Architectural guard: src/Core must contain zero WordPress (or other
 * non-PHP-native) function calls, so the core stays platform-agnostic.
 *
 * Strategy: tokenize every Core file, collect global function-call names
 * (excluding method calls, definitions, and instantiations), and assert each is
 * a built-in PHP function. WordPress functions are not defined in the unit-test
 * runtime, so any WP call surfaces as a non-internal function and fails here.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class CorePurityTest extends TestCase {

	/**
	 * Absolute path to src/Core.
	 *
	 * @return string
	 */
	private function core_dir() {
		return dirname( __DIR__, 4 ) . '/src/Core';
	}

	/**
	 * All PHP files under src/Core.
	 *
	 * @return string[]
	 */
	private function core_files() {
		$dir   = $this->core_dir();
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Extract global function-call names from PHP source.
	 *
	 * Skips method calls (->, ::), definitions (function), and instantiation
	 * (new). Language constructs like isset/empty/array are their own tokens, not
	 * T_STRING, so they are naturally excluded.
	 *
	 * @param string $code PHP source.
	 * @return string[] Lowercased function names that are called.
	 */
	private function called_functions( $code ) {
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$names  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			// Next non-whitespace token must be an opening parenthesis.
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( $j >= $count || '(' !== $tokens[ $j ] ) {
				continue;
			}

			// Previous non-whitespace token must not make this a method/def/new.
			$k = $i - 1;
			while ( $k >= 0 && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				--$k;
			}
			if ( $k >= 0 && is_array( $tokens[ $k ] ) ) {
				$prev = $tokens[ $k ][0];
				if ( in_array( $prev, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}
			}

			$names[] = strtolower( $token[1] );
		}

		return array_values( array_unique( $names ) );
	}

	public function test_core_has_files_to_check() {
		$this->assertNotEmpty( $this->core_files(), 'Expected PHP files under src/Core.' );
	}

	public function test_core_calls_only_native_php_functions() {
		$internal = array_flip( get_defined_functions()['internal'] );
		$failures = array();

		foreach ( $this->core_files() as $file ) {
			// Reading a local source file in a test; WP_Filesystem is not loaded here.
			$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( $this->called_functions( $code ) as $fn ) {
				if ( ! isset( $internal[ $fn ] ) ) {
					$failures[] = basename( $file ) . ': ' . $fn . '()';
				}
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"src/Core must only call native PHP functions (no WordPress calls). Found:\n" . implode( "\n", $failures )
		);
	}
}
