<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    $wp = getenv('CODEXAWP_TEST_WP_LOAD');
    if (!$wp || !is_file($wp) || basename($wp) !== 'wp-load.php') {
        throw new RuntimeException('Set CODEXAWP_TEST_WP_LOAD to wp-load.php on a disposable local WordPress installation.');
    }
    require_once $wp;
}
if (PHP_SAPI !== 'cli' || wp_get_environment_type() !== 'local' || is_multisite()) {
    throw new RuntimeException('Run this fixture from the CLI on disposable local single-site WordPress.');
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn() => new WP_Error('portfolio_offline', 'Outbound HTTP is disabled in this fixture.'));

function portfolio_check(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}

function portfolio_user(string $role = 'subscriber'): int {
    $login = 'portfolio-' . bin2hex(random_bytes(8));
    $id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $login . '@example.test',
        'user_pass' => wp_generate_password(24),
        'role' => $role,
    ]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    return $id;
}

function portfolio_require_woocommerce(): void {
    if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
        throw new RuntimeException('Activate WooCommerce on the disposable test installation first.');
    }
}
