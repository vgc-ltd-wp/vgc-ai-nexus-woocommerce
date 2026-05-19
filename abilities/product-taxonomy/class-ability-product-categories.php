<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Product Categories ───────────────────────────────────────────────────

class List_Product_Categories_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_product_categories';
        $this->label        = __( 'List Product Categories', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce product categories (product_cat taxonomy). Optional: parent (integer, 0 = top-level), hide_empty (boolean), per_page, page.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'parent'     => [ 'type' => 'integer', 'description' => 'Parent term ID. 0 = top-level only. Omit for all.' ],
                'hide_empty' => [ 'type' => 'boolean', 'description' => 'Exclude categories with no products. Default: false.', 'default' => false ],
                'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
                'page'       => [ 'type' => 'integer', 'default' => 1 ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => ! empty( $params['hide_empty'] ),
            'number'     => min( absint( $params['per_page'] ?? 50 ), 200 ),
            'offset'     => ( max( 1, absint( $params['page'] ?? 1 ) ) - 1 ) * min( absint( $params['per_page'] ?? 50 ), 200 ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( isset( $params['parent'] ) ) {
            $args['parent'] = absint( $params['parent'] );
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }

        $result = array_map( fn( $t ) => [
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description,
            'parent_id'   => $t->parent,
            'count'       => $t->count,
        ], $terms );

        return $this->json_result( [ 'categories' => $result, 'total' => count( $result ) ] );
    }
}

// ── Get Product Category ──────────────────────────────────────────────────────

class Get_Product_Category_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_get_product_category';
        $this->label        = __( 'Get Product Category', 'mcp-abilities-woo' );
        $this->description  = 'Get a WooCommerce product category by id or slug. Required: id (integer) or slug (string).';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'integer', 'description' => 'Term ID.' ],
                'slug' => [ 'type' => 'string',  'description' => 'Category slug (used if id omitted).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! empty( $params['id'] ) ) {
            $term = get_term( absint( $params['id'] ), 'product_cat' );
        } elseif ( ! empty( $params['slug'] ) ) {
            $term = get_term_by( 'slug', sanitize_title( $params['slug'] ), 'product_cat' );
        } else {
            return $this->error( 'Provide id or slug.' );
        }

        if ( ! $term || is_wp_error( $term ) ) {
            return $this->error( 'Product category not found.' );
        }

        // Thumbnail image.
        $thumbnail_id  = get_term_meta( $term->term_id, 'thumbnail_id', true );
        $thumbnail_url = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';

        return $this->json_result( [
            'id'            => $term->term_id,
            'name'          => $term->name,
            'slug'          => $term->slug,
            'description'   => $term->description,
            'parent_id'     => $term->parent,
            'count'         => $term->count,
            'thumbnail_url' => $thumbnail_url,
        ] );
    }
}

// ── Create Product Category ───────────────────────────────────────────────────

class Create_Product_Category_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_create_product_category';
        $this->label        = __( 'Create Product Category', 'mcp-abilities-woo' );
        $this->description  = 'Create a new WooCommerce product category. Required: name. Optional: slug, description, parent_id.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'        => [ 'type' => 'string', 'description' => 'Category name.' ],
                'slug'        => [ 'type' => 'string', 'description' => 'URL slug (auto-generated if omitted).' ],
                'description' => [ 'type' => 'string', 'description' => 'Category description.' ],
                'parent_id'   => [ 'type' => 'integer', 'description' => 'Parent category term ID. Default: 0 (top-level).' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'description' => sanitize_textarea_field( $params['description'] ?? '' ),
            'parent'      => absint( $params['parent_id'] ?? 0 ),
        ];
        if ( ! empty( $params['slug'] ) ) {
            $args['slug'] = sanitize_title( $params['slug'] );
        }

        $result = wp_insert_term( sanitize_text_field( $params['name'] ), 'product_cat', $args );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->json_result( [
            'id'      => $result['term_id'],
            'message' => 'Product category created.',
        ] );
    }
}

// ── Update Product Category ───────────────────────────────────────────────────

class Update_Product_Category_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_update_product_category';
        $this->label        = __( 'Update Product Category', 'mcp-abilities-woo' );
        $this->description  = 'Update a WooCommerce product category. Required: id. Optional: name, slug, description, parent_id.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer', 'description' => 'Term ID to update.' ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent_id'   => [ 'type' => 'integer' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id = absint( $params['id'] );
        if ( ! get_term( $term_id, 'product_cat' ) ) {
            return $this->error( 'Product category not found.' );
        }

        $args = [];
        if ( isset( $params['name'] ) )        { $args['name']        = sanitize_text_field( $params['name'] ); }
        if ( isset( $params['slug'] ) )        { $args['slug']        = sanitize_title( $params['slug'] ); }
        if ( isset( $params['description'] ) ) { $args['description'] = sanitize_textarea_field( $params['description'] ); }
        if ( isset( $params['parent_id'] ) )   { $args['parent']      = absint( $params['parent_id'] ); }

        $result = wp_update_term( $term_id, 'product_cat', $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Product category {$term_id} updated." );
    }
}

// ── Delete Product Category ───────────────────────────────────────────────────

class Delete_Product_Category_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_delete_product_category';
        $this->label        = __( 'Delete Product Category', 'mcp-abilities-woo' );
        $this->description  = 'Delete a WooCommerce product category by id. Required: id (integer term ID).';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Term ID to delete.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id = absint( $params['id'] );
        if ( ! get_term( $term_id, 'product_cat' ) ) {
            return $this->error( 'Product category not found.' );
        }

        $result = wp_delete_term( $term_id, 'product_cat' );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }
        if ( ! $result ) {
            return $this->error( 'Could not delete product category.' );
        }

        return $this->success( "Product category {$term_id} deleted." );
    }
}
