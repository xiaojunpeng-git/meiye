<?php

namespace app\services\cashier\v3\cashier;

use app\services\employee\EmployeeCraftsmanPerformanceTypeServices;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
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
        // Selecting a member always starts a fresh cart by product rule.
        $existingRows = $this->lineRows($workspaceId, true);
        if ($existingRows !== []) {
            $deleted = (int)Db::name(self::LINE_TABLE)
                ->where('workspace_id', $workspaceId)
                ->delete();
            if ($deleted !== count($existingRows)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '清空购物车失败，请重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'cashier_member_change_clear_cart_incomplete']
                );
            }
        }
        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'customer_mode' => self::MODE_MEMBER,
            'draft_status' => self::STATUS_EDITING,
            'resumed_hang_order_id' => '',
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
        // 目录加入阶段不判断会员、卡项或可售状态；结账事务统一校验。
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
        array $line,
        ?array $lockedDraft = null
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceAppendSale');
        $draft = $lockedDraft ?? $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
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
            if ((string)($row['line_key'] ?? '') === (string)($line['line_key'] ?? '')) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                    '本次添加请求已经处理，请不要重复提交。',
                    CashierV3ResultCode::STATUS_CONFLICT,
                    ['line_id' => (string)($line['line_key'] ?? ''), 'reason' => 'sale_add_intent_reused']
                );
            }
            $maxSort = max($maxSort, (int)($row['sort_no'] ?? 0));
        }
        $record = $this->normalizePersistedSaleLine($line, $workspaceId, $memberId);
        $now = time();
        $record['sort_no'] = $maxSort + 1;
        $record['add_time'] = $now;
        $record['update_time'] = $now;
        Db::name(self::LINE_TABLE)->insert($record);
        $rowsAfter = array_merge($existing, [$record]);
        $draft = $this->persistDraftWithRowsInTx($workspaceId, $draft, [
            'member_id' => $memberId,
            'draft_status' => self::STATUS_EDITING,
        ], $rowsAfter);
        return $this->toPublicDraft($draft, $rowsAfter);
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
        return $this->appendSaleLineInTx($workspaceId, $stateContextId, $operatorScope, $line, $draft);
    }

    /** @param array<int,array> $lines 由权益选择器提供的本次展示快照草稿行 */
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
        foreach ($existing as $row) {
            if ((string)($row['line_role'] ?? '') === self::ROLE_ENTITLEMENT
                && (int)($row['member_id'] ?? 0) === $memberId
                && (int)($row['holder_id'] ?? 0) === (int)$record['holder_id']
                && (int)($row['source_detail_id'] ?? 0) === (int)$record['source_detail_id']) {
                // 购物车只是草稿。同一权益池的重复项目由前端合并展示，
                // 最终可用次数由结账事务重新读取权威数据后判断。
            }
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
        // `$existing` 已经是本事务中锁定的完整草稿行。同步内存快照后可直接
        // 继续生成回执，避免“刷新同一权益池 → 再读整车 → 再读整车”三次查询。
        foreach ($existing as &$existingRow) {
            if ((string)($existingRow['line_role'] ?? '') !== self::ROLE_ENTITLEMENT
                || (int)($existingRow['member_id'] ?? 0) !== $memberId
                || (int)($existingRow['holder_id'] ?? 0) !== (int)$record['holder_id']
                || (int)($existingRow['source_detail_id'] ?? 0) !== (int)$record['source_detail_id']) {
                continue;
            }
            $existingRow['project_id'] = (int)$record['project_id'];
            $existingRow['source_version'] = (int)$record['source_version'];
            $existingRow['detail_version'] = (int)$record['detail_version'];
            $existingRow['display_snapshot_json'] = (string)$record['display_snapshot_json'];
            $existingRow['update_time'] = $now;
        }
        unset($existingRow);
        $record['sort_no'] = ++$maxSort;
        $record['add_time'] = $now;
        $record['update_time'] = $now;
        Db::name(self::LINE_TABLE)->insert($record);

        $rowsAfterAppend = array_merge($existing, [$record]);
        $draft = $this->persistDraftWithRowsInTx($workspaceId, $draft, [
            'member_id' => $memberId,
            'customer_mode' => self::MODE_MEMBER,
            'draft_status' => self::STATUS_EDITING,
        ], $rowsAfterAppend);
        return $this->toPublicDraft($draft, $rowsAfterAppend);
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
        $hasGuides = array_key_exists('guideSelections', $settings);
        $hasSalesManagers = array_key_exists('salesManagerSelections', $settings);
        $hasExperience = array_key_exists('isExperience', $settings);
        $hasFriendCounts = array_key_exists('friendCountsAsCustomer', $settings);
        $hasLaborManualFee = array_key_exists('laborManualFee', $settings);
        $hasPresale = array_key_exists('isPresale', $settings);
        $hasInventoryOutbound = array_key_exists('inventoryOutboundRequired', $settings);
        if (($hasPresale || $hasInventoryOutbound) && (!$isSale || $isSaleProject)) {
            throw $this->incompleteLineSettings($lineKey, 'inventory_rule_product_only');
        }
        if ($this->isCardOperationUpgradeSaleRow((array)$line)
            && (!$hasSalespeople || $hasGuides || $hasSalesManagers || $hasServiceObject || $hasCraftsmen || $hasExperience || $hasFriendCounts || $hasLaborManualFee || $hasPresale || $hasInventoryOutbound)) {
            throw $this->cardOperationUpgradeCartLocked();
        }
        if (!$hasServiceObject && !$hasCraftsmen && !$hasSalespeople && !$hasGuides && !$hasSalesManagers && !$hasExperience && !$hasFriendCounts && !$hasLaborManualFee && !$hasPresale && !$hasInventoryOutbound) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '购物车服务设置无效，请重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'service_settings_missing']
            );
        }

        // 权益核销没有本次销售业绩。即使直接调用事务服务，也不能写入销售人快照。
        if ($isEntitlement && ($hasSalespeople || $hasGuides || $hasSalesManagers)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '权益项目只支持设置手艺人。',
                CashierV3ResultCode::STATUS_FAILED,
                ['line_id' => $lineKey, 'reason' => 'entitlement_salespeople_forbidden']
            );
        }

        // 销售人和集团归属属于销售明细，不依赖项目服务设置；产品和卡项同样可以保存。
        if ($isSale && !$isSaleProject) {
            if ($hasServiceObject || $hasCraftsmen || $hasExperience || $hasFriendCounts || $hasLaborManualFee) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '该商品不支持设置服务对象、手艺人或体验标记。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['line_id' => $lineKey, 'reason' => 'sale_non_project_setting_invalid']
                );
            }
            $isPresale = (int)($line['is_presale'] ?? 0);
            $inventoryOutboundRequired = (int)($line['inventory_outbound_required'] ?? 1);
            if (!in_array($isPresale, [0, 1], true) || !in_array($inventoryOutboundRequired, [0, 1], true)) {
                throw $this->incompleteLineSettings($lineKey, 'stored_inventory_rule_invalid');
            }
            if ($hasPresale) {
                if (!is_bool($settings['isPresale']) && !(is_int($settings['isPresale']) && in_array($settings['isPresale'], [0, 1], true))) throw $this->incompleteLineSettings($lineKey, 'presale_flag_invalid');
                $isPresale = $settings['isPresale'] ? 1 : 0;
                if ($isPresale === 1) $inventoryOutboundRequired = 0;
            }
            if ($hasInventoryOutbound) {
                if (!is_bool($settings['inventoryOutboundRequired']) && !(is_int($settings['inventoryOutboundRequired']) && in_array($settings['inventoryOutboundRequired'], [0, 1], true))) throw $this->incompleteLineSettings($lineKey, 'inventory_outbound_flag_invalid');
                $inventoryOutboundRequired = $settings['inventoryOutboundRequired'] ? 1 : 0;
                if ($isPresale === 1 && $inventoryOutboundRequired === 1) throw $this->incompleteLineSettings($lineKey, 'presale_must_not_outbound');
            }
            $salespeople = $this->decodeStoredSalespeople($line, $lineKey);
            if ($hasSalespeople) {
                if (!is_array($settings['salespeople'])) throw $this->incompleteLineSettings($lineKey, 'salespeople_invalid');
                $salespeople = $this->authoritativeSalespeopleInTx($settings['salespeople'], $operatorScope);
            }
            $guides = $this->decodeStoredGuideSelections($line, $lineKey);
            if ($hasGuides) {
                if (!is_array($settings['guideSelections'])) throw $this->incompleteLineSettings($lineKey, 'guide_selections_invalid');
                $guides = $this->authoritativeGuideSelectionsInTx(
                    $settings['guideSelections'],
                    $operatorScope
                );
            }
            $salesManagers = $this->decodeStoredSalesManagerSelections($line, $lineKey);
            if ($hasSalesManagers) {
                if (!is_array($settings['salesManagerSelections'])) throw $this->incompleteLineSettings($lineKey, 'sales_manager_selections_invalid');
                $salesManagers = $this->authoritativeSalesManagerSelectionsInTx($settings['salesManagerSelections'], $operatorScope);
            }
            $affected = Db::name(self::LINE_TABLE)
                ->where('id', (int)$line['id'])
                ->where('workspace_id', $workspaceId)
                ->where('line_key', $lineKey)
                ->update([
                    'salespeople_json' => $this->encodeJson($salespeople),
                    'guide_selections_json' => $this->encodeJson($guides),
                    'sales_manager_selections_json' => $this->encodeJson($salesManagers),
                    'is_presale' => $isPresale,
                    'inventory_outbound_required' => $inventoryOutboundRequired,
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
        $friendCountsAsCustomer = (int)($line['friend_counts_as_customer'] ?? 1);
        if (!in_array($friendCountsAsCustomer, [0, 1], true)) {
            throw $this->incompleteLineSettings($lineKey, 'stored_friend_counts_as_customer_invalid');
        }
        if ($hasFriendCounts) {
            if ((!$isSaleProject && !$isEntitlement)
                || (!is_bool($settings['friendCountsAsCustomer'])
                    && !(is_int($settings['friendCountsAsCustomer'])
                        && in_array($settings['friendCountsAsCustomer'], [0, 1], true)))) {
                throw $this->incompleteLineSettings($lineKey, 'friend_counts_as_customer_invalid');
            }
            $friendCountsAsCustomer = $settings['friendCountsAsCustomer'] ? 1 : 0;
        }
        // “本人”始终是当前会员本人，不允许标记为不计客；朋友默认计客，除非
        // 当前行明确选择“朋友不算”。
        if ($serviceObject === 'self') {
            $friendCountsAsCustomer = 1;
        } elseif ($hasServiceObject && !$hasFriendCounts) {
            $friendCountsAsCustomer = 1;
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
                $operatorScope,
                $this->laborProjectIdFromLine($line)
            );
        } else {
            // partial 更新也重验已保存人员，避免手艺人离职后通过切换其它字段继续保留。
            $craftsmen = $this->authoritativeCraftsmenInTx(
                $this->craftsmanSelectionsFromSnapshot($craftsmen, $lineKey),
                $operatorScope,
                $this->laborProjectIdFromLine($line)
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
        // Guide attribution belongs to a sale line. Entitlement service rows
        // carry only service craftsmen; retaining legacy guide snapshots on
        // those rows makes the final authority fingerprint drift between
        // pure-entitlement and mixed checkout paths.
        $guides = $isSale ? $this->decodeStoredGuideSelections($line, $lineKey) : [];
        if ($isSale && array_key_exists('guideSelections', $settings)) {
            if (!is_array($settings['guideSelections'])) {
                throw $this->incompleteLineSettings($lineKey, 'guide_selections_invalid');
            }
            $guides = $this->authoritativeGuideSelectionsInTx(
                $settings['guideSelections'],
                $operatorScope
            );
        }
        $salesManagers = $isSale ? $this->decodeStoredSalesManagerSelections($line, $lineKey) : [];
        if ($isSale && $hasSalesManagers) {
            if (!is_array($settings['salesManagerSelections'])) {
                throw $this->incompleteLineSettings($lineKey, 'sales_manager_selections_invalid');
            }
            $salesManagers = $this->authoritativeSalesManagerSelectionsInTx($settings['salesManagerSelections'], $operatorScope);
        }
        $manualLaborFeeCents = ($line['manual_labor_fee_cents'] ?? null) === null
            ? null
            : $this->storedNonnegativeInteger($line['manual_labor_fee_cents'], $lineKey, 'manual_labor_fee_cents');
        if ($hasLaborManualFee) {
            $manualLaborFeeCents = $this->manualLaborFeeCents($settings['laborManualFee'], $lineKey);
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
                'guide_selections_json' => $this->encodeJson($guides),
                'sales_manager_selections_json' => $this->encodeJson($salesManagers),
                'manual_labor_fee_cents' => $manualLaborFeeCents,
                'friend_counts_as_customer' => $friendCountsAsCustomer,
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

    private function decodeStoredGuideSelections(array $line, string $lineKey): array
    {
        $raw = trim((string)($line['guide_selections_json'] ?? ''));
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw $this->incompleteLineSettings($lineKey, 'stored_guide_selections_invalid');
        return array_values($decoded);
    }

    private function decodeStoredSalesManagerSelections(array $line, string $lineKey): array
    {
        $raw = trim((string)($line['sales_manager_selections_json'] ?? ''));
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw $this->incompleteLineSettings($lineKey, 'stored_sales_manager_selections_invalid');
        return array_values($decoded);
    }

    private function authoritativeGuideSelectionsInTx(array $guides, CashierV3OperatorScope $operatorScope): array
    {
        $ids = [];
        $rounds = [];
        foreach ($guides as $guide) {
            $id = (int)($guide['employeeId'] ?? $guide['employee_id'] ?? $guide['id'] ?? 0);
            if ($id <= 0 || isset($ids[$id])) throw $this->incompleteLineSettings('', 'guide_selection_invalid');
            $roundNo = (int)($guide['guideRoundNo'] ?? $guide['guide_round_no'] ?? 0);
            if ($roundNo < 1 || $roundNo > 3) throw $this->incompleteLineSettings('', 'guide_round_required');
            $ids[$id] = true;
            $rounds[$roundNo] = true;
        }
        if (!$ids) return [];
        if (count($rounds) !== 1) throw $this->incompleteLineSettings('', 'guide_round_conflict');
        $this->assertAttributionEmployeesInScope(array_keys($ids), $operatorScope, 'guide_employee_out_of_scope');
        $rows = Db::name('employee')->whereIn('id', array_keys($ids))->where('status', 1)->where('is_del', 0)->lock(true)->select()->toArray();
        if (count($rows) !== count($ids)) throw $this->incompleteLineSettings('', 'guide_employee_not_active');
        $result = [];
        $roundNo = (int)array_key_first($rounds);
        foreach ($rows as $row) $result[] = ['employeeId' => (int)$row['id'], 'name' => (string)$row['name'], 'guideRoundNo' => $roundNo];
        return $result;
    }

    private function authoritativeSalesManagerSelectionsInTx(array $managers, CashierV3OperatorScope $operatorScope): array
    {
        $ids = [];
        foreach ($managers as $manager) {
            $id = (int)($manager['employeeId'] ?? $manager['employee_id'] ?? $manager['id'] ?? 0);
            if ($id <= 0 || isset($ids[$id])) throw $this->incompleteLineSettings('', 'sales_manager_selection_invalid');
            $ids[$id] = true;
        }
        if (count($ids) > 1) {
            throw $this->incompleteLineSettings('', 'sales_manager_selection_limit_exceeded');
        }
        if (!$ids) return [];
        $this->assertAttributionEmployeesInScope(array_keys($ids), $operatorScope, 'sales_manager_employee_out_of_scope');
        $rows = Db::name('employee')->whereIn('id', array_keys($ids))->where('status', 1)->where('is_del', 0)->lock(true)->select()->toArray();
        if (count($rows) !== count($ids)) throw $this->incompleteLineSettings('', 'sales_manager_employee_not_active');
        $result = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') throw $this->incompleteLineSettings('', 'sales_manager_employee_name_missing');
            $result[] = ['employeeId' => (int)$row['id'], 'name' => $name, 'employeeTypeCodeSnapshot' => (string)($row['employment_type_code'] ?? '')];
        }
        return $result;
    }

    /**
     * 导购/销售经理属于集团归属。候选与最终锁读都只接受本地实例内的在职员工，
     * 不采信客户端传入的组织、门店或人员名称。
     */
    private function assertAttributionEmployeesInScope(array $employeeIds, CashierV3OperatorScope $operatorScope, string $reason): void
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (!$employeeIds) return;
        $activeIds = Db::name('employee')
            ->whereIn('id', $employeeIds)
            ->where('status', 1)
            ->where('is_del', 0)
            ->column('id');
        if (count(array_diff($employeeIds, array_map('intval', $activeIds))) !== 0) {
            throw $this->incompleteLineSettings('', $reason);
        }
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
        $memberId = (int)($draft['member_id'] ?? 0);
        $productType = (int)($line['catalog_product_type'] ?? -1);
        if ($memberId <= 0 || !in_array($productType, [0, 4, 5], true)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '只有会员购买的卡项或产品可以设置欠款。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_line_debt_not_supported', 'line_id' => $lineKey]
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

    public function couponSelector(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey
    ): array {
        $draft = $this->readDraft($workspaceId, $stateContextId, $operatorScope, false);
        $memberId = (int)($draft['memberId'] ?? 0);
        if ($memberId <= 0) {
            throw CashierV3CommandException::invalidContext('请先选择会员后再使用优惠券。');
        }
        $line = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->where('line_role', self::ROLE_SALE)
            ->find();
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        $amounts = $this->couponLineAmounts((array)$line);
        return [
            'lineId' => $lineKey,
            'lineAmountCents' => $amounts['threshold'],
            'coupons' => $this->availableCoupons($memberId, $operatorScope->storeId(), $amounts, false, $workspaceId, $lineKey),
            'selectedCouponId' => (int)($line['coupon_user_id'] ?? 0),
        ];
    }

    /** Read applicable coupons for a browser-only line without creating it. */
    public function localCouponSelector(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        int $lineAmountCents,
        int $thresholdCents,
        array $reservedCouponIds = []
    ): array {
        $draft = $this->readDraft($workspaceId, $stateContextId, $operatorScope, false);
        $memberId = (int)($draft['memberId'] ?? 0);
        if ($memberId <= 0) {
            throw CashierV3CommandException::invalidContext('请先选择会员后再使用优惠券。');
        }
        if ($lineKey === '' || $lineAmountCents < 0 || $thresholdCents < $lineAmountCents) {
            throw $this->incompleteDraft('local_coupon_line_invalid');
        }
        $amounts = ['threshold' => $thresholdCents, 'base' => $lineAmountCents, 'cap' => $lineAmountCents];
        return [
            'lineId' => $lineKey,
            'lineAmountCents' => $thresholdCents,
            'coupons' => $this->availableCoupons(
                $memberId, $operatorScope->storeId(), $amounts, false, $workspaceId, $lineKey, 0, $reservedCouponIds
            ),
            'selectedCouponId' => 0,
        ];
    }

    public function applyLineCouponInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey,
        int $couponId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceApplyCoupon');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $memberId = (string)($draft['customer_mode'] ?? '') === self::MODE_MEMBER
            ? (int)($draft['member_id'] ?? 0) : 0;
        if ($memberId <= 0 || $couponId <= 0) {
            throw CashierV3CommandException::invalidContext('请选择当前会员可用的优惠券。');
        }
        $line = Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)->where('line_role', self::ROLE_SALE)->lock(true)->find();
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        $amounts = $this->couponLineAmounts((array)$line);
        $coupons = $this->availableCoupons($memberId, $operatorScope->storeId(), $amounts, true, $workspaceId, $lineKey, $couponId);
        if (count($coupons) !== 1 || (int)$coupons[0]['couponId'] !== $couponId) {
            throw CashierV3CommandException::versionConflict('该优惠券当前不可使用，请重新选择。', ['coupon_id' => $couponId]);
        }
        $coupon = $coupons[0];
        $discount = (int)$coupon['discountAmountCents'];
        $changes = [
            'coupon_user_id' => $couponId,
            'coupon_name_snapshot' => (string)$coupon['name'],
            'coupon_discount_cents' => $discount,
            'unit_price_cents' => max(0, $amounts['base'] - $discount),
            'update_time' => time(),
        ];
        Db::name(self::LINE_TABLE)->where('id', (int)$line['id'])->where('workspace_id', $workspaceId)->update($changes);
        $this->updateDraft($workspaceId, []);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    public function removeLineCouponInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $lineKey
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceRemoveCoupon');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $line = Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)->where('line_role', self::ROLE_SALE)->lock(true)->find();
        if (!$line) {
            throw CashierV3ScopeResolver::notFound('cashier_workspace_line', $lineKey);
        }
        $base = $this->couponLineAmounts((array)$line)['base'];
        $changes = ['coupon_user_id' => 0, 'coupon_name_snapshot' => '', 'coupon_discount_cents' => 0,
            'unit_price_cents' => $base, 'update_time' => time()];
        Db::name(self::LINE_TABLE)->where('id', (int)$line['id'])->where('workspace_id', $workspaceId)->update($changes);
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
        $lines = $this->lineRows($workspaceId, true);
        if ($beforeDelete !== null) {
            foreach ($lines as $line) {
                $beforeDelete((array)$line);
            }
        }
        Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)->delete();
        // 清空购物车就是草稿重置。若当前内容来自提单，同时解除这次
        // 工作台关联，原挂单仍留在列表中，供之后再次提取或删除。
        $this->updateDraft($workspaceId, ['resumed_hang_order_id' => '']);
        $draft = $this->persistDraftWithRowsInTx($workspaceId, $draft, [], []);
        return $this->toPublicDraft($draft, []);
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
        if ($delta === 0 || abs($delta) > 1000000) {
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
        if ((string)($line['line_role'] ?? '') === self::ROLE_ENTITLEMENT
            || (string)($line['line_role'] ?? '') === self::ROLE_SALE) {
            // 数量编辑只改草稿；权益余次与库存均在最终结账时校验。
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
     * 最终快照提交的 sale 来源集合。这里只做行锁内重验和 DTO 组装，
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
            // Coupon selection belongs to the locked workspace line, rather
            // than the catalog source. Carry the immutable checkout snapshot
            // forward so the request, order, and sale fact share one line
            // fingerprint even when no coupon is selected.
            $source['couponUserId'] = (int)($row['coupon_user_id'] ?? 0);
            $source['couponNameSnapshot'] = (string)($row['coupon_name_snapshot'] ?? '');
            $source['couponDiscountCents'] = (int)($row['coupon_discount_cents'] ?? 0);
            // Personnel attribution is part of the locked workspace line
            // authority. Carry the server-normalized snapshots into the
            // checkout preparation snapshot; the catalog source loader must
            // never be the source of these user-selected assignments.
            $lineKey = (string)($row['line_key'] ?? '');
            $source['guideSelections'] = $this->decodeStoredGuideSelections($row, $lineKey);
            $source['salesManagerSelections'] = $this->decodeStoredSalesManagerSelections($row, $lineKey);
            $source['friendCountsAsCustomer'] = (int)($row['friend_counts_as_customer'] ?? 1) === 1;
            if (($row['manual_labor_fee_cents'] ?? null) !== null) {
                $source['laborManualFeeCents'] = $this->storedNonnegativeInteger(
                    $row['manual_labor_fee_cents'],
                    $lineKey,
                    'manual_labor_fee_cents'
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
     * New checkout requests persist salesperson allocations with the immutable
     * request line.  Returning null keeps historical workspace-backed drafts
     * on the existing compatibility path.
     *
     * @return array<string,array<int,array>>|null
     */
    public function salespeopleFromCheckoutRequestLinesInTx(
        array $checkoutLines,
        CashierV3OperatorScope $operatorScope
    ): ?array {
        CashierV3TransactionGuard::assertInTransaction('cashierCheckoutRequestSalespeople');
        $result = [];
        foreach ($checkoutLines as $line) {
            if ((string)($line['line_role'] ?? '') !== self::ROLE_SALE) continue;
            $checkoutLineId = trim((string)($line['line_id'] ?? ''));
            if ($checkoutLineId === '') {
                return null;
            }
            // A persisted snapshot with no salesperson is a valid empty
            // selection. Only a missing column/key means this request was
            // built before salesperson snapshots were available and must use
            // the legacy workspace compatibility path.
            if (!array_key_exists('salespeople_snapshot_json', $line)) {
                return null;
            }
            $raw = $line['salespeople_snapshot_json'];
            if ($raw === null || trim((string)$raw) === '') {
                $result[$checkoutLineId] = [];
                continue;
            }
            $stored = json_decode((string)$raw, true);
            if (!is_array($stored)) {
                throw $this->incompleteLineSettings($checkoutLineId, 'checkout_salespeople_snapshot_invalid');
            }
            $selections = [];
            foreach ($stored as $person) {
                if (!is_array($person)) {
                    throw $this->incompleteLineSettings($checkoutLineId, 'checkout_salespeople_snapshot_invalid');
                }
                $selections[] = [
                    'staffId' => (int)($person['staffId'] ?? $person['id'] ?? 0),
                    'allocationWeight' => (int)($person['allocationWeight'] ?? 0),
                ];
            }
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

    /**
     * Complete a checkout whose authority lives in checkout_request rows.
     *
     * The browser snapshot is deliberately not materialized into this
     * workspace, so completion must never compare checkout lines with the
     * presentation cart. The gateway has already locked the workspace
     * context; this method only clears its shell after all sale facts commit.
     */
    public function completeSnapshotCheckoutInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceSnapshotCheckoutCompletion');
        $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $workspaceRows = $this->lineRows($workspaceId, true);
        $deleted = (int)Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->delete();
        if ($deleted !== count($workspaceRows)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '结账后购物车清理失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_snapshot_workspace_clear_incomplete']
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
        string $expectedLineFingerprint,
        bool $retainMember = false,
        bool $requireFingerprint = true
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceHangTransfer');
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->assertNotResumedHangMutation($draft);
        $workspaceRows = $this->lineRows($workspaceId, true);
        $publicDraft = $this->toPublicDraft($draft, $workspaceRows);
        if (!$workspaceRows) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '请先添加需要挂单的项目。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_hang_workspace_empty']
            );
        }
        if ($requireFingerprint && ($expectedLineFingerprint === ''
            || !hash_equals((string)$publicDraft['lineFingerprint'], $expectedLineFingerprint))) {
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
        $changes = ['draft_status' => self::STATUS_EDITING];
        if (!$retainMember) {
            $changes['member_id'] = 0;
            $changes['customer_mode'] = self::MODE_GUEST;
        }
        $this->updateDraft($workspaceId, $changes);
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
     * Restore a new-contract hang draft by atomically replacing the
     * current workspace. The caller locks the hang first; this method locks
     * both draft and existing rows, removes the old draft rows, then writes
     * the immutable hang snapshots in the same transaction.
     *
     * @param array<int,array> $frozenRows authoritative workspace snapshots,
     *   ordered by original line_no.
     */
    public function restoreHangDraftInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $hangOrderId,
        int $memberId,
        array $frozenRows
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceRestoreHangDraft');
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1 || $memberId < 0) {
            throw $this->incompleteDraft('cashier_hang_restore_identity_invalid');
        }
        $draft = $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $existingRows = $this->lineRows($workspaceId, true);
        if ($frozenRows === [] || count($frozenRows) > 1000) {
            throw $this->incompleteDraft('cashier_hang_restore_lines_invalid');
        }

        $records = [];
        $seenLineKeys = [];
        $now = time();
        foreach (array_values($frozenRows) as $index => $line) {
            if (!is_array($line)) {
                throw $this->incompleteDraft('cashier_hang_restore_line_invalid');
            }
            $record = $this->normalizeRestoredHangLine(
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
            'resumed_hang_order_id' => '',
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** Restore only the member shell for a local-operation hang draft. */
    public function restoreLocalHangDraftShellInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $hangOrderId,
        int $memberId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceRestoreLocalHangDraft');
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1 || $memberId < 0) {
            throw $this->incompleteDraft('cashier_local_hang_restore_identity_invalid');
        }
        $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)->delete();
        $this->updateDraft($workspaceId, [
            'member_id' => $memberId,
            'customer_mode' => $memberId > 0 ? self::MODE_MEMBER : self::MODE_GUEST,
            'draft_status' => self::STATUS_EDITING,
            'resumed_hang_order_id' => $hangOrderId,
        ]);
        return $this->readDraft($workspaceId, $stateContextId, $operatorScope, true);
    }

    /** Mark the restored workspace as awaiting checkout of its source hang. */
    public function bindResumedHangOrderInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        string $hangOrderId
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierWorkspaceBindResumedHang');
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1) {
            throw $this->incompleteDraft('cashier_hang_binding_identity_invalid');
        }
        $this->lockOrCreateDraft($workspaceId, $stateContextId, $operatorScope);
        $this->updateDraft($workspaceId, [
            'resumed_hang_order_id' => $hangOrderId,
            'draft_status' => self::STATUS_EDITING,
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
                'laborManualFeeCents' => ($workspaceLine['manual_labor_fee_cents'] ?? null) === null
                    ? null
                    : $this->storedNonnegativeInteger($workspaceLine['manual_labor_fee_cents'], $lineKey, 'manual_labor_fee_cents'),
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
                // Older SKU rows can persist a zero list price. The workspace
                // projection already displays that as the current sale price,
                // so completion must apply the same normalized list price.
                && (int)($workspaceLine['original_unit_price_cents'] ?? -1) >= 0
                && $this->checkedLineAmount(
                    max(
                        (int)($workspaceLine['original_unit_price_cents'] ?? -1),
                        (int)($workspaceLine['unit_price_cents'] ?? -1)
                    ),
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
            || (int)($row['store_id'] ?? 0) !== $operatorScope->storeId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台已经变化，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_workspace_draft_binding_mismatch']
            );
        }
    }

    /** Hang orders are copied drafts; they never lock later cart edits. */
    private function assertNotResumedHangMutation(array $draft): void
    {
        // Kept as a compatibility hook for old callers. Cart mutations remain
        // ordinary draft operations; only the successful checkout path uses
        // the source id to remove the retained hang draft.
    }

    private function assertWorkspaceIdentity(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope
    ): void {
        $expected = CashierV3CheckoutWorkspaceIdentity::id($operatorScope->storeId(), $stateContextId);
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
        $this->persistDraftWithRowsInTx($workspaceId, [], $changes, $rows);
    }

    /**
     * Persist a draft using rows already locked by the current transaction.
     * Mutation commands must not re-query the same workspace lines merely to
     * rebuild the fingerprint and response snapshot.
     */
    private function persistDraftWithRowsInTx(
        string $workspaceId,
        array $draft,
        array $changes,
        array $rows
    ): array {
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
        return array_merge($draft, $changes);
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
        CashierV3OperatorScope $operatorScope,
        int $projectId = 0
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
        $types = [];
        $requestedFees = [];
        $requestedProjectCounts = [];
        $hasProjectCount = false;
        $pointFlags = [];
        $personnelSources = [];
        foreach ($selections as $selection) {
            $staffId = is_array($selection) ? (int)($selection['staffId'] ?? 0) : 0;
            $weight = is_array($selection) ? (int)($selection['laborWeight'] ?? 0) : 0;
            $requestedType = is_array($selection)
                ? trim((string)($selection['craftsmanPerformanceType'] ?? $selection['craftsman_performance_type'] ?? ''))
                : '';
            $normalizedType = in_array($requestedType, EmployeeCraftsmanPerformanceTypeServices::TYPES, true)
                ? $requestedType
                : EmployeeCraftsmanPerformanceTypeServices::COMMISSION_LABOR;
            $duplicate = $staffId > 0 && isset($seen[$staffId]);
            if ($staffId <= 0 || $weight < 0 || $weight > 100 || $duplicate) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => $duplicate ? 'duplicate_craftsman' : 'craftsman_id_invalid']
                );
            }
            $seen[$staffId] = true;
            $personnelSources[$staffId] = trim((string)($selection['personnelSource'] ?? $selection['personnel_source'] ?? ''));
            $weights[$staffId] = $weight;
            $types[$staffId] = $normalizedType;
            $requestedFees[$staffId] = max(0, (int)($selection['laborFeeCents'] ?? $selection['labor_fee_cents'] ?? 0));
            if (array_key_exists('projectCountHalfUnits', $selection)
                || array_key_exists('project_count_half_units', $selection)) {
                $requestedProjectCounts[$staffId] = max(0, (int)($selection['projectCountHalfUnits'] ?? $selection['project_count_half_units'] ?? 0));
                $hasProjectCount = true;
            }
            $pointFlags[$staffId] = is_array($selection) && !empty($selection['isPointCustomer']);
        }
        // Labor-only craftsmen do not participate in the commission ratio.
        // For mixed selections, only commission-capable rows must total 100.
        $commissionWeight = 0;
        foreach ($weights as $staffId => $weight) {
            if ($types[$staffId] !== EmployeeCraftsmanPerformanceTypeServices::LABOR) {
                $commissionWeight += $weight;
            }
        }
        if ($commissionWeight > 0 && $commissionWeight !== 100) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '手艺人分配比例合计必须为 100%。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsman_weight_sum_invalid']
            );
        }

        $lockIds = array_keys($seen);
        sort($lockIds, SORT_NUMERIC);
        $otherStaffIds = array_keys(array_filter($personnelSources, static function (string $source): bool {
            return $source === 'other';
        }));
        $staffQuery = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
            ->whereIn('ss.id', $lockIds)
            ->where('ss.store_id', $operatorScope->storeId())
            ->where('ss.status', 1)
            ->where('ss.is_del', 0)
            ->where('ss.employee_id', '>', 0)
            ->where('ss.cashier_craftsman_enabled', 1)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,ss.cashier_craftsman_enabled,ss.craftsman_performance_type,e.name as employee_name')
            ->order('ss.id asc');
        $rows = $staffQuery->lock(true)->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) $rows = $rows->toArray();
        if ($otherStaffIds !== []) {
            $otherEmployeeIds = [];
            foreach ($otherStaffIds as $virtualStaffId) {
                $employeeId = CashierV3PersonnelIdentity::employeeIdFromStaffId((int)$virtualStaffId);
                if ($employeeId <= 0) {
                    throw new CashierV3CommandException(CashierV3ResultCode::ENTITLEMENT_LINE_INVALID, '所选组织手艺人身份无效，请重新选择。', CashierV3ResultCode::STATUS_FAILED, ['reason' => 'organization_craftsman_identity_invalid']);
                }
                $otherEmployeeIds[] = $employeeId;
            }
            $organizationId = (int)$operatorScope->organizationId();
            $organizationScope = app()->make(\app\services\organization\OrganizationScopeService::class);
            $organizationIds = $organizationId > 0 ? $organizationScope->getOrgIds($organizationId, true) : [];
            // 与人员查询保持同一后端权限口径：门店账号锚定在“直属”等分支时，
            // 允许选择同一集团根组织下的在职人员，但不接受客户端指定组织扩大范围。
            $rootOrganizationId = $organizationId;
            for ($i = 0; $i < 64 && $rootOrganizationId > 0; $i++) {
                $parentId = (int)Db::name('organization')->where('id', $rootOrganizationId)->value('pid');
                if ($parentId <= 0 || $parentId === $rootOrganizationId) break;
                $rootOrganizationId = $parentId;
            }
            if ($rootOrganizationId > 0 && $rootOrganizationId !== $organizationId) {
                $organizationIds = $organizationScope->getOrgIds($rootOrganizationId, true);
            }
            $organizationRows = $organizationIds === [] ? [] : Db::name('organization_employee')->alias('oe')
                ->join('employee e', 'e.id = oe.employee_id')
                ->whereIn('oe.employee_id', array_values(array_unique($otherEmployeeIds)))
                ->whereIn('oe.org_id', $organizationIds)
                ->where('oe.status', 1)->where('oe.is_del', 0)
                ->where('e.status', 1)->where('e.is_del', 0)
                ->field('oe.employee_id,e.name as employee_name')
                ->group('oe.employee_id,e.name')
                ->lock(true)->select()->toArray();
            foreach ($organizationRows as $row) {
                $employeeId = (int)($row['employee_id'] ?? 0);
                $virtualStaffId = CashierV3PersonnelIdentity::organizationStaffId($employeeId);
                $rows[] = [
                    'id' => $virtualStaffId,
                    'employee_id' => $employeeId,
                    'store_id' => $operatorScope->storeId(),
                    'staff_name' => (string)($row['employee_name'] ?? ''),
                    'cashier_craftsman_enabled' => 1,
                    'craftsman_performance_type' => EmployeeCraftsmanPerformanceTypeServices::COMMISSION_LABOR,
                    'employee_name' => (string)($row['employee_name'] ?? ''),
                    'personnel_source' => 'other',
                ];
            }
        }
        $byId = [];
        foreach ((array)$rows as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }
        if (count($byId) !== count($selections)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '所选手艺人已停用、离职或不属于当前门店，请重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsman_not_active_in_store']
            );
        }

        $craftsmen = [];
        $defaultLaborFeeCents = $projectId > 0 ? $this->projectLaborDefaultCents($projectId) : 0;
        $authoritativeCommissionWeight = 0;
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
            if (($personnelSources[$staffId] ?? '') !== 'other'
                && (int)($row['cashier_craftsman_enabled'] ?? 0) !== 1) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人已关闭手艺人资格，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['staff_id' => $staffId, 'reason' => 'craftsman_not_active_in_store']
                );
            }
            $type = trim((string)($row['craftsman_performance_type'] ?? ''));
            if (!in_array($type, EmployeeCraftsmanPerformanceTypeServices::TYPES, true)) {
                $type = $types[$staffId] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION_LABOR;
            }
            $effectiveWeight = $type === EmployeeCraftsmanPerformanceTypeServices::LABOR ? 0 : $weights[$staffId];
            if ($effectiveWeight <= 0 && $type !== EmployeeCraftsmanPerformanceTypeServices::LABOR) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '业绩提成类型的手艺人比例必须为正整数。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'craftsman_weight_invalid']
                );
            }
            $authoritativeCommissionWeight += $effectiveWeight;
            $laborFeeCents = $type === EmployeeCraftsmanPerformanceTypeServices::COMMISSION
                ? 0
                : ($requestedFees[$staffId] > 0 ? $requestedFees[$staffId] : $defaultLaborFeeCents);
            $personnelSource = ($personnelSources[$staffId] ?? '') === 'other' ? 'other' : 'store';
            // The virtual staff id is only the internal staff-resource key used to
            // avoid collisions with system_store_staff.id. Facts and snapshots
            // must always retain the real employee identity (for example 李倩=490).
            $employeeId = $personnelSource === 'other'
                ? CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId)
                : (int)$row['employee_id'];
            if ($employeeId <= 0) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '所选手艺人身份无效，请重新选择。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['staff_id' => $staffId, 'reason' => 'craftsman_employee_identity_invalid']
                );
            }
            $craftsman = [
                'id' => $staffId,
                'staffId' => $staffId,
                'employeeId' => $employeeId,
                'storeId' => (int)$row['store_id'],
                'name' => $name,
                'isPrimary' => $index === 0,
                'sequence' => $index + 1,
                'laborWeight' => $effectiveWeight,
                'craftsmanPerformanceType' => $type,
                'laborFeeCents' => $laborFeeCents,
                'isPointCustomer' => $pointFlags[$staffId],
                'personnelSource' => $personnelSource,
            ];
            if ($hasProjectCount) {
                $craftsman['projectCountHalfUnits'] = (int)($requestedProjectCounts[$staffId] ?? 0);
            }
            $craftsmen[] = $craftsman;
        }
        if ($authoritativeCommissionWeight > 0 && $authoritativeCommissionWeight !== 100) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '手艺人业绩比例合计必须为 100%。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'craftsman_weight_sum_invalid']
            );
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
            $type = trim((string)($craftsman['craftsmanPerformanceType'] ?? $craftsman['craftsman_performance_type'] ?? ''));
            if (!in_array($type, EmployeeCraftsmanPerformanceTypeServices::TYPES, true)) {
                $type = EmployeeCraftsmanPerformanceTypeServices::COMMISSION_LABOR;
            }
            if ($hasWeight && (($type !== EmployeeCraftsmanPerformanceTypeServices::LABOR && $weight <= 0) || $weight > 100 || $weight < 0)) {
                throw $this->incompleteLineSettings($lineKey, 'stored_craftsman_weight_invalid');
            }
            $needsLegacyEqualWeights = $needsLegacyEqualWeights || !$hasWeight;
            $selection = [
                'staffId' => $staffId,
                'laborWeight' => $weight,
                'isPointCustomer' => !empty($craftsman['isPointCustomer']),
                'craftsmanPerformanceType' => $type,
                'laborFeeCents' => max(0, (int)($craftsman['laborFeeCents'] ?? $craftsman['labor_fee_cents'] ?? 0)),
            ];
            if (array_key_exists('projectCountHalfUnits', $craftsman)
                || array_key_exists('project_count_half_units', $craftsman)) {
                $selection['projectCountHalfUnits'] = max(0, (int)($craftsman['projectCountHalfUnits'] ?? $craftsman['project_count_half_units'] ?? 0));
            }
            $selections[] = $selection;
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
        $commissionWeight = 0;
        foreach ($selections as $selection) {
            if (($selection['craftsmanPerformanceType'] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION_LABOR)
                !== EmployeeCraftsmanPerformanceTypeServices::LABOR) {
                $commissionWeight += (int)$selection['laborWeight'];
            }
        }
        if ($selections && $commissionWeight !== 100 && $commissionWeight !== 0) {
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
            || !is_int($originalUnitPriceCents)
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
            'coupon_user_id' => 0,
            'coupon_name_snapshot' => '',
            'coupon_discount_cents' => 0,
            'price_change_reason' => '',
            'price_changed_by' => 0,
            'price_changed_by_name_snapshot' => '',
            'price_changed_at' => 0,
            'authority_fingerprint' => $fingerprint,
            'authority_snapshot_json' => $this->encodeJson($authoritySnapshot),
            'service_object' => (string)($line['service_object'] ?? ''),
            'craftsmen_json' => $this->encodeJson([]),
            'salespeople_json' => $this->encodeJson([]),
            'guide_selections_json' => $this->encodeJson([]),
            'sales_manager_selections_json' => $this->encodeJson([]),
            'manual_labor_fee_cents' => null,
            // These customer/report dimensions are part of the locked line
            // authority.  Persist the defaults explicitly so the workspace
            // fingerprint and the final sales-order plan use the same shape.
            'friend_counts_as_customer' => (int)($line['friend_counts_as_customer'] ?? 1),
            'is_experience' => 0,
                'is_presale' => (int)($line['is_presale'] ?? 0),
                'inventory_outbound_required' => (int)($line['inventory_outbound_required'] ?? 1),
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
        $projectId = (int)($line['project_id'] ?? 0);
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
        $isServiceProject = $productType === 6 && $projectId === $productId;
        if (preg_match('/^sale:[a-f0-9]{48}$/D', $lineKey) !== 1
            || (string)($line['line_role'] ?? '') !== self::ROLE_SALE
            || (int)($line['member_id'] ?? -1) !== $memberId
            || $productId <= 0 || $skuId <= 0 || !in_array($productType, [0, 6], true)
            || ($productType === 0 ? $projectId !== 0 : !$isServiceProject)
            || $quantity <= 0
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
            'manual_labor_fee_cents' => isset($line['manual_labor_fee_cents']) ? (int)$line['manual_labor_fee_cents'] : null,
        ];
        $authority = $this->decodeStoredAuthoritySnapshot($probe, $lineKey);
        $display = $this->decodeStoredDisplaySnapshot($probe, $lineKey);
        $craftsmen = $this->decodeStoredCraftsmen($probe, $lineKey);
        $salespeople = $this->decodeStoredSalespeople($probe, $lineKey);
        if (($productType === 0 && ($craftsmen !== [] || $salespeople !== []))
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
            'project_id' => $projectId,
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
            'service_object' => (string)($line['service_object'] ?? ''),
            'craftsmen_json' => $craftsmenJson,
            'salespeople_json' => $salespeopleJson,
            'manual_labor_fee_cents' => isset($line['manual_labor_fee_cents']) ? (int)$line['manual_labor_fee_cents'] : null,
            'is_experience' => (int)($line['is_experience'] ?? 0),
            'display_snapshot_json' => $displayJson,
            'sort_no' => $sortNo,
        ];
    }

    /**
     * A hang order restores its own immutable workspace rows.  This is a
     * structural integrity check only: availability, price, inventory and
     * entitlement eligibility belong to the subsequent checkout authority.
     */
    private function normalizeRestoredHangLine(
        array $line,
        string $workspaceId,
        int $memberId,
        int $sortNo
    ): array {
        $lineKey = trim((string)($line['line_key'] ?? ''));
        $role = (string)($line['line_role'] ?? '');
        $quantity = (int)($line['quantity'] ?? 0);
        $sourceVersion = (int)($line['source_version'] ?? 0);
        $detailVersion = (int)($line['detail_version'] ?? 0);
        $displayJson = (string)($line['display_snapshot_json'] ?? '');
        $craftsmenJson = (string)($line['craftsmen_json'] ?? '');
        $salespeopleJson = (string)($line['salespeople_json'] ?? '');
        // Hangs written before the richer workspace snapshot are still
        // resumable. New hangs always include these fields, while old hangs
        // retain their former empty/default representation.
        $guideSelectionsJson = array_key_exists('guide_selections_json', $line)
            ? (string)$line['guide_selections_json']
            : $this->encodeJson([]);
        $salesManagerSelectionsJson = array_key_exists('sales_manager_selections_json', $line)
            ? (string)$line['sales_manager_selections_json']
            : $this->encodeJson([]);
        if ($lineKey === ''
            || !in_array($role, [self::ROLE_SALE, self::ROLE_ENTITLEMENT], true)
            || (int)($line['member_id'] ?? -1) !== $memberId
            || $quantity <= 0 || $sourceVersion <= 0 || $detailVersion <= 0
            || $displayJson === '' || $craftsmenJson === '' || $salespeopleJson === ''
            || !is_array(json_decode($displayJson, true))
            || !is_array(json_decode($craftsmenJson, true))
            || !is_array(json_decode($salespeopleJson, true))
            || !in_array((int)($line['is_experience'] ?? -1), [0, 1], true)
            || $sortNo <= 0) {
            throw $this->incompleteDraftLine($lineKey, 'cashier_hang_restore_snapshot_invalid');
        }

        $record = [
            'workspace_id' => $workspaceId,
            'line_key' => $lineKey,
            'line_role' => $role,
            'member_id' => $memberId,
            'holder_id' => (int)($line['holder_id'] ?? 0),
            'source_detail_id' => (int)($line['source_detail_id'] ?? 0),
            'project_id' => (int)($line['project_id'] ?? 0),
            'catalog_product_id' => (int)($line['catalog_product_id'] ?? 0),
            'catalog_sku_id' => (int)($line['catalog_sku_id'] ?? 0),
            'catalog_product_type' => (int)($line['catalog_product_type'] ?? 0),
            'quantity' => $quantity,
            'source_version' => $sourceVersion,
            'detail_version' => $detailVersion,
            'unit_price_cents' => (int)($line['unit_price_cents'] ?? 0),
            'original_unit_price_cents' => (int)($line['original_unit_price_cents'] ?? 0),
            'configured_cost_cents' => (int)($line['configured_cost_cents'] ?? 0),
            'debt_amount_cents' => (int)($line['debt_amount_cents'] ?? 0),
            'coupon_user_id' => (int)($line['coupon_user_id'] ?? 0),
            'coupon_name_snapshot' => (string)($line['coupon_name_snapshot'] ?? ''),
            'coupon_discount_cents' => (int)($line['coupon_discount_cents'] ?? 0),
            'price_change_reason' => (string)($line['price_change_reason'] ?? ''),
            'price_changed_by' => (int)($line['price_changed_by'] ?? 0),
            'price_changed_by_name_snapshot' => (string)($line['price_changed_by_name_snapshot'] ?? ''),
            'price_changed_at' => (int)($line['price_changed_at'] ?? 0),
            'authority_fingerprint' => (string)($line['authority_fingerprint'] ?? ''),
            'authority_snapshot_json' => (string)($line['authority_snapshot_json'] ?? ''),
            'service_object' => (string)($line['service_object'] ?? ''),
            'craftsmen_json' => $craftsmenJson,
            'salespeople_json' => $salespeopleJson,
            'guide_selections_json' => $guideSelectionsJson,
            'sales_manager_selections_json' => $salesManagerSelectionsJson,
            'manual_labor_fee_cents' => isset($line['manual_labor_fee_cents']) ? (int)$line['manual_labor_fee_cents'] : null,
            'friend_counts_as_customer' => (int)($line['friend_counts_as_customer'] ?? 1),
            'is_experience' => (int)($line['is_experience'] ?? 0),
            'is_presale' => (int)($line['is_presale'] ?? 0),
            'inventory_outbound_required' => (int)($line['inventory_outbound_required'] ?? 1),
            'display_snapshot_json' => $displayJson,
            'sort_no' => $sortNo,
        ];
        if ($role === self::ROLE_SALE) {
            $productType = (int)$record['catalog_product_type'];
            $productId = (int)$record['catalog_product_id'];
            $skuId = (int)$record['catalog_sku_id'];
            if ($productId <= 0 || $skuId <= 0 || !in_array($productType, [0, 4, 5, 6], true)
                || ($productType === 6
                    ? (int)$record['project_id'] !== $productId
                    : (int)$record['project_id'] !== 0)
                || !preg_match('/^[a-f0-9]{64}$/D', (string)$record['authority_fingerprint'])
                || (string)$record['authority_snapshot_json'] === '') {
                throw $this->incompleteDraftLine($lineKey, 'cashier_hang_restore_sale_snapshot_invalid');
            }
        } elseif ((int)$record['holder_id'] <= 0
            || (int)$record['source_detail_id'] <= 0
            || (int)$record['project_id'] <= 0) {
            throw $this->incompleteDraftLine($lineKey, 'cashier_hang_restore_entitlement_snapshot_invalid');
        }
        return $record;
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
                // Existing editing drafts written before the R15 coupon snapshot
                // fields must remain readable. A later normal draft write upgrades
                // this fingerprint to the current canonical representation.
                && !hash_equals($storedFingerprint, $this->lineFingerprint($rows, true, false))
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
            // Legacy rows may still contain attribution JSON from before the
            // entitlement boundary was enforced. Never expose or reuse it:
            // only sale rows have guide/sales-manager attribution.
            $guides = $lineRole === self::ROLE_SALE
                ? $this->decodeStoredGuideSelections($row, $lineKey)
                : [];
            $salesManagers = $lineRole === self::ROLE_SALE
                ? $this->decodeStoredSalesManagerSelections($row, $lineKey)
                : [];
            $manualLaborFeeCents = ($row['manual_labor_fee_cents'] ?? null) === null
                ? null
                : $this->storedNonnegativeInteger($row['manual_labor_fee_cents'], $lineKey, 'manual_labor_fee_cents');
            // 项目固定手工费属于当前租户的项目配置。工作台行同时保留
            // catalog_product_id 作为旧快照兜底，避免历史行只保存产品 ID
            // 时把固定手工费误读成 0。
            $projectIdForLabor = $this->laborProjectIdFromLine($row);
            $laborDefaultFeeCents = $this->projectLaborDefaultCents($projectIdForLabor);
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
                'friendCountsAsCustomer' => (int)($row['friend_counts_as_customer'] ?? 1) === 1,
                'craftsmen' => $craftsmen,
                'craftsmenSummary' => $this->craftsmenSummary($craftsmen),
                'isExperience' => $experienceFlag === 1,
                'isPresale' => (int)($row['is_presale'] ?? 0) === 1,
                'inventoryOutboundRequired' => (int)($row['inventory_outbound_required'] ?? 1) === 1,
                'guideSelections' => $guides,
                'salesManagerSelections' => $salesManagers,
                'laborDefaultFee' => $this->centsToMoney($laborDefaultFeeCents),
                'laborManualFee' => $manualLaborFeeCents === null ? null : $this->centsToMoney($manualLaborFeeCents),
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
                $couponUserId = $this->storedNonnegativeInteger(
                    $row['coupon_user_id'] ?? 0,
                    $lineKey,
                    'coupon_user_id'
                );
                $couponDiscountCents = $this->storedNonnegativeInteger(
                    $row['coupon_discount_cents'] ?? 0,
                    $lineKey,
                    'coupon_discount_cents'
                );
                $couponName = trim((string)($row['coupon_name_snapshot'] ?? ''));
                $priceChangeReason = trim((string)($row['price_change_reason'] ?? ''));
                $priceChangedBy = (int)($row['price_changed_by'] ?? 0);
                $priceChangedByName = trim((string)($row['price_changed_by_name_snapshot'] ?? ''));
                $priceChangedAt = (int)($row['price_changed_at'] ?? 0);
                $configuredPriceCents = (int)($authoritySnapshot['sku']['priceCents'] ?? -1);
                $authorityCostCents = (int)($authoritySnapshot['sku']['costCents'] ?? -1);
                $priceAuditMatches = $priceChangedAt === 0
                    ? ($unitPriceCents >= 0
                        && in_array($configuredCostCents, [0, $authorityCostCents], true)
                        && $priceChangeReason === '' && $priceChangedBy === 0 && $priceChangedByName === '')
                    : ($authorityCostCents >= 0
                        && $configuredCostCents === $authorityCostCents
                        && $unitPriceCents >= $configuredCostCents
                        && $priceChangeReason !== '' && $priceChangedBy > 0 && $priceChangedByName !== '');
                $priceSnapshotMatches = $cardOperationUpgrade === null
                    ? $priceAuditMatches
                    : (
                        (int)($cardOperationUpgrade['targetProductId'] ?? 0) === $productId
                        && (int)($cardOperationUpgrade['targetSkuId'] ?? 0) === $skuId
                        && (int)($cardOperationUpgrade['targetPriceCents'] ?? -1) === $originalUnitPriceCents
                        && $couponDiscountCents >= 0
                        && (int)($cardOperationUpgrade['settlementDeltaCents'] ?? -1)
                            === $unitPriceCents + $couponDiscountCents
                    );
                $displayOriginalUnitPriceCents = max($originalUnitPriceCents, $unitPriceCents);
                if (preg_match('/^sale:[a-f0-9]{48}$/D', $lineKey) !== 1
                    || $productId <= 0 || $skuId <= 0
                    || !in_array($productType, [0, 4, 5, 6], true)
                    || preg_match('/^[a-f0-9]{64}$/D', $authorityFingerprint) !== 1
                    || !hash_equals($authorityFingerprint, $authorityHash)
                    || (int)($authoritySnapshot['product']['id'] ?? 0) !== $productId
                    || (int)($authoritySnapshot['sku']['id'] ?? 0) !== $skuId
                    || (int)($authoritySnapshot['product']['productType'] ?? -1) !== $productType
                    || (int)($authoritySnapshot['productVersion'] ?? 0) !== $sourceVersion
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
                $originalAmountCents = $this->multiplyCents($displayOriginalUnitPriceCents, $quantity, $lineKey);
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
                    'originalUnitPriceCents' => $displayOriginalUnitPriceCents,
                    'configuredPriceCents' => $configuredPriceCents,
                    'configuredCostCents' => $configuredCostCents,
                    'priceChangeReason' => $priceChangeReason,
                    'priceChangedBy' => $priceChangedBy,
                    'priceChangedByNameSnapshot' => $priceChangedByName,
                    'priceChangedAt' => $priceChangedAt,
                    'lineAmountCents' => $lineAmountCents,
                    'debtAmountCents' => $debtAmountCents,
                    'couponUserId' => $couponUserId,
                    'couponName' => $couponName,
                    'couponDiscountAmountCents' => $couponDiscountCents,
                    'couponSummary' => $couponUserId > 0 ? $couponName . '：-' . $this->centsToMoney($couponDiscountCents) : '',
                    'originalLineAmountCents' => $originalAmountCents,
                    'unitPrice' => $this->centsToMoney($unitPriceCents),
                    'originalUnitPrice' => $this->centsToMoney($displayOriginalUnitPriceCents),
                    'finalAmount' => $this->centsToMoney($lineAmountCents),
                    'amount' => $this->centsToMoney($lineAmountCents),
                    'originalAmount' => $this->centsToMoney($originalAmountCents),
                    'amountRole' => 'sale_receivable',
                    'definitionFingerprint' => $authorityFingerprint,
                    'serviceObject' => $serviceObject,
                ]);
                if ($cardOperationUpgrade !== null) {
                    // Deliberately expose only the immutable upgrade binding,
                    // never the full server authority snapshot.
                    $line['cardOperationUpgrade'] = $cardOperationUpgrade;
                }
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
                if ($allocationStart < $consumedTimes) {
                    throw $this->incompleteLineSettings(
                        (string)$row['line_key'],
                        'actual_amount_allocation_start_invalid'
                    );
                }

                // 购物车是草稿：同一权益可被继续加入，即使暂存数量已经超过
                // 打开“使用权益”时的展示快照。此处只计算快照可覆盖部分的
                // 展示金额；剩余部分显示为 0，绝不在加购阶段阻断。最终结账
                // 事务会重新读取权威权益事实并统一校验可用次数。
                $remainingAtSnapshot = max(0, $totalPurchaseTimes - $allocationStart);
                $allocatableQuantity = min($quantity, $remainingAtSnapshot);
                if ($allocatableQuantity === 0) {
                    $line['actualAmount'] = '0.00';
                } else {
                    try {
                        $line['actualAmount'] = CashierV3EntitlementActualAmountAllocator::allocateForSnapshot(
                            $purchaseAmount,
                            $totalPurchaseTimes,
                            $allocationStart,
                            $allocatableQuantity,
                            is_array($snapshot) ? $snapshot : []
                        );
                    } catch (\InvalidArgumentException $exception) {
                        throw $this->incompleteLineSettings((string)$row['line_key'], 'actual_amount_allocation_failed');
                    }
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

    private function lineFingerprint(
        array $rows,
        bool $includeLineFinancials = true,
        bool $includeCouponFields = true
    ): string
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
                'guide_selections_json' => (string)($row['guide_selections_json'] ?? ''),
                'sales_manager_selections_json' => (string)($row['sales_manager_selections_json'] ?? ''),
                'friend_counts_as_customer' => (int)($row['friend_counts_as_customer'] ?? 1),
                'is_experience' => (int)($row['is_experience'] ?? 0),
                'is_presale' => (int)($row['is_presale'] ?? 0),
                'display_snapshot_json' => (string)($row['display_snapshot_json'] ?? ''),
                'sort_no' => (int)($row['sort_no'] ?? 0),
            ];
            if (($row['manual_labor_fee_cents'] ?? null) !== null) {
                $item['manual_labor_fee_cents'] = (int)$row['manual_labor_fee_cents'];
            }
            if ((int)($row['inventory_outbound_required'] ?? 1) !== 1) {
                $item['inventory_outbound_required'] = 0;
            }
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
                    if ($includeCouponFields) {
                        $item['coupon_user_id'] = (int)($row['coupon_user_id'] ?? 0);
                        $item['coupon_name_snapshot'] = (string)($row['coupon_name_snapshot'] ?? '');
                        $item['coupon_discount_cents'] = (int)($row['coupon_discount_cents'] ?? 0);
                    }
                }
                $item['authority_fingerprint'] = (string)($row['authority_fingerprint'] ?? '');
                $item['authority_snapshot_json'] = (string)($row['authority_snapshot_json'] ?? '');
            }
            $canonical[] = $item;
        }
        return hash('sha256', $this->encodeJson($canonical));
    }

    /** @return array{threshold:int,base:int,cap:int} */
    private function couponLineAmounts(array $line): array
    {
        $quantity = max(1, (int)($line['quantity'] ?? 1));
        $current = $this->multiplyCents((int)($line['unit_price_cents'] ?? 0), $quantity, (string)($line['line_key'] ?? ''));
        $oldDiscount = max(0, (int)($line['coupon_discount_cents'] ?? 0));
        $base = $current + $oldDiscount;
        $snapshot = json_decode((string)($line['authority_snapshot_json'] ?? ''), true);
        $upgrade = is_array($snapshot) && is_array($snapshot['cardOperationUpgrade'] ?? null)
            ? $snapshot['cardOperationUpgrade'] : null;
        $threshold = $upgrade === null ? $base : (int)($upgrade['targetPriceCents'] ?? 0);
        if ($threshold <= 0 || $base < 0 || $base > $threshold) {
            throw $this->incompleteDraftLine((string)($line['line_key'] ?? ''), 'coupon_line_amount_invalid');
        }
        return ['threshold' => $threshold, 'base' => $base, 'cap' => $base];
    }

    private function availableCoupons(
        int $memberId,
        int $storeId,
        array $amounts,
        bool $lock,
        string $workspaceId,
        string $lineKey,
        int $onlyCouponId = 0,
        array $reservedCouponIds = []
    ): array {
        $now = time();
        $query = Db::name('store_coupon_user')->alias('cu')
            ->leftJoin('store_coupon_issue ci', 'ci.id=cu.cid')
            ->where('cu.uid', $memberId)->where('cu.status', 0)->where('cu.is_fail', 0)->where('cu.use_time', 0)
            ->where(function ($q) use ($now) { $q->where('cu.start_time', 0)->whereOr('cu.start_time', '<=', $now); })
            ->where(function ($q) use ($now) { $q->where('cu.end_time', 0)->whereOr('cu.end_time', '>=', $now); })
            ->field('cu.id,cu.coupon_title,cu.coupon_price,cu.use_min_price,cu.end_time,cu.cid,ci.coupon_type,ci.top_discount_price,ci.coupon_issue_type,ci.relation_id,ci.applicable_type,ci.applicable_store_id');
        if ($onlyCouponId > 0) $query->where('cu.id', $onlyCouponId);
        if ($lock) $query->lock(true);
        $rows = $query->order('cu.end_time asc,cu.id asc')->select();
        $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
        $usedIds = Db::name(self::LINE_TABLE)->where('workspace_id', $workspaceId)
            ->where('line_key', '<>', $lineKey)->where('coupon_user_id', '>', 0)->column('coupon_user_id');
        $used = array_fill_keys(array_merge(array_map('intval', $usedIds), array_map('intval', $reservedCouponIds)), true);
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $minimum = $this->decimalMoneyToCents($row['use_min_price'] ?? null, 'coupon_use_min_price_invalid');
            if ($id <= 0 || isset($used[$id]) || $minimum > $amounts['threshold']) continue;
            if (!$this->couponAllowsStore($row, $storeId)) continue;
            $face = $this->decimalMoneyToCents($row['coupon_price'] ?? null, 'coupon_price_invalid');
            if ((int)($row['coupon_type'] ?? 1) === 2) {
                $rate = $this->decimalPercentHundredths($row['coupon_price'] ?? null);
                $face = intdiv($amounts['threshold'] * (10000 - $rate), 10000);
                $top = $this->decimalMoneyToCents($row['top_discount_price'] ?? 0, 'coupon_top_discount_invalid');
                if ($top > 0) $face = min($face, $top);
            }
            $out[] = ['couponId' => $id, 'name' => trim((string)$row['coupon_title']) ?: '优惠券',
                'discountAmountCents' => min($face, $amounts['cap']),
                'useMinAmountCents' => $minimum,
                'expiresAt' => (int)($row['end_time'] ?? 0)];
        }
        return $out;
    }

    private function couponAllowsStore(array $row, int $storeId): bool
    {
        if ((int)($row['cid'] ?? 0) <= 0) return true;
        if ((int)($row['coupon_issue_type'] ?? 0) === 1 && (int)($row['relation_id'] ?? 0) !== $storeId) return false;
        $type = (int)($row['applicable_type'] ?? 1);
        if ($type === 0) return false;
        if ($type !== 2) return true;
        $raw = trim((string)($row['applicable_store_id'] ?? ''));
        $decoded = json_decode($raw, true);
        $ids = is_array($decoded)
            ? $decoded
            : preg_split('/\s*,\s*/', trim($raw, "[] \t\n\r\0\x0B"));
        return in_array($storeId, array_map('intval', $ids ?: []), true);
    }

    private function decimalMoneyToCents($value, string $reason): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2})(?:0{0,4})?)?$/D', $raw, $matches) !== 1) {
            throw $this->incompleteDraft($reason);
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        return ((int)$matches[1] * 100) + (int)$fraction;
    }

    private function decimalPercentHundredths($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]?|100)(?:\.([0-9]{1,2})(?:0{0,4})?)?$/D', $raw, $matches) !== 1) {
            throw $this->incompleteDraft('coupon_discount_rate_invalid');
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        $rate = ((int)$matches[1] * 100) + (int)$fraction;
        if ($rate > 10000) {
            throw $this->incompleteDraft('coupon_discount_rate_invalid');
        }
        return $rate;
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

    private function projectLaborDefaultCents(int $projectId): int
    {
        if ($projectId <= 0) return 0;
        $ruleQuery = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('project_id', $projectId)
            ->order('id', 'desc');
        $rule = $ruleQuery->find();
        if (is_array($rule) && array_key_exists('labor_configured_unit_amount_cents', $rule)) {
            return max(0, (int)$rule['labor_configured_unit_amount_cents']);
        }
        // 门店商品是平台项目的复制行（type=1，pid=平台项目ID）。项目业绩规则
        // 只归属于平台主项目，不能按门店复制行 ID 查，否则固定手工费会变成 0。
        $masterId = (int)(Db::name('store_product')
            ->where('id', $projectId)
            ->where('type', 1)
            ->where('product_type', 6)
            ->value('pid') ?: 0);
        if ($masterId <= 0 || $masterId === $projectId) return 0;
        $masterRule = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('project_id', $masterId)
            ->order('id', 'desc')
            ->find();
        return is_array($masterRule) && array_key_exists('labor_configured_unit_amount_cents', $masterRule)
            ? max(0, (int)$masterRule['labor_configured_unit_amount_cents'])
            : 0;
    }

    private function laborProjectIdFromLine(array $line): int
    {
        $projectId = (int)($line['project_id'] ?? $line['projectId'] ?? 0);
        // 项目销售行的权威项目就是目录商品本身。优先使用 catalog_product_id，
        // 兼容旧购物车快照 project_id 缺失或被旧流程写成 0 的情况。
        if ((int)($line['catalog_product_type'] ?? -1) === 6
            && (int)($line['catalog_product_id'] ?? 0) > 0) {
            return (int)$line['catalog_product_id'];
        }
        return $projectId;
    }

    private function manualLaborFeeCents($value, string $lineKey): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $raw = is_numeric($value) ? (string)$value : trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw $this->incompleteLineSettings($lineKey, 'manual_labor_fee_invalid');
        }
        $cents = (int)$raw * 100;
        if ($cents < 0 || $cents > 100000000000) {
            throw $this->incompleteLineSettings($lineKey, 'manual_labor_fee_out_of_range');
        }
        return $cents;
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
