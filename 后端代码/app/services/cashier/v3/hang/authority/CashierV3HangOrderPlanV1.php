<?php

namespace app\services\cashier\v3\hang\authority;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/**
 * Immutable server-side plan made from a workspace draft that is already
 * locked by the caller. A hang order is not a sale, payment or service fact.
 */
final class CashierV3HangOrderPlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-order-authority-v1';
    public const RESUME_SALE_ONLY_CONTRACT_VERSION = 'cashier-v3-hang-resume-sale-only-v1';
    public const RESUME_SALE_PROJECT_CONTRACT_VERSION = 'cashier-v3-hang-resume-sale-project-v2';
    public const RESUME_DRAFT_CONTRACT_VERSION = 'cashier-v3-hang-resume-draft-v3';
    public const MODE_NORMAL = 'normal';
    public const MODE_START_SERVICE = 'start_service';
    public const STATUS_PENDING_CHECKOUT = 'pending_checkout';
    public const STATUS_SERVICE_IN_PROGRESS = 'service_in_progress';

    /** @var array */
    private $header;

    /** @var array<int,array> */
    private $lines;

    private function __construct(array $header, array $lines)
    {
        $this->header = $header;
        $this->lines = $lines;
    }

    /**
     * @param array $command server-authoritative snapshots plus the verified
     * command idempotency/preparation/room guard fields
     * @param array $lockedDraft CashierV3CashierWorkspaceServices public draft,
     * read while its header and complete line set are locked
     */
    public static function fromLockedDraft(
        array $command,
        array $lockedDraft,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $trustedWorkspaceRows = []
    ): self {
        self::assertScope($operatorScope, $dataScope);

        $workspaceId = self::token($lockedDraft['workspaceId'] ?? null, 64, 'hang_workspace_id_invalid');
        $stateContextId = self::token($lockedDraft['stateContextId'] ?? null, 64, 'hang_state_context_id_invalid');
        $expectedWorkspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
        if (!hash_equals($expectedWorkspaceId, $workspaceId)
            || (string)($lockedDraft['status'] ?? '') !== 'editing') {
            throw self::failure('hang_locked_workspace_invalid');
        }
        $memberId = self::nonNegativeInt($lockedDraft['memberId'] ?? null, 'hang_member_id_invalid');
        $customerMode = self::token($lockedDraft['customerMode'] ?? null, 16, 'hang_customer_mode_invalid');
        if (($customerMode === 'member' && $memberId <= 0)
            || ($customerMode === 'guest' && $memberId !== 0)
            || !in_array($customerMode, ['member', 'guest'], true)) {
            throw self::failure('hang_customer_binding_invalid');
        }
        $workspaceFingerprint = self::requireFingerprint(
            $lockedDraft['lineFingerprint'] ?? null,
            'hang_workspace_line_fingerprint_invalid'
        );
        $draftLines = $lockedDraft['lines'] ?? null;
        if (!is_array($draftLines) || count($draftLines) === 0 || count($draftLines) > 1000) {
            throw self::failure('hang_workspace_lines_invalid');
        }

        $commandKey = self::idempotencyKey($command['commandIdempotencyKey'] ?? null);
        $preparationRequestId = self::requestId(
            $command['preparationRequestId'] ?? null,
            'hang_preparation_request_id_invalid'
        );
        $preparationToken = self::requireFingerprint(
            $command['preparationToken'] ?? null,
            'hang_preparation_token_invalid'
        );
        $mode = self::token($command['mode'] ?? null, 24, 'hang_mode_invalid');
        if (!in_array($mode, [self::MODE_NORMAL, self::MODE_START_SERVICE], true)) {
            throw self::failure('hang_mode_invalid');
        }
        $recordedAt = self::positiveInt($command['recordedAt'] ?? null, 'hang_recorded_at_invalid');
        $occurredAt = self::positiveInt($command['occurredAt'] ?? null, 'hang_occurred_at_invalid');
        if ($recordedAt < $occurredAt) {
            throw self::failure('hang_times_invalid');
        }
        $businessDate = self::businessDate($command['businessDate'] ?? null);
        $businessTimezone = self::token(
            $command['businessTimezone'] ?? 'Asia/Shanghai',
            64,
            'hang_business_timezone_invalid'
        );

        $room = self::roomSnapshot($command, $mode);
        $identity = self::identity(
            $operatorScope->tenantId(),
            $preparationRequestId,
            $businessDate
        );
        $naturalKey = $identity['naturalKey'];
        $hangOrderId = $identity['hangOrderId'];
        $hangOrderNo = $identity['hangOrderNo'];

        $trustedByLine = self::trustedWorkspaceRowsByLine($trustedWorkspaceRows, $draftLines);
        $lines = [];
        $seen = [];
        $totalQuantity = 0;
        $saleAmountCents = 0;
        $entitlementAmountCents = 0;
        foreach (array_values($draftLines) as $index => $line) {
            if (!is_array($line)) {
                throw self::failure('hang_workspace_line_invalid', ['index' => $index]);
            }
            $normalized = self::line(
                $line,
                $index + 1,
                $hangOrderId,
                $preparationRequestId,
                $commandKey,
                $operatorScope,
                $memberId,
                $trustedByLine[(string)($line['id'] ?? '')] ?? []
            );
            if (isset($seen[$normalized['workspace_line_id']])) {
                throw self::failure('hang_workspace_line_duplicate', [
                    'workspaceLineId' => $normalized['workspace_line_id'],
                ]);
            }
            $seen[$normalized['workspace_line_id']] = true;
            $totalQuantity = self::safeAdd($totalQuantity, $normalized['quantity'], 'hang_quantity_overflow');
            $saleAmountCents = self::safeAdd(
                $saleAmountCents,
                $normalized['sale_amount_cents'],
                'hang_sale_amount_overflow'
            );
            $entitlementAmountCents = self::safeAdd(
                $entitlementAmountCents,
                $normalized['entitlement_actual_amount_cents'],
                'hang_entitlement_amount_overflow'
            );
            $lines[] = $normalized;
        }

        // A hang order is a frozen cashier draft.  Every authoritative cart
        // row that was accepted into this draft can be restored; current
        // catalog, inventory and entitlement conditions are deliberately
        // revalidated only by the later checkout command.
        $resumeEligible = in_array($mode, [self::MODE_NORMAL, self::MODE_START_SERVICE], true)
            && $lines !== [];
        foreach ($lines as $line) {
            if (!in_array((string)($line['line_role'] ?? ''), ['sale', 'entitlement_service'], true)
                || (string)($line['workspace_snapshot_json'] ?? '') === '') {
                $resumeEligible = false;
                break;
            }
            $snapshot = json_decode((string)$line['workspace_snapshot_json'], true);
            if (!is_array($snapshot)
                || (string)($snapshot['line_key'] ?? '') !== (string)($line['workspace_line_id'] ?? '')
                || (string)($snapshot['line_role'] ?? '') !== (string)($line['line_role'] ?? '')
                || (int)($snapshot['member_id'] ?? -1) !== $memberId) {
                $resumeEligible = false;
                break;
            }
        }

        $header = [
            'hang_order_id' => $hangOrderId,
            'hang_order_no' => $hangOrderNo,
            'natural_key' => $naturalKey,
            'contract_version' => self::CONTRACT_VERSION,
            'resume_contract_version' => $resumeEligible
                ? self::RESUME_DRAFT_CONTRACT_VERSION
                : '',
            'command_idempotency_key' => $commandKey,
            'tenant_id' => $operatorScope->tenantId(),
            'organization_id' => $operatorScope->organizationId(),
            'organization_path_snapshot' => self::text(
                $command['organizationPathSnapshot'] ?? '',
                191,
                'hang_organization_path_invalid',
                true
            ),
            'organization_name_snapshot' => self::text(
                $command['organizationNameSnapshot'] ?? '',
                128,
                'hang_organization_name_invalid',
                true
            ),
            'store_id' => $operatorScope->storeId(),
            'store_name_snapshot' => self::text(
                $command['storeNameSnapshot'] ?? null,
                128,
                'hang_store_name_invalid',
                false
            ),
            'member_id' => $memberId,
            'member_name_snapshot' => self::text(
                $command['memberNameSnapshot'] ?? '',
                128,
                'hang_member_name_invalid',
                $memberId === 0
            ),
            'operator_id' => $operatorScope->operatorId(),
            'operator_name_snapshot' => self::text(
                $command['operatorNameSnapshot'] ?? null,
                128,
                'hang_operator_name_invalid',
                false
            ),
            'workspace_id' => $workspaceId,
            'state_context_id' => $stateContextId,
            'workspace_line_fingerprint' => $workspaceFingerprint,
            'preparation_request_id' => $preparationRequestId,
            'preparation_token' => $preparationToken,
            'hang_mode' => $mode,
            'room_id' => $room['room_id'],
            'room_name_snapshot' => $room['room_name_snapshot'],
            'room_version' => $room['room_version'],
            'room_time_slot_id' => $room['room_time_slot_id'],
            'room_time_slot_version' => $room['room_time_slot_version'],
            'room_guard_fingerprint' => $room['room_guard_fingerprint'],
            'line_count' => count($lines),
            'total_quantity' => $totalQuantity,
            'sale_amount_cents' => $saleAmountCents,
            'entitlement_actual_amount_cents' => $entitlementAmountCents,
            'hang_status' => $mode === self::MODE_START_SERVICE
                ? self::STATUS_SERVICE_IN_PROGRESS
                : self::STATUS_PENDING_CHECKOUT,
            'hang_version' => 1,
            'business_date' => $businessDate,
            'business_timezone' => $businessTimezone,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ];
        $immutable = $header;
        unset($immutable['command_idempotency_key']);
        $immutable['line_fingerprints'] = array_column($lines, 'immutable_fingerprint');
        $header['immutable_fingerprint'] = hash('sha256', self::canonicalJson($immutable));

        return new self($header, $lines);
    }

    public function header(): array
    {
        return $this->header;
    }

    /** @return array<int,array> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function fingerprint(): string
    {
        return (string)$this->header['immutable_fingerprint'];
    }

    /** Stable owner identity required before the room guard can be claimed. */
    public static function identity(
        string $tenantId,
        string $preparationRequestId,
        string $businessDate
    ): array {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || strlen($tenantId) > 32 || strpos($tenantId, "\0") !== false) {
            throw self::failure('hang_tenant_id_invalid');
        }
        $preparationRequestId = self::requestId(
            $preparationRequestId,
            'hang_preparation_request_id_invalid'
        );
        $businessDate = self::businessDate($businessDate);
        $naturalKey = 'hang_preparation:' . $preparationRequestId;
        $identityHash = hash('sha256', $tenantId . "\0" . $naturalKey);
        return [
            'naturalKey' => $naturalKey,
            'hangOrderId' => 'HGO' . substr($identityHash, 0, 40),
            'hangOrderNo' => 'HG' . str_replace('-', '', $businessDate)
                . strtoupper(substr($identityHash, 0, 16)),
        ];
    }

    private static function line(
        array $line,
        int $lineNo,
        string $hangOrderId,
        string $preparationRequestId,
        string $commandKey,
        CashierV3OperatorScope $scope,
        int $memberId,
        array $trustedWorkspaceRow
    ): array {
        $workspaceLineId = self::token($line['id'] ?? null, 64, 'hang_workspace_line_id_invalid');
        $role = self::token($line['lineRole'] ?? null, 32, 'hang_line_role_invalid');
        if (!in_array($role, ['sale', 'entitlement_service'], true)
            || self::nonNegativeInt($line['memberId'] ?? null, 'hang_line_member_id_invalid') !== $memberId) {
            throw self::failure('hang_line_binding_invalid', ['workspaceLineId' => $workspaceLineId]);
        }
        $quantity = self::positiveInt($line['quantity'] ?? null, 'hang_line_quantity_invalid');
        if ($quantity > 1000000) {
            throw self::failure('hang_line_quantity_invalid');
        }

        if ($role === 'sale') {
            $sourceId = (string)self::positiveInt($line['productId'] ?? null, 'hang_sale_product_id_invalid');
            $sourceVersion = self::positiveInt($line['productVersion'] ?? null, 'hang_sale_product_version_invalid');
            $detailId = (string)self::positiveInt($line['skuId'] ?? null, 'hang_sale_sku_id_invalid');
            $detailVersion = self::positiveInt($line['skuVersion'] ?? null, 'hang_sale_sku_version_invalid');
            $projectId = self::nonNegativeInt($line['projectId'] ?? 0, 'hang_sale_project_id_invalid');
            $saleAmount = self::moneyCents($line['lineAmountCents'] ?? null, 'hang_sale_amount_invalid');
            $entitlementAmount = 0;
        } else {
            $sourceId = (string)self::positiveInt(
                $line['entitlementSourceDetailId'] ?? null,
                'hang_entitlement_detail_id_invalid'
            );
            $sourceVersion = self::positiveInt(
                $line['entitlementSourceVersion'] ?? null,
                'hang_entitlement_version_invalid'
            );
            $projectId = self::positiveInt($line['projectId'] ?? null, 'hang_project_id_invalid');
            $detailId = (string)$projectId;
            $detailVersion = self::positiveInt($line['projectVersion'] ?? null, 'hang_project_version_invalid');
            $saleAmount = 0;
            $entitlementAmount = self::decimalToCents(
                $line['actualAmount'] ?? null,
                'hang_entitlement_amount_invalid'
            );
        }

        $serviceObject = trim((string)($line['serviceObject'] ?? ''));
        $serviceObject = $serviceObject === '本人' ? 'self' : ($serviceObject === '朋友' ? 'friend' : $serviceObject);
        if (!in_array($serviceObject, ['', 'self', 'friend'], true)) {
            throw self::failure('hang_service_object_invalid');
        }
        $craftsmen = $line['craftsmen'] ?? [];
        if (!is_array($craftsmen)) {
            throw self::failure('hang_craftsmen_snapshot_invalid');
        }
        $isExperience = $line['isExperience'] ?? false;
        if (!is_bool($isExperience)) {
            throw self::failure('hang_experience_flag_invalid');
        }
        $lineSnapshot = self::canonicalJson($line);
        $craftsmenSnapshot = self::canonicalJson($craftsmen);
        $workspaceSnapshot = self::workspaceSnapshot(
            $trustedWorkspaceRow,
            $workspaceLineId,
            $role,
            $memberId
        );
        $preparationNaturalHash = hash('sha256', $preparationRequestId);
        $lineNaturalHash = hash('sha256', $workspaceLineId);
        $row = [
            'hang_order_line_id' => 'HGL' . substr(hash(
                'sha256',
                $scope->tenantId() . "\0" . $hangOrderId . "\0" . $workspaceLineId
            ), 0, 40),
            'natural_key' => 'hang_line:' . substr($preparationNaturalHash, 0, 32)
                . ':' . substr($lineNaturalHash, 0, 32),
            'command_idempotency_key' => $commandKey,
            'hang_order_id' => $hangOrderId,
            'tenant_id' => $scope->tenantId(),
            'store_id' => $scope->storeId(),
            'member_id' => $memberId,
            'workspace_line_id' => $workspaceLineId,
            'line_no' => $lineNo,
            'line_role' => $role,
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'detail_id' => $detailId,
            'detail_version' => $detailVersion,
            'project_id' => $projectId,
            'quantity' => $quantity,
            'sale_amount_cents' => $saleAmount,
            'entitlement_actual_amount_cents' => $entitlementAmount,
            'service_object' => $serviceObject,
            'is_experience' => $isExperience ? 1 : 0,
            'craftsmen_snapshot_json' => $craftsmenSnapshot,
            'line_snapshot_json' => $lineSnapshot,
            'workspace_snapshot_json' => $workspaceSnapshot,
            'line_status' => 'held',
            'line_version' => 1,
        ];
        $immutable = $row;
        unset($immutable['command_idempotency_key']);
        $row['immutable_fingerprint'] = hash('sha256', self::canonicalJson($immutable));
        return $row;
    }

    /**
     * The public line snapshot is for display only.  New resumable hangs also
     * keep the locked workspace sale row, including its authority snapshot.
     */
    private static function workspaceSnapshot(
        array $row,
        string $workspaceLineId,
        string $role,
        int $memberId
    ): string {
        if ($row === []) {
            return '';
        }
        $lineKey = self::token($row['line_key'] ?? null, 64, 'hang_restore_line_key_invalid');
        if (!hash_equals($workspaceLineId, $lineKey)
            || (string)($row['line_role'] ?? '') !== $role
            || self::nonNegativeInt($row['member_id'] ?? null, 'hang_restore_member_invalid') !== $memberId) {
            throw self::failure('hang_restore_workspace_line_mismatch', ['workspaceLineId' => $workspaceLineId]);
        }
        $fields = [
            'line_key', 'line_role', 'member_id', 'holder_id', 'source_detail_id', 'project_id',
            'catalog_product_id', 'catalog_sku_id', 'catalog_product_type', 'quantity',
            'source_version', 'detail_version', 'unit_price_cents', 'original_unit_price_cents',
            'authority_fingerprint', 'authority_snapshot_json', 'service_object', 'craftsmen_json',
            'salespeople_json', 'is_experience', 'display_snapshot_json', 'sort_no',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw self::failure('hang_restore_workspace_snapshot_field_missing', [
                    'workspaceLineId' => $workspaceLineId,
                    'field' => $field,
                ]);
            }
            $snapshot[$field] = $row[$field];
        }
        return self::canonicalJson($snapshot);
    }

    /** @return array<string,array> */
    private static function trustedWorkspaceRowsByLine(array $rows, array $draftLines): array
    {
        if ($rows === []) {
            return [];
        }
        if (count($rows) !== count($draftLines)) {
            throw self::failure('hang_restore_workspace_snapshot_count_invalid');
        }
        $expected = [];
        foreach ($draftLines as $line) {
            if (!is_array($line)) {
                throw self::failure('hang_workspace_line_invalid');
            }
            $expected[self::token($line['id'] ?? null, 64, 'hang_workspace_line_id_invalid')] = true;
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::failure('hang_restore_workspace_row_invalid');
            }
            $key = self::token($row['line_key'] ?? null, 64, 'hang_restore_line_key_invalid');
            if (!isset($expected[$key]) || isset($result[$key])) {
                throw self::failure('hang_restore_workspace_snapshot_identity_invalid');
            }
            $result[$key] = $row;
        }
        if (count($result) !== count($expected)) {
            throw self::failure('hang_restore_workspace_snapshot_identity_invalid');
        }
        return $result;
    }

    private static function roomSnapshot(array $command, string $mode): array
    {
        $hasRoom = isset($command['roomId']) && (int)$command['roomId'] > 0;
        if ($mode === self::MODE_START_SERVICE && !$hasRoom) {
            throw self::failure('hang_start_service_room_required');
        }
        if (!$hasRoom) {
            return [
                'room_id' => 0,
                'room_name_snapshot' => '',
                'room_version' => 0,
                'room_time_slot_id' => '',
                'room_time_slot_version' => 0,
                'room_guard_fingerprint' => '',
            ];
        }
        return [
            'room_id' => self::positiveInt($command['roomId'], 'hang_room_id_invalid'),
            'room_name_snapshot' => self::text(
                $command['roomNameSnapshot'] ?? null,
                128,
                'hang_room_name_invalid',
                false
            ),
            'room_version' => self::positiveInt($command['roomVersion'] ?? null, 'hang_room_version_invalid'),
            'room_time_slot_id' => self::token(
                $command['roomTimeSlotId'] ?? null,
                128,
                'hang_room_time_slot_id_invalid'
            ),
            'room_time_slot_version' => self::positiveInt(
                $command['roomTimeSlotVersion'] ?? null,
                'hang_room_time_slot_version_invalid'
            ),
            'room_guard_fingerprint' => self::requireFingerprint(
                $command['roomGuardFingerprint'] ?? null,
                'hang_room_guard_fingerprint_invalid'
            ),
        ];
    }

    private static function assertScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('hang_order_data_scope_denied');
        }
    }

    private static function idempotencyKey($value): string
    {
        $value = trim((string)$value);
        if (preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $value) !== 1) {
            throw self::failure('hang_command_idempotency_key_invalid');
        }
        return $value;
    }

    private static function requestId($value, string $reason): string
    {
        $value = trim((string)$value);
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function token($value, int $max, string $reason): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9:_.\/-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function requireFingerprint($value, string $reason): string
    {
        $value = trim((string)$value);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $max, string $reason, bool $allowEmpty): string
    {
        $value = trim((string)$value);
        if ((!$allowEmpty && $value === '') || strlen($value) > $max || strpos($value, "\0") !== false) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', trim($value)) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', trim($value)) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function moneyCents($value, string $reason): int
    {
        $value = self::nonNegativeInt($value, $reason);
        if ($value > 100000000000) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function decimalToCents($value, string $reason): int
    {
        $value = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $match) !== 1) {
            throw self::failure($reason);
        }
        $whole = (int)$match[1];
        $fraction = str_pad((string)($match[2] ?? ''), 2, '0');
        if ($whole > 1000000000) {
            throw self::failure($reason);
        }
        return $whole * 100 + (int)$fraction;
    }

    private static function businessDate($value): string
    {
        $value = trim((string)$value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw self::failure('hang_business_date_invalid');
        }
        return $value;
    }

    private static function safeAdd(int $left, int $right, string $reason): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw self::failure($reason);
        }
        return $left + $right;
    }

    private static function canonicalJson($value): string
    {
        $normalized = self::canonicalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || json_last_error() !== JSON_ERROR_NONE) {
            throw self::failure('hang_snapshot_json_invalid');
        }
        return $json;
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw self::failure('hang_snapshot_value_invalid');
            }
            return $value;
        }
        $keys = array_keys($value);
        $isList = $value === [] || $keys === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function failure(string $reason, array $detail = []): CashierV3HangOrderAuthorityException
    {
        return new CashierV3HangOrderAuthorityException($reason, $detail);
    }
}
