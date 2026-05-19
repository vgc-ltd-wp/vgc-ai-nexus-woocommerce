<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Products ────────────────────────────────────────────────────────────

class List_Products_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_products';
        $this->label        = __( 'List Products', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce products with filtering by status, category, stock and price.';
        $this->required_cap = 'edit_products';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'    => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'private', 'pending', 'any' ], 'default' => 'publish' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'search'    => [ 'type' => 'string' ],
                'category'  => [ 'type' => 'string',  'description' => 'Product category slug.' ],
                'tag'       => [ 'type' => 'string',  'description' => 'Product tag slug.' ],
                'stock'        => [ 'type' => 'string',  'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                'min_price'    => [ 'type' => 'number' ],
                'max_price'    => [ 'type' => 'number' ],
                'product_type' => [ 'type' => 'string',  'enum' => [ 'simple', 'variable', 'grouped', 'external' ] ],
            ],
        ];
    }

    private const ALLOWED_STATUSES = [ 'publish', 'draft', 'private', 'pending', 'any' ];

    public function execute( array $params ): array {
        $status = sanitize_key( $params['status'] ?? 'publish' );
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            $status = 'publish';
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => $status,
            'posts_per_page' => min( absint( $params['per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, absint( $params['page'] ?? 1 ) ),
        ];

        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
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

        $query    = new \WP_Query( $args );
        $products = [];
        foreach ( $query->posts as $post ) {
            $product    = wc_get_product( $post->ID );
            $products[] = $this->format_product( $product );
        }

        return $this->json_result( [ 'products' => $products, 'total' => $query->found_posts ] );
    }

    private function format_product( \WC_Product $p ): array {
        return [
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
            'permalink'     => $p->get_permalink(),
        ];
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
        if ( ! $product ) {
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

// ── Create Product ────────────────────────────────────────────────────────────

class Create_Product_Ability extends Ability {

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
                'description'       => [ 'type' => 'string' ],
                'short_description' => [ 'type' => 'string' ],
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
        if ( ! empty( $params['description'] ) )        $product->set_description( wp_kses_post( $params['description'] ) );
        if ( ! empty( $params['short_description'] ) )  $product->set_short_description( wp_kses_post( $params['short_description'] ) );
        if ( isset( $params['manage_stock'] ) )         $product->set_manage_stock( (bool) $params['manage_stock'] );
        if ( isset( $params['stock_qty'] ) )            $product->set_stock_quantity( absint( $params['stock_qty'] ) );
        if ( ! empty( $params['stock_status'] ) ) {
            $ss = sanitize_key( $params['stock_status'] );
            if ( in_array( $ss, self::ALLOWED_STOCK_STATUS, true ) ) {
                $product->set_stock_status( $ss );
            }
        }

        $id = $product->save();

        if ( ! empty( $params['categories'] ) ) {
            wp_set_post_terms( $id, array_map( 'absint', (array) $params['categories'] ), 'product_cat' );
        }
        if ( ! empty( $params['tags'] ) ) {
            wp_set_post_terms( $id, array_map( 'absint', (array) $params['tags'] ), 'product_tag' );
        }

        return $this->json_result( [
            'id'        => $id,
            'permalink' => get_permalink( $id ),
            'edit_url'  => get_edit_post_link( $id, 'raw' ),
        ] );
    }
}

// ── Update Product ────────────────────────────────────────────────────────────

class Update_Product_Ability extends Ability {

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
                'description'       => [ 'type' => 'string' ],
                'short_description' => [ 'type' => 'string' ],
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
        if ( ! $product ) {
            return $this->error( 'Product not found.' );
        }

        if ( isset( $params['name'] ) )   $product->set_name( sanitize_text_field( $params['name'] ) );
        if ( isset( $params['status'] ) ) {
            $new_status = sanitize_key( $params['status'] );
            $allowed    = [ 'publish', 'draft', 'private', 'pending', 'trash' ];
            if ( ! in_array( $new_status, $allowed, true ) ) {
                return $this->error( 'Invalid status. Allowed: ' . implode( ', ', $allowed ) . '.' );
            }
            $product->set_status( $new_status );
        }
        if ( isset( $params['sku'] ) )               $product->set_sku( sanitize_text_field( $params['sku'] ) );
        if ( isset( $params['regular_price'] ) )     $product->set_regular_price( wc_format_decimal( $params['regular_price'] ) );
        if ( isset( $params['sale_price'] ) )        $product->set_sale_price( wc_format_decimal( $params['sale_price'] ) );
        if ( isset( $params['description'] ) )       $product->set_description( wp_kses_post( $params['description'] ) );
        if ( isset( $params['short_description'] ) ) $product->set_short_description( wp_kses_post( $params['short_description'] ) );
        if ( isset( $params['manage_stock'] ) )      $product->set_manage_stock( (bool) $params['manage_stock'] );
        if ( isset( $params['stock_qty'] ) )         $product->set_stock_quantity( absint( $params['stock_qty'] ) );
        if ( isset( $params['stock_status'] ) )      $product->set_stock_status( sanitize_key( $params['stock_status'] ) );

        $product->save();

        if ( isset( $params['categories'] ) ) {
            wp_set_post_terms( $product->get_id(), array_map( 'absint', (array) $params['categories'] ), 'product_cat' );
        }
        if ( isset( $params['tags'] ) ) {
            wp_set_post_terms( $product->get_id(), array_map( 'absint', (array) $params['tags'] ), 'product_tag' );
        }

        return $this->success( "Product {$product->get_id()} updated." );
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
        if ( ! $product ) {
            return $this->error( 'Product not found.' );
        }

        $force = ! empty( $params['force'] );
        $result = wp_delete_post( $product->get_id(), $force );

        if ( ! $result ) {
            return $this->error( 'Failed to delete product.' );
        }

        $action = $force ? 'permanently deleted' : 'moved to trash';
        return $this->success( "Product {$product->get_id()} {$action}." );
    }
}
