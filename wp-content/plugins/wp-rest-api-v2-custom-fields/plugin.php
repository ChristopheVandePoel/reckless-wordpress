<?php

/**
 * Plugin Name: WP REST API V2 Custom Post Types
 * Description: Adds Advanced Custom Post Types to WP REST API V2 JSON output.
 * Version: 0.1
 * Author: Deyan Vatsov
 * Plugin URI: https://github.com/Vatsov/wp-rest-api-v2-custom-post-types/
 */

if ( !function_exists( 'is_plugin_active' ) ) {
	require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
}

if ( is_plugin_active('rest-api/plugin.php') ) {
	new CustomPostDataPlugin();
}

class CustomPostDataPlugin {
	public function __construct() {
		// Add Meta Fields to Posts
		add_action('rest_api_init', array( $this, 'add_custom_data' ) );
	}

	function add_custom_data() {
		// Register the post type fields
		register_rest_field('post', 'custom_fields', array(
				'get_callback' => array( $this, 'get_custom_data' ),
				'update_callback' => array( $this, 'update_custom_data' ),
				'schema' => array(
					'description' => 'My custom field',
					'type' => 'string',
					'context' => array('view', 'edit')
				)
			)
		);
	}

	/**
	 * Handler for getting custom data.
	 *
	 */
	function get_custom_data($object, $field_name, $request) {
		if ( function_exists('get_fields') ) {
			return get_fields($object['id']);
		}
	}

	 /**
	 * Handler for updating custom data.
	 */
	function update_custom_data($value, $post, $field_name) {
		if (!$value || !is_string($value)) {
			return;
		}

		return update_post_meta($post->ID, $field_name, strip_tags($value));
	}

}
