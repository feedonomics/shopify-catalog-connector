<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;

use JsonSerializable;

/**
 * Model for a Shopify collection
 */
final class Collection extends FieldHaver implements JsonSerializable
{

	/**
	 * Set up a new Collection object with the given data. The "__parentId"
	 * key will be removed from the data, if present.
	 *
	 * @param array $fields The data for the Collection
	 */
	public function __construct(array $fields)
	{
		unset($fields['__parentId']);

		$data = [];
		foreach ($fields as $c_field => $c_data) {
			$data[$c_field] = $c_data;
		}

		parent::__construct($data);
	}

	/**
	 * Get the identifier string for this collection.
	 *
	 * Identifier strings are made by concatenating the field's type tag, namespace (if
	 * requested in settings), and key with a "_" between each. The resulting string will
	 * be stripped of any spicy characters.
	 *
	 * @return string The identifier string for this collection
	 */
	public function get_identifier() : string
	{
		return '';
	}

	/**
	 * Implementation for JsonSerializable interface.
	 * Get a json_encodable representation of this object, to be used by
	 * json_encode when json_encoding.
	 *
	 * @return array A json_encodable representation of this translation
	 */
	public function jsonSerialize() : array
	{
		return $this->get_output_data();
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_data(?array $field_list = null) : array
	{
		return [];
	}

}

