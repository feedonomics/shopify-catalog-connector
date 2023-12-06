<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the store info endpoints
 */
final class StoreService extends BaseService {

	/**
	 * Get information about the store
	 *
	 * @return array The store info
	 * @throws ApiException On API errors
	 */
	public function getStoreInfo() : array {
		$url = sprintf('admin/api/%s/shop.json', $this->client::REST_VERSION);
		return $this->client->request('GET', $url);
	}

}
