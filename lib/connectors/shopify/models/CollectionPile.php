<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;

use JsonSerializable;

/**
 * Model for a Shopify collection
 */
final class CollectionPile
{

	private array $collections_by_id;

	/**
	 * Set up a new collection pile with the given data for manipulating.
	 * Data is expected to be given as an array of collections indexed by the collection
	 * id and each entry containing the relevant smart_/custom_ fields.
	 *
	 * @param array $collections_by_id The collections data, keyed by id
	 */
	public function __construct(array $collections_by_id)
	{
		$this->collections_by_id = $collections_by_id;
	}

	/**
	 * Get all of the values from a column in the collections collection concatenated
	 * together with a "|" separator.
	 *
	 * @param string $column_name The name of the column to join
	 * @return string All values from the given column, separated by '|'
	 */
	public function get_joined_column(string $column_name) : string
	{
		return implode('|', array_column($this->collections_by_id, $column_name));
	}

	public function get_formatted_custom_metadatas() : array
	{
		return $this->get_formatted_metadatas('custom_collections_meta');
	}

	public function get_formatted_smart_metadatas() : array
	{
		return $this->get_formatted_metadatas('smart_collections_meta');
	}

	private function get_formatted_metadatas(string $meta_column_name) : array
	{
		$output = [];
		foreach ($this->collections_by_id as $c_id => $data) {
			// Skip if key missing, this is likely a smart when we want custom or vice versa
			if (!isset($data[$meta_column_name])) {
				continue;
			}

			// Include at least an empty array, even when no data
			$entry = [];

			foreach ($data[$meta_column_name] as $meta) {
				$entry[] = [
					'key' => $meta['key'] ?? '',
					'value' => $meta['value'] ?? '',
					'namespace' => $meta['namespace'] ?? '',
					'description' => $meta['description'] ?? '',
				];
			}

			$output[$c_id] = array_reverse($entry);
		}

		return $output;
	}

	/**
	 * Add all the collections-related data to the given product for smart and custom collections.
	 * Can be directed to add collections meta data as well.
	 *
	 * @param Product $product The product to add collections data to
	 * @param bool $include_meta TRUE to also add collections meta data to the product
	 */
	public function add_collection_data_to_product(Product $product, bool $include_meta) : void
	{
		$product->add_data([
		   'custom_collections_handle' => $this->get_joined_column('custom_collections_handle'),
		   'custom_collections_title' => $this->get_joined_column('custom_collections_title'),
		   'custom_collections_id' => $this->get_joined_column('custom_collections_id'),
		   'smart_collections_handle' => $this->get_joined_column('smart_collections_handle'),
		   'smart_collections_title' => $this->get_joined_column('smart_collections_title'),
		   'smart_collections_id' => $this->get_joined_column('smart_collections_id'),
		]);

		if ($include_meta) {
			$product->add_datum(
				'custom_collections_meta',
				json_encode($this->get_formatted_custom_metadatas())
			);
			$product->add_datum(
				'smart_collections_meta',
				json_encode($this->get_formatted_smart_metadatas())
			);
		}

	}

}

