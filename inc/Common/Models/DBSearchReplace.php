<?php
/**
 * Model Class: DBSearchReplace
 *
 * This class is responsible for database search and replace.
 * Code is mostly from the Better Search Replace plugin.
 *
 * @see https://wordpress.org/plugins/better-search-replace/
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Models;

use wpdb;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Model Class: DBSearchReplace
 *
 * @since 1.0.0
 */
class DBSearchReplace {
	/**
	 * Page size.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public $page_size;

	/**
	 * The name of the backup file.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $file;

	/**
	 * The WordPress database class.
	 *
	 * @var WPDB
	 * @since 1.0.0
	 */
	private $wpdb;

	/**
	 * Class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb      = $wpdb;
		$this->page_size = 20000;
	}

	/**
	 * Returns an array of tables in the database.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function get_tables() {
		global $wpdb;

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( is_main_site() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tables = $wpdb->get_col( 'SHOW TABLES' );
			} else {
				$blog_id = get_current_blog_id();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tables = $wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$wpdb->prepare( 'SHOW TABLES LIKE %1$s', $wpdb->esc_like( $wpdb->base_prefix . absint( $blog_id ) ) . '\_%' )
				);
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tables = $wpdb->get_col( 'SHOW TABLES' );
		}

		return $tables;
	}

	/**
	 * Returns the number of pages in a table.
	 *
	 * @param string $table The table to check.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_pages_in_table( $table ) {
		if ( false === $this->table_exists( $table ) ) {
			return 0;
		}

		$table = esc_sql( $table );
		$query = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			'SELECT COUNT(*) FROM %1$s',
			$table
		);

		// SQL already prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $this->wpdb->get_var( $query );
		$pages = ceil( $rows / $this->page_size );

		return absint( $pages );
	}

	/**
	 * Gets the total number of pages in the DB.
	 *
	 * @param array $tables The tables to check.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_total_pages( $tables ) {
		$total_pages = 0;

		foreach ( $tables as $table ) {

			// Get the number of rows & pages in the table.
			$pages = $this->get_pages_in_table( $table );

			// Always include 1 page in case we have to create schemas, etc.
			if ( 0 === $pages ) {
				$pages = 1;
			}

			$total_pages += $pages;
		}

		return absint( $total_pages );
	}

	/**
	 * Gets the columns in a table.
	 *
	 * @param string $table The table to check.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_columns( $table ) {
		$primary_key = null;
		$columns     = [];

		if ( false === $this->table_exists( $table ) ) {
			return [ $primary_key, $columns ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$query = $this->wpdb->prepare( 'DESCRIBE %1$s', $table );

		// SQL already prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$fields = $this->wpdb->get_results( $query );

		if ( is_array( $fields ) ) {
			foreach ( $fields as $column ) {
				$columns[] = $column->Field;
				if ( 'PRI' == $column->Key ) {
					$primary_key = $column->Field;
				}
			}
		}

		return [ $primary_key, $columns ];
	}

	/**
	 * Adapted from interconnect/it's search/replace script.
	 *
	 * Modified to use WordPress wpdb functions instead of PHP's
	 * native mysql/pdo functions, and to be compatible with
	 * batch processing via AJAX.
	 *
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 *
	 * @param string $table The table to run the replacement on.
	 * @param int    $page The page/block to begin the query on.
	 * @param array  $args An associative array containing arguments for this run.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function srdb( $table, $page, $args ) {
		// Load up the default settings for this chunk.
		$table        = esc_sql( $table );
		$current_page = absint( $page );
		$pages        = $this->get_pages_in_table( $table );
		$done         = false;

		$table_report = [
			'change'  => 0,
			'updates' => 0,
			'start'   => microtime( true ),
			'end'     => microtime( true ),
			'errors'  => [],
			'skipped' => false,
		];

		// Get a list of columns in this table.
		list( $primary_key, $columns ) = $this->get_columns( $table );

		// Bail out early if there isn't a primary key.
		if ( null === $primary_key ) {
			$table_report['skipped'] = true;

			return [
				'table_complete' => true,
				'table_report'   => $table_report,
			];
		}

		$current_row = 0;
		$start       = $page * $this->page_size;
		$end         = $this->page_size;

		// Grab the content of the table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $this->wpdb->prepare( "SELECT * FROM $table LIMIT %d, %d", absint( $start ), absint( $end ) );

		// SQL already prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data = $this->wpdb->get_results( $query, ARRAY_A );

		// Loop through the data.
		foreach ( $data as $row ) {
			++$current_row;
			$update_sql = [];
			$where_sql  = [];
			$upd        = false;

			foreach ( $columns as $column ) {
				$data_to_fix = $row[ $column ];

				if ( $column === $primary_key ) {
					$where_sql[] = $column . ' = "' . $this->mysql_escape_mimic( $data_to_fix ) . '"';
					continue;
				}

				if ( $this->wpdb->options === $table ) {
					// Skip any BSR options as they may contain the search field.
					if ( isset( $should_skip ) && true === $should_skip ) {
						$should_skip = false;
						continue;
					}

					// If the Site URL needs to be updated, let's do that last.
					if ( isset( $update_later ) && true === $update_later ) {
						$update_later = false;
						$edited_data  = $this->recursive_unserialize_replace( $args['search_for'], $args['replace_with'], $data_to_fix, false, false );

						if ( $edited_data !== $data_to_fix ) {
							++$table_report['change'];
							++$table_report['updates'];
							continue;
						}
					}

					if ( '_transient_bsr_results' === $data_to_fix || 'bsr_profiles' === $data_to_fix || 'bsr_update_site_url' === $data_to_fix || 'bsr_data' === $data_to_fix ) {
						$should_skip = true;
					}

					if ( 'siteurl' === $data_to_fix && 'on' !== $args['dry_run'] ) {
						$update_later = true;
					}
				}

				// Run a search replace on the data that'll respect the serialisation.
				$edited_data = $this->recursive_unserialize_replace( $args['search_for'], $args['replace_with'], $data_to_fix, false, false );

				// Something was changed.
				if ( $edited_data !== $data_to_fix ) {
					$update_sql[] = $column . ' = "' . $this->mysql_escape_mimic( $edited_data ) . '"';
					$upd          = true;
					++$table_report['change'];
				}
			}

			// Determine what to do with updates.
			if ( $upd && ! empty( $where_sql ) ) {
				// If there are changes to make, run the query.
				$update_fields    = implode( ', ', $update_sql );
				$where_conditions = implode( ' AND ', array_filter( $where_sql ) );

				$sql = stripslashes(
					$this->wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
						'UPDATE %1$s SET %2$s WHERE %3$s',
						$table,
						$update_fields,
						$where_conditions
					)
				);

				// SQL already prepared.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result = $this->wpdb->query( $sql );

				if ( ! $result ) {
					// translators: the row number.
					$table_report['errors'][] = sprintf( esc_html__( 'Error updating row: %d.', 'easy-demo-importer' ), $current_row );
				} else {
					++$table_report['updates'];
				}
			}
		}

		if ( $current_page >= $pages - 1 ) {
			$done = true;
		}

		// Flush the results and return the report.
		$table_report['end'] = microtime( true );
		$this->wpdb->flush();

		return [
			'table_complete' => $done,
			'table_report'   => $table_report,
		];
	}

	/**
	 * Adapted from interconnect/it's search/replace script.
	 *
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 *
	 * Take a serialised array and unserialise it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the
	 * replacement on those too.
	 *
	 * @param string         $from String we're looking to replace.
	 * @param string         $to What we want it to be replaced with.
	 * @param array          $data Used to pass any subordinate arrays back to in.
	 * @param boolean        $serialised Does the array passed via $data need serialising.
	 * @param string|boolean $case_insensitive Set to 'on' if we should ignore case, false otherwise.
	 *
	 * @return string|array
	 * @since 1.0.0
	 */
	public function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false, $case_insensitive = false ) {
		try {
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			if ( is_string( $data ) && ! is_serialized_string( $data ) && ( $unserialized = $this->unserialize( $data ) ) !== false ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $case_insensitive );
			} elseif ( is_array( $data ) ) {
				$_tmp = [];
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false, $case_insensitive );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				if ( '__PHP_Incomplete_Class' !== get_class( $data ) ) {
					$_tmp  = $data;
					$props = get_object_vars( $data );
					foreach ( $props as $key => $value ) {
						$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false, $case_insensitive );
					}

					$data = $_tmp;
					unset( $_tmp );
				}
			} elseif ( is_serialized_string( $data ) ) {
				$unserialized = $this->unserialize( $data );

				if ( false !== $unserialized ) {
					$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $case_insensitive );
				}
			} elseif ( is_string( $data ) ) {
					$data = $this->str_replace( $from, $to, $data, $case_insensitive );
			}

			if ( $serialised ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				return serialize( $data );
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch, Squiz.Commenting.EmptyCatchComment.Missing
		} catch ( \Exception $error ) {
		}

		return $data;
	}

	/**
	 * Mimics the mysql_real_escape_string function. Adapted from
	 * a post by 'feedr' on php.net.
	 *
	 * @link   http://php.net/manual/en/function.mysql-real-escape-string.php#101248
	 * @access public
	 *
	 * @param string $input The string to escape.
	 *
	 * @return string|array
	 * @since 1.0.0
	 */
	public function mysql_escape_mimic( $input ) {
		if ( is_array( $input ) ) {
			return array_map( __METHOD__, $input );
		}

		if ( ! empty( $input ) && is_string( $input ) ) {
			return str_replace(
				[ '\\', "\0", "\n", "\r", "'", '"', "\x1a" ],
				[
					'\\\\',
					'\\0',
					'\\n',
					'\\r',
					"\\'",
					'\\"',
					'\\Z',
				],
				$input
			);
		}

		return $input;
	}

	/**
	 * Return unserialized object or array
	 *
	 * @param string $serialized_string Serialized string.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public static function unserialize( $serialized_string ) {
		if ( ! is_serialized( $serialized_string ) ) {
			return false;
		}

		$serialized_string = trim( $serialized_string );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		return @unserialize( $serialized_string );
	}

	/**
	 * Wrapper for str_replace
	 *
	 * @param string      $from From.
	 * @param string      $to To.
	 * @param string      $data Data.
	 * @param string|bool $case_insensitive Case insensitive.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function str_replace( $from, $to, $data, $case_insensitive = false ) {
		if ( 'on' === $case_insensitive ) {
			$data = str_ireplace( $from, $to, $data );
		} else {
			$data = str_replace( $from, $to, $data );
		}

		return $data;
	}

	/**
	 * Checks whether a table exists in DB.
	 *
	 * @param string $table Table.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function table_exists( $table ) {
		return in_array( $table, $this->get_tables(), true );
	}
}
