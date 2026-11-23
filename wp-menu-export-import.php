<?php
/**
 * Plugin Name: WP Menu Export Import
 * Plugin URI: https://github.com/Azanumer/wp-menu-export-import
 * Description: Export WordPress navigation menus (structure, items and locations) to JSON and import them on another site. Moving a site? Take your menus with you.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-menu-export-import
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WMEI_VERSION', '1.0.0' );

/**
 * Export the selected menus as a JSON-ready array.
 *
 * @param int[] $menu_ids Menu term IDs to export.
 * @return array Export payload (version, menus, locations).
 */
function wmei_export_menus( array $menu_ids ) {
    $payload = array(
        'version'   => 1,
        'exported'  => gmdate( 'Y-m-d H:i:s' ),
        'menus'     => array(),
        'locations' => array(),
    );

    $locations = get_nav_menu_locations();
    $by_id     = array_flip( $locations );

    foreach ( $menu_ids as $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            continue;
        }

        $items = array();
        foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
            $items[] = array(
                'id'          => (int) $item->ID,
                'title'       => $item->title,
                'url'         => $item->url,
                'type'        => $item->type,
                'object'      => $item->object,
                'object_id'   => (int) $item->object_id,
                'parent'      => (int) $item->menu_item_parent,
                'position'    => (int) $item->menu_order,
                'target'      => $item->target,
                'attr_title'  => $item->attr_title,
                'classes'     => implode( ' ', (array) $item->classes ),
                'xfn'         => $item->xfn,
                'description' => $item->description,
                'status'      => $item->post_status,
            );
        }

        $payload['menus'][] = array(
            'name'      => $menu->name,
            'slug'      => $menu->slug,
            'locations' => isset( $by_id[ $menu_id ] ) ? array( $by_id[ $menu_id ] ) : array(),
            'items'     => $items,
        );
    }

    $payload['locations'] = array_keys( $locations );

    return $payload;
}

/**
 * Import menus from a decoded export payload.
 *
 * Custom links are always importable. Post/page/category links are matched
 * by object type + slug on the target site; anything that cannot be matched
 * is imported as a custom link to the original URL so nothing is lost.
 *
 * @param array $payload Decoded export payload.
 * @param bool  $dry_run  When true, only report what would happen.
 * @return array Report: created menus, item counts, skipped locations, unmatched.
 */
function wmei_import_menus( array $payload, $dry_run = false ) {
    $report = array(
        'menus_created'   => array(),
        'items_imported'  => 0,
        'items_unmatched' => 0,
        'locations_set'   => array(),
        'locations_missing' => array(),
    );

    if ( empty( $payload['menus'] ) || ! is_array( $payload['menus'] ) ) {
        return $report;
    }

    $registered = get_registered_nav_menus();
    $locations  = get_nav_menu_locations();

    foreach ( $payload['menus'] as $menu_data ) {
        $name = sanitize_text_field( $menu_data['name'] ?? '' );
        if ( '' === $name ) {
            continue;
        }

        $menu_id = $dry_run ? 0 : wp_create_nav_menu( $name );
        if ( ! $dry_run && is_wp_error( $menu_id ) ) {
            continue;
        }

        if ( ! $dry_run ) {
            $report['menus_created'][] = $name;
        }

        // Map old parent IDs to new item IDs so hierarchy survives.
        // Two passes: create every item first (parents may come after
        // children in the export), then wire up the hierarchy.
        $id_map  = array();
        $created = array(); // new item ID => export parent ID.

        foreach ( $menu_data['items'] as $item ) {
            $args = array(
                'menu-item-title'     => sanitize_text_field( $item['title'] ?? '' ),
                'menu-item-url'       => esc_url_raw( $item['url'] ?? '' ),
                'menu-item-target'    => sanitize_text_field( $item['target'] ?? '' ),
                'menu-item-attr-title' => sanitize_text_field( $item['attr_title'] ?? '' ),
                'menu-item-classes'   => sanitize_text_field( $item['classes'] ?? '' ),
                'menu-item-xfn'       => sanitize_text_field( $item['xfn'] ?? '' ),
                'menu-item-description' => sanitize_text_field( $item['description'] ?? '' ),
                'menu-item-status'    => 'publish',
                'menu-item-position'  => (int) ( $item['position'] ?? 0 ),
            );

            $type      = $item['type'] ?? 'custom';
            $object    = $item['object'] ?? 'custom';
            $object_id = (int) ( $item['object_id'] ?? 0 );
            $matched   = false;

            if ( 'post_type' === $type && $object_id > 0 ) {
                // Match by slug so the same post/page on the target site links up.
                $slug = get_post_field( 'post_name', $object_id );
                if ( $slug ) {
                    $found = get_page_by_path( $slug, OBJECT, $object );
                    if ( $found ) {
                        $args['menu-item-object-id'] = $found->ID;
                        $args['menu-item-object']    = $object;
                        $args['menu-item-type']      = 'post_type';
                        $matched = true;
                    }
                }
            } elseif ( 'taxonomy' === $type && $object_id > 0 ) {
                $term = get_term( $object_id, $object );
                if ( $term && ! is_wp_error( $term ) ) {
                    $found = get_term_by( 'slug', $term->slug, $object );
                    if ( $found ) {
                        $args['menu-item-object-id'] = $found->term_id;
                        $args['menu-item-object']    = $object;
                        $args['menu-item-type']      = 'taxonomy';
                        $matched = true;
                    }
                }
            }

            if ( ! $matched ) {
                // Custom link fallback: keeps the item (and its URL) instead of dropping it.
                $args['menu-item-type'] = 'custom';
                if ( 'custom' !== $type ) {
                    $report['items_unmatched']++;
                }
            }

            if ( $dry_run ) {
                $report['items_imported']++;
                continue;
            }

            $new_id = wp_update_nav_menu_item( $menu_id, 0, $args );
            if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
                // Map the export's item ID to the new item ID so children
                // (whose 'parent' holds the export ID) attach correctly.
                $id_map[ (int) ( $item['id'] ?? 0 ) ] = $new_id;
                $created[ $new_id ] = (int) ( $item['parent'] ?? 0 );
                $report['items_imported']++;
            }
        }

        // Second pass: attach children to their (now existing) parents.
        if ( ! $dry_run ) {
            foreach ( $created as $new_id => $export_parent ) {
                if ( $export_parent > 0 && isset( $id_map[ $export_parent ] ) ) {
                    wp_update_nav_menu_item(
                        $menu_id,
                        $new_id,
                        array( 'menu-item-parent-id' => $id_map[ $export_parent ] )
                    );
                }
            }
        }

        // Assign locations the active theme actually registers.
        foreach ( (array) ( $menu_data['locations'] ?? array() ) as $location ) {
            if ( isset( $registered[ $location ] ) ) {
                if ( ! $dry_run ) {
                    $locations[ $location ] = $menu_id;
                }
                $report['locations_set'][] = $location;
            } else {
                $report['locations_missing'][] = $location;
            }
        }
    }

    if ( ! $dry_run && ! empty( $report['locations_set'] ) ) {
        set_theme_mod( 'nav_menu_locations', $locations );
    }

    $report['locations_set']     = array_unique( $report['locations_set'] );
    $report['locations_missing'] = array_unique( $report['locations_missing'] );

    return $report;
}

/**
 * Tools page: export form + import form + result display.
 */
function wmei_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-menu-export-import' ) );
    }

    $notice = '';

    // ---- Export ---------------------------------------------------------
    if ( isset( $_POST['wmei_export'] ) && check_admin_referer( 'wmei_export' ) ) {
        $menu_ids = array_map( 'intval', (array) ( $_POST['wmei_menus'] ?? array() ) );
        if ( empty( $menu_ids ) ) {
            $notice = '<div class="notice notice-error"><p>' . esc_html__( 'Select at least one menu to export.', 'wp-menu-export-import' ) . '</p></div>';
        } else {
            $payload  = wmei_export_menus( $menu_ids );
            $filename = 'menus-export-' . gmdate( 'Y-m-d' ) . '.json';
            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );
            echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            exit;
        }
    }

    // ---- Import ---------------------------------------------------------
    if ( isset( $_POST['wmei_import'] ) && check_admin_referer( 'wmei_import' ) ) {
        $dry_run = ! empty( $_POST['wmei_dry_run'] );

        if ( empty( $_FILES['wmei_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wmei_file']['tmp_name'] ) ) {
            $notice = '<div class="notice notice-error"><p>' . esc_html__( 'Choose a JSON export file first.', 'wp-menu-export-import' ) . '</p></div>';
        } else {
            $raw     = file_get_contents( $_FILES['wmei_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $payload = json_decode( $raw, true );
            if ( ! is_array( $payload ) || empty( $payload['menus'] ) ) {
                $notice = '<div class="notice notice-error"><p>' . esc_html__( 'That file does not look like a menu export.', 'wp-menu-export-import' ) . '</p></div>';
            } else {
                $report = wmei_import_menus( $payload, $dry_run );
                $notice = '<div class="notice notice-success"><p>' . sprintf(
                    /* translators: %1$d menus, %2$d items */
                    esc_html__( '%1$d menu(s) processed, %2$d item(s) imported.', 'wp-menu-export-import' ),
                    count( $report['menus_created'] ) + ( $dry_run ? count( $payload['menus'] ) : 0 ),
                    $report['items_imported']
                ) . '</p>';
                if ( $report['items_unmatched'] > 0 ) {
                    $notice .= '<p>' . sprintf(
                        /* translators: %d unmatched items */
                        esc_html__( '%d item(s) could not be matched to a post/page/category here and were imported as custom links.', 'wp-menu-export-import' ),
                        $report['items_unmatched']
                    ) . '</p>';
                }
                if ( ! empty( $report['locations_missing'] ) ) {
                    $notice .= '<p>' . sprintf(
                        /* translators: %s location names */
                        esc_html__( 'These locations are not registered by the active theme and were skipped: %s', 'wp-menu-export-import' ),
                        esc_html( implode( ', ', $report['locations_missing'] ) )
                    ) . '</p>';
                }
                if ( $dry_run ) {
                    $notice .= '<p><strong>' . esc_html__( 'Dry run — nothing was imported.', 'wp-menu-export-import' ) . '</strong></p>';
                }
                $notice .= '</div>';
            }
        }
    }

    $menus     = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Menu Export / Import', 'wp-menu-export-import' ); ?></h1>
        <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <h2><?php esc_html_e( 'Export', 'wp-menu-export-import' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'wmei_export' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Menus', 'wp-menu-export-import' ); ?></th>
                    <td>
                        <?php foreach ( $menus as $menu ) : ?>
                            <label>
                                <input type="checkbox" name="wmei_menus[]" value="<?php echo (int) $menu->term_id; ?>" />
                                <?php echo esc_html( $menu->name ); ?>
                                <?php if ( in_array( $menu->term_id, $locations, true ) ) : ?>
                                    <em>(<?php echo esc_html( array_search( $menu->term_id, $locations, true ) ); ?>)</em>
                                <?php endif; ?>
                            </label><br />
                        <?php endforeach; ?>
                        <?php if ( empty( $menus ) ) : ?>
                            <p><?php esc_html_e( 'No menus exist yet.', 'wp-menu-export-import' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Download JSON export', 'wp-menu-export-import' ), 'primary', 'wmei_export' ); ?>
        </form>

        <hr />

        <h2><?php esc_html_e( 'Import', 'wp-menu-export-import' ); ?></h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'wmei_import' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wmei_file"><?php esc_html_e( 'Export file', 'wp-menu-export-import' ); ?></label></th>
                    <td><input type="file" id="wmei_file" name="wmei_file" accept=".json,application/json" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Dry run', 'wp-menu-export-import' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wmei_dry_run" value="1" checked />
                            <?php esc_html_e( 'Preview only — do not create anything yet.', 'wp-menu-export-import' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <p class="description">
                <?php esc_html_e( 'Post, page and category links are matched by slug on this site. Anything that cannot be matched is imported as a custom link, so no menu item is ever lost.', 'wp-menu-export-import' ); ?>
            </p>
            <?php submit_button( __( 'Import menus', 'wp-menu-export-import' ), 'primary', 'wmei_import' ); ?>
        </form>
    </div>
    <?php
}

function wmei_register_menu() {
    add_management_page(
        __( 'Menu Export / Import', 'wp-menu-export-import' ),
        __( 'Menu Export / Import', 'wp-menu-export-import' ),
        'manage_options',
        'wp-menu-export-import',
        'wmei_admin_page'
    );
}
add_action( 'admin_menu', 'wmei_register_menu' );
