<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Coupons ───────────────────────────────────────────────────────────────

class List_Coupons_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_coupons';
        $this->label        = __( 'List Coupons', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce coupons.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'search'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => min( absint( $params['per_page'] ?? 20 ), 100 ),
        ];
        if ( ! empty( $params['search'] ) ) $args['s'] = sanitize_text_field( $params['search'] );

        $query   = new \WP_Query( $args );
        $coupons = [];
        foreach ( $query->posts as $post ) {
            $coupon    = new \WC_Coupon( $post->ID );
            $coupons[] = [
                'id'              => $coupon->get_id(),
                'code'            => $coupon->get_code(),
                'type'            => $coupon->get_discount_type(),
                'amount'          => $coupon->get_amount(),
                'description'     => $coupon->get_description(),
                'expiry_date'     => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                'usage_count'     => $coupon->get_usage_count(),
                'usage_limit'     => $coupon->get_usage_limit(),
                'minimum_amount'  => $coupon->get_minimum_amount(),
                'maximum_amount'  => $coupon->get_maximum_amount(),
                'free_shipping'   => $coupon->get_free_shipping(),
            ];
        }
        return $this->json_result( [ 'coupons' => $coupons, 'total' => $query->found_posts ] );
    }
}

// ── Create Coupon ──────────────────────────────────────────────────────────────

class Create_Coupon_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_create_coupon';
        $this->label        = __( 'Create Coupon', 'mcp-abilities-woo' );
        $this->description  = 'Create a new WooCommerce coupon.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'code'           => [ 'type' => 'string',  'description' => 'Coupon code (unique).' ],
                'type'           => [ 'type' => 'string',  'enum' => [ 'percent', 'fixed_cart', 'fixed_product' ], 'default' => 'percent' ],
                'amount'         => [ 'type' => 'number',  'description' => 'Discount amount.' ],
                'description'    => [ 'type' => 'string' ],
                'expiry_date'    => [ 'type' => 'string',  'description' => 'Expiry date (YYYY-MM-DD).' ],
                'usage_limit'    => [ 'type' => 'integer', 'description' => 'Max overall uses. 0 = unlimited.' ],
                'usage_limit_per_user' => [ 'type' => 'integer' ],
                'minimum_amount' => [ 'type' => 'number' ],
                'maximum_amount' => [ 'type' => 'number' ],
                'free_shipping'  => [ 'type' => 'boolean', 'default' => false ],
            ],
            'required' => [ 'code', 'amount' ],
        ];
    }

    private const ALLOWED_TYPES = [ 'percent', 'fixed_cart', 'fixed_product' ];

    public function execute( array $params ): array {
        $code         = wc_format_coupon_code( sanitize_text_field( $params['code'] ) );
        $type         = sanitize_key( $params['type'] ?? 'percent' );
        $amount       = floatval( $params['amount'] );

        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
            return $this->error( 'Invalid coupon type. Allowed: ' . implode( ', ', self::ALLOWED_TYPES ) . '.' );
        }

        // Percentage coupons above 100 make no economic sense and are likely a mistake or abuse.
        if ( $type === 'percent' && $amount > 100 ) {
            return $this->error( 'Percentage discount cannot exceed 100%.' );
        }

        if ( $amount < 0 ) {
            return $this->error( 'Coupon amount cannot be negative.' );
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $amount );

        if ( ! empty( $params['description'] ) )    $coupon->set_description( sanitize_textarea_field( $params['description'] ) );
        if ( ! empty( $params['expiry_date'] ) )    $coupon->set_date_expires( sanitize_text_field( $params['expiry_date'] ) );
        if ( isset( $params['usage_limit'] ) )      $coupon->set_usage_limit( absint( $params['usage_limit'] ) );
        if ( isset( $params['usage_limit_per_user'] ) ) $coupon->set_usage_limit_per_user( absint( $params['usage_limit_per_user'] ) );
        if ( isset( $params['minimum_amount'] ) )   $coupon->set_minimum_amount( wc_format_decimal( $params['minimum_amount'] ) );
        if ( isset( $params['maximum_amount'] ) )   $coupon->set_maximum_amount( wc_format_decimal( $params['maximum_amount'] ) );
        if ( isset( $params['free_shipping'] ) )    $coupon->set_free_shipping( (bool) $params['free_shipping'] );

        $id = $coupon->save();
        return $this->json_result( [ 'id' => $id, 'code' => $code ] );
    }
}
