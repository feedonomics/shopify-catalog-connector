<?php

namespace ShopifyConnector\util\file;

use ShopifyConnector\interfaces\TemporaryResource;


/**
 * A file handle resource that is temporary (should not live past run time, if
 * even that long)
 *
 * <p>NOTE: This should not <i>usually</i> be accessed directly, but rather be
 * generated through the {@see TemporaryFileGenerator} or similar factory, so
 * temporary files can be tracked and cleaned up appropriately</p>
 */
class TemporaryFile extends FileHandle implements TemporaryResource {

	/**
	 * Delete the file if it still exists
	 */
	public function cleanup() : void {
		$this->delete();
	}

}

