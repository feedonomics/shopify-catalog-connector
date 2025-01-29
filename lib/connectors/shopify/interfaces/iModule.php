<?php

namespace ShopifyConnector\connectors\shopify\interfaces;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\structs\PullStats;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use Generator;

/**
 * Interface that all module main classes must implement.
 *
 * The signature for a module's constructor must be exactly as follows:
 *   public function __construct(SessionContainer $session);
 *
 */
interface iModule
{

	public function __construct(SessionContainer $session);

	public function get_module_name() : string;

	public function get_output_field_list() : array;

	/**
	 * @throws InfrastructureErrorException
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats) : void;

	/**
	 * This should yield a product at a time from the result set. The product
	 * should have all of its variants attached before being yielded.
	 *
	 * @return Generator<Product>
	 * @throws InfrastructureErrorException
	 */
	public function get_products(MysqliWrapper $cxn) : Generator;

	/**
	 * @throws InfrastructureErrorException
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void;

	/**
	 * @throws InfrastructureErrorException
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void;

}

