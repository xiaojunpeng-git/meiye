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
        return $this->saveSelectionInTx(
            self::KIND_SALE,
            $requestId,
            $dataScope->tenantId(),
            $operator->storeId(),
            $operator->operatorId(),
            $payload,
            false
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
            $payload,
            true
        );
    }

    /**
     * Freezes the source that the cashier has already selected in the toolbar
     * when a recharge checkout is prepared.  Recharge no longer exposes a
     * second source picker after this point, so this is the only write
     * boundary for its attribution snapshot.
     */
    public function captureRechargePreparationInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $operatorId,
        array $source
    ): array {
        CashierV3TransactionGuard::assertInTransaction('rechargeBusinessSourcePreparation');
        $requestId = trim($requestId);
        if (preg_match('/^RCR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $tenantId === '' || $storeId <= 0 || $operatorId <= 0) {
            throw self::invalid('business_source_recharge_preparation_identity_invalid', '充值客户来源快照无效，请重新进入充值。');
        }
        return $this->saveSelectionInTx(
            self::KIND_RECHARGE,
            $requestId,
            $tenantId,
            $storeId,
            $operatorId,
            [
                'primarySourceId' => (int)($source['primarySourceId'] ?? 0),
                'secondarySourceId' => (int)($source['secondarySourceId'] ?? 0),
                'sourceSelectionVersion' => 0,
            ],
            true
        );
    }

    /**
     * Locks the source selection captured by this checkout.
     *
     * Source is a user-facing attribution snapshot, not a checkout
     * eligibility guard.  Re-resolving the live configuration here made an
     * otherwise valid checkout fail when an administrator renamed, disabled,
     * or removed a source after the cashier selected it.  The selection row
     * is already tenant/store scoped and locked by the final transaction, so
     * settlement must persist that snapshot as-is.  Payment, entitlement,
     * balance, and inventory authorities remain separately rechecked by the
     * settlement pipeline.
     */
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
        return self::rowProjection($row);
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
     * Save the source chosen in a browser checkout snapshot.
     *
     * This is intentionally distinct from mutateSaleInTx(): a final checkout
     * snapshot preserves the operator's visible selection as historical
     * attribution, so it must not be rejected because the live source catalog
     * was renamed, disabled or removed after that selection was made.
     */
    public function captureSaleSnapshotInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $operatorId,
        array $source
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutBusinessSourceSnapshot');
        $requestId = trim($requestId);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $tenantId === '' || $storeId <= 0 || $operatorId <= 0) {
            throw self::invalid('business_source_snapshot_identity_invalid', '结账来源快照无效，请重新进入结账。');
        }
        $primaryId = (int)($source['primarySourceId'] ?? 0);
        $secondaryId = (int)($source['secondarySourceId'] ?? 0);
        $rewardAmountCents = (int)($source['rewardAmountCents'] ?? 0);
        if ($primaryId < 0 || $secondaryId < 0 || $rewardAmountCents < 0) {
            throw self::invalid('business_source_snapshot_invalid', '结账来源快照无效，请重新进入结账。');
        }
        if ($primaryId === 0) {
            return self::emptySelection();
        }
        $primaryName = trim((string)($source['primarySourceNameSnapshot'] ?? ''));
        $secondaryName = trim((string)($source['secondarySourceNameSnapshot'] ?? ''));
        $label = trim((string)($source['displayNameSnapshot'] ?? ''));
        if ($label === '') {
            $label = $primaryName;
            if ($secondaryName !== '') $label .= ' / ' . $secondaryName;
        }
        $existing = (array)Db::name(self::TABLE)
            ->where('checkout_kind', self::KIND_SALE)
            ->where('checkout_request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->lock(true)->find();
        $now = time();
        $row = [
            'primary_source_id' => $primaryId,
            'primary_source_name_snapshot' => $primaryName,
            'secondary_source_id' => $secondaryId,
            'secondary_source_name_snapshot' => $secondaryName,
            'source_label_snapshot' => $label,
            'reward_amount_cents' => $rewardAmountCents,
            'updated_by_operator_id' => $operatorId,
            'updated_at' => $now,
        ];
        if ($existing) {
            // Snapshot settlement is the sole write boundary. Source choice is
            // attribution data, never an optimistic-versioned draft.
            Db::name(self::TABLE)->where('id', (int)$existing['id'])->update($row);
        } else {
            Db::name(self::TABLE)->insert($row + [
                'checkout_kind' => self::KIND_SALE,
                'checkout_request_id' => $requestId,
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
            ]);
        }
        return [
            'primarySourceId' => $primaryId,
            'primarySourceNameSnapshot' => $primaryName,
            'secondarySourceId' => $secondaryId,
            'secondarySourceNameSnapshot' => $secondaryName,
            'displayNameSnapshot' => $label,
            'rewardAmountCents' => $rewardAmountCents,
        ];
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
        return array_merge($source, ['selectionVersion' => 0, 'rewardAmountCents' => 0]);
    }

    private function saveSelectionInTx(string $kind, string $requestId, string $tenantId, int $storeId, int $operatorId, array $payload, bool $versioned = true): array
    {
        $expected = $versioned ? (int)($payload['sourceSelectionVersion'] ?? 0) : 0;
        $primaryId = (int)($payload['primarySourceId'] ?? 0);
        $secondaryId = (int)($payload['secondarySourceId'] ?? 0);
        if ($expected < 0 || $primaryId <= 0 || $secondaryId < 0) {
            throw self::invalid('business_source_payload_invalid', '请选择有效的业务来源。');
        }
        $snapshot = $this->config->resolveSourceSnapshot($primaryId, $secondaryId, true);
        $rewardAmountCents = (int)($payload['rewardAmountCents'] ?? 0);
        if ($rewardAmountCents < 0 || $rewardAmountCents > 100000000000) {
            throw self::invalid('business_source_reward_invalid', '奖励金额无效，请重新输入。');
        }
        if ($kind !== self::KIND_SALE || preg_match('/^G(?:\s|异业|$)/u', trim((string)$snapshot['primarySourceNameSnapshot'])) !== 1) {
            $rewardAmountCents = 0;
        }
        $existing = (array)Db::name(self::TABLE)
            ->where('checkout_kind', $kind)
            ->where('checkout_request_id', $requestId)
            ->lock(true)->find();
        $now = time();
        if (!$existing) {
            if ($versioned && $expected !== 0) {
                throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
            }
            $insert = [
                'checkout_kind' => $kind,
                'checkout_request_id' => $requestId,
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'primary_source_id' => $snapshot['primarySourceId'],
                'primary_source_name_snapshot' => $snapshot['primarySourceNameSnapshot'],
                'secondary_source_id' => $snapshot['secondarySourceId'],
                'secondary_source_name_snapshot' => $snapshot['secondarySourceNameSnapshot'],
                'source_label_snapshot' => $snapshot['displayNameSnapshot'],
                'reward_amount_cents' => $rewardAmountCents,
                'updated_by_operator_id' => $operatorId,
                'updated_at' => $now,
            ];
            if ($versioned) $insert['selection_version'] = 1;
            Db::name(self::TABLE)->insert($insert);
            return array_merge($snapshot, $versioned ? ['selectionVersion' => 1] : [], ['rewardAmountCents' => $rewardAmountCents]);
        }
        if ($versioned && (int)$existing['selection_version'] !== $expected) {
            throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
        }
        $next = $versioned ? $expected + 1 : 0;
        $updateQuery = Db::name(self::TABLE)->where('id', (int)$existing['id']);
        if ($versioned) $updateQuery->where('selection_version', $expected);
        $update = [
                'primary_source_id' => $snapshot['primarySourceId'],
                'primary_source_name_snapshot' => $snapshot['primarySourceNameSnapshot'],
                'secondary_source_id' => $snapshot['secondarySourceId'],
                'secondary_source_name_snapshot' => $snapshot['secondarySourceNameSnapshot'],
                'source_label_snapshot' => $snapshot['displayNameSnapshot'],
                'reward_amount_cents' => $rewardAmountCents,
                'updated_by_operator_id' => $operatorId,
                'updated_at' => $now,
            ];
        if ($versioned) $update['selection_version'] = $next;
        $affected = (int)$updateQuery->update($update);
        if ($affected !== 1) {
            throw CashierV3CommandException::versionConflict('业务来源已变化，请刷新后重新选择。');
        }
        return array_merge($snapshot, $versioned ? ['selectionVersion' => $next] : [], ['rewardAmountCents' => $rewardAmountCents]);
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
            'rewardAmountCents' => (int)($row['reward_amount_cents'] ?? 0),
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
            'rewardAmountCents' => 0,
        ];
    }

    private static function invalid(string $reason, string $message): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext($message, ['reason' => $reason]);
    }
}
