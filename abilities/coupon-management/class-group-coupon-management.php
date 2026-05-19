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
