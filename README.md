# WooCommerce supplier price preview

This sample uses a supplier CSV to propose price changes and show which rows need attention before any product updates.

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

## Source and license

This portfolio sample is extracted from Supplier Price Import for WooCommerce 1.0.0. The CSV cell adapter accepts text only, and the included methods cover the preview workflow. Licensed under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
