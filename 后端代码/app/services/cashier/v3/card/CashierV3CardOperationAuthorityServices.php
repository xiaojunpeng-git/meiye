<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * Transactional writer for V3 card state and its immutable operation audit.
 * The historic sales order is never rewritten. A transfer only changes the
 * current holder record and records both the original and current member.
 */
final class CashierV3CardOperationAuthorityServices
{
    public const STATE_TABLE = 'cashier_v3_card_state';
    public const OPERATION_TABLE = 'cashier_v3_card_operation';
    public const LINE_TABLE = 'cashier_v3_card_operation_line';

    /** @var CashierV3CardOperationReadinessGuard */
    private $readiness;

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3SaleCatalogServices */
    private $saleCatalog;

    /** @var CashierV3CardRuleEntitlementAuthorityServices */
    private $cardRules;

    public function __construct(
        ?CashierV3CardOperationReadinessGuard $readiness = null,
        ?CashierV3CashierWorkspaceServices $workspace = null,
        ?CashierV3SaleCatalogServices $saleCatalog = null,
        ?CashierV3CardRuleEntitlementAuthorityServices $cardRules = null
    ) {
        $this->readiness = $readiness ?: new CashierV3CardOperationReadinessGuard();
        $cashierReadiness = new CashierV3CashierReadinessGuard();
        $this->workspace = $workspace ?: new CashierV3CashierWorkspaceServices($cashierReadiness);
        $this->saleCatalog = $saleCatalog ?: new CashierV3SaleCatalogServices(null, $cashierReadiness);
        $this->cardRules = $cardRules ?: new CashierV3CardRuleEntitlementAuthorityServices();
    }

    /**
     * @param array $scope The normalized, Gateway-locked handler scope.
     * @return array{operation:array,state:array}
     */
    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardOperationSubmit');
        $this->readiness->assertReady();

        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::failure('card_operation_scope_missing');
        }
        $idempotencyKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw self::failure('card_operation_idempotency_missing');
        }

        $holderId = self::positiveId($payload['sourceCardHolderId'] ?? null, 'source_card_missing');
        $source = $this->loadSourceCardForUpdate(
            $holderId,
            $operatorScope,
            trim((string)($payload['operationType'] ?? '')),
            $payload
        );
        $source['holderVersion'] = $this->lockedCardHolderVersion((array)($scope['contexts'] ?? []), $holderId);
        $state = $this->lockOrCreateState($source, $operatorScope->tenantId());
        $this->assertStateMatchesCurrentHolder($state, $source);
        $source = $this->applyStateToSource($source, $state);

        $target = $this->loadTargetForOperation($payload, $operatorScope);
        $now = time();
        $context = [
            'tenantId' => $operatorScope->tenantId(),
            'organizationId' => $operatorScope->organizationId() !== ''
                ? $operatorScope->organizationId()
                : '0',
            'storeId' => $operatorScope->storeId(),
            'operatorId' => $operatorScope->operatorId(),
            'businessDate' => date('Y-m-d', $now),
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
        ];
        $intent = $payload;
        $intent['commandIdempotencyKey'] = $idempotencyKey;
        $intent['businessDocumentNo'] = (new CashierV3BusinessDocumentNumberServices())->cardOperationNoForCommandInTx(
            $operatorScope->tenantId(),
            $idempotencyKey,
            $context['businessDate'],
            $now
        );
        $plan = CashierV3CardOperationKernel::plan($intent, $source, $target, $context);

        $existing = Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $operatorScope->tenantId())
            ->where('command_idempotency_key', $idempotencyKey)
            ->lock(true)
            ->find();
        if ($existing) {
            return $this->replayOperation($existing, $plan);
        }

        $type = (string)$plan['operationType'];
        $isDirect = (string)$plan['operationStatus'] === 'succeeded';
        $operationState = $isDirect ? $this->applyDirectStateInTx(
            $source,
            $state,
            $plan,
            $target,
            $operatorScope->tenantId(),
            $now
        ) : $state;
        $auditSnapshots = $this->lockAuditSnapshotsInTx($source, $operationState, $operatorScope);
        $operation = $this->insertOperation(
            $plan,
            $source,
            $operationState,
            $target,
            $auditSnapshots,
            $operatorScope,
            $now
        );
        if (!empty($plan['lines'])) {
            $this->insertOperationLines($plan, $operatorScope->tenantId(), $now);
        }
        $cashierDraft = null;
        if (!$isDirect) {
            $cashierDraft = $this->appendUpgradeCheckoutLineInTx(
                $scope,
                $plan,
                $source,
                $operatorScope,
                $dataScope
            );
        }

        $eventRecorder = $scope['event_recorder'] ?? null;
        $eventExecution = $scope['event_execution'] ?? null;
        $eventContract = is_array($scope['event_contract'] ?? null) ? $scope['event_contract'] : [];
        if (!$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('card_operation_event_services_missing');
        }
        $this->recordOperationEvent(
            $eventRecorder,
            $eventExecution,
            $eventContract,
            $plan,
            $operationState,
            $auditSnapshots,
            $operatorScope,
            $now
        );

        return [
            'operation' => $this->presentOperation($operation),
            'state' => $this->presentState($operationState),
            'cashierDraft' => $cashierDraft,
        ];
    }

    /**
     * An upgrade sale line is generated from the locked operation plan, never
     * from a follow-up browser selection.  Its immutable snapshot carries the
     * operation id/fingerprint and the source-right versions needed again at
     * checkout preparation and final settlement.
     */
    private function appendUpgradeCheckoutLineInTx(
        array $scope,
        array $plan,
        array $source,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $operationType = (string)($plan['operationType'] ?? '');
        if (!in_array($operationType, [
            CashierV3CardOperationKernel::TYPE_CARD_UPGRADE,
            CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
        ], true)) {
            throw self::failure('card_operation_upgrade_type_invalid');
        }
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        if ($stateContextId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'card_operation_upgrade_state_context_missing']
            );
        }
        $workspaceId = self::workspaceId($operatorScope, $stateContextId);
        $this->assertWorkspaceContext((array)($scope['contexts'] ?? []), $workspaceId);
        $line = $this->saleCatalog->cardOperationUpgradeSaleLineAfterGatewayLocksInTx(
            $plan,
            (string)($scope['idempotency_key'] ?? ''),
            (array)($scope['contexts'] ?? []),
            $operatorScope,
            $dataScope
        );
        return $this->workspace->appendCardOperationUpgradeSaleLineInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            (int)($source['currentMemberId'] ?? 0),
            $line
        );
    }

    private static function workspaceId(CashierV3OperatorScope $operatorScope, string $stateContextId): string
    {
        return \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
    }

    private function assertWorkspaceContext(array $contexts, string $workspaceId): void
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') !== 'cashier_workspace'
                || (string)($context['id'] ?? '') !== $workspaceId) {
                continue;
            }
            if ((int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0) > 0) {
                return;
            }
        }
        throw CashierV3CommandException::invalidContext(
            '当前购物车已经变化，请刷新后重新办理升级。',
            ['reason' => 'card_operation_upgrade_workspace_context_missing']
        );
    }

    private function loadSourceCardForUpdate(
        int $holderId,
        CashierV3OperatorScope $operatorScope,
        string $operationType,
        array $payload
    ): array
    {
        $holder = $this->row(Db::name('user_card_holder')
            ->where('id', $holderId)
            ->where('is_del', 0)
            ->lock(true)
            ->find());
        if (!$holder || (int)($holder['oid'] ?? 0) <= 0 || (int)($holder['uid'] ?? 0) <= 0) {
            throw self::notFound('card_holder_not_active');
        }
        $order = $this->row(Db::name('store_order')
            ->where('id', (int)$holder['oid'])
            ->lock(true)
            ->find());
        if (!$this->activeOrder($order)
            || (int)($holder['store_id'] ?? 0) !== (int)($order['store_id'] ?? 0)
            || (int)($holder['store_id'] ?? 0) <= 0) {
            throw self::notFound('card_source_order_not_active');
        }
        if ((int)$holder['store_id'] !== $operatorScope->storeId()) {
            throw self::notFound('card_source_store_not_allowed');
        }
        return [
            'holderId' => (int)$holder['id'],
            'holderVersion' => 0,
            'originOrderId' => (int)$order['id'],
            'originMemberId' => (int)$order['uid'],
            'currentMemberId' => (int)$holder['uid'],
            'cardStatus' => 'enabled',
            'cardName' => trim((string)($holder['card_name'] ?? '')) ?: '会员卡项',
            'cardNo' => trim((string)($holder['card_no'] ?? '')),
            'effectiveWriteStart' => max(0, (int)($holder['write_start'] ?? 0)),
            'effectiveWriteEnd' => max(0, (int)($holder['write_end'] ?? 0)),
            'remainingValueCents' => in_array($operationType, [
                CashierV3CardOperationKernel::TYPE_CARD_UPGRADE,
                CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
            ], true) ? $this->remainingValueCents((int)$holder['oid']) : 0,
            'projects' => $this->loadSelectedProjectRightsForUpdate(
                (int)$order['id'],
                $operationType,
                $payload
            ),
            'legacyHolder' => $holder,
            'legacyOrder' => $order,
        ];
    }

    private function loadTargetForOperation(array $payload, CashierV3OperatorScope $operatorScope): array
    {
        $type = trim((string)($payload['operationType'] ?? ''));
        if ($type === CashierV3CardOperationKernel::TYPE_CARD_UPGRADE) {
            $skuId = self::positiveId($payload['targetCatalogId'] ?? null, 'target_card_invalid');
            $row = $this->row(Db::name('store_product_attr_value')->alias('sku')
                ->join('store_product product', 'product.id=sku.product_id')
                ->where('sku.id', $skuId)
                ->where('sku.type', 0)
                ->where('sku.is_show', 1)
                ->where('product.type', 1)
                ->where('product.relation_id', $operatorScope->storeId())
                ->where('product.product_type', 5)
                ->where('product.is_del', 0)
                ->where('product.is_show', 1)
                ->where('product.is_verify', 1)
                ->field('sku.id AS sku_id,sku.unique AS sku_unique,sku.price,product.id AS product_id,product.store_name')
                ->lock(true)
                ->find());
            if (!$row || (int)($row['product_id'] ?? 0) <= 0 || (int)($row['sku_id'] ?? 0) !== $skuId) {
                throw self::notFound('target_card_not_active');
            }
            return [
                'catalogId' => (int)$row['product_id'],
                'catalogName' => trim((string)($row['store_name'] ?? '')) ?: '卡项',
                'skuId' => $skuId,
                'skuUnique' => trim((string)($row['sku_unique'] ?? '')),
                'priceCents' => self::moneyToCents($row['price'] ?? null),
            ];
        }
        if (in_array($type, [
            CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT,
            CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
        ], true)) {
            $skuId = self::positiveId($payload['targetCatalogId'] ?? null, 'target_project_invalid');
            $row = $this->row(Db::name('store_product_attr_value')->alias('sku')
                ->join('store_product product', 'product.id=sku.product_id')
                ->where('sku.id', $skuId)
                ->where('sku.type', 0)
                ->where('sku.is_show', 1)
                ->where('product.type', 1)
                ->where('product.relation_id', $operatorScope->storeId())
                ->where('product.product_type', 6)
                ->where('product.is_del', 0)
                ->where('product.is_show', 1)
                ->where('product.is_verify', 1)
                ->field('sku.id AS sku_id,sku.unique AS sku_unique,sku.price,product.id AS product_id,product.store_name')
                ->lock(true)
                ->find());
            if (!$row || (int)($row['product_id'] ?? 0) <= 0 || (int)($row['sku_id'] ?? 0) !== $skuId) {
                throw self::notFound('target_project_not_active');
            }
            return [
                'catalogId' => (int)$row['product_id'],
                'catalogName' => trim((string)($row['store_name'] ?? '')) ?: '项目',
                'skuId' => $skuId,
                'skuUnique' => trim((string)($row['sku_unique'] ?? '')),
                'priceCents' => self::moneyToCents($row['price'] ?? null),
            ];
        }
        if ($type !== CashierV3CardOperationKernel::TYPE_CARD_TRANSFER) {
            return [];
        }
        $memberId = self::positiveId($payload['targetMemberId'] ?? null, 'target_member_invalid');
        $member = $this->row(Db::name('user')
            ->where('uid', $memberId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->lock(true)
            ->find());
        if (!$member) {
            throw self::notFound('target_member_not_active');
        }
        $name = trim((string)($member['real_name'] ?? $member['nickname'] ?? ''));
        return ['memberId' => $memberId, 'memberName' => $name];
    }

    private function lockOrCreateState(array $source, string $tenantId): array
    {
        $row = $this->row(Db::name(self::STATE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('card_holder_id', (int)$source['holderId'])
            ->lock(true)
            ->find());
        if ($row) {
            return $row;
        }
        $now = time();
        try {
            Db::name(self::STATE_TABLE)->insert([
                'tenant_id' => $tenantId,
                'card_holder_id' => (int)$source['holderId'],
                'origin_order_id' => (int)$source['originOrderId'],
                'origin_member_id' => (int)$source['originMemberId'],
                'current_member_id' => (int)$source['currentMemberId'],
                'card_status' => 'enabled',
                'status_reason_snapshot' => '',
                'effective_write_start' => (int)$source['effectiveWriteStart'],
                'effective_write_end' => (int)$source['effectiveWriteEnd'],
                'current_version' => 1,
                'last_operation_id' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $row = $this->row(Db::name(self::STATE_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('card_holder_id', (int)$source['holderId'])
                ->lock(true)
                ->find());
            if (!$row) {
                throw $exception;
            }
            return $row;
        }
        $row = $this->row(Db::name(self::STATE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('card_holder_id', (int)$source['holderId'])
            ->lock(true)
            ->find());
        if (!$row) {
            throw self::failure('card_state_create_failed');
        }
        return $row;
    }

    private function assertStateMatchesCurrentHolder(array $state, array $source): void
    {
        if ((int)($state['origin_order_id'] ?? 0) !== (int)$source['originOrderId']
            || (int)($state['origin_member_id'] ?? 0) !== (int)$source['originMemberId']
            || (int)($state['current_member_id'] ?? 0) !== (int)$source['currentMemberId']
            || !in_array((string)($state['card_status'] ?? ''), ['enabled', 'disabled', 'upgraded'], true)
            || (int)($state['current_version'] ?? 0) <= 0) {
            throw CashierV3CommandException::versionConflict(
                '会员卡当前状态已经变化，请重新打开后再办理。',
                ['reason' => 'card_state_source_mismatch']
            );
        }
    }

    private function applyStateToSource(array $source, array $state): array
    {
        $source['cardStatus'] = (string)$state['card_status'];
        $source['effectiveWriteStart'] = (int)$state['effective_write_start'];
        $source['effectiveWriteEnd'] = (int)$state['effective_write_end'];
        return $source;
    }

    private function applyDirectStateInTx(
        array $source,
        array $state,
        array $plan,
        array $target,
        string $tenantId,
        int $now
    ): array
    {
        $mutation = is_array($plan['stateMutation'] ?? null) ? $plan['stateMutation'] : [];
        $nextMemberId = (int)($mutation['currentMemberId'] ?? 0);
        $nextStatus = (string)($mutation['cardStatus'] ?? '');
        $nextEnd = (int)($mutation['effectiveWriteEnd'] ?? -1);
        if ($nextMemberId <= 0 || !in_array($nextStatus, ['enabled', 'disabled'], true) || $nextEnd < 0) {
            throw self::failure('card_operation_state_mutation_invalid');
        }
        $stateVersion = (int)($state['current_version'] ?? 0);
        if ($stateVersion <= 0) {
            throw self::failure('card_operation_state_version_invalid');
        }
        $updated = Db::name(self::STATE_TABLE)
            ->where('id', (int)$state['id'])
            ->where('current_version', $stateVersion)
            ->update([
                'current_member_id' => $nextMemberId,
                'card_status' => $nextStatus,
                'status_reason_snapshot' => mb_substr((string)($plan['reasonSnapshot'] ?? ''), 0, 500),
                'effective_write_start' => (int)($mutation['effectiveWriteStart'] ?? 0),
                'effective_write_end' => $nextEnd,
                'current_version' => $stateVersion + 1,
                'last_operation_id' => (string)$plan['operationId'],
                'updated_at' => $now,
            ]);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '会员卡当前状态已经变化，请重新打开后再办理。',
                ['reason' => 'card_state_update_race']
            );
        }

        if ((int)$source['currentMemberId'] !== $nextMemberId) {
            $affected = Db::name('user_card_holder')
                ->where('id', (int)$source['holderId'])
                ->where('uid', (int)$source['currentMemberId'])
                ->where('is_del', 0)
                ->update(['uid' => $nextMemberId]);
            if ((int)$affected !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '会员卡归属已经变化，请重新打开后再办理。',
                    ['reason' => 'card_holder_transfer_race']
                );
            }
            // A transfer changes the current right holder, not the historic
            // sale order. Existing project-pool versions retain their number
            // because no quantity/value changed; only their visible member
            // binding follows the card in this same transaction.
            $detailIds = Db::name('store_order_cart_info')
                ->where('oid', (int)$source['originOrderId'])
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->column('id');
            $detailIds = array_values(array_filter(array_map('intval', (array)$detailIds)));
            if ($detailIds !== []) {
                Db::name('cashier_v3_entitlement_resource_version')
                    ->where('resource_kind', 'member_benefit_pool')
                    ->whereIn('resource_id', array_map('strval', $detailIds))
                    ->update(['member_id' => $nextMemberId, 'update_time' => $now]);
            }
        }
        if ((int)$source['effectiveWriteEnd'] !== $nextEnd) {
            $affected = Db::name('user_card_holder')
                ->where('id', (int)$source['holderId'])
                ->where('write_end', (int)$source['effectiveWriteEnd'])
                ->where('is_del', 0)
                ->update(['write_end' => $nextEnd]);
            if ((int)$affected !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '会员卡有效期已经变化，请重新打开后再办理。',
                    ['reason' => 'card_holder_extension_race']
                );
            }

            // 卡的有效期和其卡内项目共同组成可核销权益。只更新 holder
            // 会造成页面显示已延期、而项目明细仍以旧 write_end 被拦截。
            // 这些明细本来就是随核销递减的当前权益状态，不是销售事实；
            // 它们与 holder 已在同一事务、同一来源订单下锁定并同步更新。
            Db::name('store_order_cart_info')
                ->where('oid', (int)$source['originOrderId'])
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->update(['write_end' => $nextEnd]);
        }

        if ((string)($plan['operationType'] ?? '') === CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT) {
            $this->applyProjectReplacementInTx($source, $plan, $target, $tenantId, $now);
        }

        return $this->row(Db::name(self::STATE_TABLE)->where('id', (int)$state['id'])->lock(true)->find());
    }

    /**
     * Consume current project rights and issue target rights in the same card
     * order. This is deliberately not a sale and does not alter the original
     * paid order or sales facts.
     */
    private function applyProjectReplacementInTx(
        array $source,
        array $plan,
        array $target,
        string $tenantId,
        int $now
    ): void
    {
        $mutations = is_array($plan['stateMutation']['projectMutations'] ?? null)
            ? $plan['stateMutation']['projectMutations'] : [];
        if (!$mutations || (int)($target['catalogId'] ?? 0) <= 0 || (int)($target['skuId'] ?? 0) <= 0) {
            throw self::failure('project_replacement_mutation_missing');
        }
        $sourceRows = [];
        $ruleSourceLines = [];
        $totalQuantity = 0;
        $totalValueCents = 0;
        foreach ($mutations as $mutation) {
            if (!is_array($mutation)) {
                throw self::failure('project_replacement_mutation_invalid');
            }
            $detailId = (int)($mutation['sourceDetailId'] ?? 0);
            $before = (int)($mutation['quantityBefore'] ?? -1);
            $after = (int)($mutation['quantityAfter'] ?? -1);
            $delta = (int)($mutation['quantityDelta'] ?? 0);
            if ($detailId <= 0 || $before <= 0 || $after < 0 || $delta >= 0 || $before + $delta !== $after) {
                throw self::failure('project_replacement_mutation_shape_invalid');
            }
            $row = $this->row(Db::name('store_order_cart_info')
                ->where('id', $detailId)
                ->where('oid', (int)$source['originOrderId'])
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->field('id,oid,cart_id,cart_type,product_id,product_type,cart_info,write_times,write_surplus_times,write_start,write_end,pay_price,is_writeoff,is_gift')
                ->lock(true)
                ->find());
            if (!$row || (int)($row['write_surplus_times'] ?? -1) !== $before) {
                throw CashierV3CommandException::versionConflict(
                    '原项目权益已经变化，请重新打开后再替换。',
                    ['reason' => 'project_replacement_source_changed', 'source_detail_id' => $detailId]
                );
            }
            $times = (int)($row['write_times'] ?? 0);
            if ($times <= 0 || $before > $times) {
                throw self::failure('project_replacement_source_quantity_invalid');
            }
            $quantity = -$delta;
            $value = self::allocateCents(self::moneyToCents($row['pay_price'] ?? null), $times, $quantity);
            $affected = Db::name('store_order_cart_info')
                ->where('id', $detailId)
                ->where('write_surplus_times', $before)
                ->update([
                    'write_surplus_times' => $after,
                    'is_writeoff' => $after === 0 ? 1 : 0,
                ]);
            if ((int)$affected !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '原项目权益已经变化，请重新打开后再替换。',
                    ['reason' => 'project_replacement_source_update_race', 'source_detail_id' => $detailId]
                );
            }
            $sourceRows[] = $row;
            $ruleSourceLines[] = ['detailId' => $detailId, 'quantity' => $quantity];
            $totalQuantity += $quantity;
            $totalValueCents += $value;
        }
        if ($totalQuantity <= 0 || $totalValueCents < 0 || !$sourceRows) {
            throw self::failure('project_replacement_total_invalid');
        }
        $first = $sourceRows[0];
        $cartId = 'cop' . substr(hash('sha256', (string)$plan['operationId']), 0, 28);
        $money = self::centsToMoney($totalValueCents);
        $cartInfo = [
            'id' => 0,
            'product_id' => (int)$target['catalogId'],
            'product_type' => 6,
            'product_attr_unique' => (string)$target['skuUnique'],
            'cart_num' => 1,
            'productInfo' => [
                'id' => (int)$target['catalogId'],
                'store_name' => (string)$target['catalogName'],
                'product_type' => 6,
                'attrInfo' => ['unique' => (string)$target['skuUnique']],
            ],
            'attrInfo' => ['unique' => (string)$target['skuUnique']],
            'truePrice' => $money,
            'pay_price' => $money,
            'cardOperationId' => (string)$plan['operationId'],
            'sourceType' => 'cashier_v3_project_replacement',
        ];
        $targetDetailId = (int)Db::name('store_order_cart_info')->insertGetId([
            // 目标权益仍属于原会员；不能只靠 oid 反推，旧表上不少读取和
            // 数据修复以 uid 作为会员关联键。
            'uid' => (int)$source['originMemberId'],
            'oid' => (int)$source['originOrderId'],
            'cart_id' => $cartId,
            'cart_type' => 2,
            'product_id' => (int)$target['catalogId'],
            'product_type' => 6,
            'pay_price' => $money,
            'write_times' => 1,
            'write_surplus_times' => 1,
            'write_start' => (int)$source['effectiveWriteStart'],
            'write_end' => (int)$source['effectiveWriteEnd'],
            'is_writeoff' => 0,
            'cart_info' => $this->json($cartInfo),
            'debt_amount' => '0.00',
            'repaid_debt_amount' => '0.00',
            'is_gift' => 0,
        ]);
        if ($targetDetailId <= 0) {
            throw self::failure('project_replacement_target_create_failed');
        }
        $this->cardRules->replaceProjectComponentsInTx(
            $tenantId,
            (int)$source['holderId'],
            $ruleSourceLines,
            $target,
            $targetDetailId,
            (string)$plan['operationId'],
            $now
        );
    }

    /** @return array<int,array> */
    private function loadSelectedProjectRightsForUpdate(int $orderId, string $operationType, array $payload): array
    {
        if (!in_array($operationType, [
            CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT,
            CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
        ], true)) {
            return [];
        }
        $ids = [];
        foreach ((array)($payload['projectLines'] ?? []) as $line) {
            if (is_array($line)) {
                $ids[self::positiveId($line['sourceDetailId'] ?? null, 'project_source_detail_missing')] = true;
            }
        }
        if (!$ids) {
            throw self::failure('project_source_lines_missing');
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $rows = Db::name('store_order_cart_info')
            ->where('oid', $orderId)
            ->whereIn('id', $ids)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->field('id,product_id,write_times,write_surplus_times,pay_price,is_writeoff')
            ->lock(true)
            ->select();
        $projects = [];
        foreach ($this->rows($rows) as $row) {
            $detailId = (int)($row['id'] ?? 0);
            $times = (int)($row['write_times'] ?? 0);
            $remaining = (int)($row['write_surplus_times'] ?? -1);
            if ($detailId <= 0 || $times <= 0 || $remaining <= 0 || $remaining > $times || (int)($row['is_writeoff'] ?? 0) !== 0) {
                continue;
            }
            $version = $this->row(Db::name('cashier_v3_entitlement_resource_version')
                ->where('resource_kind', 'member_benefit_pool')
                ->where('resource_id', (string)$detailId)
                ->lock(true)
                ->find());
            if ((int)($version['current_version'] ?? 0) <= 0) {
                throw CashierV3CommandException::versionConflict(
                    '卡内项目版本尚未同步，请重新打开使用权益后再办理。',
                    ['reason' => 'project_source_version_missing', 'source_detail_id' => $detailId]
                );
            }
            $projects[] = [
                'detailId' => $detailId,
                'detailVersion' => (int)$version['current_version'],
                'projectId' => (int)($row['product_id'] ?? 0),
                'remainingTimes' => $remaining,
                'remainingValueCents' => self::allocateCents(
                    self::moneyToCents($row['pay_price'] ?? null),
                    $times,
                    $remaining
                ),
                'totalTimes' => $times,
                'totalValueCents' => self::moneyToCents($row['pay_price'] ?? null),
            ];
        }
        if (count($projects) !== count($ids)) {
            throw CashierV3CommandException::versionConflict(
                '卡内项目已经变化，请重新打开后选择。',
                ['reason' => 'project_source_not_current']
            );
        }
        return $projects;
    }

    private function insertOperation(
        array $plan,
        array $source,
        array $state,
        array $target,
        array $auditSnapshots,
        CashierV3OperatorScope $operatorScope,
        int $now
    ): array {
        $resultSnapshot = is_array($plan['resultSnapshot'] ?? null) ? $plan['resultSnapshot'] : [];
        $row = [
            'operation_id' => (string)$plan['operationId'],
            'operation_no' => (string)$plan['operationNo'],
            'operation_type' => (string)$plan['operationType'],
            'operation_status' => (string)$plan['operationStatus'],
            'contract_version' => (string)$plan['contractVersion'],
            'tenant_id' => $operatorScope->tenantId(),
            'organization_id' => $operatorScope->organizationId() !== '' ? $operatorScope->organizationId() : '0',
            'organization_path_snapshot' => '',
            'organization_name_snapshot' => '',
            'store_id' => $operatorScope->storeId(),
            'store_name_snapshot' => mb_substr((string)$auditSnapshots['storeName'], 0, 128),
            'source_card_holder_id' => (int)$source['holderId'],
            'source_card_holder_version' => (int)$source['holderVersion'],
            'origin_order_id' => (int)$source['originOrderId'],
            'origin_member_id' => (int)$source['originMemberId'],
            'member_id_before' => (int)$source['currentMemberId'],
            'member_id_after' => (int)($state['current_member_id'] ?? $source['currentMemberId']),
            'member_name_before_snapshot' => mb_substr((string)$auditSnapshots['memberNameBefore'], 0, 128),
            'member_name_after_snapshot' => mb_substr((string)$auditSnapshots['memberNameAfter'], 0, 128),
            'card_name_snapshot' => mb_substr((string)$source['cardName'], 0, 128),
            'card_no_snapshot' => mb_substr((string)$source['cardNo'], 0, 128),
            'card_status_before' => (string)$source['cardStatus'],
            'card_status_after' => (string)($state['card_status'] ?? $source['cardStatus']),
            'write_end_before' => (int)$source['effectiveWriteEnd'],
            'write_end_after' => (int)($state['effective_write_end'] ?? $source['effectiveWriteEnd']),
            'target_catalog_id' => (int)($plan['target']['catalogId'] ?? 0),
            'target_catalog_name_snapshot' => mb_substr((string)($plan['target']['catalogName'] ?? ''), 0, 128),
            'target_price_cents' => (int)($plan['checkoutSettlement']['targetPriceCents'] ?? 0),
            'source_remaining_value_cents' => (int)($plan['checkoutSettlement']['sourceRemainingValueCents'] ?? 0),
            'settlement_delta_cents' => (int)($plan['checkoutSettlement']['settlementDeltaCents'] ?? 0),
            'checkout_request_id' => '',
            'reason_snapshot' => mb_substr((string)$plan['reasonSnapshot'], 0, 500),
            'command_idempotency_key' => (string)$plan['commandIdempotencyKey'],
            'natural_key' => (string)$plan['naturalKey'],
            'immutable_fingerprint' => (string)$plan['immutableFingerprint'],
            'source_snapshot_json' => $this->json($plan['sourceCard'] ?? []),
            'target_snapshot_json' => $this->json($plan['target'] ?? []),
            'result_snapshot_json' => $this->json(array_merge($resultSnapshot, [
                'stateVersionAfter' => (int)($state['current_version'] ?? 0),
            ])),
            'operator_id' => $operatorScope->operatorId(),
            'operator_name_snapshot' => mb_substr((string)$auditSnapshots['operatorName'], 0, 128),
            'business_date' => (string)$plan['businessDate'],
            'business_timezone' => (string)$plan['businessTimezone'],
            'occurred_at' => (int)$plan['occurredAt'],
            'settled_at' => (int)$plan['settledAt'],
            'recorded_at' => (int)$plan['recordedAt'],
            'add_time' => $now,
            'update_time' => $now,
        ];
        try {
            Db::name(self::OPERATION_TABLE)->insert($row);
        } catch (\Throwable $exception) {
            $existing = Db::name(self::OPERATION_TABLE)
                ->where('tenant_id', $operatorScope->tenantId())
                ->where('command_idempotency_key', (string)$plan['commandIdempotencyKey'])
                ->lock(true)
                ->find();
            if (!$existing) {
                throw $exception;
            }
            return $this->replayOperation($existing, $plan)['operation'];
        }
        return $row;
    }

    private function insertOperationLines(array $plan, string $tenantId, int $now): void
    {
        foreach ((array)$plan['lines'] as $line) {
            if (!is_array($line)) {
                throw self::failure('card_operation_line_invalid');
            }
            $lineNo = (int)($line['lineNo'] ?? 0);
            if ($lineNo <= 0) {
                throw self::failure('card_operation_line_no_invalid');
            }
            $operationId = (string)$plan['operationId'];
            $linePayload = $line;
            $fingerprint = hash('sha256', $this->json($linePayload));
            Db::name(self::LINE_TABLE)->insert([
                'operation_line_id' => $operationId . '-L' . $lineNo,
                'operation_id' => $operationId,
                'tenant_id' => $tenantId,
                'line_no' => $lineNo,
                'line_role' => mb_substr((string)($line['lineRole'] ?? ''), 0, 24),
                'source_detail_id' => (int)($line['sourceDetailId'] ?? 0),
                'source_detail_version' => (int)($line['sourceDetailVersion'] ?? 0),
                'source_project_id' => (int)($line['sourceProjectId'] ?? 0),
                'target_catalog_id' => (int)($line['targetCatalogId'] ?? 0),
                'quantity_before' => (int)($line['quantityBefore'] ?? 0),
                'quantity_delta' => (int)($line['quantityDelta'] ?? 0),
                'quantity_after' => (int)($line['quantityAfter'] ?? 0),
                'amount_cents' => (int)($line['amountCents'] ?? 0),
                'line_snapshot_json' => $this->json($linePayload),
                'natural_key' => hash('sha256', $operationId . "\0" . $lineNo),
                'immutable_fingerprint' => $fingerprint,
                'add_time' => $now,
            ]);
        }
    }

    private function recordOperationEvent(
        CashierV3BusinessEventRecorder $recorder,
        CashierV3BusinessEventExecution $execution,
        array $contract,
        array $plan,
        array $state,
        array $auditSnapshots,
        CashierV3OperatorScope $operatorScope,
        int $now
    ): void {
        $recorder->recordInTx($execution, $contract, [
            'event_type' => 'card.operation.recorded',
            'aggregate_type' => 'card_operation',
            'aggregate_id' => (string)$plan['operationId'],
            'aggregate_version' => max(1, (int)($state['current_version'] ?? 1)),
            'source_type' => CashierV3CardOperationKernel::ACTION,
            'source_id' => (string)$plan['operationId'],
            'member_id' => (int)($state['current_member_id'] ?? $plan['sourceCard']['currentMemberId'] ?? 0),
            'business_date' => (string)$plan['businessDate'],
            'occurred_at' => (int)$plan['occurredAt'],
            // Pending upgrade creation is still an auditable command result;
            // it is not settlement, so the operation row keeps settled_at=0,
            // while the event itself records when this state was accepted.
            'settled_at' => max((int)$plan['occurredAt'], (int)$plan['settledAt']),
            'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$plan['sourceCard']['cardName'],
            'store_name_snapshot' => (string)$auditSnapshots['storeName'],
            'payload' => [
                'operationId' => (string)$plan['operationId'],
                'operationType' => (string)$plan['operationType'],
                'operationStatus' => (string)$plan['operationStatus'],
                'cardHolderId' => (int)$plan['sourceCard']['holderId'],
                'originOrderId' => (int)$plan['sourceCard']['originOrderId'],
                'storeId' => $operatorScope->storeId(),
                'storeNameSnapshot' => (string)$auditSnapshots['storeName'],
                'memberIdBefore' => (int)$auditSnapshots['memberIdBefore'],
                'memberNameBeforeSnapshot' => (string)$auditSnapshots['memberNameBefore'],
                'memberIdAfter' => (int)$auditSnapshots['memberIdAfter'],
                'memberNameAfterSnapshot' => (string)$auditSnapshots['memberNameAfter'],
                'operatorId' => $operatorScope->operatorId(),
                'operatorNameSnapshot' => (string)$auditSnapshots['operatorName'],
                'settlementDeltaCents' => (int)$plan['checkoutSettlement']['settlementDeltaCents'],
            ],
        ]);
    }

    /**
     * The audit row and business event must use the same locked dimensions as
     * the card-state mutation, never a browser-provided display value.
     *
     * @return array{storeName:string,memberIdBefore:int,memberNameBefore:string,memberIdAfter:int,memberNameAfter:string,operatorName:string}
     */
    private function lockAuditSnapshotsInTx(
        array $source,
        array $state,
        CashierV3OperatorScope $operatorScope
    ): array {
        $store = $this->row(Db::name('system_store')
            ->where('id', $operatorScope->storeId())
            ->where('is_del', 0)
            ->field('id,name')
            ->lock(true)
            ->find());
        $storeName = trim((string)($store['name'] ?? ''));
        if ((int)($store['id'] ?? 0) !== $operatorScope->storeId() || $storeName === '') {
            throw self::failure('card_operation_store_snapshot_missing');
        }

        $memberIdBefore = (int)($source['currentMemberId'] ?? 0);
        $memberIdAfter = (int)($state['current_member_id'] ?? $memberIdBefore);
        if ($memberIdBefore <= 0 || $memberIdAfter <= 0) {
            throw self::failure('card_operation_member_snapshot_id_invalid');
        }
        $memberIds = array_values(array_unique([$memberIdBefore, $memberIdAfter]));
        sort($memberIds, SORT_NUMERIC);
        $memberRows = $this->rows(Db::name('user')
            ->whereIn('uid', $memberIds)
            ->field('uid,real_name,nickname')
            ->order('uid', 'asc')
            ->lock(true)
            ->select());
        $memberNames = [];
        foreach ($memberRows as $member) {
            $memberId = (int)($member['uid'] ?? 0);
            $name = self::memberSnapshotName($member);
            if ($memberId > 0 && $name !== '') {
                $memberNames[$memberId] = $name;
            }
        }
        if (!isset($memberNames[$memberIdBefore]) || !isset($memberNames[$memberIdAfter])) {
            throw self::failure('card_operation_member_snapshot_missing');
        }

        $operator = $this->row(Db::name('system_store_staff')
            ->where('id', $operatorScope->operatorId())
            ->where('store_id', $operatorScope->storeId())
            ->where('status', 1)
            ->where('is_del', 0)
            ->field('id,store_id,staff_name')
            ->lock(true)
            ->find());
        $operatorName = trim((string)($operator['staff_name'] ?? ''));
        if ((int)($operator['id'] ?? 0) !== $operatorScope->operatorId()
            || $operatorName === '') {
            throw self::failure('card_operation_operator_snapshot_missing');
        }

        return [
            'storeName' => $storeName,
            'memberIdBefore' => $memberIdBefore,
            'memberNameBefore' => $memberNames[$memberIdBefore],
            'memberIdAfter' => $memberIdAfter,
            'memberNameAfter' => $memberNames[$memberIdAfter],
            'operatorName' => $operatorName,
        ];
    }

    private function replayOperation(array $row, array $plan): array
    {
        if (!hash_equals((string)($row['immutable_fingerprint'] ?? ''), (string)$plan['immutableFingerprint'])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '本次卡操作的重复请求内容不一致，已拒绝执行。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'card_operation_idempotency_fingerprint_mismatch']
            );
        }
        return ['operation' => $this->presentOperation($row), 'state' => []];
    }

    private function lockedCardHolderVersion(array $contexts, int $holderId): int
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === 'card_holder'
                && (int)($context['id'] ?? 0) === $holderId
                && (int)($context['expected_version'] ?? 0) > 0) {
                return (int)$context['expected_version'];
            }
        }
        throw self::failure('card_operation_context_missing');
    }

    private function remainingValueCents(int $orderId): int
    {
        $rows = Db::name('store_order_cart_info')
            ->where('oid', $orderId)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('pay_price,write_times,write_surplus_times')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $total = 0;
        foreach ((array)$rows as $row) {
            $times = (int)($row['write_times'] ?? 0);
            $remaining = (int)($row['write_surplus_times'] ?? 0);
            if ($times <= 0 || $remaining < 0 || $remaining > $times) {
                throw self::failure('card_remaining_value_source_invalid');
            }
            $total += self::allocateCents(self::moneyToCents($row['pay_price'] ?? null), $times, $remaining);
        }
        return $total;
    }

    private static function allocateCents(int $amount, int $total, int $portion): int
    {
        if ($amount < 0 || $total <= 0 || $portion < 0 || $portion > $total) {
            throw self::failure('card_value_allocation_invalid');
        }
        return (int)floor(($amount * $portion * 2 + $total) / ($total * 2));
    }

    private static function moneyToCents($value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw self::failure('card_money_invalid');
        }
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $raw) !== 1) {
            throw self::failure('card_money_invalid');
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        return ((int)$whole * 100) + (int)$fraction;
    }

    private static function centsToMoney(int $cents): string
    {
        if ($cents < 0) {
            throw self::failure('card_money_negative');
        }
        return (string)intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function presentOperation(array $row): array
    {
        return [
            'operationId' => (string)($row['operation_id'] ?? $row['operationId'] ?? ''),
            'operationNo' => (string)($row['operation_no'] ?? $row['operationNo'] ?? ''),
            'operationType' => (string)($row['operation_type'] ?? $row['operationType'] ?? ''),
            'operationStatus' => (string)($row['operation_status'] ?? $row['operationStatus'] ?? ''),
            'requiresCheckout' => (string)($row['operation_status'] ?? $row['operationStatus'] ?? '') === 'awaiting_checkout',
            'settlementDeltaCents' => (int)($row['settlement_delta_cents'] ?? 0),
            'sourceCardHolderId' => (int)($row['source_card_holder_id'] ?? 0),
            'memberIdAfter' => (int)($row['member_id_after'] ?? 0),
            'businessDate' => (string)($row['business_date'] ?? ''),
        ];
    }

    private function presentState(array $row): array
    {
        return [
            'cardHolderId' => (int)($row['card_holder_id'] ?? 0),
            'currentMemberId' => (int)($row['current_member_id'] ?? 0),
            'cardStatus' => (string)($row['card_status'] ?? ''),
            'effectiveWriteEnd' => (int)($row['effective_write_end'] ?? 0),
            'version' => (int)($row['current_version'] ?? 0),
        ];
    }

    private function activeOrder(array $order): bool
    {
        return $order !== []
            && (int)($order['id'] ?? 0) > 0
            && (int)($order['paid'] ?? 0) === 1
            && (int)($order['is_del'] ?? 1) === 0
            && (int)($order['is_system_del'] ?? 1) === 0
            && (int)($order['is_user_del'] ?? 1) === 0
            && (int)($order['refund_status'] ?? -1) === 0
            && (int)($order['terminal_action'] ?? -1) === 0
            && (int)($order['card_upgrade_use_oid'] ?? -1) === 0;
    }

    private function row($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? $value : [];
    }

    private static function memberSnapshotName(array $member): string
    {
        foreach (['real_name', 'nickname'] as $field) {
            $name = trim((string)($member[$field] ?? ''));
            if ($name !== '') {
                return mb_substr($name, 0, 128);
            }
        }
        return '';
    }

    /** @return array<int,array> */
    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    private function json($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw self::failure('card_operation_json_encode_failed');
        }
        return $encoded;
    }

    private static function positiveId($value, string $reason): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::failure($reason);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure($reason);
        }
        return (int)$raw;
    }

    private static function notFound(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该会员卡不存在、不可用或不在当前门店操作范围内。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '卡操作资料不完整或当前不可办理，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
