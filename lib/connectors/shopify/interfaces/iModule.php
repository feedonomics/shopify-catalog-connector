<?php

namespace ShopifyConnector\connectors\shopify\interfaces;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
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
	 * Prepare temp tables, inserters, and puller. Called before bulk operations start.
	 * Returns the module's BulkBase puller if it uses bulk operations, null otherwise.
	 *
	 * @param MysqliWrapper $cxn
	 * @return ?BulkBase
	 * @throws InfrastructureErrorException
	 */
	public function prepare(MysqliWrapper $cxn) : ?BulkBase;

	/**
	 * @param MysqliWrapper $cxn
	 * @param PullStats $stats
	 * @param string|null $bulk_file Path to a pre-downloaded bulk file, or null to pull normally
	 * @throws InfrastructureErrorException
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats, ?string $bulk_file = null) : void;

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

	/**
	 * Preload product data for a batch of product IDs.
	 * After this call, add_data_to_product() for these IDs should use cached data.
	 *
	 * @param MysqliWrapper $cxn
	 * @param int[] $product_ids
	 */
	public function preload_products(MysqliWrapper $cxn, array $product_ids) : void;

	/**
	 * Preload variant data for a batch of parent product IDs.
	 * After this call, add_data_to_variant() for variants of these products should use cached data.
	 *
	 * @param MysqliWrapper $cxn
	 * @param int[] $product_ids Parent product IDs
	 */
	public function preload_variants(MysqliWrapper $cxn, array $product_ids) : void;

	/**
	 * Clear any preloaded data caches. Called between output batches.
	 */
	public function clear_preload_cache() : void;

}
