<?php

namespace ShopifyConnector\api;


/**
 * Extendable class for a service for calling specific API endpoints
 *
 * <p>Services are typically set up per resource/sub-path - as in if you have
 * the following endpoint `/reports` with GET, POST, DELETE methods and a
 * `/reports/:id` path, those would belong to a single service as such:</p>
 *
 * <p>A separate endpoint, such as `/products` would belong in its own service,
 * separate from the report service, for example</p>
 */
abstract class BaseService {

	/**
	 * @var ApiClient Cache for an instantiated client for making API calls
	 * with
	 */
	protected ApiClient $client;

	/**
	 * Extendable class for a service for calling specific API endpoints
	 *
	 * @param ApiClient $client The client for making API calls
	 */
	final public function __construct(ApiClient $client){
		$this->client = $client;
	}

}

