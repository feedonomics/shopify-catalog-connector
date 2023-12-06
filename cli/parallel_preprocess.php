<?php

namespace ShopifyConnector\cli;

use Throwable;

use ShopifyConnector\connectors\ConnectorFactory;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\CoreException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\exceptions\ValidationException;

use ShopifyConnector\log\ErrorLogger;


// No time limit
set_time_limit(0);
// Keep the error pipe clean so we can report only
// important info to the parent process
error_reporting(0);


require_once (__DIR__ . '/../config.php');


try {
	//// params
	$connection_info = json_decode($argv[1] ?? 'null', true, 16, JSON_THROW_ON_ERROR);
	$file_info       = json_decode($argv[2] ?? 'null', true, 16, JSON_THROW_ON_ERROR);

	if(!is_array($connection_info)
	|| !is_array($file_info)
	){
		throw new ValidationException('Unable to parse request. Please validate your inputs or contact support for assistance.');
	}

	if(($connection_info['protocol'] ?? null) !== 'api'){
		throw new ValidationException('Invalid protocol specified');
	}

	$connector = ConnectorFactory::getConnector($connection_info, $file_info);
	$connector->run();

} catch(ApiException $e){
	$are = new ApiResponseException($e->getMessage());

	fwrite(STDERR, json_encode([
		'error_code' => $are->get_error_code(),
		'error_message' => $are->get_pipe_safe_error_message(),
	]));

} catch(CoreException $e){
	fwrite(STDERR, json_encode([
		'error_code' => $e->get_error_code(),
		'error_message' => $e->get_pipe_safe_error_message(),
	]));

} catch(Throwable $e){
	ErrorLogger::log_exception($e);

	$iee = new InfrastructureErrorException();
	fwrite(STDERR, json_encode([
		'error_code' => $iee->get_error_code(),
		'error_message' => $iee->get_pipe_safe_error_message(),
	]));
}

