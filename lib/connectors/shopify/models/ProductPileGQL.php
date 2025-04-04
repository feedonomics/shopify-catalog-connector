<?php
namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;
use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use Generator;

/**
 * Model for a list of products from a GraphQL API response
 * <hr>
 * TODO: Combine this with ProductPile. Have getItems check if dealing with
 *   a Resource obj and adapt. Have constructor take in an iPager instead of
 *   currently taking raw page data and using iPagedResponse as a base class.
 */
final class ProductPileGQL extends PagedGQL implements iDataList
{

	/**
	 * @var array Store for the pile of raw products
	 */
	private array $products;

	/**
	 * The raw list of products returned from a GraphQL query
	 *
	 * @param array|null $nodes The product nodes
	 * @param array|null $pageInfo The page info
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function __construct(?array $nodes, ?array $pageInfo)
	{
		if ($nodes === null || $pageInfo === null) {
			throw new UnexpectedResponseException('Shopify', sprintf(
				'Response did not include requested data:%s%s',
				$nodes === null ? ' nodes' : '',
				$pageInfo === null ? ' pageInfo' : ''
			));
		}

		$this->products = $nodes;
		$this->setPageInfos($pageInfo);
	}

	/**
	 * Get the count of products in this pile
	 *
	 * @return int The product count
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
			yield $raw ? $prod : new Product($prod);
		}
	}

}
