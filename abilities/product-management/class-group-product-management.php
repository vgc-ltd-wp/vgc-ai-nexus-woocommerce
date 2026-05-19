<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Product_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'product-management';
        $this->label       = __( 'WooCommerce – Products', 'mcp-abilities-woo' );
        $this->description = __( 'Create, read, update and delete WooCommerce products and their variations.', 'mcp-abilities-woo' );
        $this->icon        = 'dashicons-cart';
    }

    /**
     * Explicitly register all abilities from the multi-class CRUD file.
     * auto_discover_abilities() only finds one class per file based on the filename
     * (e.g. Product_Crud_Ability), which doesn't exist here — so we load them manually.
     */
    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-product-crud.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Products_Ability',
            'MCP_Abilities\\Abilities\\Get_Product_Ability',
            'MCP_Abilities\\Abilities\\Create_Product_Ability',
            'MCP_Abilities\\Abilities\\Update_Product_Ability',
            'MCP_Abilities\\Abilities\\Delete_Product_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
