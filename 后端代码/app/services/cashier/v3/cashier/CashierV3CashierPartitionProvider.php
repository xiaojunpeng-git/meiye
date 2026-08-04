<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use think\facade\Log;

/** 收银工作台根分区：会员、门店目录和同一份权威购物车草稿。 */
final class CashierV3CashierPartitionProvider implements CashierV3RootPartitionProvider
{
    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3SaleCatalogServices */
    private $catalog;

    /** @var CashierV3CashierMemberSummaryServices */
    private $members;

    /** @var CashierV3CheckoutProjectionServices */
    private $checkoutProjection;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3SaleCatalogServices $catalog,
        CashierV3CashierMemberSummaryServices $members = null,
        CashierV3CheckoutProjectionServices $checkoutProjection = null
    ) {
        $this->workspace = $workspace;
        $this->catalog = $catalog;
        $this->members = $members ?: new CashierV3CashierMemberSummaryServices();
        $this->checkoutProjection = $checkoutProjection ?: new CashierV3CheckoutProjectionServices();
    }

    public function partitionKey(): string
    {
        return 'cashier';
    }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        try {
            $workspaceId = sprintf(
                'ws:%d:%d:%s',
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                $stateContextId
            );
            $draft = $this->workspace->readDraftOrSyntheticGuest(
                $workspaceId,
                $stateContextId,
                $operatorScope
            );
            $customerMode = (string)$draft['customerMode'];
            $memberId = (int)$draft['memberId'];
            $member = $customerMode === 'member'
                ? $this->members->read($memberId, $operatorScope->storeId())
                : null;
            // 单条历史商品资料不完整时，目录本身必须明确标记不可用；不能让它
            // 阻断门店、账号、房间和其他已授权菜单的整份根投影。后续选品命令
            // 仍会重新从权威目录读取并校验，因而不会把不完整商品带进结账。
            try {
                $catalog = $this->catalog->catalog($operatorScope, $dataScope);
            } catch (CashierV3CommandException $exception) {
                if ($exception->getResultCode() !== CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE) {
                    throw $exception;
                }
                $catalog = $this->unavailableCatalog($exception);
            }
            $checkout = $this->checkoutProjection->readCurrent(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $dataScope
            );
            // 结账草稿及其服务端冻结来源是后续编辑／提交的可信版本上下文。
            // 仅把投影中已完整校验过的 commandContexts 公开给当前 stateContext；
            // 缺失任一版本时前端仍会 fail-closed，绝不回退信任页面快照。
            $publicVersions = [];
            if (is_array($checkout) && is_array($checkout['commandContexts'] ?? null)) {
                foreach ($checkout['commandContexts'] as $context) {
                    $kind = trim((string)($context['kind'] ?? ''));
                    $id = trim((string)($context['id'] ?? ''));
                    $version = (int)($context['expectedVersion'] ?? 0);
                    if ($kind !== '' && $id !== '' && $version > 0) {
                        $publicVersions[] = [
                            'kind' => $kind,
                            'id' => $id,
                            'version' => $version,
                        ];
                    }
                }
            }
            return [
                'ready' => true,
                'payload' => [
                    'contractVersion' => 'cashier-root-partition-v1',
                    'customerMode' => $customerMode,
                    'member' => $member,
                    'catalog' => $catalog,
                    'cart' => [
                        'contractVersion' => CashierV3SaleCatalogServices::CHECKOUT_SOURCE_CONTRACT_VERSION,
                        'lines' => $draft['lines'],
                        'summary' => $draft['summary'],
                        'primaryAction' => $draft['primaryAction'],
                        'primaryActionLabel' => $draft['primaryActionLabel'],
                    ],
                    'checkoutComposition' => $draft['checkoutComposition'],
                    'entitlementSelector' => null,
                    'checkout' => $checkout,
                    'serviceOrder' => null,
                    'supplement' => null,
                    'workspaceDraft' => [
                        'workspaceId' => $draft['workspaceId'],
                        'lineFingerprint' => $draft['lineFingerprint'],
                        'status' => $draft['status'],
                    ],
                ],
                'public_versions' => $publicVersions,
            ];
        } catch (\Throwable $exception) {
            Log::error('[cashier_v3_cashier_partition_failed] ' . json_encode([
                'state_context_id' => $stateContextId,
                'store_id' => $operatorScope->storeId(),
                'operator_id' => $operatorScope->operatorId(),
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ], JSON_UNESCAPED_UNICODE));
            return [
                'ready' => false,
                'payload' => null,
                'public_versions' => [],
            ];
        }
    }

    private function unavailableCatalog(CashierV3CommandException $exception): array
    {
        return [
            'contractVersion' => CashierV3SaleCatalogServices::CONTRACT_VERSION,
            'types' => ['项目', '产品', '卡项', '定制卡'],
            'categories' => ['全部'],
            'items' => [],
            'itemCount' => 0,
            'complete' => false,
            'availability' => [
                'status' => 'unavailable',
                'reasonCode' => (string)($exception->getDetail()['reason'] ?? 'cashier_catalog_unavailable'),
                'message' => $exception->getMessage(),
            ],
            'priceAuthority' => 'store_product_attr_value.price',
            'dataScope' => [],
        ];
    }
}
