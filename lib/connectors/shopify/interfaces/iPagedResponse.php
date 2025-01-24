<?php

namespace ShopifyConnector\connectors\shopify\interfaces;

/**
 * Interface used by base classes for Shopify response/data models. This class
 * provides handling for higher-level concerns that may be present in response
 * data such as pagination.
 */
interface iPagedResponse
{

	/**
	 * Set the page info from the last pull
	 *
	 * @param array $pi The page info
	 */
	public function setPageInfos(array $pi) : void;

	/**
	 * Check if there is a previous page available
	 *
	 * @return bool True if there is a previous page
	 */
	public function hasPrevPage() : bool;

	/**
	 * Check if there is a next page available
	 *
	 * @return bool True if there is a next page
	 */
	public function hasNextPage() : bool;

	/**
	 * Get the previous page's cursor
	 *
	 * @return ?string The previous page's cursor or null if there isn't one
	 */
	public function getPrevPageInfo() : ?string;

	/**
	 * Get the next page's cursor
	 *
	 * @return ?string The next page's cursor or null if there isn't one
	 */
	public function getNextPageInfo() : ?string;

}

