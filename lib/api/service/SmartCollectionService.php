<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the smart collections endpoints
 */
final class SmartCollectionService extends BaseService {

	/**
	 * List the smart collections
	 *
	 * @param array $params Query params to include
	 * @return array The smart collections data
	 * @throws ApiException On API errors
	 */
	public function listSmartCollections(array $params) : array {
		return $this->client->request('GET', 'admin/api/2019-10/smart_collections.json', $params);
	}

	/**
	 * Get information about a specific smart collection
	 *
	 * @param string $id The collection ID
	 * @return array The collection data
	 * @throws ApiException On API errors
	 */
	public function getSmartCollection(string $id) : array {
		return $this->client->request('GET', "admin/api/2019-10/smart_collections/{$id}.json");
	}

}
