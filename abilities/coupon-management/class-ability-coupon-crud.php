<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use VGC\AI_Nexus_Woo\Access;

defined( 'ABSPATH' ) || exit;

// ── List Coupons ───────────────────────────────────────────────────────────────

class List_Coupons_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_coupons';
        $this->label        = __( 'List Coupons', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce coupons: the published ones, unless "status" says otherwise. "total" says how many match.';
        $this->required_cap = 'manage_woocommerce';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'default' => 20, 'description' => '1 to 100; -1 gives 100 (the most at once).' ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'search'   => [ 'type' => 'string' ],
                'status'   => [ 'type' => 'string', 'enum' => self::STATUSES, 'default' => 'publish', 'description' => '"any" is every status but trash.' ],
            ],
        ];
    }

    /** The coupon statuses that can be listed (1.14.0: before, only published ones, and nothing said so). */
    private const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ];

    public function execute( array $params ): array {
        // per_page -1 gave 1 coupon, and there was no page (1.14.0).
        $paging = mcp_woo_paging( $params, 20, 100 );
        if ( is_string( $paging ) ) {
            return $this->error( $paging );
        }
        [ $per_page, $page, $note ] = $paging;
        $status = sanitize_key( (string) ( $params['status'] ?? '' ) );
        $status = '' === $status ? 'publish' : $status;
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return $this->error( sprintf( 'Unknown status "%s": use one of %s.', $status, implode( ', ', self::STATUSES ) ) );
        }
        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
        ];
        if ( ! empty( $params['search'] ) ) $args['s'] = sanitize_text_field( $params['search'] );
        $pto = get_post_type_object( 'shop_coupon' );
        if ( $pto && ! current_user_can( $pto->cap->edit_others_posts ) ) {
            $args['author'] = get_current_user_id(); // only their own coupons are editable to them
        }

        $query    = new \WP_Query( $args );
        $coupons  = [];
        $left_out = 0;
        foreach ( $query->posts as $post ) {
            if ( ! Access::can_read_coupon( (int) $post->ID ) ) {
                ++$left_out;
                continue; // Coupons this account may not edit are left out (1.9.0).
            }
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
        $out = mcp_woo_add_note( [ 'coupons' => $coupons, 'total' => $query->found_posts ], $note );
        $out = mcp_woo_add_note( $out, mcp_woo_left_out_note( $left_out, 'coupon' ) );
        return $this->json_result( $out );
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
                'code'           => [ 'type' => 'string',  'description' => 'Coupon code (unique: a code a coupon already has is refused).' ],
                'type'           => [ 'type' => 'string',  'enum' => [ 'percent', 'fixed_cart', 'fixed_product' ], 'default' => 'percent' ],
                'amount'         => [ 'type' => 'number',  'description' => 'Discount amount.' ],
                'description'    => [ 'type' => 'string' ],
                'expiry_date'    => [ 'type' => 'string',  'description' => 'Expiry date, YYYY-MM-DD: the coupon expires at 00:00 (site time) at the start of that day, as in WooCommerce. Any other form is refused.' ],
                'usage_limit'    => [ 'type' => 'integer', 'description' => 'Max overall uses. 0 = unlimited.' ],
                'usage_limit_per_user' => [ 'type' => 'integer', 'description' => 'Max uses per customer. 0 = unlimited.' ],
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
        if ( '' === $code ) {
            return $this->error( 'The coupon code is empty.' );
        }
        // A second coupon with the same code was made (1.14.0); WooCommerce's own REST API
        // refuses that too.
        $taken = (int) wc_get_coupon_id_by_code( $code );
        if ( $taken > 0 ) {
            return $this->error( sprintf( 'A coupon with the code "%s" already exists (coupon %d). Coupon codes are unique: pick another code.', $code, $taken ) );
        }

        // An expiry date WooCommerce cannot read became 1970-01-01, so the coupon was made
        // already expired (1.14.0).
        $note    = null;
        $expires = null;
        if ( ! empty( $params['expiry_date'] ) ) {
            $raw  = trim( (string) $params['expiry_date'] );
            $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, wp_timezone() );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
                return $this->error( sprintf( 'Invalid expiry_date "%s": use YYYY-MM-DD, e.g. %s.', $raw, wp_date( 'Y' ) . '-12-31' ) );
            }
            $expires = $raw;
            if ( $raw <= wp_date( 'Y-m-d' ) ) {
                $note = "expiry_date {$raw} is not after today: the coupon expires at 00:00 at the start of that day, so it is already expired and cannot be used.";
            }
        }
        // -1 ("unlimited") became 1 use (1.14.0). 0 is unlimited.
        foreach ( [ 'usage_limit', 'usage_limit_per_user' ] as $field ) {
            if ( isset( $params[ $field ] ) && ( ! is_numeric( $params[ $field ] ) || (int) $params[ $field ] < 0 ) ) {
                return $this->error( sprintf( '%s must be 0 (unlimited) or more, not %s.', $field, is_scalar( $params[ $field ] ) ? (string) $params[ $field ] : gettype( $params[ $field ] ) ) );
            }
        }
        foreach ( [ 'minimum_amount', 'maximum_amount' ] as $field ) {
            if ( isset( $params[ $field ] ) && ( ! is_numeric( $params[ $field ] ) || (float) $params[ $field ] < 0 ) ) {
                return $this->error( sprintf( '%s must be a number, 0 or more.', $field ) );
            }
        }

        $coupon = new \WC_Coupon();
        try {
            $coupon->set_code( $code );
            $coupon->set_discount_type( $type );
            $coupon->set_amount( $amount );

            if ( ! empty( $params['description'] ) )    $coupon->set_description( sanitize_textarea_field( $params['description'] ) );
            if ( null !== $expires )                    $coupon->set_date_expires( $expires );
            if ( isset( $params['usage_limit'] ) )      $coupon->set_usage_limit( (int) $params['usage_limit'] );
            if ( isset( $params['usage_limit_per_user'] ) ) $coupon->set_usage_limit_per_user( (int) $params['usage_limit_per_user'] );
            if ( isset( $params['minimum_amount'] ) )   $coupon->set_minimum_amount( wc_format_decimal( $params['minimum_amount'] ) );
            if ( isset( $params['maximum_amount'] ) )   $coupon->set_maximum_amount( wc_format_decimal( $params['maximum_amount'] ) );
            if ( isset( $params['free_shipping'] ) )    $coupon->set_free_shipping( (bool) $params['free_shipping'] );

            $id = (int) $coupon->save();
        } catch ( \WC_Data_Exception $e ) {
            return $this->error( 'WooCommerce refused the coupon: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was saved.' );
        }
        if ( $id < 1 || 'shop_coupon' !== get_post_type( $id ) ) {
            // A save WordPress refused answered "id": 0, or the ID of whatever post 1 is:
            // WooCommerce's coupon store takes wp_insert_post()'s error as an ID (1.14.0).
            return $this->error( 'WooCommerce did not save the coupon. Nothing was created.' );
        }
        $out = [ 'id' => $id, 'code' => $code ];
        if ( null !== $expires ) {
            $stored             = $coupon->get_date_expires();
            $out['expiry_date'] = $stored ? $stored->date( 'Y-m-d' ) : null;
        }
        return $this->json_result( null !== $note ? $out + [ 'note' => $note ] : $out );
    }
}
