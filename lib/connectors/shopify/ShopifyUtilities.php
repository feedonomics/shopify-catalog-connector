<?php
namespace ShopifyConnector\connectors\shopify;

/**
 * Common utilities for Shopify tasks
 */
final class ShopifyUtilities
{

	/**
	 * Validate and reassemble a Shopify URL
	 *
	 * @param array $parsedUrl The result of {@see parse_url()}
	 * @return string The validated and reassembled URL
	 */
	public static function unparseUrl(array $parsedUrl) : string
	{
		$scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
		$host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
		$port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
		$user = isset($parsedUrl['user']) ? $parsedUrl['user'] : '';
		$pass = isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass'] : '';
		$pass = ($user || $pass) ? "$pass@" : '';
		$path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
		$query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
		$fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';
		return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
	}

}
