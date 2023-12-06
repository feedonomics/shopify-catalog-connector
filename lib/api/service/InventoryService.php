<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the inventory endpoints
 */
final class InventoryService extends BaseService {

	/**
	 * List inventory items
	 *
	 * @param array $params Optional query params to include
	 * @return array The inventory items data
	 * @throws ApiException On API errors
	 */
	public function listInventories(array $params = []) : array {
		$url = sprintf('admin/api/%s/inventory_items.json', $this->client::REST_VERSION);
		return $this->client->request('GET', $url, $params);
	}

	/**
	 * Get info about a specific inventory item
	 *
	 * @param string $id The inventory item ID
	 * @return array The inventory's data
	 * @throws ApiException On API errors
	 */
	public function getInventory(string $id) : array {
		$url = sprintf('admin/api/%s/inventory_items/%s.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url);
	}

	/**
	 * List inventory levels data
	 *
	 * @param array $params Query params to include
	 * @return array The inventory levels data
	 * @throws ApiException On API errors
	 */
	public function getInventoryLevel(array $params) : array {
		$url = sprintf('admin/api/%s/inventory_levels.json', $this->client::REST_VERSION);
		$resp = $this->client->request('GET', $url, $params);

		$links = $this->client->parseLastPaginationLinkHeader();
		$resp['next_page_token'] = $links['next'] ?? '';
		$resp['prev_page_token'] = $links['prev'] ?? '';

		return $resp;
	}

}
