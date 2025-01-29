<?php

namespace ShopifyConnector\web;

use Throwable;

use ShopifyConnector\connectors\ConnectorFactory;

use ShopifyConnector\exceptions\CustomException;
use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\CoreException;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\log\ErrorLogger;

use ShopifyConnector\util\io\InputParser;
use ShopifyConnector\util\ProcessHandler;

use ShopifyConnector\validation\ClientOptionsValidator;


set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('default_socket_timeout', 120);


require_once(__DIR__ . '/../config.php');


try {
	$args = !empty($_POST) ? $_POST : $_GET;
	ClientOptionsValidator::validate($args);

	$connection_info = $args['connection_info'] ?? [];
	$file_info       = $args['file_info'] ?? [];

	//// ShopifyModular compatibility translation
	$is_modular_shopify_implicitly = $connection_info['client'] === 'shopify' && (
		isset($connection_info['data_types']) // New implementations using data_types
		||
		(
			isset($connection_info['collections'])
			&& filter_var($connection_info['collections'], FILTER_VALIDATE_BOOLEAN)
		)
		||
		(
			isset($connection_info['meta'])
			&& filter_var($connection_info['meta'], FILTER_VALIDATE_BOOLEAN)
		)
		||
		(
			isset($connection_info['collections_meta'])
			&& filter_var($connection_info['collections_meta'], FILTER_VALIDATE_BOOLEAN)
		)
		||
		(
			isset($connection_info['inventory_level'])
			&& filter_var($connection_info['inventory_level'], FILTER_VALIDATE_BOOLEAN)
		)
		||
		(
			isset($connection_info['inventory_item'])
			&& filter_var($connection_info['inventory_item'], FILTER_VALIDATE_BOOLEAN)
		)
	);

	if($is_modular_shopify_implicitly){
		$connection_info['client'] = 'shopifymodular';
	}

	$connector = ConnectorFactory::getConnector($connection_info, $file_info);
	$connector->run();

} catch (ApiException $e){
	(new ApiResponseException($e->getMessage()))->end_process();

} catch (CoreException $e){
	$e->end_process();

} catch (Throwable $e){
	// Unknown throwables may contain sensitive data, so log the error message
	// and return a generic error to the user
	ErrorLogger::log_exception($e);
	(new InfrastructureErrorException())->end_process();
}

