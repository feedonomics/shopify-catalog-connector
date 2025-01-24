<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;
use JsonSerializable;

/**
 * Model for a Shopify collection
 */
final class Collection extends FieldHaver implements JsonSerializable
{

	const TYPE_PRODUCT = 1;
	const TYPE_VARIANT = 2;
	const TYPE_COLLECTION = 3;


	private int $type;


	/**
	 * Set up a new Collection object with the given data. The "__parentId"
	 * key will be removed from the data, if present.
	 *
	 * @param array $fields The data for the Collection
	 */
	public function __construct(array $fields, int $type)
	{
		unset($fields['__parentId']);
		parent::__construct($fields);

		$this->type = $type;
	}

	/**
	 * Get the tag appropriate to the type of collection this is.
	 *
	 * @return string Tag for use in field names
	 */
	private function get_type_tag() : string
	{
		return '';
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
		$data = [
			'translations' => $this->get('translations', ''),
		];
		$session = SessionContainer::get_active_session();

		return $data;
	}


	/**
	 * @return [] No variant translations
	 */
	public function get_variants() : array
	{
		return [];
	}


}


