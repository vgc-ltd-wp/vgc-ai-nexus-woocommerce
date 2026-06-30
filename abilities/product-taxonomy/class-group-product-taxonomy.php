<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Product_Taxonomy_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'product-taxonomy';
        $this->label       = __( 'WooCommerce – Product Taxonomy', 'mcp-abilities-woo' );
        $this->description = __( 'Manage WooCommerce product categories and product tags.', 'mcp-abilities-woo' );
        $this->icon        = 'dashicons-tag';
        $this->guide       = __( "Organise your shop: create and manage product categories and tags so customers can browse and filter easily.", 'mcp-abilities-woo' );
        $this->examples    = [
            __( "Create a 'Summer Collection' product category", 'mcp-abilities-woo' ),
            __( "List all product categories", 'mcp-abilities-woo' ),
            __( "Tag these products as 'handmade'", 'mcp-abilities-woo' ),
        ];
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-product-categories.php';
        require_once __DIR__ . '/class-ability-product-tags.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Product_Categories_Ability',
            'MCP_Abilities\\Abilities\\Get_Product_Category_Ability',
            'MCP_Abilities\\Abilities\\Create_Product_Category_Ability',
            'MCP_Abilities\\Abilities\\Update_Product_Category_Ability',
            'MCP_Abilities\\Abilities\\Delete_Product_Category_Ability',
            'MCP_Abilities\\Abilities\\List_Product_Tags_Ability',
            'MCP_Abilities\\Abilities\\Get_Product_Tag_Ability',
            'MCP_Abilities\\Abilities\\Create_Product_Tag_Ability',
            'MCP_Abilities\\Abilities\\Update_Product_Tag_Ability',
            'MCP_Abilities\\Abilities\\Delete_Product_Tag_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
