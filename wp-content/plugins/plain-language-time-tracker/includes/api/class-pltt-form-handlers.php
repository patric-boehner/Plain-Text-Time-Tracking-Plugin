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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_update_client' );

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
		if ( isset( $_POST['hourly_rate'] ) ) {
			$raw_rate = wp_unslash( $_POST['hourly_rate'] );
			$update_data['hourly_rate'] = '' === $raw_rate ? '' : floatval( $raw_rate );
		}

		$result = PLTT_Clients::update( $client_id, $update_data );

		if ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'client_updated' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'client_update_failed' ) );
		}
	}

	/**
	 * Handle client deletion form submission.
	 */
	public static function handle_delete_client() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_delete_client' );

		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;

		if ( empty( $client_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_client_id' ) );
		}

		$result = PLTT_Clients::delete( $client_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_back(
				array(
					'pltt_error'         => $result->get_error_code(),
					'pltt_error_message' => $result->get_error_message(),
				)
			);
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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_update_project' );

		$project_id  = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $project_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_project_id' ) );
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
			$data['hourly_rate'] = '' === $raw_rate ? '' : floatval( $raw_rate );
		}
		if ( isset( $_POST['recurring_period'] ) ) {
			$data['recurring_period'] = sanitize_text_field( wp_unslash( $_POST['recurring_period'] ) );
		}
		if ( isset( $_POST['budget_hours'] ) ) {
			$raw_hours = wp_unslash( $_POST['budget_hours'] );
			$data['budget_hours'] = '' === $raw_hours ? '' : floatval( $raw_hours );
		}
		// Non-billable checkbox: present means non-billable (billability_default=0); absent means billable (billability_default=1).
		// Gate on 'name' being in the payload so archive-only submits don't clobber this field.
		if ( isset( $_POST['name'] ) ) {
			$data['billability_default'] = isset( $_POST['non_billable'] ) ? 0 : 1;
		}

		$result = PLTT_Projects::update( $project_id, $data );

		if ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'project_updated' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'project_update_failed' ) );
		}
	}

	/**
	 * Handle project deletion.
	 */
	public static function handle_delete_project() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_delete_project' );

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

		if ( empty( $project_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_project_id' ) );
		}

		$result = PLTT_Projects::delete( $project_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_back(
				array(
					'pltt_error'         => $result->get_error_code(),
					'pltt_error_message' => $result->get_error_message(),
				)
			);
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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_manage_tag' );

		$tag_name = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$tag_name = strtolower( trim( $tag_name ) );

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( empty( $tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		// Check for duplicate.
		if ( PLTT_Tags::get_by_name( $tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		$result = PLTT_Tags::create( $tag_name );

		if ( $result ) {
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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_manage_tag' );

		$tag_id  = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
		$new_tag = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$new_tag = strtolower( trim( $new_tag ) );

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( ! $tag_id || empty( $new_tag ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		// Check if new name already exists (different tag).
		$existing = PLTT_Tags::get_by_name( $new_tag );
		if ( $existing && (int) $existing->id !== $tag_id ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		$success = PLTT_Tags::rename( $tag_id, $new_tag );

		if ( $success ) {
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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_manage_tag' );

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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'Unauthorized', 'plain-language-time-tracker' ), '', array( 'response' => 403 ) );
		}
		self::verify_nonce( 'pltt_save_entries' );

		$date = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';

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

}

