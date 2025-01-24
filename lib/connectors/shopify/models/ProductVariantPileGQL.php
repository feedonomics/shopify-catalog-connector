<?php
namespace ShopifyConnector\connectors\shopify\models;

use Generator;
use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Model for a pile of variants returned from a GraphQL query
 */
final class ProductVariantPileGQL extends PagedGQL implements iDataList
{

	/**
	 * @var array Store for the variant nodes
	 */
	private array $variants;

	/**
	 * Model for a pile of variants returned from a GraphQL query
	 *
	 * @param array|null $nodes The variant nodes from GraphQL
	 * @param array|null $pageInfo The page info from GraphQL
	 * @throws ApiResponseException On invalid nodes or page info
	 */
	public function __construct(?array $nodes, ?array $pageInfo)
	{
		if ($nodes === null || $pageInfo === null) {
			throw new ApiResponseException(sprintf(
				'Response did not include requested data:%s%s',
				$nodes === null ? ' nodes' : '',
				$pageInfo === null ? ' pageInfo' : ''
			));
		}

		$this->variants = $nodes;
		$this->setPageInfos($pageInfo);
	}

	/**
	 * Get the count of stored variants
	 *
	 * @return int The variant count
	 */
	public function getVariantCount() : int
	{
		return count($this->variants);
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(bool $raw = false) : Generator
	{
		foreach ($this->variants as $var) {
			yield $raw ? $var : new ProductVariant(
				new Product($var['product'] ?? []),
				$var
			);
		}
	}

}
