<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Implement this interface to signify that you are pulling data for products and variants from Shopify
 */
interface Puller
{
	/**
	 * @param MysqliWrapper $cxn
	 * @param BatchedDataInserter $insert_product
	 * @param BatchedDataInserter $insert_variant
	 * @return BulkProcessingResult
	 */
	public function pull(
		MysqliWrapper $cxn,
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : BulkProcessingResult;
}
