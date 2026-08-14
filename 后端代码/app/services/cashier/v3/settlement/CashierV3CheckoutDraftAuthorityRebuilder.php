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
                    'guideSelections' => self::attributionSnapshot($row['guide_selections_json'] ?? null),
                    'salesManagerSelections' => self::attributionSnapshot($row['sales_manager_selections_json'] ?? null),
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
                $saleLines[] = $saleLine;
                continue;
            }
            if ($role !== 'entitlement_service') {
                throw self::failure('checkout_draft_line_role_invalid');
            }
            $entitlementLines[] = [
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
            ];
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
            // 导购轮次是正式结账事实的必需归属维度，不能在工作台草稿
            // 重建结账权威快照时退化为只有员工 ID 的通用归属记录。
            if (array_key_exists('guideRoundNo', $row) || array_key_exists('guide_round_no', $row)) {
                $roundNo = (int)($row['guideRoundNo'] ?? $row['guide_round_no'] ?? 0);
                if ($roundNo < 1 || $roundNo > 3) {
                    throw self::failure('checkout_draft_guide_round_invalid');
                }
                $snapshot['guideRoundNo'] = $roundNo;
            }
            $result[] = $snapshot;
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

    private static function craftsmenSnapshot($json): array
    {
        try {
            return CashierV3CheckoutCraftsmenSnapshot::decode($json);
        } catch (\Throwable $exception) {
            throw self::failure('checkout_draft_craftsmen_snapshot_invalid');
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
