<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Coupon_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'coupon-management';
        $this->label       = __( 'WooCommerce – Coupons', 'mcp-abilities-woo' );
        $this->description = __( 'Create and inspect WooCommerce discount coupons.', 'mcp-abilities-woo' );
        $this->icon        = 'dashicons-tickets-alt';
        $this->guide       = __( "Spin up discount codes and check existing ones - percentage or fixed discounts, with the limits and rules you describe.", 'mcp-abilities-woo' );
        $this->examples    = [
            __( "Create a 15% off coupon code SUMMER15", 'mcp-abilities-woo' ),
            __( "Show the details of the FREESHIP coupon", 'mcp-abilities-woo' ),
            __( "Create a 10 EUR fixed-cart discount valid this month", 'mcp-abilities-woo' ),
        ];
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-coupon-crud.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Coupons_Ability',
            'MCP_Abilities\\Abilities\\Create_Coupon_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
