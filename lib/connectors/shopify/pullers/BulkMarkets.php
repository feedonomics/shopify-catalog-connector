<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\models\Field;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\publications\Markets;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

class BulkMarkets extends BulkBase
{
	public function get_query(array $prod_query_terms = [], array $prod_search_terms = []) : string
	{
		$prod_search_str = $this->session->settings->product_filters->get_filters_gql($prod_query_terms, $prod_search_terms);

		return <<<GQL
			products{$prod_search_str} {
				edges {
					node {
						id
						resourcePublicationsV2(catalogType: MARKET) {
							edges {
								node {
									publication {
										catalog {
											id
											title
										}
									}
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
		string               $filename,
		BulkProcessingResult $result,
		MysqliWrapper        $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void
	{
		if ($insert_product === null) {
			throw new InfrastructureErrorException('Bulk markets cannot be processed without a product inserter');
		}

		$fh = $this->checked_open_file($filename);

		try {
			$product_data = null;
			$markets = [];

			$insert_product_fn = static fn(array $data) => $insert_product->add_value_set($cxn, [
				Markets::COLUMN_ID => $data['id'],
				Markets::COLUMN_DATA => json_encode($data),
			]);

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh);
				if ($line === null) {
					break;
				}

				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

				$gid = $this->find_gid($decoded);

				if ($gid->is_product()) {
					if ($product_data !== null) {
						$insert_product_fn($product_data);
					}

					$product_data = $decoded;
					$product_data['id'] = $gid->get_id();
				} elseif ($gid->is_market_catalog()) {
					$product_data[Field::MARKETS->value][] = $decoded['publication']['catalog'];
					$markets[$decoded['publication']['catalog']['title']] = $decoded['publication']['catalog']['title'];
				}
			}

			if ($product_data !== null) {
				$insert_product_fn($product_data);
			}

			// Commit anything remaining in the batched inserter
			$insert_product->run_query($cxn);
		} finally {
			fclose($fh);
		}

		$result->result = array_filter(array_values($markets));
	}

	/**
	 * @throws ApiResponseException
	 */
	private function find_gid(array $data) : GID
	{
		if (!empty($data['id'])) {
			return new GID($data['id']);
		} elseif (!empty($data['publication']['catalog']['id'])) {
			return new GID($data['publication']['catalog']['id']);
		}

		throw new ApiResponseException('No gid found in data');
	}
}