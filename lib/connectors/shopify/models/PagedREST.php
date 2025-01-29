<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\interfaces\iPagedResponse;

/**
 * Base class for Shopify response/data models. This class provides handling
 * for higher-level concerns that may be present in response data such as
 * pagination.
 * REST Edition
 */
abstract class PagedREST implements iPagedResponse
{

	/**
	 * @var ?string Store for the previous page cursor
	 */
	private ?string $pinfo_prev = null;

	/**
	 * @var ?string Store for the next page cursor
	 */
	private ?string $pinfo_next = null;

	/**
	 * Set the page info from the current pull
	 *
	 * @param array $pi The page info to set
	 */
	public function setPageInfos(array $pi) : void
	{
		$this->pinfo_prev = $pi['previous'] ?? null;
		$this->pinfo_next = $pi['next'] ?? null;
	}

	/**
	 * @inheritDoc
	 */
	public function hasPrevPage() : bool
	{
		return $this->pinfo_prev !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function hasNextPage() : bool
	{
		return $this->pinfo_next !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrevPageInfo() : ?string
	{
		return $this->pinfo_prev;
	}

	/**
	 * @inheritDoc
	 */
	public function getNextPageInfo() : ?string
	{
		return $this->pinfo_next;
	}

}

