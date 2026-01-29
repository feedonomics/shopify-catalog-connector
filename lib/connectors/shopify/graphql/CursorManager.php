<?php

namespace ShopifyConnector\connectors\shopify\graphql;

/**
 * Manages cursors for paginated GraphQL queries
 */
class CursorManager
{
	/**
	 * @var array<string, string> Cursor storage
	 */
	private array $cursors = [];

	/**
	 * @param string $cursor_name
	 * @param string $cursor_value
	 * @return void
	 */
	public function set(string $cursor_name, string $cursor_value): void
	{
		$this->cursors[$cursor_name] = $cursor_value;
	}

	/**
	 * @param string $cursor_name
	 * @return string|null
	 */
	public function get(string $cursor_name): ?string
	{
		return $this->cursors[$cursor_name] ?? null;
	}

	/**
	 * @param string $cursor_name
	 * @return bool
	 */
	public function has(string $cursor_name): bool
	{
		return isset($this->cursors[$cursor_name]);
	}

}
