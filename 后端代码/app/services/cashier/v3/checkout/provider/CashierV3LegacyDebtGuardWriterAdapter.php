<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Bridges legacy debt writers to the C2 entitlement debt guard.
 *
 * Legacy debt/order tables are one-database-per-tenant and have no tenant_id.
 * The adapter therefore fixes tenant scope to the server-owned legacy tenant
 * constant, derives store/operator hints from the origin order, and asks the
 * provider to re-lock and revalidate the authoritative order after the guard.
 */
final class CashierV3LegacyDebtGuardWriterAdapter
{
    private const PATHS = ['create', 'repay', 'adjustment'];

    /** @var CashierV3EntitlementDebtGuardProvider */
    private $provider;

    public function __construct(CashierV3EntitlementDebtGuardProvider $provider = null)
    {
        $this->provider = $provider ?: new CashierV3EntitlementDebtGuardProvider();
    }

    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER;
    }

    public function readinessStatus(): array
    {
        $provider = $this->provider->readinessStatus();
        $legacy = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_store_order' => [
                'id', 'order_id', 'uid', 'store_id', 'staff_id', 'clerk_id',
                'debt_amount', 'repaid_debt_amount', 'order_type', 'link_id',
                'source', 'gendan_staff_id', 'is_gendan',
            ],
            'eb_store_debt' => [
                'id', 'order_id', 'order_sn', 'uid', 'store_id', 'total_debt',
                'repaid_debt', 'status', 'staff_id', 'remark', 'update_time',
            ],
            'eb_store_debt_item' => [
                'id', 'debt_id', 'order_id', 'cart_info_id', 'product_id',
                'product_type', 'product_name', 'cart_num', 'debt_amount',
                'repaid_debt', 'add_time', 'update_time',
            ],
            'eb_store_debt_repay' => [
                'id', 'repay_no', 'debt_id', 'debt_item_id', 'order_id',
                'order_sn', 'repay_order_id', 'uid', 'repay_amount', 'pay_type',
                'pay_store_id', 'debt_store_id', 'staff_id', 'combination_info',
                'add_time',
            ],
        ]);
        $ready = !empty($provider['ready']) && !empty($legacy['ready']);
        return [
            'dependency' => 'legacy_debt_guard_writer',
            'contractVersion' => $this->contractVersion(),
            'ready' => $ready,
            'reasons' => $ready ? [] : ['legacy_debt_guard_writer_schema_not_ready'],
            'provider' => $provider,
            'legacySchema' => $legacy,
            'integratedPaths' => $ready ? $this->pathContracts() : [],
            'gatewayActivationPerformed' => false,
        ];
    }

    /**
     * This token is consumed by CashierV3LegacyDebtGuardIntegrationProbe only
     * after the focused suite has executed all three real writer paths.
     */
    public function pathContracts(): array
    {
        return [
            'create' => $this->contractVersion(),
            'repay' => $this->contractVersion(),
            'adjustment' => $this->contractVersion(),
        ];
    }

    public function lockOriginOrderInTx(int $originOrderId): CashierV3LegacyDebtMutationToken
    {
        CashierV3TransactionGuard::assertInTransaction('legacyDebtWriterOriginGuard');
        $hint = $this->originOrder($originOrderId, false);
        if ($hint === null) {
            throw self::failure('legacy_debt_origin_order_not_found');
        }
        $storeId = (int)($hint['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw self::failure('legacy_debt_origin_store_invalid');
        }
        [$operatorScope, $dataScope] = $this->serverScopes($hint);
        $snapshot = $this->provider->lockOrCreateSnapshotInTx(
            $originOrderId,
            $operatorScope,
            $dataScope
        );
        $locked = $this->originOrder($originOrderId, true);
        if ($locked === null) {
            throw self::failure('legacy_debt_origin_order_disappeared');
        }
        if ((int)$locked['store_id'] !== $storeId
            || (int)$snapshot['originStoreId'] !== $storeId) {
            throw self::failure('legacy_debt_origin_store_changed');
        }
        return new CashierV3LegacyDebtMutationToken(
            $originOrderId,
            $storeId,
            (int)$snapshot['version'],
            $locked,
            $operatorScope,
            $dataScope
        );
    }

    /** @return array{token:CashierV3LegacyDebtMutationToken,debt:array} */
    public function lockDebtInTx(int $debtId): array
    {
        CashierV3TransactionGuard::assertInTransaction('legacyDebtWriterDebtGuard');
        $hint = Db::name('store_debt')->where('id', $debtId)->field('id,order_id')->find();
        if (is_object($hint) && method_exists($hint, 'toArray')) {
            $hint = $hint->toArray();
        }
        if (!is_array($hint) || (int)($hint['order_id'] ?? 0) <= 0) {
            throw self::failure('legacy_debt_record_not_found');
        }
        $token = $this->lockOriginOrderInTx((int)$hint['order_id']);
        $debt = $this->lockDebtForTokenInTx($token, $debtId);
        return compact('token', 'debt');
    }

    /** @return array{token:CashierV3LegacyDebtMutationToken,debt:?array} */
    public function lockDebtByOriginOrderInTx(int $originOrderId): array
    {
        $token = $this->lockOriginOrderInTx($originOrderId);
        $debt = Db::name('store_debt')
            ->where('order_id', $originOrderId)
            ->order('id asc')
            ->lock(true)
            ->find();
        if (is_object($debt) && method_exists($debt, 'toArray')) {
            $debt = $debt->toArray();
        }
        if (is_array($debt) && $debt) {
            $this->assertDebtRelation($token, $debt);
        } else {
            $debt = null;
        }
        return compact('token', 'debt');
    }

    public function lockDebtForTokenInTx(
        CashierV3LegacyDebtMutationToken $token,
        int $debtId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('legacyDebtWriterDebtRelock');
        $debt = Db::name('store_debt')->where('id', $debtId)->lock(true)->find();
        if (is_object($debt) && method_exists($debt, 'toArray')) {
            $debt = $debt->toArray();
        }
        if (!is_array($debt) || !$debt) {
            throw self::failure('legacy_debt_record_not_found');
        }
        $this->assertDebtRelation($token, $debt);
        return $debt;
    }

    public function beginMutationInTx(
        CashierV3LegacyDebtMutationToken $token,
        string $path,
        string $mutationKey,
        string $requestFingerprint,
        string $action
    ): array {
        $this->assertPath($path);
        $prepared = $this->provider->lockMutationReplayInTx(
            $token->originOrderId(),
            $mutationKey,
            $requestFingerprint,
            $action,
            $token->operatorScope(),
            $token->dataScope()
        );
        if (!$prepared['idempotentReplay']
            && (int)$prepared['currentVersion'] !== $token->guardVersion()) {
            throw self::failure('legacy_debt_guard_token_stale');
        }
        $prepared['path'] = $path;
        $prepared['mutationKey'] = $mutationKey;
        $prepared['requestFingerprint'] = $requestFingerprint;
        $prepared['action'] = $action;
        return $prepared;
    }

    public function completeMutationInTx(
        CashierV3LegacyDebtMutationToken $token,
        array $prepared
    ): array {
        if (!empty($prepared['idempotentReplay'])) {
            return $prepared;
        }
        $this->assertPath((string)($prepared['path'] ?? ''));
        return $this->provider->advanceAfterDebtMutationInTx(
            $token->originOrderId(),
            (int)($prepared['currentVersion'] ?? 0),
            (string)($prepared['mutationKey'] ?? ''),
            (string)($prepared['requestFingerprint'] ?? ''),
            (string)($prepared['action'] ?? ''),
            $token->operatorScope(),
            $token->dataScope()
        );
    }

    public function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function originOrder(int $originOrderId, bool $lock): ?array
    {
        if ($originOrderId <= 0) {
            return null;
        }
        $query = Db::name('store_order')
            ->where('id', $originOrderId)
            ->field(
                'id,order_id,uid,store_id,staff_id,clerk_id,debt_amount,'
                . 'repaid_debt_amount,order_type,link_id,source,gendan_staff_id,is_gendan'
            );
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private function serverScopes(array $originOrder): array
    {
        $storeId = (int)($originOrder['store_id'] ?? 0);
        $operatorId = (int)($originOrder['staff_id'] ?? 0);
        if ($operatorId <= 0) {
            $operatorId = (int)($originOrder['clerk_id'] ?? 0);
        }
        if ($operatorId <= 0) {
            // Fixed internal actor for system callbacks; never sourced from a request.
            $operatorId = 1;
        }
        $tenantId = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $operatorScope = new CashierV3OperatorScope($storeId, $operatorId, '', $tenantId);
        $dataScope = new CashierV3DataScopeContext(
            $operatorId,
            0,
            $storeId,
            $tenantId,
            '',
            [$storeId],
            CashierV3DataScopeContext::MODE_STORES,
            ['mode' => 'stores', 'store_ids' => [$storeId], 'source' => 'locked_origin_order'],
            false,
            '',
            'legacy-debt-order:' . (int)$originOrder['id'] . ':store:' . $storeId,
            [],
            ['source' => 'locked_origin_order', 'origin_order_id' => (int)$originOrder['id']]
        );
        return [$operatorScope, $dataScope];
    }

    private function assertDebtRelation(CashierV3LegacyDebtMutationToken $token, array $debt): void
    {
        $order = $token->originOrder();
        if ((int)($debt['id'] ?? 0) <= 0
            || (int)($debt['order_id'] ?? 0) !== $token->originOrderId()
            || (int)($debt['store_id'] ?? 0) !== $token->originStoreId()
            || (int)($debt['uid'] ?? 0) !== (int)($order['uid'] ?? 0)) {
            throw self::failure('legacy_debt_origin_relation_mismatch');
        }
    }

    private function assertPath(string $path): void
    {
        if (!in_array($path, self::PATHS, true)) {
            throw self::failure('legacy_debt_writer_path_invalid');
        }
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private static function failure(string $reason): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason);
    }
}
