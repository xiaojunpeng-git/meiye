<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use think\facade\Db;

/**
 * Finds project-replacement dependencies before the Gateway acquires locks.
 * The browser submits only the holder and requested source detail ids.  The
 * current benefit-pool versions and the target SKU resources are always read
 * from server authority, so a page cannot substitute another member's right
 * or an out-of-store project.
 */
final class CashierV3CardOperationResourceDiscovery
{
    public const CONTRACT_VERSION = 'cashier-v3-card-operation-discovery-v1';

    /** @var CashierV3SaleCatalogServices */
    private $catalog;

    public function __construct(?CashierV3SaleCatalogServices $catalog = null)
    {
        $this->catalog = $catalog ?: new CashierV3SaleCatalogServices();
    }

    /** @return array{contractVersion:string,resources:array} */
    public function discover(array $scope): array
    {
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::failure('card_operation_discovery_scope_missing');
        }
        $type = trim((string)($payload['operationType'] ?? ''));
        if ($type === CashierV3CardOperationKernel::TYPE_CARD_UPGRADE) {
            $holderId = self::positiveId($payload['sourceCardHolderId'] ?? null, 'source_card_missing');
            $version = self::positiveId($payload['sourceCardHolderVersion'] ?? null, 'source_card_version_missing');
            $targetSkuId = self::positiveId($payload['targetCatalogId'] ?? null, 'target_card_missing');
            $resources = [[
                'kind' => 'card_holder',
                'id' => (string)$holderId,
                'expectedVersion' => $version,
                'roles' => ['source_card_discovery'],
                'accessMode' => 'read',
                'providerContractVersion' => 'cashier-v3-card-operation-v1',
                'authorityFingerprint' => hash('sha256', 'card_holder|' . $holderId . '|' . $version),
            ]];
            foreach ($this->catalog->discoverItemResources($targetSkuId, $operator, $dataScope) as $resource) {
                $resource['roles'] = ['target_card_' . (string)$resource['kind'] . ':' . (string)$resource['id']];
                $resources[] = $resource;
            }
            return ['contractVersion' => self::CONTRACT_VERSION, 'resources' => $resources];
        }
        if (!in_array($type, [
            CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT,
            CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
        ], true)) {
            $holderId = self::positiveId($payload['sourceCardHolderId'] ?? null, 'source_card_missing');
            // Direct holder operations have no hidden dependency. Their one
            // client context remains the normal Gateway version contract;
            // duplicating it only lets the same stale version reach the
            // provider and produce the canonical resource-conflict response.
            $version = self::positiveId($payload['sourceCardHolderVersion'] ?? null, 'source_card_version_missing');
            return ['contractVersion' => self::CONTRACT_VERSION, 'resources' => [[
                'kind' => 'card_holder',
                'id' => (string)$holderId,
                'expectedVersion' => $version,
                'roles' => ['source_card_discovery'],
                'accessMode' => 'read',
                'providerContractVersion' => 'cashier-v3-card-operation-v1',
                'authorityFingerprint' => hash('sha256', 'card_holder|' . $holderId . '|' . $version),
            ]]];
        }

        $holderId = self::positiveId($payload['sourceCardHolderId'] ?? null, 'source_card_missing');
        $holder = (array)Db::name('user_card_holder')
            ->where('id', $holderId)
            ->where('is_del', 0)
            ->field('id,oid,uid,store_id')
            ->find();
        if (!$holder || (int)($holder['store_id'] ?? 0) !== $operator->storeId()) {
            throw self::notFound('source_card_not_available');
        }

        $detailIds = [];
        foreach ((array)($payload['projectLines'] ?? []) as $line) {
            if (!is_array($line)) {
                throw self::invalid('project_line_invalid');
            }
            $detailIds[self::positiveId($line['sourceDetailId'] ?? null, 'source_project_detail_missing')] = true;
        }
        if (!$detailIds) {
            throw self::invalid('project_source_lines_missing');
        }
        $ids = array_keys($detailIds);
        sort($ids, SORT_NUMERIC);
        $rows = Db::name('store_order_cart_info')
            ->where('oid', (int)$holder['oid'])
            ->whereIn('id', $ids)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('id')
            ->select();
        $found = [];
        foreach (self::rows($rows) as $row) {
            $found[(int)$row['id']] = true;
        }
        if (count($found) !== count($ids)) {
            throw CashierV3CommandException::versionConflict(
                '卡内项目已经变化，请重新打开后选择。',
                ['reason' => 'project_source_not_current']
            );
        }

        $versions = Db::name('cashier_v3_entitlement_resource_version')
            ->where('resource_kind', 'member_benefit_pool')
            ->whereIn('resource_id', array_map('strval', $ids))
            ->field('resource_id,current_version,source_fingerprint')
            ->select();
        $versionMap = [];
        foreach (self::rows($versions) as $version) {
            $id = (int)($version['resource_id'] ?? 0);
            if ($id > 0 && (int)($version['current_version'] ?? 0) > 0) {
                $versionMap[$id] = $version;
            }
        }
        if (count($versionMap) !== count($ids)) {
            throw CashierV3CommandException::versionConflict(
                '卡内项目版本尚未同步，请重新打开使用权益后再办理。',
                ['reason' => 'project_source_version_missing']
            );
        }

        $targetSkuId = self::positiveId($payload['targetCatalogId'] ?? null, 'target_project_missing');
        $target = (array)Db::name('store_product_attr_value')->alias('sku')
            ->join('store_product product', 'product.id=sku.product_id')
            ->where('sku.id', $targetSkuId)
            ->where('sku.type', 0)
            ->where('sku.is_show', 1)
            ->where('product.type', 1)
            ->where('product.relation_id', $operator->storeId())
            ->where('product.product_type', 6)
            ->where('product.is_del', 0)
            ->where('product.is_show', 1)
            ->where('product.is_verify', 1)
            ->field('sku.id,product.id AS product_id')
            ->find();
        if (!$target) {
            throw self::notFound('target_project_not_available');
        }

        $resources = [];
        foreach ($ids as $id) {
            $version = $versionMap[$id];
            $resources[] = [
                'kind' => 'member_benefit_pool',
                'id' => (string)$id,
                'expectedVersion' => (int)$version['current_version'],
                'roles' => ['source_project:' . $id],
                'accessMode' => 'mutate',
                'providerContractVersion' => 'cashier-v3-entitlement-resource-v1',
                'authorityFingerprint' => self::fingerprint($version),
            ];
        }
        foreach ($this->catalog->discoverItemResources($targetSkuId, $operator, $dataScope) as $resource) {
            $resource['roles'] = ['target_project_' . (string)$resource['kind'] . ':' . (string)$resource['id']];
            $resources[] = $resource;
        }
        return ['contractVersion' => self::CONTRACT_VERSION, 'resources' => $resources];
    }

    private static function fingerprint(array $row): string
    {
        return hash('sha256', implode('|', [
            (string)($row['resource_id'] ?? ''),
            (string)($row['current_version'] ?? ''),
            (string)($row['source_fingerprint'] ?? ''),
        ]));
    }

    private static function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private static function positiveId($value, string $reason): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::invalid($reason);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::invalid($reason);
        }
        return (int)$raw;
    }

    private static function invalid(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '卡操作资料无效，请刷新后重新填写。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function notFound(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '原卡权益或目标项目不可用，请刷新后重新选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '卡操作资源发现未完成，请稍后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
