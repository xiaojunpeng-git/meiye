<?php

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\ThinkPhpCustomerCareRepository;
use think\facade\Db;

final class CustomerCareMysqlFixedClock implements CustomerCareClock
{
    /** @var int */
    private $timestamp;

    public function __construct(int $timestamp)
    {
        $this->timestamp = $timestamp;
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    public function businessDate(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }
}

function careMysqlBoot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    c1aBootThinkApp('/var/www/html/');
    $booted = true;
}

function careMysqlEnsureLegacySchema(): void
{
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_employee` (
      `id` int(10) unsigned NOT NULL,
      `status` tinyint(1) NOT NULL DEFAULT '1',
      `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `idx_employee_status_del` (`status`,`is_del`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
      `id` int(10) unsigned NOT NULL,
      `employee_id` int(10) unsigned DEFAULT NULL,
      `store_id` int(10) unsigned NOT NULL DEFAULT '0',
      `staff_name` varchar(64) NOT NULL DEFAULT '',
      `status` tinyint(1) NOT NULL DEFAULT '1',
      `is_del` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `idx_staff_employee_store` (`employee_id`,`store_id`,`is_del`),
      KEY `idx_staff_store_employee` (`store_id`,`employee_id`,`is_del`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    foreach ([101, 102, 900] as $staffId) {
        Db::name('employee')->where('id', $staffId + 1000)->delete();
        Db::name('employee')->insert([
            'id' => $staffId + 1000,
            'status' => 1,
            'is_del' => 0,
        ]);
        Db::name('system_store_staff')->where('id', $staffId)->delete();
        Db::name('system_store_staff')->insert([
            'id' => $staffId,
            'employee_id' => $staffId + 1000,
            'store_id' => 8,
            'staff_name' => '员工' . $staffId,
            'status' => 1,
            'is_del' => 0,
        ]);
    }
}

function careMysqlResetDomain(): void
{
    Db::execute('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'customer_care_operation',
        'customer_care_record',
        'customer_care_task',
        'customer_care_document_sequence',
    ] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('cashier_v3_command_receipt')
        ->whereLike('idempotency_key', 'CARE_RECEIPT-%')
        ->delete();
    Db::execute('SET FOREIGN_KEY_CHECKS=1');
}

function careMysqlService(): CustomerCareCommandService
{
    return new CustomerCareCommandService(
        new ThinkPhpCustomerCareRepository(),
        new CustomerCareMysqlFixedClock(1785286800)
    );
}

function careMysqlUuid(int $sequence): string
{
    return '00000000-0000-4000-8000-' . str_pad((string)$sequence, 12, '0', STR_PAD_LEFT);
}

function careMysqlIdem(string $operation, int $sequence): string
{
    return 'CARE_' . $operation . '-' . careMysqlUuid($sequence);
}

function careMysqlActor(int $staffId = 101, bool $canReassign = true): array
{
    return [
        'tenantId' => 'TENANT_1',
        'staffId' => $staffId,
        'employeeId' => $staffId + 1000,
        'staffName' => '员工' . $staffId,
        'operationStoreId' => 8,
        'operationStoreName' => '八号门店',
        'operationOrganizationId' => 'ORG_8',
        'operationOrganizationPath' => '/ROOT/ORG_8/',
        'operationOrganizationName' => '八号组织',
        'allowedBusinessStoreIds' => [8],
        'canReassign' => $canReassign,
    ];
}

function careMysqlCreateTaskCommand(int $sequence, int $ownerStaffId = 101): array
{
    return [
        'idempotencyKey' => careMysqlIdem('CREATE_TASK', $sequence),
        'taskKey' => 'CARE-MYSQL-TASK-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT),
        'businessStoreId' => 8,
        'businessStoreName' => '八号门店',
        'businessOrganizationId' => 'ORG_8',
        'businessOrganizationPath' => '/ROOT/ORG_8/',
        'businessOrganizationName' => '八号组织',
        'memberId' => 5000 + $sequence,
        'memberName' => '会员' . $sequence,
        'ownerStaffId' => $ownerStaffId,
        'taskType' => 'DAILY_FOLLOWUP',
        'sourceType' => 'MANUAL',
        'sourceId' => 'MYSQL-SOURCE-' . $sequence,
        'title' => '客情任务' . $sequence,
        'note' => '真实仓储测试',
        'plannedAt' => 1785270000 + $sequence,
        'plannedTimezone' => 'Asia/Shanghai',
    ];
}

function careMysqlRecordContent(int $sequence): array
{
    return [
        'recordKey' => 'CARE-MYSQL-RECORD-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT),
        'recordType' => 'FOLLOWUP',
        'followedAt' => 1785283200,
        'followupMethod' => 'PHONE',
        'resultCode' => 'SATISFIED',
        'summary' => '已完成真实仓储回访',
        'detail' => 'MySQL 事务内写入',
        'relatedBusinessType' => 'ORDER',
        'relatedBusinessId' => 'MYSQL-ORDER-' . $sequence,
        'relatedBusinessLabel' => '测试订单' . $sequence,
    ];
}

function careMysqlCreateRecordCommand(int $sequence, int $staffId = 101): array
{
    return array_merge([
        'idempotencyKey' => careMysqlIdem('CREATE_RECORD', $sequence),
        'businessStoreId' => 8,
        'businessStoreName' => '八号门店',
        'businessOrganizationId' => 'ORG_8',
        'businessOrganizationPath' => '/ROOT/ORG_8/',
        'businessOrganizationName' => '八号组织',
        'businessTimezone' => 'Asia/Shanghai',
        'memberId' => 7000 + $sequence,
        'memberName' => '独立记录会员' . $staffId,
    ], careMysqlRecordContent($sequence));
}

function careMysqlReceiptKey(string $tenantId, string $idempotencyKey): string
{
    $hex = hash('sha256', $tenantId . "\0" . $idempotencyKey);
    $hex = strtolower(substr($hex, 0, 32));
    $hex[12] = '4';
    $hex[16] = '8';
    $uuid = substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
    return 'CARE_RECEIPT-' . $uuid;
}

function careMysqlNextTaskIdentity(string $tenantId, string $completionIdempotencyKey): array
{
    $hash = hash('sha256', $tenantId . "\0" . $completionIdempotencyKey . "\0NEXT_TASK");
    return [
        'taskKey' => 'CARE-NEXT-TASK-' . substr($hash, 0, 48),
        'idempotencyKey' => 'CARE_NEXT_TASK-' . substr($hash, 0, 64),
    ];
}
