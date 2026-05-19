<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Order_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'order-management';
        $this->label       = __( 'WooCommerce – Orders', 'mcp-abilities-woo' );
        $this->description = __( 'List, inspect and update WooCommerce orders and their statuses.', 'mcp-abilities-woo' );
        $this->icon        = 'dashicons-clipboard';
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-order-crud.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Orders_Ability',
            'MCP_Abilities\\Abilities\\Get_Order_Ability',
            'MCP_Abilities\\Abilities\\Update_Order_Status_Ability',
            'MCP_Abilities\\Abilities\\Add_Order_Note_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
