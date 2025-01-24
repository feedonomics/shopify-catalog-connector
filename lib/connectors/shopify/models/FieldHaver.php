<?php

namespace ShopifyConnector\connectors\shopify\models;


use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Base class for models that contain accessible fields and that will
 * contribute to the processed output. This will generally be used to
 * wrap the pieces of data returned by the Shopify APIs and provide a
 * simple and consistent interface for working with the data contained
 * therein.
 */
abstract class FieldHaver
{

	/**
	 * @var array Store for the field data
	 */
	private array $fields;

	/**
	 * Setup for the fields that should be exposed through the `get` method.
	 *
	 * @param array $fields The field data for this class to hold
	 */
	protected function __construct(array $fields)
	{
		$this->add_data($fields);
	}

	/**
	 * Merge in additional data to the field data. If any data is already set under
	 * a given field name, it will be overwritten by the new data. The keys of the
	 * given data will be run through {@see translate_field_name()}.
	 *
	 * @param Array<string, mixed> $data The data to add, keyed by field name
	 */
	public function add_data(array $data) : void
	{
		foreach ($data as $field => $datum) {
			$this->add_datum($field, $datum);
		}
	}

	/**
	 * Add in a single piece of field data. Similar to {@see add_data()}, this will
	 * overwrite any existing data with the new data and will run the field name
	 * through {@see translate_field_name()}.
	 *
	 * @param string $field_name The name of the field to set the data for
	 * @param mixed $datum The data to set to the indicated field
	 */
	public function add_datum(string $field_name, $datum) : void
	{
		$this->fields[$this->translate_field_name($field_name)] = $datum;
	}

	/**
	 * Get the value for the specified field. If the named field is not
	 * present in the data set, the specified default will be returned.
	 * If the `matchType` parameter is set to TRUE and a non-null default
	 * is provided and a non-null value is present in the data set for the
	 * given key, then the type of the value found will be compared to the
	 * type of the default specified, and if the types do not match, an
	 * exception will be generated.
	 *
	 * @param string $key The name of the field to get
	 * @param mixed $default A default value to use when `key` is not present
	 * @param bool $matchType TRUE to enforce type-matching for present values
	 * @return mixed The value at the given key, falling back to the given default
	 * @throws ApiResponseException On type mis-match when `matchType` is TRUE
	 */
	public final function get(string $key, $default = null, bool $matchType = true)
	{
		if (!array_key_exists($key, $this->fields)) {
			return $default;
		}
		$val = $this->fields[$key];

		if ($default !== null
			&& $val !== null
			&& $matchType
			&& gettype($val) !== gettype($default)
		) {
			throw new ApiResponseException(sprintf(
				'Invalid data type (%s -- expected %s) received for `%s` in %s',
				gettype($val),
				gettype($default),
				$key,
				static::class
			));
		}

		return $val;
	}

	/**
	 * Translate the given field name (will generally be the name used by Shopify) to
	 * the name used by FDX for the field.
	 *
	 * The default implementation is the identity operation.
	 *
	 * @param string $field The field name to translate
	 * @return string The translated version of the field name
	 */
	public function translate_field_name(string $field) : string
	{
		return $field;
	}

	/**
	 * Default impl. Simply returns the value as-is with no processing.
	 */
	public function get_processed_value(string $field)
	{
		return $this->get($field);
	}

	/**
	 * Get the set of fields that represents the data of the object that
	 * implements this class. This is the "output data" for any given object
	 * and depends on what's being modeled, so implementation is left to the
	 * child classes.
	 *
	 * The result from this method is to be used in generating output, so the
	 * returned array should reflect the result of all processing that is
	 * expected on the data.
	 *
	 * If a fields list is provided, then data for that specific list will be
	 * retrieved. This is useful for limiting the set of fields in output or
	 * when a desired output set includes virtual/generated fields that wouldn't
	 * necessarily exist as-is in the field set.
	 *
	 * If no fields list is provided, then all fields from the field set will
	 * be processed and returned.
	 *
	 * Any null outputs are not included in the results.
	 *
	 * @param ?array $field_list A specific set of fields to get data for
	 * @return array The set of (processed) output data for this object
	 */
	public function get_output_data(?array $field_list = null) : array
	{
		if ($field_list === null) {
			$field_list = array_keys($this->fields);
		}

		$output = [];
		foreach ($field_list as $field) {
			$value = $this->get_processed_value($field);
			if ($value !== null) {
				$output[$this->translate_field_name($field)] = $value;
			}
		}

		return $output;
	}

}

