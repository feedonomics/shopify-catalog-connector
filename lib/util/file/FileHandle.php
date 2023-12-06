<?php

namespace ShopifyConnector\util\file;

use ShopifyConnector\exceptions\file\UnableToOpenFileException;
use ShopifyConnector\log\ErrorLogger;


/**
 * Wrapper and utilities for managing a local file
 */
class FileHandle {

	/**
	 * @var string Store for the file's absolute path
	 */
	private string $file_path;

	/**
	 * Bind the given file path to this file handle manager
	 *
	 * @param string $file_path The absolute path to the file
	 * @throws UnableToOpenFileException If the given file is not in a whitelisted
	 * path (see {@see FileSecurityBot::valid_public_file()})
	 */
	public function __construct(string $file_path){
		$pi = pathinfo($file_path);
		$dir = realpath($pi['dirname']);
		$this->file_path = "{$dir}/{$pi['basename']}";

		if(!FileSecurityBot::valid_public_file($this->file_path)){
			ErrorLogger::log_error("Attempted to wrap an illegal file in the FileHandle. File path: {$file_path}");
			throw new UnableToOpenFileException();
		}
	}

	/**
	 * Get the file path
	 *
	 * @return string The absolute path to the file
	 */
	public function get_absolute_path() : string {
		return $this->file_path;
	}

	/**
	 * Check if the file physically exists on the drive
	 *
	 * @return bool True if the file exists
	 */
	public function file_exists() : bool {
		return file_exists($this->file_path);
	}

	/**
	 * Rename/move the file
	 *
	 * @param FileHandle $new_file The path to move the file to
	 */
	public function move(FileHandle $new_file) : void {
		if($this->file_exists()){
			rename($this->file_path, $new_file->get_absolute_path());
		}
		$this->file_path = $new_file->get_absolute_path();
	}

	/**
	 * Delete the file
	 */
	public function delete() : void {
		if($this->file_exists()){
			unlink($this->file_path);
		}
	}

	/**
	 * Empty a file of its contents
	 */
	public function empty() : void {
		if($this->file_exists()){
			file_put_contents($this->file_path, '');
		}
	}

	/**
	 * Copy the current file to the given file handle
	 *
	 * @param FileHandle $copy_to The file handle for where to copy this file's
	 * contents to
	 */
	public function copy(FileHandle $copy_to) : void {
		if($this->file_exists()){
			copy($this->file_path, $copy_to->get_absolute_path());
		}
	}

}

