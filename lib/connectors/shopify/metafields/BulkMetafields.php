<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Metafield;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use JsonException;

/**
 * BulkOperation puller for Shopify metafields.
 */
class BulkMetafields extends BulkBase
{

	const MAX_METAFIELD_LINE_LENGTH = 5_250_000;

	/**
	 * @inheritDoc
	 */
	public function get_query() : string
	{
		return (new MetafieldsQuery(
			$this->session->settings->product_filters,
			$this->session->settings->meta_filters,
		))
			->generate_for_bulk_operation();
	}

	/**
	 * @inheritDoc
	 * @throws InfrastructureErrorException
	 * @throws ApiResponseException
	 * @throws JsonException
	 */
	public function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void
	{
		$mf_split = $this->session->settings->metafields_split_columns;
		$mf_names = [];
		$fh = $this->checked_open_file($filename);

		try {
			$product_id = null;
			$last_pid_data_added_for = null;

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh, self::MAX_METAFIELD_LINE_LENGTH);
				if ($line === null) {
					break;
				}

				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

				if (empty($decoded['id'])) {
					// TODO: Error? Log something? Aggregate issues into e.g. PullStats? Different behavior?
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

					$product_id = $gid;

				} elseif ($gid->is_variant()) {
					if (empty($decoded['__parentId'])) {
						throw new ApiResponseException(
							'Unexpected format in bulk metafields response (v); declining to continue',
						);
					}

					// Always emit a placeholder row for the variant carrying the
					// variant_id → product_id mapping from its __parentId. This
					// row is required by resolve_variant_metafield_parents() to
					// patch metafield rows whose parent_id was deferred, and it
					// also ensures add_variants_to_product returns the variant
					// when it has no metafields.
					$variant_parent_gid = new GID($decoded['__parentId']);
					$insert_variant->add_value_set($cxn, [
						Metafields::COLUMN_ID => $gid->get_id(),
						Metafields::COLUMN_PARENT_ID => $variant_parent_gid->get_id(),
						Metafields::COLUMN_DATA => '',
					]);

				} elseif ($gid->is_metafield()) {
					if ($product_id === null || empty($decoded['__parentId'])) {
						// Encountered a metafield before a product, or without a
						// __parentId. This really shouldn't happen, so would
						// indicate something pretty weird is going on
						throw new ApiResponseException(
							'Unexpected format in bulk metafields response (m); declining to continue',
						);
					}

					$parent_gid = new GID($decoded['__parentId']);

					if ($parent_gid->is_variant()) {
						// Variant metafield. Key off the metafield's __parentId
						// (the variant) rather than positional state, and defer
						// the product-id resolution to a post-scan SQL UPDATE —
						// the variant's product isn't known until we see its
						// variant line, which may arrive later in the file.
						$mf = new Metafield($decoded, Metafield::TYPE_VARIANT);

						$insert_variant->add_value_set($cxn, [
							Metafields::COLUMN_ID => $parent_gid->get_id(),
							Metafields::COLUMN_PARENT_ID => 0,
							Metafields::COLUMN_DATA => json_encode($mf),
						]);

					} elseif ($parent_gid->is_product()) {
						$mf = new Metafield($decoded, Metafield::TYPE_PRODUCT);

						$insert_product->add_value_set($cxn, [
							Metafields::COLUMN_ID => $parent_gid->get_id(),
							Metafields::COLUMN_DATA => json_encode($mf),
						]);
						$last_pid_data_added_for = $product_id;

					} else {
						# Metafield parent isn't a product or variant; skip.
						continue;
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
