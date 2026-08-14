<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantCapabilityCatalog.php';

use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;

$catalog = new MobileMerchantCapabilityCatalog();
$failed = 0;
$assert = static function (string $id, bool $ok) use (&$failed): void {
    if ($ok) { echo "PASS {$id}\n"; return; }
    $failed++; fwrite(STDERR, "FAIL {$id}\n");
};

$features = $catalog->features();
$codes = array_column($features, 'feature_code');
$assert('MOBILE-CATALOG-01', $catalog->allRuleIds() === [
    401100, 401200, 401300, 401001, 401002, 401003, 401004, 401005, 401006, 401007, 401008,
]);
$assert('MOBILE-CATALOG-02', $codes === [
    'mobile.merchant.workbench', 'mobile.merchant.reservations', 'mobile.merchant.customer_care', 'mobile.merchant.customers',
    'mobile.merchant.customer_settings', 'mobile.merchant.goals', 'mobile.merchant.warehouse', 'mobile.merchant.profile',
]);
$assert('MOBILE-CATALOG-03', count($catalog->menuTree()) === 3 && count($catalog->menuTree()[1]['children']) === 3);
$assert('MOBILE-CATALOG-04', $catalog->normalizeRuleIds([401004, 401200, 401003, 401004]) === [401004, 401200, 401003]);
try {
    $catalog->normalizeRuleIds([999999]);
    $strictRejectsUnknown = false;
} catch (InvalidArgumentException $exception) {
    $strictRejectsUnknown = true;
}
$assert('MOBILE-CATALOG-05', $strictRejectsUnknown);
$assert('MOBILE-CATALOG-06', $catalog->availableActions([401002, 401006], 'STORES') === [
    'merchant.context.bootstrap', 'merchant.context.switch', 'merchant.session.logout', 'merchant.self.participation.read', 'merchant.self.performance.read',
    'RESERVATION_VIEW', 'RESERVATION_CREATE', 'RESERVATION_MANAGE', 'TARGET_PERSONAL_VIEW', 'TARGET_PERSONAL_MANAGE', 'TARGET_TEAM_VIEW',
]);
$assert('MOBILE-CATALOG-07', $catalog->availableActions([401006], 'PERSONAL_SELF') === [
    'merchant.context.bootstrap', 'merchant.context.switch', 'merchant.session.logout', 'merchant.self.participation.read', 'merchant.self.performance.read',
    'TARGET_PERSONAL_VIEW', 'TARGET_PERSONAL_MANAGE',
]);

$session = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php');
$resolver = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php');
$assert('MOBILE-CATALOG-08', is_string($session) && is_string($resolver)
    && str_contains($session, 'MobileMerchantCapabilityCatalog::class')
    && str_contains($resolver, 'MobileMerchantCapabilityCatalog::class')
    && !str_contains($session, "Db::name('system_menus')")
    && !str_contains($resolver, "Db::name('system_menus')"));

exit($failed === 0 ? 0 : 1);
