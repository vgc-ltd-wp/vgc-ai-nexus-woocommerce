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
                'per_page'   => [ 'type' => 'integer', 'default' => 50, 'description' => '1 to 200; -1 gives 200 (the most at once). "total" says how many there are.' ],
                'page'       => [ 'type' => 'integer', 'default' => 1 ],
            ],
        ];
    }

    public function execute( array $params ): array {
        // per_page -1 gave 1 category, and "total" was the size of this page (1.14.0).
        $paging = mcp_woo_paging( $params, 50, 200 );
        if ( is_string( $paging ) ) {
            return $this->error( $paging );
        }
        [ $per_page, $page, $note ] = $paging;
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => ! empty( $params['hide_empty'] ),
            'number'     => $per_page,
            'offset'     => ( $page - 1 ) * $per_page,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( isset( $params['parent'] ) && '' !== $params['parent'] ) {
            if ( ! is_numeric( $params['parent'] ) || (int) $params['parent'] < 0 ) {
                return $this->error( 'parent must be a category ID, or 0 for the top-level categories.' );
            }
            $args['parent'] = (int) $params['parent'];
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }
        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
        $total = wp_count_terms( $count_args );

        $result = array_map( fn( $t ) => [
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description,
            'parent_id'   => $t->parent,
            'count'       => $t->count,
        ], $terms );

        return $this->json_result( mcp_woo_add_note( [
            'categories' => $result,
            'total'      => is_numeric( $total ) ? (int) $total : count( $result ),
        ], $note ) );
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
                'description' => [ 'type' => 'string', 'description' => 'Category description (HTML).' . self::DESCRIPTION_RULE ],
                'parent_id'   => [ 'type' => 'integer', 'description' => 'Parent category term ID. Default: 0 (top-level).' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    /** How a term description is stored (1.14.0). */
    public const DESCRIPTION_RULE = ' WordPress keeps basic markup in a term description (links, bold, italic, code, quotes) and removes the rest, such as <p>; when it removed something, the result has a "note" and the stored "description".';

    public function execute( array $params ): array {
        $parent = self::parent_id( $params );
        if ( is_string( $parent ) ) {
            return $this->error( $parent );
        }
        // HTML was taken out of the description with no word, and backslashes were lost (1.14.0).
        $sent = (string) ( $params['description'] ?? '' );
        $args = [
            'description' => mcp_woo_term_description( $sent ),
            'parent'      => $parent,
        ];
        if ( ! empty( $params['slug'] ) ) {
            $args['slug'] = sanitize_title( $params['slug'] );
        }

        $result = wp_insert_term( wp_slash( sanitize_text_field( $params['name'] ) ), 'product_cat', $args );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        $out  = [
            'id'      => $result['term_id'],
            'message' => 'Product category created.',
        ];
        $term = get_term( (int) $result['term_id'], 'product_cat' );
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

    /**
     * The parent category's ID, or why it is refused (1.14.0): absint() made -5 into
     * category 5. WordPress refuses an ID that is not a category itself.
     *
     * @return int|string
     */
    public static function parent_id( array $params ) {
        if ( ! isset( $params['parent_id'] ) || '' === $params['parent_id'] ) {
            return 0;
        }
        if ( ! is_numeric( $params['parent_id'] ) || (int) $params['parent_id'] < 0 ) {
            return 'parent_id must be a category ID, or 0 for a top-level category.';
        }
        return (int) $params['parent_id'];
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
                'description' => [ 'type' => 'string', 'description' => 'New description (HTML).' . Create_Product_Category_Ability::DESCRIPTION_RULE ],
                'parent_id'   => [ 'type' => 'integer', 'description' => 'Parent category term ID; 0 makes it top-level.' ],
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
        $sent = null;
        if ( isset( $params['name'] ) )        { $args['name']        = wp_slash( sanitize_text_field( $params['name'] ) ); }
        if ( isset( $params['slug'] ) )        { $args['slug']        = sanitize_title( $params['slug'] ); }
        if ( isset( $params['description'] ) ) {
            // HTML was taken out with no word, and backslashes were lost (1.14.0).
            $sent                = (string) $params['description'];
            $args['description'] = mcp_woo_term_description( $sent );
        }
        if ( isset( $params['parent_id'] ) ) {
            $parent = Create_Product_Category_Ability::parent_id( $params );
            if ( is_string( $parent ) ) {
                return $this->error( $parent );
            }
            $args['parent'] = $parent;
        }

        $result = wp_update_term( $term_id, 'product_cat', $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        $extra = [];
        $term  = get_term( $term_id, 'product_cat' );
        if ( $term instanceof \WP_Term ) {
            // The stored slug: WordPress makes a taken or unusable one unique (1.14.0).
            if ( isset( $params['slug'] ) ) {
                $extra['slug'] = $term->slug;
            }
            $note = null !== $sent ? mcp_woo_term_description_note( $sent, $term->description ) : null;
            if ( null !== $note ) {
                $extra['description'] = $term->description;
                $extra['note']        = $note;
            }
        }
        return $this->success( "Product category {$term_id} updated.", $extra );
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
