<?php
/**
 * Alias learning system.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles alias storage and learning.
 */
class PLTT_Aliases {

	/**
	 * Minimum confidence threshold for auto-selection.
	 *
	 * @var float
	 */
	const CONFIDENCE_THRESHOLD = PLTT_CONFIDENCE_THRESHOLD;

	/**
	 * Generic words that should not be used as client aliases.
	 *
	 * These are common activity/task words that appear across many clients
	 * and would produce unreliable predictions.
	 *
	 * @var array
	 */
	const STOPWORDS = array(
		'Meeting', 'Review', 'Design', 'Update', 'Planning',
		'Testing', 'Writing', 'Reading', 'Editing', 'Research',
		'Analysis', 'Support', 'Training', 'Setup', 'Cleanup',
		'Development', 'Deployment', 'Maintenance', 'Documentation',
		'Discussion', 'Presentation', 'Workshop', 'Interview',
		'Standup', 'Retro', 'Sprint', 'Demo', 'Sync',
		'Call', 'Chat', 'Email', 'Lunch', 'Break',
		'PDF', 'CSS', 'HTML', 'API', 'URL', 'PHP', 'SQL', 'CMS', 'SEO', 'DNS',
		'WordPress',
	);

	/**
	 * Get an alias by ID.
	 *
	 * @param int $id Alias ID.
	 * @return object|null Alias object or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get an alias by text.
	 *
	 * @param string $alias_text Alias text (case-insensitive).
	 * @return object|null Alias object or null.
	 */
	public static function get_by_text( $alias_text ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE LOWER(alias_text) = LOWER(%s)",
				$alias_text
			)
		);
	}

	/**
	 * Get all aliases.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of alias objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		$defaults = array(
			'client_id'      => 0,
			'min_confidence' => 0,
			'orderby'        => 'alias_text',
			'order'          => 'ASC',
		);
		$args     = wp_parse_args( $args, $defaults );

		$where   = array();
		$prepare = array();

		if ( $args['client_id'] > 0 ) {
			$where[]   = 'client_id = %d';
			$prepare[] = $args['client_id'];
		}

		if ( $args['min_confidence'] > 0 ) {
			$where[]   = 'confidence >= %f';
			$prepare[] = $args['min_confidence'];
		}

		$sql = "SELECT * FROM {$table}";

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$orderby = in_array( $args['orderby'], array( 'alias_text', 'confidence', 'use_count', 'last_used' ), true )
			? $args['orderby']
			: 'alias_text';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		if ( ! empty( $prepare ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Create or update an alias.
	 *
	 * If alias exists, updates it. Otherwise creates new.
	 *
	 * @param array $data Alias data.
	 * @return int|false Alias ID or false on failure.
	 */
	public static function save( $data ) {
		$alias_text = sanitize_text_field( $data['alias_text'] ?? '' );

		if ( empty( $alias_text ) ) {
			return false;
		}

		$existing = self::get_by_text( $alias_text );

		if ( $existing ) {
			// Update existing alias.
			self::update(
				$existing->id,
				array(
					'client_id'  => $data['client_id'] ?? $existing->client_id,
					'project_id' => $data['project_id'] ?? $existing->project_id,
				)
			);
			return $existing->id;
		}

		// Create new alias.
		return self::create( $data );
	}

	/**
	 * Create a new alias.
	 *
	 * @param array $data Alias data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		$client_id  = ! empty( $data['client_id'] ) ? absint( $data['client_id'] ) : null;
		$project_id = ! empty( $data['project_id'] ) ? absint( $data['project_id'] ) : null;

		$insert_data = array(
			'alias_text' => sanitize_text_field( $data['alias_text'] ?? '' ),
			'confidence' => 0.5,
			'use_count'  => 1,
			'last_used'  => current_time( 'mysql' ),
		);

		$formats = array( '%s', '%f', '%d', '%s' );

		// Only include nullable fields when they have values.
		// wpdb->insert() converts NULL to 0 with %d format; omitting them lets MySQL use NULL.
		if ( null !== $client_id ) {
			$insert_data['client_id'] = $client_id;
			$formats[]                = '%d';
		}
		if ( null !== $project_id ) {
			$insert_data['project_id'] = $project_id;
			$formats[]                 = '%d';
		}

		if ( empty( $insert_data['alias_text'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$insert_data,
			$formats
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an alias.
	 *
	 * @param int   $id   Alias ID.
	 * @param array $data Data to update.
	 * @return bool True on success.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		$update_data  = array();
		$formats      = array();
		$null_fields  = array();

		$nullable_fields = array( 'client_id', 'project_id' );

		if ( array_key_exists( 'client_id', $data ) ) {
			if ( ! empty( $data['client_id'] ) ) {
				$update_data['client_id'] = absint( $data['client_id'] );
				$formats[]                = '%d';
			} else {
				$null_fields[] = 'client_id';
			}
		}

		if ( array_key_exists( 'project_id', $data ) ) {
			if ( ! empty( $data['project_id'] ) ) {
				$update_data['project_id'] = absint( $data['project_id'] );
				$formats[]                 = '%d';
			} else {
				$null_fields[] = 'project_id';
			}
		}

		if ( isset( $data['confidence'] ) ) {
			$update_data['confidence'] = max( 0, min( 1, floatval( $data['confidence'] ) ) );
			$formats[]                 = '%f';
		}

		if ( isset( $data['use_count'] ) ) {
			$update_data['use_count'] = absint( $data['use_count'] );
			$formats[]                = '%d';
		}

		if ( isset( $data['correct_count'] ) ) {
			$update_data['correct_count'] = absint( $data['correct_count'] );
			$formats[]                    = '%d';
		}

		if ( empty( $update_data ) && empty( $null_fields ) ) {
			return false;
		}

		$result = true;

		if ( ! empty( $update_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = false !== $wpdb->update(
				$table,
				$update_data,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);
		}

		// Set nullable fields to NULL directly since wpdb->update() converts NULL to 0 with %d.
		if ( $result && ! empty( $null_fields ) ) {
			$set_clauses = array();
			foreach ( $null_fields as $field ) {
				$set_clauses[] = "`{$field}` = NULL";
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = false !== $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"UPDATE {$table} SET " . implode( ', ', $set_clauses ) . ' WHERE id = %d',
					$id
				)
			);
		}

		return $result;
	}

	/**
	 * Record a usage of an alias.
	 *
	 * @param int  $id         Alias ID.
	 * @param bool $was_correct Whether the prediction was correct.
	 */
	public static function record_usage( $id, $was_correct = true ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		$alias = self::get( $id );
		if ( ! $alias ) {
			return;
		}

		$new_use_count     = $alias->use_count + 1;
		$new_correct_count = $was_correct ? $alias->correct_count + 1 : $alias->correct_count;

		// Recalculate confidence.
		$new_confidence = $new_use_count > 0 ? $new_correct_count / $new_use_count : 0.5;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'use_count'     => $new_use_count,
				'correct_count' => $new_correct_count,
				'confidence'    => $new_confidence,
				'last_used'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Find matching aliases in text.
	 *
	 * @param string $text Text to search.
	 * @return array Array of matching alias objects with positions.
	 */
	public static function find_in_text( $text ) {
		$aliases = self::get_all();
		$matches = array();

		foreach ( $aliases as $alias ) {
			// Case-insensitive word boundary match.
			$pattern = '/\b' . preg_quote( $alias->alias_text, '/' ) . '\b/i';
			if ( preg_match( $pattern, $text ) ) {
				$matches[] = $alias;
			}
		}

		// Sort by confidence (highest first).
		usort(
			$matches,
			function ( $a, $b ) {
				return $b->confidence <=> $a->confidence;
			}
		);

		return $matches;
	}

	/**
	 * Get best client match for text.
	 *
	 * @param string $text Text to search.
	 * @return object|null Best matching alias or null.
	 */
	public static function get_best_client_match( $text ) {
		$matches = self::find_in_text( $text );

		foreach ( $matches as $match ) {
			if ( ! empty( $match->client_id ) ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * Delete an alias.
	 *
	 * @param int $id Alias ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Extract potential aliases from text.
	 *
	 * Finds acronyms (2-5 uppercase letters) and capitalized words.
	 *
	 * @param string $text Text to analyze.
	 * @return array Array of potential alias strings.
	 */
	public static function extract_potential( $text ) {
		$potentials = array();

		// Find acronyms (2-5 uppercase letters).
		preg_match_all( '/\b([A-Z]{2,5})\b/', $text, $acronyms );
		if ( ! empty( $acronyms[1] ) ) {
			$potentials = array_merge( $potentials, $acronyms[1] );
		}

		// Find capitalized words (excluding common words and sentence starts).
		// Remove timestamp patterns first.
		$cleaned = preg_replace( '/@\d{1,2}:\d{2}(am|pm)?/i', '', $text );

		// Find words that start with uppercase.
		preg_match_all( '/\b([A-Z][a-z]{2,})\b/', $cleaned, $words );
		if ( ! empty( $words[1] ) ) {
			// Filter out common words.
			$common_words = array(
				'The', 'And', 'For', 'With', 'From', 'This', 'That', 'Done', 'End', 'Finished',
				'Having', 'Going', 'Working', 'Getting', 'Making', 'Taking', 'Doing',
				'Also', 'Just', 'Still', 'Then', 'About', 'After', 'Before', 'Around',
			);
			$filtered     = array_diff( $words[1], $common_words );
			$potentials   = array_merge( $potentials, $filtered );
		}

		$potentials = array_unique( $potentials );

		// Filter out stopwords (generic activity words that span many clients).
		$stopwords_lower = array_map( 'strtolower', self::STOPWORDS );
		$potentials      = array_filter(
			$potentials,
			function ( $p ) use ( $stopwords_lower ) {
				return ! in_array( strtolower( $p ), $stopwords_lower, true );
			}
		);

		// Filter out known tags (words already used as hashtags).
		$known_tags = PLTT_Entries::get_all_tags();
		if ( ! empty( $known_tags ) ) {
			$potentials = array_filter(
				$potentials,
				function ( $p ) use ( $known_tags ) {
					return ! in_array( strtolower( $p ), $known_tags, true );
				}
			);
		}

		return array_values( $potentials );
	}
}
