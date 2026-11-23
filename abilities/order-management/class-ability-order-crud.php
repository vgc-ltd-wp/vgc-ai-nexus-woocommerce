<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use VGC\AI_Nexus_Woo\Access;

defined( 'ABSPATH' ) || exit;

// ── List Orders ───────────────────────────────────────────────────────────────

class List_Orders_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'wc_list_orders';
        $this->label        = __( 'List Orders', 'mcp-abilities-woo' );
        $this->description  = 'List WooCommerce orders, newest first, with optional status, customer and date filters. "total" says how many orders match.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                // An open list (1.14.0): a shop adds its own order statuses, so these are
                // samples, not an enum. execute() checks the value against the shop's.
                'status'    => [ 'type' => 'string',  'examples' => [ 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ], 'default' => 'any', 'description' => '"any" (every status, unfinished checkouts "checkout-draft" included), or an order status of this shop, e.g. processing, completed; a shop may add its own. The "wc-" prefix may be left out. An unknown status is refused with the valid ones.' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20, 'description' => '1 to 100; -1 gives 100 (the most at once).' ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'customer_id' => [ 'type' => 'integer', 'description' => 'Filter by customer user ID; 0 lists the guest orders (no customer account).' ],
                'date_after'  => [ 'type' => 'string',  'description' => 'A day, YYYY-MM-DD. Orders placed after this day (site time; the day itself is not included). A time of day is not used.' ],
                'date_before' => [ 'type' => 'string',  'description' => 'A day, YYYY-MM-DD. Orders placed before this day (site time; the day itself is not included). A time of day is not used.' ],
                'search'    => [ 'type' => 'string',  'description' => 'Search by order number, billing name or email.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $status   = mcp_woo_order_status_slug( sanitize_key( (string) ( $params['status'] ?? 'any' ) ) );
        $statuses = array_keys( wc_get_order_statuses() );
        if ( '' !== $status && 'any' !== $status && ! in_array( 'wc-' . $status, $statuses, true ) ) {
            return $this->error( "Invalid status '{$status}'. Valid options: any, " . implode( ', ', array_map( 'mcp_woo_order_status_slug', $statuses ) ) . '.' );
        }

        // per_page -1 gave 1 order, more than 100 was cut down with no word, and there was no
        // total (1.14.0).
        $paging = mcp_woo_paging( $params, 20, 100 );
        if ( is_string( $paging ) ) {
            return $this->error( $paging );
        }
        [ $per_page, $page, $note ] = $paging;

        $args = [
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'status'   => ( '' === $status || 'any' === $status ) ? $statuses : [ 'wc-' . $status ],
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        ];

        // customer_id 0 (guest orders) listed every order, and a negative ID was read as
        // another customer's (1.14.0).
        if ( isset( $params['customer_id'] ) && '' !== $params['customer_id'] ) {
            if ( ! is_numeric( $params['customer_id'] ) || (int) $params['customer_id'] < 0 ) {
                return $this->error( 'customer_id must be a customer\'s user ID, or 0 for guest orders.' );
            }
            $args['customer_id'] = (int) $params['customer_id'];
        }

        // Dates are whole days in site time, both ends left out (WooCommerce's ">" and "<").
        // With both, the same range is written as the days in between, inclusive. A time of
        // day was dropped with no word (1.14.0: the note says so).
        $days = [];
        foreach ( [ 'date_after' => 1, 'date_before' => -1 ] as $name => $shift ) {
            if ( empty( $params[ $name ] ) ) {
                continue;
            }
            $date = sanitize_text_field( (string) $params[ $name ] );
            if ( false === strtotime( $date ) ) {
                return $this->error( "Invalid {$name} value '{$date}'. Use a day, YYYY-MM-DD, e.g. 2024-01-15." );
            }
            $days[ $name ] = [ wc_string_to_datetime( $date ), $shift ];
            if ( preg_match( '/\d{1,2}:\d{2}/', $date ) ) {
                $note = ( null !== $note ? $note . ' ' : '' ) . sprintf( '%s: only the day of "%s" was used (%s); dates are whole days in site time, the day itself not included.', $name, $date, $days[ $name ][0]->date( 'Y-m-d' ) );
            }
        }
        if ( 2 === count( $days ) ) {
            $range = array_map( static function ( array $d ): string {
                return $d[0]->modify( sprintf( '%+d day', $d[1] ) )->date( 'Y-m-d' );
            }, $days );
            $args['date_created'] = $range['date_after'] . '...' . $range['date_before'];
        } elseif ( isset( $days['date_after'] ) ) {
            $args['date_created'] = '>' . $days['date_after'][0]->date( 'Y-m-d' );
        } elseif ( isset( $days['date_before'] ) ) {
            $args['date_created'] = '<' . $days['date_before'][0]->date( 'Y-m-d' );
        }

        // Search the way WooCommerce's REST API does: HPOS searches in the query,
        // the posts storage through wc_order_search().
        $search = trim( sanitize_text_field( (string) ( $params['search'] ?? '' ) ) );
        if ( '' !== $search ) {
            $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            if ( $hpos ) {
                $args['s'] = $search;
            } else {
                $ids              = array_map( 'absint', (array) wc_order_search( $search ) );
                $args['post__in'] = array_merge( $ids, [ 0 ] );
            }
        }

        // Orders this account may not read are left out (1.9.0), and the note says how many.
        $found  = wc_get_orders( $args );
        $all    = is_object( $found ) && isset( $found->orders ) ? (array) $found->orders : (array) $found;
        $total  = is_object( $found ) && isset( $found->total ) ? (int) $found->total : count( $all );
        $orders = Access::readable_orders( $all );
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

        $out = mcp_woo_add_note( [ 'orders' => $result, 'total' => $total ], $note );
        $out = mcp_woo_add_note( $out, mcp_woo_left_out_note( count( array_filter( $all, static fn( $o ) => $o instanceof \WC_Order ) ) - count( $orders ), 'order' ) );
        return $this->json_result( $out );
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
        $order = Access::readable_order( absint( $params['id'] ) );
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
        $this->description  = 'Change the status of a WooCommerce order with an optional note. An order that already has the status keeps it, and the note is still added.';
        $this->required_cap = 'edit_shop_orders';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'     => [ 'type' => 'integer', 'description' => 'Order ID.' ],
                // An open list (1.14.0): a shop adds its own order statuses. execute() checks it.
                'status' => [ 'type' => 'string', 'examples' => [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ], 'description' => 'New status: an order status of this shop (the "wc-" prefix may be left out), e.g. processing, completed; a shop may add its own. An unknown status is refused with the valid ones.' ],
                'note'   => [ 'type' => 'string', 'description' => 'Optional order note.' ],
            ],
            'required' => [ 'id', 'status' ],
        ];
    }

    public function execute( array $params ): array {
        $order = Access::readable_order( absint( $params['id'] ) );
        if ( ! $order ) {
            return $this->error( 'Order not found.' );
        }
        if ( ! Access::can_order( $order, 'edit' ) ) {
            return $this->error( 'You do not have permission to edit this order.' );
        }

        $new_status = mcp_woo_order_status_slug( sanitize_key( $params['status'] ) );
        $valid      = array_map( 'mcp_woo_order_status_slug', array_keys( wc_get_order_statuses() ) );

        if ( ! in_array( $new_status, $valid, true ) ) {
            return $this->error( "Invalid status. Valid options: " . implode( ', ', $valid ) );
        }

        $note = sanitize_textarea_field( $params['note'] ?? '' );
        $id   = $order->get_id();

        // WooCommerce stores the note only when the status changes, so an order that already
        // had it lost the note while the answer said "updated" (1.14.0).
        if ( $order->get_status() === $new_status ) {
            $note_id = '' !== $note ? (int) $order->add_order_note( $note, 0, true ) : 0;
            if ( '' !== $note && $note_id < 1 ) {
                return $this->error( "Order {$id} already had the status '{$new_status}', and the note could not be added." );
            }
            return $this->success(
                "Order {$id} already had the status '{$new_status}'; nothing changed" . ( $note_id ? ', and the note was added.' : '.' ),
                array_merge( [ 'status' => $new_status, 'changed' => false ], $note_id ? [ 'note_id' => $note_id ] : [] )
            );
        }

        // update_status() answers false when WooCommerce caught an error, and the answer
        // said "updated" (1.14.0). The order is read again: a plugin may have stopped it.
        $done  = $order->update_status( $new_status, $note, true );
        $fresh = wc_get_order( $id );
        $now   = $fresh ? $fresh->get_status() : '';
        if ( ! $done || $now !== $new_status ) {
            return $this->error( "Order {$id}'s status is '{$now}', not '{$new_status}': WooCommerce or a plugin refused or changed it (the order's notes may say why)." );
        }

        return $this->success( "Order {$id} status updated to '{$new_status}'.", [ 'status' => $now, 'changed' => true ] );
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
        $order = Access::readable_order( absint( $params['id'] ) );
        if ( ! $order ) {
            return $this->error( 'Order not found.' );
        }
        if ( ! Access::can_order( $order, 'edit' ) ) {
            return $this->error( 'You do not have permission to edit this order.' );
        }

        $note_id = $order->add_order_note(
            sanitize_textarea_field( $params['note'] ),
            ! empty( $params['customer_note'] ) ? 1 : 0,
            true
        );
        if ( (int) $note_id < 1 ) {
            // A note WordPress did not store answered "note_id": 0 (1.14.0).
            return $this->error( "The note could not be added to order {$order->get_id()}." );
        }

        return $this->json_result( [ 'note_id' => $note_id ] );
    }
}
