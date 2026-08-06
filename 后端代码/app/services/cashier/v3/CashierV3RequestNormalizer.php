<?php
namespace app\services\cashier\v3;

/**
 * 写命令唯一请求规范化阶段。
 *
 * 别名类型非法或双别名冲突立即拒绝；规范化结果供 policy／permission／contexts／handler 共用。
 * 原始 payload 不得进入业务 handler。
 */
class CashierV3RequestNormalizer
{
    /** 房间安排允许的 mode 穷举合同 */
    public const ROOM_MODES = ['assign', 'change', 'remove', 'keep_unassigned'];

    /**
     * @return array{normalized:array,warnings:string[]}
     */
    public static function normalize(string $canonicalAction, array $payload): array
    {
        $out = $payload;
        $warnings = [];

        // 通用：拒绝布尔／数组冒充 ID 的别名（AliasResolver 已覆盖主要路径）
        self::assertNoIllegalAliasTypes($out, [
            'serviceOrderId', 'service_order_id',
            'checkoutRequestId', 'checkout_request_id',
            'hangOrderId', 'hang_order_id',
            'reservationId', 'reservation_id',
            'roomId', 'room_id',
            'currentRoomId', 'current_room_id',
            'targetRoomId', 'target_room_id',
            'selectorEntry', 'selector_entry',
        ]);

        if ($canonicalAction === 'save-service-room-assignment') {
            $out = self::normalizeRoomAssignment($out);
        }

        if ($canonicalAction === 'add-checkout-entitlement-lines') {
            $out = self::normalizeEntitlementLines($out);
        }

        if ($canonicalAction === 'update-cart-line-service-settings') {
            $out = self::normalizeCartLineServiceSettings($out);
        }

        if ($canonicalAction === 'submit-card-operation') {
            $out = self::normalizeCardOperation($out);
        }

        if (in_array($canonicalAction, [
            'add-payment-method',
            'update-payment-line',
            'remove-payment-line',
        ], true)) {
            $out = self::normalizeCheckoutPaymentDraft($canonicalAction, $out);
        }

        if ($canonicalAction === 'update-checkout-business-source') {
            $out = self::normalizeCheckoutBusinessSource($out);
        }

        if (in_array($canonicalAction, [
            'prepare-checkout-submission',
            'submit-checkout',
            'return-to-payment-edit',
        ], true)) {
            $out = self::normalizeCheckoutSubmissionPreparation($canonicalAction, $out);
        }

        // selectorEntry：只保留 canonical 键；双别名冲突拒绝
        if (CashierV3AliasResolver::hasAnyKey($out, ['selectorEntry', 'selector_entry'])) {
            $entry = CashierV3AliasResolver::resolveEnum($out, ['selectorEntry', 'selector_entry'], false);
            unset($out['selector_entry']);
            if ($entry !== '') {
                $out['selectorEntry'] = $entry;
            }
        }

        return ['normalized' => $out, 'warnings' => $warnings];
    }

    private static function normalizeCardOperation(array $payload): array
    {
        $type = CashierV3AliasResolver::resolveEnum(
            $payload,
            ['operationType', 'operation_type'],
            true,
            [
                'card_upgrade',
                'card_extension',
                'card_transfer',
                'card_disable',
                'card_enable',
                'project_replacement',
                'project_upgrade',
            ]
        );
        $holderId = self::canonicalPositiveInteger(
            CashierV3AliasResolver::resolveString(
                $payload,
                ['sourceCardHolderId', 'source_card_holder_id'],
                true
            ),
            'sourceCardHolderId'
        );
        $version = self::canonicalPositiveInteger(
            CashierV3AliasResolver::resolveString(
                $payload,
                ['sourceCardHolderVersion', 'source_card_holder_version'],
                true
            ),
            'sourceCardHolderVersion'
        );
        $reason = CashierV3AliasResolver::resolveString($payload, ['reason'], true);
        if (mb_strlen($reason) > 500) {
            throw self::invalidCardOperation('reason_invalid');
        }
        $base = [
            'operationType' => $type,
            'sourceCardHolderId' => $holderId,
            'sourceCardHolderVersion' => $version,
            'reason' => $reason,
        ];
        if ($type === 'card_transfer') {
            $base['targetMemberId'] = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString(
                    $payload,
                    ['targetMemberId', 'target_member_id'],
                    true
                ),
                'targetMemberId'
            );
        } elseif ($type === 'card_extension') {
            $base['newWriteEnd'] = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString(
                    $payload,
                    ['newWriteEnd', 'new_write_end'],
                    true
                ),
                'newWriteEnd'
            );
        } elseif (in_array($type, ['card_upgrade', 'project_replacement', 'project_upgrade'], true)) {
            // The authority service owns catalog resolution. The strict shape
            // is introduced here so no UI-only preview fields reach it.
            $base['targetCatalogId'] = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString(
                    $payload,
                    ['targetCatalogId', 'target_catalog_id'],
                    true
                ),
                'targetCatalogId'
            );
            if ($type !== 'card_upgrade') {
                $lines = $payload['projectLines'] ?? $payload['project_lines'] ?? null;
                if (!is_array($lines) || $lines === [] || count($lines) > 20) {
                    throw self::invalidCardOperation('project_lines_invalid');
                }
                $normalizedLines = [];
                foreach ($lines as $line) {
                    if (!is_array($line)) {
                        throw self::invalidCardOperation('project_line_invalid');
                    }
                    $normalizedLines[] = [
                        'sourceDetailId' => self::canonicalPositiveInteger(
                            CashierV3AliasResolver::resolveString(
                                $line,
                                ['sourceDetailId', 'source_detail_id'],
                                true
                            ),
                            'sourceDetailId'
                        ),
                        'quantity' => self::canonicalPositiveInteger($line['quantity'] ?? null, 'quantity'),
                    ];
                }
                $base['projectLines'] = $normalizedLines;
            }
        }
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        $allowedVariants = [
            'operationType', 'operation_type', 'sourceCardHolderId', 'source_card_holder_id',
            'sourceCardHolderVersion', 'source_card_holder_version', 'reason',
        ];
        if ($type === 'card_transfer') {
            $allowedVariants = array_merge($allowedVariants, ['targetMemberId', 'target_member_id']);
        } elseif ($type === 'card_extension') {
            $allowedVariants = array_merge($allowedVariants, ['newWriteEnd', 'new_write_end']);
        } elseif (in_array($type, ['card_upgrade', 'project_replacement', 'project_upgrade'], true)) {
            $allowedVariants = array_merge($allowedVariants, ['targetCatalogId', 'target_catalog_id']);
            if ($type !== 'card_upgrade') {
                $allowedVariants = array_merge($allowedVariants, ['projectLines', 'project_lines']);
            }
        }
        sort($allowedVariants, SORT_STRING);
        if (array_diff($actual, $allowedVariants) !== []) {
            throw self::invalidCardOperation('payload_shape_invalid');
        }
        return $base;
    }

    private static function invalidCardOperation(string $reason): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext(
            '卡操作资料无效，请刷新后重新填写。',
            ['action' => 'submit-card-operation', 'reason' => $reason]
        );
    }

    private static function normalizeCheckoutPaymentDraft(string $action, array $payload): array
    {
        $common = [
            'checkoutRequestId',
            'checkoutRequestVersion',
            'preparationRequestId',
            'preparationToken',
        ];
        $specific = [
            'add-payment-method' => ['paymentMethodId'],
            'update-payment-line' => [
                'paymentLineId',
                'amount',
                'externalTransactionNo',
                'remark',
            ],
            'remove-payment-line' => ['paymentLineId'],
        ][$action];
        $allowed = array_merge($common, $specific);
        $actual = array_keys($payload);
        sort($allowed, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $allowed) {
            throw CashierV3CommandException::invalidContext(
                '本次收款明细格式无效，请刷新结账页面后重试。',
                ['action' => $action, 'reason' => 'checkout_payment_payload_shape_invalid']
            );
        }

        $requestId = trim((string)$payload['checkoutRequestId']);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::invalidCheckoutPayment($action, 'checkout_request_id_invalid');
        }
        $requestVersion = self::strictPositiveInt(
            $payload['checkoutRequestVersion'],
            'checkoutRequestVersion',
            $action
        );
        $preparationRequestId = trim((string)$payload['preparationRequestId']);
        $preparationToken = trim((string)$payload['preparationToken']);
        if ($preparationRequestId === ''
            || strlen($preparationRequestId) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $preparationRequestId) !== 1
            || preg_match('/^CKPT-[0-9a-f]{64}$/D', $preparationToken) !== 1) {
            throw self::invalidCheckoutPayment($action, 'checkout_preparation_identity_invalid');
        }

        $normalized = [
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'preparationRequestId' => $preparationRequestId,
            'preparationToken' => $preparationToken,
        ];
        if ($action === 'add-payment-method') {
            $method = trim((string)$payload['paymentMethodId']);
            if (!in_array($method, [
                'unionpay',
                'wechat',
                'alipay',
                'dianping_voucher',
                'douyin_voucher',
                'partner_collection',
                'other_collection',
                'old_card_entry',
            ], true)) {
                throw self::invalidCheckoutPayment($action, 'payment_method_invalid');
            }
            $normalized['paymentMethodId'] = $method;
            return $normalized;
        }

        $paymentLineId = trim((string)$payload['paymentLineId']);
        if (preg_match('/^CKP-[0-9a-f]{40}$/D', $paymentLineId) !== 1) {
            throw self::invalidCheckoutPayment($action, 'payment_line_id_invalid');
        }
        $normalized['paymentLineId'] = $paymentLineId;
        if ($action === 'update-payment-line') {
            if (!is_string($payload['amount'])
                || preg_match('/^(?:0|[1-9][0-9]*)$/D', $payload['amount']) !== 1
                || !is_string($payload['externalTransactionNo'])
                || strlen($payload['externalTransactionNo']) > 64
                || !is_string($payload['remark'])
                || strlen($payload['remark']) > 255) {
                throw self::invalidCheckoutPayment($action, 'payment_line_fields_invalid');
            }
            $normalized['amount'] = $payload['amount'];
            $normalized['externalTransactionNo'] = trim($payload['externalTransactionNo']);
            $normalized['remark'] = trim($payload['remark']);
        }
        return $normalized;
    }

    private static function normalizeCheckoutBusinessSource(array $payload): array
    {
        $allowed = ['checkoutRequestId', 'checkoutRequestVersion', 'preparationRequestId', 'preparationToken', 'primarySourceId', 'secondarySourceId', 'sourceSelectionVersion'];
        $actual = array_keys($payload);
        sort($allowed, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $allowed) {
            throw CashierV3CommandException::invalidContext('业务来源资料无效，请刷新结账页面后重试。', ['action' => 'update-checkout-business-source', 'reason' => 'checkout_business_source_payload_shape_invalid']);
        }
        $requestId = trim((string)$payload['checkoutRequestId']);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::invalidCheckoutPayment('update-checkout-business-source', 'checkout_request_id_invalid');
        }
        $preparationRequestId = trim((string)$payload['preparationRequestId']);
        $preparationToken = trim((string)$payload['preparationToken']);
        if ($preparationRequestId === '' || strlen($preparationRequestId) > 128 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $preparationRequestId) !== 1 || preg_match('/^CKPT-[0-9a-f]{64}$/D', $preparationToken) !== 1) {
            throw self::invalidCheckoutPayment('update-checkout-business-source', 'checkout_preparation_identity_invalid');
        }
        $primary = self::strictPositiveInt($payload['primarySourceId'], 'primarySourceId', 'update-checkout-business-source');
        foreach (['secondarySourceId', 'sourceSelectionVersion'] as $field) {
            if (!is_int($payload[$field]) && !is_string($payload[$field]) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$payload[$field])) {
                throw self::invalidCheckoutPayment('update-checkout-business-source', $field . '_invalid');
            }
        }
        return [
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => self::strictPositiveInt($payload['checkoutRequestVersion'], 'checkoutRequestVersion', 'update-checkout-business-source'),
            'preparationRequestId' => $preparationRequestId,
            'preparationToken' => $preparationToken,
            'primarySourceId' => $primary,
            'secondarySourceId' => (int)$payload['secondarySourceId'],
            'sourceSelectionVersion' => (int)$payload['sourceSelectionVersion'],
        ];
    }

    private static function normalizeCheckoutSubmissionPreparation(
        string $action,
        array $payload
    ): array
    {
        $allowed = [
            'checkoutRequestId',
            'checkoutRequestVersion',
            'preparationRequestId',
            'preparationToken',
        ];
        $actual = array_keys($payload);
        sort($allowed, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $allowed) {
            throw CashierV3CommandException::invalidContext(
                '本次结账确认资料无效，请刷新结账页面后重试。',
                [
                    'action' => $action,
                    'reason' => 'checkout_submission_preparation_payload_shape_invalid',
                ]
            );
        }
        $requestId = trim((string)$payload['checkoutRequestId']);
        $requestVersion = self::strictPositiveInt(
            $payload['checkoutRequestVersion'],
            'checkoutRequestVersion',
            $action
        );
        $preparationRequestId = trim((string)$payload['preparationRequestId']);
        $preparationToken = trim((string)$payload['preparationToken']);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $preparationRequestId === ''
            || strlen($preparationRequestId) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $preparationRequestId) !== 1
            || preg_match('/^CKPT-[0-9a-f]{64}$/D', $preparationToken) !== 1) {
            throw self::invalidCheckoutPayment(
                $action,
                'checkout_submission_preparation_identity_invalid'
            );
        }
        return [
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'preparationRequestId' => $preparationRequestId,
            'preparationToken' => $preparationToken,
        ];
    }

    private static function strictPositiveInt($value, string $field, string $action): int
    {
        if (is_bool($value) || is_float($value) || is_array($value) || $value === null) {
            throw self::invalidCheckoutPayment($action, $field . '_invalid');
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1
            || strlen($raw) > strlen((string)PHP_INT_MAX)
            || (strlen($raw) === strlen((string)PHP_INT_MAX)
                && strcmp($raw, (string)PHP_INT_MAX) > 0)) {
            throw self::invalidCheckoutPayment($action, $field . '_invalid');
        }
        return (int)$raw;
    }

    private static function invalidCheckoutPayment(
        string $action,
        string $reason
    ): CashierV3CommandException {
        return CashierV3CommandException::invalidContext(
            '本次收款明细格式无效，请刷新结账页面后重试。',
            ['action' => $action, 'reason' => $reason]
        );
    }

    private static function normalizeEntitlementLines(array $payload): array
    {
        $payload['memberId'] = self::canonicalPositiveInteger(
            CashierV3AliasResolver::resolveString($payload, ['memberId', 'member_id'], true),
            'memberId'
        );
        unset($payload['member_id']);
        $payload['selectorRequestId'] = CashierV3AliasResolver::resolveString(
            $payload,
            ['selectorRequestId', 'selector_request_id'],
            true
        );
        $payload['selectorToken'] = CashierV3AliasResolver::resolveString(
            $payload,
            ['selectorToken', 'selector_token'],
            true
        );
        $addIntentId = CashierV3AliasResolver::resolveString(
            $payload,
            ['addIntentId', 'add_intent_id'],
            true
        );
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $addIntentId) !== 1) {
            throw self::invalidEntitlementLine(0, 'add_intent_id_invalid');
        }
        $mutationMode = CashierV3AliasResolver::resolveEnum(
            $payload,
            ['mutationMode', 'mutation_mode'],
            true,
            ['append']
        );
        unset(
            $payload['selector_request_id'],
            $payload['selector_token'],
            $payload['add_intent_id'],
            $payload['mutation_mode']
        );
        $payload['addIntentId'] = $addIntentId;
        $payload['mutationMode'] = $mutationMode;

        $lines = $payload['lines'] ?? null;
        if (!is_array($lines) || array_keys($lines) !== [0] || count($lines) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '请选择有效的卡内项目后再加入购物车。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'entitlement_lines_invalid']
            );
        }
        $normalized = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw self::invalidEntitlementLine((int)$index, 'line_not_object');
            }
            $holderId = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString($line, ['entitlementInstanceId', 'entitlement_instance_id'], true),
                'entitlementInstanceId'
            );
            $detailId = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString($line, ['entitlementSourceDetailId', 'entitlement_source_detail_id'], true),
                'entitlementSourceDetailId'
            );
            $key = $holderId . ':' . $detailId;
            if (isset($seen[$key])) {
                throw self::invalidEntitlementLine((int)$index, 'duplicate_entitlement_line');
            }
            $seen[$key] = true;
            $serviceObject = CashierV3AliasResolver::resolveEnum(
                $line,
                ['serviceObject', 'service_object'],
                false,
                ['self', 'friend', '本人', '朋友']
            );
            $craftsmen = self::normalizeCraftsmen($line['craftsmen'] ?? [], (int)$index);
            $canonicalServiceObject = in_array($serviceObject, ['friend', '朋友'], true)
                ? 'friend'
                : 'self';
            if ($canonicalServiceObject !== 'self' || $craftsmen !== []) {
                throw self::invalidEntitlementLine((int)$index, 'service_settings_must_be_updated_in_cart');
            }
            $normalized[] = [
                'entitlementInstanceId' => $holderId,
                'entitlementInstanceType' => 'card_holder',
                'entitlementSourceDetailId' => $detailId,
                'entitlementSourceVersion' => self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString(
                        $line,
                        ['entitlementSourceVersion', 'entitlement_source_version'],
                        true
                    ),
                    'entitlementSourceVersion'
                ),
                'projectId' => self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString($line, ['projectId', 'project_id'], true),
                    'projectId'
                ),
                'projectVersion' => self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString($line, ['projectVersion', 'project_version'], true),
                    'projectVersion'
                ),
                'quantity' => self::canonicalPositiveInteger($line['quantity'] ?? null, 'quantity', 1),
                // 服务对象、手艺人与体验标记只能在购物车行命令中设置；加入动作只创建安全默认值。
                'serviceObject' => '本人',
                'craftsmen' => [],
            ];
        }
        $payload['lines'] = $normalized;
        return $payload;
    }

    private static function normalizeCartLineServiceSettings(array $payload): array
    {
        $lineId = CashierV3AliasResolver::resolveString($payload, ['lineId', 'line_id'], true);
        if ($lineId === '' || strlen($lineId) > 64 || strpos($lineId, "\0") !== false) {
            throw self::invalidCartLineSetting('lineId', 'line_id_invalid');
        }

        $serviceKeys = ['serviceObject', 'service_object'];
        $craftsmanKeys = ['craftsmen', 'craftsmanIds', 'craftsman_ids'];
        $salespeopleKeys = ['salespeople', 'salesPeople', 'sales_people'];
        $experienceKeys = ['isExperience', 'is_experience'];
        $hasServiceObject = CashierV3AliasResolver::hasAnyKey($payload, $serviceKeys);
        $hasCraftsmen = CashierV3AliasResolver::hasAnyKey($payload, $craftsmanKeys);
        $hasSalespeople = CashierV3AliasResolver::hasAnyKey($payload, $salespeopleKeys);
        $hasExperience = CashierV3AliasResolver::hasAnyKey($payload, $experienceKeys);
        if (!$hasServiceObject && !$hasCraftsmen && !$hasSalespeople && !$hasExperience) {
            throw self::invalidCartLineSetting('settings', 'service_settings_missing');
        }

        $normalizedServiceObject = null;
        $normalizedCraftsmen = null;
        $normalizedSalespeople = null;
        $normalizedExperience = null;
        if ($hasServiceObject) {
            $serviceObject = CashierV3AliasResolver::resolveEnum(
                $payload,
                $serviceKeys,
                true,
                ['self', 'friend', '本人', '朋友']
            );
            $normalizedServiceObject = in_array($serviceObject, ['friend', '朋友'], true)
                ? 'friend'
                : 'self';
        }
        if ($hasCraftsmen) {
            $normalizedCraftsmen = self::resolveCraftsmen($payload);
        }
        if ($hasSalespeople) {
            $normalizedSalespeople = self::resolveSalespeople($payload);
        }
        if ($hasExperience) {
            $normalizedExperience = self::resolveBooleanAliases($payload, $experienceKeys);
        }

        unset(
            $payload['line_id'],
            $payload['service_object'],
            $payload['craftsmen'],
            $payload['craftsmanIds'],
            $payload['craftsman_ids'],
            $payload['salespeople'],
            $payload['salesPeople'],
            $payload['sales_people'],
            $payload['is_experience']
        );
        $payload['lineId'] = $lineId;

        if ($hasServiceObject) {
            $payload['serviceObject'] = $normalizedServiceObject;
        }
        if ($hasCraftsmen) {
            $payload['craftsmen'] = $normalizedCraftsmen;
        }
        if ($hasSalespeople) {
            $payload['salespeople'] = $normalizedSalespeople;
        }
        if ($hasExperience) {
            $payload['isExperience'] = $normalizedExperience;
        }
        return $payload;
    }

    /** @return array<int,array{staffId:int,allocationWeight:int}> */
    private static function resolveSalespeople(array $payload): array
    {
        $candidates = [];
        foreach (['salespeople', 'salesPeople', 'sales_people'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $rows = $payload[$key];
            if (!is_array($rows) || array_keys($rows) !== ($rows ? range(0, count($rows) - 1) : [])) {
                throw self::invalidCartLineSetting('salespeople', 'salespeople_not_list');
            }
            if (count($rows) > 20) {
                throw self::invalidCartLineSetting('salespeople', 'salespeople_too_many');
            }
            $seen = [];
            $normalized = [];
            $weightSum = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw self::invalidCartLineSetting('salespeople', 'salesperson_not_object');
                }
                $staffId = self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString(
                        $row,
                        ['staffId', 'staff_id', 'id', 'systemStoreStaffId', 'system_store_staff_id'],
                        true
                    ),
                    'salespersonId'
                );
                $weight = self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString(
                        $row,
                        ['allocationWeight', 'allocation_weight', 'weight'],
                        true
                    ),
                    'salespersonAllocationWeight'
                );
                if ($weight > 100 || isset($seen[$staffId])) {
                    throw self::invalidCartLineSetting(
                        'salespeople',
                        isset($seen[$staffId]) ? 'duplicate_salesperson' : 'salesperson_weight_invalid'
                    );
                }
                $seen[$staffId] = true;
                $weightSum += $weight;
                $normalized[] = ['staffId' => $staffId, 'allocationWeight' => $weight];
            }
            if ($normalized && $weightSum !== 100) {
                throw self::invalidCartLineSetting('salespeople', 'salesperson_weight_sum_invalid');
            }
            $candidates[] = $normalized;
        }
        if (!$candidates) {
            throw self::invalidCartLineSetting('salespeople', 'salespeople_missing');
        }
        $canonical = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate !== $canonical) {
                throw self::invalidCartLineSetting('salespeople', 'salespeople_alias_conflict');
            }
        }
        return $canonical;
    }

    /** @return array<int,array{staffId:int,laborWeight:int,isPointCustomer:bool}> */
    private static function resolveCraftsmen(array $payload): array
    {
        $candidates = [];
        if (array_key_exists('craftsmen', $payload)) {
            $candidates[] = self::normalizeCraftsmanRows($payload['craftsmen']);
        }
        foreach (['craftsmanIds', 'craftsman_ids'] as $key) {
            if (array_key_exists($key, $payload)) {
                $candidates[] = self::normalizeCraftsmanIdList($payload[$key]);
            }
        }
        if (!$candidates) {
            throw self::invalidCartLineSetting('craftsmen', 'craftsmen_missing');
        }
        $canonical = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate !== $canonical) {
                throw self::invalidCartLineSetting('craftsmen', 'craftsmen_alias_conflict');
            }
        }
        return $canonical;
    }

    /** @return array<int,array{staffId:int,laborWeight:int,isPointCustomer:bool}> */
    private static function normalizeCraftsmanRows($rows): array
    {
        if (!is_array($rows) || array_keys($rows) !== ($rows ? range(0, count($rows) - 1) : [])) {
            throw self::invalidCartLineSetting('craftsmen', 'craftsmen_not_list');
        }
        if (count($rows) > 20) {
            throw self::invalidCartLineSetting('craftsmen', 'craftsmen_too_many');
        }
        $assignments = [];
        $seen = [];
        $hasExplicitWeight = null;
        $weightSum = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::invalidCartLineSetting('craftsmen', 'craftsman_not_object');
            }
            $id = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString(
                    $row,
                    ['id', 'staffId', 'staff_id', 'systemStoreStaffId', 'system_store_staff_id'],
                    true
                ),
                'craftsmanId'
            );
            if (isset($seen[$id])) {
                throw self::invalidCartLineSetting('craftsmen', 'duplicate_craftsman');
            }
            $seen[$id] = true;
            $rowHasWeight = CashierV3AliasResolver::hasAnyKey($row, [
                'laborWeight', 'labor_weight', 'allocationWeight', 'allocation_weight', 'weight',
            ]);
            if ($hasExplicitWeight !== null && $hasExplicitWeight !== $rowHasWeight) {
                throw self::invalidCartLineSetting('craftsmen', 'craftsman_weight_partial');
            }
            $hasExplicitWeight = $rowHasWeight;
            $weight = $rowHasWeight
                ? self::canonicalPositiveInteger(
                    CashierV3AliasResolver::resolveString(
                        $row,
                        ['laborWeight', 'labor_weight', 'allocationWeight', 'allocation_weight', 'weight'],
                        true
                    ),
                    'craftsmanLaborWeight'
                )
                : 0;
            if ($weight > 100) {
                throw self::invalidCartLineSetting('craftsmen', 'craftsman_weight_invalid');
            }
            $pointCustomer = false;
            if (CashierV3AliasResolver::hasAnyKey($row, ['isPointCustomer', 'is_point_customer', 'marked'])) {
                $pointCustomer = self::resolveBooleanAliases(
                    $row,
                    ['isPointCustomer', 'is_point_customer', 'marked']
                );
            }
            $weightSum += $weight;
            $assignments[] = [
                'staffId' => $id,
                'laborWeight' => $weight,
                'isPointCustomer' => $pointCustomer,
            ];
        }
        if (!$assignments) {
            return [];
        }
        if ($hasExplicitWeight && $weightSum !== 100) {
            throw self::invalidCartLineSetting('craftsmen', 'craftsman_weight_sum_invalid');
        }
        if (!$hasExplicitWeight) {
            self::assignEqualLaborWeights($assignments);
        }
        return $assignments;
    }

    /** @return array<int,array{staffId:int,laborWeight:int,isPointCustomer:bool}> */
    private static function normalizeCraftsmanIdList($rows): array
    {
        if (!is_array($rows) || array_keys($rows) !== ($rows ? range(0, count($rows) - 1) : [])) {
            throw self::invalidCartLineSetting('craftsmanIds', 'craftsman_ids_not_list');
        }
        if (count($rows) > 20) {
            throw self::invalidCartLineSetting('craftsmanIds', 'craftsmen_too_many');
        }
        $assignments = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = self::canonicalPositiveInteger($row, 'craftsmanId');
            if (isset($seen[$id])) {
                throw self::invalidCartLineSetting('craftsmanIds', 'duplicate_craftsman');
            }
            $seen[$id] = true;
            $assignments[] = [
                'staffId' => $id,
                'laborWeight' => 0,
                'isPointCustomer' => false,
            ];
        }
        self::assignEqualLaborWeights($assignments);
        return $assignments;
    }

    private static function assignEqualLaborWeights(array &$assignments): void
    {
        if (!$assignments) {
            return;
        }
        $base = intdiv(100, count($assignments));
        $remaining = 100 - ($base * count($assignments));
        foreach ($assignments as &$assignment) {
            $assignment['laborWeight'] = $base + ($remaining > 0 ? 1 : 0);
            $remaining = max(0, $remaining - 1);
        }
        unset($assignment);
    }

    private static function resolveBooleanAliases(array $payload, array $keys): bool
    {
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            if (is_bool($raw)) {
                $value = $raw;
            } elseif (is_int($raw) && ($raw === 0 || $raw === 1)) {
                $value = $raw === 1;
            } else {
                throw self::invalidCartLineSetting($key, 'boolean_type_invalid');
            }
            $values[$value ? '1' : '0'] = $value;
        }
        if (count($values) !== 1) {
            throw self::invalidCartLineSetting('isExperience', 'boolean_alias_conflict');
        }
        return (bool)array_values($values)[0];
    }

    /** @return array<int,array{id:int,name:string,isPrimary:bool,sequence:int}> */
    private static function normalizeCraftsmen($rows, int $lineIndex): array
    {
        if (!is_array($rows) || array_keys($rows) !== ($rows ? range(0, count($rows) - 1) : [])) {
            throw self::invalidEntitlementLine($lineIndex, 'craftsmen_not_list');
        }
        if (count($rows) > 20) {
            throw self::invalidEntitlementLine($lineIndex, 'craftsmen_too_many');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $staffIndex => $row) {
            if (!is_array($row)) {
                throw self::invalidEntitlementLine($lineIndex, 'craftsman_not_object');
            }
            $id = self::canonicalPositiveInteger(
                CashierV3AliasResolver::resolveString(
                    $row,
                    ['id', 'staffId', 'staff_id', 'employeeId', 'employee_id'],
                    true
                ),
                'craftsmanId'
            );
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = CashierV3AliasResolver::resolveString(
                $row,
                ['name', 'staffName', 'staff_name', 'employeeName', 'employee_name', 'realName', 'real_name'],
                false
            );
            $name = mb_substr(trim($name), 0, 64);
            $out[] = [
                'id' => $id,
                'name' => $name,
                'isPrimary' => count($out) === 0,
                'sequence' => count($out) + 1,
            ];
        }
        return $out;
    }

    private static function canonicalPositiveInteger($value, string $field, int $maximum = 0): int
    {
        if (is_bool($value) || is_array($value) || $value === null || is_float($value)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '卡内项目明细无效，请重新打开后选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['field' => $field]
            );
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '卡内项目明细无效，请重新打开后选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['field' => $field]
            );
        }
        $normalized = (int)$raw;
        if ($normalized <= 0 || ($maximum > 0 && $normalized > $maximum)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '卡内项目明细无效，请重新打开后选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['field' => $field]
            );
        }
        return $normalized;
    }

    private static function invalidEntitlementLine(int $index, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
            '卡内项目明细无效，请重新打开后选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['index' => $index, 'reason' => $reason]
        );
    }

    private static function invalidCartLineSetting(string $field, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
            '购物车服务设置无效，请重新选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['field' => $field, 'reason' => $reason]
        );
    }

    /**
     * 房间安排：禁止静默把一个业务 mode 改成另一个；矛盾组合立即拒绝。
     */
    protected static function normalizeRoomAssignment(array $payload): array
    {
        $mode = CashierV3AliasResolver::resolveEnum(
            $payload,
            ['assignmentMode', 'assignment_mode', 'mode'],
            true,
            self::ROOM_MODES
        );
        $currentRoomId = CashierV3AliasResolver::resolveNullableId(
            $payload,
            ['currentRoomId', 'current_room_id']
        );
        $targetRoomId = CashierV3AliasResolver::resolveNullableId(
            $payload,
            ['targetRoomId', 'target_room_id']
        );
        $hasTarget = CashierV3AliasResolver::hasAnyKey($payload, ['targetRoomId', 'target_room_id']);
        $hasCurrent = CashierV3AliasResolver::hasAnyKey($payload, ['currentRoomId', 'current_room_id']);

        switch ($mode) {
            case 'assign':
                if ($hasCurrent && $currentRoomId !== null && $currentRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '分配房间时不得夹带当前房间对象。',
                        ['reason' => 'assign_forbids_current_room', 'mode' => $mode]
                    );
                }
                if ($targetRoomId === null || $targetRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '分配房间时必须指定目标房间。',
                        ['reason' => 'assign_requires_target_room', 'mode' => $mode]
                    );
                }
                break;
            case 'change':
                if ($currentRoomId === null || $currentRoomId === '' || $targetRoomId === null || $targetRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '换房必须同时指定当前房间与目标房间。',
                        ['reason' => 'change_requires_current_and_target', 'mode' => $mode]
                    );
                }
                if ($currentRoomId === $targetRoomId) {
                    throw CashierV3CommandException::invalidContext(
                        '换房的当前房间与目标房间不能相同。',
                        ['reason' => 'change_same_room', 'mode' => $mode]
                    );
                }
                break;
            case 'remove':
                if ($currentRoomId === null || $currentRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '移除房间占用时必须锁定当前房间。',
                        ['reason' => 'remove_requires_current', 'mode' => $mode]
                    );
                }
                if ($hasTarget && $targetRoomId !== null && $targetRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '移除房间占用时不得夹带目标房间。',
                        ['reason' => 'remove_forbids_target_room', 'mode' => $mode]
                    );
                }
                break;
            case 'keep_unassigned':
                if ($hasTarget && $targetRoomId !== null && $targetRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '保持待分配时不得夹带房间对象。',
                        ['reason' => 'keep_unassigned_forbids_room', 'mode' => $mode]
                    );
                }
                if ($hasCurrent && $currentRoomId !== null && $currentRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '保持待分配时不得夹带当前房间。',
                        ['reason' => 'keep_unassigned_forbids_current', 'mode' => $mode]
                    );
                }
                break;
        }

        $payload['assignmentMode'] = $mode;
        $payload['mode'] = $mode;
        unset($payload['assignment_mode']);
        if ($hasCurrent) {
            $payload['currentRoomId'] = $currentRoomId;
            unset($payload['current_room_id']);
        }
        if ($hasTarget) {
            $payload['targetRoomId'] = $targetRoomId;
            unset($payload['target_room_id']);
        }
        return $payload;
    }

    /** @param string[] $keys */
    protected static function assertNoIllegalAliasTypes(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            if (is_array($raw) || is_bool($raw)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象标识格式无效，请刷新当前工作台后重试。',
                    ['reason' => 'payload_id_alias_type_invalid', 'key' => $key]
                );
            }
        }
    }
}
