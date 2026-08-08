<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * C2 收银工作台权威草稿。
 *
 * A1 只持久化 entitlement_service 行；表结构同时保留 sale 行能力，后续 C2
 * 销售购物车接入时仍由同一服务返回完整草稿，避免两份购物车权威源。
 */
final class CashierV3CashierWorkspaceServices
{
    private const DRAFT_TABLE = 'cashier_v3_workspace_draft';
    private const LINE_TABLE = 'cashier_v3_workspace_line';
    private const ROLE_SALE = 'sale';
    private const ROLE_ENTITLEMENT = 'entitlement_service';
    private const MODE_MEMBER = 'member';
    private const MODE_GUEST = 'guest';
    private const STATUS_EDITING = 'editing';

    /** @var CashierV3CashierReadinessGuard */
    private $readiness;

    public function __construct(CashierV3CashierReadinessGuard $readiness)
    {
        $this->readiness = $readiness;
    }

    public function selectMemberInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        int $memberId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceSelectMember');
        if ($memberId <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_MEMBER_REQUIRED,
                '请选择有效会员后再继续。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $changedCustomer = (string)($draft['customer_mode'] ?? '') !== self::MODE_MEMBER
            || (int)($draft['member_id'] ?? 0) !== $memberId;
        $this->assertNoPendingCardOperationUpgradeLine($this->lineRows($workspaceId, true));
        if ($changedCustomer) {
            $this->deleteEntitlementLines($workspaceId);
        }
        // 即使 draft 已经指向同一会员，也修复可能由旧版本留下的销售行归属漂移。
        $this->rebindSaleLines($workspaceId, $memberId, $changedCustomer);
        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'customer_mode' => self::MODE_MEMBER,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function selectGuestInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceSelectGuest');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $this->assertNoPendingCardOperationUpgradeLine($this->lineRows($workspaceId, true));
        $this->deleteEntitlementLines($workspaceId);
        $this->rebindSaleLines($workspaceId, 0, true);
        $this->updateDraft($workspaceId, [
            'member_id' => 0,
            'customer_mode' => self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** 固定命令锁序：cashier workspace 必须早于目录商品、SKU 和卡项组成。 */
    public function lockForSaleMutationInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceSaleMutationLock');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        return $draft;
    }

    /** Card sales always issue member-held rights and cannot belong to a guest draft. */
    public function assertCardSaleMemberInTx(array $draft, array $line): void
    {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceCardSaleMember');
        $productType = (int)($line['catalog_product_type'] ?? -1);
        if (!in_array($productType, [4, 5], true)) {
            return;
        }
        if ((string)($draft['customer_mode'] ?? '') === self::MODE_MEMBER
            && (int)($draft['member_id'] ?? 0) > 0) {
            return;
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::ENTITLEMENT_MEMBER_REQUIRED,
            '请先创建会员档案，再购买卡项。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => 'card_purchase_member_required']
        );
    }

    /**
     * 每次商品点击追加一条独立 sale 行；同一商品不会在草稿层合并。
     *
     * @param array $line CashierV3SaleCatalogServices 已在同一事务锁定的权威行
     */
    public function appendSaleLineInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $line
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceAppendSale');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $memberId = (string)($draft['customer_mode'] ?? '') === self::MODE_MEMBER
            ? (int)($draft['member_id'] ?? 0)
            : 0;
        if ($memberId < 0) {
            throw $this->incompleteDraft('cashier_sale_member_binding_invalid');
        }
        $existing = $this->lineRows($workspaceId, true);
        $maxSort = 0;
        foreach ($existing as $row) {
            if ($this->isCardOperationUpgradeSaleRow($row)) {
                throw $this->cardOperationUpgradeCartLocked();
            }
            if ((string)($row['line_key'] ?? '') === (string)($line['line_key'] ?? '')) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                    '本次添加请求已经处理，请不要重复提交。',
                    CashierV3ResultCode::STATUS_CONFLICT,
                    ['line_id' => (string)($line['line_key'] ?? ''), 'reason' => 'sale_add_intent_reused']
                );
            }
            if ((string)($row['line_role'] ?? '') === self::ROLE_SALE
                && $this->isCustomCardSaleRow($row)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '定制卡不能与其他商品同时结账，请先处理当前订单。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'custom_card_cart_conflict']
                );
            }
            $maxSort = max($maxSort, (int)($row['sort_no'] ?? 0));
        }
        if ($existing && $this->isCustomCardSaleLine($line)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '定制卡不能与其他商品同时结账，请先处理当前订单。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'custom_card_cart_conflict']
            );
        }
        $record = $this->normalizePersistedSaleLine($line, $workspaceId, $memberId);
        $now = time();
        $record['sort_no'] = $maxSort + 1;
        $record['add_time'] = $now;
        $record['update_time'] = $now;
        Db::name(self::LINE_TABLE)->insert($record);
        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /**
     * A card/project upgrade is not an ordinary browse-and-add sale. Its
     * checkout line is created by the server together with an immutable card
     * operation record, so it must start from an otherwise empty cart and
     * remain bound to the source member.
     */
    public function appendCardOperationUpgradeSaleLineInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        int $memberId,
        array $line
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceAppendCardOperationUpgrade');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        if ($memberId <= 0
            || (string)($draft['customer_mode'] ?? '') !== self::MODE_MEMBER
            || (int)($draft['member_id'] ?? 0) !== $memberId) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTOR_SESSION_EXPIRED,
                '升级操作对应的会员已经变化，请重新打开后办理。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'card_operation_upgrade_workspace_member_changed']
            );
        }
        if ($this->lineRows($workspaceId, true) !== []) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '请先完成或清空当前购物车，再办理升级操作。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'card_operation_upgrade_workspace_not_empty']
            );
        }
        return $this->appendSaleLineInTx($workspaceId, $stateContextId, $operatorScope, $line);
    }

    /**
     * @param array<int,array> $lines 已由权益领域在同一事务中重新校验的权威草稿行
     */
    public function appendEntitlementLinesInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        int $memberId,
        array $lines
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceAppendEntitlement');
        if (count($lines) !== 1 || !is_array($lines[0])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '每次只能添加一条卡内项目。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        if ((string)($draft['customer_mode'] ?? '') !== self::MODE_MEMBER
            || (int)($draft['member_id'] ?? 0) !== $memberId) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTOR_SESSION_EXPIRED,
                '当前会员已经变化，请重新打开卡内项目后选择。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'workspace_member_changed']
            );
        }

        $existing = $this->lineRows($workspaceId, true);
        $this->assertNoPendingCardOperationUpgradeLine($existing);
        $existingByKey = [];
        $maxSort = 0;
        foreach ($existing as $row) {
            $key = (string)($row['line_key'] ?? '');
            $sort = max(0, (int)($row['sort_no'] ?? 0));
            if ($key !== '') {
                $existingByKey[$key] = $row;
            }
            $maxSort = max($maxSort, $sort);
        }

        $now = time();
        $line = $lines[0];
        $record = $this->normalizePersistedEntitlementLine($line, $workspaceId, $memberId);
        $lineKey = $record['line_key'];
        if (isset($existingByKey[$lineKey])) {
            // 同幂等键重试由 Gateway 直接重放原回执；不同键重用同一添加意图
            // 是客户端合同冲突，不能再推进工作台版本。
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '本次添加请求已经处理，请不要重复提交。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['line_id' => $lineKey, 'reason' => 'add_intent_reused_with_new_key']
            );
        }
        $selectedQuantity = 0;
        foreach ($existing as $row) {
            if ((string)($row['line_role'] ?? '') === self::ROLE_ENTITLEMENT
                && (int)($row['member_id'] ?? 0) === $memberId
                && (int)($row['holder_id'] ?? 0) === (int)$record['holder_id']
                && (int)($row['source_detail_id'] ?? 0) === (int)$record['source_detail_id']) {
                if ((int)($row['project_id'] ?? 0) !== (int)$record['project_id']) {
                    throw $this->incompleteDraftLine(
                        (string)($row['line_key'] ?? ''),
                        'entitlement_source_project_mismatch'
                    );
                }
                $existingQuantity = (int)($row['quantity'] ?? 0);
                if ($existingQuantity <= 0) {
                    throw $this->incompleteDraftLine(
                        (string)($row['line_key'] ?? ''),
                        'entitlement_line_quantity_invalid'
                    );
                }
                $selectedQuantity += $existingQuantity;
            }
        }
        $available = (int)($line['display_snapshot']['availableTimes'] ?? 0);
        if ((int)$record['quantity'] !== 1
            || $available <= 0
            || $selectedQuantity + (int)$record['quantity'] > $available) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTION_CHANGED,
                '权益项目可用次数已经变化，请重新打开后选择。',
                CashierV3ResultCode::STATUS_CONFLICT,
                [
                    'line_id' => $lineKey,
                    'selected_quantity' => $selectedQuantity,
                    'available_times' => $available,
                ]
            );
        }
        // 草稿不是权益事实。来源在两次点击之间发生合法版本变化时，以本次已锁定的
        // 权威快照刷新同一权益池的旧草稿行，避免各独立行从不同已用次数起点分摊。
        $refreshed = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_role', self::ROLE_ENTITLEMENT)
            ->where('member_id', $memberId)
            ->where('holder_id', (int)$record['holder_id'])
            ->where('source_detail_id', (int)$record['source_detail_id'])
            ->update([
                'project_id' => (int)$record['project_id'],
                'source_version' => (int)$record['source_version'],
                'detail_version' => (int)$record['detail_version'],
                'display_snapshot_json' => (string)$record['display_snapshot_json'],
                'update_time' => $now,
            ]);
        if ((int)$refreshed < 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '权益项目权威快照刷新失败，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'entitlement_draft_snapshot_refresh_failed']
            );
        }
        $record['sort_no'] = ++$maxSort;
        $record['add_time'] = $now;
        $record['update_time'] = $now;
        Db::name(self::LINE_TABLE)->insert($record);

        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'customer_mode' => self::MODE_MEMBER,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function updateLineServiceSettingsInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        array $settings
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceUpdateLineServiceSettings');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $line = $this->lockLine($workspaceId, $lineKey);
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        if ($this->isCardOperationUpgradeSaleRow((array)$line)) {
            throw $this->cardOperationUpgradeCartLocked();
        }
        $lineRole = (string)($line['line_role'] ?? '');
        $isEntitlement = $lineRole === self::ROLE_ENTITLEMENT;
        $isSale = $lineRole === self::ROLE_SALE;
        $isSaleProject = $isSale && (int)($line['project_id'] ?? 0) > 0;
        if (!$isEntitlement && !$isSale) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '只有项目可以设置服务对象、手艺人或体验标记。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'line_not_service_project']
            );
        }

        $hasServiceObject = array_key_exists('serviceObject', $settings);
        $hasCraftsmen = array_key_exists('craftsmen', $settings);
        $hasSalespeople = array_key_exists('salespeople', $settings);
        $hasExperience = array_key_exists('isExperience', $settings);
        if (!$hasServiceObject && !$hasCraftsmen && !$hasSalespeople && !$hasExperience) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '购物车服务设置无效，请重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'service_settings_missing']
            );
        }

        // 权益核销没有本次销售业绩。即使直接调用事务服务，也不能写入销售人快照。
        if ($isEntitlement && $hasSalespeople) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '权益项目只支持设置手艺人。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'entitlement_salespeople_forbidden']
            );
        }

        // 销售人属于销售明细，不依赖项目服务设置；产品和卡项同样可以分配销售业绩。
        if ($isSale && !$isSaleProject) {
            if ($hasServiceObject || $hasCraftsmen || $hasExperience || !$hasSalespeople) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '该商品只支持设置销售人。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'sale_non_project_setting_invalid']
                );
            }
            $salespeople = $this->authoritativeSalespeopleInTx($settings['salespeople'], $operatorScope);
            $affected = Db::name(self::LINE_TABLE)
                ->where('id', (int)$line['id'])
                ->where('workspace_id', $workspaceId)
                ->where('line_key', $lineKey)
                ->update([
                    'salespeople_json' => $this->encodeJson($salespeople),
                    'update_time' => time(),
                ]);
            if ((int)$affected < 0) {
                throw $this->incompleteLineSettings($lineKey, 'salespeople_update_failed');
            }
            $this->updateDraft($workspaceId, []);
            return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
        }

        $serviceObject = (string)($line['service_object'] ?? '');
        if ($isSaleProject && $serviceObject === '') {
            $serviceObject = 'self';
        }
        if (!in_array($serviceObject, ['self', 'friend'], true)) {
            throw $this->incompleteLineSettings($lineKey, 'stored_service_object_invalid');
        }
        if ($hasServiceObject) {
            $serviceObject = (string)$settings['serviceObject'];
            if (!in_array($serviceObject, ['self', 'friend'], true)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '服务对象无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'service_object_invalid']
                );
            }
        }

        $craftsmen = $this->decodeStoredCraftsmen($line, $lineKey);
        if ($hasCraftsmen) {
            if (!is_array($settings['craftsmen'])) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'craftsman_ids_invalid']
                );
            }
            $craftsmen = $this->authoritativeCraftsmenInTx(
                array_values($settings['craftsmen']),
                $operatorScope
            );
        } else {
            // partial 更新也重验已保存人员，避免手艺人离职后通过切换其它字段继续保留。
            $craftsmen = $this->authoritativeCraftsmenInTx(
                $this->craftsmanSelectionsFromSnapshot($craftsmen, $lineKey),
                $operatorScope
            );
        }

        $isExperience = (int)($line['is_experience'] ?? -1);
        if (!in_array($isExperience, [0, 1], true)) {
            throw $this->incompleteLineSettings($lineKey, 'stored_experience_flag_invalid');
        }
        if ($hasExperience) {
            if (!is_bool($settings['isExperience'])
                && !(is_int($settings['isExperience'])
                    && in_array($settings['isExperience'], [0, 1], true))) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '体验项目设置无效，请重新操作。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'experience_flag_invalid']
                );
            }
            $isExperience = $settings['isExperience'] ? 1 : 0;
        }

        // 遗留草稿的销售人快照不属于权益服务，后续任意权益设置保存都会清空它。
        $salespeople = $isEntitlement ? [] : $this->decodeStoredSalespeople($line, $lineKey);
        if ($hasSalespeople) {
            if (!is_array($settings['salespeople'])) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选销售人无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'salespeople_invalid']
                );
            }
            $salespeople = $this->authoritativeSalespeopleInTx($settings['salespeople'], $operatorScope);
        }

        // 权益仅持久化服务设置与手艺人；普通项目仍可同事务持久化销售人快照。
        $affected = Db::name(self::LINE_TABLE)
            ->where('id', (int)$line['id'])
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->update([
                'service_object' => $serviceObject,
                'craftsmen_json' => $this->encodeJson($craftsmen),
                'salespeople_json' => $this->encodeJson($salespeople),
                'is_experience' => $isExperience,
                'update_time' => time(),
            ]);
        if ((int)$affected < 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车服务设置保存失败，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'service_settings_update_failed']
            );
        }
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** Apply one confirmed salesperson allocation to every current-purchase line atomically. */
    public function applySalespeopleToAllSaleLinesInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $salespeople
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceApplySalespeopleToAllSaleLines');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $rows = $this->lineRows($workspaceId, true);
        $saleIds = [];
        foreach ($rows as $row) {
            if ((string)($row['line_role'] ?? '') !== self::ROLE_SALE) {
                continue;
            }
            if ($this->isCardOperationUpgradeSaleRow((array)$row)) {
                throw $this->cardOperationUpgradeCartLocked();
            }
            $saleIds[] = (int)($row['id'] ?? 0);
        }
        $saleIds = array_values(array_filter($saleIds));
        if (!$saleIds) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '当前没有可应用销售人的本次购买商品。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'sale_lines_missing_for_apply_all']
            );
        }
        $normalized = $this->authoritativeSalespeopleInTx($salespeople, $operatorScope);
        if (!$normalized) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '请先选择要应用到全部商品的销售人。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'salespeople_missing_for_apply_all']
            );
        }
        Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $saleIds)
            ->update([
                'salespeople_json' => $this->encodeJson($normalized),
                'update_time' => time(),
            ]);
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** Apply one confirmed craftsman allocation to every current service-project line atomically. */
    public function applyCraftsmenToAllServiceLinesInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $craftsmen
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceApplyCraftsmenToAllServiceLines');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $serviceLineIds = [];
        foreach ($this->lineRows($workspaceId, true) as $row) {
            $lineRole = (string)($row['line_role'] ?? '');
            $authoritySnapshot = json_decode((string)($row['authority_snapshot_json'] ?? ''), true);
            $isCustomCard = is_array($authoritySnapshot)
                && (string)($authoritySnapshot['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
            $isSaleProject = $lineRole === self::ROLE_SALE
                && (int)($row['project_id'] ?? 0) > 0
                && !$isCustomCard;
            $isEntitlementService = $lineRole === self::ROLE_ENTITLEMENT;
            if (!$isSaleProject && !$isEntitlementService) {
                continue;
            }
            if ($isSaleProject && $this->isCardOperationUpgradeSaleRow((array)$row)) {
                throw $this->cardOperationUpgradeCartLocked();
            }
            $serviceLineIds[] = (int)($row['id'] ?? 0);
        }
        $serviceLineIds = array_values(array_filter($serviceLineIds));
        if (!$serviceLineIds) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '当前没有可应用手艺人的服务项目。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'service_lines_missing_for_apply_all']
            );
        }
        $normalized = $this->authoritativeCraftsmenInTx($craftsmen, $operatorScope);
        if (!$normalized) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '请先选择要应用到全部服务项目的手艺人。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsmen_missing_for_apply_all']
            );
        }
        Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $serviceLineIds)
            ->update([
                'craftsmen_json' => $this->encodeJson($normalized),
                'update_time' => time(),
            ]);
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** Persist debt intent on exactly one current-purchase line. */
    public function updateLineDebtInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        int $debtAmountCents
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceUpdateLineDebt');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $line = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->where('line_role', self::ROLE_SALE)
            ->lock(true)
            ->find();
        if (!$line) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '该购物车商品不存在或已经删除。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey]
            );
        }
        $lineAmountCents = $this->multiplyCents(
            (int)($line['unit_price_cents'] ?? -1),
            (int)($line['quantity'] ?? 0),
            $lineKey
        );
        if ($debtAmountCents < 0 || $debtAmountCents > $lineAmountCents) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '欠款金额不能超过该条商品的应收金额。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'line_debt_amount_invalid', 'line_id' => $lineKey]
            );
        }
        Db::name(self::LINE_TABLE)
            ->where('id', (int)$line['id'])
            ->where('workspace_id', $workspaceId)
            ->update([
                'debt_amount_cents' => $debtAmountCents,
                'update_time' => time(),
            ]);
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function assertSelectedMemberInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        int $memberId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceAssertMember');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        if ((string)($draft['customer_mode'] ?? '') !== self::MODE_MEMBER
            || (int)($draft['member_id'] ?? 0) !== $memberId) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTOR_SESSION_EXPIRED,
                '当前会员已经变化，请重新打开卡内项目后选择。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'workspace_member_changed']
            );
        }
        return $draft;
    }

    public function removeLineInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        ?callable $beforeDelete = null
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceRemoveLine');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $line = $this->lockLine($workspaceId, $lineKey);
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        if ($beforeDelete !== null) {
            $beforeDelete((array)$line);
        }
        $deleted = Db::name(self::LINE_TABLE)
            ->where('id', (int)$line['id'])
            ->where('workspace_id', $workspaceId)
            ->delete();
        if ((int)$deleted !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该购物车项目已经变化，请刷新后重试。',
                ['line_id' => $lineKey, 'reason' => 'cashier_line_delete_race']
            );
        }
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /**
     * 清空当前未结账草稿的商品行，但保留已选客户。
     * 由单一工作台版本锁保护，不能由前端逐条删除，以免并发失败时留下半空草稿。
     *
     * @param null|callable(array):void $beforeDelete
     */
    public function clearLinesInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        ?callable $beforeDelete = null
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceClearLines');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $lines = $this->lineRows($workspaceId, true);
        if ($beforeDelete !== null) {
            foreach ($lines as $line) {
                $beforeDelete((array)$line);
            }
        }
        Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)->delete();
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /**
     * 重开订单的明确确认路径：在同一事务、同一工作台锁下丢弃未结账草稿。
     * 不能由页面先逐条删除再发起重开，否则中途失败会留下半空购物车。
     */
    public function clearForReopenInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceClearForReopen');
        $this->lockForSaleMutationInTx($workspaceId, $stateContextId, $operatorScope);
        // 先锁定当前行，确保确认弹窗后的并发编辑不会被静默覆盖。
        $this->lineRows($workspaceId, true);
        Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)->delete();
        $this->updateDraft($workspaceId, [
            'member_id' => 0,
            'customer_mode' => self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function changeLineQuantityInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        int $delta,
        callable $entitlementValidator,
        callable $saleValidator
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceChangeLineQuantity');
        if ($delta === 0 || abs($delta) > 1000) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '购物车数量变化无效，请重新操作。',
                CashierV3ResultCode::STATUS_FAILED,
                ['field' => 'delta']
            );
        }
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $rows = $this->lineRows($workspaceId, true);
        $line = null;
        foreach ($rows as $row) {
            if ((string)($row['line_key'] ?? '') === $lineKey) {
                $line = $row;
                break;
            }
        }
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        if ($this->isCardOperationUpgradeSaleRow((array)$line)) {
            throw $this->cardOperationUpgradeCartLocked();
        }
        $current = (int)($line['quantity'] ?? 0);
        $next = $current + $delta;
        if ($current <= 0 || $next <= 0 || $next > 1000000) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '购物车数量必须大于 0。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'quantity' => $next]
            );
        }
        if ((string)($line['line_role'] ?? '') === self::ROLE_ENTITLEMENT) {
            $aggregateQuantity = $next;
            foreach ($rows as $row) {
                if ((int)($row['id'] ?? 0) === (int)($line['id'] ?? 0)) {
                    continue;
                }
                if ((string)($row['line_role'] ?? '') === self::ROLE_ENTITLEMENT
                    && (int)($row['member_id'] ?? 0) === (int)($line['member_id'] ?? 0)
                    && (int)($row['holder_id'] ?? 0) === (int)($line['holder_id'] ?? 0)
                    && (int)($row['source_detail_id'] ?? 0) === (int)($line['source_detail_id'] ?? 0)) {
                    if ((int)($row['project_id'] ?? 0) !== (int)($line['project_id'] ?? 0)) {
                        throw $this->incompleteDraftLine(
                            (string)($row['line_key'] ?? ''),
                            'entitlement_source_project_mismatch'
                        );
                    }
                    $otherQuantity = (int)($row['quantity'] ?? 0);
                    if ($otherQuantity <= 0) {
                        throw $this->incompleteDraftLine(
                            (string)($row['line_key'] ?? ''),
                            'entitlement_line_quantity_invalid'
                        );
                    }
                    $aggregateQuantity += $otherQuantity;
                }
            }
            $entitlementValidator($line, $next, $aggregateQuantity);
        } elseif ((string)($line['line_role'] ?? '') === self::ROLE_SALE) {
            $saleValidator($line, $next);
        } else {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车项目类型异常，请刷新工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey]
            );
        }
        $updated = Db::name(self::LINE_TABLE)
            ->where('id', (int)$line['id'])
            ->where('workspace_id', $workspaceId)
            ->where('quantity', $current)
            ->update(['quantity' => $next, 'update_time' => time()]);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该购物车项目已经变化，请刷新后重试。',
                ['line_id' => $lineKey, 'reason' => 'cashier_line_quantity_race']
            );
        }
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /**
     * 最终 prepare-checkout 的 sale 来源集合。这里只做行锁内重验和 DTO 组装，
     * 不创建订单、支付、库存、业绩或会员卡事实。
     */
    public function checkoutSaleSourceSetInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        callable $saleSourceLoader
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceCheckoutSaleSources');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $rows = $this->lineRows($workspaceId, true);
        // 先执行完整草稿校验，防止损坏行绕过结账来源重验。
        $publicDraft = $this->toPublicDraft($draft, $rows);
        $sources = [];
        foreach ($rows as $row) {
            if ((string)($row['line_role'] ?? '') !== self::ROLE_SALE) {
                continue;
            }
            $source = $saleSourceLoader($row);
            if (!is_array($source)) {
                throw $this->incompleteDraftLine(
                    (string)($row['line_key'] ?? ''),
                    'cashier_sale_checkout_source_invalid'
                );
            }
            $sources[] = $source;
        }
        return [
            'contractVersion' => CashierV3SaleCatalogServices::CHECKOUT_SOURCE_CONTRACT_VERSION,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'customerMode' => (string)$draft['customer_mode'],
            'memberId' => (int)$draft['member_id'],
            'lineFingerprint' => (string)$draft['line_fingerprint'],
            'lines' => $sources,
            // Internal checkout preparation data. Both values were assembled
            // from the same locked workspace rows as the sale source set.
            'publicDraft' => $publicDraft,
            'storedRows' => array_values($rows),
            'storedDraft' => (array)$draft,
            'complete' => true,
        ];
    }

    /**
     * Revalidate the salesperson snapshots bound to the exact checkout lines.
     *
     * @return array<string,array<int,array>>
     */
    public function lockedSalespeopleByCheckoutLineInTx(
        string $workspaceId,
        array $checkoutLines,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceCheckoutSalespeople');
        $rows = $this->lineRows($workspaceId, true);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string)($row['line_key'] ?? '')] = (array)$row;
        }
        $result = [];
        foreach ($checkoutLines as $line) {
            if ((string)($line['line_role'] ?? '') !== self::ROLE_SALE) {
                continue;
            }
            $checkoutLineId = trim((string)($line['line_id'] ?? ''));
            $authorityKey = trim((string)($line['authority_key'] ?? ''));
            $workspaceLineKey = strpos($authorityKey, 'sale:') === 0
                ? substr($authorityKey, 5)
                : '';
            $row = $byKey[$workspaceLineKey] ?? null;
            if ($checkoutLineId === '' || !$row
                || (string)($row['line_role'] ?? '') !== self::ROLE_SALE) {
                throw $this->incompleteLineSettings($workspaceLineKey, 'checkout_salespeople_binding_missing');
            }
            $stored = $this->decodeStoredSalespeople($row, $workspaceLineKey);
            $selections = array_map(static function (array $person): array {
                return [
                    'staffId' => (int)($person['staffId'] ?? $person['id'] ?? 0),
                    'allocationWeight' => (int)($person['allocationWeight'] ?? 0),
                ];
            }, $stored);
            $result[$checkoutLineId] = $selections
                ? $this->authoritativeSalespeopleInTx($selections, $operatorScope)
                : [];
        }
        return $result;
    }

    /**
     * Clear the exact sale-only cart that produced a succeeded checkout and
     * return the workspace to its default guest state. The request drafts are
     * rechecked against locked workspace rows before anything is deleted.
     *
     * @param array<int,array> $checkoutLines locked checkout-request line rows
     */
    public function completeSaleOnlyCheckoutInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $checkoutLines
    ): array {
        return $this->completeCheckoutInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $checkoutLines
        );
    }

    public function completeCheckoutInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $checkoutLines
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceCheckoutCompletion');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $workspaceRows = $this->lineRows($workspaceId, true);
        if (!$workspaceRows || count($workspaceRows) !== count($checkoutLines)) {
            throw CashierV3CommandException::versionConflict(
                '购物车已经变化，请刷新后重新结账。',
                ['reason' => 'cashier_checkout_workspace_line_count_changed']
            );
        }
        $this->matchCheckoutWorkspaceLines($workspaceRows, $checkoutLines);

        $deleted = (int)Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->delete();
        if ($deleted !== count($workspaceRows)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '结账后购物车清理失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_checkout_workspace_clear_incomplete']
            );
        }
        $this->updateDraft($workspaceId, [
            'member_id' => 0,
            'customer_mode' => self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
            'resumed_hang_order_id' => '',
            'order_note' => '',
            'supplement_enabled' => 0,
            'supplement_business_date' => null,
            'supplement_reason' => '',
            'supplement_operator_id' => 0,
            'supplement_operator_name_snapshot' => '',
            'supplement_operated_at' => 0,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function transferToHangInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $expectedLineFingerprint
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceHangTransfer');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $workspaceRows = $this->lineRows($workspaceId, true);
        $this->assertNoPendingCardOperationUpgradeLine($workspaceRows);
        $publicDraft = $this->toPublicDraft($draft, $workspaceRows);
        if (!$workspaceRows || ($publicDraft['complete'] ?? false) !== true) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '请先添加需要挂单的项目。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_hang_workspace_empty']
            );
        }
        if ($expectedLineFingerprint === ''
            || !hash_equals((string)$publicDraft['lineFingerprint'], $expectedLineFingerprint)) {
            throw CashierV3CommandException::versionConflict(
                '购物车已经变化，请重新打开挂单页面。',
                ['reason' => 'cashier_hang_workspace_fingerprint_changed']
            );
        }
        $deleted = (int)Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->delete();
        if ($deleted !== count($workspaceRows)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '挂单后购物车清理失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_hang_workspace_clear_incomplete']
            );
        }
        $this->updateDraft($workspaceId, [
            'member_id' => 0,
            'customer_mode' => self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
        ]);
        return [
            'hangDraft' => $publicDraft,
            // Only the caller that already owns this transaction can pass these
            // raw authority rows into the immutable hang-order plan.  The
            // public draft is deliberately insufficient for future restoration.
            'frozenWorkspaceRows' => array_values($workspaceRows),
            'cashierDraft' => $this->readDraft($workspaceId, $stateContextId, $operatorScope, true),
        ];
    }

    /**
     * Restore a new-contract, sale-only hang by atomically replacing the
     * current workspace. The caller locks the hang first; this method locks
     * both draft and existing rows, removes the old draft rows, then writes
     * the immutable hang snapshots in the same transaction.
     *
     * @param array<int,array> $frozenSaleRows selected fields decoded from
     *   immutable hang-line workspace snapshots, ordered by original line_no.
     */
    public function restoreSaleOnlyHangInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $hangOrderId,
        int $memberId,
        array $frozenSaleRows
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceRestoreSaleOnlyHang');
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1 || $memberId < 0) {
            throw $this->incompleteDraft('cashier_hang_restore_identity_invalid');
        }
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        if (trim((string)($draft['resumed_hang_order_id'] ?? '')) !== '') {
            throw CashierV3CommandException::versionConflict(
                '当前收银台已经提取了另一张挂单，请先完成该挂单结账。',
                ['reason' => 'cashier_hang_restore_workspace_already_bound']
            );
        }
        $existingRows = $this->lineRows($workspaceId, true);
        if ($frozenSaleRows === [] || count($frozenSaleRows) > 1000) {
            throw $this->incompleteDraft('cashier_hang_restore_lines_invalid');
        }

        $records = [];
        $seenLineKeys = [];
        $now = time();
        foreach (array_values($frozenSaleRows) as $index => $line) {
            if (!is_array($line)) {
                throw $this->incompleteDraft('cashier_hang_restore_line_invalid');
            }
            $record = $this->normalizeRestoredSaleLine(
                $line,
                $workspaceId,
                $memberId,
                $index + 1
            );
            if (isset($seenLineKeys[$record['line_key']])) {
                throw $this->incompleteDraft('cashier_hang_restore_line_duplicate');
            }
            $seenLineKeys[$record['line_key']] = true;
            $record['add_time'] = $now;
            $record['update_time'] = $now;
            $records[] = $record;
        }
        if ($existingRows !== []) {
            $deleted = (int)Db::name(self::LINE_TABLE)
                ->where('workspace_id', $workspaceId)
                ->delete();
            if ($deleted !== count($existingRows)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '替换当前购物车失败，本次提单已回滚，请重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'cashier_hang_restore_workspace_replace_incomplete']
                );
            }
        }
        $affected = (int)Db::name(self::LINE_TABLE)->insertAll($records);
        if ($affected !== count($records)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '提取挂单购物车失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_hang_restore_line_insert_incomplete']
            );
        }
        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'customer_mode' => $memberId > 0 ? self::MODE_MEMBER : self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
            'resumed_hang_order_id' => $hangOrderId,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function lockCheckoutServiceIntentsInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        array $checkoutLines
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceCheckoutServiceIntents');
        $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $workspaceRows = $this->lineRows($workspaceId, true);
        if (!$workspaceRows || count($workspaceRows) !== count($checkoutLines)) {
            throw CashierV3CommandException::versionConflict(
                '购物车已经变化，请刷新后重新结账。',
                ['reason' => 'cashier_checkout_workspace_line_count_changed']
            );
        }
        $matched = $this->matchCheckoutWorkspaceLines($workspaceRows, $checkoutLines);
        $intents = [];
        foreach ($checkoutLines as $checkoutLine) {
            if ((string)($checkoutLine['line_role'] ?? '') !== self::ROLE_ENTITLEMENT) {
                continue;
            }
            $checkoutLineId = (string)($checkoutLine['line_id'] ?? '');
            $workspaceLine = $matched[$checkoutLineId] ?? null;
            if (!is_array($workspaceLine)) {
                throw $this->incompleteDraftLine($checkoutLineId, 'checkout_service_intent_missing');
            }
            $lineKey = (string)$workspaceLine['line_key'];
            $craftsmen = $this->decodeStoredCraftsmen($workspaceLine, $lineKey);
            // The final completion authority must receive the locked allocation
            // weights as well as the selected staff IDs. Dropping the weights
            // here turns every later authority snapshot into a zero-weight plan.
            $craftsmanSelections = $this->craftsmanSelectionsFromSnapshot($craftsmen, $lineKey);
            $craftsmanIds = array_map(static function (array $selection): int {
                return (int)$selection['staffId'];
            }, $craftsmanSelections);
            if ($craftsmanIds === []) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '项目必须先选择手艺人才能完成服务。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'craftsman_required']
                );
            }
            $serviceObject = (string)($workspaceLine['service_object'] ?? '');
            $isExperience = (int)($workspaceLine['is_experience'] ?? -1);
            if (!in_array($serviceObject, ['self', 'friend'], true)
                || !in_array($isExperience, [0, 1], true)) {
                throw $this->incompleteDraftLine($lineKey, 'checkout_service_settings_invalid');
            }
            $intents[] = [
                'checkoutLineId' => $checkoutLineId,
                'workspaceLineId' => $lineKey,
                'quantity' => (int)$workspaceLine['quantity'],
                'serviceObject' => $serviceObject,
                'isExperience' => $isExperience === 1,
                'craftsmanIds' => $craftsmanIds,
                'craftsmanSettingsById' => array_reduce(
                    $craftsmanSelections,
                    static function (array $settings, array $selection): array {
                        $settings[(int)$selection['staffId']] = [
                            'laborWeight' => (int)$selection['laborWeight'],
                            'isPointCustomer' => (bool)$selection['isPointCustomer'],
                        ];
                        return $settings;
                    },
                    []
                ),
                'craftsmen' => $craftsmen,
                'displaySnapshot' => $this->decodeStoredDisplaySnapshot($workspaceLine, $lineKey),
            ];
        }
        return $intents;
    }

    /** @return array<string,array> checkout line id => locked workspace row */
    private function matchCheckoutWorkspaceLines(array $workspaceRows, array $checkoutLines): array
    {
        $workspaceByKey = [];
        foreach ($workspaceRows as $row) {
            $workspaceByKey[(string)($row['line_key'] ?? '')] = $row;
        }
        $matched = [];
        foreach ($checkoutLines as $index => $checkoutLine) {
            $authorityKey = (string)($checkoutLine['authority_key'] ?? '');
            $role = (string)($checkoutLine['line_role'] ?? '');
            $lineKey = $role === self::ROLE_SALE && strpos($authorityKey, 'sale:') === 0
                ? substr($authorityKey, strlen('sale:'))
                : ($role === self::ROLE_ENTITLEMENT ? $authorityKey : '');
            $workspaceLine = $workspaceByKey[$lineKey] ?? null;
            $quantity = (int)($checkoutLine['quantity'] ?? 0);
            $sameRole = is_array($workspaceLine)
                && (string)($workspaceLine['line_role'] ?? '') === $role;
            $saleMatches = $role === self::ROLE_SALE
                && $sameRole
                && (int)($workspaceLine['catalog_product_id'] ?? 0)
                    === (int)($checkoutLine['source_id'] ?? 0)
                && (int)($workspaceLine['source_version'] ?? 0)
                    === (int)($checkoutLine['source_version'] ?? 0)
                && $this->checkedLineAmount(
                    (int)($workspaceLine['unit_price_cents'] ?? -1),
                    $quantity
                ) === (int)($checkoutLine['sale_amount_cents'] ?? -1)
                && $this->checkedLineAmount(
                    (int)($workspaceLine['original_unit_price_cents'] ?? -1),
                    $quantity
                ) === (int)($checkoutLine['original_amount_cents'] ?? -1);
            $entitlementMatches = $role === self::ROLE_ENTITLEMENT
                && $sameRole
                && (int)($workspaceLine['holder_id'] ?? 0)
                    === (int)($checkoutLine['source_id'] ?? 0)
                && (int)($workspaceLine['source_detail_id'] ?? 0)
                    === (int)($checkoutLine['entitlement_source_detail_id'] ?? 0)
                && (int)($workspaceLine['project_id'] ?? 0)
                    === (int)($checkoutLine['project_id'] ?? 0)
                && (int)($workspaceLine['source_version'] ?? 0)
                    === (int)($checkoutLine['source_version'] ?? 0)
                && (int)($workspaceLine['detail_version'] ?? 0)
                    === (int)($checkoutLine['project_version'] ?? 0);
            if (!$sameRole
                || $quantity <= 0
                || (int)($workspaceLine['quantity'] ?? 0) !== $quantity
                || (!$saleMatches && !$entitlementMatches)) {
                throw CashierV3CommandException::versionConflict(
                    '购物车已经变化，请刷新后重新结账。',
                    ['reason' => 'cashier_checkout_workspace_line_changed', 'index' => $index]
                );
            }
            $checkoutLineId = (string)($checkoutLine['line_id'] ?? '');
            if ($checkoutLineId === '' || isset($matched[$checkoutLineId])) {
                throw CashierV3CommandException::versionConflict(
                    '购物车已经变化，请刷新后重新结账。',
                    ['reason' => 'cashier_checkout_line_identity_invalid', 'index' => $index]
                );
            }
            $matched[$checkoutLineId] = $workspaceLine;
        }
        return $matched;
    }

    /**
     * Read-only checkout discovery. Gateway calls this before locking any
     * business resource and again after the complete resource set is locked.
     */
    public function discoverCheckoutDraft(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        $this->readiness->assertReady();
        $draft = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->find();
        $this->assertDraftBinding($draft, $workspaceId, $stateContextId, $operatorScope);
        $rows = $this->lineRows($workspaceId, false);
        $public = $this->toPublicDraft($draft, $rows);
        if (!$rows || empty($public['checkoutComposition']['primaryAction'])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车为空，请先添加需要结账或使用的项目。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_checkout_cart_empty']
            );
        }
        return [
            'draft' => (array)$draft,
            'rows' => array_values($rows),
            'publicDraft' => $public,
            'fingerprint' => (string)$draft['line_fingerprint'],
        ];
    }

    /**
     * 投影读取不锁草稿；命令进入 handler 后仍须再次锁定并核对会员。
     */
    public function requireSelectedMember(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        int $requestedMemberId
    ): array {
        $this->readiness->assertReady();
        $row = Db::name(self::DRAFT_TABLE)->where('workspace_id', $workspaceId)->find();
        $this->assertDraftBinding($row, $workspaceId, $stateContextId, $operatorScope);
        $memberId = (int)($row['member_id'] ?? 0);
        if ((string)($row['customer_mode'] ?? '') !== self::MODE_MEMBER
            || $memberId <= 0
            || $requestedMemberId <= 0
            || $memberId !== $requestedMemberId) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_MEMBER_REQUIRED,
                '请先选择需要使用权益的会员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'workspace_member_not_selected']
            );
        }
        return $row;
    }

    public function readDraft(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        bool $lock = false
    ): array {
        $this->readiness->assertReady();
        $query = Db::name(self::DRAFT_TABLE)->where('workspace_id', $workspaceId);
        if ($lock) {
            CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceReadLocked');
            $query->lock(true);
        }
        $draft = $query->find();
        $this->assertDraftBinding($draft, $workspaceId, $stateContextId, $operatorScope);
        $rows = $this->lineRows($workspaceId, $lock);
        return $this->toPublicDraft($draft, $rows);
    }

    /**
     * 根投影显式选择本入口时，尚未创建草稿才返回只读游客空草稿。
     * 不写库，也不改变 readDraft() 的严格语义，避免其他空根被全局猜成游客。
     */
    public function readDraftOrSyntheticGuest(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        $this->readiness->assertReady();
        $this->assertWorkspaceIdentity($workspaceId, $stateContextId, $operatorScope);
        $draft = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->find();
        if (!$draft) {
            return $this->toPublicDraft([
                'workspace_id' => $workspaceId,
                'state_context_id' => $stateContextId,
                'store_id' => $operatorScope->storeId(),
                'operator_id' => $operatorScope->operatorId(),
                'member_id' => 0,
                'customer_mode' => self::MODE_GUEST,
                'draft_status' => self::STATUS_EDITING,
                'line_fingerprint' => hash('sha256', '[]'),
            ], []);
        }
        $this->assertDraftBinding($draft, $workspaceId, $stateContextId, $operatorScope);
        return $this->toPublicDraft($draft, $this->lineRows($workspaceId, false));
    }

    private function lockOrCreateDraft(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        $this->readiness->assertReady();
        $this->assertWorkspaceIdentity($workspaceId, $stateContextId, $operatorScope);
        $row = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->lock(true)
            ->find();
        if (!$row) {
            $now = time();
            try {
                Db::name(self::DRAFT_TABLE)->insert([
                    'workspace_id' => $workspaceId,
                    'state_context_id' => $stateContextId,
                    'store_id' => $operatorScope->storeId(),
                    'operator_id' => $operatorScope->operatorId(),
                    'member_id' => 0,
                    'customer_mode' => self::MODE_GUEST,
                    'draft_status' => self::STATUS_EDITING,
                    'line_fingerprint' => hash('sha256', '[]'),
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $exception) {
                $row = Db::name(self::DRAFT_TABLE)
                    ->where('workspace_id', $workspaceId)
                    ->lock(true)
                    ->find();
                if (!$row) {
                    throw $exception;
                }
            }
            if (!$row) {
                $row = Db::name(self::DRAFT_TABLE)
                    ->where('workspace_id', $workspaceId)
                    ->lock(true)
                    ->find();
            }
        }
        $this->assertDraftBinding($row, $workspaceId, $stateContextId, $operatorScope);
        return $row;
    }

    private function assertDraftBinding(
        $row,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): void {
        $this->assertWorkspaceIdentity($workspaceId, $stateContextId, $operatorScope);
        if (!$row
            || (string)($row['workspace_id'] ?? '') !== $workspaceId
            || (string)($row['state_context_id'] ?? '') !== $stateContextId
            || (int)($row['store_id'] ?? 0) !== $operatorScope->storeId()
            || (int)($row['operator_id'] ?? 0) !== $operatorScope->operatorId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台已经变化，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_workspace_draft_binding_mismatch']
            );
        }
    }

    /** A restored hang is immutable until it either settles or rolls back. */
    private function assertNotResumedHangMutation(array $draft): void
    {
        $hangOrderId = trim((string)($draft['resumed_hang_order_id'] ?? ''));
        if ($hangOrderId === '') {
            return;
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '已提取的挂单必须按原内容完成结账，不能修改会员或购物车。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => 'cashier_resumed_hang_mutation_blocked', 'hang_order_id' => $hangOrderId]
        );
    }

    private function assertWorkspaceIdentity(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): void {
        $expected = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if ($stateContextId === '' || $workspaceId === '' || !hash_equals($expected, $workspaceId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_workspace_identity_invalid']
            );
        }
    }

    private function deleteEntitlementLines(string $workspaceId): void
    {
        Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_role', self::ROLE_ENTITLEMENT)
            ->delete();
    }

    private function lockLine(string $workspaceId, string $lineKey)
    {
        $lineKey = trim($lineKey);
        if ($lineKey === '' || strlen($lineKey) > 64 || strpos($lineKey, "\0") !== false) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '购物车项目标识无效，请刷新后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['field' => 'lineId']
            );
        }
        return Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->lock(true)
            ->find();
    }

    private function rebindSaleLines(string $workspaceId, int $memberId, bool $clearDebt = false): void
    {
        $changes = [
            'member_id' => $memberId,
            'update_time' => time(),
        ];
        if ($clearDebt) {
            $changes['debt_amount_cents'] = 0;
        }
        Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_role', self::ROLE_SALE)
            ->update($changes);
    }

    private function updateDraft(string $workspaceId, array $changes): void
    {
        $rows = $this->lineRows($workspaceId, true);
        $changes['line_fingerprint'] = $this->lineFingerprint($rows);
        $changes['update_time'] = time();
        $affected = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->update($changes);
        if ((int)$affected < 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '收银购物车草稿保存失败，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function checkedLineAmount(int $unitAmountCents, int $quantity): int
    {
        if ($unitAmountCents < 0 || $quantity <= 0
            || ($unitAmountCents > 0 && $quantity > intdiv(PHP_INT_MAX, $unitAmountCents))) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车金额异常，请删除对应商品后重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_checkout_workspace_amount_invalid']
            );
        }
        return $unitAmountCents * $quantity;
    }

    /** @return array<int,array> */
    private function authoritativeCraftsmenInTx(
        array $selections,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceResolveCraftsmen');
        if (!$selections) {
            return [];
        }
        if (count($selections) > 20) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '手艺人最多选择 20 位。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsmen_too_many']
            );
        }

        $seen = [];
        $weights = [];
        $pointFlags = [];
        $weightSum = 0;
        foreach ($selections as $selection) {
            $staffId = is_array($selection) ? (int)($selection['staffId'] ?? 0) : 0;
            $weight = is_array($selection) ? (int)($selection['laborWeight'] ?? 0) : 0;
            $duplicate = $staffId > 0 && isset($seen[$staffId]);
            if ($staffId <= 0 || $weight <= 0 || $weight > 100 || $duplicate) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => $duplicate ? 'duplicate_craftsman' : 'craftsman_id_invalid']
                );
            }
            $seen[$staffId] = true;
            $weights[$staffId] = $weight;
            $pointFlags[$staffId] = is_array($selection) && !empty($selection['isPointCustomer']);
            $weightSum += $weight;
        }
        if ($weightSum !== 100) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '手艺人分配比例合计必须为 100%。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsman_weight_sum_invalid']
            );
        }

        $lockIds = array_keys($seen);
        sort($lockIds, SORT_NUMERIC);
        $rows = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
            ->whereIn('ss.id', $lockIds)
            ->where('ss.store_id', $operatorScope->storeId())
            ->where('ss.status', 1)
            ->where('ss.is_del', 0)
            ->where('ss.employee_id', '>', 0)
            ->where('ss.cashier_craftsman_enabled', 1)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,ss.cashier_craftsman_enabled,e.name as employee_name')
            ->order('ss.id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $byId = [];
        foreach ((array)$rows as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }
        if (count($byId) !== count($selections)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '所选手艺人已停用、离职、不属于当前门店或已关闭手艺人资格，请重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsman_not_active_in_store']
            );
        }

        $craftsmen = [];
        foreach ($selections as $index => $selection) {
            $staffId = (int)$selection['staffId'];
            $row = $byId[$staffId] ?? null;
            $name = trim((string)($row['employee_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['staff_name'] ?? ''));
            }
            if (!$row || $name === '') {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人资料不完整，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['staff_id' => $staffId, 'reason' => 'craftsman_profile_incomplete']
                );
            }
            $craftsmen[] = [
                'id' => $staffId,
                'staffId' => $staffId,
                'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)$row['store_id'],
                'name' => $name,
                'isPrimary' => $index === 0,
                'sequence' => $index + 1,
                'laborWeight' => $weights[$staffId],
                'isPointCustomer' => $pointFlags[$staffId],
            ];
        }
        return $craftsmen;
    }

    /**
     * Re-read every selected salesperson under the current store lock. Client
     * names, employee types and allocation snapshots are never persisted.
     *
     * @param array<int,array{staffId:int,allocationWeight:int}> $selections
     * @return array<int,array>
     */
    private function authoritativeSalespeopleInTx(
        array $selections,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceResolveSalespeople');
        if (!$selections) {
            return [];
        }
        if (count($selections) > 20) {
            throw $this->incompleteLineSettings('', 'salespeople_too_many');
        }
        $weights = [];
        $sum = 0;
        foreach ($selections as $selection) {
            $staffId = is_array($selection) ? (int)($selection['staffId'] ?? 0) : 0;
            $weight = is_array($selection) ? (int)($selection['allocationWeight'] ?? 0) : 0;
            if ($staffId <= 0 || $weight <= 0 || $weight > 100 || isset($weights[$staffId])) {
                throw $this->incompleteLineSettings('', 'salesperson_selection_invalid');
            }
            $weights[$staffId] = $weight;
            $sum += $weight;
        }
        if ($sum !== 100) {
            throw $this->incompleteLineSettings('', 'salesperson_weight_sum_invalid');
        }
        $staffIds = array_keys($weights);
        sort($staffIds, SORT_NUMERIC);
        $rows = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
            ->whereIn('ss.id', $staffIds)
            ->where('ss.store_id', $operatorScope->storeId())
            ->where('ss.status', 1)
            ->where('ss.is_del', 0)
            ->where('ss.employee_id', '>', 0)
            ->where('ss.cashier_salesperson_enabled', 1)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,ss.cashier_salesperson_enabled,e.name as employee_name,e.employment_type_code,e.employment_type_version')
            ->order('ss.id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $byId = [];
        foreach ((array)$rows as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }
        if (count($byId) !== count($selections)) {
            throw $this->incompleteLineSettings('', 'salesperson_not_active_in_store');
        }
        $result = [];
        foreach ($selections as $index => $selection) {
            $staffId = (int)$selection['staffId'];
            $row = $byId[$staffId] ?? null;
            $name = trim((string)($row['employee_name'] ?? $row['staff_name'] ?? ''));
            $type = (string)($row['employment_type_code'] ?? '');
            $typeVersion = (int)($row['employment_type_version'] ?? 0);
            if (!$row || $name === '' || !in_array($type, ['internal', 'partner', 'outsourced'], true) || $typeVersion <= 0) {
                throw $this->incompleteLineSettings('', 'salesperson_profile_incomplete');
            }
            $result[] = [
                'id' => $staffId,
                'staffId' => $staffId,
                'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)$row['store_id'],
                'name' => $name,
                'allocationWeight' => (int)$selection['allocationWeight'],
                'sequence' => $index + 1,
                'employeeTypeCodeSnapshot' => $type,
                'employeeTypeAuthorityVersion' => $typeVersion,
            ];
        }
        return $result;
    }

    private function decodeStoredCraftsmen(array $line, string $lineKey): array
    {
        $stored = (string)($line['craftsmen_json'] ?? '');
        $decoded = $stored === '' ? [] : json_decode($stored, true);
        if (!is_array($decoded) || array_keys($decoded) !== ($decoded ? range(0, count($decoded) - 1) : [])) {
            throw $this->incompleteLineSettings($lineKey, 'stored_craftsmen_invalid');
        }
        return $decoded;
    }

    private function decodeStoredSalespeople(array $line, string $lineKey): array
    {
        $stored = (string)($line['salespeople_json'] ?? '');
        $decoded = $stored === '' ? [] : json_decode($stored, true);
        if (!is_array($decoded) || array_keys($decoded) !== ($decoded ? range(0, count($decoded) - 1) : [])) {
            throw $this->incompleteLineSettings($lineKey, 'stored_salespeople_invalid');
        }
        return $decoded;
    }

    /** @return array<int,array{staffId:int,laborWeight:int,isPointCustomer:bool}> */
    private function craftsmanSelectionsFromSnapshot(array $craftsmen, string $lineKey): array
    {
        $selections = [];
        $seen = [];
        $needsLegacyEqualWeights = false;
        foreach ($craftsmen as $craftsman) {
            if (!is_array($craftsman)) {
                throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_not_object');
            }
            $staffId = $craftsman['staffId'] ?? $craftsman['id'] ?? null;
            if (is_string($staffId)) {
                $raw = trim($staffId);
                $staffId = preg_match('/^[1-9][0-9]*$/', $raw) === 1 ? (int)$raw : 0;
            } elseif (!is_int($staffId)) {
                $staffId = 0;
            }
            if ($staffId <= 0 || isset($seen[$staffId])) {
                throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_id_invalid');
            }
            $seen[$staffId] = true;
            $hasWeight = array_key_exists('laborWeight', $craftsman);
            $weight = $hasWeight ? (int)$craftsman['laborWeight'] : 0;
            if ($hasWeight && ($weight <= 0 || $weight > 100)) {
                throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_weight_invalid');
            }
            $needsLegacyEqualWeights = $needsLegacyEqualWeights || !$hasWeight;
            $selections[] = [
                'staffId' => $staffId,
                'laborWeight' => $weight,
                'isPointCustomer' => !empty($craftsman['isPointCustomer']),
            ];
        }
        if ($needsLegacyEqualWeights && $selections) {
            if (count(array_filter($selections, static function (array $selection): bool {
                return $selection['laborWeight'] > 0;
            })) > 0) {
                throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_weight_partial');
            }
            $base = intdiv(100, count($selections));
            $remaining = 100 - ($base * count($selections));
            foreach ($selections as &$selection) {
                $selection['laborWeight'] = $base + ($remaining > 0 ? 1 : 0);
                $remaining = max(0, $remaining - 1);
            }
            unset($selection);
        }
        if ($selections && array_sum(array_column($selections, 'laborWeight')) !== 100) {
            throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_weight_sum_invalid');
        }
        return $selections;
    }

    /** @return int[] */
    private function craftsmanIdsFromSnapshot(array $craftsmen, string $lineKey): array
    {
        return array_map(static function (array $selection): int {
            return (int)$selection['staffId'];
        }, $this->craftsmanSelectionsFromSnapshot($craftsmen, $lineKey));
    }

    private function incompleteLineSettings(
        string $lineKey,
        string $reason
    ): CashierV3CommandException {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '购物车服务设置不完整，请删除该项目后重新选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['line_id' => $lineKey, 'reason' => $reason]
        );
    }

    private function incompleteDraft(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '收银购物车草稿不完整，请刷新工作台后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private function incompleteDraftLine(
        string $lineKey,
        string $reason
    ): CashierV3CommandException {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '购物车项目资料不完整，请删除该项目后重新选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['line_id' => $lineKey, 'reason' => $reason]
        );
    }

    /** @return array<int,array> */
    private function lineRows(string $workspaceId, bool $lock): array
    {
        $query = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->order('sort_no asc,id asc');
        if ($lock) {
            CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceLinesLocked');
            $query->lock(true);
        }
        $rows = $query->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private function isCustomCardSaleRow(array $row): bool
    {
        $snapshot = json_decode((string)($row['authority_snapshot_json'] ?? ''), true);
        return is_array($snapshot)
            && (string)($snapshot['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
    }

    private function isCardOperationUpgradeSaleRow(array $row): bool
    {
        if ((string)($row['line_role'] ?? '') !== self::ROLE_SALE) {
            return false;
        }
        $snapshot = json_decode((string)($row['authority_snapshot_json'] ?? ''), true);
        return is_array($snapshot)
            && is_array($snapshot['cardOperationUpgrade'] ?? null);
    }

    private function assertNoPendingCardOperationUpgradeLine(array $rows): void
    {
        foreach ($rows as $row) {
            if (is_array($row) && $this->isCardOperationUpgradeSaleRow($row)) {
                throw $this->cardOperationUpgradeCartLocked();
            }
        }
    }

    private function cardOperationUpgradeCartLocked(): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '当前升级补价已锁定，请完成结账或删除该项目后重新操作。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => 'card_operation_upgrade_workspace_line_locked']
        );
    }

    private function isCustomCardSaleLine(array $line): bool
    {
        return (string)($line['authority_snapshot']['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
    }

    private function normalizePersistedSaleLine(array $line, string $workspaceId, int $memberId): array
    {
        $lineKey = trim((string)($line['line_key'] ?? ''));
        $productId = (int)($line['catalog_product_id'] ?? 0);
        $skuId = (int)($line['catalog_sku_id'] ?? 0);
        $productType = (int)($line['catalog_product_type'] ?? -1);
        $projectId = (int)($line['project_id'] ?? 0);
        $quantity = (int)($line['quantity'] ?? 0);
        $sourceVersion = (int)($line['source_version'] ?? 0);
        $detailVersion = (int)($line['detail_version'] ?? 0);
        $unitPriceCents = $line['unit_price_cents'] ?? null;
        $originalUnitPriceCents = $line['original_unit_price_cents'] ?? null;
        $fingerprint = trim((string)($line['authority_fingerprint'] ?? ''));
        $authoritySnapshot = is_array($line['authority_snapshot'] ?? null)
            ? $line['authority_snapshot']
            : null;
        $displaySnapshot = is_array($line['display_snapshot'] ?? null)
            ? $line['display_snapshot']
            : null;
        $isCustomCard = is_array($authoritySnapshot)
            && (string)($authoritySnapshot['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
        $isServiceProject = $productType === 6 && !$isCustomCard;
        if (preg_match('/^sale:[a-f0-9]{48}$/D', $lineKey) !== 1
            || $productId <= 0 || $skuId <= 0
            || !in_array($productType, [0, 4, 5, 6], true)
            || ($isServiceProject ? $projectId !== $productId : $projectId !== 0)
            || $quantity !== 1 || $sourceVersion <= 0 || $detailVersion <= 0
            || !is_int($unitPriceCents) || $unitPriceCents < 0
            || !is_int($originalUnitPriceCents) || (!$isCustomCard && $originalUnitPriceCents < $unitPriceCents)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || $authoritySnapshot === null || $displaySnapshot === null) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '商品购物车资料不完整，请重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_sale_persisted_line_invalid']
            );
        }
        return [
            'workspace_id' => $workspaceId,
            'line_key' => $lineKey,
            'line_role' => self::ROLE_SALE,
            'member_id' => $memberId,
            'holder_id' => 0,
            'source_detail_id' => 0,
            'project_id' => $projectId,
            'catalog_product_id' => $productId,
            'catalog_sku_id' => $skuId,
            'catalog_product_type' => $productType,
            'quantity' => 1,
            'source_version' => $sourceVersion,
            'detail_version' => $detailVersion,
            'unit_price_cents' => $unitPriceCents,
            'original_unit_price_cents' => $originalUnitPriceCents,
            'configured_cost_cents' => (int)($line['configured_cost_cents'] ?? 0),
            'debt_amount_cents' => 0,
            'price_change_reason' => '',
            'price_changed_by' => 0,
            'price_changed_by_name_snapshot' => '',
            'price_changed_at' => 0,
            'authority_fingerprint' => $fingerprint,
            'authority_snapshot_json' => $this->encodeJson($authoritySnapshot),
            'service_object' => (string)($line['service_object'] ?? ''),
            'craftsmen_json' => $this->encodeJson([]),
            'salespeople_json' => $this->encodeJson([]),
            'is_experience' => 0,
            'display_snapshot_json' => $this->encodeJson($displaySnapshot),
        ];
    }

    /**
     * Restore only the trusted, persisted sale-line representation kept by a
     * new-contract hang order.  It intentionally does not accept the public
     * display line: authority_snapshot_json is required and revalidated by
     * toPublicDraft() before this transaction can commit.
     */
    private function normalizeRestoredSaleLine(
        array $line,
        string $workspaceId,
        int $memberId,
        int $sortNo
    ): array {
        $lineKey = trim((string)($line['line_key'] ?? ''));
        $productId = (int)($line['catalog_product_id'] ?? 0);
        $skuId = (int)($line['catalog_sku_id'] ?? 0);
        $productType = (int)($line['catalog_product_type'] ?? -1);
        $quantity = (int)($line['quantity'] ?? 0);
        $sourceVersion = (int)($line['source_version'] ?? 0);
        $detailVersion = (int)($line['detail_version'] ?? 0);
        $unitPriceCents = $line['unit_price_cents'] ?? null;
        $originalUnitPriceCents = $line['original_unit_price_cents'] ?? null;
        $fingerprint = trim((string)($line['authority_fingerprint'] ?? ''));
        $authorityJson = (string)($line['authority_snapshot_json'] ?? '');
        $displayJson = (string)($line['display_snapshot_json'] ?? '');
        $craftsmenJson = (string)($line['craftsmen_json'] ?? '');
        $salespeopleJson = (string)($line['salespeople_json'] ?? '');
        if (preg_match('/^sale:[a-f0-9]{48}$/D', $lineKey) !== 1
            || (string)($line['line_role'] ?? '') !== self::ROLE_SALE
            || (int)($line['member_id'] ?? -1) !== $memberId
            || $productId <= 0 || $skuId <= 0 || $productType !== 0 || $quantity <= 0
            || $quantity > 1000000 || $sourceVersion <= 0 || $detailVersion <= 0
            || !is_numeric($unitPriceCents) || (int)$unitPriceCents < 0
            || !is_numeric($originalUnitPriceCents)
            || (int)$originalUnitPriceCents < (int)$unitPriceCents
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || $authorityJson === '' || $displayJson === '' || $craftsmenJson === ''
            || $salespeopleJson === '' || $sortNo <= 0) {
            throw $this->incompleteDraftLine($lineKey, 'cashier_hang_restore_sale_snapshot_invalid');
        }
        $probe = [
            'authority_snapshot_json' => $authorityJson,
            'display_snapshot_json' => $displayJson,
            'craftsmen_json' => $craftsmenJson,
            'salespeople_json' => $salespeopleJson,
        ];
        $authority = $this->decodeStoredAuthoritySnapshot($probe, $lineKey);
        $display = $this->decodeStoredDisplaySnapshot($probe, $lineKey);
        $craftsmen = $this->decodeStoredCraftsmen($probe, $lineKey);
        $salespeople = $this->decodeStoredSalespeople($probe, $lineKey);
        if ($craftsmen !== [] || $salespeople !== []
            || !hash_equals($fingerprint, hash('sha256', $this->canonicalJson($authority)))
            || (int)($authority['product']['id'] ?? 0) !== $productId
            || (int)($authority['sku']['id'] ?? 0) !== $skuId
            || (int)($authority['product']['productType'] ?? -1) !== $productType
            || (int)($authority['productVersion'] ?? 0) !== $sourceVersion
            || (int)($authority['skuVersion'] ?? 0) !== $detailVersion
            || (int)($authority['sku']['priceCents'] ?? -1) !== (int)$unitPriceCents
            || (int)($authority['sku']['originalPriceCents'] ?? -1) !== (int)$originalUnitPriceCents
            || (int)($display['productId'] ?? 0) !== $productId
            || (int)($display['skuId'] ?? 0) !== $skuId
            || trim((string)($display['name'] ?? '')) === ''
            || trim((string)($display['kind'] ?? '')) === '') {
            throw $this->incompleteDraftLine($lineKey, 'cashier_hang_restore_authority_mismatch');
        }
        return [
            'workspace_id' => $workspaceId,
            'line_key' => $lineKey,
            'line_role' => self::ROLE_SALE,
            'member_id' => $memberId,
            'holder_id' => 0,
            'source_detail_id' => 0,
            'project_id' => 0,
            'catalog_product_id' => $productId,
            'catalog_sku_id' => $skuId,
            'catalog_product_type' => $productType,
            'quantity' => $quantity,
            'source_version' => $sourceVersion,
            'detail_version' => $detailVersion,
            'unit_price_cents' => (int)$unitPriceCents,
            'original_unit_price_cents' => (int)$originalUnitPriceCents,
            'configured_cost_cents' => 0,
            'debt_amount_cents' => 0,
            'price_change_reason' => '',
            'price_changed_by' => 0,
            'price_changed_by_name_snapshot' => '',
            'price_changed_at' => 0,
            'authority_fingerprint' => $fingerprint,
            'authority_snapshot_json' => $authorityJson,
            'service_object' => '',
            'craftsmen_json' => $craftsmenJson,
            'salespeople_json' => $salespeopleJson,
            'is_experience' => 0,
            'display_snapshot_json' => $displayJson,
            'sort_no' => $sortNo,
        ];
    }

    private function normalizePersistedEntitlementLine(array $line, string $workspaceId, int $memberId): array
    {
        $holderId = (int)($line['holder_id'] ?? $line['entitlementInstanceId'] ?? 0);
        $detailId = (int)($line['source_detail_id'] ?? $line['entitlementSourceDetailId'] ?? 0);
        $projectId = (int)($line['project_id'] ?? $line['projectId'] ?? 0);
        $quantity = (int)($line['quantity'] ?? 0);
        $sourceVersion = (int)($line['source_version'] ?? $line['entitlementSourceVersion'] ?? 0);
        $detailVersion = (int)($line['detail_version'] ?? $line['projectVersion'] ?? 0);
        $addIntentId = trim((string)($line['add_intent_id'] ?? $line['addIntentId'] ?? ''));
        if ($holderId <= 0 || $detailId <= 0 || $projectId <= 0 || $quantity <= 0
            || $sourceVersion <= 0 || $detailVersion <= 0
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $addIntentId) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '卡内项目明细无效，请重新打开后选择。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $lineKey = 'entitlement:' . substr(hash('sha256', $addIntentId), 0, 48);
        return [
            'workspace_id' => $workspaceId,
            'line_key' => $lineKey,
            'line_role' => self::ROLE_ENTITLEMENT,
            'member_id' => $memberId,
            'holder_id' => $holderId,
            'source_detail_id' => $detailId,
            'project_id' => $projectId,
            'quantity' => $quantity,
            'source_version' => $sourceVersion,
            'detail_version' => $detailVersion,
            'service_object' => 'self',
            'craftsmen_json' => $this->encodeJson([]),
            'salespeople_json' => $this->encodeJson([]),
            'is_experience' => 0,
            'display_snapshot_json' => $this->encodeJson((array)($line['display_snapshot'] ?? [])),
        ];
    }

    private function toPublicDraft(array $draft, array $rows): array
    {
        $customerMode = (string)($draft['customer_mode'] ?? '');
        $draftMemberId = (int)($draft['member_id'] ?? 0);
        $draftStatus = (string)($draft['draft_status'] ?? '');
        $storedFingerprint = trim((string)($draft['line_fingerprint'] ?? ''));
        if (!in_array($customerMode, [self::MODE_MEMBER, self::MODE_GUEST], true)
            || ($customerMode === self::MODE_MEMBER && $draftMemberId <= 0)
            || ($customerMode === self::MODE_GUEST && $draftMemberId !== 0)
            || $draftStatus !== self::STATUS_EDITING
            || preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) !== 1
            || (!hash_equals($storedFingerprint, $this->lineFingerprint($rows))
                && !hash_equals($storedFingerprint, $this->lineFingerprint($rows, false)))) {
            throw $this->incompleteDraft('cashier_workspace_draft_contract_invalid');
        }

        $lines = [];
        $selectedQuantityBySource = [];
        $allocationBasisBySource = [];
        $projectBySource = [];
        foreach ($rows as $row) {
            $lineKey = trim((string)($row['line_key'] ?? ''));
            $lineRole = (string)($row['line_role'] ?? '');
            $quantity = (int)($row['quantity'] ?? 0);
            $sourceVersion = (int)($row['source_version'] ?? 0);
            $detailVersion = (int)($row['detail_version'] ?? 0);
            $experienceFlag = (int)($row['is_experience'] ?? -1);
            if ($lineKey === ''
                || !in_array($lineRole, [self::ROLE_SALE, self::ROLE_ENTITLEMENT], true)
                || (int)($row['member_id'] ?? -1) !== $draftMemberId
                || $quantity <= 0
                || $sourceVersion <= 0
                || $detailVersion <= 0
                || !in_array($experienceFlag, [0, 1], true)) {
                throw $this->incompleteDraftLine($lineKey, 'cashier_workspace_line_contract_invalid');
            }

            $snapshot = $this->decodeStoredDisplaySnapshot($row, $lineKey);
            $craftsmen = $this->decodeStoredCraftsmen($row, $lineKey);
            $salespeople = $lineRole === self::ROLE_SALE
                ? $this->decodeStoredSalespeople($row, $lineKey)
                : [];
            // 损坏的手艺人快照不能被静默显示为“待分配”。
            $this->craftsmanIdsFromSnapshot($craftsmen, $lineKey);
            $storedServiceObject = (string)($row['service_object'] ?? '');
            if ($lineRole === self::ROLE_SALE
                && !in_array($storedServiceObject, ['', 'self', 'friend'], true)) {
                throw $this->incompleteDraftLine($lineKey, 'sale_line_service_object_invalid');
            }
            $serviceObject = $storedServiceObject === ''
                ? ''
                : ($storedServiceObject === 'friend' ? '朋友' : '本人');
            $line = array_merge($snapshot, [
                'id' => $lineKey,
                'lineRole' => $lineRole,
                'memberId' => (int)$row['member_id'],
                'entitlementInstanceId' => (int)$row['holder_id'],
                'entitlementSourceDetailId' => (int)$row['source_detail_id'],
                'projectId' => (int)$row['project_id'],
                'quantity' => $quantity,
                'entitlementSourceVersion' => $sourceVersion,
                'projectVersion' => $detailVersion,
                'serviceObject' => $serviceObject,
                'craftsmen' => $craftsmen,
                'craftsmenSummary' => $this->craftsmenSummary($craftsmen),
                'isExperience' => $experienceFlag === 1,
            ]);
            if ($lineRole === self::ROLE_SALE) {
                $line['salespeople'] = $salespeople;
                $line['salespersonSummary'] = $this->salespeopleSummary($salespeople);
                $productId = (int)($row['catalog_product_id'] ?? 0);
                $skuId = (int)($row['catalog_sku_id'] ?? 0);
                $productType = (int)($row['catalog_product_type'] ?? -1);
                $unitPriceCents = $this->storedNonnegativeInteger(
                    $row['unit_price_cents'] ?? null,
                    $lineKey,
                    'unit_price_cents'
                );
                $originalUnitPriceCents = $this->storedNonnegativeInteger(
                    $row['original_unit_price_cents'] ?? null,
                    $lineKey,
                    'original_unit_price_cents'
                );
                $authorityFingerprint = trim((string)($row['authority_fingerprint'] ?? ''));
                $authoritySnapshot = $this->decodeStoredAuthoritySnapshot($row, $lineKey);
                $authorityHash = hash('sha256', $this->canonicalJson($authoritySnapshot));
                $isCustomCard = (string)($authoritySnapshot['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
                $isProject = $productType === 6 && !$isCustomCard;
                $cardOperationUpgrade = is_array($authoritySnapshot['cardOperationUpgrade'] ?? null)
                    ? $authoritySnapshot['cardOperationUpgrade']
                    : null;
                $configuredCostCents = $this->storedNonnegativeInteger(
                    $row['configured_cost_cents'] ?? 0,
                    $lineKey,
                    'configured_cost_cents'
                );
                $priceChangeReason = trim((string)($row['price_change_reason'] ?? ''));
                $priceChangedBy = (int)($row['price_changed_by'] ?? 0);
                $priceChangedByName = trim((string)($row['price_changed_by_name_snapshot'] ?? ''));
                $priceChangedAt = (int)($row['price_changed_at'] ?? 0);
                $configuredPriceCents = (int)($authoritySnapshot['sku']['priceCents'] ?? -1);
                $authorityCostCents = (int)($authoritySnapshot['sku']['costCents'] ?? -1);
                $priceAuditMatches = $priceChangedAt === 0
                    ? ($unitPriceCents === $configuredPriceCents
                        && in_array($configuredCostCents, [0, $authorityCostCents], true)
                        && $priceChangeReason === '' && $priceChangedBy === 0 && $priceChangedByName === '')
                    : ($authorityCostCents >= 0
                        && $configuredCostCents === $authorityCostCents
                        && $unitPriceCents >= $configuredCostCents
                        && ($isCustomCard || $unitPriceCents <= $configuredPriceCents)
                        && $priceChangeReason !== '' && $priceChangedBy > 0 && $priceChangedByName !== '');
                $priceSnapshotMatches = $cardOperationUpgrade === null
                    ? ($priceAuditMatches
                        && (int)($authoritySnapshot['sku']['originalPriceCents'] ?? -1) === $originalUnitPriceCents)
                    : (
                        (int)($cardOperationUpgrade['targetProductId'] ?? 0) === $productId
                        && (int)($cardOperationUpgrade['targetSkuId'] ?? 0) === $skuId
                        && (int)($cardOperationUpgrade['targetPriceCents'] ?? -1) === $originalUnitPriceCents
                        && (int)($cardOperationUpgrade['settlementDeltaCents'] ?? -1) === $unitPriceCents
                    );
                if (preg_match('/^sale:[a-f0-9]{48}$/D', $lineKey) !== 1
                    || $productId <= 0 || $skuId <= 0
                    || !in_array($productType, [0, 4, 5, 6], true)
                    || (!$isCustomCard && $originalUnitPriceCents < $unitPriceCents)
                    || preg_match('/^[a-f0-9]{64}$/D', $authorityFingerprint) !== 1
                    || !hash_equals($authorityFingerprint, $authorityHash)
                    || (int)($authoritySnapshot['product']['id'] ?? 0) !== $productId
                    || (int)($authoritySnapshot['sku']['id'] ?? 0) !== $skuId
                    || (int)($authoritySnapshot['product']['productType'] ?? -1) !== $productType
                    || (int)($authoritySnapshot['productVersion'] ?? 0) !== $sourceVersion
                    || (int)($authoritySnapshot['skuVersion'] ?? 0) !== $detailVersion
                    || !$priceSnapshotMatches
                    || (int)($row['holder_id'] ?? 0) !== 0
                    || (int)($row['source_detail_id'] ?? 0) !== 0
                    || ($isProject ? (int)($row['project_id'] ?? 0) !== $productId : (int)($row['project_id'] ?? 0) !== 0)
                    || ($isProject ? !in_array($storedServiceObject, ['self', 'friend'], true) : $storedServiceObject !== '')
                    || (!$isProject && ($craftsmen !== [] || $experienceFlag !== 0))
                    || (int)($snapshot['productId'] ?? 0) !== $productId
                    || (int)($snapshot['skuId'] ?? 0) !== $skuId
                    || trim((string)($snapshot['name'] ?? '')) === ''
                    || trim((string)($snapshot['kind'] ?? '')) === '') {
                    throw $this->incompleteDraftLine($lineKey, 'cashier_sale_line_identity_invalid');
                }
                $lineAmountCents = $this->multiplyCents($unitPriceCents, $quantity, $lineKey);
                $originalAmountCents = $this->multiplyCents($originalUnitPriceCents, $quantity, $lineKey);
                $debtAmountCents = $this->storedNonnegativeInteger(
                    $row['debt_amount_cents'] ?? 0,
                    $lineKey,
                    'debt_amount_cents'
                );
                if ($debtAmountCents > $lineAmountCents) {
                    throw $this->incompleteDraftLine($lineKey, 'sale_line_debt_amount_invalid');
                }
                $line = array_merge($line, [
                    'catalogItemId' => $skuId,
                    'productId' => $productId,
                    'skuId' => $skuId,
                    'productType' => $productType,
                    'productVersion' => $sourceVersion,
                    'skuVersion' => $detailVersion,
                    'unitPriceCents' => $unitPriceCents,
                    'originalUnitPriceCents' => $originalUnitPriceCents,
                    'configuredPriceCents' => $configuredPriceCents,
                    'configuredCostCents' => $configuredCostCents,
                    'priceChangeReason' => $priceChangeReason,
                    'priceChangedBy' => $priceChangedBy,
                    'priceChangedByNameSnapshot' => $priceChangedByName,
                    'priceChangedAt' => $priceChangedAt,
                    'lineAmountCents' => $lineAmountCents,
                    'debtAmountCents' => $debtAmountCents,
                    'originalLineAmountCents' => $originalAmountCents,
                    'unitPrice' => $this->centsToMoney($unitPriceCents),
                    'originalUnitPrice' => $this->centsToMoney($originalUnitPriceCents),
                    'finalAmount' => $this->centsToMoney($lineAmountCents),
                    'amount' => $this->centsToMoney($lineAmountCents),
                    'originalAmount' => $this->centsToMoney($originalAmountCents),
                    'amountRole' => 'sale_receivable',
                    'definitionFingerprint' => $authorityFingerprint,
                    'serviceObject' => $serviceObject,
                ]);
            } elseif ($lineRole === self::ROLE_ENTITLEMENT) {
                if ((int)($row['holder_id'] ?? 0) <= 0
                    || (int)($row['source_detail_id'] ?? 0) <= 0
                    || (int)($row['project_id'] ?? 0) <= 0
                    || !in_array($storedServiceObject, ['self', 'friend'], true)) {
                    throw $this->incompleteDraftLine($lineKey, 'entitlement_line_identity_invalid');
                }
                $purchaseAmount = trim((string)($snapshot['purchaseAmount'] ?? ''));
                $totalPurchaseTimes = (int)($snapshot['totalPurchaseTimes'] ?? 0);
                $consumedTimes = (int)($snapshot['consumedTimesAtSelection'] ?? -1);
                $amountSourceVersion = (int)($snapshot['amountSourceVersion'] ?? 0);
                $calculationVersion = trim((string)($snapshot['amountCalculationVersion'] ?? ''));
                if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $purchaseAmount) !== 1
                    || $totalPurchaseTimes <= 0
                    || $consumedTimes < 0
                    || $consumedTimes > $totalPurchaseTimes
                    || $amountSourceVersion !== (int)$row['detail_version']
                    || substr($calculationVersion, -strlen(CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION))
                        !== CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION) {
                    throw $this->incompleteLineSettings((string)$row['line_key'], 'actual_amount_allocation_invalid');
                }
                $sourceKey = (int)$row['holder_id'] . ':' . (int)$row['source_detail_id'];
                $projectId = (int)$row['project_id'];
                if (isset($projectBySource[$sourceKey]) && $projectBySource[$sourceKey] !== $projectId) {
                    throw $this->incompleteLineSettings(
                        (string)$row['line_key'],
                        'entitlement_source_project_mismatch'
                    );
                }
                $projectBySource[$sourceKey] = $projectId;
                $allocationBasis = [
                    'purchaseAmount' => $purchaseAmount,
                    'totalPurchaseTimes' => $totalPurchaseTimes,
                    'consumedTimesAtSelection' => $consumedTimes,
                    'amountSourceVersion' => $amountSourceVersion,
                    'amountCalculationVersion' => $calculationVersion,
                ];
                if (isset($allocationBasisBySource[$sourceKey])
                    && $allocationBasisBySource[$sourceKey] !== $allocationBasis) {
                    throw $this->incompleteLineSettings(
                        (string)$row['line_key'],
                        'actual_amount_allocation_basis_mismatch'
                    );
                }
                $allocationBasisBySource[$sourceKey] = $allocationBasis;
                $selectedBefore = (int)($selectedQuantityBySource[$sourceKey] ?? 0);
                $allocationStart = $consumedTimes + $selectedBefore;
                if ($allocationStart < $consumedTimes
                    || $allocationStart + $quantity > $totalPurchaseTimes) {
                    throw $this->incompleteLineSettings(
                        (string)$row['line_key'],
                        'actual_amount_aggregate_quantity_invalid'
                    );
                }
                try {
                    $line['actualAmount'] = CashierV3EntitlementActualAmountAllocator::allocate(
                        $purchaseAmount,
                        $totalPurchaseTimes,
                        $allocationStart,
                        $quantity
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw $this->incompleteLineSettings((string)$row['line_key'], 'actual_amount_allocation_failed');
                }
                $line['purchaseAmount'] = bcadd($purchaseAmount, '0', 2);
                $line['totalPurchaseTimes'] = $totalPurchaseTimes;
                $line['consumedTimesAtSelection'] = $consumedTimes;
                $line['allocationStartConsumedTimes'] = $allocationStart;
                $line['amountSourceVersion'] = $amountSourceVersion;
                $line['amountCalculationVersion'] = $calculationVersion;
                $line['amountRole'] = 'entitlement_actual';
                $selectedQuantityBySource[$sourceKey] = $selectedBefore + $quantity;
            }
            $lines[] = $line;
        }
        $composition = self::checkoutComposition($lines);
        return [
            'workspaceId' => (string)$draft['workspace_id'],
            'stateContextId' => (string)$draft['state_context_id'],
            'customerMode' => (string)$draft['customer_mode'],
            'memberId' => (int)$draft['member_id'],
            'status' => (string)$draft['draft_status'],
            'orderNote' => (string)($draft['order_note'] ?? ''),
            'supplement' => [
                'enabled' => (int)($draft['supplement_enabled'] ?? 0) === 1,
                'businessDate' => (string)($draft['supplement_business_date'] ?? ''),
                'reason' => (string)($draft['supplement_reason'] ?? ''),
                'operatorId' => (int)($draft['supplement_operator_id'] ?? 0),
                'operatorNameSnapshot' => (string)($draft['supplement_operator_name_snapshot'] ?? ''),
                'operatedAt' => (int)($draft['supplement_operated_at'] ?? 0),
            ],
            'lines' => $lines,
            'summary' => self::cartSummary($lines),
            'checkoutComposition' => $composition,
            'primaryAction' => $composition['primaryAction'],
            'primaryActionLabel' => $composition['primaryActionLabel'],
            'lineFingerprint' => (string)$draft['line_fingerprint'],
            'complete' => true,
            'managedLineRoles' => [self::ROLE_SALE, self::ROLE_ENTITLEMENT],
        ];
    }

    private static function checkoutComposition(array $lines): array
    {
        $roles = [];
        foreach ($lines as $line) {
            $role = (string)($line['lineRole'] ?? '');
            if (in_array($role, [self::ROLE_SALE, self::ROLE_ENTITLEMENT], true) && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        $hasSale = in_array(self::ROLE_SALE, $roles, true);
        $hasEntitlement = in_array(self::ROLE_ENTITLEMENT, $roles, true);
        $primaryAction = $hasSale && $hasEntitlement
            ? 'collect_and_complete'
            : ($hasEntitlement ? 'complete_service' : ($hasSale ? 'collect_payment' : ''));
        $labels = [
            'collect_payment' => '确认收款',
            'complete_service' => '确认完成服务',
            'collect_and_complete' => '收款并完成服务',
        ];
        $primaryLabel = $labels[$primaryAction] ?? '';
        $steps = [];
        if ($primaryAction !== '') {
            $steps[] = ['key' => 'order', 'number' => 1, 'label' => $hasEntitlement ? '确认本次内容' : '确认订单'];
            if ($hasSale) {
                $steps[] = ['key' => 'payment', 'number' => 2, 'label' => '收款信息'];
            }
            $steps[] = ['key' => 'final', 'number' => 3, 'label' => $primaryLabel];
            $steps[] = ['key' => 'result', 'number' => 4, 'label' => '处理结果'];
        }
        return [
            'lineRoles' => $roles,
            'hasSale' => $hasSale,
            'hasEntitlement' => $hasEntitlement,
            'primaryAction' => $primaryAction,
            'primaryActionLabel' => $primaryLabel,
            'steps' => $steps,
        ];
    }

    private static function cartSummary(array $lines): array
    {
        $original = '0.00';
        $receivable = '0.00';
        foreach ($lines as $line) {
            if ((string)($line['lineRole'] ?? '') !== self::ROLE_SALE) {
                continue;
            }
            $original = bcadd($original, (string)($line['originalAmount'] ?? $line['finalAmount'] ?? '0'), 2);
            $receivable = bcadd($receivable, (string)($line['finalAmount'] ?? $line['amount'] ?? '0'), 2);
        }
        return [
            'selectedCount' => count($lines),
            'originalAmount' => $original,
            'discountAmount' => bccomp($original, $receivable, 2) > 0 ? bcsub($original, $receivable, 2) : '0.00',
            'receivableAmount' => $receivable,
        ];
    }

    private function craftsmenSummary(array $craftsmen): string
    {
        if (!$craftsmen) {
            return '待分配';
        }
        $labels = [];
        foreach ($craftsmen as $index => $staff) {
            if (!is_array($staff)) {
                continue;
            }
            $name = trim((string)($staff['name'] ?? $staff['staffName'] ?? ''));
            if ($name !== '') {
                $labels[] = $name . ($index === 0 ? '(主)' : '');
            }
        }
        return $labels ? implode('、', $labels) : '待分配';
    }

    private function salespeopleSummary(array $salespeople): string
    {
        if (!$salespeople) {
            return '待分配';
        }
        $labels = [];
        foreach ($salespeople as $staff) {
            if (!is_array($staff)) {
                continue;
            }
            $name = trim((string)($staff['name'] ?? $staff['staffName'] ?? ''));
            $weight = (int)($staff['allocationWeight'] ?? 0);
            if ($name !== '' && $weight > 0) {
                $labels[] = $name . ' ' . $weight . '%';
            }
        }
        return $labels ? implode('、', $labels) : '待分配';
    }

    private function lineFingerprint(array $rows, bool $includeLineFinancials = true): string
    {
        $canonical = [];
        foreach ($rows as $row) {
            $item = [
                'line_key' => (string)($row['line_key'] ?? ''),
                'line_role' => (string)($row['line_role'] ?? ''),
                'member_id' => (int)($row['member_id'] ?? 0),
                'holder_id' => (int)($row['holder_id'] ?? 0),
                'source_detail_id' => (int)($row['source_detail_id'] ?? 0),
                'project_id' => (int)($row['project_id'] ?? 0),
                'quantity' => (int)($row['quantity'] ?? 0),
                'source_version' => (int)($row['source_version'] ?? 0),
                'detail_version' => (int)($row['detail_version'] ?? 0),
                'service_object' => (string)($row['service_object'] ?? ''),
                'craftsmen_json' => (string)($row['craftsmen_json'] ?? ''),
                'salespeople_json' => (string)($row['salespeople_json'] ?? ''),
                'is_experience' => (int)($row['is_experience'] ?? 0),
                'display_snapshot_json' => (string)($row['display_snapshot_json'] ?? ''),
                'sort_no' => (int)($row['sort_no'] ?? 0),
            ];
            if ((string)($row['line_role'] ?? '') === self::ROLE_SALE) {
                $item['catalog_product_id'] = (int)($row['catalog_product_id'] ?? 0);
                $item['catalog_sku_id'] = (int)($row['catalog_sku_id'] ?? 0);
                $item['catalog_product_type'] = (int)($row['catalog_product_type'] ?? 0);
                $item['unit_price_cents'] = (int)($row['unit_price_cents'] ?? 0);
                $item['original_unit_price_cents'] = (int)($row['original_unit_price_cents'] ?? 0);
                if ($includeLineFinancials) {
                    $item['configured_cost_cents'] = (int)($row['configured_cost_cents'] ?? 0);
                    $item['debt_amount_cents'] = (int)($row['debt_amount_cents'] ?? 0);
                    $item['price_change_reason'] = (string)($row['price_change_reason'] ?? '');
                    $item['price_changed_by'] = (int)($row['price_changed_by'] ?? 0);
                    $item['price_changed_by_name_snapshot'] = (string)($row['price_changed_by_name_snapshot'] ?? '');
                    $item['price_changed_at'] = (int)($row['price_changed_at'] ?? 0);
                }
                $item['authority_fingerprint'] = (string)($row['authority_fingerprint'] ?? '');
                $item['authority_snapshot_json'] = (string)($row['authority_snapshot_json'] ?? '');
            }
            $canonical[] = $item;
        }
        return hash('sha256', $this->encodeJson($canonical));
    }

    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '收银购物车草稿无法保存，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_workspace_json_encode_failed']
            );
        }
        return $json;
    }

    private function decodeStoredDisplaySnapshot(array $line, string $lineKey): array
    {
        $json = (string)($line['display_snapshot_json'] ?? '');
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw $this->incompleteDraftLine($lineKey, 'stored_display_snapshot_invalid');
        }
        return $decoded;
    }

    private function decodeStoredAuthoritySnapshot(array $line, string $lineKey): array
    {
        $json = (string)($line['authority_snapshot_json'] ?? '');
        $decoded = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)
            || ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1))) {
            throw $this->incompleteDraftLine($lineKey, 'stored_authority_snapshot_invalid');
        }
        return $decoded;
    }

    private function canonicalJson(array $value): string
    {
        return $this->encodeJson($this->canonicalize($value));
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function multiplyCents(int $unitPriceCents, int $quantity, string $lineKey): int
    {
        if ($unitPriceCents < 0 || $quantity <= 0
            || ($unitPriceCents > 0 && $quantity > intdiv(PHP_INT_MAX, $unitPriceCents))) {
            throw $this->incompleteDraftLine($lineKey, 'cashier_sale_amount_overflow');
        }
        return $unitPriceCents * $quantity;
    }

    private function centsToMoney(int $cents): string
    {
        return bcdiv((string)$cents, '100', 2);
    }

    private function storedNonnegativeInteger($value, string $lineKey, string $field): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
        } elseif (is_string($value)) {
            $raw = trim($value);
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) === 1
                && strlen($raw) <= strlen((string)PHP_INT_MAX)
                && (strlen($raw) < strlen((string)PHP_INT_MAX)
                    || strcmp($raw, (string)PHP_INT_MAX) <= 0)) {
                return (int)$raw;
            }
        }
        throw $this->incompleteDraftLine($lineKey, 'cashier_sale_' . $field . '_invalid');
    }
}
