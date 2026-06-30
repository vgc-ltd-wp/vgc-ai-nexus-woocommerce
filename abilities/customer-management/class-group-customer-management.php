<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Customer_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug             = 'customer-management';
        $this->label            = __( 'WooCommerce – Customers', 'mcp-abilities-woo' );
        $this->description      = __( 'Look up WooCommerce customer profiles and order history.', 'mcp-abilities-woo' );
        $this->icon             = 'dashicons-groups';
        $this->guide       = __( "Look up who is buying: find customer profiles and review their order history to answer support and sales questions.", 'mcp-abilities-woo' );
        $this->examples    = [
            __( "Show me jane@example.com's order history", 'mcp-abilities-woo' ),
            __( "Who are my top customers by number of orders?", 'mcp-abilities-woo' ),
            __( "Look up the customer profile for order #1043", 'mcp-abilities-woo' ),
        ];
        $this->security_warning = __( 'Privacy notice: these tools expose full customer PII including billing address, phone number, email and total spend history. Only enable this if the connected AI client is trusted and your data processing complies with applicable privacy regulations (GDPR, CCPA etc.).', 'mcp-abilities-woo' );
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-customer-read.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\Get_Customer_Ability',
            'MCP_Abilities\\Abilities\\List_Customer_Orders_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
