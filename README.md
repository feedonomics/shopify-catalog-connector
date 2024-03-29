# Shopify Connector

## Description
The Shopify Connector is an open-source project to assist with extracting product listing information from Shopify in a flexible and configurable manner. This will pull information from a Shopify store using a combination of the Rest API and Graphql, and will output that data into a singular CSV.

## Software Requirements
The Shopify connector has the following requirements:
- PHP 8.2
- MariaDB 10.x or higher

## Hardware Requirements
Due to the multi-threaded nature of this repo, multiple servers are recommended to absorb request loads to maximize availability and reduce potential downtime. A setup of one load balancer with two worker servers is a good starting point. The load balancer is recommended to be equivalent to an AWS `t3a.xlarge` and the worker servers are recommended to be equivalent to an AWS `c5.4xlarge`.

## Security Considerations
- Ensure all communication is over SSL, as this repo deals with communicating sensitive info such as API tokens.
- The database only deals with on-demand tables for per-request processing and does not need to be web-accessible, therefore only local connections should be allowed and a secure password should be used.

## Setup
Ansible files are provided for seamless deployment. Before running the provided playbook, please review `./ansible/inventories/shopify-connector.inv.yml` for required setup variables. Once the inventory is set up, the playbook can be run without any extra steps:

```shell
cd ansible
ansible-playbook -i inventories/shopify-connector.inv.yml playbooks/shopify-connector.yml
```

## Flags
The following flags can be provided when calling the Shopify connector to pull data in different ways:

- `file_info[request_type]` _Required_ `string` The request type. Options are `get` to pull the product catalog or `list` to fetch summary information about the shop.
- `file_info[output_compressed]` _Optional_ `bool` Return file as gzip file. Defaults to `false`.
- `connection_info[client]` _Required_ `string` This should always be 'shopify'
- `connection_info[protocol]` _Required_ `string` This should always be 'api'
- `connection_info[oauth_token]` _Required_ `string` The OAuth token to use with API requests
- `connection_info[shop_name]` _Required_ `string` The shop name/code
- `connection_info[data_types]` _Required_ `string` This is a coma seperated string of pull types. Possible values are:
  - products
  - collections
  - inventory_item
  - meta
  - collections_meta
  - inventory_level
- `connection_info[include_presement_prices]` _Optional_ `bool` Flag for whether to pull presentment price information
- `connection_info[use_gmc_transition_id]` _Optional_ `bool` Flag for whether to use GMC transition ID
- `connection_info[metafields_split_columns]` _Optional_ `bool` Flag for whether meta-field data will be output as a JSON blob or split into individual columns
- `connection_info[variant_names_split_columns]` _Optional_ `bool` Flag for whether variant-names data will be output as a JSON blob or split into individual columns
- `connection_info[inventory_level_explode]` _Optional_ `bool` Flag for whether inventory-level data will be output as a JSON blob or split into individual columns
- `connection_info[extra_parent_fields]` _Optional_ `string` This is a coma seperated string of additional product fields that are available on the API that are not pulled by default
- `connection_info[tax_rates]` _Optional_ `string` This is a coma seperated string of tax rate codes to pull
- `connection_info[product_filters]` _Optional_ `string` Filters to apply when pulling products. This is an array of objects as a JSON encoded string. Each object should have a `filter` and `value` key


## Example

```php
<?php
$query_params = http_build_query([
    'file_info' => [
        'request_type' => 'get',
        'output_compressed' => true
    ],
    'connection_info' => [
      'client' => 'shopify',
      'protocol' => 'api',
      'oauth_token' => 'my_token',
      'shop_name' => 'my_shop',
      'data_types' => 'products,collections,inventory_item,meta,collections_meta,inventory_level',
      'include_presentment_prices' => true,
      'product_filters' => json_encode([
          [
              'filter' => 'published_status',
              'value' => 'published'
          ],
      ]),
      'metafields_split_columns' => true,
      'extra_parent_fields' => 'vendor,handle',
      'tax_rates' => 'us,ca',
      'use_gmc_transition_id' => true,
      'variant_names_split_columns' => true,
      'inventory_level_explode' => true
    ],
]);

$curl = curl_init('https://shopify-connector.example?' . $query_params);
curl_exec($curl);
curl_close($curl);
```
