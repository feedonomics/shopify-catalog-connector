<?php

namespace ShopifyConnector\validation;

/**
 * Validator for the Shopify import settings
 */
abstract class ShopifySettingsValidator extends BaseValidator {

	/**
	 * Validate the shopify specific settings.
	 *
	 * @inheritDoc BaseValidator::check()
	 * @param array $input The connection_info settings from the request
	 *  (e.g. $_GET['connection_info'])
	 */
	final protected static function check(array $input) : void {
		self::required($input, 'oauth_token');
		self::required($input, 'shop_name');

		# Rules for this format were surprisingly difficult to find for
		# certain, but some piecing together plus testing seems to indicate
		# shop names are all lower case letters with any symbols converted to
		# hyphens. Being slightly more lenient here for now just in case, while
		# still prohobiting obviously bad formats.
		if(preg_match('/^[-_[:alnum:]]+$/', $input['shop_name']) !== 1){
			self::addError('Invalid shop name format');
		}

		if(!empty($input['fields']) && !is_array($input['fields'])){
			self::addError('Fields must be an array');
		}

		if(!empty($input['product_published_status'])){
			self::required($input, 'product_published_status', [
				'published',
				'unpublished',
				'any'
			]);
		}
	}

}

