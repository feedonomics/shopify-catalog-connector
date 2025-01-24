<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\interfaces\iPagedResponse;

/**
 * Base class for Shopify response/data models. This class provides handling
 * for higher-level concerns that may be present in response data such as
 * pagination.
 * GraphQL Edition
 */
abstract class PagedGQL implements iPagedResponse
{

	/**
	 * @var bool Store for the has-previous-page flag
	 */
	private bool $has_prev;

	/**
	 * @var bool Store for the has-next-page flag
	 */
	private bool $has_next;

	/**
	 * @var ?string Store for the previous page cursor
	 */
	private ?string $cur_prev;

	/**
	 * @var ?string Store for the next page cursor
	 */
	private ?string $cur_next;

	/**
	 * Set the page info from the current pull
	 *
	 * @param array $pi The page info to set
	 */
	public function setPageInfos(array $pi) : void
	{
		$this->cur_prev = $pi['startCursor'] ?? null;
		$this->cur_next = $pi['endCursor'] ?? null;
		$this->has_prev = (bool)($pi['hasPreviousPage'] ?? ($this->cur_prev !== null));
		$this->has_next = (bool)($pi['hasNextPage'] ?? ($this->cur_next !== null));
	}

	/**
	 * @inheritDoc
	 */
	public function hasPrevPage() : bool
	{
		return $this->has_prev;
	}

	/**
	 * @inheritDoc
	 */
	public function hasNextPage() : bool
	{
		return $this->has_next;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrevPageInfo() : ?string
	{
		return $this->cur_prev;
	}

	/**
	 * @inheritDoc
	 */
	public function getNextPageInfo() : ?string
	{
		return $this->cur_next;
	}

}

