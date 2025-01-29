<?php

namespace ShopifyConnector\connectors\shopify\structs;

/**
 * Container for any results generated during bulk processing. This class may extended
 * with custom logic if any bulk processors need to do anything really crazy.
 *
 * This basic base simply includes an array that can be accessed in a consistent manner
 * by processors.
 */
class BulkProcessingResult
{

	public array $result = [];

}

