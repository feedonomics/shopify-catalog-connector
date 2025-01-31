<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\collections\Collections;
use ShopifyConnector\connectors\shopify\models\GID;
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

		// Leaving $prod_search_str off of "products" here for partiy (for now)
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
		$fh = $this->checked_open_file($filename);

		try {
			$collections = [];
			$metafields = [];
			$product_collections = [];

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

				if ($gid->is_collection()) {
					$collection_id = $gid->get_id();
					$collections[$collection_id] = $decoded;

				} elseif ($gid->is_metafield()) {
					$collection_id = (new GID($decoded['__parentId']))->get_id();
					unset($decoded['id']);
					unset($decoded['__parentId']);
					$metafields[$collection_id][] = $decoded;

				} elseif ($gid->is_product()) {
					$product_id = $gid->get_id();
					$collection_id = (new GID($decoded['__parentId']))->get_id();
					$product_collections[$product_id][] = $collection_id;
				}
			}

			// rows are
			foreach ($product_collections as $product_id => $collection_ids) { // Run through Products
				$output = [];

				foreach($collection_ids as $c_id) { // Run through Collections linked to single Product
					$collection = $collections[$c_id];
					$collection_id = '';
					try {
						$collection_id = (new GID($collection['id']))->get_id();
					} catch(\Throwable $e) { /* Fuhgeddaboudit */ }

					// Determine if "custom" or "smart" collection based on the presence of a ruleSet
					if ($collection['ruleSet'] === null) {
						$output[$c_id] = [
							'custom_collections_handle' => $collection['handle'],
							'custom_collections_title' => $collection['title'],
							'custom_collections_id' => $collection_id,
							'custom_collections_meta' => $metafields[$c_id] ?? [],
						];
					} else {
						$output[$c_id] = [
							'smart_collections_handle' => $collection['handle'],
							'smart_collections_title' => $collection['title'],
							'smart_collections_id' => $collection_id,
							'smart_collections_meta' => $metafields[$c_id] ?? [],
						];
					}
				}

				$insert_product->add_value_set($cxn, [
					Collections::COLUMN_ID => $product_id,
					Collections::COLUMN_DATA => json_encode($output),
				]);
			}

			// Commit anything remaining in the batched inserters
			$insert_product->run_query($cxn);

		} finally {
			fclose($fh);
		}
	}
}

