<?php
namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\util\io\OutputTemplate;

/**
 * TODO: This class is meant to go bye bye.
 *   Leaving it here for the moment in case there's anything worthwhile to reference in it.
 *
 * Output template for Shopify
 * <p>
 * TODO:
 * - add* methods can take a ShopifySettings if dynamic fields involved
 * - Need to account for client_options['fields'] ??
 * - Likely need to account for 'fields' in the product filters
 * - include tax_rates if requested
 * <p>
 * TODO: More fields that may need to be included by prod/var:
 *   - child_title
 *   - fulfillment_service
 *   - gtin
 *   - inventory_item_id
 *   - inventory_management
 *   - inventory_policy
 *   - inventory_quantity
 *   - sku
 *   - weight
 *   - weight_unit
 */
final class ShopifyOutputTemplate
{

	/**
	 * @var array Store for product specific fields
	 */
	private array $productFields = [];

	/**
	 * @var array Store for variant specific fields
	 */
	private array $variantFields = [];

	/**
	 * @var array Store for additional fields
	 */
	private array $additionalFields = [];

	/**
	 * @var OutputTemplate Store for the output template
	 */
	private OutputTemplate $template;

	/**
	 * Create a new output template for Shopify. If fields are specified when
	 * calling this, those will be used as the fields of the template. If not,
	 * then the default outputs of Product and ProductVariant will be used to
	 * generate the list of fields. Additional fields from the request can be
	 * added in using the public add* methods.
	 *
	 * @param ?array $fields List of fields for the template to override the defaults
	 */
	public function __construct(?array $fields = null)
	{
		$this->template = new OutputTemplate();

		if ($fields === null) {
			$this->addProductFields();
			$this->addVariantFields();
		} else {
			$this->template->append_keyless_to_template($fields);
		}
	}

	/**
	 * Add the fields used for products to this template.
	 */
	private function addProductFields() : void
	{
		# Use output from a product object to generate list of fields
		$this->productFields = array_keys((new Product([]))->get_output_data([
			# For init, no metafields split
			'mfSplit' => false,
		]));

		# TODO: Add in product_meta if requested

		/* TODO: Move logic to somewhere higher up
				# Extra options set by client
				foreach(InputParser::extract_array(
					[ '_' => $settings->get('extra_options') ],
					'_'
				) as $opt){
					if($opt !== ''){
						# TODO: A different fields map for this?
						$name = "extra_option_{$opt}";
						$this->productFields[] = $name;
					}
				}
		*/

		$this->template->append_keyless_to_template($this->productFields);
	}

	/**
	 * Add the fields used for product variants to this template.
	 */
	private function addVariantFields() : void
	{
		# Use output from a variant object to generate list of fields
		$this->variantFields = array_keys((new ProductVariant(
			new Product([]),
			[]
		))->get_output_data([
			# For init, no domain needed and no metafields split
			'domain' => '',
			'mfSplit' => false,
		]));

		/* TOOD: Move logic to somewhere higher up
				# Presentment prices
				if($settings->get('include_presentment_prices')){
					$this->variantFields['presentment_prices'] = 'presentment_prices';
				}

				# GMC transition ids
				# Derrived from country_code + product_id + variant_id
				if($settings->get('use_gmc_transition_id')){
					$this->variantFields[] = 'gmc_transition_id';
				}
		*/

		$this->template->append_keyless_to_template($this->variantFields);
	}

	/**
	 * Get the header row of field labels contained by this template.
	 *
	 * @return array The header fields from this template
	 */
	public function getHeader() : array
	{
		return $this->template->get_template();
	}

	/**
	 * Get a templated row of data for the given product variant (+ contained
	 * product)
	 *
	 * @param ProductVariant $var The variant containing the data to map out
	 */
	public function getRowFor(ProductVariant $var)
	{
		$data = array_merge(
			$var->product->get_output_data(),
			$var->get_output_data(['domain' => 'todo']) # TODO
		);
		return $this->template->fill_template($data);
	}

}
