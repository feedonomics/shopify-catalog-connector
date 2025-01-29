<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;

use JsonSerializable;

/**
 * Model for a Shopify inventory
 */
final class Inventory extends FieldHaver
{

	/**
	 * Set up a new Metafield object with the given data. The "__parentId"
	 * key will be removed from the data, if present.
	 *
	 * @param array $fields The data for the Metafield
	 */
	public function __construct(array $fields)
	{
		parent::__construct($fields);
	}

	/**
	 * @return Array<Array<string, mixed>>
	 */
	public function get_data_for_rows() : array
	{
		$split_rows = SessionContainer::get_active_setting('inventory_level_explode', false);
		return $split_rows ? $this->get_data_as_multiple_rows() : [$this->get_output_data()];
	}

	/**
	 * Gets inventory data as a single row. To account for row-splitting, {@see get_data_for_rows()}
	 * should be how data is retrieved from these models.
	 *
	 * @inheritDoc
	 */
	public function get_output_data(?array $field_list = null) : array
	{
		return [
			'inventory_item' => $this->get_formatted_inventory_item(),
			'inventory_level' => json_encode($this->get_formatted_inventory_levels()),
		];
	}

	/**
	 * Returns the data in an array with multiple entries, each representing the
	 * data for a row in the output
	 *
	 * @return Array<Array<string, mixed>>
	 */
	private function get_data_as_multiple_rows() : array
	{
		$item = $this->get_formatted_inventory_item();
		$rows = [];
		foreach ($this->get_formatted_inventory_levels() as $level) {
			$rows[] = [
				'inventory_item' => $item,
				'inventory_level' => json_encode($level),
			];
		}

		return $rows;
	}

	private function get_formatted_inventory_item() : string
	{
		$item = $this->get('item');
		if (empty($item)) {
			return '';
		}

		return json_encode([
			'id' => $item['id'],
			'sku' => $item['sku'],
			'cost' => $item['cost'],
			'currency' => $item['currency'],
		]);
	}

	/**
	 * @return Array<Array<string, mixed>>
	 */
	private function get_formatted_inventory_levels() : array
	{
		$levels = $this->get('levels');
		if (empty($levels)) {
			return [[]];
		}

		$formatted_levels = [];
		foreach ($levels as $location_level) {
			$formatted_levels[] = [
				'inventory_item_id' => $location_level['inventory_item_id'],
				'location_id' => $location_level['location_id'],
				'available' => $location_level['available'],
				'location_name' => $location_level['location_name'],
			];
		}

		return $formatted_levels;
	}

}
