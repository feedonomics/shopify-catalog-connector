<?php

namespace ShopifyConnector\util;

use ShopifyConnector\interfaces\TemporaryResource;


/**
 * Register for storing, tracking, and cleaning up temporary resources
 */
final class TemporaryResourceRegister {

	/**
	 * @var int $process_id Store for the PHP process id
	 */
	private int $process_id;

	/**
	 * @var TemporaryResource[] An internal store for all generated temporary
	 * resources to clean up
	 */
	protected array $store = [];

	/**
	 * Initialize the resource register
	 *
	 * <p>This assumes the first thing calling it is the parent process and will
	 * store the process id to use with the php shutdown function register</p>
	 */
	public function __construct(){
		$this->process_id = getmypid();
		register_shutdown_function(function() {
			$this->cleanup(); // @codeCoverageIgnore
		});
	}

	/**
	 * Add a temporary resource to this register
	 *
	 * @param TemporaryResource $resource The resource to add
	 */
	public function add(TemporaryResource $resource) : void {
		$this->store[] = $resource;
	}

	/**
	 * Cleanup any and all temporary resources that have been created
	 *
	 * <p>This will only activate the cleanup if the process id matches the
	 * process id that was set as part of {@see init}. This can be bypassed with
	 * the $force parameter</p>
	 *
	 * @param bool $force Force the register to clean up its resources
	 * regardless of process calling it
	 * @return void
	 */
	public function cleanup(bool $force = false) : void {
		if($force || $this->process_id === getmypid()) {
			foreach ($this->store as $resource) {
				$resource->cleanup();
			}
		}
	}

}

