<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── Get Customer ──────────────────────────────────────────────────────────────

class Get_Customer_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_get_customer';
        $this->label        = __( 'Get Customer', 'mcp-abilities-woo' );
        $this->description  = 'Retrieve WooCommerce customer profile including billing/shipping and order stats.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'    => [ 'type' => 'integer', 'description' => 'Customer user ID.' ],
                'email' => [ 'type' => 'string',  'description' => 'Email address (used if id not provided).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! empty( $params['id'] ) ) {
            try {
                $customer = new \WC_Customer( absint( $params['id'] ) );
            } catch ( \Exception $e ) {
                return $this->error( 'Customer not found.' );
            }
        } elseif ( ! empty( $params['email'] ) ) {
            $user = get_user_by( 'email', sanitize_email( $params['email'] ) );
            if ( ! $user ) {
                return $this->error( 'User with this email not found.' );
            }
            $customer = new \WC_Customer( $user->ID );
        } else {
            return $this->error( 'Provide id (customer user ID) or email.' );
        }

        return $this->json_result( [
            'id'             => $customer->get_id(),
            'email'          => $customer->get_email(),
            'username'       => $customer->get_username(),
            'first_name'     => $customer->get_first_name(),
            'last_name'      => $customer->get_last_name(),
            'date_created'   => $customer->get_date_created()?->date( 'Y-m-d H:i:s' ),
            'orders_count'   => $customer->get_order_count(),
            'total_spent'    => $customer->get_total_spent(),
            'billing'        => [
                'first_name' => $customer->get_billing_first_name(),
                'last_name'  => $customer->get_billing_last_name(),
                'company'    => $customer->get_billing_company(),
                'address_1'  => $customer->get_billing_address_1(),
                'city'       => $customer->get_billing_city(),
                'state'      => $customer->get_billing_state(),
                'postcode'   => $customer->get_billing_postcode(),
                'country'    => $customer->get_billing_country(),
                'email'      => $customer->get_billing_email(),
                'phone'      => $customer->get_billing_phone(),
            ],
            'shipping'       => [
                'first_name' => $customer->get_shipping_first_name(),
                'last_name'  => $customer->get_shipping_last_name(),
                'address_1'  => $customer->get_shipping_address_1(),
                'city'       => $customer->get_shipping_city(),
                'country'    => $customer->get_shipping_country(),
            ],
        ] );
    }
}

// ── List Customer Orders ──────────────────────────────────────────────────────

class List_Customer_Orders_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_customer_orders';
        $this->label        = __( 'List Customer Orders', 'mcp-abilities-woo' );
        $this->description  = 'List all orders for a specific WooCommerce customer.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'       => [ 'type' => 'integer', 'description' => 'Customer user ID.' ],
                'per_page' => [ 'type' => 'integer', 'default' => 10 ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $orders = wc_get_orders( [
            'customer_id' => absint( $params['id'] ),
            'limit'       => min( absint( $params['per_page'] ?? 10 ), 50 ),
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        $result = array_map( fn( $o ) => [
            'id'           => $o->get_id(),
            'status'       => $o->get_status(),
            'total'        => $o->get_total(),
            'currency'     => $o->get_currency(),
            'date_created' => $o->get_date_created()?->date( 'Y-m-d H:i:s' ),
            'items_count'  => count( $o->get_items() ),
        ], $orders );

        return $this->json_result( [ 'orders' => $result ] );
    }
}
