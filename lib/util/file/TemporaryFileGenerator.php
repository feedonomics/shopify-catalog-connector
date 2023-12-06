<?php

namespace ShopifyConnector\util\file;

use ShopifyConnector\exceptions\file\UnableToCreateTmpFileException;
use ShopifyConnector\exceptions\file\UnableToOpenFileException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;
use ShopifyConnector\util\TemporaryResourceRegister;

/**
 * Factory for generating and cleaning up temporary files
 */
class TemporaryFileGenerator {

	/**
	 * @var TemporaryResourceRegister The resource register to add temporary
	 * file resources to
	 */
	private static TemporaryResourceRegister $resource_register;

	/**
	 * Generate an empty temporary file
	 *
	 * @param string $prefix The file name prefix to use when creating the
	 * temporary file
	 * @param string $extension An optional file extension to add to the
	 * randomly generated file name
	 * @return TemporaryFile The temporary file resource for use
	 * @throws UnableToCreateTmpFileException|UnableToOpenFileException On
	 * errors generating the temporary file
	 */
	public static function get(string $prefix, string $extension = '') : TemporaryFile {
		$file_name = self::generate_temp_name($prefix);
		$tmp_file = new TemporaryFile($file_name);

		// We have to create a second file and track it if an extension was
		// provided because `tempnam` in `generate_temp_name` requires the
		// original file to exist and be present for generating subsequent
		// unique file names
		if($extension !== ''){
			$extension = ltrim($extension, '.');
			$tmp_file_ext = new TemporaryFile($tmp_file->get_absolute_path() . '.' . $extension);
			self::$resource_register->add($tmp_file_ext);
		}

		// Register the pre-generated file after the extension file to avoid
		// a cleanup race-condition with multiple simultaneous requests
		// using `tempnam`
		self::$resource_register->add($tmp_file);
		return $tmp_file_ext ?? $tmp_file;
	}

	/**
	 * Takes a file handle and copies the file for use as a
	 * temporary resource that can be cleaned up without deleting or affecting
	 * the original file
	 *
	 * @param FileHandle $file The file handle to copy a file from
	 * @return TemporaryFile A copy of the file as a TemporaryFile
	 * @throws InfrastructureErrorException|UnableToCreateTmpFileException|UnableToOpenFileException On
	 * errors generating the temporary file
	 */
	public static function copy(FileHandle $file) : TemporaryFile {
		if(!$file->file_exists()){
			ErrorLogger::log_error('Error copying file, file does not exists. File path: '. $file->get_absolute_path());
			throw new InfrastructureErrorException();
		}

		$new_file = self::get('copy_');
		$file->copy($new_file);
		return $new_file;
	}

	/**
	 * Take a given file path, add it to the register for cleanup, and return it
	 * wrapped in the TemporaryFile utility
	 *
	 * @param string $file_path Absolute path to the file
	 * @return TemporaryFile The file as a utility
	 * @throws UnableToOpenFileException On errors generating the temporary file
	 */
	public static function register(string $file_path) : TemporaryFile {
		$file = new TemporaryFile($file_path);
		self::$resource_register->add($file);
		return $file;
	}

	/**
	 * Take a list of given file paths, add them to the register for cleanup,
	 * and return the list of files converted into TemporaryFiles
	 *
	 * @param string[] $file_paths The list of files to register
	 * @return TemporaryFile[] The files as TemporaryFile resources
	 * @throws UnableToOpenFileException On errors generating the temporary files
	 */
	public static function register_list(array $file_paths) : array {
		$ret = [];
		foreach($file_paths as $file){
			$ret[] = self::register($file);
		}
		return $ret;
	}

	/**
	 * Utility for generating temporary file names in the temporary file
	 * directory specified in the file_path configs
	 *
	 * @param string $prefix A prefix to add to the file name that is randomly
	 * generated
	 * @return string The generated absolute file path
	 * @throws UnableToCreateTmpFileException On errors generating the temporary file
	 */
	private static function generate_temp_name(string $prefix) : string {
		$name = tempnam($GLOBALS['file_paths']['tmp_path'], $prefix);
		if($name === false){
			throw new UnableToCreateTmpFileException();
		}
		return $name;
	}

	/**
	 * Initialize the temporary resource register for use
	 *
	 * <p><i>This is done on file-load and should not be called again</i></p>
	 */
	public static function init() : void {
		self::$resource_register = new TemporaryResourceRegister();
	}

}

// Do initialization when this file is loaded
TemporaryFileGenerator::init();

