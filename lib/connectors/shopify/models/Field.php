<?php

namespace ShopifyConnector\connectors\shopify\models;

/**
 * Enums for centralizing field names in Shopify imports
 */
enum Field: string
{
	case MARKETS = 'markets';
	case PUBLICATIONS = 'publications';
	case VARIANT_PUBLISHED_ONLINE_STORE = 'variant_published_online_store';
}
