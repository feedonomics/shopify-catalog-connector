<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\inventories\Inventories;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify inventories.
 */
class BulkInventories extends BulkBase
{
	const MAX_INVENTORY_LINE_LENGTH = 250_000;

	/**
	 * @inheritDoc
	 */
	public function get_query() : string
	{
		// Use the appropriate filter method based on whether GraphQL mode is enabled
		$variant_search_str = $this->get_variant_filters_for_graphql();

		$levels = !$this->session->settings->include_inventory_level ? '' : <<<GQL
							inventoryLevels {
								edges {
									node {
										id
										quantities(names: ["available"]){
											name
											quantity
										}
										location {
											id
											name
											fulfillmentService {
												handle
												inventoryManagement
												serviceName
												type
											}
										}
									}
								}
							}
			GQL;

		return <<<GQL
			productVariants{$variant_search_str} {
				edges {
					node {
						id
						product {
							id
						}
						inventoryItem {
							id
							measurement {
								weight {
									unit
									value
								}
							}
							requiresShipping
							sku
							tracked
							unitCost {
								amount
								currencyCode
							}
							{$levels}
						}
					}
				}
			}
			GQL;
	}

	/**
	 * Build GraphQL filter string appropriate for the productVariants endpoint.
	 *
	 * When GraphQL mode is enabled (gql_product_filters), returns no filters.
	 * This is intentional because:
	 * 1. Product-level filtering is already handled by the products query
	 * 2. The productVariants endpoint filters on variant-level attributes, not product-level
	 * 3. Applying translated filters could cause mismatches between products and their variants
	 *
	 * When in legacy mode, uses the product_filters->get_filters_gql() method directly
	 * to maintain backwards compatibility.
	 *
	 * @link https://shopify.dev/docs/api/admin-graphql/latest/queries/productVariants
	 * @return string The filter string for productVariants query
	 */
	private function get_variant_filters_for_graphql() : string
	{
		// GraphQL mode: no filters on productVariants
		// Product-level filtering is handled by the products query
		if ($this->session->settings->use_gql_product_filters) {
			return '';
		}

		// Legacy mode: use the product_filters directly for backwards compatibility
		return $this->session->settings->product_filters->get_filters_gql();
	}

	/**
	 * @inheritDoc
	 */
	public function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void {
		$fh = $this->checked_open_file($filename);

		try {
			$last_variant_data = null;
			$last_inv_item_id = null;
			$levels_accumulator = [];

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh, self::MAX_INVENTORY_LINE_LENGTH);
				if ($line === null) {
					break;
				}

				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
				if (empty($decoded['id'])) {
					continue;
				}

				$gid = new GID($decoded['id']);

				if ($gid->is_variant()) {
					if ($last_variant_data !== null) {
						$inventory_item = [
							'id' => $last_inv_item_id,
							'sku' => $last_variant_data['inventoryItem']['sku'],
							'cost' => $last_variant_data['inventoryItem']['unitCost']['amount'] ?? null,
							'currency' => $last_variant_data['inventoryItem']['unitCost']['currencyCode'] ?? null,
							'tracked' => $last_variant_data['inventoryItem']['tracked'],
						];

						$last_variant_id = new GID($last_variant_data['id']);
						$last_parent_id = new GID($last_variant_data['product']['id']);
						$insert_variant->add_value_set($cxn, [
							Inventories::COLUMN_ID => $last_variant_id->get_id(),
							Inventories::COLUMN_PARENT_ID => $last_parent_id->get_id(),
							Inventories::COLUMN_DATA => json_encode([
								'item' => $inventory_item,
								'levels' => $levels_accumulator,
							])
						]);
					}

					// Advance last-trackers to this new variant and clear levels accumulator
					$last_variant_data = $decoded;
					$last_inv_item_id = (new GID($decoded['inventoryItem']['id']))->get_id();
					$levels_accumulator = [];
				} elseif ($gid->is_inventory_level()) {
					$loc_id = $decoded['location']['id'] ?? null;
					$loc_id = $loc_id === null ? null : (new GID($loc_id))->get_id();

					$quantity = null;
					foreach ($decoded['quantities'] ?? [] as $q) {
						if (strtolower($q['name']) === 'available') {
							$quantity = $q['quantity'] ?? 0;
							break;
						}
					}

					$levels_accumulator[] = [
						'inventory_item_id' => $last_inv_item_id,
						'location_id' => $loc_id,
						'available' => $quantity,
						'location_name' => $decoded['location']['name'] ?? '',
						'fulfillment_service' => $decoded['location']['fulfillmentService'] ?? '',
					];
				} else {
					# Not a type we were expecting.
					# I guess just silently skip...
				}
			}

			// Store the remaining data after reaching the end of the file
			if ($last_variant_data !== null && !empty($gid)) {
				$inventory_item = [
					'id' => (int)$last_inv_item_id,
					'sku' => $last_variant_data['inventoryItem']['sku'],
					'cost' => $last_variant_data['inventoryItem']['unitCost']['amount'] ?? null,
					'currency' => $last_variant_data['inventoryItem']['unitCost']['currencyCode'] ?? null,
					'tracked' => $last_variant_data['inventoryItem']['tracked'],
				];

				$last_variant_id = new GID($last_variant_data['id']);
				$last_parent_id = new GID($last_variant_data['product']['id']);
				$insert_variant->add_value_set($cxn, [
					Inventories::COLUMN_ID => $last_variant_id->get_id(),
					Inventories::COLUMN_PARENT_ID => $last_parent_id->get_id(),
					Inventories::COLUMN_DATA => json_encode([
						'item' => $inventory_item,
						'levels' => $levels_accumulator,
					])
				]);
			}

			// Commit anything remaining in the batched inserters
			$insert_variant->run_query($cxn);
		} finally {
			fclose($fh);
		}
	}
}