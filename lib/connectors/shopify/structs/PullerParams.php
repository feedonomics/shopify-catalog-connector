<?php

namespace ShopifyConnector\connectors\shopify\structs;

/**
 * Container for parameters and other info used by pullers/services. The main
 * purpose of this class is to make it possible to have an abstract base between
 * REST and GraphQL logics and for ShopifyPuller to be generalized.
 *
 * TODO: What's the difference between this and PullParams
 * TODO: Can these two be merged?
 */
class PullerParams
{

	/**
	 * @var array The filter params to use on the API call
	 */
	public array $params;

	/**
	 * @var string|null The next page's info or null if not available
	 */
	public ?string $nextPageInfo;

	/**
	 * Container for parameters and other info used by pullers/services
	 *
	 * @param array $params Filter params to use on API calls
	 * @param string|null $nextPageInfo Next page info (if available)
	 */
	public function __construct(
		array $params = [],
		?string $nextPageInfo = null
	)
	{
		$this->params = $params;
		$this->nextPageInfo = $nextPageInfo;
	}

}

