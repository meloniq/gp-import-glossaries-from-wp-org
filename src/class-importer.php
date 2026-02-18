<?php
/**
 * Importer for GlotCore Import Glossaries.
 *
 * @package GlotCore\ImportGlossaries
 */

namespace GlotCore\ImportGlossaries;

use GP;
use GP_Glossary;
use GP_Glossary_Entry;
use GP_Locales;

/**
 * Importer class.
 */
class Importer {

	/**
	 * Get the supported locales with names.
	 *
	 * @return array Associative array of locale slug => locale name.
	 */
	public static function get_supported_locales(): array {
		$glotpress_locales = GP_Locales::locales();
		$wporg_locales     = Data::get_wporg_locales();

		$supported_locales = array();
		foreach ( $glotpress_locales as $locale ) {
			// Skip locales that don't have a WordPress locale mapping.
			if ( empty( $locale->wp_locale ) ) {
				continue;
			}

			// Only include locales that have a mapping to WordPress.org.
			if ( ! in_array( $locale->slug, $wporg_locales, true ) ) {
				continue;
			}

			$supported_locales[ $locale->slug ] = $locale->english_name;
		}

		// Sort by english name.
		asort( $supported_locales );

		return $supported_locales;
	}

	/**
	 * Import locales.
	 *
	 * @param array $locales Array of locale slugs to import.
	 *
	 * @return array Associative array of locale slug => import result (number of entries or error code).
	 */
	public static function import_locales( array $locales ): array {
		$results = array();

		foreach ( $locales as $locale ) {
			$results[ $locale ] = self::import_from_wporg( $locale );
		}

		return $results;
	}

	/**
	 * Import glossary entries from WordPress.org into GlotPress native glossary.
	 *
	 * Downloads the CSV from translate.wordpress.org and imports entries
	 * using the same approach as GlotPress's native import.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return int Number of entries imported, or -1 on error.
	 */
	public static function import_from_wporg( string $locale ): int {
		// Find or create a glossary for this locale.
		$glossary = self::get_or_create_glossary_for_locale( $locale );
		if ( ! $glossary ) {
			return -1;
		}

		// Download the CSV from WordPress.org.
		$csv_content = self::download_wporg_glossary_csv( $locale );
		if ( empty( $csv_content ) ) {
			return 0;
		}

		// Write to a temp file so we can use fgetcsv like GlotPress does.
		$tmp_file = wp_tempnam( 'gc_ig_glossary_' );
		file_put_contents( $tmp_file, $csv_content );

		$imported = self::import_csv_to_glossary( $tmp_file, $glossary->id, $locale );

		unlink( $tmp_file );

		// Update last import timestamp.
		$import_times            = get_option( 'gc_ig_glossary_import_times', array() );
		$import_times[ $locale ] = time();
		update_option( 'gc_ig_glossary_import_times', $import_times );

		return $imported;
	}

	/**
	 * Import a CSV file into a GlotPress glossary using native GlotPress methods.
	 *
	 * Mirrors GlotPress's own read_glossary_entries_from_file() logic.
	 *
	 * @param string $file        Path to the CSV file.
	 * @param int    $glossary_id The glossary ID.
	 * @param string $locale      The locale slug.
	 *
	 * @return int Number of entries imported.
	 */
	protected static function import_csv_to_glossary( string $file, int $glossary_id, string $locale ): int { // phpcs:ignore
		$f = fopen( $file, 'r' );
		if ( ! $f ) {
			return 0;
		}

		$imported = 0;

		// Read and validate header.
		$header = fgetcsv( $f, 0, ',', '"', '' );
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			fclose( $f );
			return 0;
		}

		// Resolve user ID once. In CLI context get_current_user_id() returns 0.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$admins  = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			$user_id = ! empty( $admins ) ? (int) $admins[0] : 1;
		}

		// WordPress.org CSV header is: en, <locale>, pos, description.
		// GlotPress validates that header[1] matches locale slug.

		while ( ( $data = fgetcsv( $f, 0, ',', '"', '' ) ) !== false ) {
			if ( count( $data ) < 4 ) {
				continue;
			}

			// Match GlotPress's native logic: if more than 4 columns, splice.
			if ( count( $data ) > 4 ) {
				$data = array_splice( $data, 2, -2 );
			}

			$entry_data = array(
				'glossary_id'    => $glossary_id,
				'term'           => $data[0],
				'translation'    => $data[1],
				'part_of_speech' => $data[2],
				'comment'        => $data[3],
				'last_edited_by' => $user_id,
			);

			// Use GlotPress native validation.
			$new_entry = new GP_Glossary_Entry( $entry_data );
			if ( ! $new_entry->validate() ) {
				continue;
			}

			// Check if entry already exists (GlotPress native duplicate check).
			$existing = GP::$glossary_entry->find_one( $entry_data );
			if ( $existing ) {
				continue;
			}

			$created = GP::$glossary_entry->create_and_select( $new_entry );
			if ( $created ) {
				++$imported;
			}
		}

		fclose( $f );

		return $imported;
	}

	/**
	 * Download glossary CSV from WordPress.org.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return string CSV content or empty string on failure.
	 */
	public static function download_wporg_glossary_csv( string $locale ): string {
		$wporg_locale = self::convert_locale_to_wporg( $locale );
		if ( empty( $wporg_locale ) ) {
			return '';
		}

		$url = sprintf(
			'https://translate.wordpress.org/locale/%s/default/glossary/-export/',
			$wporg_locale
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'text/csv',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch glossary data from WordPress.org as parsed array.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return array Array of glossary entries.
	 */
	public static function fetch_wporg_glossary( string $locale ): array {
		$csv_content = self::download_wporg_glossary_csv( $locale );
		if ( empty( $csv_content ) ) {
			return array();
		}

		return self::parse_csv_glossary( $csv_content );
	}

	/**
	 * Parse CSV glossary content into an array.
	 *
	 * @param string $csv_content The CSV content.
	 *
	 * @return array Array of glossary entries.
	 */
	protected static function parse_csv_glossary( string $csv_content ): array {
		$entries = array();
		$lines   = explode( "\n", $csv_content );

		$header = null;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$row = str_getcsv( $line, ',', '"', '' );

			// First non-empty line is the header.
			if ( null === $header ) {
				$header = array_map( 'strtolower', $row );
				continue;
			}

			if ( count( $row ) < 2 ) {
				continue;
			}

			// WordPress.org CSV: en, <locale>, pos, description (positional).
			$entries[] = array(
				'term'           => $row[0] ?? '',
				'translation'    => $row[1] ?? '',
				'part_of_speech' => $row[2] ?? '',
				'comment'        => $row[3] ?? '',
			);
		}

		return array_filter( $entries, fn( $e ) => ! empty( $e['term'] ) );
	}

	/**
	 * Convert a GlotPress locale slug to WordPress.org format.
	 *
	 * @param string $locale The GlotPress locale slug.
	 *
	 * @return string The WordPress.org locale slug.
	 */
	protected static function convert_locale_to_wporg( string $locale ): string {
		$mappings = array(
			'pt' => 'pt-br',
			'zh' => 'zh-cn',
		);

		if ( isset( $mappings[ $locale ] ) ) {
			return $mappings[ $locale ];
		}

		return $locale;
	}

	/**
	 * Get or create a GlotPress native glossary for a specific locale.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return GP_Glossary|null The glossary object or null on failure.
	 */
	protected static function get_or_create_glossary_for_locale( string $locale ) {
		// Use project_id = 0 for locale-level glossary (GlotPress convention).
		// This is the glossary shown at /languages/{locale}/default/glossary/.
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( 0, 'default', $locale );

		if ( ! $translation_set ) {
			return null;
		}

		// Check for existing glossary.
		$glossary = GP::$glossary->by_set_id( $translation_set->id );
		if ( $glossary ) {
			return $glossary;
		}

		// Create a new glossary for this locale translation set.
		return GP::$glossary->create(
			array(
				'translation_set_id' => $translation_set->id,
			)
		);
	}

	/**
	 * Get the last import time for a locale.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return int|null Unix timestamp or null if never imported.
	 */
	public static function get_last_import_time( string $locale ): ?int {
		$import_times = get_option( 'gc_ig_glossary_import_times', array() );

		return $import_times[ $locale ] ?? null;
	}
}
