<?php

namespace ShopifyConnector\validation;

use ShopifyConnector\exceptions\ValidationException;


/**
 * Base functionality for validators.
 *
 * Extending classes should be declared `abstract`, as nothing is meant to be
 * instantiated, only static methods are provided. These classes should make
 * use of the protected helper methods provided herein, e.g. {@see required()}.
 * When errors are encountered in validation, error messages should be enqueued
 * using the {@see addError()} method.
 *
 * The entry point for validation is provided by the {@see validate()} method
 * on this class. Validators should be used as follows:
 *   SpecificValidator::validate($myInput);
 */
abstract class BaseValidator {

	/**
	 * @var array Collection of error that have occurred
	 * @see throwIfErrors()
	 */
	private static array $errors = [];


	/**
	 * Logic to check the input for validity. Child classes should implement
	 * this method to cover the input they expect.
	 */
	abstract protected static function check(array $input) : void;


	/**
	 * Run validation logic on the given input.
	 *
	 * @throws ValidationException On missing or invalid fields
	 */
	final public static function validate(array $input) : void {
		static::check($input);
		self::throwIfErrors();
	}


	/**
	 * Add an error message to the list of errors to be delivered at the end of
	 * checking. Once this has been called at least once (i.e. at least one
	 * validation error has been found), then a ValidationException will be
	 * raised at the end of validation containing all the messages that were
	 * queued up.
	 *
	 * @param string $message The error message to be added to the list
	 */
	final protected static function addError(string $message) : void {
		self::$errors[] = $message;
	}


	/**
	 * Check a required field is present and not empty
	 *
	 * @param array $in The input array
	 * @param string $field The field name from the input array
	 * @param array|null $expected An array of expected values if the field
	 * is a whitelist, leave null to omit this check
	 */
	final protected static function required(
		array $in,
		string $field,
		?array $expected = null
	) : void {
		if(empty($in[$field])){
			self::addError("`{$field}` is required and cannot be empty");
		} elseif($expected !== null && !in_array($in[$field], $expected, true)){
			self::addError("Invalid value for `{$field}` specified, expected: " . implode(', ', $expected));
		}
	}


	/**
	 * Throw any collected errors and reset the error cache
	 *
	 * @throws ValidationException All collected errors summarized to a single
	 * message
	 */
	private static function throwIfErrors() : void {
		if(!empty(self::$errors)){
			$errors = implode(". ", self::$errors);
			self::$errors = [];
			throw new ValidationException($errors);
		}
	}

}

