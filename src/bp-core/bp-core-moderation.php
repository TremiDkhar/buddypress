<?php
/**
 * BuddyPress Moderation Functions.
 *
 * @package BuddyPress
 * @subpackage Core
 * @since 1.6.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/** Moderation ****************************************************************/

/**
 * Check for flooding.
 *
 * Check to make sure that a user is not making too many posts in a short amount
 * of time.
 *
 * @since 1.6.0
 *
 * @param int $user_id User id to check for flood.
 * @return bool True if there is no flooding, false if there is.
 */
function bp_core_check_for_flood( $user_id = 0 ) {

	// Option disabled. No flood checks.
	if ( !$throttle_time = bp_get_option( '_bp_throttle_time' ) ) {
		return true;
	}

	// Bail if no user ID passed.
	if ( empty( $user_id ) ) {
		return false;
	}

	$last_posted = get_user_meta( $user_id, '_bp_last_posted', true );
	if ( isset( $last_posted ) && ( time() < ( $last_posted + $throttle_time ) ) && !current_user_can( 'throttle' ) ) {
		return false;
	}

	return true;
}

/**
 * Check for moderation keys and too many links.
 *
 * @since 1.6.0
 * @since 2.6.0 Added $error_type parameter.
 *
 * @param int    $user_id    User ID.
 * @param string $title      The title of the content.
 * @param string $content    The content being posted.
 * @param string $error_type The error type to return. Either 'bool' or 'wp_error'.
 * @return bool|WP_Error True if test is passed, false if fail.
 */
function bp_core_check_for_moderation( $user_id = 0, $title = '', $content = '', $error_type = 'bool' ) {

	/**
	 * Filters whether or not to bypass checking for moderation keys and too many links.
	 *
	 * @since 2.2.0
	 *
	 * @param bool   $value   Whether or not to bypass checking. Default false.
	 * @param int    $user_id Topic of reply author ID.
	 * @param string $title   The title of the content.
	 * @param string $content $the content being posted.
	 */
	if ( apply_filters( 'bp_bypass_check_for_moderation', false, $user_id, $title, $content ) ) {
		return true;
	}

	// Bail if super admin is author.
	if ( is_super_admin( $user_id ) ) {
		return true;
	}

	// Define local variable(s).
	$_post     = array();
	$match_out = '';

	/** User Data ************************************************************
	 */

	if ( ! empty( $user_id ) ) {

		// Get author data.
		$user = get_userdata( $user_id );

		// If data exists, map it.
		if ( ! empty( $user ) ) {
			$_post['author'] = $user->display_name;
			$_post['email']  = $user->user_email;
			$_post['url']    = $user->user_url;
		}
	}

	// Current user IP and user agent.
	$_post['user_ip'] = bp_core_current_user_ip();
	$_post['user_ua'] = bp_core_current_user_ua();

	// Post title and content.
	$_post['title']   = $title;
	$_post['content'] = $content;

	/** Max Links ************************************************************
	 */

	$max_links = get_option( 'comment_max_links' );
	if ( ! empty( $max_links ) ) {

		// How many links?
		$num_links = preg_match_all( '/(http|ftp|https):\/\//i', $content, $match_out );

		// Allow for bumping the max to include the user's URL.
		if ( ! empty( $_post['url'] ) ) {

			/**
			 * Filters the maximum amount of links allowed to include the user's URL.
			 *
			 * @since 1.6.0
			 *
			 * @param string $num_links How many links found.
			 * @param string $value     User's url.
			 */
			$num_links = apply_filters( 'comment_max_links_url', $num_links, $_post['url'] );
		}

		// Das ist zu viele links!
		if ( $num_links >= $max_links ) {
			if ( 'bool' === $error_type ) {
				return false;
			} else {
				return new WP_Error( 'bp_moderation_too_many_links', __( 'You have posted too many links', 'buddypress' ) );
			}
		}
	}

	/** Blacklist ************************************************************
	 */

	// Get the moderation keys.
	$disallowed = trim( get_option( 'moderation_keys' ) );

	// Bail if list is empty.
	if ( ! empty( $disallowed ) ) {

		// Get words separated by new lines.
		$words = explode( "\n", $disallowed );

		// Loop through words.
		foreach ( (array) $words as $word ) {

			// Trim the whitespace from the word.
			$word = trim( $word );

			// Skip empty lines.
			if ( empty( $word ) ) {
				continue;
			}

			// Do some escaping magic so that '#' chars in the
			// spam words don't break things.
			$word    = preg_quote( $word, '#' );
			$pattern = "#$word#i";

			// Loop through post data.
			foreach ( $_post as $post_data ) {

				// Check each user data for current word.
				if ( preg_match( $pattern, $post_data ) ) {
					if ( 'bool' === $error_type ) {
						return false;
					} else {
						return new WP_Error( 'bp_moderation_word_match', _x( 'You have posted an inappropriate word.', 'Comment moderation', 'buddypress' ) );
					}
				}
			}
		}
	}

	// Check passed successfully.
	return true;
}

/**
 * Check for blocked keys.
 *
 * @since 7.0.0
 *
 * @param int    $user_id    User ID.
 * @param string $title      The title of the content.
 * @param string $content    The content being posted.
 * @param string $error_type The error type to return. Either 'bool' or 'wp_error'.
 * @return bool|WP_Error True if test is passed, false if fail.
 */
function bp_core_check_for_disallowed_keys( $user_id = 0, $title = '', $content = '', $error_type = 'bool' ) {

	/**
	 * Filters whether or not to bypass checking for blocked keys.
	 *
	 * @since 2.2.0
	 * @deprecated 7.0.0 Use 'bp_bypass_check_for_disallowed_keys' instead.
	 *
	 * @param bool   $value   Whether or not to bypass checking. Default false.
	 * @param int    $user_id Topic of reply author ID.
	 * @param string $title   The title of the content.
	 * @param string $content $the content being posted.
	 */
	if ( apply_filters_deprecated( 'bp_bypass_check_for_blacklist', array( false, $user_id, $title, $content ), '7.0.0', 'bp_bypass_check_for_disallowed_keys' ) ) {
		return true;
	}

	/**
	 * Filters whether or not to bypass checking for blocked keys.
	 *
	 * @since 7.0.0
	 *
	 * @param bool   $value   Whether or not to bypass checking. Default false.
	 * @param int    $user_id Topic of reply author ID.
	 * @param string $title   The title of the content.
	 * @param string $content $the content being posted.
	 */
	if ( apply_filters( 'bp_bypass_check_for_disallowed_keys', false, $user_id, $title, $content ) ) {
		return true;
	}

	// Bail if super admin is author.
	if ( is_super_admin( $user_id ) ) {
		return true;
	}

	// Define local variable.
	$_post = array();

	/** Blacklist ************************************************************
	 */

	// Get the moderation keys.
	$disallowed = get_option( 'disallowed_keys' );
	$disallowed = trim( $disallowed );

	// Bail if disallowed list is empty.
	if ( empty( $disallowed ) ) {
		return true;
	}

	/** User Data ************************************************************
	 */

	// Map current user data.
	if ( ! empty( $user_id ) ) {

		// Get author data.
		$user = get_userdata( $user_id );

		// If data exists, map it.
		if ( ! empty( $user ) ) {
			$_post['author'] = $user->display_name;
			$_post['email']  = $user->user_email;
			$_post['url']    = $user->user_url;
		}
	}

	// Current user IP and user agent.
	$_post['user_ip'] = bp_core_current_user_ip();
	$_post['user_ua'] = bp_core_current_user_ua();

	// Post title and content.
	$_post['title']   = $title;
	$_post['content'] = $content;

	/** Words ****************************************************************
	 */

	// Get words separated by new lines.
	$words = explode( "\n", $disallowed );

	// Loop through words.
	foreach ( (array) $words as $word ) {

		// Trim the whitespace from the word.
		$word = trim( $word );

		// Skip empty lines.
		if ( empty( $word ) ) { continue; }

		// Do some escaping magic so that '#' chars in the
		// spam words don't break things.
		$word    = preg_quote( $word, '#' );
		$pattern = "#$word#i";

		// Loop through post data.
		foreach( $_post as $post_data ) {

			// Check each user data for current word.
			if ( preg_match( $pattern, $post_data ) ) {
				if ( 'bool' === $error_type ) {
					return false;
				} else {
					return new WP_Error( 'bp_moderation_disallowed_key_match', _x( 'You have posted an inappropriate word.', 'Comment disallowed key', 'buddypress' ) );
				}
			}
		}
	}

	// Check passed successfully.
	return true;
}

/**
 * Get the current user's IP address.
 *
 * @since 1.6.0
 *
 * @return string IP address.
 */
function bp_core_current_user_ip() {
	$retval = preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] );

	/**
	 * Filters the current user's IP address.
	 *
	 * @since 1.6.0
	 *
	 * @param string $retval Current user's IP Address.
	 */
	return apply_filters( 'bp_core_current_user_ip', $retval );
}

/**
 * Get the current user's user-agent.
 *
 * @since 1.6.0
 *
 * @return string User agent string.
 */
function bp_core_current_user_ua() {

	// Sanity check the user agent.
	if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$retval = substr( $_SERVER['HTTP_USER_AGENT'], 0, 254 );
	} else {
		$retval = '';
	}

	/**
	 * Filters the current user's user-agent.
	 *
	 * @since 1.6.0
	 *
	 * @param string $retval Current user's user-agent.
	 */
	return apply_filters( 'bp_core_current_user_ua', $retval );
}
