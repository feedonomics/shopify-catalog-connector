<?php

namespace ShopifyConnector\util\io;

use JsonException;
use ShopifyConnector\exceptions\io\UnableToDecodeResponseException;

/**
 * Utilities and helpers for common input parsing tasks
 */
class InputParser {

	/**
	 * Helper for extracting a value array out of an input array
	 *
	 * <p>If the key does not exist, an empty array is returned</p>
	 *
	 * @param array $in The input array
	 * @param string $key The key for the $in array which has the nested array
	 * value to extract
	 * @param string $separator The separator string to explode on if the value
	 * array is not already an array
	 * @return array The input transformed to an array
	 */
	public static function extract_array(array $in, string $key, string $separator = ',') : array {
		$in = $in[$key] ?? [];

		if(is_array($in)){
			return $in;
		}

		$in = trim($in);
		$in = !empty($in) ? explode($separator, $in) : [];
		return array_map(fn($v) => trim($v), $in);
	}

	/**
	 * Helper for pulling a value out of an input array and ensuring the correct
	 * boolean value
	 *
	 * <p>If the key does not exist, the value will default to false</p>
	 *
	 * @param array $in The input array
	 * @param string $key The key for the $in array which has the bool value
	 * to extract
	 * @param bool $default The default value to use if the key is not set
	 * @return bool The extracted and validated boolean value
	 */
	public static function extract_boolean(array $in, string $key, bool $default = false) : bool {
		$ret = $in[$key] ?? $default;
		return filter_var($ret, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * Helper for extracting and parsing JSON objects from the given input
	 *
	 * <p>Note: json_decode will always use the associative flag</p>
	 *
	 * @param array $in The input array
	 * @param string $key The key for the $in array which has the JSON value
	 * to extract
	 * @return mixed The JSON decoded value
	 * @throws UnableToDecodeResponseException If there was an error parsing the JSON
	 */
	public static function extract_json(array $in, string $key){
		$val = $in[$key] ?? 'null';

		if(is_array($val) || is_object($val)){
			return $val;
		}

		try {
			$ret = @json_decode($val, true, 512, JSON_THROW_ON_ERROR);
		} catch(JsonException $e){
			throw new UnableToDecodeResponseException("Error decoding JSON. Reason: {$e->getMessage()}. Original string: {$val}");
		}

		return $ret;
	}

	/**
	 * Helper for decoding a json string with built in error handling
	 *
	 * @param string $input The input string to decode
	 * @return mixed The {@see json_decode} response
	 * @throws UnableToDecodeResponseException On errors decoding
	 */
	public static function decode_json(string $input){
		try {
			return @json_decode($input, true, 512, JSON_THROW_ON_ERROR);
		} catch(JsonException $e){
			throw new UnableToDecodeResponseException(sprintf(
				'Error decoding JSON. Reason: %s. Original string: %.1024s',
				$e->getMessage(),
				$input
			));
		}
	}

	/**
	 * Helper to check if the given input is an associative array
	 *
	 * @param mixed $in The input to check
	 * @return bool True if the input is an associative array, false if it is
	 * not an array or is auto-indexed
	 */
	public static function is_associative($in) : bool {
		if(!is_array($in) || empty($in)){
			return false;
		}
		return array_keys($in) !== range(0, count($in) - 1);
	}

	/**
	 * Go through an array of object-arrays and extract + concatenate a specific
	 * key from each sub-array
	 *
	 * ```php
	 * // Example
	 * $input = [
	 *   ['id' => 1, 'piece' => 'N'],
	 *   ['id' => 2, 'piece' => 'v'],
	 *   ['id' => 3, 'piece' => 'rmor'],
	 *   ['id' => 4, 'piece' => ''],
	 * ];
	 *
	 * InputParser::implode_column($input, 'piece', 'e');
	 * // Outputs: `Nevermore`
	 * ```
	 *
	 * @param array $in The input array
	 * @param string|int $key The column key to extract values of
	 * @param string $separator The separator to use when imploding
	 * @return string The imploded extracted values
	 */
	public static function implode_column(array $in, $key, string $separator = '|') : string {
		return implode($separator, array_column($in, $key));
	}

	/**
	 * Get the appropriate value that is between and minimum and
	 * maximum boundary
	 *
	 * @param int $val The original value
	 * @param int $min The minimum allowed number
	 * @param int $max The maximum allowed number
	 * @return int The validated value
	 */
	public static function min_max(int $val, int $min, int $max) : int {
		return max(min($val, $max), $min);
	}

}

