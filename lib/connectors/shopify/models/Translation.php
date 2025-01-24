<?php

namespace ShopifyConnector\connectors\shopify\models;

use JsonSerializable;

/**
 * Model for a Shopify translation
 */
final class Translation extends FieldHaver implements JsonSerializable
{

	const TYPE_PRODUCT = 1;

	private int $type;


	/**
	 * Set up a new Translation object with the given data. The "__parentId"
	 * key will be removed from the data, if present.
	 *
	 * @param array $fields The data for the Translation
	 */
	public function __construct(array $fields, int $type)
	{
		parent::__construct($fields);

		$this->type = $type;
	}

	/**
	 * Get the tag appropriate to the type of translation this is.
	 *
	 * @return string Tag for use in field names
	 */
	private function get_type_tag() : string
	{
		return '';
	}

	/**
	 * Get the identifier strings for this translation.
	 *
	 * Identifier strings are made by concatenating the field's locale
	 * and key with a "_" between each. The resulting string will
	 * be stripped of any spicy characters.
	 *
	 * @return array The identifiers array for this translation
	 */
	public function get_identifiers() : array
	{
		$translations = $this->get('translations', []);
		foreach ($translations as $translation) {
			$translation_locale = $translation['locale'];
			$translation_key = $translation['key'];
			$identifiers[] = preg_replace('/[^0-9,a-zA-Z$_\x{0080}-\x{FFFF}]/u', '', "{$translation_locale}_{$translation_key}");
		}
		return $identifiers;
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


	/**
	 * @return [] No variant translations
	 */
	public function get_variants() : array
	{
		return [];
	}


}

