<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\metafields\Metafields;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Metafield;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify metafields.
 */
class BulkInventories extends BulkBase
{

	const MAX_INVENTORY_LINE_LENGTH = 250_000;

	const MAX_METAFIELD_LINE_LENGTH = 250000;

	/**
	 * @inheritDoc
	 */
	public function get_query(array $prod_query_terms = [], array $prod_search_terms = []) : string
	{
		$product_filters = $this->session->settings->product_filters;
		$meta_filters = $this->session->settings->meta_filters;

		$prod_search_str = $product_filters->get_filters_gql($prod_query_terms, $prod_search_terms);
		$meta_search_str = $meta_filters->get_filters_gql();

		$levels =  <<<GQL
							inventoryLevels {
								edges {
									node {
										id
										available
										location {
											id
											name
										}
									}
								}
							}
			GQL;

		return <<<GQL
			productVariants (query: "published_status:{$this->session->settings->raw_client_options['product_published_status']}") {
				edges {
					node {
						id
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
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : void
	{
		$mf_split = $this->session->settings->metafields_split_columns;
		$mf_names = [];
		$fh = $this->checked_open_file($filename);

		try {
			$product_id = null;
			$variant_id = null;
			$last_pid_data_added_for = null;
			$last_vid_data_added_for = null;
			$decoded = null;

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh, self::MAX_METAFIELD_LINE_LENGTH);
				if ($line === null) {
					break;
				}

				$previous_decoded = $decoded;
				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

				if (empty($decoded['id'])) {
					continue;
				}
				$gid = new GID($decoded['id']);

				if ($gid->is_product()) {
					// Ensure at least one row exists in db for previous product before moving on to next product
					if ($product_id !== null && $last_pid_data_added_for !== $product_id) {
						$insert_product->add_value_set($cxn, [
							Metafields::COLUMN_ID => $product_id->get_id(),
							Metafields::COLUMN_DATA => '',
						]);
					}

					// Ensure at least one row exists in db for previous variant before moving on to next product
					if ($variant_id !== null && $last_vid_data_added_for !== $variant_id) {
						$previous_pid = new GID($previous_decoded['__parentId']);
						$insert_variant->add_value_set($cxn, [
							Metafields::COLUMN_ID => $variant_id->get_id(),
							Metafields::COLUMN_PARENT_ID => $previous_pid->get_id(),
							Metafields::COLUMN_DATA => '',
						]);
					}

					$variant_id = null;
					$product_id = $gid;

				} elseif ($gid->is_variant()) {
					if ($product_id === null) {
						// Encountered a variant before a product. This really shouldn't
						// happen, so would indicate something pretty weird is going on
						$this->generic_exception(
							'Unexpected format in bulk metafields response (v); declining to continue',
							'processing'
						);
					}

					// Ensure at least one row exists in db for previous variant before moving on to next variant
					if ($variant_id !== null && $last_vid_data_added_for !== $variant_id) {
						$previous_pid = new GID($previous_decoded['__parentId']);
						$insert_variant->add_value_set($cxn, [
							Metafields::COLUMN_ID => $variant_id->get_id(),
							Metafields::COLUMN_PARENT_ID => $previous_pid->get_id(),
							Metafields::COLUMN_DATA => '',
						]);
					}

					$variant_id = $gid;

				} elseif ($gid->is_metafield()) {
					if ($product_id === null) {
						// Encountered a metafield before a product. This really shouldn't
						// happen, so would indicate something pretty weird is going on
						$this->generic_exception(
							'Unexpected format in bulk metafields response (m); declining to continue',
							'processing'
						);
					}

					if ($variant_id !== null) {
						// The current line is a metafield for a variant
						$mf = new Metafield($decoded, Metafield::TYPE_VARIANT);

						$insert_variant->add_value_set($cxn, [
							Metafields::COLUMN_ID => $variant_id->get_id(),
							Metafields::COLUMN_PARENT_ID => $product_id->get_id(),
							Metafields::COLUMN_DATA => json_encode($mf),
						]);
						$last_vid_data_added_for = $variant_id;

					} else {
						// The current line is a metafield for a product
						$mf = new Metafield($decoded, Metafield::TYPE_PRODUCT);

						$insert_product->add_value_set($cxn, [
							Metafields::COLUMN_ID => $product_id->get_id(),
							Metafields::COLUMN_DATA => json_encode($mf),
						]);
						$last_pid_data_added_for = $product_id;
					}

					if ($mf_split) {
						$mf_names[$mf->get_identifier()] = true;
					}

				} else {
					# Not a type we were expecting.
					# I guess just silently skip...
				}
			}

			// Commit anything remaining in the batched inserters
			$insert_product->run_query($cxn);
			$insert_variant->run_query($cxn);

		} finally {
			fclose($fh);
		}

		$result->result = array_values(array_unique(array_merge(
			$result->result,
			array_keys($mf_names)
		)));
	}

}

