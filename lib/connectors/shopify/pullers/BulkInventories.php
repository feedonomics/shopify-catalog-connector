<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\ProductFilterManager;
use ShopifyConnector\connectors\shopify\inventories\Inventories;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Inventory;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify metafields.
 */
class BulkInventories extends BulkBase
{

	const MAX_INVENTORY_LINE_LENGTH = 250_000;

	/**
	 * @inheritDoc
	 */
	public function get_query(array $prod_query_terms = [], array $prod_search_terms = []) : string
	{
		$product_filters = $this->session->settings->product_filters;
		$meta_filters = $this->session->settings->meta_filters;

		$prod_search_str = $product_filters->get_filters_gql($prod_query_terms, $prod_search_terms);
		$meta_search_str = $meta_filters->get_filters_gql();

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
										}
									}
								}
							}
			GQL;

		return <<<GQL
			productVariants (query: "published_status:published") {
				edges {
					node {
						id
						product {
							id
						}
						inventoryItem {
							id
							sku
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
	 * @inheritDoc
	 */
	public function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		BatchedDataInserter $_, // Unused, but required
		BatchedDataInserter $insert_variant
	) : void
	{

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
				if (isset($decoded['inventoryItem']['id'])) {
				}

				if ($gid->is_variant()) {
					if ($last_variant_data !== null) {
						$inventory_item = [
							'id' => $last_inv_item_id,
							'sku' => $last_variant_data['inventoryItem']['sku'] ?? '',
							'cost' => $last_variant_data['inventoryItem']['unitCost']['amount'] ?? 'null',
							'currency' => $last_variant_data['inventoryItem']['unitCost']['currencyCode'] ?? 'null',
						];

						$parent_id = new GID($last_variant_data['product']['id']);
						$insert_variant->add_value_set($cxn, [
							Inventories::COLUMN_ID => $gid->get_id(),
							Inventories::COLUMN_PARENT_ID => $parent_id->get_id(),
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
					foreach($decoded['quantities'] ?? [] as $q){
						if(strtolower($q['name']) === 'available'){
							$quantity = $q['quantity'] ?? 0;
							break;
						}
					}

					$levels_accumulator[] = [
						'inventory_item_id' => $last_inv_item_id,
						'location_id' => $loc_id,
						'available' => $quantity,
						'location_name' => $decoded['location']['name'] ?? '',
					];

				} else {
					# Not a type we were expecting.
					# I guess just silently skip...
				}
			}

			// Commit anything remaining in the batched inserters
			$insert_variant->run_query($cxn);

		} finally {
			fclose($fh);
		}

	}
}

