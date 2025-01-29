<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\collections\Collections;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Collection;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify collections
 */
class BulkCollections extends BulkBase
{

	const MAX_COLLECTION_LINE_LENGTH = 250_000;

	/**
	 *
	 * @inheritDoc
	 */
	public function get_query(array $prod_query_terms = [], array $prod_search_terms = []) : string
	{

		$product_filters = $this->session->settings->product_filters;
		$meta_filters = $this->session->settings->meta_filters;

		$prod_search_str = $product_filters->get_filters_gql($prod_query_terms, $prod_search_terms);
		$meta_search_str = $meta_filters->get_filters_gql();

		$meta = !$this->session->settings->include_collections_meta ? '' : <<<GQL
			metafields{$meta_search_str} {
				edges {
					node {
						id
						key
						value
						namespace
						description
					}
				}
			}
			GQL;

		return <<<GQL
					collections {
						edges {
							node {
								id
								handle
								title
								ruleSet {
									appliedDisjunctively
								}
								{$meta}
								products {
									edges {
										node {
											id
										}
									}
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
		//copy($filename, '/var/www/feedonomics-import-scripts/tmp/collect_bulk_copy'); # TODO: Just for debug/dev
		$fh = $this->checked_open_file($filename);

		try {
			$product_id = null;
			$variant_id = null;
			$last_pid_data_added_for = null;
			$last_vid_data_added_for = null;
			$decoded = null;
			$products = [];
			$collections = [];
			$collection_ids = [];
			$metafields = [];

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh);
				if ($line === null) {
					break;
				}

				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

				if (empty($decoded['id'])) {
					continue;
				}
				$gid = new GID($decoded['id']);
				if ($gid->is_product()) {
					$product_id = $gid->get_id();
					$collection_id = new GID($decoded['__parentId']);
					$collection_id = $collection_id->get_id();
					$decoded['id'] = $collection_id;
					$collection_ids[$product_id][] = $collection_id;
				} elseif ($gid->is_collection()) {
					$collection_id = $gid->get_id();
					$decoded['id'] = $collection_id;
					foreach($decoded as $key=>$value) {
						$collections[$collection_id][$key] = $value;
					}
				} elseif ($gid->is_metafield()) {
					$collection_id = new GID($decoded['__parentId']);
					$collection_id = $collection_id->get_id();
					unset($decoded['id']);
					unset($decoded['__parentId']);
					$metafields[$collection_id] = $decoded;
				}
			}
			// rows are
			foreach ($collection_ids as $product_id=>$array) { // Run through Products
				foreach($array as $id) { // Run through Collections linked to single Product
					if (is_null($collections[$id]['ruleSet'])) { // Custom
						$output_collections[$product_id]['custom_collections_handle'][$collections[$id]['handle']] = true;
						$output_collections[$product_id]['custom_collections_title'][$collections[$id]['title']] = true;
						$output_collections[$product_id]['custom_collections_id'][$collections[$id]['id']] = true;
						$output_collections[$product_id]['custom_collections_meta'][$id] = [$metafields[$id] ?? ''];
					} else { // Smart
						$output_collections[$product_id]['smart_collections_handle'][$collections[$id]['handle']] = true;
						$output_collections[$product_id]['smart_collections_title'][$collections[$id]['title']] = true;
						$output_collections[$product_id]['smart_collections_id'][$collections[$id]['id']] = true;
						$output_collections[$product_id]['smart_collections_meta'][$id] = [$metafields[$id] ?? ''];
					}
				}
				$insert_product->add_value_set($cxn, [
					Collections::COLUMN_ID => $product_id,
					Collections::COLUMN_DATA => json_encode($output_collections[$product_id]),
				]);
			}
			// Commit anything remaining in the batched inserters
			$insert_product->run_query($cxn);

		} finally {
			fclose($fh);
		}
	}
}

