<?php
/*
	Plugin Name: Uncode Custom Fields Mapper
	Description: Adds a settings page to map custom fields to Uncode options with optional prefix/suffix.
	Author: Joe Thomas
	Version: 1.0.2
	
	GitHub Plugin URI: joethomas/uncode-custom-fields-mapper
	Primary Branch: main
*/

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/* Dependency & Activation
==============================================================================*/

// Determine whether or not Uncode theme is active.
function ucfm_is_uncode_active(): bool {
	return ( 'uncode' === get_template() );
}

// On activation, deactivate if Uncode theme is not active.
function ucfm_deactivate_self_with_notice( $message, $wp_die = false ) {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );

	if ( $wp_die ) {
		wp_die( $message, 'Plugin dependency check', [ 'back_link' => true ] );
	} else {
		add_action( 'admin_notices', function () use ( $message ) {
			echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
		} );
	}
}

// Block activation if Uncode isn't active right now.
register_activation_hook( __FILE__, function () {
	if ( ! ucfm_is_uncode_active() ) {
		ucfm_deactivate_self_with_notice(
			'<strong>Uncode Custom Fields Mapper</strong> requires the <em>Uncode</em> parent theme to be active before activation.',
			true // wp_die to block activation
		);
	}
 });

// Guard every load in case Uncode is switched later.
if ( ! ucfm_is_uncode_active() ) {
	ucfm_deactivate_self_with_notice(
		'<strong>Uncode Custom Fields Mapper</strong> requires the <em>Uncode</em> parent theme. The plugin has been deactivated.'
	);
	return; // Stop loading the rest of the plugin.
}


/* Admin Menu
==============================================================================*/

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


/* Settings Page
==============================================================================*/

// Save settings.
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

// Render the settings page.
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


/* Styles
==============================================================================*/

// Admin CSS/JS for Page Options chips on the post editor screens
add_action( 'admin_enqueue_scripts', function( $hook ) {
	// Only load on the classic post editor screens
	if ($hook !== 'post.php' && $hook !== 'post-new.php') {
		return;
	}

	// CSS (your chip styling)
	$css = '
	#poststuff .format-setting-label .label label {
		padding: 2px 6px 3px;
		vertical-align: 5%;
		background-color: #f0f0f0;
		border-radius: 10px;
		font-size: .675em;
		color: #252b31;
		text-transform: none;
	}
	';

	// JS: convert [text] => <label>text</label>
	$js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
  var scope = document.getElementById('poststuff');
  if (!scope) return;

  var nodes = scope.querySelectorAll('.format-setting-label .label');
  nodes.forEach(function (node) {
    // Skip if there is already a <label> to avoid double-wrapping
    // (We still process text nodes to allow multiple chips.)
    node.childNodes.forEach(function (n) {
      if (n.nodeType === Node.TEXT_NODE) {
        var txt = n.textContent;
        if (txt && txt.indexOf('[') !== -1) {
          // Replace all [something] with <label>something</label>
          var frag = document.createElement('span');
          frag.innerHTML = txt.replace(/\\s*\\[(.+?)\\]\\s*/g, ' <label>$1</label> ');
          node.replaceChild(frag, n);
        }
      }
    } );
  } );
} );
JS;

	// Enqueue a handle with inline CSS/JS
	wp_register_style('ucfm-admin', false);
	wp_enqueue_style('ucfm-admin');
	wp_add_inline_style('ucfm-admin', $css);

	wp_register_script('ucfm-admin', false, [], false, true);
	wp_enqueue_script('ucfm-admin');
	wp_add_inline_script('ucfm-admin', $js);
});
