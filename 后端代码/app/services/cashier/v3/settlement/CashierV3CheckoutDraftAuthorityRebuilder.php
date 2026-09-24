<?php

namespace app\services\cashier\v3\settlement;

/**
 * Rebuild an editable checkout authority snapshot from its locked aggregate.
 *
 * Product, entitlement, member, operator and source-document snapshots remain
 * historical. Only the current workspace authority version, permission
 * fingerprint and draft-operation time are refreshed by the server.
 */
final class CashierV3CheckoutDraftAuthorityRebuilder
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-draft-authority-rebuilder-v1';

    public function rebuild(
        array $aggregate,
        int $workspaceExpectedVersion,
        string $permissionSnapshotFingerprint,
        int $serverTime
    ): array {
        self::assertAggregateShape($aggregate);
        if ($workspaceExpectedVersion <= 0) {
            throw self::failure('checkout_draft_workspace_version_invalid');
        }
        if ($serverTime <= 0 || $serverTime > 4294967295) {
            throw self::failure('checkout_draft_server_time_invalid');
        }

        // Reuse the read projection's strict row/fingerprint checks before
        // turning persistence rows back into a writable authority snapshot.
        CashierV3CheckoutProjectionServices::projectPersistedAggregate([
            'request' => $aggregate['request'],
            'lines' => array_values($aggregate['lines']),
            'payments' => array_values($aggregate['payments']),
            'sources' => array_values($aggregate['sources']),
            'workspaceCurrentVersion' => $workspaceExpectedVersion,
            // The aggregate was locked by its exact checkout request ID. A
            // workspace-only edit such as business-source selection may have
            // advanced the workspace more than once without changing this
            // request version; that is valid for this exact-request rebuild.
            'exactRequestProjection' => true,
        ]);

        $request = $aggregate['request'];
        $saleLines = [];
        $entitlementLines = [];
        foreach (self::orderedRows($aggregate['lines']) as $row) {
            $role = (string)($row['line_role'] ?? '');
            if ($role === 'sale') {
                $saleLine = [
                    'authorityKey' => (string)($row['authority_key'] ?? ''),
                    'saleClassification' => 'formal_sale',
                    'sourceType' => (string)($row['source_type'] ?? ''),
                    'sourceId' => self::positiveInt($row['source_id'] ?? null, 'sale.source_id'),
                    'sourceVersion' => self::positiveInt(
                        $row['source_version'] ?? null,
                        'sale.source_version'
                    ),
                    'quantity' => self::positiveInt($row['quantity'] ?? null, 'sale.quantity'),
                    'originalAmountCents' => self::nonNegativeInt(
                        $row['original_amount_cents'] ?? null,
                        'sale.original_amount_cents'
                    ),
                    'discountAmountCents' => self::nonNegativeInt(
                        $row['discount_amount_cents'] ?? null,
                        'sale.discount_amount_cents'
                    ),
                    'couponUserId' => self::nonNegativeInt(
                        $row['coupon_user_id'] ?? 0,
                        'sale.coupon_user_id'
                    ),
                    'couponNameSnapshot' => (string)($row['coupon_name_snapshot'] ?? ''),
                    'couponDiscountCents' => self::nonNegativeInt(
                        $row['coupon_discount_cents'] ?? 0,
                        'sale.coupon_discount_cents'
                    ),
                    'saleAmountCents' => self::nonNegativeInt(
                        $row['sale_amount_cents'] ?? null,
                        'sale.sale_amount_cents'
                    ),
                    'debtAmountCents' => self::nonNegativeInt(
                        $row['debt_amount_cents'] ?? null,
                        'sale.debt_amount_cents'
                    ),
                    'sourceNameSnapshot' => (string)($row['source_name_snapshot'] ?? ''),
                    'sourceCodeSnapshot' => (string)($row['source_code_snapshot'] ?? ''),
                    'categoryIdSnapshot' => self::nonNegativeInt(
                        $row['category_id_snapshot'] ?? null,
                        'sale.category_id_snapshot'
                    ),
                    'categoryNameSnapshot' => (string)($row['category_name_snapshot'] ?? ''),
                    'configuredCostCents' => self::nonNegativeInt(
                        $row['configured_cost_cents'] ?? null,
                        'sale.configured_cost_cents'
                    ),
                    'priceChangeReason' => (string)($row['price_change_reason'] ?? ''),
                    'priceChangedBy' => self::nonNegativeInt(
                        $row['price_changed_by'] ?? null,
                        'sale.price_changed_by'
                    ),
                    'priceChangedByNameSnapshot' => (string)($row['price_changed_by_name_snapshot'] ?? ''),
                    'priceChangedAt' => self::nonNegativeInt(
                        $row['price_changed_at'] ?? null,
                        'sale.price_changed_at'
                    ),
                    'serviceObject' => (string)($row['service_object'] ?? ''),
                    'friendCountsAsCustomer' => self::nonNegativeInt(
                        $row['friend_counts_as_customer'] ?? 1,
                        'sale.friend_counts_as_customer'
                    ),
                    'isExperience' => self::nonNegativeInt(
                        $row['is_experience'] ?? null,
                        'sale.is_experience'
                    ),
                    'craftsmen' => self::craftsmenSnapshot(
                        $row['craftsmen_snapshot_json'] ?? null
                    ),
                    'salespeople' => self::salespeopleSnapshot(
                        $row['salespeople_snapshot_json'] ?? null
                    ),
                    'guideSelections' => self::attributionSnapshot($row['guide_selections_json'] ?? null),
                    'salesManagerSelections' => self::attributionSnapshot($row['sales_manager_selections_json'] ?? null),
                    'cardPurchaseSnapshot' => self::cardPurchaseSnapshot(
                        $row['card_purchase_snapshot_json'] ?? null,
                        (string)($row['source_type'] ?? '')
                    ),
                ];
                if (self::nonNegativeInt($row['is_presale'] ?? 0, 'sale.is_presale') !== 0) {
                    $saleLine['isPresale'] = 1;
                }
                if (self::nonNegativeInt($row['inventory_outbound_required'] ?? 1, 'sale.inventory_outbound_required') !== 1) {
                    $saleLine['inventoryOutboundRequired'] = 0;
                }
                if (($row['manual_labor_fee_cents'] ?? null) !== null) {
                    $saleLine['manualLaborFeeCents'] = self::nonNegativeInt(
                        $row['manual_labor_fee_cents'],
                        'sale.manual_labor_fee_cents'
                    );
                }
                $catalogSkuId = self::nonNegativeInt(
                    $row['catalog_sku_id'] ?? 0,
                    'sale.catalog_sku_id'
                );
                if ($catalogSkuId > 0) {
                    $saleLine['catalogSkuId'] = $catalogSkuId;
                }
                if ((string)($row['detail_remark_snapshot'] ?? '') !== '') {
                    $saleLine['detailRemark'] = (string)$row['detail_remark_snapshot'];
                }
                $saleLines[] = $saleLine;
                continue;
            }
            if ($role !== 'entitlement_service') {
                throw self::failure('checkout_draft_line_role_invalid');
            }
            $entitlementLine = [
                'authorityKey' => (string)($row['authority_key'] ?? ''),
                'sourceKind' => (string)($row['source_kind'] ?? ''),
                'holderId' => self::positiveInt(
                    $row['source_id'] ?? null,
                    'entitlement.holder_id'
                ),
                'entitlementSourceDetailId' => self::positiveInt(
                    $row['entitlement_source_detail_id'] ?? null,
                    'entitlement.source_detail_id'
                ),
                'sourceVersion' => self::positiveInt(
                    $row['source_version'] ?? null,
                    'entitlement.source_version'
                ),
                'projectId' => self::positiveInt(
                    $row['project_id'] ?? null,
                    'entitlement.project_id'
                ),
                'projectVersion' => self::positiveInt(
                    $row['project_version'] ?? null,
                    'entitlement.project_version'
                ),
                'quantity' => self::positiveInt(
                    $row['quantity'] ?? null,
                    'entitlement.quantity'
                ),
                'actualEntitlementAmountCents' => self::nonNegativeInt(
                    $row['entitlement_actual_amount_cents'] ?? null,
                    'entitlement.actual_amount_cents'
                ),
                'sourceNameSnapshot' => (string)($row['source_name_snapshot'] ?? ''),
                'sourceCodeSnapshot' => (string)($row['source_code_snapshot'] ?? ''),
                'projectNameSnapshot' => (string)($row['project_name_snapshot'] ?? ''),
                'projectCategoryIdSnapshot' => self::nonNegativeInt(
                    $row['category_id_snapshot'] ?? null,
                    'entitlement.category_id_snapshot'
                ),
                'projectCategoryNameSnapshot' => (string)($row['category_name_snapshot'] ?? ''),
                // Service settings are part of the checkout-line snapshot. They
                // are not entitlement authority, but final completion must use
                // the exact selection made in the browser snapshot.
                // Entitlement service rows carry the browser's compact
                // selection (staffId/laborWeight/flags). They are expanded
                // to authoritative staff snapshots by the entitlement
                // completion adapter after its final locks. Do not decode
                // this selection with the sale-line snapshot contract, which
                // requires employee/name/store fields that are not part of
                // the browser-owned entitlement intent.
                'craftsmen' => self::entitlementCraftsmenSnapshot(
                    $row['craftsmen_snapshot_json'] ?? null
                ),
                'serviceObject' => (string)($row['service_object'] ?? ''),
                'friendCountsAsCustomer' => (int)($row['friend_counts_as_customer'] ?? 1) === 1,
                'isExperience' => (int)($row['is_experience'] ?? 0) === 1,
            ];
            if (($row['manual_labor_fee_cents'] ?? null) !== null) {
                $entitlementLine['manualLaborFeeCents'] = self::nonNegativeInt(
                    $row['manual_labor_fee_cents'],
                    'entitlement.manual_labor_fee_cents'
                );
            }
            if ((string)($row['detail_remark_snapshot'] ?? '') !== '') {
                $entitlementLine['detailRemark'] = (string)$row['detail_remark_snapshot'];
            }
            $entitlementLines[] = $entitlementLine;
        }

        $paymentDetails = [];
        foreach (self::orderedRows($aggregate['payments']) as $row) {
            $paymentDetails[] = [
                'paymentAuthorityKey' => (string)($row['payment_authority_key'] ?? ''),
                'method' => (string)($row['payment_method'] ?? ''),
                'amountCents' => self::nonNegativeInt(
                    $row['amount_cents'] ?? null,
                    'payment.amount_cents'
                ),
                'businessTime' => $serverTime,
                'externalTransactionNo' => (string)($row['external_transaction_no'] ?? ''),
                'remark' => (string)($row['remark'] ?? ''),
            ];
        }

        $balanceAmount = self::nonNegativeInt(
            $request['balance_deduction_amount_cents'] ?? null,
            'request.balance_deduction_amount_cents'
        );
        $debtAmount = self::nonNegativeInt(
            $request['debt_amount_cents'] ?? null,
            'request.debt_amount_cents'
        );
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => $workspaceExpectedVersion,
            'authoritySnapshotFingerprint' => '',
            'tenantId' => (string)($request['tenant_id'] ?? ''),
            'organizationId' => (string)($request['organization_id'] ?? ''),
            'organizationPath' => (string)($request['organization_path'] ?? ''),
            'organizationName' => (string)($request['organization_name_snapshot'] ?? ''),
            'storeId' => self::positiveInt($request['store_id'] ?? null, 'request.store_id'),
            'storeName' => (string)($request['store_name_snapshot'] ?? ''),
            'workspaceId' => (string)($request['workspace_id'] ?? ''),
            'stateContextId' => (string)($request['state_context_id'] ?? ''),
            'permissionSnapshotFingerprint' => $permissionSnapshotFingerprint,
            'memberId' => self::nonNegativeInt($request['member_id'] ?? null, 'request.member_id'),
            'memberName' => (string)($request['member_name_snapshot'] ?? ''),
            'operatorId' => self::positiveInt($request['operator_id'] ?? null, 'request.operator_id'),
            'operatorName' => (string)($request['operator_name_snapshot'] ?? ''),
            'businessDate' => (string)($request['business_date'] ?? ''),
            'businessTimezone' => (string)($request['business_timezone'] ?? ''),
            'occurredAt' => $serverTime,
            'recordedAt' => $serverTime,
            'orderNote' => (string)($request['order_note'] ?? ''),
            'supplement' => [
                'enabled' => (int)($request['supplement_enabled'] ?? 0) === 1,
                'reason' => (string)($request['supplement_reason'] ?? ''),
                'operatorId' => self::nonNegativeInt(
                    $request['supplement_operator_id'] ?? null,
                    'request.supplement_operator_id'
                ),
                'operatorNameSnapshot' => (string)($request['supplement_operator_name_snapshot'] ?? ''),
                'operatedAt' => self::nonNegativeInt(
                    $request['supplement_operated_at'] ?? null,
                    'request.supplement_operated_at'
                ),
            ],
            'sourceDocument' => [
                'type' => (string)($request['source_document_type'] ?? ''),
                'id' => (string)($request['source_document_id'] ?? ''),
                'no' => (string)($request['source_document_no'] ?? ''),
            ],
            'saleLines' => $saleLines,
            'entitlementLines' => $entitlementLines,
            'paymentDetails' => $paymentDetails,
            'balanceDeduction' => $balanceAmount === 0 ? [
                'authorityKey' => '',
                'accountId' => '',
                'accountVersion' => 0,
                'amountCents' => 0,
            ] : [
                'authorityKey' => (string)($request['balance_authority_key'] ?? ''),
                'accountId' => (string)($request['balance_account_id'] ?? ''),
                'accountVersion' => self::positiveInt(
                    $request['balance_account_version'] ?? null,
                    'request.balance_account_version'
                ),
                'amountCents' => $balanceAmount,
            ],
            'debt' => $debtAmount === 0 ? [
                'authorityKey' => '',
                'policyVersion' => 0,
                'amountCents' => 0,
            ] : [
                'authorityKey' => (string)($request['debt_authority_key'] ?? ''),
                'policyVersion' => self::positiveInt(
                    $request['debt_policy_version'] ?? null,
                    'request.debt_policy_version'
                ),
                'amountCents' => $debtAmount,
            ],
        ];
        $snapshot['authoritySnapshotFingerprint'] =
            CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        return $snapshot;
    }

    private static function attributionSnapshot($raw): array
    {
        if ($raw === null || trim((string)$raw) === '') return [];
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) throw self::failure('checkout_draft_attribution_snapshot_invalid');
        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) throw self::failure('checkout_draft_attribution_snapshot_invalid');
            $id = (int)($row['employeeId'] ?? $row['employee_id'] ?? $row['id'] ?? 0);
            if ($id <= 0) throw self::failure('checkout_draft_attribution_snapshot_invalid');
            $snapshot = ['employeeId' => $id];
            // 重建必须保留导购身份及其轮次选择。0 是明确的“无轮次”
            // 传输值；最终事实层按权威会员 ID 验证后存成 NULL。
            if (array_key_exists('guideRoundNo', $row) || array_key_exists('guide_round_no', $row)) {
                $roundNo = (int)($row['guideRoundNo'] ?? $row['guide_round_no'] ?? 0);
                if ($roundNo < 0 || $roundNo > 3) {
                    throw self::failure('checkout_draft_guide_round_invalid');
                }
                $snapshot['guideRoundNo'] = $roundNo;
            }
            $result[] = $snapshot;
        }
        return $result;
    }

    private static function salespeopleSnapshot($raw): array
    {
        if ($raw === null || trim((string)$raw) === '') return [];
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) throw self::failure('checkout_draft_salespeople_snapshot_invalid');
        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) throw self::failure('checkout_draft_salespeople_snapshot_invalid');
            $staffId = (int)($row['staffId'] ?? $row['id'] ?? 0);
            $allocation = (int)($row['allocationWeight'] ?? 0);
            if ($staffId <= 0 || $allocation < 0 || $allocation > 100) {
                throw self::failure('checkout_draft_salespeople_snapshot_invalid');
            }
            $positionId = max(0, (int)($row['positionId'] ?? $row['position_id'] ?? 0));
            $explicitGroupKey = trim((string)($row['allocationGroupKey'] ?? ''));
            $performanceIndependent = !empty($row['performanceIndependent'])
                || !empty($row['performance_independent'])
                || strncmp($explicitGroupKey, 'independent:', 12) === 0;
            $groupKey = $performanceIndependent
                ? ($explicitGroupKey !== '' && strncmp($explicitGroupKey, 'independent:', 12) === 0
                    ? $explicitGroupKey
                    : 'independent:' . ($positionId > 0 ? $positionId : $staffId))
                : 'normal';
            $resultRow = [
                'staffId' => $staffId,
                'allocationWeight' => $allocation,
                // Do not rely on a chained null-coalescing expression here:
                // legacy PHP error handlers can still report the final alias
                // as an undefined index while rebuilding an older snapshot.
                'isPreSale' => !empty($row['isPreSale'])
                    || !empty($row['is_presale'])
                    || !empty($row['marked']),
            ];
            if (array_key_exists('performanceAmountCents', $row)
                || array_key_exists('performance_amount_cents', $row)) {
                $amount = (int)($row['performanceAmountCents'] ?? $row['performance_amount_cents'] ?? -1);
                if ($amount < 0) throw self::failure('checkout_draft_salespeople_snapshot_invalid');
                $resultRow['performanceAmountCents'] = $amount;
                $resultRow['performanceAmountManual'] = !empty($row['performanceAmountManual'])
                    || !empty($row['performance_amount_manual']);
            }
            if ($positionId > 0) {
                $resultRow['positionId'] = $positionId;
                $resultRow['positionName'] = trim((string)($row['positionName'] ?? $row['position_name'] ?? ''));
            }
            if ($performanceIndependent) {
                $resultRow['performanceIndependent'] = true;
                $resultRow['allocationGroupKey'] = $groupKey;
            }
            $result[] = $resultRow;
        }
        return $result;
    }

    private static function assertAggregateShape(array $aggregate): void
    {
        foreach (['request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources'] as $key) {
            if (!array_key_exists($key, $aggregate)) {
                throw self::failure('checkout_draft_aggregate_incomplete', ['missing' => $key]);
            }
        }
        if (!is_array($aggregate['request'])
            || !is_array($aggregate['lines'])
            || !is_array($aggregate['payments'])
            || !is_array($aggregate['sources'])
            || !is_array($aggregate['currentRequest'])
            || !($aggregate['verifiedSources'] instanceof CashierV3CheckoutVerifiedSourceSet)) {
            throw self::failure('checkout_draft_aggregate_shape_invalid');
        }
    }

    private static function orderedRows(array $rows): array
    {
        $rows = array_values($rows);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_draft_child_shape_invalid');
            }
        }
        usort($rows, static function (array $left, array $right): int {
            $sort = ((int)($left['sort_no'] ?? 0)) <=> ((int)($right['sort_no'] ?? 0));
            return $sort !== 0
                ? $sort
                : ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
        });
        return $rows;
    }

    private static function positiveInt($value, string $field): int
    {
        $value = self::nonNegativeInt($value, $field);
        if ($value <= 0) {
            throw self::failure('checkout_draft_positive_integer_required', ['field' => $field]);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $field): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw self::failure('checkout_draft_integer_invalid', ['field' => $field]);
            }
            return $value;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX)
                && strcmp($value, (string)PHP_INT_MAX) > 0)) {
            throw self::failure('checkout_draft_integer_invalid', ['field' => $field]);
        }
        return (int)$value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function craftsmenSnapshot($json): array
    {
        try {
            return CashierV3CheckoutCraftsmenSnapshot::decode($json);
        } catch (\Throwable $exception) {
            throw self::failure('checkout_draft_craftsmen_snapshot_invalid');
        }
    }

    private static function cardPurchaseSnapshot($json, string $sourceType): array
    {
        if ($sourceType !== 'card') return [];
        $decoded = is_array($json) ? $json : json_decode((string)$json, true);
        if (!is_array($decoded) || self::isList($decoded)) {
            throw self::failure('checkout_draft_card_purchase_snapshot_invalid');
        }
        return $decoded;
    }

    /**
     * Decode the compact, browser-owned entitlement service intent.
     *
     * Sales lines persist the immutable personnel snapshot and therefore use
     * CashierV3CheckoutCraftsmenSnapshot::decode(). Entitlement lines instead
     * persist only the selection needed by the completion authority; that
     * authority locks staff profiles and creates the historical snapshot later
     * in the same final transaction.
     */
    private static function entitlementCraftsmenSnapshot($json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = is_array($json) ? $json : json_decode((string)$json, true);
        if (!is_array($decoded) || !self::isList($decoded) || count($decoded) > 20) {
            throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
        }
        $result = [];
        $seen = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            // Older hot-reloaded tabs may still carry display/profile fields
            // from the sale-line personnel shape. They are not checkout
            // authority; accept and discard the known fields while retaining
            // only the compact service intent below.
            $allowed = [
                'staffId', 'laborWeight', 'isPointCustomer',
                'craftsmanPerformanceType', 'laborFeeCents', 'personnelSource',
                'performanceAmountCents', 'performanceAmountManual',
                'id', 'employeeId', 'storeId', 'name', 'staffName', 'employeeName',
                'isPrimary', 'sequence', 'projectCount', 'projectCountHalfUnits',
                'positionId', 'positionName', 'performanceIndependent', 'allocationGroupKey',
            ];
            $actual = array_keys($row);
            sort($actual, SORT_STRING);
            $unexpected = array_diff($actual, $allowed);
            if ($unexpected !== []) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            $staffId = self::positiveInt($row['staffId'], 'entitlement.craftsman.staff_id');
            if (isset($seen[$staffId])) {
                throw self::failure('checkout_draft_entitlement_craftsmen_duplicate');
            }
            $seen[$staffId] = true;
            $laborWeight = self::nonNegativeInt(
                $row['laborWeight'],
                'entitlement.craftsman.labor_weight'
            );
            $performanceType = (string)($row['craftsmanPerformanceType'] ?? 'commission_labor');
            if (!in_array($performanceType, ['commission', 'labor', 'commission_labor'], true)) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            if ($laborWeight > 100 || !is_bool($row['isPointCustomer'])) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            $laborFeeCents = self::nonNegativeInt(
                $row['laborFeeCents'] ?? 0,
                'entitlement.craftsman.labor_fee_cents'
            );
            if ($performanceType === 'commission' && $laborFeeCents !== 0) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            $personnelSource = array_key_exists('personnelSource', $row)
                ? trim((string)$row['personnelSource'])
                : 'store';
            if (!in_array($personnelSource, ['store', 'other'], true)) {
                throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
            }
            $positionId = max(0, (int)($row['positionId'] ?? $row['position_id'] ?? 0));
            $explicitGroupKey = trim((string)($row['allocationGroupKey'] ?? ''));
            $performanceIndependent = !empty($row['performanceIndependent'])
                || !empty($row['performance_independent'])
                || strncmp($explicitGroupKey, 'independent:', 12) === 0;
            $groupKey = $performanceIndependent
                ? ($explicitGroupKey !== '' && strncmp($explicitGroupKey, 'independent:', 12) === 0
                    ? $explicitGroupKey
                    : 'independent:' . ($positionId > 0 ? $positionId : $staffId))
                : 'normal';
            $assignment = [
                'staffId' => $staffId,
                'laborWeight' => $laborWeight,
                'isPointCustomer' => $row['isPointCustomer'],
                'craftsmanPerformanceType' => $performanceType,
                'laborFeeCents' => $laborFeeCents,
            ];
            if (array_key_exists('performanceAmountCents', $row)) {
                $amount = self::nonNegativeInt($row['performanceAmountCents'], 'entitlement.craftsman.performance_amount_cents');
                if ($performanceType === 'labor' && ($amount !== 0 || !empty($row['performanceAmountManual']))) {
                    throw self::failure('checkout_draft_entitlement_craftsmen_snapshot_invalid');
                }
                $assignment['performanceAmountCents'] = $amount;
                $assignment['performanceAmountManual'] = !empty($row['performanceAmountManual']);
            }
            // 项目数是完整分配的业务输入，不能在“草稿 JSON → 权威快照”
            // 重建时退化为旧的半个单位字段；否则页面填写的小数会在结账后
            // 被读取端当作缺失并显示默认值 1。
            if (array_key_exists('projectCount', $row)) {
                $assignment['projectCount'] = self::projectCount(
                    $row['projectCount'],
                    'entitlement.craftsman.project_count'
                );
            } elseif (array_key_exists('projectCountHalfUnits', $row)) {
                $assignment['projectCountHalfUnits'] = self::nonNegativeInt(
                    $row['projectCountHalfUnits'],
                    'entitlement.craftsman.project_count_half_units'
                );
            }
            if ($personnelSource === 'other') {
                $assignment['personnelSource'] = 'other';
            }
            if ($positionId > 0) {
                $assignment['positionId'] = $positionId;
                $assignment['positionName'] = trim((string)($row['positionName'] ?? $row['position_name'] ?? ''));
            }
            if ($performanceIndependent) {
                $assignment['performanceIndependent'] = true;
                $assignment['allocationGroupKey'] = $groupKey;
            }
            $result[] = $assignment;
        }
        return $result;
    }

    private static function projectCount($value, string $reason): string
    {
        if (is_bool($value) || is_array($value) || $value === null || is_float($value)) {
            throw self::failure($reason);
        }
        $value = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' ? '0' : $value;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
