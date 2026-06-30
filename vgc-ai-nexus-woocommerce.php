<?php
/**
 * Plugin Name:       VGC AI Nexus for WooCommerce
 * Plugin URI:        https://tools.vgc-ltd.com
 * Description:       Extends VGC AI Nexus with WooCommerce tools. Gives AI agents the ability to manage products, product categories, product tags, orders, customers and coupons through MCP. Requires VGC AI Nexus and WooCommerce.
 * Version:           1.7.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            VGC
 * Author URI:        https://tools.vgc-ltd.com
 * License:           GPL v2 or later
 * Text Domain:       mcp-abilities-woo
 * Requires Plugins:  vgc-ai-nexus, woocommerce
 */

defined( 'ABSPATH' ) || exit;

/* VGC self-hosted updates: shows the native WordPress "Update now" button (no token; manifest is public). */
require_once __DIR__ . '/includes/class-vgc-plugin-updater.php';
new \VGC_Plugin_Updater( __FILE__, 'vgc-ai-nexus-woocommerce', '1.7.1', 'https://raw.githubusercontent.com/vgc-ltd-wp/vgc-plugin-updates/main/plugins.json' );


define( 'MCP_WOO_VERSION', '1.7.1' );
define( 'MCP_WOO_FILE',    __FILE__ );
define( 'MCP_WOO_DIR',     plugin_dir_path( __FILE__ ) );

/**
 * Boot after the main MCP Abilities plugin so its base classes (Ability, Ability_Group)
 * are available. We then register our own independent category, ability names, and
 * McpTool instances — mirroring exactly what the core plugin does for its own abilities.
 */
add_action( 'mcp_abilities_loaded', function ( \MCP_Abilities\Plugin $core_plugin ): void {

    if ( ! function_exists( 'WC' ) ) {
        add_action( 'admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'MCP Abilities – WooCommerce requires WooCommerce to be active.', 'mcp-abilities-woo' )
                . '</p></div>';
        } );
        return;
    }

    // Textdomain must load at init, not plugins_loaded — deferred here.
    add_action( 'init', function (): void {
        load_plugin_textdomain( 'mcp-abilities-woo', false, dirname( plugin_basename( MCP_WOO_FILE ) ) . '/languages' );
    } );

    // ── Discover enabled WooCommerce abilities for adapter registration ──────
    $abilities = mcp_woo_discover_abilities();

    // ── Register with the admin Extensions page ───────────────────────────────
    add_filter( 'mcp_abilities_extensions', function ( array $extensions ): array {
        $groups = mcp_woo_get_groups(); // fresh instances with saved settings applied
        if ( empty( $groups ) ) {
            return $extensions;
        }
        $extensions[] = [
            'id'          => 'woocommerce',
            'label'       => __( 'WooCommerce', 'mcp-abilities-woo' ),
            'description' => __( 'WooCommerce product, order, customer and coupon management.', 'mcp-abilities-woo' ),
            'icon'        => 'dashicons-cart',
            'groups'      => $groups,
            'option_key'  => 'mcp_woo_settings',
        ];
        return $extensions;
    } );

    // ── 1. Register our category ──────────────────────────────────────────────
    add_action( 'wp_abilities_api_categories_init', function (): void {
        wp_register_ability_category( 'woocommerce', [
            'label'       => __( 'WooCommerce', 'mcp-abilities-woo' ),
            'description' => __( 'WooCommerce product, order, customer and coupon management.', 'mcp-abilities-woo' ),
        ] );
    } );

    // ── 2. Register each ability with the WP Abilities API ────────────────────
    add_action( 'wp_abilities_api_init', function () use ( $abilities ): void {
        foreach ( $abilities as $ability ) {
            $name = 'woocommerce/' . mcp_woo_ability_slug( $ability->get_key() );

            // Strip top-level 'type' — same fix as core plugin (v2.4.0).
            // WP REST validation: rest_is_object([]) = false for PHP [] (empty params),
            // so removing 'type' lets optional-only abilities accept empty {}.
            $wp_schema = $ability->get_input_schema();
            unset( $wp_schema['type'] );

            wp_register_ability( $name, [
                'category'            => 'woocommerce',
                'label'               => $ability->get_label(),
                'description'         => $ability->get_description(),
                'input_schema'        => $wp_schema,
                'permission_callback' => function () use ( $ability ) {
                    return $ability->current_user_can();
                },
                'execute_callback'    => function ( $params = [] ) use ( $ability ) {
                    return $ability->execute( (array) $params );
                },
                'meta' => [ 'mcp' => [ 'public' => true ] ],
            ] );
        }
    } );

    // ── 3. Inject McpTool instances into the default MCP server config ─────────
    // Uses McpTool::fromArray (callable-backed) to bypass wp_get_ability() timing.
    // Falls back to string ability names if McpTool::fromArray is unavailable
    // (e.g. WooCommerce bundles an older McpTool class without fromArray).
    add_filter( 'mcp_adapter_default_server_config', function ( array $config ) use ( $abilities ): array {
        foreach ( $abilities as $ability ) {
            $tool = mcp_woo_make_tool( $ability );
            if ( null !== $tool ) {
                $config['tools'][] = $tool;
            }
        }
        return $config;
    } );

} );

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Scan MCP_WOO_DIR/abilities/ and return all Ability_Group instances.
 * Saved settings (from 'mcp_woo_settings' option) are applied to each group
 * and ability so enabled/disabled states are respected everywhere.
 *
 * @return \MCP_Abilities\Ability_Group[]
 */
function mcp_woo_get_groups(): array {
    $settings = get_option( 'mcp_woo_settings', [] );
    $dirs     = glob( MCP_WOO_DIR . 'abilities/*', GLOB_ONLYDIR ) ?: [];
    $groups   = [];

    foreach ( $dirs as $dir ) {
        $slug       = basename( $dir );
        $group_file = trailingslashit( $dir ) . 'class-group-' . $slug . '.php';

        if ( ! file_exists( $group_file ) ) {
            continue;
        }

        require_once $group_file;

        // "product-taxonomy" → "Product_Taxonomy_Group"
        $class_suffix = str_replace( '-', '_', $slug );
        $class_name   = 'MCP_Abilities\\Groups\\' . ucwords( $class_suffix, '_' ) . '_Group';

        if ( ! class_exists( $class_name ) ) {
            continue;
        }

        /** @var \MCP_Abilities\Ability_Group $group */
        $group = new $class_name();

        // Apply persisted enabled/disabled states from the option.
        $group_settings = $settings[ $group->get_slug() ] ?? [];
        $group->set_enabled( $group_settings['enabled'] ?? true );
        foreach ( $group->get_abilities() as $ability ) {
            $ability->set_enabled( $group_settings['abilities'][ $ability->get_key() ] ?? true );
        }

        $groups[] = $group;
    }

    return $groups;
}

/**
 * Return only the enabled abilities — used for runtime adapter registration.
 *
 * @return \MCP_Abilities\Ability[]
 */
function mcp_woo_discover_abilities(): array {
    $abilities = [];
    $has_crud  = class_exists( '\MCP_Abilities\Crud_Ability' );
    foreach ( mcp_woo_get_groups() as $group ) {
        if ( ! $group->is_enabled() ) {
            continue;
        }
        // get_mcp_abilities() honours the site-wide consolidation toggle (AI Nexus
        // >= 2.7.0): a multi-ability group is returned as a single Crud_Ability
        // dispatcher. On older AI Nexus that lacks the method, fall back to the
        // individual abilities so a version mismatch degrades instead of fataling.
        $list = method_exists( $group, 'get_mcp_abilities' )
            ? $group->get_mcp_abilities()
            : $group->get_abilities();

        foreach ( $list as $ability ) {
            $is_dispatcher = $has_crud && $ability instanceof \MCP_Abilities\Crud_Ability;
            if ( $is_dispatcher || $ability->is_enabled() ) {
                $abilities[] = $ability;
            }
        }
    }
    return $abilities;
}

/**
 * Convert an ability key to the URL slug portion of the WP ability name.
 * Strips leading 'wc_' prefix so keys like 'wc_list_products' → 'list-products',
 * keeping the full namespace: 'woocommerce/list-products'.
 */
function mcp_woo_ability_slug( string $key ): string {
    $key = preg_replace( '/^wc_/', '', $key );
    return str_replace( '_', '-', $key );
}

/**
 * Build an McpTool instance for direct injection into the server config.
 *
 * McpNameSanitizer replaces '/' with '-', so we pre-sanitize the name.
 * Falls back to the string ability name when McpTool::fromArray is unavailable.
 *
 * @return \WP\MCP\Domain\Tools\McpTool|string|null
 */
function mcp_woo_make_tool( \MCP_Abilities\Ability $ability ) {
    $ability_name = 'woocommerce/' . mcp_woo_ability_slug( $ability->get_key() );

    if ( ! class_exists( '\WP\MCP\Domain\Tools\McpTool' )
        || ! method_exists( '\WP\MCP\Domain\Tools\McpTool', 'fromArray' ) ) {
        // McpTool::fromArray not available — adapter resolves via wp_get_ability() instead.
        return $ability_name;
    }

    // Pre-sanitize: 'woocommerce/list-products' → 'woocommerce-list-products'
    $tool_name    = str_replace( '/', '-', $ability_name );
    $input_schema = $ability->get_input_schema();

    // Normalize array-syntax 'type' values (e.g. ['object','array']) to a plain string.
    // McpTool::fromArray validates JSON Schema and may reject non-string type values,
    // returning WP_Error and forcing a fallback to string names (which fail on WP 6.9
    // because wp_get_ability() runs before wp_abilities_api_init registers the ability).
    if ( isset( $input_schema['type'] ) && is_array( $input_schema['type'] ) ) {
        $input_schema['type'] = 'object';
    }

    $tool = \WP\MCP\Domain\Tools\McpTool::fromArray( [
        'name'        => $tool_name,
        'description' => $ability->get_description(),
        'inputSchema' => $input_schema,
        'handler'     => function ( $params = [] ) use ( $ability ) {
            return $ability->execute( (array) $params );
        },
        'permission'  => function () use ( $ability ) {
            return $ability->current_user_can();
        },
    ] );

    if ( $tool instanceof \WP_Error ) {
        return $ability_name;
    }

    return $tool;
}
