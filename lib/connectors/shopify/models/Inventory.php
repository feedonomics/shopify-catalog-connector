<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;
use JsonSerializable;

/**
 * Model for a Shopify metafield
 */
final class Inventory extends FieldHaver implements JsonSerializable
{

	const TYPE_PRODUCT = 1;
	const TYPE_VARIANT = 2;
	const TYPE_COLLECTION = 3;


	private int $type;


	/**
	 * Set up a new Metafield object with the given data. The "__parentId"
	 * key will be removed from the data, if present.
	 *
	 * @param array $fields The data for the Metafield
	 */
	public function __construct(array $fields, int $type)
	{
		unset($fields['__parentId']);
		parent::__construct($fields);

		$this->type = $type;
	}

	/**
	 * Get the tag appropriate to the type of metafield this is.
	 *
	 * @return string Tag for use in field names
	 */
	private function get_type_tag() : string
	{
		switch ($this->type) {
			case self::TYPE_PRODUCT:
				return 'parent_meta';

			case self::TYPE_VARIANT:
				return 'variant_meta';

			case self::TYPE_COLLECTION:
				return 'collection_meta';
		}

		return 'other_meta';
	}

	/**
	 * Get the identifier string for this metafield.
	 *
	 * Identifier strings are made by concatenating the field's type tag, namespace (if
	 * requested in settings), and key with a "_" between each. The resulting string will
	 * be stripped of any spicy characters.
	 *
	 * @return string The identifier string for this metafield
	 */
	public function get_identifier() : string
	{
		$mf_type = $this->get_type_tag();
		$mf_key = str_replace('-', '_', strtolower($this->get('key', '')));
		$mf_ns = SessionContainer::get_active_setting('use_metafield_namespaces', false)
			? $this->get('namespace', '') . '_'
			: ''
		;

		$identifier = "{$mf_type}_{$mf_ns}{$mf_key}";
		return preg_replace('/[^0-9,a-zA-Z$_\x{0080}-\x{FFFF}]/u', '', $identifier);
	}

	/**
	 * Implementation for JsonSerializable interface.
	 * Get a json_encodable representation of this object, to be used by
	 * json_encode when json_encoding.
	 *
	 * @return array A json_encodable representation of this metafield
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
			'namespace' => $this->get('namespace', ''),
			'key' => $this->get('key', ''),
			'description' => $this->get('descriptionHtml', ''),
			'value' => $this->get('value', ''),
		];

		$session = SessionContainer::get_active_session();
		if ($session && $session->in_final_output_stage() && $session->settings->metafields_split_columns) {
			unset($data['key']);
		}

		return $data;
	}

}

