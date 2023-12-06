<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the meta-fields endpoints
 */
final class MetaFieldService extends BaseService {

	/**
	 * List information about available meta-fields
	 *
	 * @return array The meta-fields data
	 * @throws ApiException On API errors
	 */
	public function listAllMetaFields() : array {
		$url = sprintf('admin/api/%s/metafields.json', $this->client::REST_VERSION);
		return $this->client->request('GET', $url);
	}

	/**
	 * List information about a specific product's meta-fields
	 *
	 * @param string $id The product ID
	 * @param array $params Option query params to include
	 * @return array The product's meta-field data
	 * @throws ApiException On API errors
	 */
	public function listMetaFields(string $id, array $params = []) : array {
		return $this->client->request('GET', "admin/products/{$id}/metafields.json", $params);
	}

	/**
	 * List information about a specific product variant's meta-fields
	 *
	 * @param string $id The product ID
	 * @param string $variantId The variant ID
	 * @return array The variant's meta-field data
	 * @throws ApiException On API errors
	 */
	public function listVariantMetaFields(string $id, string $variantId) : array {
		return $this->client->request(
			'GET',
			"admin/products/{$id}/variants/{$variantId}/metafields.json"
		);
	}

	/**
	 * Get the meta-field data for a smart-collection
	 *
	 * @param string $id The smart-collection ID
	 * @return array The smart-collection's meta-field data
	 * @throws ApiException On API errors
	 */
	public function getSmartCollectionMetaField(string $id) : array {
		$url = sprintf('admin/api/%s/smart_collections/%s/metafields.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url);
	}

	/**
	 * Get the meta-field data for a custom-collection
	 *
	 * @param string $id The custom-collection ID
	 * @return array The custom-collection's meta-field data
	 * @throws ApiException On API errors
	 */
	public function getCustomCollectionMetaField(string $id) : array {
		$url = sprintf('admin/api/%s/custom_collections/%s/metafields.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url);
	}

}
