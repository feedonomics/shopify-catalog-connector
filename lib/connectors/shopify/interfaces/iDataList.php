<?php

namespace ShopifyConnector\connectors\shopify\interfaces;

use Generator;

/**
 * Interface for base classes responsible for housing a list of data objects
 */
interface iDataList
{

	/**
	 * Iterate through the stored list of objects
	 *
	 * @param bool $raw Flag to return the raw or parsed version of the object
	 * @return Generator<mixed> Return a generator to iterate through the
	 * objects one at a time
	 */
	public function getItems(bool $raw = false) : Generator;

}

