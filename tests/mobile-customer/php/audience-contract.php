<?php
declare(strict_types=1);

namespace app\services\query {
    final class UnifiedQueryJson {
        public static function encode($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    }
}
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace think\facade { final class Db {} }

namespace {
    require dirname(__DIR__, 3) . '/后端代码/app/services/mobile/customer/MobileCustomerAudienceServices.php';

    use app\services\mobile\customer\MobileCustomerAudienceServices;
    use think\exception\ValidateException;

    $failed = 0;
    $assert = static function (string $name, bool $condition) use (&$failed): void {
        if ($condition) {
            echo "PASS {$name}\n";
            return;
        }
        $failed++;
        fwrite(STDERR, "FAIL {$name}\n");
    };
    $service = new MobileCustomerAudienceServices();
    $method = new \ReflectionMethod(MobileCustomerAudienceServices::class, 'command');
    $validRule = [
        'page_code' => 'member_list',
        'filters' => [],
        'filter_relation' => 'all',
        'permission_must_be_injected_before_calculation' => true,
    ];
    $created = $method->invoke($service, [
        'idempotencyKey' => 'customer-audience-create-001',
        'name' => '本月到店客户',
        'validatedRule' => $validRule,
    ], true, false);
    $assert('MC-AUD-01', $created['operation'] === 'CREATE'
        && $created['ruleDigest'] === hash('sha256', json_encode($validRule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    $badPageRejected = false;
    $missingVersionRejected = false;
    $badKeyRejected = false;
    try {
        $method->invoke($service, [
            'idempotencyKey' => 'customer-audience-create-002',
            'name' => '错误规则',
            'validatedRule' => ['page_code' => 'other_page'],
        ], true, false);
    } catch (\ReflectionException $exception) {
        throw $exception;
    } catch (\Throwable $exception) {
        $badPageRejected = $exception->getPrevious() instanceof ValidateException || $exception instanceof ValidateException;
    }
    try {
        $method->invoke($service, [
            'idempotencyKey' => 'customer-audience-update-001',
            'name' => '缺少版本',
            'validatedRule' => $validRule,
        ], false, false);
    } catch (\Throwable $exception) {
        $missingVersionRejected = $exception->getPrevious() instanceof ValidateException || $exception instanceof ValidateException;
    }
    try {
        $method->invoke($service, [
            'idempotencyKey' => 'short',
            'name' => '错误键',
            'validatedRule' => $validRule,
        ], true, false);
    } catch (\Throwable $exception) {
        $badKeyRejected = $exception->getPrevious() instanceof ValidateException || $exception instanceof ValidateException;
    }
    $assert('MC-AUD-02', $badPageRejected && $missingVersionRejected && $badKeyRejected);

    $upgrade = dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-手机端客户动态客群';
    $apply = (string)file_get_contents($upgrade . '/02-正式升级.sql');
    $assert('MC-AUD-03', strpos($apply, 'CREATE TABLE IF NOT EXISTS `eb_mobile_customer_audience`') !== false
        && strpos($apply, 'CREATE TABLE IF NOT EXISTS `eb_mobile_customer_audience_receipt`') !== false
        && strpos($apply, 'UNIQUE KEY `uk_owner_idempotency`') !== false);

    exit($failed === 0 ? 0 : 1);
}
