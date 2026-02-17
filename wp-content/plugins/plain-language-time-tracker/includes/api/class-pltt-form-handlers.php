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

		// Review screen.
		add_action( 'admin_post_pltt_save_entries', array( __CLASS__, 'handle_save_entries' ) );

		// Tag operations.
		add_action( 'admin_post_pltt_create_tag', array( __CLASS__, 'handle_create_tag' ) );
		add_action( 'admin_post_pltt_rename_tag', array( __CLASS__, 'handle_rename_tag' ) );
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
			$update_data['hourly_rate'] = wp_unslash( $_POST['hourly_rate'] );
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
		self::verify_nonce( 'pltt_delete_client' );

		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;

		if ( empty( $client_id ) ) {
			self::redirect_back( array( 'pltt_error' => 'invalid_client_id' ) );
		}

		$result = PLTT_Clients::delete( $client_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_back(
				array(
					'pltt_error'         => 'client_has_projects',
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
			$data['hourly_rate'] = wp_unslash( $_POST['hourly_rate'] );
		}

		$result = PLTT_Projects::update( $project_id, $data );

		if ( $result ) {
			self::redirect_back( array( 'pltt_message' => 'project_updated' ) );
		} else {
			self::redirect_back( array( 'pltt_error' => 'project_update_failed' ) );
		}
	}

	/**
	 * Handle tag creation.
	 */
	public static function handle_create_tag() {
		self::verify_nonce( 'pltt_manage_tag' );

		$tag_name = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$tag_name = strtolower( trim( $tag_name ) );

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( empty( $tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		// Check if tag already exists.
		$all_tags = PLTT_Entries::get_all_tags();
		if ( in_array( $tag_name, $all_tags, true ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		// Tags are stored in entries, so "creating" a tag means adding it to the available pool.
		// We store it by creating a transient that gets merged with get_all_tags results.
		$custom_tags   = get_option( 'pltt_custom_tags', array() );
		$custom_tags[] = $tag_name;
		$custom_tags   = array_unique( $custom_tags );
		update_option( 'pltt_custom_tags', $custom_tags );

		wp_safe_redirect( add_query_arg( 'pltt_message', 'tag_created', $redirect_url ) );
		exit;
	}

	/**
	 * Handle tag rename.
	 */
	public static function handle_rename_tag() {
		self::verify_nonce( 'pltt_manage_tag' );

		$old_tag = isset( $_POST['old_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['old_tag'] ) ) : '';
		$new_tag = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$old_tag = strtolower( trim( $old_tag ) );
		$new_tag = strtolower( trim( $new_tag ) );

		$redirect_url = admin_url( 'admin.php?page=pltt-tags' );

		if ( empty( $old_tag ) || empty( $new_tag ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'invalid_tag', $redirect_url ) );
			exit;
		}

		if ( $old_tag === $new_tag ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Check if new tag name already exists.
		$all_tags = PLTT_Entries::get_all_tags();
		if ( in_array( $new_tag, $all_tags, true ) ) {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_exists', $redirect_url ) );
			exit;
		}

		$success = PLTT_Entries::rename_tag( $old_tag, $new_tag );

		// Also update custom tags list if the old tag was a custom tag.
		$custom_tags = get_option( 'pltt_custom_tags', array() );
		$key         = array_search( $old_tag, $custom_tags, true );
		if ( false !== $key ) {
			$custom_tags[ $key ] = $new_tag;
			update_option( 'pltt_custom_tags', array_values( array_unique( $custom_tags ) ) );
		}

		if ( $success ) {
			wp_safe_redirect( add_query_arg( 'pltt_message', 'tag_renamed', $redirect_url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'pltt_error', 'tag_rename_failed', $redirect_url ) );
		}
		exit;
	}

	/**
	 * Handle save entries form submission from review screen.
	 */
	public static function handle_save_entries() {
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
			wp_die( esc_html__( 'Invalid date.', 'plain-language-time-tracker' ), '', array( 'response' => 400 ) );
		}

		if ( empty( $entries ) || ! is_array( $entries ) ) {
			wp_die( esc_html__( 'No entries to save.', 'plain-language-time-tracker' ), '', array( 'response' => 400 ) );
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
			wp_die( esc_html( $result['message'] ?? __( 'Failed to save entries.', 'plain-language-time-tracker' ) ), '', array( 'response' => 500 ) );
		}
	}
}

