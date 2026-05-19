<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Orders ───────────────────────────────────────────────────────────────

class List_Orders_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_orders';
        $this->label        = __( 'List Orders', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce orders with optional status and date filters.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'status'    => [ 'type' => 'string',  'enum' => [ 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ], 'default' => 'any' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'customer_id' => [ 'type' => 'integer', 'description' => 'Filter by customer user ID.' ],
                'date_after'  => [ 'type' => 'string',  'description' => 'ISO8601 date. Orders placed after this date.' ],
                'date_before' => [ 'type' => 'string',  'description' => 'ISO8601 date. Orders placed before this date.' ],
                'search'    => [ 'type' => 'string',  'description' => 'Search by order number, billing name or email.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $status = sanitize_text_field( $params['status'] ?? 'any' );

        $args = [
            'limit'    => min( absint( $params['per_page'] ?? 20 ), 100 ),
            'page'     => max( 1, absint( $params['page'] ?? 1 ) ),
            'status'   => $status === 'any' ? array_keys( wc_get_order_statuses() ) : [ 'wc-' . ltrim( $status, 'wc-' ) ],
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        ];

        if ( ! empty( $params['customer_id'] ) ) {
            $args['customer_id'] = absint( $params['customer_id'] );
        }
        if ( ! empty( $params['date_after'] ) ) {
            $date = sanitize_text_field( $params['date_after'] );
            if ( false === strtotime( $date ) ) {
                return $this->error( "Invalid date_after value '{$date}'. Use ISO8601 format, e.g. 2024-01-15 or 2024-01-15T00:00:00." );
            }
            $args['date_created'] = '>' . $date;
        }

        $orders = wc_get_orders( $args );
        $result = array_map( fn( $o ) => [
            'id'             => $o->get_id(),
            'number'         => $o->get_order_number(),
            'status'         => $o->get_status(),
            'date_created'   => $o->get_date_created()?->date( 'Y-m-d H:i:s' ),
            'total'          => $o->get_total(),
            'currency'       => $o->get_currency(),
            'customer_id'    => $o->get_customer_id(),
            'billing_email'  => $o->get_billing_email(),
            'billing_name'   => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
            'items_count'    => count( $o->get_items() ),
            'payment_method' => $o->get_payment_method_title(),
        ], $orders );

        return $this->json_result( [ 'orders' => $result ] );
    }
}

// ── Get Order ─────────────────────────────────────────────────────────────────

class Get_Order_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_get_order';
        $this->label        = __( 'Get Order', 'mcp-abilities-woo' );
        $this->description  = 'Retrieve full details of a WooCommerce order including line items.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Order ID.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $order = wc_get_order( absint( $params['id'] ) );
        if ( ! $order ) {
            return $this->error( 'Order not found.' );
        }

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'qty'        => $item->get_quantity(),
                'total'      => $item->get_total(),
                'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
            ];
        }

        return $this->json_result( [
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            'date_created'    => $order->get_date_created()?->date( 'Y-m-d H:i:s' ),
            'date_modified'   => $order->get_date_modified()?->date( 'Y-m-d H:i:s' ),
            'customer_id'     => $order->get_customer_id(),
            'billing'         => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ],
            'shipping'        => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'city'       => $order->get_shipping_city(),
                'country'    => $order->get_shipping_country(),
            ],
            'items'           => $items,
            'subtotal'        => $order->get_subtotal(),
            'total_tax'       => $order->get_total_tax(),
            'shipping_total'  => $order->get_shipping_total(),
            'total'           => $order->get_total(),
            'currency'        => $order->get_currency(),
            'payment_method'  => $order->get_payment_method_title(),
            'customer_note'   => $order->get_customer_note(),
        ] );
    }
}

// ── Update Order Status ───────────────────────────────────────────────────────

class Update_Order_Status_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_update_order_status';
        $this->label        = __( 'Update Order Status', 'mcp-abilities-woo' );
        $this->description  = 'Change the status of a WooCommerce order with an optional note.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'     => [ 'type' => 'integer', 'description' => 'Order ID.' ],
                'status' => [ 'type' => 'string', 'enum' => [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ], 'description' => 'New status (without wc- prefix).' ],
                'note'   => [ 'type' => 'string', 'description' => 'Optional order note.' ],
            ],
            'required' => [ 'id', 'status' ],
        ];
    }

    public function execute( array $params ): array {
        $order = wc_get_order( absint( $params['id'] ) );
        if ( ! $order ) {
            return $this->error( 'Order not found.' );
        }

        $new_status = sanitize_key( $params['status'] );
        $valid      = array_keys( wc_get_order_statuses() );
        $valid      = array_map( fn( $s ) => ltrim( $s, 'wc-' ), $valid );

        if ( ! in_array( $new_status, $valid, true ) ) {
            return $this->error( "Invalid status. Valid options: " . implode( ', ', $valid ) );
        }

        $note = sanitize_textarea_field( $params['note'] ?? '' );
        $order->update_status( $new_status, $note, true );

        return $this->success( "Order {$order->get_id()} status updated to '{$new_status}'." );
    }
}

// ── Add Order Note ────────────────────────────────────────────────────────────

class Add_Order_Note_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_add_order_note';
        $this->label        = __( 'Add Order Note', 'mcp-abilities-woo' );
        $this->description  = 'Add an internal or customer-facing note to an order.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'            => [ 'type' => 'integer', 'description' => 'Order ID.' ],
                'note'          => [ 'type' => 'string' ],
                'customer_note' => [ 'type' => 'boolean', 'description' => 'If true, note is visible to customer.', 'default' => false ],
            ],
            'required' => [ 'id', 'note' ],
        ];
    }

    public function execute( array $params ): array {
        $order = wc_get_order( absint( $params['id'] ) );
        if ( ! $order ) {
            return $this->error( 'Order not found.' );
        }

        $note_id = $order->add_order_note(
            sanitize_textarea_field( $params['note'] ),
            ! empty( $params['customer_note'] ) ? 1 : 0,
            true
        );

        return $this->json_result( [ 'note_id' => $note_id ] );
    }
}
