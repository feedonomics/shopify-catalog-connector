<?php

namespace ShopifyConnector\connectors\shopify\models;

/**
 * Enums for centralizing field names in Shopify imports
 */
enum Field: string
{
	case MARKETS = 'markets';
	case PUBLICATIONS = 'publications';
}
