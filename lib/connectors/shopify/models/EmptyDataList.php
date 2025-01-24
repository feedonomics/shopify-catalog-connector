<?php
namespace ShopifyConnector\connectors\shopify\models;

use Generator;
use ShopifyConnector\connectors\shopify\interfaces\iDataList;

/**
 * An empty data list
 */
class EmptyDataList implements iDataList
{

	/**
	 * @inheritDoc
	 */
	public function getItems(bool $raw = false) : Generator
	{
		yield from [];
	}

}
