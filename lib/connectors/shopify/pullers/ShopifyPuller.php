<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\PullParams;
use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use ShopifyConnector\connectors\shopify\interfaces\iDataPuller;
use ShopifyConnector\connectors\shopify\interfaces\iPagedResponse;
use ShopifyConnector\connectors\shopify\models\EmptyDataList;
use ShopifyConnector\connectors\shopify\structs\PullerParams;

/**
 * Abstract class for pullers responsible for retrieving and storing data from
 * Shopify
 */
abstract class ShopifyPuller implements iDataPuller
{

	/**
	 * @var Callable The Callable used to pull data
	 */
	private $pullFunc;

	/**
	 * @var SessionContainer Store for the session container
	 */
	private SessionContainer $session;

	/**
	 * @var PullParams Store for the pull params
	 */
	private PullParams $pullParams;

	/**
	 * @var string|null Store for the next page info
	 */
	private ?string $nextPageInfo = null;

	/**
	 * @var int Internal tracker for total pages pulled
	 */
	private int $pagesPulled = 0;

	/**
	 * Abstract class for pullers responsible for retrieving and storing data
	 * from Shopify
	 *
	 * @param SessionContainer $session The active session container
	 * @param Callable $pullFunc The Callable to use to pull data using the following signature:
	 *   <code>function (SessionContainer $session, PullerParams $params) : iDataList</code>
	 * @param ?PullParams $pullParams The parameter set to use when pulling data
	 */
	public function __construct(
		SessionContainer $session,
		callable $pullFunc,
		?PullParams $pullParams = null
	)
	{
		$this->session = $session;
		$this->pullFunc = $pullFunc;
		$this->pullParams = $pullParams ?? new PullParams();
	}

	/**
	 * @inheritDoc
	 */
	final public function pullPage() : iDataList
	{
		if (!$this->hasNextPage()) {
			return new EmptyDataList();
		}

		$params = $this->pullParams->getParams();

		# This is REST-specific, but having it here keeps it in one place
		# rather than requiring each REST service to individually do this
		if ($this->nextPageInfo !== null) {
			$params['page_info'] = $this->nextPageInfo;
		}

		$res = ($this->pullFunc)($this->session, new PullerParams(
			$params, $this->nextPageInfo
		));

		$this->nextPageInfo = ($res instanceof iPagedResponse && $res->hasNextPage())
			? $res->getNextPageInfo()
			: null;

		++$this->pagesPulled;
		return $res;
	}

	/**
	 * @inheritDoc
	 */
	final public function hasNextPage() : bool
	{
		return $this->pagesPulled === 0 || $this->nextPageInfo !== null;
	}

}

