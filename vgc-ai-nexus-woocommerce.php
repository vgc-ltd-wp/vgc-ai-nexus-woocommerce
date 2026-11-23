<?php
/**
 * Plugin Name:       VGC AI Nexus for WooCommerce
 * Plugin URI:        https://tools.vgc-ltd.com
 * Description:       Extends VGC AI Nexus with WooCommerce tools. Gives AI agents the ability to manage products, product categories, product tags, orders, customers and coupons through MCP. Requires VGC AI Nexus and WooCommerce.
 * Version:           1.14.0
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
define( 'MCP_WOO_VERSION', '1.14.0' );
require_once __DIR__ . '/includes/class-vgc-plugin-updater.php'; // fallback when core is missing or older than 2.37
require_once __DIR__ . '/includes/class-woo-access.php';
// AI Nexus core's updater (2.37.0+) takes a package only from the update channel and
// checks its sha256. This plugin loads before core, so it is made once all plugins are in.
add_action( 'plugins_loaded', static function (): void {
    $args = [ __FILE__, 'vgc-ai-nexus-woocommerce', MCP_WOO_VERSION, 'https://raw.githubusercontent.com/vgc-ltd-wp/vgc-plugin-updates/main/plugins.json' ];
    if ( class_exists( '\MCP_Abilities\Package_Guard' ) && class_exists( '\MCP_Abilities\VGC_Plugin_Updater' ) ) {
        new \MCP_Abilities\VGC_Plugin_Updater( ...$args );
    } else {
        new \VGC_Plugin_Updater( ...$args );
    }
}, 1 );


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
    // Built on first use (init or later). Building translates labels, and
    // doing it here on plugins_loaded logs "translation loaded too early".
    $abilities = static function (): array {
        static $list = null;
        return $list ??= mcp_woo_discover_abilities();
    };

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
        if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'woocommerce' ) ) {
            return;
        }
        wp_register_ability_category( 'woocommerce', [
            'label'       => __( 'WooCommerce', 'mcp-abilities-woo' ),
            'description' => __( 'WooCommerce product, order, customer and coupon management.', 'mcp-abilities-woo' ),
        ] );
    } );

    // ── 2. Register each ability with the WP Abilities API ────────────────────
    add_action( 'wp_abilities_api_init', function () use ( $abilities ): void {
        foreach ( $abilities() as $ability ) {
            $name = 'woocommerce/' . mcp_woo_ability_slug( $ability->get_key() );

            // Strip top-level 'type' — same fix as core plugin (v2.4.0).
            // WP REST validation: rest_is_object([]) = false for PHP [] (empty params),
            // so removing 'type' lets optional-only abilities accept empty {}.
            $wp_schema = $ability->get_input_schema();
            // Strict-client-valid schema: Claude Desktop 1.17377+ rejects the whole
            // connector if any tool schema lacks type:"object" or serializes properties
            // as []. Foreign MCP adapters (WooCommerce 10.9 bundles one; AIOSEO installs
            // one) can serve this raw registration verbatim, so it must be valid as stored.
            $wp_schema['type'] = 'object';
            if ( empty( $wp_schema['properties'] ) || ! is_array( $wp_schema['properties'] ) ) {
                $wp_schema['properties'] = [
                    'verbose' => [ 'type' => 'boolean', 'description' => 'Reserved for future use. Has no effect.' ],
                ];
            }

            wp_register_ability( $name, [
                'category'            => 'woocommerce',
                'label'               => $ability->get_label(),
                'description'         => $ability->get_description(),
                'input_schema'        => $wp_schema,
                'permission_callback' => function () use ( $ability ) {
                    return $ability->current_user_can();
                },
                'execute_callback'    => function ( $params = [] ) use ( $ability ) {
                    return mcp_woo_run( $ability, (array) $params );
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
        foreach ( $abilities() as $ability ) {
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

    static $loaded_by_us = [];

    foreach ( $dirs as $dir ) {
        $slug       = basename( $dir );
        $group_file = trailingslashit( $dir ) . 'class-group-' . $slug . '.php';

        if ( ! file_exists( $group_file ) ) {
            continue;
        }


        // "product-taxonomy" → "Product_Taxonomy_Group"
        $class_suffix = str_replace( '-', '_', $slug );
        $class_name   = 'MCP_Abilities\\Groups\\' . ucwords( $class_suffix, '_' ) . '_Group';

        // Group classes share the MCP_Abilities\Groups namespace with core and
        // every other extension. If a class with this name already exists and WE
        // didn't define it, loading our file would fatal with "cannot redeclare"
        // (this took a site down when an extension group slug matched a core
        // group). Skip the foreign collision gracefully instead.
        if ( ! isset( $loaded_by_us[ $class_name ] ) ) {
            if ( class_exists( $class_name, false ) ) {
                continue;
            }
            require_once $group_file;
            $loaded_by_us[ $class_name ] = true;
        }

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
 * An order status without its "wc-" prefix: "wc-completed" → "completed". Removes the
 * prefix only; ltrim( $s, 'wc-' ) also ate the leading c of "completed" and "cancelled".
 */
function mcp_woo_order_status_slug( string $status ): string {
    return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
}

/**
 * Runs an ability for both the WordPress ability and the MCP tool (1.9.1).
 *
 * Core's own tools re-check read-only mode and the input schema just before
 * running; these two callbacks call the ability directly, so they do the same.
 * With tool consolidation on, each tool is core's dispatcher, which checks the
 * chosen action itself.
 *
 * A parameter the tool does not have is named in the answer ("ignored_parameters" and
 * a "warning"), as core's own tools do (1.14.0): before, it was dropped without a word
 * and a list filtered by a wrongly spelt parameter looked filtered. The dispatcher
 * does this itself against the chosen action's own parameters.
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function mcp_woo_run( \MCP_Abilities\Ability $ability, array $params ): array {
    if ( method_exists( $ability, 'blocked_by_read_only' ) && $ability->blocked_by_read_only() ) {
        return [
            'success' => false,
            'error'   => class_exists( '\MCP_Abilities\Read_Only' )
                ? \MCP_Abilities\Read_Only::error_message( $ability->get_key() )
                : 'AI Nexus is in read-only mode on this site.',
        ];
    }
    $dispatcher = $ability instanceof \MCP_Abilities\Crud_Ability;
    if ( ! $dispatcher && method_exists( $ability, 'validate_params' ) ) {
        $checked = $ability->validate_params( $params );
        if ( is_string( $checked ) ) {
            return [ 'success' => false, 'error' => $checked ];
        }
        $params = $checked;
    }
    $result = $ability->execute( $params );
    if ( ! $dispatcher && method_exists( $ability, 'annotate_ignored_params' ) ) {
        $result = $ability->annotate_ignored_params( $result, $params );
    }
    return $result;
}

/**
 * The page size and page of a list call (1.14.0), or why they are refused.
 *
 * absint() turned per_page -1 ("all", as WP_Query reads it) into 1 and page -2 into 2;
 * a page size above the tool's most was cut down without a word. Now -1 and anything
 * above $max give $max, and the note says so; 0, other negatives and a page below 1
 * are refused.
 *
 * @param array<string,mixed> $params
 * @return array{0:int,1:int,2:?string}|string [ per_page, page, note or null ], or the error.
 */
function mcp_woo_paging( array $params, int $default, int $max ) {
    $per  = $params['per_page'] ?? $default;
    $page = $params['page'] ?? 1;
    if ( null === $per || '' === $per ) {
        $per = $default;
    }
    if ( null === $page || '' === $page ) {
        $page = 1;
    }
    if ( ! is_numeric( $per ) || (int) $per != $per ) { // phpcs:ignore Universal.Operators.StrictComparisons -- "20" and 20.0 are 20.
        return sprintf( 'per_page must be a whole number from 1 to %d (or -1 for %d at once).', $max, $max );
    }
    if ( ! is_numeric( $page ) || (int) $page != $page || (int) $page < 1 ) { // phpcs:ignore Universal.Operators.StrictComparisons
        return 'page must be a whole number, 1 or more.';
    }
    $per  = (int) $per;
    $note = null;
    if ( -1 === $per ) {
        $per  = $max;
        $note = sprintf( 'per_page -1 ("all") gives the most this tool returns at once: %d. "total" says how many there are; ask for the next page with "page".', $max );
    } elseif ( $per < 1 ) {
        return sprintf( 'per_page must be from 1 to %d (or -1 for %d at once), not %d.', $max, $max, $per );
    } elseif ( $per > $max ) {
        $note = sprintf( 'per_page %d is more than this tool returns at once, so %d were asked for. "total" says how many there are; ask for the next page with "page".', $per, $max );
        $per  = $max;
    }
    return [ $per, (int) $page, $note ];
}

/**
 * The stock statuses this shop's WooCommerce accepts (1.14.0): instock, outofstock,
 * onbackorder and any a plugin adds (woocommerce_product_stock_status_options).
 * WooCommerce's set_stock_status() saves "instock" for any other value.
 *
 * @return string[]
 */
function mcp_woo_stock_statuses(): array {
    $options = function_exists( 'wc_get_product_stock_status_options' ) ? (array) wc_get_product_stock_status_options() : [];
    $keys    = array_values( array_filter( array_map( 'strval', array_keys( $options ) ) ) );
    return $keys ? $keys : [ 'instock', 'outofstock', 'onbackorder' ];
}

/**
 * A price as it may be stored, or why it is refused (1.14.0).
 *
 * wc_format_decimal() made "abc" or "free" into "" (the price erased, the product no
 * longer for sale) and "19,99" into 1999 on a shop that writes "." for decimals. Now a
 * plain number: digits with "." for decimals ("19.99", or a JSON number), or "," when
 * that is the shop's decimal separator; no thousands separators, currency signs or minus.
 * "" clears the price, as before.
 *
 * @param mixed $value
 * @return array{0:?string,1:?string} [ price to store or null, error or null ]
 */
function mcp_woo_price( string $param, $value ): array {
    if ( is_bool( $value ) || ! is_scalar( $value ) ) {
        return [ null, sprintf( 'Invalid %s: send a number as text, e.g. "19.99", or "" to clear the price.', $param ) ];
    }
    $raw = trim( (string) $value );
    if ( '' === $raw ) {
        return [ '', null ];
    }
    $sep   = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
    $plain = preg_match( '/^\d+(\.\d+)?$/', $raw ) || ( ',' === $sep && preg_match( '/^\d+,\d+$/', $raw ) );
    if ( ! $plain ) {
        return [ null, sprintf(
            'Invalid %s "%s": send a plain number with "." for decimals, e.g. "19.99" (no thousands separators, currency signs or minus). Send "" to clear the price.',
            $param,
            $raw
        ) ];
    }
    return [ wc_format_decimal( $raw ), null ];
}

/**
 * Add a sentence to the answer's "note", after any note it already has (1.14.0).
 *
 * @param array<string,mixed> $out
 * @return array<string,mixed>
 */
function mcp_woo_add_note( array $out, ?string $note ): array {
    if ( null === $note || '' === $note ) {
        return $out;
    }
    $out['note'] = isset( $out['note'] ) && '' !== (string) $out['note'] ? $out['note'] . ' ' . $note : $note;
    return $out;
}

/**
 * Items a list left out because this account may not read them (1.14.0): the page is
 * shorter than per_page, and "total" counts them. Before, nothing said so.
 */
function mcp_woo_left_out_note( int $count, string $item ): ?string {
    if ( $count < 1 ) {
        return null;
    }
    return 1 === $count
        ? sprintf( '1 %s on this page is left out: this account may not read it. The page is shorter than per_page, and "total" counts it.', $item )
        : sprintf( '%d %ss on this page are left out: this account may not read them. The page is shorter than per_page, and "total" counts them.', $count, $item );
}

/**
 * A term description as it is handed to WordPress (1.14.0).
 *
 * sanitize_textarea_field() took every tag out (links, bold, italic) with no word. Now
 * WordPress's own rule for term descriptions, as on its admin screens: it keeps basic
 * markup (links, bold, italic, code, quotes) and removes the rest (<p>, scripts, styles)
 * for every account (the pre_term_description filter). For an account that may not post
 * unfiltered HTML, or while raw code is locked, the same rule is applied here first, in
 * case a plugin lifted WordPress's filter. Whatever was removed is said in a note, decided
 * from what was stored: mcp_woo_term_description_note(). Slashed: wp_insert_term() and
 * wp_update_term() unslash, so every backslash was lost.
 */
function mcp_woo_term_description( string $sent ): string {
    $raw = class_exists( '\MCP_Abilities\Custom_Code' ) && method_exists( '\MCP_Abilities\Custom_Code', 'raw_allowed' )
        ? \MCP_Abilities\Custom_Code::raw_allowed()
        : current_user_can( 'unfiltered_html' );
    return wp_slash( $raw ? $sent : wp_kses( $sent, 'pre_term_description' ) );
}

/** The note for a term description that was not stored as sent, or null (1.14.0). */
function mcp_woo_term_description_note( string $sent, string $stored ): ?string {
    if ( $stored === $sent ) {
        return null;
    }
    if ( class_exists( '\MCP_Abilities\Custom_Code' ) && method_exists( '\MCP_Abilities\Custom_Code', 'filtered_away' ) ) {
        $removed = \MCP_Abilities\Custom_Code::filtered_away( $sent, $stored );
    } else {
        // Core before 2.49.0: the tags and attribute names, in order ("&" written as
        // "&amp;" or "<br/>" as "<br />" loses nothing).
        $outline = static function ( string $html ): array {
            preg_match_all( '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/', $html, $tags, PREG_SET_ORDER );
            $out = [];
            foreach ( $tags as $t ) {
                preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=/', $t[3], $attrs );
                $out[] = $t[1] . strtolower( $t[2] ) . ':' . strtolower( implode( ',', $attrs[1] ) );
            }
            return $out;
        };
        $removed = $outline( $sent ) !== $outline( $stored );
    }
    if ( ! $removed ) {
        return null;
    }
    return 'WordPress keeps only basic markup in a term description (links, bold, italic, code, quotes), so the rest was removed before saving: "description" is what was stored.';
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
            return mcp_woo_run( $ability, (array) $params );
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

// ── Usage guide section ──────────────────────────────────────────────────────
// Contributes this extension's section (and its anti-patterns) to the guide
// served by core's usage_guide ability, so an AI caller can read what this
// extension can do instead of inferring it.
add_filter( 'mcp_abilities_guide_sections', function ( array $sections ): array {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return $sections;
    }
    $body = <<<'MD'
Manages the WooCommerce catalogue and orders: woocommerce-product-management,
woocommerce-product-taxonomy, woocommerce-order-management,
woocommerce-customer-management and woocommerce-coupon-management. Each takes an
action parameter selecting a wc_ prefixed operation.

Anti-patterns:

- WooCommerce 10.9 and later registers its OWN abilities too, named
  woocommerce/... such as products-query and product-update. Those are a
  different and smaller set. The tools listed above are the AI Nexus ones and
  are what this guide documents.
- Products carry many taxonomies, including product attributes named
  pa_something. Call list_terms before filtering by one instead of guessing
  term slugs.
- Variations belong to a parent product. Read the parent with wc_get_product
  before changing a variation.
- Each product, order, coupon and customer is checked against the account's
  own permissions. One this account may not read answers "not found" and is
  left out of lists; it may exist for other accounts.
MD;
    $sections['woocommerce'] = [ 'title' => 'WooCommerce extension', 'body' => $body ];
    return $sections;
} );
