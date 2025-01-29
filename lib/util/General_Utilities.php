<?php

namespace ShopifyConnector\util;

use CurlHandle;
use ShopifyConnector\util\db\ConnectionFactory;


class General_Utilities {

	/**
	 * @param $retry_function
	 * 				The function to retry the allotted number of times
	 * @param $is_successful
	 * 				The retry callback to determine if the retry loop can be broken out of
	 * 				Should return BOOL (True = stop retries and return result| False = keep trying)
	 * 				The parameter is the result from $retry_function
	 * @param int $max_attempts
	 *                                The maximum attempts to will retry the $retry_function
	 *                                (Defaults to 1 attempt i.e. no retries)
	 * @param int $max_backoff_window
	 *                                The maximum window of time a retry will sleep for.
	 *                                (Defaults to 10 seconds)
	 * @param int $initial_sleep
	 *                                Where the backoff window will start at
	 *                                (Defaults to 1 second)
	 *
	 * @throws \Exception
	 *
	 * @return mixed
	 *               Will return the result of $retry_function or FALSE if never successful
	 */
	public static function exponential_backoff_retry(
		$retry_function,
		$is_successful,
		$max_attempts = 1,
		$max_backoff_window = 10,
		$initial_sleep = 1,
		$random_sleep = true
	){
		$attempt_num = 0;
		$current_back_off_window = $initial_sleep;
		$result = false;
		while (++$attempt_num <= $max_attempts) {
			$result = false;
			$exception_thrown = false;

			try {
				$result = $retry_function();
			} catch (\Exception $e) {
				$exception_thrown = true;
				if ($attempt_num === $max_attempts) {
					throw $e;
				}
			}
			$quit_loop =
				$attempt_num === $max_attempts //If we are out of tries, don't bother sleeping
				|| (!$exception_thrown && $is_successful($result)) //We met success criteria, stop trying
			;
			if ($quit_loop) {
				break;
			}

			// Random sleep or exact backoff?
			if ($random_sleep) {
				$sleep_time = rand($initial_sleep, $current_back_off_window);
			} else {
				$sleep_time = $current_back_off_window;
			}
			sleep($sleep_time);
			$current_back_off_window = min($max_backoff_window, $current_back_off_window * $current_back_off_window);
		}

		return $result;
	}

	/**
	 * Sets the allowed request protocols.
	 *
	 * @param $ch CurlHandle Curl handle
	 */
	public static function update_request_with_protocol(&$ch){
		$protocols = CURLPROTO_HTTP | CURLPROTO_HTTPS | CURLPROTO_FTP | CURLPROTO_FTPS | CURLPROTO_SFTP;

		curl_setopt($ch, CURLOPT_PROTOCOLS, $protocols);
		curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $protocols);
	}

	/**
	 * Request a new curl resource with a baseline set of config and
	 * constraints already applied to it. The config applied here is meant to
	 * be very high-level (think security) and applicable to any curl usage.
	 *
	 * <p>Most, if not all places that need a curl handle should instantiate it
	 * using this method. When adding new modifications here for the returned
	 * handle, be sure to keep this in mind.</p>
	 *
	 * @param ?string $url URL to pass to curl_init (optional)
	 * @return CurlHandle|false The result of {@see curl_init} with the
	 * {@see CURLOPT_PRODOCOLS} and {@see CURLOPT_REDIR_PROTOCOLS} whitelist
	 * set
	 */
	public static function get_configured_curl_handle($url = null){
		$ch = curl_init($url);
		if($ch === false){ return false; }
		self::update_request_with_protocol($ch);
		return $ch;
	}

	/**
	 * Takes a URL and returns the curl_get_info without returning a body.
	 * @param string $raw_url The url to get info for
	 * @return mixed The curl info for the url
	 */
	public static function get_url_info(string $raw_url){
		$url = filter_var($raw_url, FILTER_SANITIZE_URL);
		$ch = self::get_configured_curl_handle();

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_SSL_VERIFYPEER	=> false,
			CURLOPT_CONNECTTIMEOUT  => 30,
			CURLOPT_TIMEOUT	=> 30,
			CURLOPT_ENCODING	=> "gzip",
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_AUTOREFERER	=> true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT	=> "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.80 Safari/537.36",
			CURLOPT_HEADER	=> false,
			CURLOPT_NOBODY	=> true,
		]);

		curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		return $info;
	}

	/**
	 * Takes a literal string and replaces the literal with evaluated values (e.g. \n becomes a newline)
	 * @param $literal_string
	 * @return array|string|string[]
	 */
	public static function evaluate_string_literal($literal_string){
		$translations = [
			'\n' => "\n",
			'\t' => "\t",
			'\r' => "\r",
			'none' => '',
		];
		return str_replace(
			array_keys($translations),
			array_values($translations),
			$literal_string
		);
	}

	/**
	 * @param string $script_id
	 * @param array $unique_data
	 * @return bool
	 */
	public static function lock_script(string $script_id, array $unique_data) : bool {
		try {
			$cxn = ConnectionFactory::connect('central_db');
		} catch (\Exception $e) {
			return false;
		}
		$crawl_data_string = json_encode(array_merge(
			$unique_data,
			[
				'debugging_information' => [
					'process_ids' => [
						getmypid()
					],
					'server' => gethostname()
				]
			]
		));
		$data_string = json_encode($unique_data);
		$data_uuid = md5($data_string);
		$uuid_esc = $cxn->real_escape_string("{$script_id}_{$data_uuid}");

		$is_locked = $cxn->query("SELECT 1 FROM crawl_locks.locks WHERE uuid = '{$uuid_esc}' AND locked = 1");
		if ($is_locked->num_rows > 0) {
			return false;
		}

		$crawl_data_string_esc = $cxn->real_escape_string($crawl_data_string);
		$cxn->query("INSERT INTO crawl_locks.locks(uuid, crawl_data) VALUES('{$uuid_esc}','{$crawl_data_string_esc}') ON DUPLICATE KEY UPDATE locked = 1,locked_date = CURRENT_TIMESTAMP, crawl_data = '{$crawl_data_string_esc}'");
		return true;
	}

	/**
	 * @param string $script_id
	 * @param array $unique_data
	 * @return bool
	 */
	public static function unlock_script(string $script_id, array $unique_data) : bool {
		try {
			$cxn = ConnectionFactory::connect('central_db');
		} catch (\Exception $e) {
			return false;
		}
		$data_string = json_encode($unique_data);
		$data_uuid = md5($data_string);
		$uuid_esc = $cxn->real_escape_string("{$script_id}_{$data_uuid}");

		$cxn->query("UPDATE crawl_locks.locks SET locked = 0,run_time = TIMESTAMPDIFF(SECOND, locked_date , CURRENT_TIMESTAMP) WHERE uuid = '{$uuid_esc}' AND locked = 1");
		return true;
	}

}

