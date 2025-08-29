<?php
/**
 * Plugin Name: Uncode Custom Fields Mapper
 * Description: Adds a settings page to map custom fields to Uncode options with optional prefix/suffix.
 * Author: Joe Thomas
 * Version: 1.0
 */

/**
 * Hard dependency on Uncode theme.
 */
function ucfm_is_uncode_active() {
	return ( 'uncode' === get_template() );
}
	$parent = $theme->parent();
	if ( $parent && ( strtolower( $parent->get( 'Stylesheet' ) ) === 'uncode' || strtolower( $parent->get( 'Template' ) ) === 'uncode' ) ) {
		return true;
	}
	return false;
}

register_activation_hook( __FILE__, 'ucfm_on_activation' );
function ucfm_on_activation() {
	if ( 'uncode' !== get_template() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( '<p><strong>Uncode Custom Fields Mapper</strong> requires the <em>Uncode</em> parent theme to be active before activation.</p>', 'Plugin dependency check', [ 'back_link' => true ] );
	}
}

if ( ! ucfm_is_uncode_active() ) {
	add_action( 'admin_init', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
	} );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Uncode Custom Fields Mapper</strong> requires the <em>Uncode</em> theme (parent or child) to be active. The plugin has been deactivated.</p></div>';
	} );

	// Stop loading rest of plugin if Uncode is not active.
	return;
}
// Register admin menu
add_action( 'admin_menu', function () {
	$parent = 'uncode';
	if ( ! isset( $GLOBALS['admin_page_hooks'][ $parent ] ) ) {
		$parent = 'uncode-system-status';
	}
	if ( ! isset( $GLOBALS['admin_page_hooks'][ $parent ] ) ) {
		$parent = 'themes.php'; // Fallback under Appearance if Uncode menu is unavailable
	}
	add_submenu_page(
		$parent,
		'CFM Mapper',
		'CFM Mapper',
		'manage_options',
		'ucfm-mapper',
		'ucfm_render_admin_page'
	);
}, 99 );

// Save settings
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ucfm_field_mapping_nonce'] ) && wp_verify_nonce( $_POST['ucfm_field_mapping_nonce'], 'ucfm_field_mapping' ) ) {
		$raw   = isset( $_POST['ucfm_mapping'] ) ? (array) $_POST['ucfm_mapping'] : [];
		$clean = [];
		foreach ( $raw as $post_type => $fields ) {
			$post_type = sanitize_key( $post_type );
			foreach ( (array) $fields as $meta_key => $cfg ) {
				$mapped_option = isset( $cfg['mapped_option'] ) ? sanitize_text_field( $cfg['mapped_option'] ) : '';
				$prefix        = isset( $cfg['prefix'] ) ? wp_kses_post( $cfg['prefix'] ) : '';
				$suffix        = isset( $cfg['suffix'] ) ? wp_kses_post( $cfg['suffix'] ) : '';
				$clean[ $post_type ][ $meta_key ] = [
					'mapped_option' => $mapped_option,
					'prefix'        => $prefix,
					'suffix'        => $suffix,
				];
			}
		}
		update_option( 'ucfm_field_mappings', $clean );
		add_settings_error( 'ucfm_extras', 'ucfm_saved', __( 'Mappings saved.', 'default' ), 'updated' );
	}
} );

function ucfm_render_admin_page() {
	global $wpdb;

	$post_types = get_post_types( [ 'public' => true, 'show_ui' => true ], 'names' );
	$mappings   = get_option( 'ucfm_field_mappings', [] );

	// List of supported Uncode options
	$uncode_options = [
		''                            => '[None]',
		'_uncode_logo_light'         => 'Logo – Light',
		'_uncode_logo_dark'          => 'Logo – Dark',
		'_uncode_logo_height'        => 'Logo – Height',
		'_uncode_logo_height_mobile' => 'Logo – Height (Mobile)',
	];

	echo '<div class="wrap"><h1>CFM Mapper</h1>'; settings_errors('ucfm_extras');
	echo '<form method="post">';
	wp_nonce_field( 'ucfm_field_mapping', 'ucfm_field_mapping_nonce' );
	echo '<table class="widefat fixed striped">
			<thead>
				<tr>
					<th>Post Type</th>
					<th>Custom Field</th>
					<th>Mapped Uncode Option</th>
					<th>Prefix</th>
					<th>Suffix</th>
				</tr>
			</thead>
			<tbody>';

	foreach ( $post_types as $type ) {
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_status = 'publish' LIMIT 100",
			$type
		) );

		if ( empty( $post_ids ) ) continue;

		$ids = implode( ',', array_map( 'absint', $post_ids ) );
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE post_id IN ($ids) AND meta_key NOT LIKE '\\_%' ORDER BY meta_key ASC"
		);

		foreach ( $meta_keys as $meta_key ) {
			$map   = $mappings[$type][$meta_key] ?? [];
			echo '<tr>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . esc_html( $meta_key ) . '</td>';

			echo '<td><select name="ucfm_mapping[' . esc_attr( $type ) . '][' . esc_attr( $meta_key ) . '][mapped_option]">';
			foreach ( $uncode_options as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $map['mapped_option'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
		echo '</select></td>';

		echo '<td><input type="text" name="ucfm_mapping[' . esc_attr( $type ) . '][' . esc_attr( $meta_key ) . '][prefix]" value="' . esc_attr( $map['prefix'] ?? '' ) . '" /></td>';
		echo '<td><input type="text" name="ucfm_mapping[' . esc_attr( $type ) . '][' . esc_attr( $meta_key ) . '][suffix]" value="' . esc_attr( $map['suffix'] ?? '' ) . '" /></td>';
		echo '</tr>';
		}
	}

	echo '</tbody></table>';
	echo '<p><button class="button-primary">Save Changes</button></p>';
	echo '</form></div>';
}

// Use mappings in your filter
add_filter( 'uncode_ot_get_option', function ( $val, $option_id ) {
	if ( ! is_singular() ) return $val;

	global $post;
	if ( ! $post instanceof WP_Post ) return $val;

	$type     = $post->post_type;
	$mappings = get_option( 'ucfm_field_mappings', [] );

	if ( isset( $mappings[ $type ] ) ) {
		foreach ( $mappings[ $type ] as $meta_key => $config ) {
			if ( isset( $config['mapped_option'] ) && $config['mapped_option'] === $option_id ) {
				$value = get_post_meta( $post->ID, $meta_key, true );
				if ( $value !== '' ) {
					$val = ($config['prefix'] ?? '') . $value . ($config['suffix'] ?? '');
				}
			}
		}
	}

	return $val;
}, 10, 2 );
