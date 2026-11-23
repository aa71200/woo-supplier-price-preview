<?php
declare(strict_types=1);

namespace SupplierPriceImport;

require __DIR__ . '/bootstrap.php';
\portfolio_require_woocommerce();
// These capabilities are defined by the full plugin bootstrap.
const APPLY = 'spifw_apply_prices';
const MAP = 'spifw_manage_mappings';
const HISTORY = 'spifw_view_history';

$src = dirname(__DIR__) . '/src';
require "$src/class-store.php";
require "$src/class-input.php";
require "$src/class-catalog.php";
require "$src/class-importer.php";
Store::activate(false);
wp_set_current_user(\portfolio_user('administrator'));
$previousCurrency = get_option('woocommerce_currency');
update_option('woocommerce_currency', 'IRR');

try {
    $products = [];
    foreach (['0007' => 100000, '7' => 200000, 'DEMO-DUP' => 300000] as $sku => $price) {
        $product = new \WC_Product_Simple(wc_get_product_id_by_sku((string) $sku));
        $product->set_name("Demo SKU $sku");
        $product->set_sku((string) $sku);
        $product->set_status('publish');
        $product->set_regular_price((string) $price);
        $product->set_sale_price('95000');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(7);
        $products[(string) $sku] = $product->save();
    }
    $profile = Importer::save_profile([
        'name' => 'Synthetic Supplier', 'code' => 'SKU', 'price' => 'Price',
        'unit' => 'IRR', 'match' => 'sku',
    ])['id'];
    $rows = Input::csv(file_get_contents(dirname(__DIR__) . '/examples/supplier-prices.csv'), ',', 20);
    $quoted = Input::csv("\xEF\xBB\xBFSKU,Price\r\n\"00,07\",\"112,000\"\r\n", ',', 2);
    \portfolio_check($quoted[1] === ['SKU', 'Price'] && $quoted[2] === ['00,07', '112,000'], 'UTF-8 BOM and quoted values are preserved');
    foreach ([
        'malformed quoted row' => static fn() => Input::csv("SKU,Price\n\"unfinished,8", ',', 20),
        'unsupported currency' => static fn() => Input::unit('USD'),
        'fractional toman' => static fn() => Input::price('11', 'IRR', 'IRT'),
        'row limit' => static fn() => Input::csv("SKU,Price\nA,1\nB,2\n", ',', 1),
    ] as $label => $invalid) {
        try {
            $invalid();
            throw new \RuntimeException("Expected rejection: $label");
        } catch (Problem $problem) {
            \portfolio_check(true, "Rejects $label");
        }
    }
    \portfolio_check(Input::price('112,000', 'IRR', 'IRT') === '11200' && Input::price('11200', 'IRT', 'IRR') === '112000', 'Rial and toman convert exactly in both directions');
    $header = Input::columns($rows);
    $preview = Importer::preview_prices(
        $profile, ['base' => 'IRR', 'unit' => 'IRR'], $rows,
        Importer::position($header, 'SKU'), Importer::position($header, 'Price')
    )['rows'];
    \portfolio_check($preview[2]['product'] === $products['0007'] && $preview[3]['product'] === $products['7'], 'Leading zeros are retained and match distinct WooCommerce products');
    \portfolio_check($preview[2]['before'] === '100000' && $preview[2]['after'] === '112000' && $preview[2]['state'] === 'ready', 'Preview records the current and proposed price');
    \portfolio_check($preview[4]['state'] === 'invalid' && $preview[5]['state'] === 'invalid', 'Both duplicate input rows are flagged');
    \portfolio_check($preview[6]['state'] === 'invalid' && !isset($preview[6]['product']), 'An unmatched SKU is flagged without guessing');
    \portfolio_check(
        wc_get_product($products['0007'])->get_regular_price('edit') === '100000'
        && wc_get_product($products['7'])->get_regular_price('edit') === '200000'
        && wc_get_product($products['DEMO-DUP'])->get_regular_price('edit') === '300000',
        'Preview leaves every regular product price unchanged'
    );
    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        \portfolio_check('95000' === $product->get_sale_price('edit') && 7 === (int) $product->get_stock_quantity('edit'), 'Preview preserves sale price and stock');
    }
    foreach ($preview as $row) {
        printf("%s | %s | %s → %s IRR\n", $row['code'], $row['state'], $row['before'] ?? '-', $row['after'] ?? '-');
    }
} finally {
    update_option('woocommerce_currency', $previousCurrency);
}
