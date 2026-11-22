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
                'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'sku'       => [ 'type' => 'string',  'description' => 'Exact SKU, or several separated by commas ("BM-01,BM-02"). A variation\'s SKU finds its parent product.' ],
                'search'    => [ 'type' => 'string',  'description' => 'Words in the name or description, or part of a SKU.' ],
                'category'  => [ 'type' => 'string',  'description' => 'Product category slug.' ],
                'tag'       => [ 'type' => 'string',  'description' => 'Product tag slug.' ],
                'stock'        => [ 'type' => 'string',  'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                'min_price'    => [ 'type' => 'number' ],
                'max_price'    => [ 'type' => 'number' ],
                'product_type' => [ 'type' => 'string',  'enum' => [ 'simple', 'variable', 'grouped', 'external' ] ],
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

        $args = [
            'post_type'      => 'product',
            'post_status'    => $status,
            'posts_per_page' => min( absint( $params['per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, absint( $params['page'] ?? 1 ) ),
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
        if ( ! empty( $params['category'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_title( $params['category'] ),
            ];
        }
        if ( ! empty( $params['tag'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field'    => 'slug',
                'terms'    => sanitize_title( $params['tag'] ),
            ];
        }
        if ( ! empty( $params['product_type'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => sanitize_key( $params['product_type'] ),
            ];
        }
        if ( $tax_query ) {
            $args['tax_query'] = $tax_query;
        }

        $meta_query = [];
        if ( ! empty( $params['stock'] ) ) {
            $meta_query[] = [ 'key' => '_stock_status', 'value' => sanitize_key( $params['stock'] ) ];
        }
        if ( ! empty( $params['min_price'] ) ) {
            $meta_query[] = [ 'key' => '_price', 'value' => floatval( $params['min_price'] ), 'compare' => '>=', 'type' => 'NUMERIC' ];
        }
        if ( ! empty( $params['max_price'] ) ) {
            $meta_query[] = [ 'key' => '_price', 'value' => floatval( $params['max_price'] ), 'compare' => '<=', 'type' => 'NUMERIC' ];
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
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product || ! Access::can_read_product( $product ) ) {
                continue;
            }
            $products[] = $this->format_product( $product, $split['keep'] );
        }

        $out = [ 'products' => $products, 'total' => $query->found_posts ];
        if ( null !== $scope['note'] ) {
            $out['note'] = $scope['note'];
        }
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

// ── Create Product ────────────────────────────────────────────────────────────

class Create_Product_Ability extends Ability {
    use Woo_Product_Text;

    private const ALLOWED_TYPES        = [ 'simple', 'grouped', 'external' ];
    private const ALLOWED_STATUSES     = [ 'publish', 'draft', 'private', 'pending' ];
    private const ALLOWED_STOCK_STATUS = [ 'instock', 'outofstock', 'onbackorder' ];

    protected function define_meta(): void {
        $this->key          = 'wc_create_product';
        $this->label        = __( 'Create Product', 'mcp-abilities-woo' );
        $this->description  = 'Create a new WooCommerce product.';
        $this->required_cap = 'publish_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'              => [ 'type' => 'string',  'description' => 'Product name.' ],
                'type'              => [ 'type' => 'string',  'description' => 'simple | grouped | external. Default: simple.', 'default' => 'simple' ],
                'status'            => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'private', 'pending' ], 'default' => 'draft' ],
                'sku'               => [ 'type' => 'string' ],
                'regular_price'     => [ 'type' => 'string',  'description' => 'Regular price (numeric string, e.g. "19.99").' ],
                'sale_price'        => [ 'type' => 'string' ],
                'description'       => [ 'type' => 'string', 'description' => 'Product description (HTML).' . self::text_rule_description() ],
                'short_description' => [ 'type' => 'string', 'description' => 'Short description (HTML), stored the same way as the description.' ],
                'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category term IDs.' ],
                'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of tag term IDs.' ],
                'manage_stock'      => [ 'type' => 'boolean', 'default' => false ],
                'stock_qty'         => [ 'type' => 'integer' ],
                'stock_status'      => [ 'type' => 'string',  'enum' => [ 'instock', 'outofstock', 'onbackorder' ], 'default' => 'instock' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $type   = sanitize_key( $params['type'] ?? 'simple' );
        $status = sanitize_key( $params['status'] ?? 'draft' );

        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
            return $this->error( 'Invalid product type. Allowed: ' . implode( ', ', self::ALLOWED_TYPES ) . '. Use the WooCommerce admin UI to create variable products.' );
        }
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            return $this->error( 'Invalid status. Allowed: ' . implode( ', ', self::ALLOWED_STATUSES ) . '.' );
        }

        $class_map = [
            'simple'   => \WC_Product_Simple::class,
            'grouped'  => \WC_Product_Grouped::class,
            'external' => \WC_Product_External::class,
        ];
        $product = new $class_map[ $type ]();
        $product->set_status( $status );

        $product->set_name( sanitize_text_field( $params['name'] ) );

        if ( ! empty( $params['sku'] ) )               $product->set_sku( sanitize_text_field( $params['sku'] ) );
        if ( isset( $params['regular_price'] ) )        $product->set_regular_price( wc_format_decimal( $params['regular_price'] ) );
        if ( isset( $params['sale_price'] ) )           $product->set_sale_price( wc_format_decimal( $params['sale_price'] ) );
        // Description and short description follow core's rule for post content (1.13.0):
        // as sent for an account that may post unfiltered HTML, unless raw code is locked;
        // otherwise filtered as WordPress filters posts, with a note.
        $notes = [];
        foreach ( [ 'description', 'short_description' ] as $field ) {
            if ( ! empty( $params[ $field ] ) ) {
                [ $html, $notes[] ] = self::filter_text( (string) $params[ $field ] );
                $product->{'set_' . $field}( $html );
            }
        }
        if ( isset( $params['manage_stock'] ) )         $product->set_manage_stock( (bool) $params['manage_stock'] );
        if ( isset( $params['stock_qty'] ) )            $product->set_stock_quantity( absint( $params['stock_qty'] ) );
        if ( ! empty( $params['stock_status'] ) ) {
            $ss = sanitize_key( $params['stock_status'] );
            if ( in_array( $ss, self::ALLOWED_STOCK_STATUS, true ) ) {
                $product->set_stock_status( $ss );
            }
        }

        $id   = self::save_product( $product );
        $note = current( array_filter( $notes ) );

        if ( ! empty( $params['categories'] ) ) {
            wp_set_post_terms( $id, array_map( 'absint', (array) $params['categories'] ), 'product_cat' );
        }
        if ( ! empty( $params['tags'] ) ) {
            wp_set_post_terms( $id, array_map( 'absint', (array) $params['tags'] ), 'product_tag' );
        }

        return $this->json_result( array_merge( [
            'id'        => $id,
            'permalink' => get_permalink( $id ),
            'edit_url'  => get_edit_post_link( $id, 'raw' ),
        ], false !== $note ? [ 'note' => $note ] : [] ) );
    }
}

// ── Update Product ────────────────────────────────────────────────────────────

class Update_Product_Ability extends Ability {
    use Woo_Product_Text;

    protected function define_meta(): void {
        $this->key          = 'wc_update_product';
        $this->label        = __( 'Update Product', 'mcp-abilities-woo' );
        $this->description  = 'Update WooCommerce product fields. Only provided fields are changed.';
        $this->required_cap = 'edit_products';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'                => [ 'type' => 'integer', 'description' => 'Product ID.' ],
                'name'              => [ 'type' => 'string' ],
                'status'            => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'private', 'pending', 'trash' ] ],
                'sku'               => [ 'type' => 'string' ],
                'regular_price'     => [ 'type' => 'string' ],
                'sale_price'        => [ 'type' => 'string' ],
                'description'       => [ 'type' => 'string', 'description' => 'New description (HTML).' . self::text_rule_description() ],
                'short_description' => [ 'type' => 'string', 'description' => 'New short description (HTML), stored the same way as the description.' ],
                'manage_stock'      => [ 'type' => 'boolean' ],
                'stock_qty'         => [ 'type' => 'integer' ],
                'stock_status'      => [ 'type' => 'string',  'description' => 'instock | outofstock | onbackorder' ],
                'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Replace product category IDs.' ],
                'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Replace product tag IDs.' ],
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

        if ( isset( $params['name'] ) )   $product->set_name( sanitize_text_field( $params['name'] ) );
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
        if ( isset( $params['sku'] ) )               $product->set_sku( sanitize_text_field( $params['sku'] ) );
        if ( isset( $params['regular_price'] ) )     $product->set_regular_price( wc_format_decimal( $params['regular_price'] ) );
        if ( isset( $params['sale_price'] ) )        $product->set_sale_price( wc_format_decimal( $params['sale_price'] ) );
        // Core's rule for post content, with a note when filtering took something out (1.13.0).
        $notes = [];
        foreach ( [ 'description', 'short_description' ] as $field ) {
            if ( isset( $params[ $field ] ) ) {
                [ $html, $notes[] ] = self::filter_text( (string) $params[ $field ] );
                $product->{'set_' . $field}( $html );
            }
        }
        if ( isset( $params['manage_stock'] ) )      $product->set_manage_stock( (bool) $params['manage_stock'] );
        if ( isset( $params['stock_qty'] ) )         $product->set_stock_quantity( absint( $params['stock_qty'] ) );
        if ( isset( $params['stock_status'] ) )      $product->set_stock_status( sanitize_key( $params['stock_status'] ) );

        // Keeps the backslashes of every text field WooCommerce rewrites (1.13.0).
        self::save_product( $product );
        $note = current( array_filter( $notes ) );

        if ( isset( $params['categories'] ) ) {
            wp_set_post_terms( $product->get_id(), array_map( 'absint', (array) $params['categories'] ), 'product_cat' );
        }
        if ( isset( $params['tags'] ) ) {
            wp_set_post_terms( $product->get_id(), array_map( 'absint', (array) $params['tags'] ), 'product_tag' );
        }

        return $this->success( "Product {$product->get_id()} updated.", false !== $note ? [ 'note' => $note ] : [] );
    }
}

// ── Delete Product ────────────────────────────────────────────────────────────

class Delete_Product_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_delete_product';
        $this->label        = __( 'Delete Product', 'mcp-abilities-woo' );
        $this->description  = 'Trash or permanently delete a WooCommerce product.';
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
        // WooCommerce's own delete: wp_delete_post() trashes only posts and pages, so it
        // deleted a product for good even without force (1.9.0).
        $result = $product->delete( $force );

        if ( ! $result ) {
            return $this->error( 'Failed to delete product.' );
        }

        $action = $force ? 'permanently deleted' : 'moved to trash';
        return $this->success( "Product {$id} {$action}." );
    }
}
