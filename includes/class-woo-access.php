<?php
namespace VGC\AI_Nexus_Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Per-object permission rules for the WooCommerce tools (1.9.0).
 *
 * The tool gate checks one capability per tool (edit_products, edit_shop_orders,
 * manage_woocommerce). Which product, order, coupon or customer the caller may touch is
 * decided here, the way WordPress and WooCommerce decide it on their own screens. With the
 * stock roles (administrator, shop manager) nothing changes. It matters where a role holds
 * those capabilities for its own objects only, as multi-vendor plugins and custom roles do.
 *
 * Something the caller may not read answers like a missing one; lists leave it out.
 */
final class Access {

    // ── Products ─────────────────────────────────────────────────────────────

    /** The post the checks are made on: the product itself, or a variation's parent product. */
    public static function product_post( \WC_Product $product ): ?\WP_Post {
        $id = $product->get_id();
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $id = $product->get_parent_id();
        }
        $post = get_post( $id );
        return $post instanceof \WP_Post ? $post : null;
    }

    /** Whether the caller may read this product (a variation: its parent product). */
    public static function can_read_product( \WC_Product $product ): bool {
        $post = self::product_post( $product );
        return null !== $post && self::can_read_post( $post );
    }

    /** Whether the caller may edit this product (a variation: its parent product). */
    public static function can_edit_product( \WC_Product $product ): bool {
        $post = self::product_post( $product );
        return null !== $post && current_user_can( 'edit_post', $post->ID );
    }

    /** Whether the caller may delete this product (a variation: its parent product). */
    public static function can_delete_product( \WC_Product $product ): bool {
        $post = self::product_post( $product );
        return null !== $post && current_user_can( 'delete_post', $post->ID );
    }

    /**
     * Why the caller may not give this product this status, or null when they may.
     *
     * The rule of core's Post_Access::update_status(): trash needs `delete_post`, publish and
     * private need `publish_post` (publish_products). Where core saves such a post as pending,
     * this refuses the update, since a variation has no pending status.
     */
    public static function product_status_error( \WC_Product $product, string $status ): ?string {
        if ( $status === $product->get_status() ) {
            return null;
        }
        $post = self::product_post( $product );
        if ( null === $post ) {
            return 'Product not found.';
        }
        if ( 'trash' === $status && ! current_user_can( 'delete_post', $post->ID ) ) {
            return 'You do not have permission to move this product to the trash.';
        }
        if ( in_array( $status, [ 'publish', 'private' ], true ) && ! current_user_can( 'publish_post', $post->ID ) ) {
            return sprintf( 'Status "%s" needs the permission to publish products (publish_products), which this account does not have. Use "pending" to send the product for review.', $status );
        }
        return null;
    }

    /**
     * Whether the caller may read this post: core's rule (Post_Access::can_read, AI Nexus
     * 2.33.0 and later), or the same rule here on an older core. The type is public or the
     * caller may edit posts of it, `read_post` passes, and a post that is not publicly visible
     * (draft, pending, private, trashed, password-protected) also needs `edit_post`.
     */
    public static function can_read_post( \WP_Post $post ): bool {
        if ( class_exists( '\MCP_Abilities\Post_Access' ) && method_exists( '\MCP_Abilities\Post_Access', 'can_read' ) ) {
            return \MCP_Abilities\Post_Access::can_read( $post );
        }
        $pto = get_post_type_object( $post->post_type );
        if ( ! $pto || ( ! $pto->public && ! current_user_can( $pto->cap->edit_posts ) ) ) {
            return false;
        }
        if ( ! current_user_can( 'read_post', $post->ID ) ) {
            return false;
        }
        $status = get_post_status_object( (string) get_post_status( $post ) );
        $public = '' === (string) $post->post_password && $status && $status->public;
        return $public || current_user_can( 'edit_post', $post->ID );
    }

    /**
     * Restrict a product list query to what the caller may see: core's rule
     * (Post_Access::scope_list_query, AI Nexus 2.33.0 and later), or the same rule here.
     * Published products are listed for everyone; other statuses list every author's
     * products only for someone who may edit others' and read private products, and the
     * caller's own products for anyone else.
     *
     * @param array<string,mixed> $args WP_Query args (no post__in, no author), changed in place.
     * @return array{error:?string,note:?string}
     */
    public static function scope_list_query( array &$args ): array {
        if ( class_exists( '\MCP_Abilities\Post_Access' ) && method_exists( '\MCP_Abilities\Post_Access', 'scope_list_query' ) ) {
            return \MCP_Abilities\Post_Access::scope_list_query( $args );
        }
        $args['perm'] = 'readable';
        $statuses     = array_map( 'strval', (array) ( $args['post_status'] ?? 'publish' ) );
        if ( [ 'publish' ] === $statuses ) {
            return [ 'error' => null, 'note' => null ];
        }
        foreach ( (array) ( $args['post_type'] ?? 'post' ) as $type ) {
            $pto = get_post_type_object( (string) $type );
            if ( ! $pto || ! current_user_can( $pto->cap->edit_others_posts ) || ! current_user_can( $pto->cap->read_private_posts ) ) {
                $args['author'] = get_current_user_id();
                return [
                    'error' => null,
                    'note'  => 'Only your own items are listed for this status: other authors\' unpublished items need the "edit others" permission for this content type.',
                ];
            }
        }
        return [ 'error' => null, 'note' => null ];
    }

    // ── Coupons ──────────────────────────────────────────────────────────────

    /**
     * Whether the caller may see this coupon. A coupon's code is what gives the discount, so
     * it is shown only to someone who may edit that coupon (`edit_post`). With coupons turned
     * off in WooCommerce the coupon type is not registered and there is no per-coupon rule to
     * ask; the coupon tools' own gate (manage_woocommerce) decides then.
     */
    public static function can_read_coupon( int $coupon_id ): bool {
        if ( $coupon_id <= 0 ) {
            return false;
        }
        if ( ! post_type_exists( 'shop_coupon' ) ) {
            return current_user_can( 'manage_woocommerce' );
        }
        return current_user_can( 'edit_post', $coupon_id );
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    /**
     * Whether the caller may read ('read') or change ('edit') this order: WooCommerce's own
     * rule for its order screen, the order type's meta capability on this order
     * (read_shop_order / edit_shop_order) or manage_woocommerce. With HPOS, WordPress maps
     * the meta capability through the order's placeholder post (shop_order_placehold), which
     * asks for edit_others_posts; WooCommerce lets manage_woocommerce through for that
     * reason, and so does this.
     */
    public static function can_order( \WC_Abstract_Order $order, string $action = 'read' ): bool {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }
        $pto = get_post_type_object( $order->get_type() );
        if ( ! $pto ) {
            return false;
        }
        $cap = 'edit' === $action ? $pto->cap->edit_post : $pto->cap->read_post;
        return current_user_can( $cap, $order->get_id() );
    }

    /** The order with this ID, when it is an order (not a refund) and the caller may read it. */
    public static function readable_order( int $id ): ?\WC_Order {
        $order = $id > 0 ? wc_get_order( $id ) : false;
        return $order instanceof \WC_Order && self::can_order( $order, 'read' ) ? $order : null;
    }

    /**
     * Leave out the orders the caller may not read.
     *
     * @param mixed[] $orders
     * @return \WC_Order[]
     */
    public static function readable_orders( array $orders ): array {
        return array_values( array_filter( $orders, static function ( $order ): bool {
            return $order instanceof \WC_Order && self::can_order( $order, 'read' );
        } ) );
    }

    // ── Customers ────────────────────────────────────────────────────────────

    /**
     * Whether the caller may read this user's customer record (addresses, phone, spend).
     * WooCommerce's REST API reads customers with list_users; a role without it may read
     * the users it may edit (`edit_user`: itself, and whom WordPress and WooCommerce let it
     * edit).
     */
    public static function can_read_customer( int $user_id ): bool {
        return $user_id > 0 && ( current_user_can( 'list_users' ) || current_user_can( 'edit_user', $user_id ) );
    }
}
