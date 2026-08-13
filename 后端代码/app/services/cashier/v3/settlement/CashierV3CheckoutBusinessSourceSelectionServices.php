<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use think\facade\Db;

/**
 * A business source is an order-level checkout choice, separate from the
 * checkout's technical source-document references. It has its own optimistic
 * version so changing the choice never rebuilds payment or line drafts.
 */
final class CashierV3CheckoutBusinessSourceSelectionServices
{
    public const KIND_SALE = 'sale';
    public const KIND_RECHARGE = 'recharge';
    private const TABLE = 'cashier_v3_checkout_business_source_selection';

    /** @var CashierV3BusinessConfigServices */
    private $config;

    public function __construct(CashierV3BusinessConfigServices $config = null)
    {
        $this->config = $config ?: new CashierV3BusinessConfigServices();
    }

    public function mutateSaleInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBusinessSourceSale');
        $payload = (array)($scope['payload'] ?? []);
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!($operator instanceof CashierV3OperatorScope) || !($dataScope instanceof CashierV3DataScopeContext)) {
            throw self::invalid('business_source_scope_missing', '当前收银账号或门店权限已失效，请重新登录后重试。');
        }
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $requestVersion = (int)($payload['checkoutRequestVersion'] ?? 0);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1 || $requestVersion <= 0) {
            throw self::invalid('business_source_sale_request_invalid', '结账来源选择已失效，请重新进入结账。');
        }
        $request = (array)Db::name('cashier_v3_checkout_request')
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('request_version', $requestVersion)
            ->where('request_status', 'editing')
            ->lock(true)->find();
        if (!$request) {
            throw CashierV3CommandException::versionConflict('结账资料已变化，请刷新后重新选择来源。');
        }
        return $this->saveSelectionInTx(
            self::KIND_SALE,
            $requestId,
            $dataScope->tenantId(),
            $operator->storeId(),
            $operator->operatorId(),
            $payload
        );
    }

    public function mutateRechargeInTx(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope, string $stateContextId): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBusinessSourceRecharge');
        $requestId = trim((string)($payload['rechargeCheckoutRequestId'] ?? ''));
        $requestVersion = (int)($payload['rechargeCheckoutRequestVersion'] ?? 0);
        if (preg_match('/^RCR-[0-9a-f]{40}$/D', $requestId) !== 1 || $requestVersion <= 0) {
            throw self::invalid('business_source_recharge_request_invalid', '充值结账来源选择已失效，请重新进入。');
        }
        $request = (array)Db::name('cashier_v3_recharge_checkout_request')
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('state_context_id', $stateContextId)
            ->where('request_version', $requestVersion)
            ->where('request_status', 'editing')
            ->lock(true)->find();
        if (!$request) {
            throw CashierV3CommandException::versionConflict('充值结账资料已变化，请刷新后重新选择来源。');
        }
        return $this->saveSelectionInTx(
            self::KIND_RECHARGE,
            $requestId,
            $dataScope->tenantId(),
            $operator->storeId(),
            $operator->operatorId(),
            $payload
        );
    }

    /** Locks and rechecks current config before the successful business write. */
    public function lockResolvedForSettlementInTx(string $kind, string $requestId, string $tenantId, int $storeId): array
    {
        $row = (array)Db::name(self::TABLE)
            ->where('checkout_kind', $kind)
            ->where('checkout_request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->lock(true)->find();
        if (!$row || (int)($row['primary_source_id'] ?? 0) <= 0) {
            if ($kind !== self::KIND_SALE) {
                return self::emptySelection();
            }
            $request = (array)Db::name('cashier_v3_checkout_request')
                ->where('request_id', $requestId)
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->field('member_id')->find();
            return $this->latestNormalMemberSource(
                $tenantId,
                $storeId,
                (int)($request['member_id'] ?? 0),
                true
            );
        }
        $source = $this->config->resolveSourceSnapshot(
            (int)$row['primary_source_id'],
            (int)$row['secondary_source_id'],
            true
        );
        return array_merge($source, ['selectionVersion' => (int)$row['selection_version']]);
    }

    public function read(string $kind, string $requestId, string $tenantId, int $storeId): array
    {
        $row = (array)Db::name(self::TABLE)
            ->where('checkout_kind', $kind)
            ->where('checkout_request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)->find();
        if (!$row) {
            return self::emptySelection();
        }
        return self::rowProjection($row);
    }

    /**
     * Read the latest normal V3 sale source for the confirmation-page default.
     * This is a read-only projection, not a persisted member preference.
     */
    public function latestNormalMemberSource(string $tenantId, int $storeId, int $memberId, bool $forUpdate = false): array
    {
        if ($tenantId === '' || $storeId <= 0 || $memberId <= 0) {
            return self::emptySelection();
        }
        $order = Db::name('cashier_v3_sales_order')->alias('o')
            ->where('o.tenant_id', $tenantId)
            ->where('o.store_id', $storeId)
            ->where('o.member_id', $memberId)
            ->where('o.order_status', 'settled')
            ->where('o.order_direction', 'forward')
            ->where('o.settled_at', '>', 0)
            ->whereNotExists(function ($operation) {
                $operation->name('cashier_v3_order_lifecycle_operation')->alias('olo')
                    ->whereRaw('olo.source_order_id = o.order_id')
                    ->whereRaw('olo.tenant_id = o.tenant_id')
                    ->where('olo.source_type', 'sales')
                    ->where('olo.status', 'succeeded')
                    ->whereIn('olo.operation_type', ['refund', 'void']);
            })
            ->field('o.business_source_primary_id,o.business_source_primary_name_snapshot,'
                . 'o.business_source_secondary_id,o.business_source_secondary_name_snapshot,'
                . 'o.business_source_label_snapshot')
            ->order('o.settled_at desc,o.id desc')
            ->find();
        if (!$order || (int)($order['business_source_primary_id'] ?? 0) <= 0) {
            return self::emptySelection();
        }
        try {
            $source = $this->config->resolveSourceSnapshot(
                (int)$order['business_source_primary_id'],
                (int)($order['business_source_secondary_id'] ?? 0),
                $forUpdate
            );
        } catch (\Throwable $exception) {
            return self::emptySelection();
        }
        return array_merge($source, ['selectionVersion' => 0]);
    }

    private function saveSelectionInTx(string $kind, string $requestId, string $tenantId, int $storeId, int $operatorId, array $payload): array
    {
        $expected = (int)($payload['sourceSelectionVersion'] ?? 0);
        $primaryId = (int)($payload['primarySourceId'] ?? 0);
        $secondaryId = (int)($payload['secondarySourceId'] ?? 0);
        if ($expected < 0 || $primaryId <= 0 || $secondaryId < 0) {
            throw self::invalid('business_source_payload_invalid', '请选择有效的业务来源。');
        }
        $snapshot = $this->config->resolveSourceSnapshot($primaryId, $secondaryId, true);
        $existing = (array)Db::name(self::TABLE)
            ->where('checkout_kind', $kind)
            ->where('checkout_request_id', $requestId)
            ->lock(true)->find();
        $now = time();
        if (!$existing) {
            if ($expected !== 0) {
                throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
            }
            Db::name(self::TABLE)->insert([
                'checkout_kind' => $kind,
                'checkout_request_id' => $requestId,
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'primary_source_id' => $snapshot['primarySourceId'],
                'primary_source_name_snapshot' => $snapshot['primarySourceNameSnapshot'],
                'secondary_source_id' => $snapshot['secondarySourceId'],
                'secondary_source_name_snapshot' => $snapshot['secondarySourceNameSnapshot'],
                'source_label_snapshot' => $snapshot['displayNameSnapshot'],
                'selection_version' => 1,
                'updated_by_operator_id' => $operatorId,
                'updated_at' => $now,
            ]);
            return array_merge($snapshot, ['selectionVersion' => 1]);
        }
        if ((int)$existing['selection_version'] !== $expected) {
            throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
        }
        $next = $expected + 1;
        $affected = (int)Db::name(self::TABLE)->where('id', (int)$existing['id'])
            ->where('selection_version', $expected)->update([
                'primary_source_id' => $snapshot['primarySourceId'],
                'primary_source_name_snapshot' => $snapshot['primarySourceNameSnapshot'],
                'secondary_source_id' => $snapshot['secondarySourceId'],
                'secondary_source_name_snapshot' => $snapshot['secondarySourceNameSnapshot'],
                'source_label_snapshot' => $snapshot['displayNameSnapshot'],
                'selection_version' => $next,
                'updated_by_operator_id' => $operatorId,
                'updated_at' => $now,
            ]);
        if ($affected !== 1) {
            throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
        }
        return array_merge($snapshot, ['selectionVersion' => $next]);
    }

    private static function rowProjection(array $row): array
    {
        return [
            'primarySourceId' => (int)($row['primary_source_id'] ?? 0),
            'primarySourceNameSnapshot' => (string)($row['primary_source_name_snapshot'] ?? ''),
            'secondarySourceId' => (int)($row['secondary_source_id'] ?? 0),
            'secondarySourceNameSnapshot' => (string)($row['secondary_source_name_snapshot'] ?? ''),
            'displayNameSnapshot' => (string)($row['source_label_snapshot'] ?? ''),
            'selectionVersion' => (int)($row['selection_version'] ?? 0),
        ];
    }

    private static function emptySelection(): array
    {
        return [
            'primarySourceId' => 0,
            'primarySourceNameSnapshot' => '',
            'secondarySourceId' => 0,
            'secondarySourceNameSnapshot' => '',
            'displayNameSnapshot' => '',
            'selectionVersion' => 0,
        ];
    }

    private static function invalid(string $reason, string $message): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext($message, ['reason' => $reason]);
    }
}
