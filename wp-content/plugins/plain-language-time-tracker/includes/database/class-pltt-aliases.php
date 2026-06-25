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
	 * Common English words that should never become a client alias.
	 *
	 * Catches accidental mid-sentence / sentence-start capitalizations (e.g.
	 * "Into", "Get", "Work", "Now") that the learner would otherwise harvest as
	 * junk aliases. Stored lowercase and matched case-insensitively, so a
	 * capitalized form is filtered the same as a lowercase one. Client names are
	 * proper nouns, so filtering ordinary words here is low-risk — and seeding
	 * via the chip manager bypasses this filter if you ever truly need one.
	 *
	 * @var array
	 */
	const COMMON_WORDS = array(
		// Determiners / quantifiers.
		'the', 'this', 'that', 'these', 'those', 'some', 'any', 'all', 'each', 'every',
		'both', 'more', 'most', 'other', 'another', 'such', 'same', 'own', 'few', 'many',
		// Pronouns.
		'you', 'she', 'her', 'him', 'his', 'they', 'them', 'their', 'our', 'its',
		'who', 'whom', 'whose', 'what', 'which', 'someone', 'something', 'anything', 'everything',
		// Prepositions / particles.
		'into', 'onto', 'over', 'under', 'between', 'among', 'through', 'during', 'before',
		'after', 'above', 'below', 'out', 'off', 'near', 'around', 'across', 'against',
		'along', 'behind', 'within', 'without', 'upon', 'about', 'with', 'from', 'for',
		// Conjunctions.
		'and', 'but', 'nor', 'because', 'while', 'although', 'though', 'since', 'unless',
		'until', 'whether', 'than', 'then', 'when', 'where',
		// Common verbs (esp. capitalized at sentence start).
		'are', 'was', 'were', 'been', 'being', 'have', 'has', 'had', 'having',
		'does', 'did', 'doing', 'done', 'get', 'gets', 'got', 'gotten', 'getting',
		'make', 'makes', 'made', 'making', 'goes', 'going', 'went', 'gone',
		'come', 'comes', 'came', 'coming', 'take', 'takes', 'took', 'taken', 'taking',
		'meet', 'meets', 'met', 'see', 'sees', 'saw', 'seen', 'know', 'knows', 'knew', 'known', 'knowing',
		'think', 'thinks', 'thought', 'want', 'wants', 'wanted', 'need', 'needs', 'needed',
		'use', 'uses', 'used', 'using', 'work', 'works', 'worked', 'working',
		'put', 'set', 'keep', 'kept', 'let', 'say', 'says', 'said', 'tell', 'told',
		'ask', 'asks', 'asked', 'find', 'found', 'give', 'gives', 'gave', 'given',
		'try', 'tried', 'trying', 'start', 'starts', 'started', 'finish', 'finished',
		'send', 'sent', 'look', 'looks', 'looked', 'add', 'added', 'run', 'ran', 'end',
		// Adverbs / misc.
		'now', 'here', 'there', 'just', 'also', 'still', 'very', 'too', 'only', 'even',
		'well', 'back', 'again', 'once', 'never', 'always', 'often', 'soon', 'later',
		'really', 'maybe', 'probably', 'actually', 'however', 'instead', 'almost', 'already',
		'enough', 'quite', 'rather', 'much', 'lot', 'today', 'tomorrow', 'yesterday',
		// Days / time.
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
		'morning', 'afternoon', 'evening', 'night', 'week', 'next', 'last',
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

		if ( $result ) {
			pltt_flush_alias_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Seed (or repoint) an alias at full confidence.
	 *
	 * Used by the settings chip manager: a user-supplied shorthand should match
	 * immediately, so it lands at confidence 1.00. Idempotent on alias_text —
	 * if the text already exists (e.g. the learner discovered it), it's
	 * repointed to the seeded target and lifted to full confidence rather than
	 * duplicated (alias_text is UNIQUE).
	 *
	 * @param string   $alias_text Shorthand text.
	 * @param int|null $client_id  Client to map to.
	 * @param int|null $project_id Project to map to (also pass its client_id).
	 * @return int|false Alias ID or false on failure.
	 */
	public static function seed( $alias_text, $client_id = null, $project_id = null ) {
		global $wpdb;
		$table      = PLTT_Database::get_table_name( 'aliases' );
		$alias_text = sanitize_text_field( $alias_text );

		if ( '' === $alias_text ) {
			return false;
		}

		$client_id  = ! empty( $client_id ) ? absint( $client_id ) : null;
		$project_id = ! empty( $project_id ) ? absint( $project_id ) : null;

		$existing = self::get_by_text( $alias_text );

		if ( $existing ) {
			$data    = array( 'confidence' => 1.00 );
			$formats = array( '%f' );
			if ( null !== $client_id ) {
				$data['client_id'] = $client_id;
				$formats[]         = '%d';
			}
			if ( null !== $project_id ) {
				$data['project_id'] = $project_id;
				$formats[]          = '%d';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $data, array( 'id' => $existing->id ), $formats, array( '%d' ) );
			pltt_flush_alias_cache();
			return (int) $existing->id;
		}

		$insert  = array(
			'alias_text' => $alias_text,
			'confidence' => 1.00,
			'use_count'  => 0,
			'last_used'  => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%f', '%d', '%s' );
		// Omit nullable FKs when absent so wpdb doesn't write 0 instead of NULL.
		if ( null !== $client_id ) {
			$insert['client_id'] = $client_id;
			$formats[]           = '%d';
		}
		if ( null !== $project_id ) {
			$insert['project_id'] = $project_id;
			$formats[]            = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $insert, $formats );
		if ( $result ) {
			pltt_flush_alias_cache();
			return (int) $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get the client-level aliases for a client (project aliases excluded).
	 *
	 * @param int $client_id Client ID.
	 * @return array Alias objects, most-used first.
	 */
	public static function get_for_client( $client_id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_id = %d AND project_id IS NULL ORDER BY use_count DESC, alias_text ASC",
				absint( $client_id )
			)
		);
	}

	/**
	 * Delete an alias (chip-manager prune).
	 *
	 * @param int $id Alias ID.
	 * @return int|false Rows deleted or false.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );
		$id    = absint( $id );

		if ( ! $id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		pltt_flush_alias_cache();
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
		$id    = absint( $id );

		if ( ! $id ) {
			return;
		}

		// SEC-M9: atomic UPDATE so concurrent calls increment monotonically.
		// The previous read-modify-write could lose counter bumps.
		$delta = $was_correct ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET use_count     = use_count + 1,
				     correct_count = correct_count + %d,
				     confidence    = (correct_count + %d) / (use_count + 1),
				     last_used     = %s
				 WHERE id = %d",
				$delta,
				$delta,
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/**
	 * Prune low-value learned aliases.
	 *
	 * Two precise rules, neither of which touches deliberate seeds (confidence
	 * >= 0.95):
	 *  - common/stop word: alias_text is now in STOPWORDS/COMMON_WORDS (would
	 *    never be learned today);
	 *  - never confirmed: confidence <= 0.10 (used but consistently wrong).
	 *
	 * Deliberately NOT pruning low-use "one-offs": use-count = 1 can't tell a
	 * fresh proper-noun alias (e.g. "Mintie" -> Daniel Mintie) from a typo, so
	 * those are left to manual pruning in the chip manager.
	 *
	 * @param bool $apply When true, delete the candidates; otherwise dry-run.
	 * @return array Candidate objects: { alias, reason }.
	 */
	public static function prune_low_value( $apply = false ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$aliases  = $wpdb->get_results( "SELECT * FROM {$table}" );
		$filtered = array_merge( array_map( 'strtolower', self::STOPWORDS ), self::COMMON_WORDS );

		$candidates = array();
		foreach ( $aliases as $a ) {
			$conf  = (float) $a->confidence;
			$lower = strtolower( $a->alias_text );

			$reason = '';
			if ( $conf < 0.95 && in_array( $lower, $filtered, true ) ) {
				$reason = 'common/stop word';
			} elseif ( $conf <= 0.10 ) {
				$reason = 'never confirmed (' . number_format( $conf, 2 ) . ')';
			}

			if ( '' !== $reason ) {
				$candidates[] = (object) array(
					'alias'  => $a,
					'reason' => $reason,
				);
			}
		}

		if ( $apply && ! empty( $candidates ) ) {
			foreach ( $candidates as $c ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->delete( $table, array( 'id' => (int) $c->alias->id ), array( '%d' ) );
			}
			pltt_flush_alias_cache();
		}

		return $candidates;
	}

	/**
	 * Find matching aliases in text.
	 *
	 * @param string $text Text to search.
	 * @return array Array of matching alias objects with positions.
	 */
	private static function find_in_text( $text ) {
		$aliases = pltt_get_cached_aliases();
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
	 * Get best project match for text.
	 *
	 * Returns the highest-confidence matching alias that carries a project_id —
	 * a "direct alias-to-project hit." Project aliases also carry their parent
	 * client_id, so the caller can resolve both from one row.
	 *
	 * @param string $text Text to search.
	 * @return object|null Best matching project-bearing alias or null.
	 */
	public static function get_best_project_match( $text ) {
		$matches = self::find_in_text( $text );

		foreach ( $matches as $match ) {
			if ( ! empty( $match->project_id ) ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * Extract potential aliases from text.
	 *
	 * Finds acronyms (2-5 uppercase letters) and capitalized words.
	 *
	 * OPT-L7: Accepts optional $known_tags to avoid a DB call when processing multiple entries.
	 * Pass pre-loaded tag names (array of strings) to skip the internal PLTT_Tags::get_all() call.
	 *
	 * @param string     $text       Text to analyze.
	 * @param array|null $known_tags Optional pre-loaded tag names. If null, loads from DB.
	 * @return array Array of potential alias strings.
	 */
	public static function extract_potential( $text, $known_tags = null ) {
		$potentials = self::_extract_acronyms( $text );
		$potentials = array_merge( $potentials, self::_extract_capitalized_words( $text ) );
		$potentials = array_unique( $potentials );

		// OPT-L7: Use provided tag list if available; otherwise load once from DB.
		if ( null === $known_tags ) {
			$known_tags = array_column( PLTT_Tags::get_all(), 'name' );
		}

		return self::_filter_stopwords_and_tags( $potentials, $known_tags );
	}

	/**
	 * Extract acronyms (2-5 uppercase letters) from text.
	 *
	 * @param string $text Text to scan.
	 * @return array Array of acronym strings.
	 */
	private static function _extract_acronyms( $text ) {
		preg_match_all( '/\b([A-Z]{2,5})\b/', $text, $matches );
		return ! empty( $matches[1] ) ? $matches[1] : array();
	}

	/**
	 * Extract capitalized words (3+ chars, not common English words) from text.
	 *
	 * Strips timestamp patterns before scanning to avoid false positives.
	 *
	 * @param string $text Text to scan.
	 * @return array Array of capitalized word strings.
	 */
	private static function _extract_capitalized_words( $text ) {
		// Remove timestamp patterns first.
		$cleaned = preg_replace( '/@\d{1,2}:\d{2}(am|pm)?/i', '', $text );

		preg_match_all( '/\b([A-Z][a-z]{2,})\b/', $cleaned, $matches );
		if ( empty( $matches[1] ) ) {
			return array();
		}

		// Common-word filtering happens case-insensitively in
		// _filter_stopwords_and_tags(), so just return the raw candidates here.
		return $matches[1];
	}

	/**
	 * Filter a list of potential aliases, removing stopwords and known tag names.
	 *
	 * @param array $potentials  Candidate alias strings.
	 * @param array $known_tags  Tag names (lowercase) to exclude.
	 * @return array Filtered alias strings (re-indexed).
	 */
	private static function _filter_stopwords_and_tags( $potentials, $known_tags ) {
		$stopwords_lower = array_map( 'strtolower', self::STOPWORDS );

		$potentials = array_filter(
			$potentials,
			function ( $p ) use ( $stopwords_lower, $known_tags ) {
				$lower = strtolower( $p );
				return ! in_array( $lower, $stopwords_lower, true )
					&& ! in_array( $lower, self::COMMON_WORDS, true )
					&& ! in_array( $lower, $known_tags, true );
			}
		);

		return array_values( $potentials );
	}
}
