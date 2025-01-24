<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\ProductPile;
use ShopifyConnector\connectors\shopify\structs\PullerParams;
use ShopifyConnector\api\service\ProductService as clProductService;

/**
 * Service for making product related calls
 */
final class ProductService
{

	/**
	 * Get the product count via the REST API for the given date ranges
	 *
	 * @param SessionContainer $session The session container
	 * @param string $dateStart Start of product creation date for filtering
	 * @param string $dateEnd End of product creation dates for filtering
	 * @param string $publishStatus Filter for the published status
	 * @return int The product count
	 */
	public static function getCountForRangeREST(
		SessionContainer $session,
		string $dateStart,
		string $dateEnd,
		string $publishStatus = 'published'
	) : int
	{
		$ps = new clProductService($session->client);
		$count = $ps->getProductCount([
			'created_at_min' => $dateStart,
			'created_at_max' => $dateEnd,
			'published_status' => $publishStatus,
		])['count'];
		$session->set_last_call_limit();
		return (int)$count;
	}

	/**
	 * Get a page of products via the REST API
	 *
	 * @param SessionContainer $session The session container
	 * @param PullerParams $params Params to filter the API call
	 * @return ProductPile The product results
	 */
	public static function getProducts(
		SessionContainer $session,
		PullerParams $params
	) : ProductPile
	{
		$headers = $session->settings->get('include_presentment_prices')
			? ['X-Shopify-Api-Features' => 'include-presentment-prices']
			: [];

		$ps = new clProductService($session->client);
		$list = $ps->listProducts($params->params, $headers);
		$session->set_last_call_limit();

		return new ProductPile(
			$list,
			$session->client->parseLastPaginationLinkHeader()
		);
	}

}

