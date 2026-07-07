<?php

namespace ShopifyConnector\connectors\shopify\models;

enum ShopifyType: string
{
	case PRODUCT = 'Product';
	case PRODUCT_VARIANT = 'ProductVariant';
	case MARKET_CATALOG = 'MarketCatalog';
	case METAFIELD = 'Metafield';
	case COLLECTION = 'Collection';
	case MEDIA_IMAGE = 'MediaImage';
	case VIDEO = 'Video';
	case EXTERNAL_VIDEO = 'ExternalVideo';
	case MODEL_3D = 'Model3d';
	case TRANSLATION = 'Translation';
	case INVENTORY_LEVEL = 'InventoryLevel';
	case INVENTORY_ITEM = 'InventoryItem';
	case PUBLICATION = 'Publication';
	case LOCATION = 'Location';
	case UNKNOWN = 'Unknown';
}
