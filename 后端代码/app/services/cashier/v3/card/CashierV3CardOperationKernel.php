<?php

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;

/**
 * Pure card-operation planner. Persistence, Gateway locking and checkout
 * settlement remain outside this class so a preview can never mutate rights.
 */
final class CashierV3CardOperationKernel
{
    public const CONTRACT_VERSION = 'cashier-v3-card-operation-v1';
    public const ACTION = 'submit-card-operation';

    public const TYPE_CARD_UPGRADE = 'card_upgrade';
    public const TYPE_CARD_EXTENSION = 'card_extension';
    public const TYPE_CARD_TRANSFER = 'card_transfer';
    public const TYPE_CARD_DISABLE = 'card_disable';
    public const TYPE_CARD_ENABLE = 'card_enable';
    public const TYPE_PROJECT_REPLACEMENT = 'project_replacement';
    public const TYPE_PROJECT_UPGRADE = 'project_upgrade';

    private const TYPES = [
        self::TYPE_CARD_UPGRADE,
        self::TYPE_CARD_EXTENSION,
        self::TYPE_CARD_TRANSFER,
        self::TYPE_CARD_DISABLE,
        self::TYPE_CARD_ENABLE,
        self::TYPE_PROJECT_REPLACEMENT,
        self::TYPE_PROJECT_UPGRADE,
    ];

    private const DIRECT_TYPES = [
        self::TYPE_CARD_EXTENSION,
        self::TYPE_CARD_TRANSFER,
        self::TYPE_CARD_DISABLE,
        self::TYPE_CARD_ENABLE,
        self::TYPE_PROJECT_REPLACEMENT,
    ];

    /**
     * @param array $intent Untrusted operator intent after the Gateway envelope.
     * @param array $source Locked source card and current state.
     * @param array $target Locked target member/catalog/project data.
     * @param array $context Trusted scope and time data.
     */
    public static function plan(array $intent, array $source, array $target, array $context): array
    {
        $intent = self::normalizeIntent($intent);
        $source = self::normalizeSource($source);
        $target = self::normalizeTarget($target);
        $context = self::normalizeContext($context);

        if ((int)$intent['sourceCardHolderId'] !== (int)$source['holderId']
            || (int)$intent['sourceCardHolderVersion'] !== (int)$source['holderVersion']) {
            throw self::conflict('source_card_version_changed');
        }

        $type = $intent['operationType'];
        $mutation = [
            'currentMemberId' => $source['currentMemberId'],
            'cardStatus' => $source['cardStatus'],
            'effectiveWriteEnd' => $source['effectiveWriteEnd'],
            'projectMutations' => [],
        ];
        $lines = [];
        $targetCatalogId = 0;
        $targetCatalogName = '';
        $targetPriceCents = 0;
        $sourceRemainingValueCents = 0;
        $settlementDeltaCents = 0;
        $requiresCheckout = false;

        if ($type === self::TYPE_CARD_TRANSFER) {
            self::assertCardUsableForMutation($source, 'card_transfer');
            $memberId = self::positiveInt($target['memberId'] ?? null, 'target_member_invalid');
            if ($memberId === $source['currentMemberId']) {
                throw self::invalid('target_member_same_as_source');
            }
            $mutation['currentMemberId'] = $memberId;
        } elseif ($type === self::TYPE_CARD_EXTENSION) {
            $newEnd = self::positiveInt($intent['newWriteEnd'] ?? null, 'extension_end_invalid');
            if ($newEnd <= $context['occurredAt']) {
                throw self::invalid('extension_end_not_future');
            }
            if ($newEnd <= $source['effectiveWriteEnd']) {
                throw self::invalid('extension_end_not_extended');
            }
            $mutation['effectiveWriteEnd'] = $newEnd;
        } elseif ($type === self::TYPE_CARD_DISABLE) {
            if ($source['cardStatus'] !== 'enabled') {
                throw self::invalid('card_already_disabled');
            }
            $mutation['cardStatus'] = 'disabled';
        } elseif ($type === self::TYPE_CARD_ENABLE) {
            if ($source['cardStatus'] !== 'disabled') {
                throw self::invalid('card_not_disabled');
            }
            if ($source['effectiveWriteEnd'] > 0 && $source['effectiveWriteEnd'] < $context['occurredAt']) {
                throw self::invalid('card_expired_cannot_enable');
            }
            $mutation['cardStatus'] = 'enabled';
        } elseif ($type === self::TYPE_PROJECT_REPLACEMENT) {
            self::assertCardUsableForMutation($source, 'project_replacement');
            $projectPlan = self::projectPlan($intent, $source, $target, false);
            $mutation['projectMutations'] = $projectPlan['mutations'];
            $lines = $projectPlan['lines'];
            $targetCatalogId = $projectPlan['targetCatalogId'];
            $targetCatalogName = $projectPlan['targetCatalogName'];
            $sourceRemainingValueCents = $projectPlan['sourceValueCents'];
        } elseif ($type === self::TYPE_CARD_UPGRADE || $type === self::TYPE_PROJECT_UPGRADE) {
            self::assertCardUsableForMutation($source, $type);
            if ($type === self::TYPE_PROJECT_UPGRADE) {
                $projectPlan = self::projectPlan($intent, $source, $target, true);
                $mutation['projectMutations'] = $projectPlan['mutations'];
                $lines = $projectPlan['lines'];
                $targetCatalogId = $projectPlan['targetCatalogId'];
                $targetCatalogName = $projectPlan['targetCatalogName'];
                $sourceRemainingValueCents = $projectPlan['sourceValueCents'];
                $targetPriceCents = $projectPlan['targetPriceCents'];
            } else {
                $targetCatalogId = self::positiveInt($target['catalogId'] ?? null, 'target_card_missing');
                $targetCatalogName = self::requiredText($target['catalogName'] ?? null, 128, 'target_card_name_missing');
                $targetPriceCents = self::nonnegativeInt($target['priceCents'] ?? null, 'target_card_price_invalid');
                $sourceRemainingValueCents = self::nonnegativeInt(
                    $source['remainingValueCents'] ?? null,
                    'source_card_value_invalid'
                );
            }
            $settlementDeltaCents = $targetPriceCents - $sourceRemainingValueCents;
            if ($settlementDeltaCents < 0) {
                throw self::invalid('upgrade_negative_delta_not_supported');
            }
            // 即使差额为零，升级仍须生成一笔正式销售／权益变更事务：
            // 目标卡（或项目）必须有可追溯的成交来源，原权益也只能在同
            // 一次成功结账中失效。不能把“无需补款”误当成可直接改权益。
            $requiresCheckout = true;
        } else {
            throw self::invalid('operation_type_invalid');
        }

        $isDirect = in_array($type, self::DIRECT_TYPES, true);
        $status = $isDirect ? 'succeeded' : 'awaiting_checkout';
        // Upgrade state and rights must stay untouched until checkout success.
        // The project mutation plan itself is immutable audit input for that
        // later transaction, so it must not be discarded merely because it is
        // not yet applied.

        $identityMaterial = implode("\0", [
            $context['tenantId'],
            $intent['commandIdempotencyKey'],
            $type,
            (string)$source['holderId'],
            (string)$source['holderVersion'],
        ]);
        $operationId = 'COP-' . strtoupper(substr(hash('sha256', $identityMaterial), 0, 40));
        $operationNo = trim((string)($intent['businessDocumentNo'] ?? ''))
            ?: ('CO' . gmdate('YmdHis', $context['occurredAt']) . strtoupper(substr(hash('sha256', $identityMaterial), 0, 8)));
        $naturalKey = hash('sha256', implode("\0", [
            $context['tenantId'], $type, (string)$source['holderId'], (string)$source['holderVersion'],
            $intent['commandIdempotencyKey'],
        ]));

        $sourceSnapshot = self::sourceSnapshot($source);
        $targetSnapshot = self::targetSnapshot($target, $targetCatalogId, $targetCatalogName, $targetPriceCents);
        $resultSnapshot = [
            'operationStatus' => $status,
            'requiresCheckout' => $requiresCheckout,
            'settlementDeltaCents' => $settlementDeltaCents,
            'stateMutation' => $mutation,
        ];
        $fingerprint = self::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'operationId' => $operationId,
            'operationType' => $type,
            'source' => $sourceSnapshot,
            'target' => $targetSnapshot,
            'reason' => $intent['reason'],
            'lines' => $lines,
            'result' => $resultSnapshot,
            'businessDate' => $context['businessDate'],
        ]);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'action' => self::ACTION,
            'operationId' => $operationId,
            'operationNo' => $operationNo,
            'operationType' => $type,
            'operationStatus' => $status,
            'requiresCheckout' => $requiresCheckout,
            'checkoutSettlement' => [
                'targetPriceCents' => $targetPriceCents,
                'sourceRemainingValueCents' => $sourceRemainingValueCents,
                'settlementDeltaCents' => $settlementDeltaCents,
            ],
            'sourceCard' => $sourceSnapshot,
            'target' => $targetSnapshot,
            'stateMutation' => $mutation,
            'lines' => $lines,
            // This is persisted verbatim in the immutable operation audit.
            // Keep the result decision alongside the request/source snapshots
            // so future readers never need to reconstruct it from live state.
            'resultSnapshot' => $resultSnapshot,
            'reasonSnapshot' => $intent['reason'],
            'commandIdempotencyKey' => $intent['commandIdempotencyKey'],
            'naturalKey' => $naturalKey,
            'immutableFingerprint' => $fingerprint,
            'businessDate' => $context['businessDate'],
            'businessTimezone' => $context['businessTimezone'],
            'occurredAt' => $context['occurredAt'],
            'settledAt' => $isDirect ? $context['occurredAt'] : 0,
            'recordedAt' => $context['recordedAt'],
        ];
    }

    private static function projectPlan(array $intent, array $source, array $target, bool $isUpgrade): array
    {
        $targetCatalogId = self::positiveInt($target['catalogId'] ?? null, 'target_project_missing');
        $targetCatalogName = self::requiredText($target['catalogName'] ?? null, 128, 'target_project_name_missing');
        $targetPriceCents = $isUpgrade
            ? self::nonnegativeInt($target['priceCents'] ?? null, 'target_project_price_invalid')
            : 0;
        $selected = is_array($intent['projectLines'] ?? null) ? $intent['projectLines'] : [];
        if (!$selected || count($selected) > 20) {
            throw self::invalid('project_source_lines_invalid');
        }
        $sourceByDetail = [];
        foreach ((array)$source['projects'] as $project) {
            if (!is_array($project)) {
                continue;
            }
            $detailId = self::positiveInt($project['detailId'] ?? null, 'source_project_detail_invalid');
            $sourceByDetail[$detailId] = [
                'detailId' => $detailId,
                'detailVersion' => self::positiveInt($project['detailVersion'] ?? null, 'source_project_version_invalid'),
                'projectId' => self::positiveInt($project['projectId'] ?? null, 'source_project_invalid'),
                'remainingTimes' => self::nonnegativeInt($project['remainingTimes'] ?? null, 'source_project_times_invalid'),
                'remainingValueCents' => self::nonnegativeInt($project['remainingValueCents'] ?? null, 'source_project_value_invalid'),
                'totalTimes' => self::positiveInt($project['totalTimes'] ?? $project['remainingTimes'] ?? null, 'source_project_total_times_invalid'),
                'totalValueCents' => self::nonnegativeInt($project['totalValueCents'] ?? $project['remainingValueCents'] ?? null, 'source_project_total_value_invalid'),
            ];
        }
        $selectedByDetail = [];
        foreach ($selected as $selectedLine) {
            if (!is_array($selectedLine)) {
                throw self::invalid('project_source_line_invalid');
            }
            $detailId = self::positiveInt($selectedLine['sourceDetailId'] ?? null, 'project_source_detail_missing');
            $quantity = self::positiveInt($selectedLine['quantity'] ?? null, 'project_source_quantity_invalid');
            $selectedByDetail[$detailId] = (int)($selectedByDetail[$detailId] ?? 0) + $quantity;
        }
        $lines = [];
        $mutations = [];
        $sourceValue = 0;
        $lineNo = 0;
        foreach ($selectedByDetail as $detailId => $quantity) {
            $project = $sourceByDetail[$detailId] ?? null;
            if ($project === null || $quantity > $project['remainingTimes']) {
                throw self::conflict('project_source_changed');
            }
            // Allocate from the original right price and original configured
            // times, exactly as the transactional writer does. Allocating
            // twice from a rounded remaining value would otherwise make the
            // immutable plan disagree with the concrete target right.
            $lineValue = self::allocateValue($project['totalValueCents'], $project['totalTimes'], $quantity);
            $sourceValue += $lineValue;
            $mutations[] = [
                'sourceDetailId' => $detailId,
                'sourceDetailVersion' => $project['detailVersion'],
                'projectId' => $project['projectId'],
                'quantityBefore' => $project['remainingTimes'],
                'quantityDelta' => -$quantity,
                'quantityAfter' => $project['remainingTimes'] - $quantity,
            ];
            $lines[] = [
                'lineNo' => ++$lineNo,
                'lineRole' => 'source_project',
                'sourceDetailId' => $detailId,
                'sourceDetailVersion' => $project['detailVersion'],
                'sourceProjectId' => $project['projectId'],
                'targetCatalogId' => $targetCatalogId,
                'quantityBefore' => $project['remainingTimes'],
                'quantityDelta' => -$quantity,
                'quantityAfter' => $project['remainingTimes'] - $quantity,
                'amountCents' => $lineValue,
            ];
        }
        // A replacement may consume several source rights, but it always
        // creates one target right. The target row remains a new current-right
        // record on the same original card sale; no sales fact or historic
        // order amount is rewritten.
        if (!$isUpgrade) {
            $lines[] = [
                'lineNo' => ++$lineNo,
                'lineRole' => 'target_project',
                'sourceDetailId' => 0,
                'sourceDetailVersion' => 0,
                'sourceProjectId' => 0,
                'targetCatalogId' => $targetCatalogId,
                'quantityBefore' => 0,
                'quantityDelta' => 1,
                'quantityAfter' => 1,
                'amountCents' => $sourceValue,
            ];
        }
        return [
            'targetCatalogId' => $targetCatalogId,
            'targetCatalogName' => $targetCatalogName,
            'targetPriceCents' => $targetPriceCents,
            'sourceValueCents' => $sourceValue,
            'mutations' => $mutations,
            'lines' => $lines,
        ];
    }

    private static function allocateValue(int $remainingValue, int $remainingTimes, int $quantity): int
    {
        if ($remainingTimes <= 0 || $quantity > $remainingTimes) {
            throw self::invalid('project_value_allocation_invalid');
        }
        // Integer half-up; the authority writer applies the same allocation to
        // the concrete source detail under its final row lock.
        return (int)floor(($remainingValue * $quantity * 2 + $remainingTimes) / ($remainingTimes * 2));
    }

    private static function normalizeIntent(array $intent): array
    {
        $type = trim((string)($intent['operationType'] ?? $intent['operation_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw self::invalid('operation_type_invalid');
        }
        $key = trim((string)($intent['commandIdempotencyKey'] ?? $intent['idempotencyKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{12,128}$/', $key)) {
            throw self::invalid('operation_idempotency_key_invalid');
        }
        $reason = trim((string)($intent['reason'] ?? ''));
        $reasonRequired = in_array($type, [
            self::TYPE_CARD_EXTENSION,
            self::TYPE_CARD_TRANSFER,
            self::TYPE_CARD_DISABLE,
            self::TYPE_CARD_ENABLE,
        ], true);
        if ($reasonRequired) {
            $reason = self::requiredText($reason, 500, 'operation_reason_required');
        } elseif (mb_strlen($reason) > 500) {
            throw self::invalid('operation_reason_invalid');
        }
        return [
            'operationType' => $type,
            'sourceCardHolderId' => self::positiveInt($intent['sourceCardHolderId'] ?? null, 'source_card_missing'),
            'sourceCardHolderVersion' => self::positiveInt($intent['sourceCardHolderVersion'] ?? null, 'source_card_version_missing'),
            'commandIdempotencyKey' => $key,
            'reason' => $reason,
            'newWriteEnd' => $intent['newWriteEnd'] ?? null,
            'projectLines' => $intent['projectLines'] ?? [],
            'businessDocumentNo' => trim((string)($intent['businessDocumentNo'] ?? '')),
        ];
    }

    private static function normalizeSource(array $source): array
    {
        $status = trim((string)($source['cardStatus'] ?? 'enabled'));
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw self::invalid('source_card_status_invalid');
        }
        return [
            'holderId' => self::positiveInt($source['holderId'] ?? null, 'source_card_missing'),
            'holderVersion' => self::positiveInt($source['holderVersion'] ?? null, 'source_card_version_invalid'),
            'originOrderId' => self::positiveInt($source['originOrderId'] ?? null, 'source_origin_order_invalid'),
            'originMemberId' => self::positiveInt($source['originMemberId'] ?? null, 'source_origin_member_invalid'),
            'currentMemberId' => self::positiveInt($source['currentMemberId'] ?? null, 'source_current_member_invalid'),
            'cardStatus' => $status,
            'cardName' => self::requiredText($source['cardName'] ?? null, 128, 'source_card_name_missing'),
            'cardNo' => trim((string)($source['cardNo'] ?? '')),
            'effectiveWriteStart' => self::nonnegativeInt($source['effectiveWriteStart'] ?? 0, 'source_write_start_invalid'),
            'effectiveWriteEnd' => self::nonnegativeInt($source['effectiveWriteEnd'] ?? 0, 'source_write_end_invalid'),
            'remainingValueCents' => self::nonnegativeInt($source['remainingValueCents'] ?? 0, 'source_remaining_value_invalid'),
            'projects' => is_array($source['projects'] ?? null) ? $source['projects'] : [],
        ];
    }

    private static function normalizeTarget(array $target): array
    {
        return [
            'memberId' => $target['memberId'] ?? null,
            'memberName' => trim((string)($target['memberName'] ?? '')),
            'catalogId' => $target['catalogId'] ?? null,
            'skuId' => $target['skuId'] ?? null,
            'skuUnique' => trim((string)($target['skuUnique'] ?? '')),
            'catalogName' => $target['catalogName'] ?? null,
            'priceCents' => $target['priceCents'] ?? null,
        ];
    }

    private static function normalizeContext(array $context): array
    {
        $businessDate = trim((string)($context['businessDate'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            throw self::invalid('operation_business_date_invalid');
        }
        $occurredAt = self::positiveInt($context['occurredAt'] ?? null, 'operation_occurred_at_invalid');
        $recordedAt = self::positiveInt($context['recordedAt'] ?? null, 'operation_recorded_at_invalid');
        if ($recordedAt < $occurredAt) {
            throw self::invalid('operation_time_order_invalid');
        }
        return [
            'tenantId' => self::requiredAscii($context['tenantId'] ?? null, 32, 'operation_tenant_invalid'),
            'organizationId' => self::requiredAscii($context['organizationId'] ?? null, 32, 'operation_organization_invalid'),
            'storeId' => self::positiveInt($context['storeId'] ?? null, 'operation_store_invalid'),
            'operatorId' => self::positiveInt($context['operatorId'] ?? null, 'operation_operator_invalid'),
            'businessDate' => $businessDate,
            'businessTimezone' => trim((string)($context['businessTimezone'] ?? 'Asia/Shanghai')),
            'occurredAt' => $occurredAt,
            'recordedAt' => $recordedAt,
        ];
    }

    private static function assertCardUsableForMutation(array $source, string $operation): void
    {
        if ($source['cardStatus'] !== 'enabled') {
            throw self::invalid($operation . '_card_disabled');
        }
    }

    private static function sourceSnapshot(array $source): array
    {
        return [
            'holderId' => $source['holderId'],
            'holderVersion' => $source['holderVersion'],
            'originOrderId' => $source['originOrderId'],
            'originMemberId' => $source['originMemberId'],
            'currentMemberId' => $source['currentMemberId'],
            'cardStatus' => $source['cardStatus'],
            'cardName' => $source['cardName'],
            'cardNo' => $source['cardNo'],
            'effectiveWriteStart' => $source['effectiveWriteStart'],
            'effectiveWriteEnd' => $source['effectiveWriteEnd'],
            'remainingValueCents' => $source['remainingValueCents'],
        ];
    }

    private static function targetSnapshot(array $target, int $catalogId, string $catalogName, int $priceCents): array
    {
        return [
            'memberId' => $target['memberId'] === null ? 0 : (int)$target['memberId'],
            'memberName' => $target['memberName'],
            'catalogId' => $catalogId,
            'skuId' => $target['skuId'] === null ? 0 : (int)$target['skuId'],
            'skuUnique' => $target['skuUnique'],
            'catalogName' => $catalogName,
            'priceCents' => $priceCents,
        ];
    }

    private static function requiredText($value, int $maxLength, string $reason): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw self::invalid($reason);
        }
        return $value;
    }

    private static function requiredAscii($value, int $maxLength, string $reason): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,' . $maxLength . '}$/', $value)) {
            throw self::invalid($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
            throw self::invalid($reason);
        }
        return (int)$value;
    }

    private static function nonnegativeInt($value, string $reason): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
            throw self::invalid($reason);
        }
        return (int)$value;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(self::sortRecursive($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private static function invalid(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '卡操作资料不完整或当前不可办理，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function conflict(string $reason): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict(
            '卡或项目权益已经变化，请重新打开后再办理。',
            ['reason' => $reason]
        );
    }
}
