<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Translation;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\translations\Translations;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify translations.
 */
class BulkTranslations extends BulkBase
{
	/**
	 * @inheritDoc
	 */
	public function get_query() : string
	{
		$prod_search_str = $this->session->settings->product_filters->get_filters_gql();
		$locale = $this->session->settings->translation_locale;

		return <<<GQL
			products{$prod_search_str} {
				edges {
					node {
						id
						translations(locale: "{$locale}") {
							key
							locale
							value
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
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void
	{
		$fh = $this->checked_open_file($filename);

		try {
			$translation_names = [];

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
				$product_id = $gid;
				if ($gid->is_product()) {
					$translation = new Translation($decoded);
					// Ensure that there is data in translations
					if (!empty($decoded['translations'])) {
						$insert_product->add_value_set($cxn, [
							Translations::COLUMN_ID => $product_id->get_id(),
							Translations::COLUMN_DATA => json_encode($decoded['translations']),
						]);
						$identifiers = $translation->get_identifiers();
						foreach ($identifiers as $identifier) {
							$translation_names[$identifier] = true;
						}
					}

				}
			}
			// Commit anything remaining in the batched inserters
			$insert_product->run_query($cxn);

		} finally {
			fclose($fh);
		}
		$result->result = array_values(array_unique(array_merge(
			$result->result,
			array_keys($translation_names)
		)));
	}

}

