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
	// TODO Eventually all shopify implementations will be using the new modularized code,
	//   and the others will be deprecated and removed along with this translation logic
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

	// ShopifyModular uses parallel processing
	if($connection_info['client'] === 'shopifymodular'){
		$output_compressed = InputParser::extract_boolean($file_info, 'output_compressed');

		$prunner_path = "{$GLOBALS['file_paths']['base_path']}/cli/parallel_preprocess.php";
		if(empty($GLOBALS['file_paths']['base_path']) || !file_exists($prunner_path)){
			// Small safety check before attempting to execute
			throw new CustomException(
				'internal_error',
				'Parallel processing misconfigured, please contact support'
			);
		}

		$esc_parallel_runner = escapeshellarg($prunner_path);
		$esc_json_cxn_info = escapeshellarg(json_encode($connection_info, 16, JSON_THROW_ON_ERROR));
		$esc_json_file_info = escapeshellarg(json_encode($file_info, 16, JSON_THROW_ON_ERROR));

		$descriptor_spec = [
			0 => ["pipe", "r"],   // stdin is a pipe that the child will read from
			1 => ["pipe", "w"],   // stdout is a pipe that the child will write to
			2 => ["pipe", "w"]    // stderr is a pipe that the child will write to
		];

		// WARNING error handler does not cross new script calls
		$process = proc_open(
			"php -f {$esc_parallel_runner} {$esc_json_cxn_info} {$esc_json_file_info}",
			$descriptor_spec,
			$pipes
		);

		$process_handler = new ProcessHandler($process, $pipes, $output_compressed);
		$process_handler->handle_process();

	} else {
		$connector = ConnectorFactory::getConnector($connection_info, $file_info);
		$connector->run();
	}

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

