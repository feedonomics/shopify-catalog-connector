<?php

namespace ShopifyConnector\interfaces;


/**
 * Interface for any resource that should not live past the runtime environment
 * and needs to clean up after itself
 */
interface TemporaryResource {

	/**
	 * Clean up any and all temporary resources that this resource has created
	 *
	 * @return void
	 */
	public function cleanup() : void;

}

