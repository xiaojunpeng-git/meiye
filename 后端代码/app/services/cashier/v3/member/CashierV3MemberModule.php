<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\report\StoreUnifiedReportPhaseThreeFoundationServices;
use mohe\services\SystemConfigService;
use think\facade\Db;

/**
 * C5 会员选择与收银建档的最小 V3 领域适配。
 *
 * 会员主表仍是 user，store_user 是门店可见关系。该模块只负责把旧领域
 * DAO 能力接入 V3 的权限、幂等和事务边界，不暴露旧 HTTP 接口。
 */
final class CashierV3MemberModule
{
    private const PHONE_LOCK_TABLE = 'cashier_v3_member_phone_lock';
    private const NUMBER_SEQUENCE_TABLE = 'cashier_v3_member_number_sequence';
    private const NUMBER_SEQUENCE_KEY = 'member_bar_code';
    private const EXCLUSIVE_SERVICE_TABLE = 'member_exclusive_service';
    private const EXCLUSIVE_SERVICE_CHANGE_TABLE = 'member_exclusive_service_change';
    private const MEMBER_NUMBER_MIN = 100000000;
    private const MEMBER_NUMBER_MAX = 999999999;
    private const MEMBER_NUMBER_MAX_SKIP = 10000;

    public static function install(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3RootDomainAssembler $assembler,
        CashierV3CashierWorkspaceServices $cashierWorkspace,
        ?CashierV3EntitlementResourceVersionProvider $memberVersions = null
    ): void
    {
        // 会员建档完成后必须在同一事务内初始化 member 资源版本。否则后续
        // 旧的分步准备无法把新会员加入最终锁集合，会把一笔本可结账的卡项
        // 订单错误地拦截为资料不完整。
        $memberVersions = $memberVersions ?: new CashierV3EntitlementResourceVersionProvider(
            new CashierV3CashierReadinessGuard()
        );
        self::registerPolicy($dispatcher, 'create-member');
        self::registerPolicy($dispatcher, 'set-guest-order');
        self::registerMemberMutationPolicy($dispatcher, 'update-member');
        self::registerMemberMutationPolicy($dispatcher, 'deactivate-member');
        self::registerDirectGiftPolicy($dispatcher);
        self::registerPolicy($dispatcher, 'select-cashier-member');
        self::registerSelectionPolicy($dispatcher, 'select-writeoff-member', 'writeoff');
        self::registerSelectionPolicy($dispatcher, 'select-reservation-member', 'reservation');

        $handlers = $dispatcher->handlers();
        $memberDetails = new CashierV3MemberDetailQueryServices();
        $memberDebtDetails = new CashierV3MemberDebtProjectionServices();
        $memberSalesOrders = new CashierV3SalesOrderQueryServices();
        $cashierMemberSummaries = new CashierV3CashierMemberSummaryServices();
        foreach (['open-member-detail', 'load-member-detail-tab'] as $action) {
            if (!$handlers->hasProjection($action)) {
                $handlers->registerProjection($action, function (array $scope) use ($memberDetails, $memberDebtDetails, $memberSalesOrders): array {
                    $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                    $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
                    $detail = $memberDetails->read(
                        $memberId,
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        [
                            'tab' => (string)($payload['tab'] ?? ''),
                            'keyword' => (string)($payload['keyword'] ?? ''),
                            'status' => (string)($payload['status'] ?? ''),
                            'dateFrom' => (string)($payload['dateFrom'] ?? ''),
                            'dateTo' => (string)($payload['dateTo'] ?? ''),
                        ]
                    );
                    if ((string)($payload['tab'] ?? '') === 'debt') {
                        $debtSnapshot = $memberDebtDetails->read(
                            $memberId,
                            $scope['operator_scope'],
                            $scope['data_scope']
                        );
                        $detail['debtRecords'] = array_map(static function (array $record): array {
                            $record['actions'] = [[
                                'code' => 'open-debt-settlements',
                                'label' => '补交',
                            ]];
                            return $record;
                        }, self::filterMemberDetailRecords((array)($debtSnapshot['records'] ?? []), (string)($payload['keyword'] ?? '')));
                        $detail['summary']['outstandingDebtAmount'] = (string)($debtSnapshot['outstandingDebtAmount'] ?? '0.00');
                        $detail['summary']['outstandingDebtCount'] = (int)($debtSnapshot['outstandingDebtCount'] ?? 0);
                    }
                    if ((string)($payload['tab'] ?? '') === 'sales') {
                        $salesPage = $memberSalesOrders->querySalesOrders([
                            'memberId' => $memberId,
                            'keyword' => (string)($payload['keyword'] ?? ''),
                            'dateFrom' => (string)($payload['dateFrom'] ?? ''),
                            'dateTo' => (string)($payload['dateTo'] ?? ''),
                            'page' => 1,
                            'pageSize' => 50,
                        ], $scope['operator_scope'], $scope['data_scope']);
                        $detail['salesOrders'] = array_map(static function (array $record): array {
                            $record['orderNo'] = (string)($record['salesOrderNo'] ?? $record['sales_order_no'] ?? '');
                            $record['orderTypeLabel'] = '销售订单';
                            $record['orderAmount'] = $record['receivableAmount'] ?? null;
                            $record['cashPerformanceAmount'] = $record['actualReceivedAmount'] ?? null;
                            $record['statusLabel'] = (string)($record['orderStatus'] ?? $record['order_status'] ?? '');
                            $record['completedAt'] = (string)($record['paymentCompletedAt'] ?? $record['settledAt'] ?? '');
                            // 会员详情只提供查看订单入口。人员调整、退款、作废和重开
                            // 都由订单详情按权限、状态和版本生成，不把内部动作编码泄露
                            // 到会员记录列表。
                            $record['availableActions'] = [[
                                'code' => 'open-sales-order-detail',
                                'label' => '查看详情',
                            ]];
                            return $record;
                        }, array_values((array)($salesPage['records'] ?? [])));
                        $detail['tabStates']['sales'] = [
                            'total' => (int)($salesPage['total'] ?? 0),
                            'hasMore' => (bool)($salesPage['hasMore'] ?? false),
                            'dataStatus' => (string)($salesPage['dataStatus'] ?? ''),
                        ];
                    }
                    return [
                        'data' => [
                            'detail' => $detail,
                            'memberCenter' => ['detail' => $detail],
                        ],
                    ];
                });
            }
        }
        if (!$handlers->hasProjection('query-query-entities')) {
            $entitySelector = new CashierV3QueryEntitySelectorServices();
            $handlers->registerProjection('query-query-entities', function (array $scope) use ($entitySelector): array {
                return [
                    'data' => $entitySelector->query(
                        is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                        $scope['operator_scope'],
                        $scope['data_scope']
                    ),
                ];
            });
        }
        if (!$handlers->hasProjection('query-member-selector')) {
            $handlers->registerProjection('query-member-selector', function (array $scope): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                return [
                    'data' => self::querySelector(
                        $payload,
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        // 推荐人必须是当前门店可见会员；不能复用办理业务时的全集团选客例外。
                        (string)($payload['selectorContext'] ?? $payload['selector_context'] ?? '') !== 'member-referrer'
                    ),
                ];
            });
        }
        if (!$handlers->hasProjection('query-cashier-member-summary')) {
            $handlers->registerProjection('query-cashier-member-summary', function (array $scope) use ($cashierMemberSummaries): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
                if ($memberId <= 0) {
                    throw CashierV3CommandException::invalidContext('请选择有效会员后再读取欠款信息。');
                }
                $summary = $cashierMemberSummaries->read(
                    $memberId,
                    $scope['operator_scope']->storeId()
                );
                return ['data' => ['memberSummary' => $summary]];
            });
        }
        if (!$handlers->hasProjection('query-members')) {
            $handlers->registerProjection('query-members', function (array $scope): array {
                return [
                    'data' => self::querySelector(
                        is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        false
                    ),
                ];
            });
        }
        if (!$handlers->hasProjection('open-member-selector')) {
            $handlers->registerProjection('open-member-selector', function (): array {
                return ['data' => ['ready' => true, 'selector' => 'member']];
            });
        }
        foreach (['open-writeoff-member-selector' => 'writeoff', 'open-reservation-member-selector' => 'reservation'] as $action => $entry) {
            if (!$handlers->hasProjection($action)) {
                $handlers->registerProjection($action, function () use ($entry): array {
                    return ['data' => ['ready' => true, 'selector' => 'member', 'selectorEntry' => $entry]];
                });
            }
        }
        if (!$handlers->hasProjection('open-member-creator')) {
            $handlers->registerProjection('open-member-creator', function (): array {
                return ['data' => [
                    'ready' => true,
                    'creator' => 'member',
                    'creatorSchema' => self::memberCreatorSchema(),
                ]];
            });
        }
        if (!$handlers->hasProjection('open-recharge')) {
            $handlers->registerProjection('open-recharge', function (array $scope) use ($cashierWorkspace, $memberVersions): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
                if ($memberId <= 0) {
                    throw CashierV3CommandException::invalidContext('请先选择需要充值的会员。');
                }
                // 充值弹窗是只读准备动作，因此不会携带写命令的 contexts。
                // 工作台 ID 由受控账号、强制门店和服务端 state context 唯一派生，
                // 不能改为信任浏览器提交的 workspace ID。
                return Db::transaction(function () use ($scope, $memberVersions, $memberId): array {
                    // 充值入口只接收当前页面传入的会员身份；不读取或改写
                    // 收银购物车草稿，具体充值校验仍在后续充值流程中完成。
                    $member = self::findSelectableMember((string)$memberId, $scope['operator_scope']);
                    $memberVersion = $memberVersions->synchronizeProjectionVersion(
                        'member',
                        (string)$memberId,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    );
                    $balance = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx(
                        $memberId,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    );
                    $rechargeOptions = self::rechargeOptions($member);
                    return [
                        'data' => [
                            'member' => $member,
                            'balance' => $balance,
                            'rechargeOptions' => $rechargeOptions,
                        ],
                        // Projection envelope 只会公开顶层 overlay；不能把它塞进
                        // data._overlay 后期待命令分支的解包逻辑代为处理。
                        'overlay' => [
                            'name' => 'recharge',
                            'member' => $member,
                            'balance' => $balance,
                            'rechargeOptions' => $rechargeOptions,
                        ],
                        'versions' => [
                            ['kind' => 'member', 'id' => (string)$memberId, 'version' => $memberVersion],
                            ['kind' => 'member_balance', 'id' => (string)$memberId, 'version' => (int)$balance['accountVersion']],
                        ],
                    ];
                });
            });
        }
        if (!$handlers->hasProjection('open-gift')) {
            $handlers->registerProjection('open-gift', function (array $scope) use ($cashierWorkspace, $memberVersions): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
                if ($memberId <= 0) {
                    throw CashierV3CommandException::invalidContext('请先选择需要赠送的会员。');
                }
                return Db::transaction(function () use ($scope, $memberVersions, $memberId): array {
                    // 赠送入口只接收当前页面传入的会员身份；不读取或改写
                    // 收银购物车草稿，具体赠送校验仍在后续赠送流程中完成。
                    $member = self::findSelectableMember((string)$memberId, $scope['operator_scope']);
                    $memberVersion = $memberVersions->synchronizeProjectionVersion('member', (string)$memberId, $scope['operator_scope'], $scope['data_scope']);
                    $catalog = (new CashierV3SaleCatalogServices())->catalog($scope['operator_scope'], $scope['data_scope']);
                    // Gifts do not create inventory movements. Inventory-managed
                    // products remain selectable and are issued as benefits;
                    // stock is intentionally unchanged by this workflow.
                    $catalogItems = array_map(static function (array $item): array {
                        // Sale catalog disables an inventory SKU when stock is
                        // empty. Gift issuance has no stock movement, so only
                        // clear that stock-only disable; ordinary off-shelf or
                        // invalid items stay unavailable.
                        if (trim((string)($item['stockText'] ?? '')) !== ''
                            && (string)($item['disabledReason'] ?? '') === '库存不足') {
                            $item['disabled'] = false;
                            $item['disabledReason'] = '';
                            $item['stockWarning'] = false;
                        }
                        return $item;
                    }, array_values((array)($catalog['items'] ?? [])));
                    $store = (array)Db::name('system_store')->where('id', $scope['operator_scope']->storeId())
                        ->field('id,name')->find();
                    if ((int)($store['id'] ?? 0) !== $scope['operator_scope']->storeId()) {
                        throw CashierV3CommandException::invalidContext('当前办理门店不存在，请刷新后重试。');
                    }
                    // 券的归属门店必须是总部或当前办理门店。适用范围仍由券模板
                    // 原样保存到会员券；不允许从本店直接发放其他门店的券。
                    $coupons = Db::name('store_coupon_issue')->where('status', 1)->where('is_del', 0)
                        ->whereIn('relation_id', [0, $scope['operator_scope']->storeId()])
                        ->field('id,title,coupon_price,use_min_price,applicable_type,applicable_store_id,coupon_issue_type,relation_id')
                        ->order('id desc')->limit(500)->select()->toArray();
                    $applicableIds = [];
                    foreach ($coupons as $coupon) {
                        foreach (self::directGiftApplicableStoreIds($coupon['applicable_store_id'] ?? '') as $storeId) {
                            $applicableIds[$storeId] = $storeId;
                        }
                    }
                    $storeNames = $applicableIds === [] ? [] : Db::name('system_store')->whereIn('id', array_values($applicableIds))
                        ->column('name', 'id');
                    $coupons = array_map(static function (array $coupon) use ($storeNames): array {
                        $names = [];
                        foreach (self::directGiftApplicableStoreIds($coupon['applicable_store_id'] ?? '') as $storeId) {
                            if (isset($storeNames[$storeId])) $names[] = (string)$storeNames[$storeId];
                        }
                        $coupon['applicableStoreLabel'] = $names === []
                            ? '适用门店以券模板设置为准'
                            : '适用门店：' . implode('、', $names);
                        return $coupon;
                    }, $coupons);
                    return [
                        'data' => ['member' => $member, 'store' => $store, 'catalogItems' => $catalogItems, 'coupons' => $coupons],
                        'overlay' => ['name' => 'direct-gift', 'member' => $member, 'store' => $store, 'catalogItems' => $catalogItems, 'coupons' => $coupons],
                        'versions' => [['kind' => 'member', 'id' => (string)$memberId, 'version' => $memberVersion]],
                    ];
                });
            });
        }
        if (!$handlers->hasProjection('open-member-editor')) {
            $handlers->registerProjection('open-member-editor', function (array $scope) use ($memberVersions): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                return Db::transaction(function () use ($payload, $scope, $memberVersions): array {
                    $member = self::findManageableMember(
                        (string)($payload['memberId'] ?? $payload['member_id'] ?? ''),
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        false
                    );
                    $memberId = (int)$member['uid'];
                    $version = $memberVersions->synchronizeProjectionVersion(
                        'member',
                        (string)$memberId,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    );
                    return [
                        'data' => [
                            'member' => self::editableMember($member),
                            'creatorSchema' => self::memberCreatorSchema(),
                        ],
                        'versions' => [['kind' => 'member', 'id' => (string)$memberId, 'version' => $version]],
                    ];
                });
            });
        }
        if (!$handlers->hasCommand('create-member')) {
            $handlers->registerCommand('create-member', function (array $scope) use ($memberVersions): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                $member = self::createMember(
                    $payload,
                    $scope['operator_scope'],
                    $scope['data_scope'],
                    is_array($scope['operator'] ?? null) ? $scope['operator'] : [],
                    (string)($scope['idempotency_key'] ?? ''),
                    $scope['event_recorder'] ?? null,
                    $scope['event_execution'] ?? null,
                    is_array($scope['event_contract'] ?? null) ? $scope['event_contract'] : []
                );
                $memberId = (int)($member['memberId'] ?? $member['id'] ?? 0);
                if ($memberId <= 0) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '会员建档结果不完整，本次操作已取消。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['reason' => 'member_create_result_id_missing']
                    );
                }
                $memberVersions->synchronizeProjectionVersion(
                    'member',
                    (string)$memberId,
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                return [
                    'data' => ['member' => $member],
                    'business_no' => (string)($member['memberNo'] ?? ''),
                    'touched' => ['cashier_workspace'],
                    'message' => '会员建档成功。',
                ];
            });
        }
        if (!$handlers->hasCommand('update-member')) {
            $handlers->registerCommand('update-member', function (array $scope): array {
                $member = self::updateMember(
                    is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                    $scope['operator_scope'],
                    $scope['data_scope'],
                    (string)($scope['idempotency_key'] ?? ''),
                    $scope['event_recorder'] ?? null,
                    $scope['event_execution'] ?? null,
                    is_array($scope['event_contract'] ?? null) ? $scope['event_contract'] : []
                );
                return [
                    'data' => ['member' => $member],
                    'business_no' => (string)($member['memberNo'] ?? ''),
                    'touched' => ['member', 'cashier_workspace'],
                    'message' => '会员资料已保存。',
                ];
            });
        }
        if (!$handlers->hasCommand('deactivate-member')) {
            $handlers->registerCommand('deactivate-member', function (array $scope): array {
                $member = self::deactivateMember(
                    is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                    $scope['operator_scope'],
                    $scope['data_scope'],
                    (string)($scope['idempotency_key'] ?? ''),
                    $scope['event_recorder'] ?? null,
                    $scope['event_execution'] ?? null,
                    is_array($scope['event_contract'] ?? null) ? $scope['event_contract'] : []
                );
                return [
                    'data' => ['member' => $member],
                    'business_no' => (string)($member['memberNo'] ?? ''),
                    'touched' => ['member', 'cashier_workspace'],
                    'message' => '会员已注销，历史业务记录已保留。',
                ];
            });
        }
        if (!$handlers->hasCommand('submit-direct-gift')) {
            $handlers->registerCommand('submit-direct-gift', function (array $scope): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                self::assertSelectorEntry($payload, 'cashier', '赠送只能从当前收银工作台发起。');
                $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
                // 赠送提交只使用本次命令锁定的 member 身份，不依赖收银草稿
                // 中可能尚未同步的会员字段；赠送业务事务本身保持不变。
                $member = self::findSelectableMember((string)$memberId, $scope['operator_scope']);
                $result = (new CashierV3DirectGiftIssuanceServices())->issueInTx(
                    $payload, $member, $scope['operator_scope'], $scope['data_scope'], (string)$scope['idempotency_key'],
                    $scope['event_recorder'], $scope['event_execution'], (array)$scope['event_contract']
                );
                // The member identity is a locked read dependency.  Direct gifts create
                // new entitlement resources, but do not mutate the legacy member row.
                // Advancing the member-row version here would make the provider reject
                // a correctly-committed gift after all authority rows have been written.
                return ['data' => $result, 'business_no' => (string)$result['giftNo'], 'touched' => ['cashier_workspace'], 'message' => '赠送已生效。'];
            });
        }
        if (!$handlers->hasCommand('set-guest-order')) {
            $handlers->registerCommand('set-guest-order', function (array $scope) use ($cashierWorkspace): array {
                self::assertSelectorEntry(
                    is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                    'cashier',
                    '游客只能用于结账收款。'
                );
                $draft = $cashierWorkspace->selectGuestInTx(
                    self::workspaceContextId((array)($scope['contexts'] ?? [])),
                    (string)($scope['state_context_id'] ?? ''),
                    $scope['operator_scope']
                );
                return [
                    'data' => ['customerMode' => 'guest', 'member' => null, 'cashierDraft' => $draft],
                    'touched' => ['cashier_workspace'],
                    'message' => '已切换为游客开单。',
                ];
            });
        }
        if (!$handlers->hasCommand('select-cashier-member')) {
            $handlers->registerCommand('select-cashier-member', function (array $scope) use ($cashierWorkspace, $memberVersions, $cashierMemberSummaries): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                self::assertSelectorEntry($payload, 'cashier');
                $member = self::findSelectableMember(
                    (string)($payload['memberId'] ?? $payload['member_id'] ?? ''),
                    $scope['operator_scope']
                );
                // 历史会员没有经过 V3 建档流程时，首次选中即在本事务内补齐
                // 权威投影版本，使后续结账可以稳定锁定会员资源。
                $memberVersions->synchronizeProjectionVersion(
                    'member',
                    (string)($member['id'] ?? $member['memberId'] ?? 0),
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                $draft = $cashierWorkspace->selectMemberInTx(
                    self::workspaceContextId((array)($scope['contexts'] ?? [])),
                    (string)($scope['state_context_id'] ?? ''),
                    $scope['operator_scope'],
                    (int)($member['id'] ?? $member['memberId'] ?? 0)
                );
                $member = array_merge($member, $cashierMemberSummaries->read(
                    (int)($member['id'] ?? $member['memberId'] ?? 0),
                    $scope['operator_scope']->storeId()
                ));
                return [
                    'data' => ['customerMode' => 'member', 'member' => $member, 'cashierDraft' => $draft],
                    'touched' => ['cashier_workspace'],
                    'message' => '已选择会员。',
                ];
            });
        }
        foreach ([
            'select-writeoff-member' => 'writeoff',
            'select-reservation-member' => 'reservation',
        ] as $action => $entry) {
            if (!$handlers->hasCommand($action)) {
                $handlers->registerCommand($action, function (array $scope) use ($entry): array {
                    $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                    self::assertSelectorEntry($payload, $entry);
                    $member = self::findSelectableMember(
                        (string)($payload['memberId'] ?? $payload['member_id'] ?? ''),
                        $scope['operator_scope']
                    );
                    return [
                        'data' => [
                            'customerMode' => 'member',
                            'member' => $member,
                            'selectorEntry' => $entry,
                        ],
                        'touched' => ['cashier_workspace'],
                        'message' => '已选择会员。',
                    ];
                });
            }
        }
    }

    private static function registerPolicy(CashierV3ActionDispatcher $dispatcher, string $action): void
    {
        if ($dispatcher->policies()->has($action)) {
            return;
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            $action,
            ['cashier_workspace'],
            [],
            null,
            ['cashier_workspace']
        ));
    }

    private static function registerMemberMutationPolicy(CashierV3ActionDispatcher $dispatcher, string $action): void
    {
        if ($dispatcher->policies()->has($action)) {
            return;
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            $action,
            ['cashier_workspace', 'member'],
            [],
            static function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
                if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) {
                    throw CashierV3CommandException::invalidContext(
                        '会员资料或当前工作台版本无效，请刷新后重试。',
                        ['reason' => 'member_mutation_identity_invalid']
                    );
                }
                return [
                    'required' => ['cashier_workspace', 'member'],
                    'allowed' => [],
                    'identities' => [
                        ['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true],
                        ['role' => 'member', 'kind' => 'member', 'id' => $memberId, 'required' => true],
                    ],
                    'required_read_roles' => ['cashier_workspace', 'member'],
                    'required_touched_roles' => ['cashier_workspace', 'member'],
                ];
            },
            ['cashier_workspace', 'member'],
            ['cashier_workspace', 'member'],
            ['cashier_workspace', 'member']
        ));
    }

    private static function registerDirectGiftPolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        if ($dispatcher->policies()->has('submit-direct-gift')) {
            return;
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'submit-direct-gift',
            ['cashier_workspace', 'member'],
            [],
            static function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
                if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) {
                    throw CashierV3CommandException::invalidContext(
                        '赠送会员或当前工作台版本无效，请刷新后重试。',
                        ['reason' => 'direct_gift_identity_invalid']
                    );
                }
                return [
                    'required' => ['cashier_workspace', 'member'],
                    'allowed' => [],
                    'identities' => [
                        ['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true],
                        ['role' => 'member', 'kind' => 'member', 'id' => $memberId, 'required' => true],
                    ],
                    'required_read_roles' => ['cashier_workspace', 'member'],
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace'],
            ['cashier_workspace', 'member'],
            ['cashier_workspace', 'member']
        ));
    }

    private static function registerSelectionPolicy(
        CashierV3ActionDispatcher $dispatcher,
        string $action,
        string $entry
    ): void {
        if ($dispatcher->policies()->has($action)) {
            return;
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            $action,
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($entry): array {
                self::assertSelectorEntry($payload, $entry);
                return [
                    'required' => ['cashier_workspace'],
                    'allowed' => [],
                    'identities' => [[
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => (string)($base['session']['workspace_id'] ?? ''),
                        'required' => true,
                    ]],
                    'required_read_roles' => ['cashier_workspace'],
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace']
        ));
    }

    private static function assertSelectorEntry(
        array $payload,
        string $expected,
        string $message = '选择来源无效，请从正确的业务入口重新打开。'
    ): void {
        $entry = trim((string)($payload['selectorEntry']
            ?? $payload['selector_entry']
            ?? $payload['selectorContext']
            ?? ''));
        if ($entry !== $expected) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                $message,
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'selector_entry_mismatch', 'expected' => $expected, 'actual' => $entry]
            );
        }
    }

    private static function workspaceContextId(array $contexts): string
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === 'cashier_workspace') {
                $id = trim((string)($context['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '本次操作缺少当前工作台版本，请刷新页面后重试。',
            CashierV3ResultCode::STATUS_FAILED
        );
    }

    /**
     * 只读准备动作没有写命令 contexts，工作台身份只能由服务端已解析的 scope 生成。
     */
    private static function workspaceIdForProjection(array $scope): string
    {
        $operatorScope = $scope['operator_scope'] ?? null;
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        if (!$operatorScope instanceof CashierV3OperatorScope || $stateContextId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        return \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
    }

    /**
     * Reuse the legacy recharge configuration as a read-only policy source.
     * The submit command resolves a selected package again, so this projection
     * is never the authority for a package price or its gifts.
     */
    private static function rechargeOptions(array $member): array
    {
        $quotas = is_array(sys_data('user_recharge_quota')) ? sys_data('user_recharge_quota') : [];
        $quotaIds = array_values(array_filter(array_map(static function ($quota): int {
            return is_array($quota) ? (int)($quota['id'] ?? 0) : 0;
        }, $quotas)));
        $giftConfig = [];
        if ($quotaIds) {
            /** @var \app\services\other\StoreGiftConfigServices $giftService */
            $giftService = app()->make(\app\services\other\StoreGiftConfigServices::class);
            $giftConfig = $giftService->getConfigMap(
                \app\services\other\StoreGiftConfigServices::GIFT_TYPE_RECHARGE,
                $quotaIds
            );
        }

        $packages = [];
        foreach ($quotas as $quota) {
            if (!is_array($quota)) {
                continue;
            }
            $id = (int)($quota['id'] ?? 0);
            $price = self::normalizeRechargeMoney($quota['price'] ?? null);
            $bonus = self::normalizeRechargeMoney($quota['give_money'] ?? 0);
            if ($id <= 0 || $price === null || $price <= 0 || $bonus === null) {
                continue;
            }
            $preset = is_array($giftConfig[$id] ?? null) ? $giftConfig[$id] : [];
            $products = is_array($preset['product'] ?? null) ? $preset['product'] : [];
            $coupons = is_array($preset['coupon'] ?? null) ? $preset['coupon'] : [];
            $packages[] = [
                'id' => $id,
                'price' => $price,
                'bonus' => $bonus,
                'giftProductCount' => count($products),
                'giftCouponCount' => count($coupons),
            ];
        }

        return [
            'packages' => $packages,
            'operatorGiftEnabled' => (int)sys_config('cashier_operator_gift_switch', 1) === 1,
            'debtPaymentEnabled' => (int)sys_config('cashier_debt_pay_switch', 0) === 1,
            'minimumAmount' => max(0, (float)sys_config('store_user_min_recharge', 0)),
            'maximumBonusPercent' => max(0, (float)($member['recharge_per'] ?? 0)),
        ];
    }

    private static function normalizeRechargeMoney($value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            return null;
        }
        return (float)$raw;
    }

    /**
     * 欠款明细由专用权威投影读取；详情页的关键词只在服务端对该投影结果做只读
     * 过滤，绝不回退到销售订单聚合或前端筛选。
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private static function filterMemberDetailRecords(array $records, string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') return $records;
        $keyword = mb_substr($keyword, 0, 80);
        return array_values(array_filter($records, static function (array $record) use ($keyword): bool {
            $text = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($text) && mb_stripos($text, $keyword) !== false;
        }));
    }

    /**
     * @return array{records:array,total:int,page:int,pageSize:int,isLoading:bool}
     */
    private static function querySelector(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $globalSelector
    ): array {
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($payload['pageSize'] ?? 20)));
        $keyword = trim((string)($payload['keyword'] ?? $payload['search'] ?? ''));
        // 产品已确认：办理业务的会员选择器可查全集团，员工数据权限只影响
        // 会员中心等管理查询。该例外不扩展到编辑、导出或批量操作。
        $storeIds = $globalSelector ? null : self::visibleStoreIds($dataScope);
        if ($storeIds === []) {
            return ['records' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false];
        }

        $priority = self::priorityContext($operatorScope, $storeIds);
        $query = self::memberVisibilityQuery($storeIds, $keyword);
        $total = (int)(clone $query)->count('distinct u.uid');
        $rows = $query
            ->field('u.uid,u.nickname,u.real_name,u.phone,u.bar_code,u.belong_store_id,u.status,u.is_del,u.delete_time,u.add_time')
            ->group('u.uid,u.nickname,u.real_name,u.phone,u.bar_code,u.belong_store_id,u.status,u.is_del,u.delete_time,u.add_time')
            ->orderRaw($priority['order'])
            ->order('u.uid', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $displayStoreIds = self::resolveDisplayStoreIds(
            array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'uid'))))),
            $rows,
            $storeIds,
            $priority['storeRanks']
        );
        $storeIdsForNames = array_values(array_unique(array_filter(array_values($displayStoreIds))));
        $stores = $storeIdsForNames
            ? Db::name('system_store')->whereIn('id', $storeIdsForNames)->column('name', 'id')
            : [];
        $organizations = self::organizationNamesForStores(
            $storeIdsForNames,
            $priority['organizationNames']
        );
        $records = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $name = trim((string)($row['real_name'] ?? '')) ?: trim((string)($row['nickname'] ?? ''));
            $displayStoreId = (int)($displayStoreIds[$uid] ?? $row['belong_store_id'] ?? 0);
            $state = self::memberState($row);
            $records[] = [
                'id' => (string)$uid,
                'memberId' => $uid,
                'name' => $name !== '' ? $name : '未命名会员',
                'phone' => (string)($row['phone'] ?? ''),
                'memberNo' => (string)($row['bar_code'] ?? ''),
                'status' => $state['label'],
                'statusLabel' => $state['label'],
                'storeId' => $displayStoreId,
                'storeName' => (string)($stores[$displayStoreId] ?? ''),
                'organizationName' => (string)($organizations[$displayStoreId] ?? ''),
                'selectable' => $uid > 0 && $state['selectable'],
                'disabledReason' => $state['reason'],
            ];
        }

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'isLoading' => false,
        ];
    }

    /**
     * 会员管理范围统一从 store_user 读取；全集团业务选择器使用 LEFT JOIN，
     * 因此没有门店关系的历史会员仍可查询，但不会被误认成本店会员。
     *
     * user.belong_store_id 只是主归属快照，不能表达会员被调店或跨店服务后的
     * 可见关系。ALL 模式保留没有门店关系的历史平台会员；有限门店范围必须有
     * 一条有效的 store_user 关系，避免仅凭主归属门店越权展示。
     */
    private static function memberVisibilityQuery(?array $storeIds, string $keyword)
    {
        $query = Db::name('user')->alias('u');
        if ($storeIds === null) {
            $query->leftJoin('store_user su', 'su.uid = u.uid AND su.status = 1');
        } else {
            $query->join('store_user su', 'su.uid = u.uid AND su.status = 1')
                ->whereIn('su.store_id', $storeIds);
        }
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_') . '%';
            $query->where(function ($q) use ($like, $keyword) {
                $q->whereLike('u.nickname', $like)
                    ->whereLike('u.real_name', $like, 'OR')
                    ->whereOr('u.phone', $keyword)
                    ->whereOr('u.bar_code', $keyword);
            });
        }
        return $query;
    }

    private static function visibleStoreIds(CashierV3DataScopeContext $dataScope): ?array
    {
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
            return null;
        }
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE) {
            return [];
        }
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
            // 会员查询没有“本人参与会员”的权威 provider。把 SELF_PARTICIPANT
            // 偷换成本店会扩大权限，因此在 provider 接入前必须 fail-closed。
            return [];
        }
        return array_values(array_unique(array_map('intval', (array)$dataScope->visibleStoreIds())));
    }

    /**
     * 会员选择器固定排序：本店 → 当前组织 → 逐级上级新增范围 → 全集团。
     *
     * 对候选门店的组织逐级向上查找其与当前组织链最近的交点。这样同组织
     * 门店、兄弟组织门店和更高层组织范围分别落在正确层级，而不是只比较
     * 门店直接所属组织。最终 SQL 只包含已转成整数的门店 ID，兼容 MySQL 5.6。
     *
     * @return array{order:string,organizationNames:array<int,string>,storeRanks:array<int,int>}
     */
    private static function priorityContext(CashierV3OperatorScope $operatorScope, ?array $visibleStoreIds): array
    {
        $currentStoreId = $operatorScope->storeId();
        $candidateStoreIds = $visibleStoreIds === null
            ? null
            : array_values(array_unique(array_filter(array_map('intval', $visibleStoreIds))));
        $storeOrg = [];
        $organizationNames = [];
        try {
            $storeQuery = Db::name('organization_store')->alias('os')
                ->leftJoin('organization o', 'o.id = os.org_id')
                ->field('os.store_id,os.org_id,o.name as organization_name');
            if ($candidateStoreIds !== null) {
                if ($candidateStoreIds) {
                    $storeQuery->whereIn('os.store_id', $candidateStoreIds);
                }
            }
            $storeRows = $storeQuery->select()->toArray();
            $allOrganizationStoreIds = [];
            foreach ($storeRows as $row) {
                $storeId = (int)($row['store_id'] ?? 0);
                if ($storeId <= 0) {
                    continue;
                }
                $orgId = (int)($row['org_id'] ?? 0);
                $storeOrg[$storeId] = $orgId;
                $organizationNames[$storeId] = (string)($row['organization_name'] ?? '');
                $allOrganizationStoreIds[] = $storeId;
            }
            if ($candidateStoreIds === null) {
                $candidateStoreIds = array_values(array_unique($allOrganizationStoreIds));
            }
            if (!in_array($currentStoreId, $candidateStoreIds, true)) {
                $candidateStoreIds[] = $currentStoreId;
            }

            $orgRows = Db::name('organization')->where('is_del', 0)->field('id,pid,name')->select()->toArray();
            $orgById = [];
            foreach ($orgRows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $orgById[$id] = [
                        'pid' => (int)($row['pid'] ?? 0),
                        'name' => (string)($row['name'] ?? ''),
                    ];
                }
            }
            $currentOrgId = (int)$operatorScope->organizationId();
            if ($currentOrgId <= 0) {
                $currentOrgId = (int)($storeOrg[$currentStoreId] ?? 0);
            }
            $currentOrgDistances = [];
            $cursor = $currentOrgId;
            $distance = 1;
            $visited = [];
            while ($cursor > 0 && !isset($visited[$cursor]) && $distance <= 100) {
                $visited[$cursor] = true;
                $currentOrgDistances[$cursor] = $distance;
                $cursor = (int)($orgById[$cursor]['pid'] ?? 0);
                $distance++;
            }

            $buckets = [];
            $storeRanks = [];
            foreach ($candidateStoreIds as $storeId) {
                if ($storeId === $currentStoreId) {
                    $rank = 0;
                } else {
                    $rank = 10000;
                    $orgCursor = (int)($storeOrg[$storeId] ?? 0);
                    $orgVisited = [];
                    while ($orgCursor > 0 && !isset($orgVisited[$orgCursor])) {
                        $orgVisited[$orgCursor] = true;
                        if (isset($currentOrgDistances[$orgCursor])) {
                            $rank = (int)$currentOrgDistances[$orgCursor];
                            break;
                        }
                        $orgCursor = (int)($orgById[$orgCursor]['pid'] ?? 0);
                    }
                }
                $storeRanks[$storeId] = $rank;
                $buckets[$rank][] = $storeId;
            }
            ksort($buckets, SORT_NUMERIC);
            $when = [];
            $storeExpression = 'COALESCE(NULLIF(su.store_id,0),u.belong_store_id)';
            foreach ($buckets as $rank => $ids) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                if ($ids) {
                    $when[] = sprintf(
                        'WHEN %s IN (%s) THEN %d',
                        $storeExpression,
                        implode(',', $ids),
                        (int)$rank
                    );
                }
            }
            if ($when) {
                return [
                    'order' => 'MIN(CASE ' . implode(' ', $when) . ' ELSE 10000 END) ASC',
                    'organizationNames' => $organizationNames,
                    'storeRanks' => $storeRanks,
                ];
            }
        } catch (\Throwable $e) {
            // 排序元数据不可用不改变查询范围，只退回本店优先的稳定排序。
        }
        return [
            'order' => sprintf(
                'MIN(CASE WHEN COALESCE(NULLIF(su.store_id,0),u.belong_store_id) = %d THEN 0 ELSE 10000 END) ASC',
                $currentStoreId
            ),
            'organizationNames' => $organizationNames,
            'storeRanks' => [$currentStoreId => 0],
        ];
    }

    /**
     * 为每个会员选择本次查询范围内优先级最高的有效门店关系。
     *
     * @param int[] $memberIds
     * @param array<int,array> $rows
     * @param null|int[] $visibleStoreIds
     * @param array<int,int> $storeRanks
     * @return array<int,int>
     */
    private static function resolveDisplayStoreIds(
        array $memberIds,
        array $rows,
        ?array $visibleStoreIds,
        array $storeRanks
    ): array {
        $resolved = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid > 0) {
                $resolved[$uid] = (int)($row['belong_store_id'] ?? 0);
            }
        }
        if (!$memberIds) {
            return $resolved;
        }

        $relations = Db::name('store_user')
            ->whereIn('uid', $memberIds)
            ->where('status', 1);
        if ($visibleStoreIds !== null) {
            $relations->whereIn('store_id', $visibleStoreIds);
        }
        $relations = $relations->field('uid,store_id')->select()->toArray();
        $bestRanks = [];
        foreach ($relations as $relation) {
            $uid = (int)($relation['uid'] ?? 0);
            $storeId = (int)($relation['store_id'] ?? 0);
            if ($uid <= 0 || $storeId <= 0) {
                continue;
            }
            $rank = (int)($storeRanks[$storeId] ?? 10000);
            if (!isset($bestRanks[$uid])
                || $rank < $bestRanks[$uid]
                || ($rank === $bestRanks[$uid] && $storeId < $resolved[$uid])) {
                $bestRanks[$uid] = $rank;
                $resolved[$uid] = $storeId;
            }
        }
        return $resolved;
    }

    private static function findSelectableMember(
        string $memberId,
        CashierV3OperatorScope $operatorScope
    ): array {
        $memberId = trim($memberId);
        if ($memberId === '' || !ctype_digit($memberId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '该会员不存在或当前不可见。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        // 业务选客已确认允许全集团，选择时仍从权威主表重新读取状态。
        $row = Db::name('user')
            ->where('uid', (int)$memberId)
            ->field('uid,nickname,real_name,phone,bar_code,belong_store_id,status,is_del,delete_time')
            ->find();
        if (!$row) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该会员不存在或当前不可见。', CashierV3ResultCode::STATUS_FAILED);
        }
        $state = self::memberState($row);
        if (!$state['selectable']) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                $state['reason'],
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => $state['code'], 'member_id' => (int)$row['uid']]
            );
        }
        return self::formatMemberRow(
            self::withBestDisplayStore($row, $operatorScope),
            $operatorScope->storeId()
        );
    }

    /** @return int[] */
    private static function directGiftApplicableStoreIds($value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($raw as $storeId) {
            $storeId = (int)$storeId;
            if ($storeId > 0) $ids[$storeId] = $storeId;
        }
        return array_values($ids);
    }

    private static function createMember(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $operator,
        string $idempotencyKey = '',
        $eventRecorder = null,
        $eventExecution = null,
        array $eventContract = []
    ): array {
        // SELF_PARTICIPANT 没有会员 provider 可以证明“本人参与”，新增会员必须
        // 明确落在已授权门店；不能用该模式绕过门店集合门禁。
        if (!$dataScope->allowsStore($operatorScope->storeId()) && !$dataScope->isSuperAdmin()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号没有在本店新增会员的权限。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $name = trim((string)($payload['name'] ?? $payload['real_name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? $payload['mobile'] ?? ''));
        if ($name === '') {
            throw self::validation('name', '请填写会员姓名。');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw self::validation('phone', '请填写正确的手机号。');
        }

        $profile = self::validateProfileSelections($payload, $operatorScope);
        self::lockPhoneResource($phone);
        $existing = Db::name('user')->where('phone', $phone)->lock(true)->find();
        if ($existing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '该手机号已存在会员，请直接选择已有会员。',
                CashierV3ResultCode::STATUS_CONFLICT,
                [
                    'reason' => 'phone_exists',
                    'existingMember' => self::formatMemberRow(
                        self::withBestDisplayStore($existing, $operatorScope),
                        $operatorScope->storeId()
                    ),
                ]
            );
        }

        $now = time();
        $memberNo = self::nextMemberNumber();
        $profileFields = self::normalizeProfileFields(
            is_array($payload['profileFields'] ?? null) ? $payload['profileFields'] : []
        );
        $userServices = app()->make(\app\services\user\UserServices::class);
        $extendInfo = '';
        if ($profileFields) {
            try {
                $extendInfo = self::encodeMemberExtendInfo(
                    $userServices->handelExtendInfo($profileFields),
                    $profileFields
                );
            } catch (\Throwable $e) {
                throw self::validation('profileFields', '会员档案信息校验失败，请检查后重试。');
            }
        }
        $birthday = self::normalizeBirthday($payload['birthday'] ?? '');
        $referrerMemberId = self::resolveReferrerMemberId($payload, 0, $operatorScope, $dataScope);
        $data = [
            'nickname' => $name,
            'real_name' => $name,
            'phone' => $phone,
            'bar_code' => $memberNo,
            'avatar' => sys_config('h5_avatar'),
            'user_type' => 'cashier',
            'belong_store_id' => $operatorScope->storeId(),
            'status' => 1,
            'add_time' => $now,
            'extend_info' => $extendInfo,
            'sex' => (int)($payload['sex'] ?? 0),
            'birthday' => $birthday,
            'card_id' => trim((string)($payload['idCard'] ?? '')),
            'addres' => trim((string)($payload['address'] ?? '')),
            'mark' => trim((string)($payload['note'] ?? '')),
            'adminId' => (int)($operator['id'] ?? $operator['operator_id'] ?? $dataScope->operatorId()),
            'spread_uid' => $referrerMemberId,
        ];
        $saved = $userServices->save($data);
        if (!$saved || !(int)$saved->uid) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员保存失败，未产生有效会员。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $uid = (int)$saved->uid;
        $storeUser = Db::name('store_user')->where(['uid' => $uid, 'store_id' => $operatorScope->storeId()])->lock(true)->find();
        if (!$storeUser) {
            $storeUserInserted = Db::name('store_user')->insert([
                'uid' => $uid,
                'store_id' => $operatorScope->storeId(),
                'label_id' => '',
                'status' => 1,
                'add_time' => $now,
            ]);
            if ((int)$storeUserInserted !== 1) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '会员门店关系保存失败，本次建档已取消。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'member_store_relation_insert_failed']
                );
            }
        }
        $phaseThreeFoundation = new StoreUnifiedReportPhaseThreeFoundationServices();
        $phaseThreeFoundation->recordMemberOrigin(
            ['tenant_id' => '0', 'operator_id' => (int)($operator['id'] ?? $operator['operator_id'] ?? $dataScope->operatorId())],
            [
                'member_id' => $uid,
                'origin_type' => 'SYSTEM_CREATED',
                'source_type' => 'CASHIER_V3_MEMBER_CREATE',
                'source_id' => (string)$uid,
                'member_created_at' => $now,
                'idempotency_key' => 'phase3-member-origin:cashier-v3:' . $uid,
            ]
        );
        $phaseThreeFoundation->recordMemberStoreAssignmentInTx(
            ['tenant_id' => '0'],
            [
                'member_id' => $uid,
                'assigned' => true,
                'store_id' => $operatorScope->storeId(),
                'effective_at' => $now,
                'source_type' => 'CASHIER_V3_MEMBER_CREATE',
                'source_event_id' => (string)$uid,
                'idempotency_key' => 'phase3-member-store:cashier-v3:' . $uid,
            ]
        );
        if ((int)$profile['level_id'] > 0) {
            try {
                /** @var \app\services\user\level\UserLevelServices $levelServices */
                $levelServices = app()->make(\app\services\user\level\UserLevelServices::class);
                if (!$levelServices->setUserLevel($uid, (int)$profile['level_id'])) {
                    throw new \RuntimeException('setUserLevel returned false');
                }
            } catch (\Throwable $e) {
                throw self::validation('memberLevelId', '会员等级保存失败，请重试。');
            }
        }
        if ($profile['tag_ids']) {
            try {
                /** @var \app\services\user\label\UserLabelRelationServices $labelServices */
                $labelServices = app()->make(\app\services\user\label\UserLabelRelationServices::class);
                if (!$labelServices->setUserLable([$uid], $profile['tag_ids'], 0, 0, true)) {
                    throw new \RuntimeException('setUserLable returned false');
                }
            } catch (\Throwable $e) {
                throw self::validation('memberTagIds', '会员标签保存失败，请重试。');
            }
        }
        if ($profile['exclusive_service_person'] !== null) {
            self::assignExclusiveServicePerson(
                $uid,
                $profile['exclusive_service_person'],
                $operatorScope,
                $dataScope,
                $idempotencyKey,
                $now
            );
        }
        self::recordMemberCreatedEvent(
            $uid,
            $operatorScope,
            $dataScope,
            $idempotencyKey,
            $now,
            $profile,
            $name,
            $phone,
            $memberNo,
            $eventRecorder,
            $eventExecution,
            $eventContract
        );
        $row = Db::name('user')->where('uid', $uid)->field('uid,nickname,real_name,phone,bar_code,belong_store_id,spread_uid')->find();
        return self::formatMemberRow($row ?: ['uid' => $uid, 'real_name' => $name, 'phone' => $phone, 'belong_store_id' => $operatorScope->storeId()], $operatorScope->storeId());
    }

    private static function updateMember(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        $eventRecorder,
        $eventExecution,
        array $eventContract
    ): array {
        $member = self::findManageableMember(
            (string)($payload['memberId'] ?? $payload['member_id'] ?? ''),
            $operatorScope,
            $dataScope,
            true
        );
        $name = trim((string)($payload['name'] ?? $payload['real_name'] ?? ''));
        if ($name === '') {
            throw self::validation('name', '请填写会员姓名。');
        }
        $sex = (int)($payload['sex'] ?? $member['sex'] ?? 0);
        if (!in_array($sex, [0, 1, 2], true)) {
            throw self::validation('sex', '会员性别参数无效。');
        }
        $updates = [
            'nickname' => $name,
            'real_name' => $name,
            'sex' => $sex,
            'birthday' => self::normalizeBirthday(array_key_exists('birthday', $payload)
                ? $payload['birthday']
                : ((int)($member['birthday'] ?? 0) > 0 ? date('Y-m-d', (int)$member['birthday']) : '')),
            'addres' => trim((string)($payload['address'] ?? $payload['addres'] ?? ($member['addres'] ?? ''))),
            'mark' => trim((string)($payload['note'] ?? $payload['mark'] ?? ($member['mark'] ?? ''))),
        ];
        $referrerSupplied = array_key_exists('referrerMemberId', $payload) || array_key_exists('referrer_member_id', $payload);
        if ($referrerSupplied) {
            $referrerId = self::resolveReferrerMemberId($payload, (int)$member['uid'], $operatorScope, $dataScope);
            if ($referrerId !== (int)($member['spread_uid'] ?? 0)
                && Db::name('cashier_v3_customer_lifecycle_projection')
                    ->where('tenant_id', $dataScope->tenantId())->where('member_id', (int)$member['uid'])
                    ->where('first_course_completed_at', '>', 0)->value('id')) {
                throw self::validation('referrerMemberId', '首次疗程卡成交后不能修改推荐人。');
            }
            $updates['spread_uid'] = $referrerId;
        }
        // 编辑与新增共用完整档案契约：档案字段、身份证、等级和标签都从同一
        // 个命令一次保存，避免编辑窗体显示了字段却只落基础资料。
        $profile = self::validateProfileSelections($payload, $operatorScope);
        $profileFields = self::normalizeProfileFields(
            is_array($payload['profileFields'] ?? null) ? $payload['profileFields'] : [],
            $member
        );
        $userServices = app()->make(\app\services\user\UserServices::class);
        try {
            $updates['card_id'] = trim((string)($payload['idCard'] ?? $payload['card_id'] ?? ($member['card_id'] ?? '')));
            $updates['extend_info'] = self::encodeMemberExtendInfo(
                $userServices->handelExtendInfo($profileFields),
                $profileFields,
                $member
            );
        } catch (\Throwable $e) {
            throw self::validation('profileFields', '会员档案信息校验失败，请检查后重试。');
        }
        // ThinkPHP returns 0 when the submitted values equal the current row;
        // that is still a successful edit and must not surface as a false error.
        $updated = Db::name('user')->where('uid', (int)$member['uid'])->update($updates);
        if ($updated === false) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员资料保存失败，本次操作已取消。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        try {
            /** @var \app\services\user\level\UserLevelServices $levelServices */
            $levelServices = app()->make(\app\services\user\level\UserLevelServices::class);
            if (!$levelServices->setUserLevel((int)$member['uid'], (int)$profile['level_id'])) {
                throw new \RuntimeException('setUserLevel returned false');
            }
            /** @var \app\services\user\label\UserLabelRelationServices $labelServices */
            $labelServices = app()->make(\app\services\user\label\UserLabelRelationServices::class);
            if (!$labelServices->setUserLable([(int)$member['uid']], $profile['tag_ids'], 0, 0, true)) {
                throw new \RuntimeException('setUserLable returned false');
            }
        } catch (\Throwable $e) {
            throw self::validation('memberProfile', '会员等级或标签保存失败，请重试。');
        }
        $exclusiveServicePersonChanged = false;
        if ($profile['exclusive_service_person_supplied']) {
            $exclusiveServicePersonChanged = self::updateExclusiveServicePerson(
                (int)$member['uid'],
                $profile['exclusive_service_person'],
                $operatorScope,
                $dataScope,
                $idempotencyKey,
                time()
            );
        }
        $current = Db::name('user')->where('uid', (int)$member['uid'])->lock(true)->find();
        $changedFields = ['name', 'sex', 'birthday', 'address', 'note', 'idCard', 'profileFields', 'memberLevelId', 'memberTagIds'];
        if ($referrerSupplied) $changedFields[] = 'referrerMemberId';
        $mutationPayload = [
            'changed_fields' => $changedFields,
            'source' => 'cashier_v3',
        ];
        if ($profile['exclusive_service_person_supplied']) {
            $changedFields[] = 'exclusiveServicePersonId';
            $mutationPayload['changed_fields'] = $changedFields;
            $mutationPayload['exclusive_service_person_id'] = (int)($profile['exclusive_service_person']['staff_id'] ?? 0);
            $mutationPayload['exclusive_service_person_changed'] = $exclusiveServicePersonChanged;
        }
        self::recordMemberMutationEvent(
            'member.updated',
            'update-member',
            $current ?: $member,
            $operatorScope,
            $dataScope,
            $idempotencyKey,
            $mutationPayload,
            $eventRecorder,
            $eventExecution,
            $eventContract
        );
        return self::formatMemberRow($current ?: $member, $operatorScope->storeId());
    }

    private static function deactivateMember(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        $eventRecorder,
        $eventExecution,
        array $eventContract
    ): array {
        $member = self::findManageableMember(
            (string)($payload['memberId'] ?? $payload['member_id'] ?? ''),
            $operatorScope,
            $dataScope,
            true
        );
        $now = time();
        if ((int)Db::name('user')->where('uid', (int)$member['uid'])->update([
            'status' => 0,
            'is_del' => 1,
            'delete_time' => date('Y-m-d H:i:s', $now),
        ]) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员注销失败，本次操作已取消。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $current = Db::name('user')->where('uid', (int)$member['uid'])->lock(true)->find();
        self::recordMemberMutationEvent(
            'member.deactivated',
            'deactivate-member',
            $current ?: $member,
            $operatorScope,
            $dataScope,
            $idempotencyKey,
            ['reason' => trim((string)($payload['reason'] ?? '门店端会员注销')), 'source' => 'cashier_v3'],
            $eventRecorder,
            $eventExecution,
            $eventContract
        );
        return self::formatMemberRow($current ?: $member, $operatorScope->storeId());
    }

    /** Resolves the referrer inside the V3 member-write transaction and scope. */
    private static function resolveReferrerMemberId(array $payload, int $memberId, CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope): int
    {
        $raw = $payload['referrerMemberId'] ?? $payload['referrer_member_id'] ?? 0;
        if (!is_int($raw) && !is_string($raw) || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$raw) !== 1) {
            throw self::validation('referrerMemberId', '推荐人资料无效，请重新选择。');
        }
        $referrerId = (int)$raw;
        if ($referrerId === 0) return 0;
        if ($memberId > 0 && $referrerId === $memberId) {
            throw self::validation('referrerMemberId', '推荐人不能是顾客本人。');
        }
        self::findManageableMember((string)$referrerId, $operatorScope, $dataScope, true);
        return $referrerId;
    }

    private static function findManageableMember(
        string $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        if (preg_match('/^[1-9][0-9]*$/D', trim($memberId)) !== 1) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该会员不存在或当前不可编辑。', CashierV3ResultCode::STATUS_FAILED);
        }
        $query = Db::name('user')->where('uid', (int)$memberId);
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->find();
        if (!$member || !self::memberState($member)['selectable']) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该会员不存在、已停用或已注销，不能继续编辑。', CashierV3ResultCode::STATUS_FAILED);
        }
        $stores = self::visibleStoreIds($dataScope);
        if ($stores !== null) {
            if (!$stores || !Db::name('store_user')->where('uid', (int)$member['uid'])->where('status', 1)->whereIn('store_id', $stores)->value('uid')) {
                throw new CashierV3CommandException(CashierV3ResultCode::PERMISSION_DENIED, '当前账号没有编辑该会员的权限。', CashierV3ResultCode::STATUS_FAILED);
            }
        }
        return $member;
    }

    private static function editableMember(array $member): array
    {
        $uid = (int)($member['uid'] ?? 0);
        $rawExtendInfo = $member['extend_info'] ?? [];
        $extendRows = is_array($rawExtendInfo) ? $rawExtendInfo : (json_decode((string)$rawExtendInfo, true) ?: []);
        $stored = [];
        foreach ((array)$extendRows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string)($row['param'] ?? $row['key'] ?? $row['info'] ?? ''));
            if ($key !== '') $stored[$key] = $row['value'] ?? '';
        }
        $profileFields = [];
        foreach (self::memberCreatorSchema()['profileFields'] as $field) {
            $key = trim((string)($field['param'] ?? $field['key'] ?? $field['info'] ?? ''));
            if ($key !== '') $profileFields[$key] = $stored[$key] ?? $stored[(string)($field['info'] ?? '')] ?? '';
        }
        $tagIds = $uid > 0
            ? array_map('intval', (array)Db::name('user_label_relation')->where('uid', $uid)->where('type', 0)->where('relation_id', 0)->column('label_id'))
            : [];
        $exclusive = $uid > 0
            ? Db::name(self::EXCLUSIVE_SERVICE_TABLE)->where('member_id', $uid)->where('status', 1)->find()
            : null;
        $referrer = self::referrerProjection($member);
        return [
            'id' => (string)($member['uid'] ?? ''),
            'memberId' => (int)($member['uid'] ?? 0),
            'name' => trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? '')),
            'phone' => (string)($member['phone'] ?? ''),
            'idCard' => (string)($member['card_id'] ?? ''),
            'sex' => (int)($member['sex'] ?? 0),
            'birthday' => (int)($member['birthday'] ?? 0) > 0 ? date('Y-m-d', (int)$member['birthday']) : '',
            'address' => (string)($member['addres'] ?? ''),
            'note' => (string)($member['mark'] ?? ''),
            'memberLevelId' => (int)($member['level'] ?? 0),
            'memberTagIds' => array_values(array_unique($tagIds)),
            'profileFields' => $profileFields,
            'exclusiveServicePersonId' => (int)($exclusive['staff_id'] ?? 0),
            'exclusiveServiceStaffRecord' => $exclusive ?: null,
            'referrerMemberId' => (int)$referrer['member_id'],
            'referrerMemberName' => (string)$referrer['name'],
            'referrerLocked' => (bool)$referrer['locked'],
        ];
    }

    /**
     * 推荐关系仍以 user.spread_uid 为权威主数据；生命周期投影只提供首次疗程后的只读锁定状态。
     * @return array{member_id:int,name:string,locked:bool}
     */
    private static function referrerProjection(array $member): array
    {
        $memberId = (int)($member['uid'] ?? 0);
        $referrerId = (int)($member['spread_uid'] ?? 0);
        $name = '';
        if ($referrerId > 0) {
            $referrer = Db::name('user')->where('uid', $referrerId)->field('real_name,nickname')->find();
            $name = trim((string)($referrer['real_name'] ?? '')) ?: trim((string)($referrer['nickname'] ?? ''));
        }
        $locked = $memberId > 0 && (int)Db::name('cashier_v3_customer_lifecycle_projection')
            ->where('member_id', $memberId)
            ->max('first_course_completed_at') > 0;
        return ['member_id' => $referrerId, 'name' => $name, 'locked' => $locked];
    }

    /**
     * The legacy extend-info writer accepts only field `param` keys.  Older
     * callers and saved drafts may still use a field id, key or label, so
     * normalize those aliases before persisting.  Missing fields retain their
     * current value for a basic-profile-only edit; explicit empty values clear
     * the corresponding field.
     *
     * @param array<string,mixed> $submitted
     * @param array<string,mixed> $member
     * @return array<string,mixed>
     */
    private static function normalizeProfileFields(array $submitted, array $member = []): array
    {
        $storedRows = $member['extend_info'] ?? [];
        $storedRows = is_array($storedRows) ? $storedRows : (json_decode((string)$storedRows, true) ?: []);
        $stored = [];
        foreach ((array)$storedRows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string)($row['param'] ?? $row['key'] ?? $row['info'] ?? ''));
            if ($key !== '') $stored[$key] = $row['value'] ?? '';
        }

        $normalized = [];
        foreach (self::memberCreatorSchema()['profileFields'] as $fieldIndex => $field) {
            $param = trim((string)($field['param'] ?? $field['key'] ?? $field['info'] ?? ''));
            if ($param === '') continue;
            $aliases = array_values(array_unique(array_filter([
                $param,
                $field['fieldParam'] ?? null,
                $field['fieldKey'] ?? null,
                $field['field_key'] ?? null,
                $field['key'] ?? null,
                $field['id'] ?? null,
                $field['info'] ?? null,
                // The original cashier profile panel used this positional key
                // for legacy custom fields that have neither param nor id.
                // Accept it during the rollout, then persist by param/info.
                sprintf('profile-field-%d', $fieldIndex),
            ], static function ($value): bool {
                return $value !== null && trim((string)$value) !== '';
            })));
            $hasSubmittedValue = false;
            foreach ($aliases as $alias) {
                if (!array_key_exists((string)$alias, $submitted)) continue;
                $normalized[$param] = $submitted[(string)$alias];
                $hasSubmittedValue = true;
                break;
            }
            if (!$hasSubmittedValue) {
                $normalized[$param] = $stored[$param] ?? $stored[(string)($field['info'] ?? '')] ?? '';
            }
        }
        return $normalized;
    }

    /**
     * The legacy schema stores extension data in a LONGTEXT JSON column. Query
     * builder updates do not reliably coerce nested PHP arrays for that column,
     * so the V3 command serializes the complete canonical payload explicitly.
     * Values outside the editable schema are retained during an edit.
     *
     * @param array<int,array<string,mixed>> $configured
     * @param array<string,mixed> $submitted
     * @param array<string,mixed> $member
     */
    private static function encodeMemberExtendInfo(array $configured, array $submitted, array $member = []): string
    {
        $storedRows = $member['extend_info'] ?? [];
        $storedRows = is_array($storedRows) ? $storedRows : (json_decode((string)$storedRows, true) ?: []);
        $storedValues = [];
        foreach ((array)$storedRows as $storedRow) {
            if (!is_array($storedRow)) continue;
            $key = trim((string)($storedRow['param'] ?? $storedRow['key'] ?? $storedRow['info'] ?? ''));
            if ($key !== '') $storedValues[$key] = $storedRow['value'] ?? '';
        }
        foreach ($configured as &$field) {
            if (!is_array($field)) continue;
            $key = trim((string)($field['param'] ?? $field['key'] ?? $field['info'] ?? ''));
            if ($key !== '' && !array_key_exists($key, $submitted) && array_key_exists($key, $storedValues)) {
                $field['value'] = $storedValues[$key];
            }
        }
        unset($field);
        $encoded = json_encode($configured, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('member_extend_info_encode_failed');
        }
        return $encoded;
    }

    private static function recordMemberMutationEvent(
        string $eventType,
        string $sourceType,
        array $member,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        array $payload,
        $eventRecorder,
        $eventExecution,
        array $eventContract
    ): void {
        if (!$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw new CashierV3CommandException(CashierV3ResultCode::EVENT_OUTBOX_NOT_READY, '会员事件底座尚未就绪，本次操作已取消。', CashierV3ResultCode::STATUS_FAILED);
        }
        $memberId = (int)($member['uid'] ?? 0);
        if ($memberId <= 0 || trim($idempotencyKey) === '') {
            throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '会员操作幂等标识缺失，本次操作已取消。', CashierV3ResultCode::STATUS_FAILED);
        }
        $lastVersion = (int)Db::name('cashier_v3_business_event')
            ->where('aggregate_type', 'member')
            ->where('aggregate_id', (string)$memberId)
            ->max('aggregate_version');
        $now = time();
        $name = trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? ''));
        $payload = array_merge($payload, [
            'member_id' => $memberId,
            'member_no' => (string)($member['bar_code'] ?? ''),
            'name' => $name,
            'phone' => (string)($member['phone'] ?? ''),
        ]);
        $referrer = self::referrerProjection($member);
        $payload['referrer_member_id'] = (int)$referrer['member_id'];
        $payload['referrer_member_name_snapshot'] = (string)$referrer['name'];
        $payload['referrer_locked'] = (bool)$referrer['locked'];
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => $eventType,
            'aggregate_type' => 'member',
            'aggregate_id' => (string)$memberId,
            'aggregate_version' => max(1, $lastVersion + 1),
            'member_id' => $memberId,
            'source_type' => $sourceType,
            'source_id' => (string)$memberId,
            'occurred_at' => $now,
            'settled_at' => $now,
            'aggregate_name_snapshot' => $name,
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operatorScope->storeId())->value('name'),
            'organization_path' => (string)$operatorScope->organizationId(),
            'payload' => $payload,
        ]);
    }

    /**
     * 校验并规范化完整建档中的可落库选择项。
     * @return array{level_id:int,tag_ids:int[],exclusive_service_person:?array,exclusive_service_person_supplied:bool}
     */
    private static function validateProfileSelections(array $payload, CashierV3OperatorScope $operatorScope): array
    {
        $levelRaw = $payload['memberLevelId'] ?? $payload['level_id'] ?? $payload['level'] ?? null;
        $levelId = 0;
        if ($levelRaw !== null && $levelRaw !== '' && $levelRaw !== 0 && $levelRaw !== '0') {
            if (is_array($levelRaw) || !ctype_digit((string)$levelRaw) || (int)$levelRaw <= 0) {
                throw self::validation('memberLevelId', '会员等级参数无效。');
            }
            $levelId = (int)$levelRaw;
            $level = Db::name('system_user_level')
                ->where('id', $levelId)
                ->where('is_del', 0)
                ->where('is_show', 1)
                ->lock(true)
                ->find();
            if (!$level) {
                throw self::validation('memberLevelId', '所选会员等级不存在或已停用。');
            }
        }

        $tagRaw = $payload['memberTagIds'] ?? $payload['label_ids'] ?? $payload['label_id'] ?? [];
        if ($tagRaw === null || $tagRaw === '') {
            $tagRaw = [];
        }
        if (!is_array($tagRaw)) {
            $tagRaw = [$tagRaw];
        }
        $tagIds = [];
        foreach ($tagRaw as $tag) {
            if ($tag === '' || $tag === null) {
                continue;
            }
            if (is_array($tag) || !ctype_digit((string)$tag) || (int)$tag <= 0) {
                throw self::validation('memberTagIds', '会员标签参数无效。');
            }
            $tagIds[] = (int)$tag;
        }
        $tagIds = array_values(array_unique($tagIds));
        if (count($tagIds) > 100) {
            throw self::validation('memberTagIds', '会员标签最多选择100个。');
        }
        if ($tagIds) {
            $valid = Db::name('user_label')
                ->whereIn('id', $tagIds)
                ->where('type', 0)
                ->where('relation_id', 0)
                ->column('id');
            $valid = array_map('intval', (array)$valid);
            if (count(array_diff($tagIds, $valid)) !== 0) {
                throw self::validation('memberTagIds', '所选会员标签不存在或已失效。');
            }
        }

        $exclusiveServicePersonSupplied = array_key_exists('exclusiveServicePersonId', $payload)
            || array_key_exists('exclusive_service_person_id', $payload);
        $servicePersonRaw = $payload['exclusiveServicePersonId']
            ?? $payload['exclusive_service_person_id']
            ?? null;
        $exclusiveServicePerson = null;
        if ($servicePersonRaw !== null && $servicePersonRaw !== '' && $servicePersonRaw !== 0 && $servicePersonRaw !== '0') {
            if (is_array($servicePersonRaw)
                || !ctype_digit((string)$servicePersonRaw)
                || (int)$servicePersonRaw <= 0) {
                throw self::validation('exclusiveServicePersonId', '专属服务人参数无效。');
            }
            $staffId = (int)$servicePersonRaw;
            $staff = Db::name('system_store_staff')->alias('ss')
                ->join('employee e', 'e.id = ss.employee_id')
                ->where('ss.id', $staffId)
                ->where('ss.store_id', $operatorScope->storeId())
                ->where('ss.status', 1)
                ->where('ss.is_del', 0)
                ->where('ss.employee_id', '>', 0)
                ->where('ss.cashier_craftsman_enabled', 1)
                ->where('e.status', 1)
                ->where('e.is_del', 0)
                ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,e.name as employee_name')
                ->lock(true)
                ->find();
            if (!$staff) {
                throw self::validation('exclusiveServicePersonId', '所选人员已停用、离职、不属于当前门店或已关闭手艺人资格。');
            }
            $exclusiveServicePerson = [
                'staff_id' => (int)$staff['id'],
                'employee_id' => (int)$staff['employee_id'],
                'store_id' => (int)$staff['store_id'],
                'staff_name' => trim((string)($staff['employee_name'] ?? ''))
                    ?: (string)$staff['staff_name'],
            ];
        }

        return [
            'level_id' => $levelId,
            'tag_ids' => $tagIds,
            'exclusive_service_person' => $exclusiveServicePerson,
            'exclusive_service_person_supplied' => $exclusiveServicePersonSupplied,
        ];
    }

    /**
     * 更新会员的当前专属服务人，并追加不可覆盖的变更历史。
     * 未提交该字段的编辑由调用方跳过本方法；明确提交空值才表示解除绑定。
     */
    private static function updateExclusiveServicePerson(
        int $uid,
        ?array $person,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        int $occurredAt
    ): bool {
        CashierV3TransactionGuard::assertInTransaction('memberExclusiveServicePersonUpdate');
        if ($uid <= 0 || trim($idempotencyKey) === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员操作幂等标识缺失，本次操作已取消。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        $current = Db::name(self::EXCLUSIVE_SERVICE_TABLE)
            ->where('member_id', $uid)
            ->lock(true)
            ->find();
        $previous = self::exclusiveServiceSnapshot($current ?: []);
        $next = $person === null ? self::exclusiveServiceSnapshot([]) : self::exclusiveServiceSnapshot($person);
        $isCurrentActive = (int)($current['status'] ?? 0) === 1;
        if ($isCurrentActive && $previous === $next) {
            return false;
        }
        if (!$current && $person === null) {
            return false;
        }

        $operatorName = self::exclusiveServiceOperatorName($operatorScope, $dataScope);
        $sourceType = 'member_update';
        $sourceBusinessType = 'member';
        $sourceBusinessId = (string)$uid;
        $reason = $person === null ? '编辑会员时清空' : '编辑会员时指定';
        $changeKey = 'exclusive_service:' . $uid . ':' . trim($idempotencyKey);
        $storeName = '';
        if ($person !== null) {
            $storeName = (string)Db::name('system_store')
                ->where('id', (int)$next['store_id'])
                ->where('is_del', 0)
                ->where('is_show', 1)
                ->value('name');
            if ($storeName === '') {
                throw self::validation('exclusiveServicePersonId', '专属服务人所属门店不存在或已失效。');
            }
        }

        try {
            if ($current) {
                $affected = Db::name(self::EXCLUSIVE_SERVICE_TABLE)
                    ->where('id', (int)$current['id'])
                    ->where('version', (int)$current['version'])
                    ->update([
                        'staff_id' => (int)$next['staff_id'],
                        'employee_id' => (int)$next['employee_id'],
                        'store_id' => (int)$next['store_id'],
                        'staff_name' => (string)$next['staff_name'],
                        'store_name' => $storeName,
                        'source_type' => $sourceType,
                        'source_business_type' => $sourceBusinessType,
                        'source_business_id' => $sourceBusinessId,
                        'reason' => $reason,
                        'status' => $person === null ? 0 : 1,
                        'version' => (int)$current['version'] + 1,
                        'bound_at' => $occurredAt,
                        'operator_id' => $operatorScope->operatorId(),
                        'operator_name' => $operatorName,
                        'idempotency_key' => trim($idempotencyKey),
                        'updated_at' => $occurredAt,
                    ]);
                if ((int)$affected !== 1) {
                    throw new \RuntimeException('exclusive current relation update returned non-one');
                }
            } else {
                $inserted = Db::name(self::EXCLUSIVE_SERVICE_TABLE)->insert([
                    'member_id' => $uid,
                    'staff_id' => (int)$next['staff_id'],
                    'employee_id' => (int)$next['employee_id'],
                    'store_id' => (int)$next['store_id'],
                    'staff_name' => (string)$next['staff_name'],
                    'store_name' => $storeName,
                    'source_type' => $sourceType,
                    'source_business_type' => $sourceBusinessType,
                    'source_business_id' => $sourceBusinessId,
                    'reason' => $reason,
                    'status' => 1,
                    'version' => 1,
                    'bound_at' => $occurredAt,
                    'operator_id' => $operatorScope->operatorId(),
                    'operator_name' => $operatorName,
                    'idempotency_key' => trim($idempotencyKey),
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);
                if ((int)$inserted !== 1) {
                    throw new \RuntimeException('exclusive current relation insert returned non-one');
                }
            }
            $historyInserted = Db::name(self::EXCLUSIVE_SERVICE_CHANGE_TABLE)->insert([
                'change_key' => $changeKey,
                'member_id' => $uid,
                'previous_staff_id' => (int)$previous['staff_id'],
                'previous_employee_id' => (int)$previous['employee_id'],
                'previous_store_id' => (int)$previous['store_id'],
                'previous_staff_name' => (string)$previous['staff_name'],
                'previous_store_name' => (string)($current['store_name'] ?? ''),
                'current_staff_id' => (int)$next['staff_id'],
                'current_employee_id' => (int)$next['employee_id'],
                'current_store_id' => (int)$next['store_id'],
                'current_staff_name' => (string)$next['staff_name'],
                'current_store_name' => $storeName,
                'source_type' => $sourceType,
                'source_business_type' => $sourceBusinessType,
                'source_business_id' => $sourceBusinessId,
                'reason' => $reason,
                'operator_id' => $operatorScope->operatorId(),
                'operator_name' => $operatorName,
                'idempotency_key' => trim($idempotencyKey),
                'occurred_at' => $occurredAt,
                'recorded_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
            if ((int)$historyInserted !== 1) {
                throw new \RuntimeException('exclusive change insert returned non-one');
            }
        } catch (CashierV3CommandException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '专属服务人记录保存失败，本次会员资料修改已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_tables' => ['eb_' . self::EXCLUSIVE_SERVICE_TABLE, 'eb_' . self::EXCLUSIVE_SERVICE_CHANGE_TABLE]]
            );
        }
        return true;
    }

    /** @return array{staff_id:int,employee_id:int,store_id:int,staff_name:string} */
    private static function exclusiveServiceSnapshot(array $record): array
    {
        return [
            'staff_id' => (int)($record['staff_id'] ?? 0),
            'employee_id' => (int)($record['employee_id'] ?? 0),
            'store_id' => (int)($record['store_id'] ?? 0),
            'staff_name' => trim((string)($record['staff_name'] ?? '')),
        ];
    }

    private static function exclusiveServiceOperatorName(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): string {
        $operatorProfile = $dataScope->operatorProfile();
        $operatorName = trim((string)($operatorProfile['staff_name']
            ?? $operatorProfile['real_name']
            ?? $operatorProfile['account']
            ?? ''));
        if ($operatorName === '') {
            $operatorName = (string)Db::name('system_store_staff')
                ->where('id', $operatorScope->operatorId())
                ->value('staff_name');
        }
        return $operatorName !== '' ? $operatorName : '操作人#' . $operatorScope->operatorId();
    }

    /**
     * 事务内从单行序列表分配永久唯一的 9 位会员编号。
     *
     * 序列表保存“最后已分配值”，固定 sequence_key=member_bar_code。所有 V3
     * 建档先锁同一行再推进，禁止回退到随机数、时间戳或 MAX()+1。若迁移前的
     * 历史编号占用了候选号，则在持锁状态下继续向后跳过。
     */
    private static function nextMemberNumber(): string
    {
        CashierV3TransactionGuard::assertInTransaction('memberNumberSequence');

        try {
            $sequence = Db::name(self::NUMBER_SEQUENCE_TABLE)
                ->where('sequence_key', self::NUMBER_SEQUENCE_KEY)
                ->lock(true)
                ->find();
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员编号底座尚未就绪，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_table' => 'eb_' . self::NUMBER_SEQUENCE_TABLE]
            );
        }
        if (!$sequence || !array_key_exists('current_value', $sequence)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员编号序列尚未初始化，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['sequence_key' => self::NUMBER_SEQUENCE_KEY]
            );
        }

        $rawCurrent = $sequence['current_value'];
        if (!is_int($rawCurrent) && !ctype_digit((string)$rawCurrent)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员编号序列数据异常，已停止建档。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'member_number_sequence_invalid']
            );
        }
        $current = (int)$rawCurrent;
        if ($current < self::MEMBER_NUMBER_MIN - 1 || $current > self::MEMBER_NUMBER_MAX) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员编号序列超出 9 位数字范围，已停止建档。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'member_number_sequence_out_of_range', 'current_value' => $current]
            );
        }

        $candidate = $current;
        $skipCount = 0;
        do {
            if ($candidate >= self::MEMBER_NUMBER_MAX) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '会员编号可用空间已耗尽，暂时不能新增会员。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'member_number_exhausted']
                );
            }
            $candidate++;
            // user.bar_code 目前只有历史索引，候选号仍用 FOR UPDATE 读取；在
            // InnoDB 默认隔离级别下同时锁住命中行或索引间隙，缩小旧入口并发
            // 生成同号的窗口。V3 入口的最终互斥仍由序列行负责。
            $occupied = (bool)Db::name('user')
                ->where('bar_code', (string)$candidate)
                ->field('uid')
                ->lock(true)
                ->find();
            if ($occupied) {
                $skipCount++;
            }
            if ($skipCount > self::MEMBER_NUMBER_MAX_SKIP) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '会员编号序列与历史编号未对齐，已停止建档。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'member_number_sequence_seed_too_old', 'skip_count' => $skipCount]
                );
            }
        } while ($occupied);

        try {
            $affected = Db::name(self::NUMBER_SEQUENCE_TABLE)
                ->where('sequence_key', self::NUMBER_SEQUENCE_KEY)
                ->where('current_value', $current)
                ->update([
                    'current_value' => $candidate,
                    'updated_at' => time(),
                ]);
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员编号序列推进失败，本次建档已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'member_number_sequence_update_failed']
            );
        }
        if ((int)$affected !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员编号序列推进冲突，本次建档已取消，请重试。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'member_number_sequence_conflict']
            );
        }

        $memberNo = (string)$candidate;
        if (!preg_match('/^[1-9]\d{8}$/', $memberNo)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员编号生成结果异常，本次建档已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'member_number_format_invalid']
            );
        }
        return $memberNo;
    }

    /**
     * 新增会员时同时写入专属服务人当前关系与首次变更历史。
     * 两张表、会员主表和 member.created Outbox 共用 Gateway 外层事务。
     */
    private static function assignExclusiveServicePerson(
        int $uid,
        array $person,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        int $occurredAt
    ): void {
        CashierV3TransactionGuard::assertInTransaction('memberExclusiveServicePerson');

        $staffId = (int)($person['staff_id'] ?? 0);
        $employeeId = (int)($person['employee_id'] ?? 0);
        $storeId = (int)($person['store_id'] ?? 0);
        $staffName = trim((string)($person['staff_name'] ?? ''));
        if ($uid <= 0 || $staffId <= 0 || $employeeId <= 0
            || $storeId !== $operatorScope->storeId() || $staffName === '') {
            throw self::validation('exclusiveServicePersonId', '专属服务人信息不完整，请重新选择。');
        }

        // 在真正写关系前再次锁定人员主档与当前门店任职，防止选择后离职。
        $staff = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
            ->where('ss.id', $staffId)
            ->where('ss.employee_id', $employeeId)
            ->where('ss.store_id', $storeId)
            ->where('ss.status', 1)
            ->where('ss.is_del', 0)
            ->where('ss.cashier_craftsman_enabled', 1)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,e.name as employee_name')
            ->lock(true)
            ->find();
        if (!$staff) {
            throw self::validation('exclusiveServicePersonId', '所选人员已停用、离职、不属于当前门店或已关闭手艺人资格。');
        }
        $staffName = trim((string)($staff['employee_name'] ?? ''))
            ?: trim((string)($staff['staff_name'] ?? ''));
        $storeName = (string)Db::name('system_store')
            ->where('id', $storeId)
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->value('name');
        if ($storeName === '') {
            throw self::validation('exclusiveServicePersonId', '专属服务人所属门店不存在或已失效。');
        }

        $operatorProfile = $dataScope->operatorProfile();
        $operatorName = trim((string)($operatorProfile['staff_name']
            ?? $operatorProfile['real_name']
            ?? $operatorProfile['account']
            ?? ''));
        if ($operatorName === '') {
            $operatorName = (string)Db::name('system_store_staff')
                ->where('id', $operatorScope->operatorId())
                ->value('staff_name');
        }
        if ($operatorName === '') {
            $operatorName = '操作人#' . $operatorScope->operatorId();
        }

        $sourceType = 'member_create';
        $sourceBusinessType = 'member';
        $sourceBusinessId = (string)$uid;
        $reason = '新增会员时指定';
        $normalizedIdempotencyKey = trim($idempotencyKey);
        if ($normalizedIdempotencyKey === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员建档幂等标识缺失，本次建档已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'exclusive_service_idempotency_key_missing']
            );
        }
        $changeKey = 'exclusive_service:' . $uid . ':' . $normalizedIdempotencyKey;
        try {
            $existing = Db::name(self::EXCLUSIVE_SERVICE_TABLE)
                ->where('member_id', $uid)
                ->lock(true)
                ->find();
            if ($existing) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '会员专属服务人关系已存在，本次建档已取消。',
                    CashierV3ResultCode::STATUS_CONFLICT,
                    ['reason' => 'exclusive_service_relation_exists', 'member_id' => $uid]
                );
            }

            $currentInserted = Db::name(self::EXCLUSIVE_SERVICE_TABLE)->insert([
                'member_id' => $uid,
                'staff_id' => $staffId,
                'employee_id' => $employeeId,
                'store_id' => $storeId,
                'staff_name' => $staffName,
                'store_name' => $storeName,
                'source_type' => $sourceType,
                'source_business_type' => $sourceBusinessType,
                'source_business_id' => $sourceBusinessId,
                'reason' => $reason,
                'status' => 1,
                'version' => 1,
                'bound_at' => $occurredAt,
                'operator_id' => $operatorScope->operatorId(),
                'operator_name' => $operatorName,
                'idempotency_key' => $normalizedIdempotencyKey,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
            if ((int)$currentInserted !== 1) {
                throw new \RuntimeException('exclusive current relation insert returned non-one');
            }

            $changeInserted = Db::name(self::EXCLUSIVE_SERVICE_CHANGE_TABLE)->insert([
                'change_key' => $changeKey,
                'member_id' => $uid,
                'previous_staff_id' => 0,
                'previous_employee_id' => 0,
                'previous_store_id' => 0,
                'previous_staff_name' => '',
                'previous_store_name' => '',
                'current_staff_id' => $staffId,
                'current_employee_id' => $employeeId,
                'current_store_id' => $storeId,
                'current_staff_name' => $staffName,
                'current_store_name' => $storeName,
                'source_type' => $sourceType,
                'source_business_type' => $sourceBusinessType,
                'source_business_id' => $sourceBusinessId,
                'reason' => $reason,
                'operator_id' => $operatorScope->operatorId(),
                'operator_name' => $operatorName,
                'idempotency_key' => $normalizedIdempotencyKey,
                'occurred_at' => $occurredAt,
                'recorded_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
            if ((int)$changeInserted !== 1) {
                throw new \RuntimeException('exclusive change insert returned non-one');
            }
        } catch (CashierV3CommandException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '专属服务人记录底座尚未就绪，本次建档已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'missing_tables' => [
                        'eb_' . self::EXCLUSIVE_SERVICE_TABLE,
                        'eb_' . self::EXCLUSIVE_SERVICE_CHANGE_TABLE,
                    ],
                ]
            );
        }
    }

    /**
     * 对同一手机号建立数据库行锁。user.phone 是历史普通索引，不能依赖
     * 不存在行的 FOR UPDATE；专用锁表把同一手机号的并发建档串行化，兼容
     * 旧库中已有空号/重复号数据，不修改历史 user 表索引。
     */
    private static function lockPhoneResource(string $phone): void
    {
        CashierV3TransactionGuard::assertInTransaction('memberPhoneResource');
        try {
            Db::name(self::PHONE_LOCK_TABLE)->insert([
                'phone' => $phone,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // 已有锁行是正常并发路径；其他错误（尤其锁表未升级）必须拒绝。
            $message = strtolower($e->getMessage());
            if (strpos($message, 'duplicate') === false
                && strpos($message, '1062') === false
                && strpos($message, 'unique') === false) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '会员建档底座尚未就绪，请联系管理员完成升级后再试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['missing_table' => 'eb_' . self::PHONE_LOCK_TABLE]
                );
            }
        }
        $locked = Db::name(self::PHONE_LOCK_TABLE)->where('phone', $phone)->lock(true)->find();
        if (!$locked) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员建档锁定失败，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['resource' => 'member_phone']
            );
        }
    }

    /**
     * 会员主表、门店关系和统一业务事件共用 Gateway 事务。
     * member.created 当前没有真实消费者，因此只写 business_event，
     * 不制造 no-op Outbox 行。
     */
    private static function recordMemberCreatedEvent(
        int $uid,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $idempotencyKey,
        int $occurredAt,
        array $profile,
        string $name,
        string $phone,
        string $memberNo,
        $eventRecorder,
        $eventExecution,
        array $eventContract
    ): void {
        if (!$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::EVENT_OUTBOX_NOT_READY,
                '会员事件底座尚未就绪，本次建档已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'event_recorder_missing']
            );
        }
        $exclusiveServicePerson = is_array($profile['exclusive_service_person'] ?? null)
            ? $profile['exclusive_service_person']
            : [];
        $payload = [
            'member_id' => $uid,
            'member_no' => $memberNo,
            'name' => $name,
            'phone' => $phone,
            'store_id' => $operatorScope->storeId(),
            'level_id' => (int)$profile['level_id'],
            'tag_ids' => array_values($profile['tag_ids']),
            'exclusive_service_person_id' => (int)($exclusiveServicePerson['staff_id'] ?? 0),
            'exclusive_service_person_employee_id' => (int)($exclusiveServicePerson['employee_id'] ?? 0),
            'source' => 'cashier_v3',
        ];
        $member = Db::name('user')->where('uid', $uid)->field('uid,spread_uid')->find() ?: ['uid' => $uid];
        $referrer = self::referrerProjection($member);
        $payload['referrer_member_id'] = (int)$referrer['member_id'];
        $payload['referrer_member_name_snapshot'] = (string)$referrer['name'];
        $payload['referrer_locked'] = (bool)$referrer['locked'];
        $storeName = (string)Db::name('system_store')->where('id', $operatorScope->storeId())->value('name');
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'member.created',
            'aggregate_type' => 'member',
            'aggregate_id' => (string)$uid,
            'aggregate_version' => 1,
            'member_id' => $uid,
            'occurred_at' => $occurredAt,
            'settled_at' => $occurredAt,
            'aggregate_name_snapshot' => $name,
            'store_name_snapshot' => $storeName,
            'organization_path' => $operatorScope->organizationId(),
            'payload' => $payload,
        ]);
    }

    /**
     * @param int[] $storeIds
     * @param array<int,string> $known
     * @return array<int,string>
     */
    private static function organizationNamesForStores(array $storeIds, array $known = []): array
    {
        if (!$storeIds) {
            return $known;
        }
        try {
            $rows = Db::name('organization_store')->alias('os')
                ->leftJoin('organization o', 'o.id = os.org_id')
                ->whereIn('os.store_id', $storeIds)
                ->field('os.store_id,o.name as organization_name')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $storeId = (int)($row['store_id'] ?? 0);
                if ($storeId > 0) {
                    $known[$storeId] = (string)($row['organization_name'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // 组织名称仅是展示快照；排序函数已提供空名称的安全退化。
        }
        return $known;
    }

    /**
     * @return array{label:string,selectable:bool,reason:string,code:string}
     */
    private static function memberState(array $row): array
    {
        $deleteTime = $row['delete_time'] ?? null;
        $isDeleted = (int)($row['is_del'] ?? 0) !== 0 || !($deleteTime === null
            || $deleteTime === ''
            || $deleteTime === 0
            || $deleteTime === '0'
            || $deleteTime === '0000-00-00 00:00:00');
        if ($isDeleted) {
            return [
                'label' => '已注销',
                'selectable' => false,
                'reason' => '该会员已注销，不能办理新的收银、预约或核销业务。',
                'code' => 'member_cancelled',
            ];
        }
        if ((int)($row['status'] ?? 1) !== 1) {
            return [
                'label' => '已停用',
                'selectable' => false,
                'reason' => '该会员已停用，请先恢复正常后再办理业务。',
                'code' => 'member_disabled',
            ];
        }
        return [
            'label' => '正常',
            'selectable' => true,
            'reason' => '',
            'code' => 'member_active',
        ];
    }

    private static function formatMemberRow(array $row, int $currentStoreId): array
    {
        $storeId = (int)($row['belong_store_id'] ?? $currentStoreId);
        $state = self::memberState($row);
        $name = trim((string)($row['real_name'] ?? '')) ?: trim((string)($row['nickname'] ?? ''));
        $referrer = self::referrerProjection($row);
        return [
            'id' => (string)($row['uid'] ?? 0),
            // 下游写入（如独立赠送兼容权益）使用 user.uid 作为权威会员外键。
            'uid' => (int)($row['uid'] ?? 0),
            'memberId' => (int)($row['uid'] ?? 0),
            'name' => $name !== '' ? $name : '未命名会员',
            'phone' => (string)($row['phone'] ?? ''),
            'memberNo' => (string)($row['bar_code'] ?? ''),
            'status' => $state['label'],
            'statusLabel' => $state['label'],
            'storeId' => $storeId,
            'storeName' => (string)Db::name('system_store')->where('id', $storeId)->value('name'),
            'organizationName' => (string)(self::organizationNamesForStores([$storeId])[$storeId] ?? ''),
            'selectable' => $state['selectable'],
            'disabledReason' => $state['reason'],
            'referrerMemberId' => (int)$referrer['member_id'],
            'referrerMemberName' => (string)$referrer['name'],
            'referrerLocked' => (bool)$referrer['locked'],
        ];
    }

    private static function withBestDisplayStore(
        array $row,
        CashierV3OperatorScope $operatorScope
    ): array {
        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            return $row;
        }
        $priority = self::priorityContext($operatorScope, null);
        $resolved = self::resolveDisplayStoreIds(
            [$uid],
            [$row],
            null,
            $priority['storeRanks']
        );
        if ((int)($resolved[$uid] ?? 0) > 0) {
            $row['belong_store_id'] = (int)$resolved[$uid];
        }
        return $row;
    }

    private static function validation(string $field, string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['fieldErrors' => [$field => $message]]
        );
    }

    /** @return array{profileFields:array<int,array<string,mixed>>,memberLevels:array<int,array<string,mixed>>,memberTags:array<int,array<string,mixed>>} */
    private static function memberCreatorSchema(): array
    {
        $profileFields = array_values(array_filter(
            (array)SystemConfigService::get('user_extend_info', []),
            static function ($field): bool {
                return is_array($field) && !empty($field['use']);
            }
        ));
        usort($profileFields, static function (array $left, array $right): int {
            return (int)($left['sort'] ?? 0) <=> (int)($right['sort'] ?? 0);
        });

        $memberLevels = array_map(static function (array $row): array {
            return ['value' => (int)$row['id'], 'label' => (string)$row['name']];
        }, Db::name('system_user_level')
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->field('id,name')
            ->order('grade asc,id asc')
            ->select()
            ->toArray());
        $memberTags = array_map(static function (array $row): array {
            return ['value' => (int)$row['id'], 'label' => (string)$row['label_name']];
        }, Db::name('user_label')
            ->where('type', 0)
            ->where('relation_id', 0)
            ->field('id,label_name')
            ->order('id asc')
            ->select()
            ->toArray());

        return compact('profileFields', 'memberLevels', 'memberTags');
    }

    private static function normalizeBirthday($raw): int
    {
        if ($raw === null || trim((string)$raw) === '') {
            return 0;
        }
        $birthday = trim((string)$raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw self::validation('birthday', '请填写正确的生日日期。');
        }
        $timestamp = strtotime($birthday . ' 00:00:00');
        if ($timestamp === false || $timestamp > strtotime(date('Y-m-d') . ' 23:59:59')) {
            throw self::validation('birthday', '生日不能晚于今天。');
        }
        return (int)$timestamp;
    }
}
