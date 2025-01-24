<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\interfaces\iDataList;

use Generator;

/**
 * Model for a list of products from a REST API response
 */
final class ProductPile extends PagedREST implements iDataList
{

	/**
	 * @var array Store for the raw product data
	 */
	private array $products;

	/**
	 * The list of products provided here is expected to come from the
	 * client library, so is expected to be a list of Resource objects. The
	 * de-resource'ing into Product objects will be handled internally by
	 * this class.
	 *
	 * @param array $prods Array of products from API response
	 * @param array $pageLinks Pagination links as returned by
	 *   {@see ShopifyClient::parseLastPaginationLinkHeader}
	 */
	public function __construct(array $prods, array $pageLinks)
	{
		$this->products = $prods;
		$this->setPageInfos($pageLinks);
	}

	/**
	 * Get the count of available products
	 *
	 * @return int The count of products in this pile
	 */
	public function getProductCount() : int
	{
		return count($this->products);
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(bool $raw = false) : Generator
	{
		foreach ($this->products as $prod) {
			yield $raw ? $prod->items : new Product($prod->items);
		}
	}

}

