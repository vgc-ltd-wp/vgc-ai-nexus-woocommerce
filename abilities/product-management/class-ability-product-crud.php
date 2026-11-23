<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use VGC\AI_Nexus_Woo\Access;

defined( 'ABSPATH' ) || exit;

// ── List Products ────────────────────────────────────────────────────────────

class List_Products_Ability extends Ability {

    /** The fields "fields" may name (1.11.0). */
    public const ITEM_FIELDS = [ 'id', 'name', 'sku', 'type', 'status', 'price', 'regular_price', 'sale_price', 'stock_status', 'stock_qty', 'permalink' ];

    protected function define_meta(): void {
        $this->key          = 'wc_list_products';
        $this->label        = __( 'List Products', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce products with filtering by status, SKU, category, stock and price. "sku" finds products by their exact SKU (several: comma-separated); "search" matches the name and description and also part of a SKU.';
        $this->required_cap = 'edit_products';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'    => [ 'type' => 'string',  'enum' => self::ALLOWED_STATUSES, 'default' => 'publish', 'description' => '"any" is every status but trash; "trash" lists the products in the trash.' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20, 'description' => '1 to 100; -1 gives 100 (the most at once). "total" says how many match.' ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'sku'       => [ 'type' => 'string',  'description' => 'Exact SKU, or several separated by commas ("BM-01,BM-02"). A variation\'s SKU finds its parent product.' ],
                'search'    => [ 'type' => 'string',  'description' => 'Words in the name or description, or part of a SKU.' ],
                'category'  => [ 'type' => 'string',  'description' => 'Product category slug (not its ID or name; wc_list_product_categories lists them). An unknown slug is refused.' ],
                'tag'       => [ 'type' => 'string',  'description' => 'Product tag slug (wc_list_product_tags lists them). An unknown slug is refused.' ],
                // Open lists (1.14.0): the shop's own stock statuses and product types, also
                // ones a plugin adds, so samples, not an enum. execute() checks the value.
                'stock'        => [ 'type' => 'string',  'examples' => [ 'instock', 'outofstock', 'onbackorder' ], 'description' => 'A stock status of this shop, e.g. instock, outofstock, onbackorder; a plugin may add more. An unknown one is refused with the valid ones.' ],
                'min_price'    => [ 'type' => 'number' ],
                'max_price'    => [ 'type' => 'number', 'description' => 'Highest price, inclusive; 0 lists the products that cost nothing.' ],
                'product_type' => [ 'type' => 'string',  'examples' => [ 'simple', 'variable', 'grouped', 'external' ], 'description' => 'A product type of this shop, e.g. simple, variable, grouped, external; plugins add others (subscription, bundle). An unknown one is refused with the valid ones.' ],
                'fields'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Only these fields of each product (id is always kept), e.g. ["id", "name", "sku"]: a smaller answer. Fields: ' . implode( ', ', self::ITEM_FIELDS ) . '.' ],
            ],
        ];
    }

    private const ALLOWED_STATUSES = [ 'publish', 'draft', 'private', 'pending', 'trash', 'any' ];

    public function execute( array $params ): array {
        $status = sanitize_key( (string) ( $params['status'] ?? '' ) );
        $status = '' === $status ? 'publish' : $status;
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            // Until 1.10.0 an unknown status quietly listed the published products instead.
            return $this->error( sprintf( 'Unknown status "%s": use one of %s.', $status, implode( ', ', self::ALLOWED_STATUSES ) ) );
        }

        // per_page -1 gave 1 product, and more than 100 was cut down with no word (1.14.0).
        $paging = mcp_woo_paging( $params, 20, 100 );
        if ( is_string( $paging ) ) {
            return $this->error( $paging );
        }
        [ $per_page, $page, $paging_note ] = $paging;

        // An unknown category, tag, product type or stock status gave an empty list with
        // no word, read as "no products in category X" (1.14.0): refused, saying what is valid.
        $filters = [];
        foreach ( [ 'category' => 'product_cat', 'tag' => 'product_tag' ] as $param => $taxonomy ) {
            if ( isset( $params[ $param ] ) && '' !== trim( (string) $params[ $param ] ) ) {
                [ $slug, $why ] = self::term_slug( $taxonomy, (string) $params[ $param ] );
                if ( null === $slug ) {
                    return $this->error( $why );
                }
                $filters[ $param ] = $slug;
            }
        }
        if ( isset( $params['product_type'] ) && '' !== trim( (string) $params['product_type'] ) ) {
            $type  = sanitize_key( (string) $params['product_type'] );
            $types = self::product_types();
            if ( ! in_array( $type, $types, true ) ) {
                return $this->error( sprintf( 'Unknown product_type "%s": this shop has %s.', (string) $params['product_type'], implode( ', ', $types ) ) );
            }
            $filters['product_type'] = $type;
        }
        if ( isset( $params['stock'] ) && '' !== trim( (string) $params['stock'] ) ) {
            $stock  = sanitize_key( (string) $params['stock'] );
            $stocks = mcp_woo_stock_statuses();
            if ( ! in_array( $stock, $stocks, true ) ) {
                return $this->error( sprintf( 'Unknown stock status "%s": this shop has %s.', (string) $params['stock'], implode( ', ', $stocks ) ) );
            }
            $filters['stock'] = $stock;
        }
        // max_price 0 ("free products") was taken as no filter (1.14.0).
        foreach ( [ 'min_price', 'max_price' ] as $param ) {
            if ( isset( $params[ $param ] ) && '' !== $params[ $param ] ) {
                if ( ! is_numeric( $params[ $param ] ) ) {
                    return $this->error( sprintf( '%s must be a number, e.g. 19.99.', $param ) );
                }
                $filters[ $param ] = (float) $params[ $param ];
            }
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
        ];

        // SKU (1.10.0): "sku" is an exact match, "search" also matches part of a SKU.
        // Both look at the product's own SKU and its variations' SKUs.
        $candidates = null;
        $skus       = isset( $params['sku'] ) && is_scalar( $params['sku'] )
            ? array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $params['sku'] ) ) ), 'strlen' ) )
            : [];
        if ( $skus ) {
            $candidates = self::ids_by_sku( $skus, false );
        }
        if ( isset( $params['search'] ) && is_scalar( $params['search'] ) && '' !== trim( (string) $params['search'] ) ) {
            $term     = sanitize_text_field( (string) $params['search'] );
            $sku_hits = self::ids_by_sku( [ $term ], true );
            if ( $sku_hits ) {
                $text_hits  = get_posts( [
                    'post_type'      => 'product',
                    'post_status'    => $status,
                    's'              => $term,
                    'fields'         => 'ids',
                    'posts_per_page' => -1,
                    'no_found_rows'  => true,
                ] );
                $found      = array_values( array_unique( array_merge( array_map( 'intval', $text_hits ), $sku_hits ) ) );
                $candidates = null === $candidates ? $found : array_values( array_intersect( $candidates, $found ) );
            } else {
                $args['s'] = $term;
            }
        }
        if ( null !== $candidates ) {
            $args['post__in'] = $candidates ? $candidates : [ 0 ];
        }

        $tax_query = [];
        foreach ( [ 'category' => 'product_cat', 'tag' => 'product_tag', 'product_type' => 'product_type' ] as $param => $taxonomy ) {
            if ( isset( $filters[ $param ] ) ) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $filters[ $param ],
                ];
            }
        }
        if ( $tax_query ) {
            $args['tax_query'] = $tax_query;
        }

        $meta_query = [];
        if ( isset( $filters['stock'] ) ) {
            $meta_query[] = [ 'key' => '_stock_status', 'value' => $filters['stock'] ];
        }
        if ( isset( $filters['min_price'] ) && $filters['min_price'] > 0 ) { // 0: no lower bound, as before
            $meta_query[] = [ 'key' => '_price', 'value' => $filters['min_price'], 'compare' => '>=', 'type' => 'NUMERIC' ];
        }
        if ( isset( $filters['max_price'] ) ) {
            $meta_query[] = [ 'key' => '_price', 'value' => $filters['max_price'], 'compare' => '<=', 'type' => 'NUMERIC' ];
        }
        if ( $meta_query ) {
            $args['meta_query'] = $meta_query;
        }

        // Only what this account may read: other authors' unpublished products need the
        // permission to edit others' products (1.9.0).
        $scope = Access::scope_list_query( $args );
        if ( null !== $scope['error'] ) {
            return $this->error( $scope['error'] );
        }

        // "fields" (1.11.0): only the fields named, sorted by AI Nexus 2.47.0's helpers.
        // Unknown names are said in fields_note; with none known, products stay whole.
        $named   = self::requested_fields( $params );
        $helpers = self::core_has_field_helpers();
        $split   = [ 'keep' => null, 'unknown' => [] ];
        if ( $named && $helpers ) {
            $split = \MCP_Abilities\Query_Support::split_fields( $named, self::ITEM_FIELDS, false );
        }

        $query    = new \WP_Query( $args );
        $products = [];
        $left_out = 0;
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product || ! Access::can_read_product( $product ) ) {
                ++$left_out;
                continue;
            }
            $products[] = $this->format_product( $product, $split['keep'] );
        }

        $out = [ 'products' => $products, 'total' => $query->found_posts ];
        if ( null !== $scope['note'] ) {
            $out['note'] = $scope['note'];
        }
        $out = mcp_woo_add_note( $out, $paging_note );
        $out = mcp_woo_add_note( $out, mcp_woo_left_out_note( $left_out, 'product' ) );
        if ( $named && ! $helpers ) {
            // Older core: whole products, as before 1.11.0, and the caller is told why.
            $out['fields_note'] = sprintf(
                '"fields" needs VGC AI Nexus 2.47.0 or later (this site has %s), so the products are whole.',
                defined( 'MCP_ABILITIES_VERSION' ) ? MCP_ABILITIES_VERSION : 'an older version'
            );
        } elseif ( $named ) {
            $note = \MCP_Abilities\Query_Support::fields_note( $products, [], $split['unknown'], self::ITEM_FIELDS );
            if ( null !== $note ) {
                $out['fields_note'] = $note;
            }
        } elseif ( method_exists( '\MCP_Abilities\Query_Support', 'with_size_note' ) ) {
            // 1.12.0 (AI Nexus 2.48.0+): a big list of whole products says in "note" how
            // small the same list is with "fields". An AI may hold this tool's description
            // from before 1.11.0 (a connector keeps the first one it saw), so the answer says it.
            $out = \MCP_Abilities\Query_Support::with_size_note( $out, $products, [ 'id', 'name' ] );
        }
        return $this->json_result( $out );
    }

    /**
     * The slug of the term a category or tag filter names, or why there is none (1.14.0).
     * Only a slug is taken; an ID or a name is refused, and the answer gives its slug.
     *
     * @return array{0:?string,1:?string} [ slug or null, error or null ]
     */
    private static function term_slug( string $taxonomy, string $value ): array {
        global $wpdb;
        // Read from the database, as the list's tax_query does: get_term_by() goes through
        // get_terms(), which a multilingual plugin (WPML) limits to the current language.
        $find = static function ( string $column, $needle ) use ( $wpdb, $taxonomy ): ?array {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.{$column} = %s LIMIT 1",
                $taxonomy,
                (string) $needle
            ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of three literals.
            return is_array( $row ) ? $row : null;
        };
        $raw  = trim( $value );
        $slug = sanitize_title( $raw );
        $term = '' !== $slug ? $find( 'slug', $slug ) : null;
        if ( null !== $term ) {
            return [ (string) $term['slug'], null ];
        }
        $what = 'product_cat' === $taxonomy ? 'category' : 'tag';
        $hint = '';
        $byid = ctype_digit( $raw ) ? $find( 'term_id', $raw ) : null;
        if ( null !== $byid ) {
            $hint = sprintf( ' %s is the ID of the %s "%s", whose slug is "%s".', $raw, $what, $byid['name'], $byid['slug'] );
        } else {
            $byname = $find( 'name', $raw ) ?? $find( 'name', esc_html( $raw ) ); // names are stored with & as &amp;
            if ( null !== $byname ) {
                $hint = sprintf( ' "%s" is the name of the %s whose slug is "%s".', $raw, $what, $byname['slug'] );
            }
        }
        return [ null, sprintf( 'No product %s has the slug "%s".%s Pass a slug: %s lists them.', $what, $raw, $hint, 'product_cat' === $taxonomy ? 'wc_list_product_categories' : 'wc_list_product_tags' ) ];
    }

    /**
     * The product types of this shop: WooCommerce's and plugins' (wc_get_product_types())
     * and every product_type term (1.14.0).
     *
     * @return string[]
     */
    private static function product_types(): array {
        $types = function_exists( 'wc_get_product_types' ) ? array_keys( (array) wc_get_product_types() ) : [];
        $terms = get_terms( [ 'taxonomy' => 'product_type', 'hide_empty' => false, 'fields' => 'slugs' ] );
        if ( is_array( $terms ) ) {
            $types = array_merge( $types, $terms );
        }
        return array_values( array_unique( array_map( 'strval', $types ) ) );
    }

    /**
     * The products whose own SKU, or one of whose variations' SKU, is one of $skus
     * ($partial false) or contains $skus[0] ($partial true). Any status: the list
     * query's own filters and access checks decide the rest.
     *
     * @param string[] $skus
     * @return int[]
     */
    private static function ids_by_sku( array $skus, bool $partial ): array {
        global $wpdb;
        if ( ! $skus ) {
            return [];
        }
        $where = $partial
            ? $wpdb->prepare( 'm.meta_value LIKE %s', '%' . $wpdb->esc_like( $skus[0] ) . '%' )
            : $wpdb->prepare( 'm.meta_value IN (' . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . ')', $skus );
        $ids = $wpdb->get_col(
            "SELECT DISTINCT IF( p.post_type = 'product_variation', p.post_parent, p.ID )
             FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_sku' AND p.post_type IN ( 'product', 'product_variation' ) AND {$where}"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
        return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
    }

    /**
     * The names in "fields", cleaned the way core's Query_Support::meta_keys() cleans them.
     *
     * @return string[]
     */
    private static function requested_fields( array $params ): array {
        if ( empty( $params['fields'] ) || ! is_array( $params['fields'] ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'sanitize_text_field', $params['fields'] ) ) );
    }

    /** Whether AI Nexus core has the list-field helpers (2.47.0 and later). */
    private static function core_has_field_helpers(): bool {
        $class = '\MCP_Abilities\Query_Support';
        return class_exists( $class )
            && method_exists( $class, 'split_fields' )
            && method_exists( $class, 'finish_item' )
            && method_exists( $class, 'fields_note' );
    }

    /**
     * One product of the list: whole ($keep null, the same answer as 1.10.0), or id and
     * the fields in $keep. The permalink is worked out only when it is kept.
     *
     * @param string[]|null $keep
     */
    private function format_product( \WC_Product $p, ?array $keep = null ): array {
        $item = [
            'id'            => $p->get_id(),
            'name'          => $p->get_name(),
            'sku'           => $p->get_sku(),
            'type'          => $p->get_type(),
            'status'        => $p->get_status(),
            'price'         => $p->get_price(),
            'regular_price' => $p->get_regular_price(),
            'sale_price'    => $p->get_sale_price(),
            'stock_status'  => $p->get_stock_status(),
            'stock_qty'     => $p->get_stock_quantity(),
            'permalink'     => static fn() => $p->get_permalink(),
        ];
        if ( null !== $keep ) {
            return \MCP_Abilities\Query_Support::finish_item( $item, $keep );
        }
        $item['permalink'] = $item['permalink']();
        return $item;
    }
}

// ── Get Product ───────────────────────────────────────────────────────────────

class Get_Product_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_get_product';
        $this->label        = __( 'Get Product', 'mcp-abilities-woo' );
        $this->description  = 'Retrieve full details of a WooCommerce product including variations, attributes and meta.';
        $this->required_cap = 'edit_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Product post ID.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $product = wc_get_product( absint( $params['id'] ) );
        if ( ! $product || ! Access::can_read_product( $product ) ) {
            return $this->error( 'Product not found.' );
        }

        $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'all' ] );
        $tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'all' ] );

        $data = [
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'sku'               => $product->get_sku(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price'             => $product->get_price(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'price_html'        => $product->get_price_html(),
            'on_sale'           => $product->is_on_sale(),
            'manage_stock'      => $product->get_manage_stock(),
            'stock_qty'         => $product->get_stock_quantity(),
            'stock_status'      => $product->get_stock_status(),
            'weight'            => $product->get_weight(),
            'dimensions'        => [
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ],
            'categories'        => array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], is_array( $cats ) ? $cats : [] ),
            'tags'              => array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], is_array( $tags ) ? $tags : [] ),
            'image_id'          => $product->get_image_id(),
            'image_url'         => wp_get_attachment_url( $product->get_image_id() ) ?: null,
            'gallery_image_ids' => $product->get_gallery_image_ids(),
            'permalink'         => $product->get_permalink(),
        ];

        if ( $product->is_type( 'variable' ) ) {
            $data['variations'] = array_map( function ( $vid ) {
                $v = wc_get_product( $vid );
                if ( ! $v ) return null;
                return [
                    'id'         => $v->get_id(),
                    'sku'        => $v->get_sku(),
                    'price'      => $v->get_price(),
                    'stock_status' => $v->get_stock_status(),
                    'attributes' => $v->get_variation_attributes(),
                ];
            }, array_filter( $product->get_children() ) );
        }

        return $this->json_result( $data );
    }
}

// ── Product text (1.13.0) ─────────────────────────────────────────────────────

/**
 * How create_product and update_product store a product's description, short
 * description and name.
 */
trait Woo_Product_Text {

    /**
     * Description markup as it may be stored, and the note to give when filtering took
     * something out. Core's rule for post and page content (AI Nexus 2.49.0): as sent for
     * an account that may post unfiltered HTML, unless raw code is locked on the site;
     * otherwise filtered as WordPress filters posts. On core 2.41.0-2.48.x the same rule
     * without a note; on an older core every account is filtered, as before (1.12.0
     * filtered every account).
     *
     * @return array{0:string,1:?string} [ markup to store, note or null ]
     */
    protected static function filter_text( string $html ): array {
        if ( class_exists( '\MCP_Abilities\Custom_Code' ) && method_exists( '\MCP_Abilities\Custom_Code', 'filter_with_note' ) ) {
            return \MCP_Abilities\Custom_Code::filter_with_note( $html );
        }
        if ( class_exists( '\MCP_Abilities\Custom_Code' ) && method_exists( '\MCP_Abilities\Custom_Code', 'filter_markup' ) ) {
            return [ \MCP_Abilities\Custom_Code::filter_markup( $html ), null ];
        }
        return [ wp_kses_post( $html ), null ];
    }

    /** How the description is stored; appended to the "description" input (1.13.0). */
    protected static function text_rule_description(): string {
        if ( ! class_exists( '\MCP_Abilities\Custom_Code' ) || ! method_exists( '\MCP_Abilities\Custom_Code', 'filter_markup' ) ) {
            return '';
        }
        return ' Stored as sent (style, script and form fields included) for an account that may post unfiltered HTML, such as an administrator; otherwise, or while raw code is locked on the site, script and other markup WordPress does not allow in posts is removed'
            . ( method_exists( '\MCP_Abilities\Custom_Code', 'filter_with_note' ) ? ', and the result has a "note" saying so.' : '.' );
    }

    /**
     * Save the product so that its name, description, short description and password
     * keep their backslashes (1.13.0).
     *
     * WooCommerce's product data store hands them to wp_insert_post() / wp_update_post()
     * as they are, and those unslash: every backslash was lost (/\d+/ became /d+/), also
     * from a description the call did not touch when the name or status changed, since
     * that rewrites the whole post row. A variation's description is meta, written with
     * update_post_meta(), which unslashes too. WooCommerce's own REST API sets these
     * slashed; this does the same for this one save and puts the plain text back on the
     * object after. Saved as they are: a product of another data store, a save that writes
     * none of them, and a product saved inside save_post (WooCommerce then writes the row
     * with $wpdb, which does not unslash).
     */
    protected static function save_product( \WC_Product $product ): int {
        $store   = $product->get_data_store();
        $class   = is_object( $store ) && method_exists( $store, 'get_current_class_name' ) ? (string) $store->get_current_class_name() : '';
        $changes = array_keys( $product->get_changes() );
        $slash   = [];
        if ( is_a( $class, 'WC_Product_Variation_Data_Store_CPT', true ) ) {
            if ( ! $product->get_id() || in_array( 'description', $changes, true ) ) {
                $slash = [ 'description' ];
            }
        } elseif ( is_a( $class, 'WC_Product_Data_Store_CPT', true ) && ! doing_action( 'save_post' ) ) {
            // The props that make WooCommerce rewrite the post row (its update()).
            $row = [ 'description', 'short_description', 'name', 'parent_id', 'reviews_allowed', 'status', 'menu_order', 'date_created', 'date_modified', 'slug', 'post_password' ];
            if ( ! $product->get_id() || array_intersect( $row, $changes ) ) {
                $slash = [ 'name', 'description', 'short_description', 'post_password' ];
            }
        }
        if ( ! $slash ) {
            return (int) $product->save();
        }

        $plain = [];
        foreach ( $slash as $prop ) {
            if ( method_exists( $product, 'get_' . $prop ) && method_exists( $product, 'set_' . $prop ) ) {
                $plain[ $prop ] = (string) $product->{'get_' . $prop}( 'edit' );
            }
        }
        foreach ( $plain as $prop => $value ) {
            $product->{'set_' . $prop}( wp_slash( $value ) );
        }
        try {
            $id = (int) $product->save();
        } finally {
            foreach ( $plain as $prop => $value ) {
                $product->{'set_' . $prop}( $value );
            }
            $product->apply_changes();
        }
        return $id;
    }
}

// ── Product fields (1.14.0) ───────────────────────────────────────────────────

/**
 * How create_product and update_product check prices, stock, SKU, categories and tags,
 * and say what WooCommerce stored (1.14.0, from the audit of 2026-11-23).
 *
 * Before, a value WooCommerce could not use was quietly replaced: an unknown stock status
 * became "instock", "19,99" became 1999 and "abc" erased the price, a quantity without
 * stock management was erased, an unknown category ID was skipped, and a variation's name
 * was overwritten; the answer said "updated" all the same.
 */
trait Woo_Product_Fields {

    /**
     * Check this call's prices and stock and set them on the product (nothing is saved).
     * May throw WC_Data_Exception (an external product with stock, a duplicate SKU).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|string The values asked for, to compare with what is stored; or why the call is refused.
     */
    protected static function apply_fields( \WC_Product $product, array $params, bool $creating ) {
        $type  = $product->get_type();
        $asked = [];

        // Fields WooCommerce does not keep on this kind of product.
        if ( 'variation' === $type ) {
            $not = array_values( array_filter( [ 'name', 'short_description', 'categories', 'tags' ], static fn( $f ) => isset( $params[ $f ] ) ) );
            if ( $not ) {
                return sprintf(
                    'Product %d is a variation: WooCommerce makes its name from its parent product and attributes, and its short description, categories and tags are the parent\'s. %s cannot be set on it; update the parent product %d instead.',
                    $product->get_id(),
                    implode( ', ', $not ),
                    $product->get_parent_id()
                );
            }
        }
        if ( in_array( $type, [ 'variable', 'grouped' ], true ) ) {
            $not = array_values( array_filter( [ 'regular_price', 'sale_price' ], static fn( $f ) => isset( $params[ $f ] ) ) );
            if ( $not ) {
                return 'variable' === $type
                    ? sprintf( 'A variable product\'s price comes from its variations, so %s cannot be set on it. Set a variation\'s price instead (wc_get_product lists them).', implode( ', ', $not ) )
                    : sprintf( 'A grouped product\'s price comes from the products in the group, so %s cannot be set on it.', implode( ', ', $not ) );
            }
        }

        foreach ( [ 'regular_price', 'sale_price' ] as $field ) {
            if ( isset( $params[ $field ] ) ) {
                [ $price, $why ] = mcp_woo_price( $field, $params[ $field ] );
                if ( null === $price ) {
                    return $why;
                }
                $asked[ $field ] = $price;
            }
        }

        if ( isset( $params['stock_status'] ) && '' !== $params['stock_status'] ) {
            $status = sanitize_key( (string) $params['stock_status'] );
            $valid  = mcp_woo_stock_statuses();
            if ( ! in_array( $status, $valid, true ) ) {
                return sprintf( 'Unknown stock_status "%s": use one of %s.', (string) $params['stock_status'], implode( ', ', $valid ) );
            }
            $asked['stock_status'] = $status;
        }
        if ( isset( $params['manage_stock'] ) ) {
            $asked['manage_stock'] = wc_string_to_bool( $params['manage_stock'] );
        }
        if ( isset( $params['stock_qty'] ) && '' !== $params['stock_qty'] ) {
            if ( ! is_numeric( $params['stock_qty'] ) ) {
                return 'stock_qty must be a number, e.g. 5 (below 0 for backorders).';
            }
            // A variation of a parent that tracks stock answers "parent" here.
            $manages = array_key_exists( 'manage_stock', $asked ) ? $asked['manage_stock'] : (bool) $product->get_manage_stock();
            if ( ! $manages ) {
                return 'stock_qty needs "manage_stock": true. WooCommerce keeps a quantity only for a product that tracks stock, and '
                    . ( $creating ? 'a new product does not unless asked' : 'this one does not' )
                    . ', so the quantity would be erased on saving. Send "manage_stock": true with it.';
            }
            // A negative quantity (backorders) was stored as positive: absint() (1.14.0).
            $asked['stock_qty'] = wc_stock_amount( $params['stock_qty'] );
        }

        if ( $creating ? ! empty( $params['sku'] ) : isset( $params['sku'] ) ) {
            $asked['sku'] = sanitize_text_field( (string) $params['sku'] );
            $product->set_sku( $asked['sku'] );
        }
        foreach ( [ 'regular_price', 'sale_price' ] as $field ) {
            if ( array_key_exists( $field, $asked ) ) {
                $product->{'set_' . $field}( $asked[ $field ] );
            }
        }
        if ( array_key_exists( 'manage_stock', $asked ) ) {
            $product->set_manage_stock( $asked['manage_stock'] );
        }
        if ( array_key_exists( 'stock_qty', $asked ) ) {
            $product->set_stock_quantity( $asked['stock_qty'] );
        }
        if ( array_key_exists( 'stock_status', $asked ) ) {
            $product->set_stock_status( $asked['stock_status'] );
        }
        return $asked;
    }

    /**
     * The category and tag IDs to set, checked (1.14.0): an ID that is not a term of that
     * taxonomy (a tag's ID as a category, a deleted category) was skipped by WordPress and
     * the answer said nothing. Read from the database, not term_exists(), which WPML limits
     * to the current language.
     *
     * @param array<string,mixed> $params
     * @return array<string,int[]>|string Taxonomy => IDs for each field sent; or why it is refused.
     */
    protected static function term_fields( array $params, bool $creating ) {
        global $wpdb;
        $out = [];
        foreach ( [ 'categories' => 'product_cat', 'tags' => 'product_tag' ] as $field => $taxonomy ) {
            if ( $creating ? empty( $params[ $field ] ) : ! isset( $params[ $field ] ) ) {
                continue;
            }
            $ids = [];
            $bad = [];
            foreach ( (array) $params[ $field ] as $value ) {
                if ( is_numeric( $value ) && (int) $value > 0 && (string) (int) $value === trim( (string) $value ) ) {
                    $ids[] = (int) $value;
                } else {
                    $bad[] = is_scalar( $value ) ? (string) $value : gettype( $value );
                }
            }
            $ids = array_values( array_unique( $ids ) );
            if ( $ids ) {
                $found = $wpdb->get_col( $wpdb->prepare(
                    "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
                    array_merge( [ $taxonomy ], $ids )
                ) );
                $bad = array_merge( $bad, array_map( 'strval', array_diff( $ids, array_map( 'intval', (array) $found ) ) ) );
            }
            if ( $bad ) {
                return sprintf(
                    '%s: no product %s has the ID %s. Pass term IDs: %s lists them.',
                    $field,
                    'categories' === $field ? 'category' : 'tag',
                    implode( ', ', $bad ),
                    'categories' === $field ? 'wc_list_product_categories' : 'wc_list_product_tags'
                );
            }
            $out[ $taxonomy ] = $ids;
        }
        return $out;
    }

    /**
     * Write the checked categories and tags; the error when WordPress could not.
     *
     * @param array<string,int[]> $terms
     */
    protected static function write_terms( int $id, array $terms ): ?string {
        foreach ( $terms as $taxonomy => $ids ) {
            $r = wp_set_post_terms( $id, $ids, $taxonomy );
            if ( is_wp_error( $r ) || false === $r ) {
                return sprintf( 'The %s could not be set: %s', 'product_cat' === $taxonomy ? 'categories' : 'tags', is_wp_error( $r ) ? $r->get_error_message() : 'WordPress refused them.' );
            }
        }
        return null;
    }

    /**
     * What WooCommerce stored for the fields asked for, and a note for each one it did not
     * keep as sent (1.14.0). With stock management on, WooCommerce sets the stock status
     * from the quantity; a variable product without it takes its variations'.
     *
     * @param array<string,mixed> $asked
     * @return array{0:array<string,mixed>,1:string[]} [ stored values, notes ]
     */
    protected static function stored_fields( int $id, array $asked ): array {
        $fresh = $id ? wc_get_product( $id ) : null;
        if ( ! $fresh || ! $asked ) {
            return [ [], [] ];
        }
        $echo  = [];
        $notes = [];
        foreach ( $asked as $field => $want ) {
            switch ( $field ) {
                case 'regular_price':
                    $got = (string) $fresh->get_regular_price( 'edit' );
                    break;
                case 'sale_price':
                    $got = (string) $fresh->get_sale_price( 'edit' );
                    break;
                case 'stock_status':
                    $got = (string) $fresh->get_stock_status( 'edit' );
                    break;
                case 'manage_stock':
                    $got = (bool) $fresh->get_manage_stock( 'edit' );
                    break;
                case 'stock_qty':
                    $got = $fresh->get_stock_quantity( 'edit' );
                    break;
                case 'sku':
                    $got = (string) $fresh->get_sku( 'edit' );
                    break;
                default:
                    continue 2;
            }
            $echo[ $field ] = $got;
            $same = 'stock_qty' === $field ? ( null !== $got && (float) $got === (float) $want ) : $got === $want;
            if ( $same ) {
                continue;
            }
            if ( 'stock_status' === $field && $fresh->get_manage_stock() ) {
                $notes[] = sprintf(
                    'stock_status "%s" was not kept: this product tracks stock (manage_stock), so WooCommerce sets its stock status from the quantity (%s); it is "%s". Change stock_qty, or set manage_stock false, to change it.',
                    $want,
                    null === $fresh->get_stock_quantity() ? 'none' : $fresh->get_stock_quantity(),
                    $got
                );
            } elseif ( 'stock_status' === $field && $fresh->is_type( 'variable' ) ) {
                $notes[] = sprintf( 'stock_status "%s" was not kept: a variable product that does not track stock takes its stock status from its variations; it is "%s".', $want, $got );
            } elseif ( 'sale_price' === $field && '' === $got && '' !== (string) $fresh->get_regular_price( 'edit' ) && (float) $want >= (float) $fresh->get_regular_price( 'edit' ) ) {
                $notes[] = sprintf( 'sale_price %s was not kept: it is not lower than the regular price %s, so WooCommerce removed it and the product is not on sale.', $want, $fresh->get_regular_price( 'edit' ) );
            } else {
                $notes[] = sprintf( '%s %s was not kept: WooCommerce stored %s.', $field, wp_json_encode( $want ), wp_json_encode( $got ) );
            }
        }
        if ( array_key_exists( 'regular_price', $asked ) || array_key_exists( 'sale_price', $asked ) ) {
            $echo['price'] = (string) $fresh->get_price( 'edit' );
            $sale          = (string) $fresh->get_sale_price( 'edit' );
            $regular       = (string) $fresh->get_regular_price( 'edit' );
            if ( array_key_exists( 'sale_price', $asked ) && '' !== $sale && '' !== $regular && (float) $sale >= (float) $regular ) {
                $notes[] = sprintf( 'sale_price %s is not lower than the regular price %s, so the product is not on sale: WooCommerce sells it at %s.', $sale, $regular, $regular );
            }
        }
        return [ $echo, $notes ];
    }

    /** The error for a save WooCommerce refused (WC_Data_Exception). */
    protected static function refused( \Exception $e ): string {
        return 'WooCommerce refused the change: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was saved.';
    }
}

// ── Create Product ────────────────────────────────────────────────────────────

class Create_Product_Ability extends Ability {
    use Woo_Product_Text;
    use Woo_Product_Fields;

    private const ALLOWED_TYPES    = [ 'simple', 'grouped', 'external' ];
    private const ALLOWED_STATUSES = [ 'publish', 'draft', 'private', 'pending' ];

    protected function define_meta(): void {
        $this->key          = 'wc_create_product';
        $this->label        = __( 'Create Product', 'mcp-abilities-woo' );
        $this->description  = 'Create a new WooCommerce product. The answer gives back the stored prices and stock, and a "note" for any value WooCommerce did not keep as sent.';
        $this->required_cap = 'publish_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'              => [ 'type' => 'string',  'description' => 'Product name.' ],
                // The types the code makes (1.14.0: an enum; variable products are made in WooCommerce).
                'type'              => [ 'type' => 'string',  'enum' => self::ALLOWED_TYPES, 'default' => 'simple', 'description' => 'simple, grouped or external. Variable products are made in the WooCommerce admin.' ],
                'status'            => [ 'type' => 'string',  'enum' => self::ALLOWED_STATUSES, 'default' => 'draft' ],
                'sku'               => [ 'type' => 'string' ],
                'regular_price'     => [ 'type' => 'string',  'description' => self::PRICE_DESCRIPTION ],
                'sale_price'        => [ 'type' => 'string',  'description' => 'Sale price, written like regular_price.' ],
                'description'       => [ 'type' => 'string', 'description' => 'Product description (HTML).' . self::text_rule_description() ],
                'short_description' => [ 'type' => 'string', 'description' => 'Short description (HTML), stored the same way as the description.' ],
                'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Category term IDs (wc_list_product_categories). An unknown ID is refused.' ],
                'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Tag term IDs (wc_list_product_tags). An unknown ID is refused.' ],
                'manage_stock'      => [ 'type' => 'boolean', 'default' => false, 'description' => 'Track the stock quantity. Needed for stock_qty.' ],
                'stock_qty'         => [ 'type' => 'integer', 'description' => 'Quantity in stock; needs manage_stock true. With stock tracked, WooCommerce sets the stock status from it.' ],
                'stock_status'      => [ 'type' => 'string',  'enum' => mcp_woo_stock_statuses(), 'default' => 'instock', 'description' => 'Used as sent only when the stock is not tracked (manage_stock false).' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    /** How a price is written (1.14.0). */
    private const PRICE_DESCRIPTION = 'Regular price: a plain number with "." for decimals, e.g. "19.99" (no thousands separators or currency signs). Anything else is refused.';

    public function execute( array $params ): array {
        $type   = sanitize_key( $params['type'] ?? 'simple' );
        $status = sanitize_key( $params['status'] ?? 'draft' );

        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
            return $this->error( 'Invalid product type. Allowed: ' . implode( ', ', self::ALLOWED_TYPES ) . '. Use the WooCommerce admin UI to create variable products.' );
        }
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            return $this->error( 'Invalid status. Allowed: ' . implode( ', ', self::ALLOWED_STATUSES ) . '.' );
        }
        $terms = self::term_fields( $params, true );
        if ( is_string( $terms ) ) {
            return $this->error( $terms );
        }

        $class_map = [
            'simple'   => \WC_Product_Simple::class,
            'grouped'  => \WC_Product_Grouped::class,
            'external' => \WC_Product_External::class,
        ];
        $product = new $class_map[ $type ]();
        $notes   = [];
        try {
            $product->set_status( $status );
            $product->set_name( sanitize_text_field( $params['name'] ) );
            $asked = self::apply_fields( $product, $params, true );
            if ( is_string( $asked ) ) {
                return $this->error( $asked );
            }
            // Description and short description follow core's rule for post content (1.13.0):
            // as sent for an account that may post unfiltered HTML, unless raw code is locked;
            // otherwise filtered as WordPress filters posts, with a note.
            foreach ( [ 'description', 'short_description' ] as $field ) {
                if ( ! empty( $params[ $field ] ) ) {
                    [ $html, $notes[] ] = self::filter_text( (string) $params[ $field ] );
                    $product->{'set_' . $field}( $html );
                }
            }
            $id = self::save_product( $product );
        } catch ( \WC_Data_Exception $e ) {
            return $this->error( self::refused( $e ) );
        }
        if ( $id < 1 || 'product' !== get_post_type( $id ) ) {
            // A save WordPress refused gave "id": 0 and no error (1.14.0).
            return $this->error( 'WooCommerce did not save the product. Nothing was created.' );
        }
        $note = current( array_filter( $notes ) );

        $terms_error = self::write_terms( $id, $terms );
        [ $stored, $kept_notes ] = self::stored_fields( $id, $asked );

        $out = array_merge( [
            'id'        => $id,
            'permalink' => get_permalink( $id ),
            'edit_url'  => get_edit_post_link( $id, 'raw' ),
        ], false !== $note ? [ 'note' => $note ] : [], $stored );
        foreach ( $kept_notes as $line ) {
            $out = mcp_woo_add_note( $out, $line );
        }
        if ( null !== $terms_error ) {
            $out = mcp_woo_add_note( $out, "Product {$id} was created, but: {$terms_error}" );
        }
        return $this->json_result( $out );
    }
}

// ── Update Product ────────────────────────────────────────────────────────────

class Update_Product_Ability extends Ability {
    use Woo_Product_Text;
    use Woo_Product_Fields;

    protected function define_meta(): void {
        $this->key          = 'wc_update_product';
        $this->label        = __( 'Update Product', 'mcp-abilities-woo' );
        $this->description  = 'Update WooCommerce product fields. Only provided fields are changed. The answer gives back the stored prices and stock, and a "note" for any value WooCommerce did not keep as sent. A variation\'s name, categories and tags are its parent\'s, and a variable or grouped product has no price of its own: those are refused.';
        $this->required_cap = 'edit_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'                => [ 'type' => 'integer', 'description' => 'Product ID.' ],
                'name'              => [ 'type' => 'string' ],
                'status'            => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'private', 'pending', 'trash' ] ],
                'sku'               => [ 'type' => 'string' ],
                'regular_price'     => [ 'type' => 'string', 'description' => 'A plain number with "." for decimals, e.g. "19.99" (no thousands separators or currency signs); "" clears it. Anything else is refused.' ],
                'sale_price'        => [ 'type' => 'string', 'description' => 'Written like regular_price; "" ends the sale.' ],
                'description'       => [ 'type' => 'string', 'description' => 'New description (HTML).' . self::text_rule_description() ],
                'short_description' => [ 'type' => 'string', 'description' => 'New short description (HTML), stored the same way as the description.' ],
                'manage_stock'      => [ 'type' => 'boolean', 'description' => 'Track the stock quantity. Needed for stock_qty.' ],
                'stock_qty'         => [ 'type' => 'integer', 'description' => 'Quantity in stock (below 0 for backorders); needs stock tracked (manage_stock). With stock tracked, WooCommerce sets the stock status from it.' ],
                // An enum since 1.14.0: the stock statuses this shop's WooCommerce accepts. An
                // unknown one was saved as "instock".
                'stock_status'      => [ 'type' => 'string',  'enum' => mcp_woo_stock_statuses(), 'description' => 'Used as sent only when the stock is not tracked (manage_stock false).' ],
                'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Replace product category IDs (wc_list_product_categories). An unknown ID is refused.' ],
                'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Replace product tag IDs (wc_list_product_tags). An unknown ID is refused.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $product = wc_get_product( absint( $params['id'] ) );
        if ( ! $product || ! Access::can_read_product( $product ) ) {
            return $this->error( 'Product not found.' );
        }
        if ( ! Access::can_edit_product( $product ) ) {
            return $this->error( 'You do not have permission to edit this product.' );
        }
        $terms = self::term_fields( $params, false );
        if ( is_string( $terms ) ) {
            return $this->error( $terms );
        }

        $notes = [];
        try {
            $asked = self::apply_fields( $product, $params, false );
            if ( is_string( $asked ) ) {
                return $this->error( $asked );
            }
            if ( isset( $params['name'] ) ) {
                $product->set_name( sanitize_text_field( $params['name'] ) );
            }
            if ( isset( $params['status'] ) ) {
                $new_status = sanitize_key( $params['status'] );
                $allowed    = [ 'publish', 'draft', 'private', 'pending', 'trash' ];
                if ( ! in_array( $new_status, $allowed, true ) ) {
                    return $this->error( 'Invalid status. Allowed: ' . implode( ', ', $allowed ) . '.' );
                }
                $status_error = Access::product_status_error( $product, $new_status );
                if ( null !== $status_error ) {
                    return $this->error( $status_error );
                }
                $product->set_status( $new_status );
            }
            // Core's rule for post content, with a note when filtering took something out (1.13.0).
            foreach ( [ 'description', 'short_description' ] as $field ) {
                if ( isset( $params[ $field ] ) ) {
                    [ $html, $notes[] ] = self::filter_text( (string) $params[ $field ] );
                    $product->{'set_' . $field}( $html );
                }
            }
            // Keeps the backslashes of every text field WooCommerce rewrites (1.13.0).
            $saved = self::save_product( $product );
        } catch ( \WC_Data_Exception $e ) {
            return $this->error( self::refused( $e ) );
        }
        if ( $saved < 1 ) {
            // The save's result was not looked at (1.14.0).
            return $this->error( "WooCommerce did not save product {$product->get_id()}." );
        }
        $note = current( array_filter( $notes ) );

        $terms_error = self::write_terms( $product->get_id(), $terms );
        [ $stored, $kept_notes ] = self::stored_fields( $product->get_id(), $asked );

        $extra = array_merge( false !== $note ? [ 'note' => $note ] : [], $stored );
        foreach ( $kept_notes as $line ) {
            $extra = mcp_woo_add_note( $extra, $line );
        }
        if ( null !== $terms_error ) {
            $extra = mcp_woo_add_note( $extra, $terms_error );
        }
        $message = $kept_notes || null !== $terms_error
            ? "Product {$product->get_id()} updated, but not every value was kept as sent: see note."
            : "Product {$product->get_id()} updated.";
        return $this->success( $message, $extra );
    }
}

// ── Delete Product ────────────────────────────────────────────────────────────

class Delete_Product_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_delete_product';
        $this->label        = __( 'Delete Product', 'mcp-abilities-woo' );
        $this->description  = 'Trash or permanently delete a WooCommerce product. Where the trash is turned off, trashing is refused (it would delete for good); send force true to delete.';
        $this->required_cap = 'delete_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'    => [ 'type' => 'integer', 'description' => 'Product ID.' ],
                'force' => [ 'type' => 'boolean', 'description' => 'If true, permanently delete. Default: false (trash).', 'default' => false ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $product = wc_get_product( absint( $params['id'] ) );
        if ( ! $product || ! Access::can_read_product( $product ) ) {
            return $this->error( 'Product not found.' );
        }
        if ( ! Access::can_delete_product( $product ) ) {
            return $this->error( 'You do not have permission to delete this product.' );
        }

        $force = ! empty( $params['force'] );
        $id    = $product->get_id();
        if ( ! $force ) {
            // wp_trash_post() deletes for good when the trash is turned off (EMPTY_TRASH_DAYS
            // 0), and does nothing to a trashed post (1.14.0).
            if ( 'trash' === get_post_status( $id ) ) {
                return $this->success( "Product {$id} was already in the trash; nothing changed.", [ 'already_in_trash' => true ] );
            }
            if ( ! EMPTY_TRASH_DAYS ) {
                return $this->error( "The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so trashing product {$id} would delete it for good. Nothing was changed. Send force: true to delete it permanently." );
            }
        }
        // WooCommerce's own delete: wp_delete_post() trashes only posts and pages, so it
        // deleted a product for good even without force (1.9.0).
        $result = $product->delete( $force );

        // WC_Data::delete() answers true whatever WordPress did, so a trash or delete a
        // plugin stopped (pre_trash_post, pre_delete_post) was reported as done (1.14.0).
        clean_post_cache( $id );
        $now = get_post_status( $id );
        if ( $force && false !== $now ) {
            return $this->error( "Product {$id} was not deleted (WordPress or a plugin stopped it); it is still \"{$now}\"." );
        }
        if ( ! $force && 'trash' !== $now ) {
            return $this->error( false === $now
                ? "Product {$id} was deleted for good instead of trashed."
                : "Product {$id} was not moved to the trash (WordPress or a plugin stopped it); it is still \"{$now}\"." );
        }
        if ( ! $result ) {
            return $this->error( 'Failed to delete product.' );
        }

        $action = $force ? 'permanently deleted' : 'moved to trash';
        return $this->success( "Product {$id} {$action}." );
    }
}
