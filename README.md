# WooCommerce supplier price preview

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-supplier-price-preview/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-supplier-price-preview/actions/workflows/checks.yml)

A supplier CSV can contain duplicates, unknown items, and SKUs that differ only by leading zeros. This sample previews price changes and flags the rows that need review before products are updated.

The [CSV parser](src/class-input.php) keeps SKU values as text. The [catalogue lookup](src/class-catalog.php) matches exact SKUs, and the [preview service](src/class-importer.php) shows the current price, proposed price, and row status. [The sample CSV](examples/supplier-prices.csv) uses synthetic products and prices in Iranian rials.

| Input | Preview result |
| --- | --- |
| 0007 and 7 | Match two distinct WooCommerce products |
| DEMO-DUP on two lines | Both rows are flagged |
| NO-SUCH-SKU | Row is flagged as unmatched |

## Run the integration fixture

Use disposable single-site WordPress with WooCommerce active and <code>WP_ENVIRONMENT_TYPE</code> set to <code>local</code>. Run with <code>CODEXAWP_TEST_WP_LOAD</code> set to that installation's absolute <code>wp-load.php</code> path.

    CODEXAWP_TEST_WP_LOAD=/path/to/wp-load.php php tests/check.php

The fixture populates a test catalogue and uses the same preview records as the plugin. It checks that regular product prices stay unchanged after previewing. Five checks passed with WordPress 7.1.3, WooCommerce 11.1.2, and PHP 8.2.12.

[Recorded integration output](docs/verification.txt) covers exact SKU matching, duplicates and unmatched rows.

## Source and license

This portfolio sample is extracted from Supplier Price Import for WooCommerce 1.0.0. The CSV cell adapter accepts text only, and the included methods cover the preview workflow. Licensed under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
