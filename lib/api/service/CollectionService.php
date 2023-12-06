<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\api\BaseService;
use ShopifyConnector\exceptions\ApiException;

/**
 * Service for interacting with the collection endpoints
 */
final class CollectionService extends BaseService {

	/**
	 * Get a specific collection
	 *
	 * @param string $id The collection ID
	 * @return array The collection's data
	 * @throws ApiException On API errors
	 */
	public function getCollection(string $id) : array {
		return $this->client->request('GET', "admin/api/2020-07/collects/{$id}.json");
	}

	/**
	 * Get a list of collections
	 *
	 * @param array $params Optional query params to pass through
	 * @return array The list of collections
	 * @throws ApiException On API errors
	 */
	public function listCollections(array $params) : array {
		return $this->client->request('GET', 'admin/api/2020-07/collects.json', $params);
	}

}
