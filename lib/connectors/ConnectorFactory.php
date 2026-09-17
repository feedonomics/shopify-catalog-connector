<?php

namespace ShopifyConnector\connectors;

use ShopifyConnector\exceptions\CustomException;

/**
 * Factory for getting the correct connector for the given request
 */
class ConnectorFactory {

	/**
	 * Map of connector classes
	 */
	const CLASS_MAP = [
		'shopifymodular' => ShopifyModular::class,
		'shopifymodular_v2' => ShopifyModular_v2::class,
		'shopifymodular_v4' => ShopifyModular_v4::class
	];

	/**
	 * Get the connector to use given the passed in user settings
	 *
	 * @param array $conn_info The user settings
	 * @return BaseConnector The connector
	 * @throws CustomException If no connector was found
	 */
	public static function getConnector(array $conn_info, array $file_info) : BaseConnector {
		$client = $conn_info['client'] ?? null;
		$connector = self::CLASS_MAP[$client] ?? null;

		# Disallow empty client, even if it happened to map to something
		if(empty($client) || $connector === null){
			throw new CustomException(
				'no_client_class',
				'The specified client is not supported'
			);
		}

		# Callers that reach us through a job-queue wrapper cannot set file_info: the wrapper builds
		# it and forwards only connection_info verbatim. Without this they are stuck with the
		# default product pull and cannot ask for the shop-info request type at all. file_info
		# still wins when it carries a value, so callers hitting run_preprocess.php or the CLI
		# directly are unaffected. An unrecognised value is rejected later by BaseConnector::run().
		if (empty($file_info['request_type']) && !empty($conn_info['request_type'])) {
			$file_info['request_type'] = $conn_info['request_type'];
		}

		return new $connector($conn_info, $file_info);
	}

}

