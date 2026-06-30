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
        $this->guide       = __( "Stay on top of orders: list and inspect them, check statuses, and move orders along - for example mark one as completed - from the chat.", 'mcp-abilities-woo' );
        $this->examples    = [
            __( "List today's processing orders", 'mcp-abilities-woo' ),
            __( "Mark order #1052 as completed", 'mcp-abilities-woo' ),
            __( "What is in order #1048 and who placed it?", 'mcp-abilities-woo' ),
        ];
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
