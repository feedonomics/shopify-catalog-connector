<?php

namespace ShopifyConnector\util\file;


/**
 * Security utilities for the file system
 */
class FileSecurityBot {

	/**
	 * Check if the given file handle is in a legal public directory against a
	 * white list of directories we expect files to be in
	 *
	 * @param string $file The file to check
	 * @return bool True if the given file is in a valid/public directory
	 */
	public static function valid_public_file(string $file) : bool {
		$path_to_check = pathinfo($file, PATHINFO_DIRNAME);
		$path_to_check = realpath($path_to_check);

		$safe_paths = array_filter([
			// Get the real path in case configs have `/../` or such
			realpath($GLOBALS['file_paths']['tmp_path']),
		]);

		return in_array($path_to_check, $safe_paths, true);
	}

}

