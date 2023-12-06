<?php

namespace ShopifyConnector\util;


class File_Utilities {

	public static function create_temp_file($dir, $prefix, $keep=false){
		$fn_temp = tempnam($dir, $prefix);
		if($fn_temp !== false && !$keep) {
			register_shutdown_function(function() use ($fn_temp) {
				if( file_exists($fn_temp) ) {
					unlink($fn_temp);
				}
			});
		}
		return $fn_temp;
	}

	public static function fput_delimited($fp, $fields, $delimiter=',', $enclosure='"', $escape_char='"', $strip_characters=array(), $replace = ''){
		// Remove strip_characters
		if ($strip_characters!=array()) {
			$fields = str_replace($strip_characters, $replace, $fields);
		}

		// Escape enclosure/escape_char with the escape_char
		if ($enclosure!='') {
			$enclosure_esc = preg_quote($enclosure, '#');
			$escape_char_esc = preg_quote($escape_char, '#');

			// Backslashes need to be escaped in the preg_replace replacement parameter
			$replace_escape = '';
			if ($escape_char=='\\') {
				$replace_escape='\\';
			}

			// Add the enclosure character to the beginning and end of every field
			foreach ($fields as &$field) {
				if ($field!='') {
					$field =
						$enclosure
						. preg_replace("#([{$enclosure_esc}{$escape_char_esc}])#", $replace_escape.$escape_char.'$1', $field)
						. $enclosure;
				}
			}
		}

		// Write
		fwrite($fp, implode($delimiter,$fields) . "\n");
	}

	public static function download_file($curlopt_url, $curlopt_userpwd = '', $keep = false){

		$target_file_name = pathinfo(parse_url($curlopt_url,PHP_URL_PATH),PATHINFO_BASENAME);
		$target_file_extension = pathinfo(parse_url($curlopt_url,PHP_URL_PATH),PATHINFO_EXTENSION);

		//Create temp infile.
		$fn_in = self::create_temp_file($GLOBALS['file_paths']['tmp_path'], $target_file_name . '_', $keep);
		$fp_in = fopen($fn_in, 'w');
		if ($fp_in===false) {
			throw new \Exception('Could not open tmp file for' . PHP_EOL . $curlopt_url);
		}

		// Download the raw file
		$ch = General_Utilities::get_configured_curl_handle();
		curl_setopt($ch, CURLOPT_URL, $curlopt_url);
		curl_setopt($ch, CURLOPT_FILE, $fp_in);
		curl_setopt($ch, CURLOPT_USERPWD, $curlopt_userpwd);
		curl_exec($ch);
		fclose($fp_in);

		//Handle gzip
		if (strtolower($target_file_extension) == 'gzip'){
			rename($fn_in, "{$fn_in}.gz");
			register_shutdown_function(function() use ($fn_in) {
				if (file_exists("{$fn_in}.gz")) {
					unlink("{$fn_in}.gz");
				}
			});
			exec(sprintf('gunzip -- %s', escapeshellarg("{$fn_in}.gz")));
		}

		//Handle zip (assumes only one file)
		if (strtolower($target_file_extension) == 'zip'){
			$z = new \ZipArchive;
			if($z->open($fn_in) === true) {
				$fn_unzipped = self::create_temp_file($GLOBALS['file_paths']['tmp_path'], $target_file_name . '_unzipped_', $keep);
				file_put_contents($fn_unzipped,$z->getFromIndex(0));
				$z->close();
				$fn_in = $fn_unzipped;
			}
		}

		return $fn_in;
	}

	/**
	 * @param $handle
	 * @param int $newlines_at_end
	 * @return \Generator
	 */
	public static function rfgets($handle, $newlines_at_end = 1){
		$pos = $newlines_at_end * -1;
		$currentLine = '';

		while (-1 !== fseek($handle, $pos, SEEK_END)) {
			$char = fgetc($handle);
			if (PHP_EOL == $char) {
				yield $currentLine;
				$currentLine = '';
			} else {
				$currentLine = $char . $currentLine;
			}
			$pos--;
		}

		yield $currentLine; // Grab final line
	}

}

