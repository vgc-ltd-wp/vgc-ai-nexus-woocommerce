<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Product Tags ─────────────────────────────────────────────────────────

class List_Product_Tags_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_product_tags';
        $this->label        = __( 'List Product Tags', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce product tags (product_tag taxonomy). Optional: hide_empty (boolean), per_page, page.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'hide_empty' => [ 'type' => 'boolean', 'description' => 'Exclude tags with no products. Default: false.', 'default' => false ],
                'per_page'   => [ 'type' => 'integer', 'default' => 50, 'description' => '1 to 200; -1 gives 200 (the most at once). "total" says how many there are.' ],
                'page'       => [ 'type' => 'integer', 'default' => 1 ],
                'search'     => [ 'type' => 'string',  'description' => 'Search tags by name.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        // per_page -1 gave 1 tag, and "total" was the size of this page (1.14.0).
        $paging = mcp_woo_paging( $params, 50, 200 );
        if ( is_string( $paging ) ) {
            return $this->error( $paging );
        }
        [ $per_page, $page, $note ] = $paging;
        $args = [
            'taxonomy'   => 'product_tag',
            'hide_empty' => ! empty( $params['hide_empty'] ),
            'number'     => $per_page,
            'offset'     => ( $page - 1 ) * $per_page,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( ! empty( $params['search'] ) ) {
            $args['search'] = sanitize_text_field( $params['search'] );
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }
        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
        $total = wp_count_terms( $count_args );

        $result = array_map( fn( $t ) => [
            'id'    => $t->term_id,
            'name'  => $t->name,
            'slug'  => $t->slug,
            'count' => $t->count,
        ], $terms );

        return $this->json_result( mcp_woo_add_note( [
            'tags'  => $result,
            'total' => is_numeric( $total ) ? (int) $total : count( $result ),
        ], $note ) );
    }
}

// ── Get Product Tag ───────────────────────────────────────────────────────────

class Get_Product_Tag_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_get_product_tag';
        $this->label        = __( 'Get Product Tag', 'mcp-abilities-woo' );
        $this->description  = 'Get a WooCommerce product tag by id or slug. Required: id (integer) or slug (string).';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'integer', 'description' => 'Term ID.' ],
                'slug' => [ 'type' => 'string',  'description' => 'Tag slug (used if id omitted).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! empty( $params['id'] ) ) {
            $term = get_term( absint( $params['id'] ), 'product_tag' );
        } elseif ( ! empty( $params['slug'] ) ) {
            $term = get_term_by( 'slug', sanitize_title( $params['slug'] ), 'product_tag' );
        } else {
            return $this->error( 'Provide id or slug.' );
        }

        if ( ! $term || is_wp_error( $term ) ) {
            return $this->error( 'Product tag not found.' );
        }

        return $this->json_result( [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => $term->count,
        ] );
    }
}

// ── Create Product Tag ────────────────────────────────────────────────────────

class Create_Product_Tag_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_create_product_tag';
        $this->label        = __( 'Create Product Tag', 'mcp-abilities-woo' );
        $this->description  = 'Create a new WooCommerce product tag. Required: name. Optional: slug, description.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'        => [ 'type' => 'string', 'description' => 'Tag name.' ],
                'slug'        => [ 'type' => 'string', 'description' => 'URL slug (auto-generated if omitted).' ],
                'description' => [ 'type' => 'string', 'description' => 'Tag description (HTML).' . Create_Product_Category_Ability::DESCRIPTION_RULE ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        // HTML was taken out of the description with no word, and backslashes were lost (1.14.0).
        $sent = (string) ( $params['description'] ?? '' );
        $args = [
            'description' => mcp_woo_term_description( $sent ),
        ];
        if ( ! empty( $params['slug'] ) ) {
            $args['slug'] = sanitize_title( $params['slug'] );
        }

        $result = wp_insert_term( wp_slash( sanitize_text_field( $params['name'] ) ), 'product_tag', $args );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        $out  = [
            'id'      => $result['term_id'],
            'message' => 'Product tag created.',
        ];
        $term = get_term( (int) $result['term_id'], 'product_tag' );
        if ( $term instanceof \WP_Term ) {
            $out['slug'] = $term->slug;
            $note        = mcp_woo_term_description_note( $sent, $term->description );
            if ( null !== $note ) {
                $out['description'] = $term->description;
                $out['note']        = $note;
            }
        }
        return $this->json_result( $out );
    }
}

// ── Update Product Tag ────────────────────────────────────────────────────────

class Update_Product_Tag_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_update_product_tag';
        $this->label        = __( 'Update Product Tag', 'mcp-abilities-woo' );
        $this->description  = 'Update a WooCommerce product tag. Required: id. Optional: name, slug, description.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer', 'description' => 'Term ID to update.' ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string', 'description' => 'New description (HTML).' . Create_Product_Category_Ability::DESCRIPTION_RULE ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id = absint( $params['id'] );
        if ( ! get_term( $term_id, 'product_tag' ) ) {
            return $this->error( 'Product tag not found.' );
        }

        $args = [];
        $sent = null;
        if ( isset( $params['name'] ) )        { $args['name']        = wp_slash( sanitize_text_field( $params['name'] ) ); }
        if ( isset( $params['slug'] ) )        { $args['slug']        = sanitize_title( $params['slug'] ); }
        if ( isset( $params['description'] ) ) {
            // HTML was taken out with no word, and backslashes were lost (1.14.0).
            $sent                = (string) $params['description'];
            $args['description'] = mcp_woo_term_description( $sent );
        }

        $result = wp_update_term( $term_id, 'product_tag', $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        $extra = [];
        $term  = get_term( $term_id, 'product_tag' );
        if ( $term instanceof \WP_Term ) {
            if ( isset( $params['slug'] ) ) {
                $extra['slug'] = $term->slug; // the stored slug (1.14.0)
            }
            $note = null !== $sent ? mcp_woo_term_description_note( $sent, $term->description ) : null;
            if ( null !== $note ) {
                $extra['description'] = $term->description;
                $extra['note']        = $note;
            }
        }
        return $this->success( "Product tag {$term_id} updated.", $extra );
    }
}

// ── Delete Product Tag ────────────────────────────────────────────────────────

class Delete_Product_Tag_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_delete_product_tag';
        $this->label        = __( 'Delete Product Tag', 'mcp-abilities-woo' );
        $this->description  = 'Delete a WooCommerce product tag by id. Required: id (integer term ID).';
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
        if ( ! get_term( $term_id, 'product_tag' ) ) {
            return $this->error( 'Product tag not found.' );
        }

        $result = wp_delete_term( $term_id, 'product_tag' );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }
        if ( ! $result ) {
            return $this->error( 'Could not delete product tag.' );
        }

        return $this->success( "Product tag {$term_id} deleted." );
    }
}
