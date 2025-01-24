<?php
namespace ShopifyConnector\util\io;

/**
 * Utilities for common data manipulation tasks
 */
class DataUtilities
{

	/**
	 * Create a new array by taking the given input and running the keys
	 * through the given map. If any keys are not present in the map, then
	 * the original key will be preserved. If any collisions are produced by
	 * the mapping process, the last encountered value will emerge victorious.
	 * For the map, the keys are the inputs and the values are the outputs.
	 *
	 * ```php
	 * DataUtilities::translate_keys(
	 *   ['id' => 1, 'sku' => 2, 'title' => 'thing'],
	 *   ['id' => 'uid', 'sku' => 'pid']
	 * );
	 *
	 * // Returns:
	 * // ['uid' => 1, 'pid' => 2, 'title' => 'thing']
	 * ```
	 *
	 * @param array $array Array to translate the keys of
	 * @param array $map Map of translations
	 * @return array Array with the new set of keys mapped to the original values
	 */
	public static function translate_keys(array $array, array $map) : array
	{
		$ret = [];
		foreach ($array as $k => $v) {
			$ret[$map[$k] ?? $k] = $v;
		}
		return $ret;
	}

	/**
	 * Create a new array by running values through a map and converting any
	 * matches while preserving any values not found in the map
	 *
	 * ```php
	 * DataUtilities::translate_values(
	 *   ['id' => 1, 'color' => 'color:blue', 'stock' => 'STOCK_OUT_OF_STOCK']
	 *   ['color:blue' => 'blue', 'STOCK_OUT_OF_STOCK' => 'out of stock']
	 * );
	 *
	 * // Returns:
	 * // ['id' => 1, 'color' => 'blue', 'stock' => 'out of stock']
	 *
	 * @param array $array Array to translate the values of
	 * @param array $map Map of translations keyed by value of the first param
	 * @return array Array with the translated values
	 */
	public static function translate_values(array $array, array $map) : array
	{
		# array_map will preserve keys when given one input array
		return array_map(
			fn($v) => $map[$v] ?? $v,
			$array
		);
	}

}
