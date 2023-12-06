<?php

namespace ShopifyConnector\exceptions;

use Exception;


/**
 * Core exception class all custom exceptions will need to extend
 */
abstract class CoreException extends Exception {

	/**
	 * Get the error code string (e.g. preprocess_validation_error)
	 *
	 * @return string The error code
	 */
	abstract public function get_error_code() : string;

	/**
	 * Get the error message
	 *
	 * @return string The error message
	 */
	abstract public function get_error_message() : string;

	/**
	 * Helper for error reporting from child processes that are passing error
	 * messages to a parent process
	 *
	 * @return string The error message appropriate to pass to a parent process
	 * via <code>fwrite(STDERR, $e->get_pipe_safe_error_message());</code>
	 * or similar
	 */
	final public function get_pipe_safe_error_message() : string {
		return substr($this->get_error_message(), 0, 800);
	}

	/**
	 * Ends the process with the http status code header and formatted JSON body
	 *
	 * <p>Uses a static 599 response code as a flag for our systems to check for
	 * the response code header for the custom error code</p>
	 *
	 * <p><b>NOTE: This modifies headers and kills the process</b></p>
	 * @codeCoverageIgnore
	 */
	final public function end_process() : void {
		$status_line = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
		$status_line .= ' 599 Preprocess Error';
		header($status_line, true);

		// This shouldn't be relied on anymore, but is provided for any legacy
		// things that might be expecting it
		header('X-preprocess-Response-Code: 700', true);

		$clean_display_message = substr(
			preg_replace('/[^ -~\t]/', '', strip_tags($this->get_error_message())),
			0,
			1250
		);

		echo json_encode([
			'message' => $this->get_error_code(),
			'display_message' => $clean_display_message,
		]);

		die;
	}

}

