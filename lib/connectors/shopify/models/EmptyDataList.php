<?php
namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use Generator;

/**
 * @deprecated
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
