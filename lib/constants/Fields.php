<?php

namespace ShopifyConnector\constants;

/**
 * Enum for common preprocess fields
 */
enum Fields:string
{
    case PULLED_TIME = '__fdx_pulled_time';
    case UNIQUE_ID = '__uid';
}
