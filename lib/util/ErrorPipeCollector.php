<?php
namespace ShopifyConnector\util;

use ShopifyConnector\exceptions\CoreException;
use ShopifyConnector\exceptions\CustomException;
use JsonException;

/**
 * Helper class for collecting and sorting errors, notices, etc. from a
 * child process
 */
class ErrorPipeCollector {

	/**
	 * @var string[] Store for notifications that were not valid JSON
	 */
	private array $notifications = [];

	/**
	 * @var ?CustomException Store for the last found custom exception if one
	 * was set
	 */
	private ?CustomException $exception = null;

	/**
	 * Add a line of error data to the collector's cache that was returned
	 * from a child process's error pipe
	 *
	 * <p>This will ignore any empty info fed to it.</p>
	 *
	 * <p>Any responses that are valid JSON will be parsed and validated to be
	 * a {@see CoreException}, and will store only the latest value.</p>
	 *
	 * <p>All non-JSON blobs of info as assumed to be PHP warnings and notices
	 * and will be cached in a separate register for logging. Also, any decoded
	 * JSON that does not appear to be formatted as a {@see CoreException} will
	 * be added to this cache.</p>
	 *
	 * @param mixed $info The line returned from the error pipe
	 */
	public function collect($info){
		if(empty($info)){
			return;
		}

		try {
			$err = @json_decode($info, true, 512, JSON_THROW_ON_ERROR);
			if(isset($err['error_code']) && isset($err['error_message'])){
				$this->exception = new CustomException($err['error_code'], $err['error_message']);
			} else {
				$this->notifications[] = $info;
			}
		} catch (JsonException $e){
			$this->notifications[] = $info;
		}
	}

	/**
	 * Check if an exception was collected or not
	 *
	 * @return bool True if an exception was collected
	 */
	public function has_collected_exception() : bool {
		return $this->exception !== null;
	}

	/**
	 * Get the last logged exception
	 *
	 * @return CustomException|null The last found exception parsed into a
	 * custom exception or null if no exception was found
	 */
	public function get_exception() : ?CustomException {
		return $this->exception;
	}

	/**
	 * Get the PHP warnings and notices
	 *
	 * @return string[] The array of collected notifications
	 */
	public function get_notifications() : array {
		return $this->notifications;
	}

}
