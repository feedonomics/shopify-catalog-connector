<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for calling the custom collection endpoints
 */
final class CustomCollectionService extends BaseService {

	/**
	 * List custom collections
	 *
	 * @param array $params Query params to include
	 * @return array The collections data
	 * @throws ApiException On API errors
	 */
	public function listCustomCollections(array $params) : array {
		return $this->client->request('GET', 'admin/api/2019-10/custom_collections.json', $params);
	}

	/**
	 * Get info about a specific custom collection
	 *
	 * @param string $id The collection ID
	 * @return array The collection's data
	 * @throws ApiException On API errors
	 */
	public function getCustomCollection(string $id) : array {
		return $this->client->request('GET', "admin/api/2019-10/custom_collections/{$id}.json");
	}

}
