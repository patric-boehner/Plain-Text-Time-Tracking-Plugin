<?php
/**
 * Form submission handlers for admin_post actions.
 *
 * Handles traditional form POSTs with redirects instead of AJAX responses.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles form submissions via admin_post hooks.
 */
class PLTT_Form_Handlers {

	/**
	 * Register admin_post hooks.
	 */
	public static function init() {
		// Client operations.
		add_action( 'admin_post_pltt_update_client', array( __CLASS__, 'handle_update_client' ) );
		add_action( 'admin_post_pltt_delete_client', array( __CLASS__, 'handle_delete_client' ) );

		// Project operations.
		add_action( 'admin_post_pltt_update_project', array( __CLASS__, 'handle_update_project' ) );
		add_action( 'admin_post_pltt_delete_project', array( __CLASS__, 'handle_delete_project' ) );

		// Review screen.
		add_action( 'admin_post_pltt_save_entries', array( __CLASS__, 'handle_save_entries' ) );

		// Billing surface: commit one billing record.
		add_action( 'admin_post_pltt_commit_billing', array( __CLASS__, 'handle_commit_billing' ) );

		// Tag operations.
		add_action( 'admin_post_pltt_create_tag', array( __CLASS__, 'handle_create_tag' ) );
		add_action( 'admin_post_pltt_rename_tag', array( __CLASS__, 'handle_rename_tag' ) );
		add_action( 'admin_post_pltt_delete_tag', array( __CLASS__, 'handle_delete_tag' ) );
	}

	/**
	 * Verify nonce for form submission.
	 *
	 * @param string $action Nonce action name.
	 */
	private static function verify_nonce( $action ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Standard form-handler gate: capability check, then nonce verification.
	 *
	 * Every admin_post handler opens with this pair (OPT-DUP-D), so call
	 * self::guard( 'pltt_xxx' ) instead of repeating both checks. Dies on failure.
	 *
	 * @param string $action Nonce action name.
	 */
	private static function guard( $action ) {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( $action );
	}

	/**
	 * Redirect back to referer with query args.
	 *
	 * @param array $args Query arguments to add to redirect URL.
	 */
	private static function redirect_back( $args = array() ) {
		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = pltt_get_admin_url( 'clients' );
		}
		$redirect_url = add_query_arg( $args, $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle client update form submission.
	 */
	public static function handle_update_client() {
		self::guard( 'pltt_update_client' );

		$client_id   = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $client_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_client_id' ) );
		}

		$update_data = array(
			'name'        => $name,
			'description' => $description,
		);

		// Pass hourly_rate through — empty string clears it to NULL.
		// TRC-13: '' is the documented signal to clear the column. The data
		// layer routes it through pltt_set_nullable_fields() to write a real NULL
		// (wpdb->update cannot write NULL via %d/%f).
		if ( isset( $_POST['hourly_rate'] ) ) {
			$raw_rate = wp_unslash( $_POST['hourly_rate'] );
			if ( '' === $raw_rate ) {
				$update_data['hourly_rate'] = '';
			} else {
				// SEC-M7: validate at the handler boundary.
				$rate_float = floatval( $raw_rate );
				$valid_rate = pltt_validate_hourly_rate( $rate_float );
				if ( is_wp_error( $valid_rate ) ) {
					self::redirect_back( array( 'pltt_error' => 'invalid_rate' ) );
				}
				$update_data['hourly_rate'] = $rate_float;
			}
		}

		$result = PLTT_Clients::update( $client_id, $update_data );

		if ( is_wp_error( $result ) ) {
			// TRC-API1: WP_Error is truthy — guard it before the success branch so a
			// validation failure (e.g. empty name) isn't reported as "client_updated".
			// SEC-M2: redirect with the error code only, never the raw message.
			self::redirect_back( array( 'pltt_error' => $result->get_error_code() ) );
		}

		// Apply alias chip-manager changes (seed adds, prune removes). Independent
		// of whether other client fields changed.
		$alias_add    = isset( $_POST['aliases_add'] ) ? (array) wp_unslash( $_POST['aliases_add'] ) : array();
		$alias_remove = isset( $_POST['aliases_remove'] ) ? (array) wp_unslash( $_POST['aliases_remove'] ) : array();
		pltt_apply_alias_chip_changes( $alias_add, $alias_remove, $client_id );

		if ( $result || ! empty( $alias_add ) || ! empty( $alias_remove ) ) {
			self::redirect_back( array( 'pltt_message' => 'client_updated' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'client_update_failed' ) );
		}
	}

	/**
	 * Handle client deletion form submission.
	 */
	public static function handle_delete_client() {
		self::guard( 'pltt_delete_client' );

		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;

		if ( empty( $client_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_client_id' ) );
		}

		$result = PLTT_Clients::delete( $client_id );

		if ( is_wp_error( $result ) ) {
			// SEC-M2: Use only the error code (not the raw message) in the redirect URL.
			self::redirect_back( array( 'pltt_error' => $result->get_error_code() ) );
		} elseif ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'client_deleted' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'client_delete_failed' ) );
		}
	}

	/**
	 * Handle project update form submission.
	 */
	public static function handle_update_project() {
		self::guard( 'pltt_update_project' );

		$project_id  = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $project_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_project_id' ) );
		}

		// SEC-M2: status allowlist — the data layer rejects unknown values too,
		// but reject early at the handler boundary.
		if ( '' !== $status && ! in_array( $status, array( 'active', 'archived' ), true ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_status' ) );
		}

		$data = array();
		if ( ! empty( $name ) ) {
			$data['name'] = $name;
		}
		if ( ! empty( $status ) ) {
			$data['status'] = $status;
		}
		if ( isset( $_POST['description'] ) ) {
			$data['description'] = $description;
		}
		if ( isset( $_POST['hourly_rate'] ) ) {
			$raw_rate = wp_unslash( $_POST['hourly_rate'] );
			if ( '' === $raw_rate ) {
				$data['hourly_rate'] = '';
			} else {
				// SEC-M7: validate at the handler boundary.
				$rate_float = floatval( $raw_rate );
				$valid_rate = pltt_validate_hourly_rate( $rate_float );
				if ( is_wp_error( $valid_rate ) ) {
					self::redirect_back( array( 'pltt_error' => 'invalid_rate' ) );
				}
				$data['hourly_rate'] = $rate_float;
			}
		}
		if ( isset( $_POST['recurring_period'] ) ) {
			$recurring_period = sanitize_text_field( wp_unslash( $_POST['recurring_period'] ) );
			if ( ! in_array( $recurring_period, PLTT_ALLOWED_RECURRING_PERIODS, true ) ) {
				self::redirect_back( array( 'pltt_error' => 'invalid_recurring_period' ) );
				return;
			}
			$data['recurring_period'] = $recurring_period;
		}
		if ( isset( $_POST['budget_hours'] ) ) {
			$raw_hours = wp_unslash( $_POST['budget_hours'] );
			$data['budget_hours'] = '' === $raw_hours ? '' : floatval( $raw_hours );
		}
		if ( isset( $_POST['budget_fee'] ) ) {
			$raw_fee = wp_unslash( $_POST['budget_fee'] );
			$data['budget_fee'] = '' === $raw_fee ? '' : floatval( $raw_fee );
		}
		// Mutual exclusion: if fee is set to a real value, clear hours and vice versa.
		if ( ! empty( $data['budget_fee'] ) ) {
			$data['budget_hours'] = '';
		}
		if ( ! empty( $data['budget_hours'] ) ) {
			$data['budget_fee'] = '';
		}
		// Non-billable checkbox: present means non-billable (billability_default=0); absent means billable (billability_default=1).
		// Gate on 'name' being in the payload so archive-only submits don't clobber this field.
		if ( isset( $_POST['name'] ) ) {
			$data['billability_default'] = isset( $_POST['non_billable'] ) ? 0 : 1;
		}
		// Client reassignment (project detail Settings tab exposes a client select).
		// The data layer validates non-empty; gate on presence so status-only submits
		// (archive/restore) don't touch it.
		if ( isset( $_POST['client_id'] ) ) {
			$data['client_id'] = absint( $_POST['client_id'] );
		}

		$result = PLTT_Projects::update( $project_id, $data );

		if ( is_wp_error( $result ) ) {
			// SEC-M2: use only the error code (not the raw message) in the redirect URL.
			self::redirect_back( array( 'pltt_error' => $result->get_error_code() ) );
		} elseif ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'project_updated' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'project_update_failed' ) );
		}
	}

	/**
	 * Handle project deletion.
	 */
	public static function handle_delete_project() {
		self::guard( 'pltt_delete_project' );

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

		if ( empty( $project_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_project_id' ) );
		}

		$result = PLTT_Projects::delete( $project_id );

		if ( is_wp_error( $result ) ) {
			// SEC-M2: Use only the error code (not the raw message) in the redirect URL.
			self::redirect_back( array( 'pltt_error' => $result->get_error_code() ) );
		} elseif ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'project_deleted' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'project_delete_failed' ) );
		}
	}

	/**
	 * Handle tag creation.
	 */
	public static function handle_create_tag() {
		self::guard( 'pltt_manage_tag' );

		$tag_name = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$tag_name = strtolower( trim( $tag_name ) );

		$group_name = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( empty( $tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		// SEC-M8: enforce the varchar(100) cap explicitly.
		if ( mb_strlen( $tag_name ) > 100 ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_too_long', $redirect_url ) );
			exit;
		}

		// Check for duplicate.
		if ( PLTT_Tags::get_by_name( $tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		$result = PLTT_Tags::create( $tag_name, $group_name );

		if ( $result ) {
			// Seed any keyword chips supplied for the new tag.
			$kw_add = isset( $_POST['aliases_add'] ) ? (array) wp_unslash( $_POST['aliases_add'] ) : array();
			pltt_apply_tag_keyword_changes( $kw_add, array(), (int) $result );
			wp_safe_redirect( add_query_arg( 'pltt_message', 'tag_created', $redirect_url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_create_failed', $redirect_url ) );
		}
		exit;
	}

	/**
	 * Handle tag rename.
	 */
	public static function handle_rename_tag() {
		self::guard( 'pltt_manage_tag' );

		$tag_id  = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
		$new_tag = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$new_tag = strtolower( trim( $new_tag ) );

		// The group field is always present on submit from the modal — accept '' (clear).
		// If it's not present at all in the POST body, leave the group untouched.
		$group_arg = array_key_exists( 'group_name', $_POST )
			? sanitize_text_field( wp_unslash( $_POST['group_name'] ) )
			: false;

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( ! $tag_id || empty( $new_tag ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		// SEC-M8: enforce the varchar(100) cap.
		if ( mb_strlen( $new_tag ) > 100 ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_too_long', $redirect_url ) );
			exit;
		}

		// Check if new name already exists (different tag).
		$existing = PLTT_Tags::get_by_name( $new_tag );
		if ( $existing && (int) $existing->id !== $tag_id ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		$success = PLTT_Tags::rename( $tag_id, $new_tag, $group_arg );

		// Apply keyword chip changes (seed adds, prune removes scoped to this tag).
		$kw_add    = isset( $_POST['aliases_add'] ) ? (array) wp_unslash( $_POST['aliases_add'] ) : array();
		$kw_remove = isset( $_POST['aliases_remove'] ) ? (array) wp_unslash( $_POST['aliases_remove'] ) : array();
		pltt_apply_tag_keyword_changes( $kw_add, $kw_remove, $tag_id );

		if ( $success || ! empty( $kw_add ) || ! empty( $kw_remove ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_message', 'tag_renamed', $redirect_url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_rename_failed', $redirect_url ) );
		}
		exit;
	}

	/**
	 * Handle tag deletion.
	 */
	public static function handle_delete_tag() {
		self::guard( 'pltt_manage_tag' );

		$tag_id = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( ! $tag_id ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		$success = PLTT_Tags::delete( $tag_id );

		if ( $success ) {
			wp_safe_redirect( add_query_arg( 'pltt_message', 'tag_deleted', $redirect_url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_delete_failed', $redirect_url ) );
		}
		exit;
	}

	/**
	 * Handle save entries form submission from review screen.
	 */
	public static function handle_save_entries() {
		self::guard( 'pltt_save_entries' );

		// SEC-M3: strict date — this handler writes to entries scoped by date.
		$date = isset( $_POST['date'] ) ? pltt_sanitize_date_strict( wp_unslash( $_POST['date'] ) ) : '';

		// Handle entries - may be JSON string or already decoded array.
		$entries = array();
		if ( isset( $_POST['entries'] ) ) {
			$entries_raw = wp_unslash( $_POST['entries'] );
			if ( is_string( $entries_raw ) ) {
				$entries = json_decode( $entries_raw, true );
			} elseif ( is_array( $entries_raw ) ) {
				$entries = $entries_raw;
			}
		}

		if ( empty( $date ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_date' ) );
		}

		if ( empty( $entries ) || ! is_array( $entries ) ) {
			self::redirect_back( array( 'pltt_error' => 'no_entries' ) );
		}

		// SEC-H3: cap entries count to bound mass-corruption window from a forged submit.
		if ( count( $entries ) > 200 ) {
			self::redirect_back( array( 'pltt_error' => 'too_many_entries' ) );
		}

		$result = PLTT_Review::save_entries( $date, $entries );

		if ( $result['success'] ) {
			$return_to = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( $_POST['return_to'] ) ) : '';
			$default   = pltt_get_admin_url( 'daily-log', array( 'date' => $date ) );

			if ( $return_to ) {
				$redirect_url = wp_validate_redirect( $return_to, $default );
			} else {
				$redirect_url = $default;
			}

			$redirect_url = add_query_arg( array( 'pltt_message' => 'entries_saved' ), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			self::redirect_back( array( 'pltt_error' => 'save_failed' ) );
		}
	}

	/**
	 * Commit one billing record from the billing surface.
	 *
	 * The verify-and-commit step: nothing writes a record except this handler.
	 * `calculated` is ALWAYS recomputed server-side via PLTT_Billing, but the
	 * posted amount is taken as given — it may fall below the calculation
	 * (absorption; zero fully absorbs the scope) or rise above it (a rounded-up
	 * invoice). The calculation seeds the default, it doesn't cap the figure.
	 */
	public static function handle_commit_billing() {
		self::guard( 'pltt_commit_billing' );

		$project_id    = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$billing_type  = isset( $_POST['billing_type'] ) ? sanitize_key( wp_unslash( $_POST['billing_type'] ) ) : '';
		$period        = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$posted_amount = isset( $_POST['billed_amount'] ) ? floatval( wp_unslash( $_POST['billed_amount'] ) ) : 0.0;
		$excluded_ids  = isset( $_POST['excluded_entry_ids'] )
			? array_values( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['excluded_entry_ids'] ) ) ) ) ) )
			: array();
		$description   = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$redirect = $project_id > 0 ? PLTT_Project_Detail::get_url( $project_id ) : pltt_get_admin_url( 'projects' );

		// Retainer records are scoped to a period (its first day); hourly is scoped
		// to the viewed date range it billed.
		$period_start = ( 'retainer_overage' === $billing_type && '' !== $period )
			? pltt_sanitize_date_strict( $period )
			: null;
		$date_from = isset( $_POST['date_from'] ) && '' !== $_POST['date_from'] ? pltt_sanitize_date_strict( wp_unslash( $_POST['date_from'] ) ) : null;
		$date_to   = isset( $_POST['date_to'] ) && '' !== $_POST['date_to'] ? pltt_sanitize_date_strict( wp_unslash( $_POST['date_to'] ) ) : null;

		// The date the invoice actually went out; blank/invalid falls back to today
		// in the data layer.
		$marked_at = isset( $_POST['marked_at'] ) ? pltt_sanitize_date_strict( wp_unslash( $_POST['marked_at'] ) ) : '';

		// Single shared write path (recomputes the scope server-side).
		$result = PLTT_Billing::commit(
			array(
				'project_id'    => $project_id,
				'billing_type'  => $billing_type,
				'period_start'  => $period_start,
				'date_from'     => $date_from,
				'date_to'       => $date_to,
				'billed_amount' => $posted_amount,
				'excluded_entry_ids' => $excluded_ids,
				'description'   => $description,
				'marked_at'     => $marked_at,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', $result->get_error_code(), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'pltt_message', 'billed', $redirect ) );
		exit;
	}

}

