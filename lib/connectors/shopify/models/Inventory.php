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
			'fulfillment_service' => $this->get_fulfillment_service(),
			'inventory_management' => $this->get_inventory_management(),
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
		$fulfillment_service = $this->get_fulfillment_service();
		$inventory_management = $this->get_inventory_management();
		$rows = [];
		foreach ($this->get_formatted_inventory_levels() as $level) {
			$rows[] = [
				'fulfillment_service' => $fulfillment_service,
				'inventory_management' => $inventory_management,
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
			'id' => (int)$item['id'],
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
				'inventory_item_id' => (int)$location_level['inventory_item_id'],
				'location_id' => (int)$location_level['location_id'],
				'available' => $location_level['available'],
				'location_name' => $location_level['location_name'],
				'fulfillment_service' => $location_level['fulfillment_service'],
			];
		}

		return array_reverse($formatted_levels);
	}

	/**
	 * The handle of a fulfillment service that stocks a product variant.
	 * This is the handle of a third-party fulfillment service if the following conditions are met:
	 *
	 * - The product variant is stocked by a single fulfillment service.
	 * - The FulfillmentService is a third-party fulfillment service.
	 *   Third-party fulfillment services don't have a handle with the value manual.
	 * - The fulfillment service hasn't opted into SKU sharing.
	 *
	 * If the conditions aren't met, then this is 'manual'.
	 *
	 * This should eventually be phased out and not leveraged by Shopify clients.
	 *
	 * @return string
	 */
	private function get_fulfillment_service(): string
	{
		$levels = $this->get('levels');
		if (
			count($levels) === 1 &&
			$levels[0]['fulfillment_service'] !== "" &&
			$levels[0]['fulfillment_service']['handle'] === 'THIRD_PARTY' &&
			$levels[0]['fulfillment_service']['permitsSkuSharing'] === false
		) {
				return $levels[0]['fulfillment_service']['handle'];
			} else {
				return 'manual';
			}
	}

	/**
	 * The fulfillment service that tracks the number of items in stock for the product variant. Valid values:
	 *
	 * - shopify: You are tracking inventory yourself using the admin.
	 * - null: You aren't tracking inventory on the variant.
	 * - the handle of a fulfillment service that has inventory management enabled.
	 *   This must be the same fulfillment service referenced by the fulfillment_service property.
	 *
	 * This should eventually be phased out and not leveraged by Shopify clients.
	 *
	 * @return string
	 */
	private function get_inventory_management(): string
	{
		$item = $this->get('item');
		// Check if inventory tracking is enabled.
		// Assuming the 'tracked' field is available in the data; if not tracked, return an empty string.
		if (!$item['tracked']) {
			return "";
		}

		// Retrieve the fulfillment service as determined by get_fulfillment_service.
		$fulfillment = $this->get_fulfillment_service();

		// If the fulfillment service is 'manual', that indicates inventory is tracked directly via Shopify.
		// In that case, return 'shopify' as per the valid values.
		if ($fulfillment === 'manual') {
			return "shopify";
		}

		// Otherwise, return the third-party fulfillment service handle.
		return $fulfillment;
	}
}

