<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\card\CashierV3CardOperationCheckoutSettlementServices;
use app\services\cashier\v3\card\CashierV3CustomCardConfigurationServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use think\facade\Db;

/** Server-only resource discovery and checkout-request preparation. */
final class CashierV3CheckoutPreparationServices
{
    public const DISCOVERY_CONTRACT_VERSION = 'cashier-v3-checkout-preparation-discovery-v1';
    public const PREPARATION_CONTRACT_VERSION = 'cashier-v3-checkout-preparation-v1';

    private const SOURCE_KINDS = ['service_order', 'reservation', 'room'];

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3SaleCatalogServices */
    private $saleCatalog;

    /** @var CashierV3EntitlementProjectionServices */
    private $entitlements;

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CashierMemberSummaryServices */
    private $members;

    /** @var CashierV3CardOperationCheckoutSettlementServices */
    private $cardOperationSettlements;

    /** @var CashierV3CheckoutBusinessSourceSelectionServices */
    private $businessSources;

    /** @var CashierV3CustomCardConfigurationServices */
    private $customCards;

    /** @var CashierV3MemberBalanceProvider */
    private $balances;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3SaleCatalogServices $saleCatalog,
        CashierV3EntitlementProjectionServices $entitlements,
        CashierV3CheckoutRequestRepository $requests = null,
        CashierV3CashierMemberSummaryServices $members = null,
        string $serverIdSecret = '',
        ?CashierV3CardOperationCheckoutSettlementServices $cardOperationSettlements = null,
        ?CashierV3CheckoutBusinessSourceSelectionServices $businessSources = null,
        ?CashierV3CustomCardConfigurationServices $customCards = null,
        ?CashierV3MemberBalanceProvider $balances = null
    ) {
        $this->workspace = $workspace;
        $this->saleCatalog = $saleCatalog;
        $this->entitlements = $entitlements;
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->members = $members ?: new CashierV3CashierMemberSummaryServices();
        $this->serverIdSecret = $serverIdSecret;
        $this->cardOperationSettlements = $cardOperationSettlements
            ?: new CashierV3CardOperationCheckoutSettlementServices();
        $this->businessSources = $businessSources
            ?: new CashierV3CheckoutBusinessSourceSelectionServices();
        $this->customCards = $customCards
            ?: new CashierV3CustomCardConfigurationServices($this->saleCatalog);
        $this->balances = $balances ?: new CashierV3MemberBalanceProvider();
    }

    /** Callable contract consumed by CashierV3CommandGatewayServices. */
    public function discover(array $scope): array
    {
        $operatorScope = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $workspaceId = self::workspaceId($operatorScope, $stateContextId);
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $checkoutSnapshot = is_array($payload['checkoutSnapshot'] ?? null)
            ? $payload['checkoutSnapshot']
            : null;
        if ($checkoutSnapshot !== null) {
            return $this->discoverSnapshotResources($checkoutSnapshot, $operatorScope, $dataScope);
        }
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        if ($requestId !== '') {
            return $this->discoverPreparedRequestResources($requestId, $operatorScope, $dataScope);
        }
        $pack = $this->workspace->discoverCheckoutDraft(
            $workspaceId,
            $stateContextId,
            $operatorScope
        );

        $resources = [];
        $memberId = (int)($pack['draft']['member_id'] ?? 0);
        foreach ($pack['rows'] as $row) {
            $lineRole = (string)($row['line_role'] ?? '');
            if ($lineRole === 'sale') {
                foreach ($this->saleCatalog->discoverStoredLineResources(
                    (array)$row,
                    (int)($row['quantity'] ?? 0),
                    $operatorScope,
                    $dataScope
                ) as $resource) {
                    $resources[] = $resource;
                }
                continue;
            }
            if ($lineRole !== 'entitlement_service') {
                throw self::incomplete('checkout_discovery_line_role_invalid');
            }
            $holderId = (int)($row['holder_id'] ?? 0);
            $detailId = (int)($row['source_detail_id'] ?? 0);
            $holderVersion = (int)($row['source_version'] ?? 0);
            $detailVersion = (int)($row['detail_version'] ?? 0);
            if ($memberId <= 0 || $holderId <= 0 || $detailId <= 0
                || $holderVersion <= 0 || $detailVersion <= 0) {
                throw self::incomplete('checkout_discovery_entitlement_identity_invalid');
            }
            $resources[] = self::resource(
                'member_benefit_pool',
                $detailId,
                $detailVersion,
                'checkout_entitlement_pool:' . $detailId
            );
            $resources[] = self::resource(
                'card_holder',
                $holderId,
                $holderVersion,
                'checkout_card_holder:' . $holderId
            );
        }
        if ($memberId > 0) {
            $memberVersion = $this->shadowVersion('member', $memberId);
            $resources[] = self::resource('member', $memberId, $memberVersion, 'checkout_member');
        }
        $result = [
            'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
            'resources' => $resources,
        ];
        return $result;
    }

    /**
     * Discover resources from the browser snapshot. Display fields never enter
     * the lock set; only server-resolved IDs and versions do.
     */
    private function discoverSnapshotResources(
        array $snapshot,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $memberId = (int)($snapshot['memberId'] ?? 0);
        $lines = array_values((array)($snapshot['lines'] ?? []));
        if (count($lines) < 1 || count($lines) > 1000 || $memberId < 0) {
            throw self::incomplete('checkout_snapshot_shape_invalid');
        }
        $resources = [];
        foreach ($lines as $line) {
            if (!is_array($line)) throw self::incomplete('checkout_snapshot_line_invalid');
            $role = (string)($line['lineRole'] ?? $line['line_role'] ?? '');
            if ($role === 'sale') {
                $itemId = (int)($line['itemId'] ?? $line['catalogItemId'] ?? $line['productId'] ?? 0);
                if ($this->isCustomCardSnapshotLine($line)) {
                    $configuration = is_array($line['customCardConfiguration'] ?? null)
                        ? $line['customCardConfiguration']
                        : (is_array($line['localCustomCardConfiguration'] ?? null)
                            ? $line['localCustomCardConfiguration'] : []);
                    foreach ($this->customCards->discoverCreateResources(
                        $configuration,
                        $operatorScope,
                        $dataScope
                    ) as $resource) {
                        $resources[] = $resource;
                    }
                    continue;
                }
                if ($itemId <= 0) throw self::incomplete('checkout_snapshot_sale_identity_invalid');
                // The final snapshot transaction locks the SKU authority
                // itself. Discovery must not reject a browser snapshot merely
                // because a display-era product active flag changed; product
                // effective status is intentionally outside this contract.
                continue;
            }
            if (!in_array($role, ['entitlement_service', 'entitlement', 'benefit_service'], true)) {
                throw self::incomplete('checkout_snapshot_line_role_invalid');
            }
            $holderId = (int)($line['entitlementInstanceId'] ?? $line['cardHolderId'] ?? 0);
            $detailId = (int)($line['entitlementSourceDetailId'] ?? $line['memberBenefitPoolId'] ?? 0);
            $holderVersion = (int)($line['entitlementSourceVersion'] ?? $line['sourceVersion'] ?? 0);
            $detailVersion = (int)($line['projectVersion'] ?? $line['detailVersion'] ?? 0);
            if ($holderId <= 0 || $detailId <= 0 || $holderVersion <= 0 || $detailVersion <= 0) {
                throw self::incomplete('checkout_snapshot_entitlement_identity_invalid');
            }
            $resources[] = self::resource('member_benefit_pool', $detailId, $detailVersion, 'checkout_snapshot_entitlement_pool:' . $detailId);
            $resources[] = self::resource('card_holder', $holderId, $holderVersion, 'checkout_snapshot_card_holder:' . $holderId);
        }
        if ($memberId > 0) {
            $resources[] = self::resource('member', $memberId, $this->shadowVersion('member', $memberId), 'checkout_snapshot_member');
        }
        return [
            'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    /**
     * Final submission discovery for a browser-origin checkout request.
     *
     * Its line authority was persisted by prepare-checkout, so re-reading the
     * mutable cashier workspace here would reintroduce the stale-projection
     * race that the snapshot flow removes. Product lifecycle flags are not an
     * eligibility check; only current resource versions are assembled for the
     * final inventory/entitlement/balance transaction.
     */
    private function discoverPreparedRequestResources(
        string $requestId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::incomplete('checkout_request_identity_invalid');
        }
        $request = (array)Db::name(ThinkPhpCashierV3CheckoutRequestRepository::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->whereIn('request_status', ['editing', 'ready_for_submit'])
            ->field('request_version,member_id')->find();
        $version = (int)($request['request_version'] ?? 0);
        if ($version <= 0) throw self::incomplete('checkout_request_not_editable');
        $lines = Db::name(ThinkPhpCashierV3CheckoutRequestRepository::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $version)
            ->where('draft_status', 'draft')
            ->field('line_role,source_type,source_id,catalog_sku_id,source_version,entitlement_source_detail_id,project_version')
            ->select()->toArray();
        if ($lines === []) throw self::incomplete('checkout_request_lines_missing');
        $resources = [];
        foreach ($lines as $index => $line) {
            if ((string)($line['line_role'] ?? '') === 'sale') {
                $productId = (int)($line['source_id'] ?? 0);
                $skuId = (int)($line['catalog_sku_id'] ?? 0);
                if ($productId <= 0 || $skuId <= 0) {
                    throw self::incomplete('checkout_request_sale_identity_invalid');
                }
                foreach (['catalog_product' => $productId, 'catalog_sku' => $skuId] as $kind => $id) {
                    $resources[] = $this->saleResource(
                        $kind,
                        $id,
                        $skuId,
                        'checkout_request:' . $kind . ':' . $id,
                        $operatorScope,
                        $dataScope
                    );
                }
                if ((string)($line['source_type'] ?? '') === 'card') {
                    $resources[] = $this->saleResource(
                        'catalog_card_definition',
                        $productId,
                        $skuId,
                        'checkout_request:catalog_card_definition:' . $productId,
                        $operatorScope,
                        $dataScope
                    );
                }
                continue;
            }
            if ((string)($line['line_role'] ?? '') !== 'entitlement_service') {
                throw self::incomplete('checkout_request_line_role_invalid');
            }
            $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            $detailVersion = (int)($line['project_version'] ?? 0);
            if ($detailId <= 0 || $detailVersion <= 0) {
                throw self::incomplete('checkout_request_entitlement_identity_invalid');
            }
            $resources[] = self::resource(
                'member_benefit_pool',
                $detailId,
                $detailVersion,
                'checkout_request_entitlement_pool:' . $detailId
            );
        }
        $memberId = (int)($request['member_id'] ?? 0);
        if ($memberId > 0) {
            $resources[] = self::resource(
                'member',
                $memberId,
                $this->shadowVersion('member', $memberId),
                'checkout_request_member'
            );
        }
        return ['contractVersion' => self::DISCOVERY_CONTRACT_VERSION, 'resources' => $resources];
    }

    private function saleResource(
        string $kind,
        int $resourceId,
        int $skuId,
        string $role,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $version = $this->saleCatalog->readResourceVersion(
            $kind,
            $resourceId,
            $skuId,
            $operatorScope,
            $dataScope
        );
        return [
            'kind' => $kind,
            'id' => (string)$resourceId,
            'expectedVersion' => $version,
            'roles' => [$role],
            'accessMode' => 'read',
            'providerContractVersion' => 'cashier-sale-catalog-resource-v1',
            'authorityFingerprint' => hash('sha256', $kind . '|' . $resourceId . '|' . $version),
        ];
    }

    /**
     * Build and persist one eventless editing checkout request under Gateway's
     * complete lock set. No sale, payment, balance, debt or service fact is
     * written here.
     */
    public function prepareInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierCheckoutPreparation');
        $operatorScope = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $contexts = array_values((array)($scope['contexts'] ?? []));
        $idempotencyKey = trim((string)($scope['idempotency_key'] ?? ''));
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $editingRequestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $editingRequestVersion = self::editingRequestVersion($payload['checkoutRequestVersion'] ?? null);
        if (($editingRequestId === '') !== ($editingRequestVersion === null)) {
            throw self::incomplete('checkout_edit_identity_incomplete');
        }
        $workspaceId = self::workspaceId($operatorScope, $stateContextId);

        $checkoutSnapshot = is_array($payload['checkoutSnapshot'] ?? null)
            ? $payload['checkoutSnapshot']
            : null;
        if ($checkoutSnapshot !== null) {
            // Resolve each sale identity directly against the locked catalog
            // authority. This path writes only checkout_request draft rows;
            // it never replays the browser snapshot into cashier_workspace.
            [$authority, $publicDraft, $storedDraft, $storedRows, $debtAmountCents]
                = $this->authorityFromCheckoutSnapshot(
                    $checkoutSnapshot,
                    $idempotencyKey,
                    $contexts,
                    $workspaceId,
                    $stateContextId,
                    $operatorScope,
                    $dataScope
                );
        } else {
            $authority = $this->workspace->checkoutSaleSourceSetInTx(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                function (array $row) use ($contexts, $operatorScope, $dataScope): array {
                    return $this->saleCatalog->checkoutSourceLineAfterGatewayLocksInTx(
                        $row,
                        $contexts,
                        $operatorScope,
                        $dataScope
                    );
                }
            );
            $publicDraft = (array)($authority['publicDraft'] ?? []);
            $storedDraft = (array)($authority['storedDraft'] ?? []);
            $storedRows = array_values((array)($authority['storedRows'] ?? []));
            $debtAmountCents = $this->saleDebtAmountCents((array)($authority['lines'] ?? []));
        }
        // 这里仅创建可编辑的结账请求。权益余次、余额和服务资源统一由
        // prepare-checkout-submission 在第三步确认时重读并校验，不能在
        // 点击“立即结账”时提前阻断草稿。

        $snapshot = $this->authoritySnapshot(
            $authority,
            $publicDraft,
            $contexts,
            $operatorScope,
            $dataScope,
            $workspaceId,
            $stateContextId,
            $debtAmountCents
        );
        $command = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
            'idempotencyKey' => $idempotencyKey,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
        ];
        if ($editingRequestId !== '') {
            $command['requestId'] = $editingRequestId;
            $command['expectedVersion'] = $editingRequestVersion;
        }
        $current = $this->requests->lockCurrentForKernelInTx(
            $editingRequestId,
            $idempotencyKey,
            $operatorScope,
            $dataScope
        );
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft(
            $command,
            $snapshot,
            $current,
            $this->serverIdSecret()
        );
        $verifiedSources = $this->verifiedNavigationSources(
            $contexts,
            $operatorScope,
            $dataScope
        );
        $persisted = $this->requests->persistKernelPlanInTx(
            $kernel,
            $verifiedSources,
            $operatorScope,
            $dataScope
        );
        // The browser snapshot is the sole source for a newly opened local
        // checkout. Persist its customer-source selection in this same
        // preparation transaction, rather than replaying a mutable UI action
        // after the checkout request exists. The selection is attribution
        // metadata and deliberately does not validate the live source catalog.
        if ($checkoutSnapshot !== null) {
            $this->businessSources->captureSaleSnapshotInTx(
                (string)$kernel['requestId'],
                $dataScope->tenantId(),
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                (array)($checkoutSnapshot['source'] ?? [])
            );
        }
        // A resumed hang is only an editable cart draft. It never enters
        // source-document resolution; the internal reference lets final
        // submission lock and remove that draft only after success.
        $this->requests->bindResumedHangOrderInTx(
            (string)$kernel['requestId'],
            (int)$kernel['requestVersion'],
            trim((string)($storedDraft['resumed_hang_order_id'] ?? '')),
            $operatorScope,
            $dataScope
        );
        $cardOperationBinding = $this->cardOperationSettlements->bindPreparedCheckoutInTx(
            $storedRows,
            (string)$kernel['requestId'],
            $operatorScope,
            $dataScope
        );

        $result = [
            'contractVersion' => self::PREPARATION_CONTRACT_VERSION,
            'preparationRequestId' => $idempotencyKey,
            'checkoutRequestId' => (string)$kernel['requestId'],
            'checkoutRequestVersion' => (int)$kernel['requestVersion'],
            'requestStatus' => (string)$kernel['requestStatus'],
            'composition' => (string)$kernel['composition'],
            'totals' => (array)$kernel['totals'],
            'authoritySnapshotFingerprint' => (string)$kernel['authoritySnapshotFingerprint'],
            'aggregateFingerprint' => (string)$kernel['aggregateFingerprint'],
            'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
            'eventless' => true,
            'businessEffects' => (array)$kernel['businessEffects'],
            'cardOperationCheckout' => $cardOperationBinding,
        ];
        return $result;
    }

    private function authorityFromCheckoutSnapshot(
        array $snapshot,
        string $idempotencyKey,
        array $contexts,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $lines = array_values((array)($snapshot['lines'] ?? []));
        if ($lines === []) {
            throw self::incomplete('checkout_snapshot_lines_empty');
        }
        $saleLines = [];
        $entitlementLines = [];
        foreach ($lines as $index => $line) {
            $role = (string)($line['lineRole'] ?? '');
            if ($role === 'sale') {
                $itemId = (int)($line['itemId'] ?? 0);
                $quantity = (int)($line['quantity'] ?? 0);
                $customCard = $this->isCustomCardSnapshotLine($line);
                if ((!$customCard && $itemId <= 0) || $quantity <= 0) {
                    throw self::incomplete('checkout_snapshot_sale_identity_invalid');
                }
                if ($customCard) {
                    $configuration = is_array($line['customCardConfiguration'] ?? null)
                        ? $line['customCardConfiguration']
                        : (is_array($line['localCustomCardConfiguration'] ?? null)
                            ? $line['localCustomCardConfiguration'] : []);
                    $server = $this->customCards->createSaleLineAfterGatewayLocksInTx(
                        $configuration,
                        $workspaceId,
                        $stateContextId,
                        $idempotencyKey . ':snapshot:' . $index,
                        $contexts,
                        $operatorScope,
                        $dataScope
                    );
                } else {
                    $server = $this->saleCatalog->selectDraftSaleLineAfterGatewayLocksInTx(
                        $itemId,
                        $idempotencyKey . ':snapshot:' . $index,
                        $operatorScope,
                        $dataScope
                    );
                }
                $unit = (int)($server['unit_price_cents'] ?? 0);
                $originalUnit = max($unit, (int)($server['original_unit_price_cents'] ?? $unit));
                $snapshotAmount = array_key_exists('lineAmountCents', $line)
                    ? $this->snapshotCents($line['lineAmountCents'])
                    : $this->snapshotMoneyCents(
                        $line['finalAmount'] ?? $line['amount'] ?? $line['lineAmount'] ?? null
                    );
                $snapshotOriginal = array_key_exists('originalLineAmountCents', $line)
                    ? $this->snapshotCents($line['originalLineAmountCents'])
                    : $this->snapshotMoneyCents(
                        $line['originalAmount'] ?? $line['originalLineAmount'] ?? null
                    );
                $lineAmount = $snapshotAmount !== null ? $snapshotAmount : $unit * $quantity;
                $originalAmount = max(
                    $lineAmount,
                    $snapshotOriginal !== null ? $snapshotOriginal : $originalUnit * $quantity
                );
                $coupon = is_array($line['coupon'] ?? null) ? $line['coupon'] : [];
                $saleLines[] = [
                    'lineId' => (string)($line['lineId'] ?? 'snapshot-' . $index),
                    'lineAmountCents' => $lineAmount,
                    'originalLineAmountCents' => $originalAmount,
                    'productId' => (int)($server['catalog_product_id'] ?? 0),
                    'skuId' => (int)($server['catalog_sku_id'] ?? $itemId),
                    'productVersion' => (int)($server['source_version'] ?? 0),
                    'skuUnique' => (string)($server['display_snapshot']['skuUnique'] ?? ''),
                    'kindCode' => in_array((string)($server['kind_code'] ?? ''), ['count_card', 'card_package', 'custom_card'], true)
                        ? 'card'
                        : ((string)($line['kindCode'] ?? $server['kind_code'] ?? '') === 'project'
                            || (int)($server['catalog_product_type'] ?? 0) === 6 ? 'project' : 'product'),
                    'nameSnapshot' => (string)($server['display_snapshot']['name'] ?? ''),
                    'categoryIdSnapshot' => 0,
                    'categoryNameSnapshot' => '',
                    'quantity' => $customCard ? 1 : $quantity,
                    'couponUserId' => (int)($line['couponUserId'] ?? $coupon['id'] ?? $line['couponId'] ?? 0),
                    'couponNameSnapshot' => (string)($line['couponNameSnapshot'] ?? $coupon['name'] ?? $line['couponSummary'] ?? ''),
                    'couponDiscountCents' => (int)($line['couponDiscountCents'] ?? $coupon['discountAmountCents'] ?? 0),
                    'debtAmountCents' => (int)($line['debtAmountCents'] ?? 0),
                    'serviceObject' => (string)($line['serviceObject'] ?? ''),
                    'friendCountsAsCustomer' => !array_key_exists('friendCountsAsCustomer', $line) || !empty($line['friendCountsAsCustomer']),
                    'craftsmen' => is_array($line['craftsmen'] ?? null) ? $line['craftsmen'] : [],
                    'salespeople' => is_array($line['salespeople'] ?? null) ? $line['salespeople'] : [],
                    'guideSelections' => is_array($line['guideSelections'] ?? null) ? $line['guideSelections'] : [],
                    'salesManagerSelections' => is_array($line['salesManagerSelections'] ?? null) ? $line['salesManagerSelections'] : [],
                    'isExperience' => !empty($line['isExperience']) ? 1 : 0,
                    'isPresale' => !empty($line['isPresale']) ? 1 : 0,
                    'inventoryOutboundRequired' => array_key_exists('inventoryOutboundRequired', $line) && empty($line['inventoryOutboundRequired']) ? 0 : 1,
                    'configuredCostCents' => (int)($server['configured_cost_cents'] ?? 0),
                    'priceChangeReason' => '',
                    'priceChangedBy' => 0,
                    'priceChangedByNameSnapshot' => '',
                    'priceChangedAt' => 0,
                ];
                continue;
            }
            if ($role === 'entitlement_service') {
                $entitlementLines[] = [
                    'id' => (string)($line['lineId'] ?? 'snapshot-' . $index),
                    'lineRole' => 'entitlement_service',
                    'entitlementInstanceId' => (int)($line['entitlementInstanceId'] ?? 0),
                    'entitlementSourceDetailId' => (int)($line['entitlementSourceDetailId'] ?? 0),
                    'entitlementSourceVersion' => (int)($line['entitlementSourceVersion'] ?? 0),
                    'projectId' => (int)($line['projectId'] ?? 0),
                    'projectVersion' => (int)($line['projectVersion'] ?? 0),
                    'quantity' => (int)($line['quantity'] ?? 0),
                    'actualAmount' => (string)($line['actualAmount'] ?? $line['actualEntitlementAmount'] ?? '0'),
                    'entitlementSourceKind' => (string)($line['entitlementSourceKind'] ?? 'unknown'),
                    'entitlementSourceName' => (string)($line['entitlementSourceName'] ?? ''),
                    'fullCardNo' => (string)($line['fullCardNo'] ?? ''),
                    'name' => (string)($line['name'] ?? ($line['displaySnapshot']['name'] ?? '')),
                ];
                continue;
            }
            throw self::incomplete('checkout_snapshot_line_role_invalid');
        }
        $publicDraft = [
            'memberId' => (int)($snapshot['memberId'] ?? 0),
            'lines' => $entitlementLines,
        ];
        $storedDraft = [
            'order_note' => (string)($snapshot['orderNote'] ?? ''),
            'supplement_enabled' => !empty($snapshot['supplement']['enabled']) ? 1 : 0,
            'supplement_reason' => (string)($snapshot['supplement']['reason'] ?? ''),
        ];
        $authority = [
            'lines' => $saleLines,
            'storedDraft' => $storedDraft,
            'publicDraft' => $publicDraft,
            'storedRows' => [],
            'browserSnapshot' => $snapshot,
        ];
        return [$authority, $publicDraft, $storedDraft, [], $this->saleDebtAmountCents($saleLines)];
    }

    private function isCustomCardSnapshotLine(array $line): bool
    {
        $kind = (string)($line['kindCode'] ?? $line['sourceKind'] ?? '');
        return $kind === 'custom_card'
            || is_array($line['customCardConfiguration'] ?? null)
            || is_array($line['localCustomCardConfiguration'] ?? null);
    }

    private function authoritySnapshot(
        array $authority,
        array $publicDraft,
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $workspaceId,
        string $stateContextId,
        int $debtAmountCents
    ): array {
        $dimensions = $this->dimensions($operatorScope, $dataScope);
        $memberId = (int)($publicDraft['memberId'] ?? 0);
        $memberName = '';
        if ($memberId > 0) {
            $member = $this->members->read($memberId, $operatorScope->storeId());
            $memberName = (string)($member['name'] ?? '');
        }
        $saleLines = [];
        foreach ((array)($authority['lines'] ?? []) as $line) {
            $saleLines[] = $this->saleSnapshotLine((array)$line);
        }
        $salesAmountCents = array_sum(array_column($saleLines, 'saleAmountCents'));
        if ($debtAmountCents > 0
            && ($memberId <= 0
                || $salesAmountCents <= 0
                || $debtAmountCents > $salesAmountCents)) {
            throw self::incomplete('checkout_debt_intent_invalid');
        }
        $entitlementLines = [];
        foreach ((array)($publicDraft['lines'] ?? []) as $line) {
            if ((string)($line['lineRole'] ?? '') === 'entitlement_service') {
                $entitlementLines[] = $this->entitlementSnapshotLine((array)$line);
            }
        }
        $now = time();
        $browserSnapshot = is_array($authority['browserSnapshot'] ?? null)
            ? $authority['browserSnapshot'] : [];
        $supplementEnabled = (int)($authority['storedDraft']['supplement_enabled'] ?? 0) === 1;
        $supplementBusinessDate = (string)($authority['storedDraft']['supplement_business_date'] ?? '');
        if ($browserSnapshot !== []) {
            $businessDate = trim((string)($browserSnapshot['businessDate'] ?? ''));
            if ($businessDate !== '') {
                $supplementEnabled = true;
                $supplementBusinessDate = $businessDate;
            }
        }
        if ($supplementEnabled
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $supplementBusinessDate) !== 1) {
            throw self::incomplete('checkout_supplement_business_date_invalid');
        }
        $balance = $this->snapshotBalanceDeduction($browserSnapshot, $memberId, $operatorScope, $dataScope);
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => $this->contextVersion($contexts, 'cashier_workspace', $workspaceId),
            'authoritySnapshotFingerprint' => '',
            'tenantId' => $dataScope->tenantId(),
            'organizationId' => $operatorScope->organizationId(),
            'organizationPath' => $dimensions['organizationPath'],
            'organizationName' => $dimensions['organizationName'],
            'storeId' => $operatorScope->storeId(),
            'storeName' => $dimensions['storeName'],
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
            'memberId' => $memberId,
            'memberName' => $memberName,
            'operatorId' => $operatorScope->operatorId(),
            'operatorName' => $dimensions['operatorName'],
            'businessDate' => $supplementEnabled ? $supplementBusinessDate : date('Y-m-d', $now),
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
            'orderNote' => $browserSnapshot !== []
                ? (string)($browserSnapshot['orderNote'] ?? '')
                : (string)($authority['storedDraft']['order_note'] ?? ''),
            'supplement' => [
                'enabled' => $supplementEnabled,
                'reason' => (string)($authority['storedDraft']['supplement_reason'] ?? ''),
                'operatorId' => (int)($authority['storedDraft']['supplement_operator_id'] ?? 0),
                'operatorNameSnapshot' => (string)($authority['storedDraft']['supplement_operator_name_snapshot'] ?? ''),
                'operatedAt' => (int)($authority['storedDraft']['supplement_operated_at'] ?? 0),
            ],
            'sourceDocument' => $this->sourceDocument($contexts, $workspaceId),
            'saleLines' => $saleLines,
            'entitlementLines' => $entitlementLines,
            'paymentDetails' => $browserSnapshot === []
                ? []
                : $this->snapshotPaymentDetails($browserSnapshot, $now),
            'balanceDeduction' => $balance,
            'debt' => [
                'authorityKey' => $debtAmountCents > 0
                    ? CashierV3CheckoutDebtAuthorityServices::authorityKey($operatorScope->storeId())
                    : '',
                'policyVersion' => $debtAmountCents > 0
                    ? CashierV3CheckoutDebtAuthorityServices::POLICY_VERSION
                    : 0,
                'amountCents' => $debtAmountCents,
            ],
        ];
        $snapshot['authoritySnapshotFingerprint'] =
            CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        return $snapshot;
    }

    private function assertEntitlementRowsAfterGatewayLocks(
        array $storedRows,
        array $contexts,
        CashierV3OperatorScope $operatorScope
    ): void {
        $aggregate = [];
        foreach ($storedRows as $row) {
            if ((string)($row['line_role'] ?? '') !== 'entitlement_service') {
                continue;
            }
            $key = (int)($row['holder_id'] ?? 0) . ':' . (int)($row['source_detail_id'] ?? 0);
            $aggregate[$key] = (int)($aggregate[$key] ?? 0) + (int)($row['quantity'] ?? 0);
        }
        foreach ($storedRows as $row) {
            if ((string)($row['line_role'] ?? '') !== 'entitlement_service') {
                continue;
            }
            $key = (int)($row['holder_id'] ?? 0) . ':' . (int)($row['source_detail_id'] ?? 0);
            $this->entitlements->assertDraftLineQuantityInTx(
                (array)$row,
                (int)($row['quantity'] ?? 0),
                (int)($aggregate[$key] ?? 0),
                $contexts,
                $operatorScope
            );
        }
    }

    private function saleSnapshotLine(array $line): array
    {
        $kindCode = (string)($line['kindCode'] ?? '');
        $sourceType = $kindCode === 'product'
            ? 'product'
            : ($kindCode === 'project' ? 'project' : 'card');
        $original = (int)($line['originalLineAmountCents'] ?? -1);
        $sale = (int)($line['lineAmountCents'] ?? -1);
        if ($original < 0 || $sale < 0) {
            throw self::incomplete('checkout_sale_amount_invalid');
        }
        // 手工改价可以高于目录原价；结算方程以成交价作为本单原价，
        // 不产生负折扣，实际收款仍以草稿中的成交价为准。
        if ($sale > $original) {
            $original = $sale;
        }
        try {
            $craftsmen = $sourceType === 'project'
                ? CashierV3CheckoutCraftsmenSnapshot::normalize($line['craftsmen'] ?? [])
                : [];
        } catch (\Throwable $exception) {
            throw self::incomplete('checkout_sale_craftsmen_snapshot_invalid');
        }
        $result = [
            'authorityKey' => 'sale:' . (string)($line['lineId'] ?? ''),
            'saleClassification' => 'formal_sale',
            'sourceType' => $sourceType,
            'sourceId' => (int)($line['productId'] ?? 0),
            'catalogSkuId' => (int)($line['skuId'] ?? 0),
            'sourceVersion' => (int)($line['productVersion'] ?? 0),
            'quantity' => (int)($line['quantity'] ?? 0),
            'originalAmountCents' => $original,
            'discountAmountCents' => $original - $sale,
            'couponUserId' => (int)($line['couponUserId'] ?? 0),
            'couponNameSnapshot' => (string)($line['couponNameSnapshot'] ?? ''),
            'couponDiscountCents' => (int)($line['couponDiscountCents'] ?? 0),
            'saleAmountCents' => $sale,
            'debtAmountCents' => $this->saleLineDebtAmountCents($line, $sale),
            'configuredCostCents' => (int)($line['configuredCostCents'] ?? 0),
            'priceChangeReason' => (string)($line['priceChangeReason'] ?? ''),
            'priceChangedBy' => (int)($line['priceChangedBy'] ?? 0),
            'priceChangedByNameSnapshot' => (string)($line['priceChangedByNameSnapshot'] ?? ''),
            'priceChangedAt' => (int)($line['priceChangedAt'] ?? 0),
            'sourceNameSnapshot' => (string)($line['nameSnapshot'] ?? ''),
            'sourceCodeSnapshot' => (string)($line['skuUnique'] ?? ''),
            'categoryIdSnapshot' => (int)($line['categoryIdSnapshot'] ?? 0),
            'categoryNameSnapshot' => (string)($line['categoryNameSnapshot'] ?? ''),
            'serviceObject' => (string)($line['serviceObject'] ?? ''),
            'friendCountsAsCustomer' => !array_key_exists('friendCountsAsCustomer', $line)
                || !empty($line['friendCountsAsCustomer']) ? 1 : 0,
            'craftsmen' => $craftsmen,
            'salespeople' => is_array($line['salespeople'] ?? null)
                ? array_values($line['salespeople']) : [],
            'guideSelections' => is_array($line['guideSelections'] ?? null)
                ? array_values($line['guideSelections'])
                : [],
            'salesManagerSelections' => is_array($line['salesManagerSelections'] ?? null)
                ? array_values($line['salesManagerSelections'])
                : [],
            'isExperience' => !empty($line['isExperience']) ? 1 : 0,
            // These flags are part of every persisted line snapshot. Keep the
            // ordinary sale defaults explicit so strict repository validation
            // cannot reject a line that did not use the presale/outbound UI.
            'isPresale' => !empty($line['isPresale']) ? 1 : 0,
            'inventoryOutboundRequired' => array_key_exists('inventoryOutboundRequired', $line)
                ? (empty($line['inventoryOutboundRequired']) ? 0 : 1)
                : 1,
        ];
        $manualLaborFeeCents = array_key_exists('laborManualFeeCents', $line)
            ? ($line['laborManualFeeCents'] === null ? null : (int)$line['laborManualFeeCents'])
            : (array_key_exists('manualLaborFeeCents', $line) && $line['manualLaborFeeCents'] !== null
                ? (int)$line['manualLaborFeeCents']
                : null);
        if ($manualLaborFeeCents !== null) {
            $result['manualLaborFeeCents'] = $manualLaborFeeCents;
        }
        return $result;
    }

    private function saleDebtAmountCents(array $saleLines): int
    {
        $total = 0;
        foreach ($saleLines as $line) {
            $sale = (int)($line['lineAmountCents'] ?? -1);
            $debt = $this->saleLineDebtAmountCents((array)$line, $sale);
            if ($total > PHP_INT_MAX - $debt) {
                throw self::incomplete('checkout_line_debt_total_overflow');
            }
            $total += $debt;
        }
        return $total;
    }

    /** Convert browser-local payment lines into the immutable request draft. */
    private function snapshotPaymentDetails(array $snapshot, int $occurredAt): array
    {
        $payment = is_array($snapshot['payment'] ?? null) ? $snapshot['payment'] : [];
        $lines = array_values((array)($payment['selectedLines'] ?? []));
        $details = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw self::incomplete('checkout_snapshot_payment_line_invalid');
            }
            $method = trim((string)($line['method'] ?? $line['paymentMethod'] ?? ''));
            $amount = array_key_exists('amountCents', $line)
                ? $this->snapshotCents($line['amountCents'])
                : $this->snapshotMoneyCents($line['amount'] ?? null);
            if ($method === '' || $amount === null || $amount < 0) {
                throw self::incomplete('checkout_snapshot_payment_line_invalid');
            }
            $identity = trim((string)($line['id'] ?? $line['paymentLineId'] ?? ''));
            if ($identity === '' || strlen($identity) > 64) {
                $identity = 'line-' . ($index + 1);
            }
            $details[] = [
                'paymentAuthorityKey' => 'snapshot-payment:' . substr(
                    hash('sha256', $identity . '|' . $method . '|' . $index),
                    0,
                    48
                ),
                'method' => $method,
                'amountCents' => $amount,
                'businessTime' => $occurredAt,
                'externalTransactionNo' => trim((string)($line['externalTransactionNo'] ?? '')),
                'remark' => trim((string)($line['remark'] ?? '')),
            ];
        }
        return $details;
    }

    private function snapshotBalanceDeduction(
        array $snapshot,
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $amount = $this->snapshotMoneyCents($snapshot['balancePaymentAmount'] ?? null);
        if ($amount === null || $amount === 0) {
            return ['authorityKey' => '', 'accountId' => '', 'accountVersion' => 0, 'amountCents' => 0];
        }
        if ($memberId <= 0) throw self::incomplete('checkout_snapshot_balance_member_invalid');
        try {
            $authority = $this->balances->readSnapshot($memberId, $operatorScope, $dataScope);
        } catch (\Throwable $exception) {
            throw self::incomplete('checkout_snapshot_balance_authority_unavailable');
        }
        $available = (int)($authority['totalCents'] ?? 0);
        if ($amount > $available) throw self::incomplete('checkout_snapshot_balance_insufficient');
        return [
            'authorityKey' => (string)($authority['authorityKey'] ?? ''),
            'accountId' => (string)($authority['accountId'] ?? ''),
            'accountVersion' => (int)($authority['accountVersion'] ?? 0),
            'amountCents' => $amount,
        ];
    }

    /** Convert a browser Yuan value without introducing floating-point cents. */
    private function snapshotMoneyCents($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= intdiv(PHP_INT_MAX, 100) ? $value * 100 : null;
        }
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', trim($value)) !== 1) {
            return null;
        }
        $parts = explode('.', trim($value), 2);
        $raw = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        if ($raw === '') $raw = '0';
        if (strlen($raw) > 12 || (string)(int)$raw !== $raw) return null;
        return (int)$raw;
    }

    /** Read an explicitly named integer-cents browser field. */
    private function snapshotCents($value): ?int
    {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', trim($value)) !== 1) {
            return null;
        }
        $raw = trim($value);
        return strlen($raw) <= 12 && (string)(int)$raw === $raw ? (int)$raw : null;
    }

    private function saleLineDebtAmountCents(array $line, int $saleAmountCents): int
    {
        $debt = (int)($line['debtAmountCents'] ?? 0);
        if ($debt < 0 || $saleAmountCents < 0 || $debt > $saleAmountCents) {
            throw self::incomplete('checkout_line_debt_invalid');
        }
        return $debt;
    }

    private function entitlementSnapshotLine(array $line): array
    {
        // The cart line is an editing projection and may have been created
        // before the legacy entitlement shadow was synchronized. Rebind the
        // final checkout snapshot to the latest shadow versions; submit still
        // locks the holder/detail and verifies remaining authority in the same
        // transaction, so this does not bypass entitlement validation.
        $holderId = (int)($line['entitlementInstanceId'] ?? 0);
        $detailId = (int)($line['entitlementSourceDetailId'] ?? 0);
        $versions = [];
        if ($holderId > 0 || $detailId > 0) {
            $rows = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
                ->where(function ($query) use ($holderId, $detailId) {
                    if ($holderId > 0) {
                        $query->where(function ($nested) use ($holderId) {
                            $nested->where('resource_kind', 'card_holder')->where('resource_id', (string)$holderId);
                        });
                    }
                    if ($detailId > 0) {
                        $query->whereOr(function ($nested) use ($detailId) {
                            $nested->where('resource_kind', 'member_benefit_pool')->where('resource_id', (string)$detailId);
                        });
                    }
                })
                ->select()->toArray();
            foreach ($rows as $row) {
                $versions[(string)$row['resource_kind'] . ':' . (string)$row['resource_id']]
                    = (int)($row['current_version'] ?? 0);
            }
        }
        return [
            'authorityKey' => (string)($line['id'] ?? ''),
            'sourceKind' => (string)($line['entitlementSourceKind'] ?? ''),
            'holderId' => $holderId,
            'entitlementSourceDetailId' => $detailId,
            'sourceVersion' => $versions['card_holder:' . $holderId]
                ?? (int)($line['entitlementSourceVersion'] ?? 0),
            'projectId' => (int)($line['projectId'] ?? 0),
            'projectVersion' => $versions['member_benefit_pool:' . $detailId]
                ?? (int)($line['projectVersion'] ?? 0),
            'quantity' => (int)($line['quantity'] ?? 0),
            'actualEntitlementAmountCents' => $this->moneyToCents($line['actualAmount'] ?? null),
            'sourceNameSnapshot' => (string)($line['entitlementSourceName'] ?? ''),
            'sourceCodeSnapshot' => (string)($line['fullCardNo'] ?? ''),
            'projectNameSnapshot' => (string)($line['name'] ?? ''),
            'projectCategoryIdSnapshot' => 0,
            'projectCategoryNameSnapshot' => '',
        ];
    }

    private function verifiedNavigationSources(
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): CashierV3CheckoutVerifiedSourceSet {
        $rows = [];
        foreach ($contexts as $context) {
            $kind = (string)($context['kind'] ?? '');
            if (!in_array($kind, self::SOURCE_KINDS, true)) {
                continue;
            }
            $sourceId = (string)($context['id'] ?? '');
            $rows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $operatorScope->storeId(),
                'kind' => $kind,
                'id' => $sourceId,
                'sourceVersion' => $this->contextVersion($contexts, $kind, $sourceId),
                'role' => $kind,
            ];
        }
        return CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $rows
        );
    }

    private function dimensions(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $storeName = trim((string)Db::name('system_store')
            ->where('id', $operatorScope->storeId())
            ->value('name'));
        $profile = $dataScope->operatorProfile();
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($profile[$field] ?? ''));
            if ($operatorName !== '') {
                break;
            }
        }
        $organizationId = (int)$operatorScope->organizationId();
        $ids = [];
        $organizationName = '';
        $seen = [];
        $currentId = $organizationId;
        while ($currentId > 0 && count($ids) < 64) {
            if (isset($seen[$currentId])) {
                throw self::incomplete('checkout_organization_cycle');
            }
            $seen[$currentId] = true;
            $node = Db::name('organization')
                ->where('id', $currentId)
                ->where('is_del', 0)
                ->field('id,pid,name')
                ->find();
            if (!$node) {
                throw self::incomplete('checkout_organization_missing');
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                throw self::incomplete('checkout_organization_name_missing');
            }
            if ($organizationName === '') {
                $organizationName = $name;
            }
            $ids[] = (int)$node['id'];
            $currentId = (int)($node['pid'] ?? 0);
        }
        if ($storeName === '' || $operatorName === '' || !$ids || $currentId > 0) {
            throw self::incomplete('checkout_dimension_snapshot_incomplete');
        }
        return [
            'storeName' => $storeName,
            'operatorName' => $operatorName,
            'organizationName' => $organizationName,
            'organizationPath' => '/' . implode('/', array_reverse($ids)) . '/',
        ];
    }

    private function sourceDocument(array $contexts, string $workspaceId): array
    {
        foreach (self::SOURCE_KINDS as $kind) {
            foreach ($contexts as $context) {
                if ((string)($context['kind'] ?? '') === $kind) {
                    $id = (string)($context['id'] ?? '');
                    return ['type' => $kind, 'id' => $id, 'no' => $id];
                }
            }
        }
        return ['type' => 'cashier_workspace', 'id' => $workspaceId, 'no' => $workspaceId];
    }

    private function contextVersion(array $contexts, string $kind, string $id): int
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === $kind
                && (string)($context['id'] ?? '') === $id) {
                $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
                if ($version > 0) {
                    return $version;
                }
            }
        }
        throw self::incomplete('checkout_workspace_version_missing');
    }

    private function moneyToCents($amount): int
    {
        if (!is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw self::incomplete('checkout_entitlement_actual_amount_invalid');
        }
        $parts = explode('.', $amount, 2);
        $raw = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        $raw = $raw === '' ? '0' : $raw;
        if (strlen($raw) > 12 || (string)(int)$raw !== $raw) {
            throw self::incomplete('checkout_entitlement_actual_amount_overflow');
        }
        return (int)$raw;
    }

    private static function debtIntentAmountCents($value): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0 || $value > 100000000000) {
            throw self::incomplete('checkout_debt_intent_amount_invalid');
        }
        return $value;
    }

    private static function editingRequestVersion($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0 || $value > PHP_INT_MAX) {
            throw self::incomplete('checkout_edit_version_invalid');
        }
        return $value;
    }

    private function serverIdSecret(): string
    {
        $secret = $this->serverIdSecret;
        if ($secret === '' && function_exists('config')) {
            $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        }
        if (strlen($secret) < 32) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账请求签名服务尚未配置，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_namespace_secret_missing']
            );
        }
        return $secret;
    }

    private function shadowVersion(string $kind, int $resourceId): int
    {
        $row = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', (string)$resourceId)
            ->field('current_version')
            ->find();
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0) {
            throw self::incomplete('checkout_discovery_member_version_missing');
        }
        return $version;
    }

    private static function workspaceId(
        CashierV3OperatorScope $operatorScope,
        string $stateContextId
    ): string {
        if ($stateContextId === '') {
            throw self::incomplete('checkout_state_context_missing');
        }
        return \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
    }

    private static function resource(string $kind, int $id, int $version, string $role): array
    {
        return [
            'kind' => $kind,
            'id' => (string)$id,
            'expectedVersion' => $version,
            'roles' => [$role],
            'accessMode' => 'read',
            'providerContractVersion' => 'cashier-entitlement-projection-resource-v1',
            'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
        ];
    }

    private static function incomplete(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账所需的商品或权益资料不完整，请刷新购物车后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
