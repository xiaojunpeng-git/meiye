<?php

/**
 * 统一查询会员首发字段权威口径真实 Gateway 门禁。
 */
require __DIR__ . '/../../cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../../cashier-v3/lib/_lib.php';
require __DIR__ . '/../../cashier-v3/lib/TestGraphFactory.php';
require __DIR__ . '/../../cashier-v3/lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\query\UnifiedQueryException;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
date_default_timezone_set('Asia/Shanghai');
MemberIntegrationFixture::bindTestAdapters();

function uqMemberRequestId(string $prefix): string
{
    return $prefix . '-' . str_replace('-', '', uuid());
}

function uqMemberQuery(
    $dispatcher,
    string $phone,
    array &$session,
    array $overrides = []
): array
{
    $request = array_merge([
        'action' => 'query-members',
        'pageCode' => 'member_list',
        'page' => 1,
        'pageSize' => 20,
        'keyword' => $phone,
        'dataScope' => 'all',
        'businessStatus' => '',
        'clientSessionId' => (string)$session['client_session_id'],
        'stateContextId' => (string)($session['state_context_id'] ?? ''),
        'correlationId' => uqMemberRequestId('CORR'),
    ], $overrides);
    $envelope = $dispatcher->dispatch($request, $session);
    if ((string)($session['state_context_id'] ?? '') === '') {
        $session['state_context_id'] = (string)($envelope['stateContextId'] ?? '');
    }
    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    return [
        'request' => $request,
        'envelope' => $envelope,
        'data' => $data,
        'record' => (array)($data['records'][0] ?? []),
    ];
}

try {
    MemberIntegrationFixture::ensureLegacySchema();
    MemberIntegrationFixture::resetAndSeed();

    MemberIntegrationFixture::seedMember(
        901,
        '首发指标会员',
        '13900000901',
        MemberIntegrationFixture::STORE_ID
    );
    MemberIntegrationFixture::seedMember(
        902,
        '服务人回退会员',
        '13900000902',
        MemberIntegrationFixture::STORE_ID
    );
    MemberIntegrationFixture::seedMember(
        903,
        '跨时区到店会员',
        '13900000903',
        MemberIntegrationFixture::STORE_ID
    );
    // 同一会员同时归属门店 A/B，但专属服务人绑定仅属于 B。当前登录范围仅 A 时，
    // 该字段不得在列表或导出泄漏；这也覆盖了数据权限必须先进入投影补充查询。
    MemberIntegrationFixture::seedMember(
        904,
        '跨店专属服务人会员',
        '13900000904',
        MemberIntegrationFixture::STORE_ID
    );
    Db::name('store_user')->insert([
        'uid' => 904,
        'store_id' => 9,
        'status' => 1,
        'add_time' => time(),
    ]);
    Db::name('member_exclusive_service')->insert([
        'member_id' => 904,
        'staff_id' => 91,
        'employee_id' => 91,
        'store_id' => 9,
        'staff_name' => '门店B专属服务人',
        'store_name' => '同组织店',
        'source_type' => 'manual',
        'source_business_type' => 'manual',
        'source_business_id' => 'UQ-STORE-B-904',
        'reason' => '跨店隔离回归',
        'status' => 1,
        'version' => 1,
        'bound_at' => time(),
        'operator_id' => 91,
        'operator_name' => '门店B操作员',
        'idempotency_key' => 'UQ-EXCLUSIVE-904',
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    Db::name('store_order')->insertAll([
        [
            'id' => 9101,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
            'refund_status' => 0,
            'terminal_action' => 0,
            'card_upgrade_use_oid' => 0,
            'pid' => -2,
            'order_type' => 1,
            'is_debt_repay' => 0,
            'order_id' => 'UQ-CARD-ACTIVE',
            'pay_price' => '300.00',
            'add_time' => time(),
            'pay_time' => time(),
        ],
        [
            'id' => 9102,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
            'refund_status' => 1,
            'terminal_action' => 0,
            'card_upgrade_use_oid' => 0,
            'pid' => -2,
            'order_type' => 1,
            'is_debt_repay' => 0,
            'order_id' => 'UQ-CARD-REFUNDED',
            'pay_price' => '400.00',
            'add_time' => time(),
            'pay_time' => time(),
        ],
        [
            'id' => 9103,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
            'refund_status' => 0,
            'terminal_action' => 0,
            'card_upgrade_use_oid' => 0,
            'pid' => -2,
            'order_type' => 1,
            'is_debt_repay' => 0,
            'order_id' => 'UQ-CARD-DELETED-HOLDER',
            'pay_price' => '500.00',
            'add_time' => time(),
            'pay_time' => time(),
        ],
        [
            'id' => 9104,
            'uid' => 901,
            'store_id' => 9,
            'paid' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
            'refund_status' => 0,
            'terminal_action' => 0,
            'card_upgrade_use_oid' => 0,
            'pid' => -2,
            'order_type' => 1,
            'is_debt_repay' => 0,
            'order_id' => 'UQ-CARD-OTHER-STORE',
            'pay_price' => '600.00',
            'add_time' => time(),
            'pay_time' => time(),
        ],
    ]);
    Db::name('user_card_holder')->insertAll([
        [
            'uid' => 901,
            'oid' => 9101,
            'card_name' => '有效疗程卡',
            'card_no' => 'CARD-UQ-01',
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'write_surplus_times' => 3,
            'is_del' => 0,
        ],
        [
            'uid' => 901,
            'oid' => 9102,
            'card_name' => '已退款卡',
            'card_no' => 'CARD-UQ-02',
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'write_surplus_times' => 4,
            'is_del' => 0,
        ],
        [
            'uid' => 901,
            'oid' => 9103,
            'card_name' => '已删除持卡记录',
            'card_no' => 'CARD-UQ-03',
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'write_surplus_times' => 5,
            'is_del' => 1,
        ],
        [
            'uid' => 901,
            'oid' => 9104,
            'card_name' => '越权门店卡',
            'card_no' => 'CARD-UQ-04',
            'store_id' => 9,
            'write_surplus_times' => 6,
            'is_del' => 0,
        ],
    ]);
    Db::name('store_order_cart_info')->insertAll([
        [
            'oid' => 9101,
            'cart_id' => 'UQ-CART-01',
            'product_id' => 101,
            'cart_type' => 2,
            'product_type' => 6,
            'write_times' => 6,
            'write_surplus_times' => 3,
            'is_writeoff' => 0,
            'pay_price' => '300.00',
        ],
        [
            'oid' => 9101,
            'cart_id' => 'UQ-CART-NON-PROJECT',
            'product_id' => 102,
            'cart_type' => 1,
            'product_type' => 1,
            'write_times' => 10,
            'write_surplus_times' => 10,
            'is_writeoff' => 0,
            'pay_price' => '999.00',
        ],
    ]);

    Db::name('store_debt')->insertAll([
        [
            'order_id' => 9201,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'status' => 0,
            'total_debt' => '100.00',
            'repaid_debt' => '30.00',
        ],
        [
            'order_id' => 9202,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'status' => 0,
            'total_debt' => '20.00',
            'repaid_debt' => '25.00',
        ],
        [
            'order_id' => 9203,
            'uid' => 901,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'status' => 1,
            'total_debt' => '80.00',
            'repaid_debt' => '0.00',
        ],
        [
            'order_id' => 9204,
            'uid' => 901,
            'store_id' => 9,
            'status' => 0,
            'total_debt' => '500.00',
            'repaid_debt' => '0.00',
        ],
    ]);

    $visitDayOne = strtotime(date('Y-m-d', strtotime('-5 days')) . ' 09:00:00');
    $visitDayOneLater = strtotime(date('Y-m-d', strtotime('-5 days')) . ' 16:00:00');
    $latestVisit = strtotime(date('Y-m-d', strtotime('-1 day')) . ' 10:00:00');
    $ignoredCancelledVisit = strtotime(date('Y-m-d') . ' 12:00:00');
    $ignoredOtherStoreVisit = strtotime(date('Y-m-d') . ' 11:00:00');
    $fallbackVisit = strtotime(date('Y-m-d', strtotime('-2 days')) . ' 14:00:00');
    $crossMidnightVisitEarly = (new \DateTimeImmutable(
        '2026-07-28 00:30:00',
        new \DateTimeZone('Asia/Shanghai')
    ))->getTimestamp();
    $crossMidnightVisitLate = (new \DateTimeImmutable(
        '2026-07-28 08:30:00',
        new \DateTimeZone('Asia/Shanghai')
    ))->getTimestamp();
    Db::name('store_order_writeoff')->insertAll([
        [
            'id' => 9301,
            'uid' => 901,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $visitDayOne,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9302,
            'uid' => 901,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $visitDayOneLater,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9303,
            'uid' => 901,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $latestVisit,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9304,
            'uid' => 901,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $ignoredCancelledVisit,
            'status' => 1,
            'staff_id' => 20,
        ],
        [
            'id' => 9305,
            'uid' => 901,
            'relation_id' => 9,
            'add_time' => $ignoredOtherStoreVisit,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9310,
            'uid' => 902,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $fallbackVisit,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9311,
            'uid' => 903,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $crossMidnightVisitEarly,
            'status' => 0,
            'staff_id' => 20,
        ],
        [
            'id' => 9312,
            'uid' => 903,
            'relation_id' => MemberIntegrationFixture::STORE_ID,
            'add_time' => $crossMidnightVisitLate,
            'status' => 0,
            'staff_id' => 20,
        ],
    ]);
    Db::name('staff_yeji')->insertAll([
        [
            'id' => 9401,
            'staff_id' => 31,
            'staff_name' => '历史技师甲',
            'type' => 3,
            'status' => 0,
            'link_id' => 9303,
            'store_id' => MemberIntegrationFixture::STORE_ID,
        ],
        [
            'id' => 9402,
            'staff_id' => 32,
            'staff_name' => '历史技师乙',
            'type' => 3,
            'status' => 0,
            'link_id' => 9303,
            'store_id' => MemberIntegrationFixture::STORE_ID,
        ],
        [
            'id' => 9403,
            'staff_id' => 33,
            'staff_name' => '历史技师甲',
            'type' => 3,
            'status' => 0,
            'link_id' => 9303,
            'store_id' => MemberIntegrationFixture::STORE_ID,
        ],
        [
            'id' => 9404,
            'staff_id' => 34,
            'staff_name' => '错误业绩类型',
            'type' => 2,
            'status' => 0,
            'link_id' => 9303,
            'store_id' => MemberIntegrationFixture::STORE_ID,
        ],
        [
            'id' => 9405,
            'staff_id' => 35,
            'staff_name' => '已冲销快照',
            'type' => 3,
            'status' => 1,
            'link_id' => 9303,
            'store_id' => MemberIntegrationFixture::STORE_ID,
        ],
    ]);

    // 故意把数据库会话设为 UTC，证明到店日不依赖连接时区。
    Db::execute("SET time_zone = '+00:00'");
    $sessionTimeZone = (string)(Db::query('SELECT @@session.time_zone AS time_zone')[0]['time_zone'] ?? '');
    $dispatcher = MemberIntegrationFixture::dispatcher();
    $session = MemberIntegrationFixture::projectionSession(1);
    $primary = uqMemberQuery($dispatcher, '13900000901', $session);
    $fallback = uqMemberQuery($dispatcher, '13900000902', $session);
    $crossTimeZone = uqMemberQuery($dispatcher, '13900000903', $session);
    $crossStoreExclusive = uqMemberQuery($dispatcher, '13900000904', $session);
    $primaryRecord = $primary['record'];
    $fallbackRecord = $fallback['record'];
    $crossTimeZoneRecord = $crossTimeZone['record'];
    $crossStoreExclusiveRecord = $crossStoreExclusive['record'];

    $runtime = UnifiedQueryModule::runtime();
    $scopedDataScope = MemberIntegrationFixture::dataScope($dispatcher, 1);
    $scopedExportContext = $runtime['contextFactory']->make(
        MemberIntegrationFixture::operatorScope(1),
        $scopedDataScope,
        []
    );
    $scopedExportPlan = [
        'page_code' => 'member_list',
        'query_cutoff_date' => (string)$scopedExportContext['query_cutoff_date'],
        'stable_row_key' => 'member_id',
        'filters' => [[
            'field_key' => 'phone',
            'operator' => 'contains',
            'value' => '13900000904',
        ]],
        'top_filters' => [],
        'keyword_filters' => [],
        'filter_relation' => 'all',
        'sorts' => [['field_key' => 'member_id', 'direction' => 'asc']],
        'groups' => [],
        'summaries' => [],
        'pagination' => ['page' => 1, 'limit' => 20],
        'custom_definitions' => [],
        'domain_scope' => ['data_scope' => 'all', 'business_status' => ''],
        'quick_filters' => [],
        'visible_fields' => ['member_name', 'exclusive_service_staff'],
        'permission_must_be_injected_before_calculation' => true,
    ];
    $scopedExport = $runtime['memberProvider']->executeFrozenPlan(
        $scopedExportContext,
        $scopedExportPlan,
        'query',
        ['member_name', 'exclusive_service_staff']
    );
    $scopedExportRow = (array)($scopedExport['exportRows'][0] ?? []);

    ok(
        '有效卡、剩余项目与待还欠款均按权威状态和门店范围汇总',
        ($primary['envelope']['result']['status'] ?? '') === 'success'
            && (int)($primary['data']['total'] ?? 0) === 1
            && (int)($primaryRecord['memberId'] ?? 0) === 901
            && (int)($primaryRecord['activeCardCount'] ?? -1) === 1
            && (int)($primaryRecord['remainingProjectTimes'] ?? -1) === 3
            && (string)($primaryRecord['remainingProjectAmount'] ?? '') === '150.00'
            && (string)($primaryRecord['debtAmount'] ?? '') === '70.00',
        json_encode(compact('primaryRecord'), JSON_UNESCAPED_UNICODE),
        'UQ-GW-06'
    );
    ok(
        '到店按有效核销日期去重，最近服务人优先历史业绩快照且缺失时回退当前员工名',
        ($fallback['envelope']['result']['status'] ?? '') === 'success'
            && (int)($primaryRecord['visitCount'] ?? -1) === 2
            && (string)($primaryRecord['latestVisitDate'] ?? '')
                === date('Y-m-d', $latestVisit)
            && (string)($primaryRecord['lastServiceStaff'] ?? '')
                === '历史技师甲、历史技师乙'
            && (int)($fallback['data']['total'] ?? 0) === 1
            && (int)($fallbackRecord['memberId'] ?? 0) === 902
            && (int)($fallbackRecord['visitCount'] ?? -1) === 1
            && (string)($fallbackRecord['latestVisitDate'] ?? '')
                === date('Y-m-d', $fallbackVisit)
            && (string)($fallbackRecord['lastServiceStaff'] ?? '')
                === '专属服务人甲',
        json_encode(compact(
            'primaryRecord',
            'fallbackRecord',
            'visitDayOne',
            'visitDayOneLater',
            'latestVisit',
            'ignoredCancelledVisit',
            'ignoredOtherStoreVisit',
            'fallbackVisit'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-07'
    );
    ok(
        '到店去重与最近到店日期固定使用业务时区，不受 MySQL 会话时区影响',
        $sessionTimeZone === '+00:00'
            && ($crossTimeZone['envelope']['result']['status'] ?? '') === 'success'
            && (int)($crossTimeZoneRecord['memberId'] ?? 0) === 903
            && (int)($crossTimeZoneRecord['visitCount'] ?? -1) === 1
            && (string)($crossTimeZoneRecord['latestVisitDate'] ?? '') === '2026-07-28',
        json_encode(compact(
            'sessionTimeZone',
            'crossTimeZoneRecord',
            'crossMidnightVisitEarly',
            'crossMidnightVisitLate'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-08'
    );
    ok(
        '跨店专属服务人先按数据权限过滤，会员列表与冻结导出均不泄漏门店 B 绑定',
        ($crossStoreExclusive['envelope']['result']['status'] ?? '') === 'success'
            && (int)($crossStoreExclusiveRecord['memberId'] ?? 0) === 904
            && (string)($crossStoreExclusiveRecord['exclusiveServiceStaff'] ?? '') === ''
            && (array)($scopedExportContext['visible_store_ids'] ?? []) === [8]
            && (int)($scopedExport['pagination']['total'] ?? 0) === 1
            && (string)($scopedExportRow['member_name'] ?? '') === '跨店专属服务人会员'
            && (string)($scopedExportRow['exclusive_service_staff'] ?? '') === '',
        json_encode(compact(
            'crossStoreExclusiveRecord',
            'scopedExportContext',
            'scopedExport',
            'scopedExportRow'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERM-07'
    );

    $bulkUsers = [];
    $bulkRelations = [];
    for ($uid = 10000; $uid <= 20000; $uid++) {
        $bulkUsers[] = [
            'uid' => $uid,
            'nickname' => '大数据会员' . $uid,
            'real_name' => '大数据会员' . $uid,
            'phone' => sprintf('138%08d', $uid),
            'bar_code' => sprintf('UQ%09d', $uid),
            'belong_store_id' => MemberIntegrationFixture::STORE_ID,
            'status' => 1,
            'is_del' => 0,
            'delete_time' => null,
            'add_time' => $uid,
        ];
        $bulkRelations[] = [
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'uid' => $uid,
            'status' => 1,
            'add_time' => $uid,
        ];
        if (count($bulkUsers) === 500) {
            Db::name('user')->insertAll($bulkUsers);
            Db::name('store_user')->insertAll($bulkRelations);
            $bulkUsers = [];
            $bulkRelations = [];
        }
    }
    if ($bulkUsers) {
        Db::name('user')->insertAll($bulkUsers);
        Db::name('store_user')->insertAll($bulkRelations);
    }
    $largeDefault = uqMemberQuery($dispatcher, '', $session, [
        'page' => 2,
        'pageSize' => 20,
        'dataScope' => 'normal',
    ]);
    $largeAdvancedCode = '';
    try {
        uqMemberQuery($dispatcher, '', $session, [
            'page' => 1,
            'pageSize' => 20,
            'dataScope' => 'normal',
            'querySettings' => [
                'filters' => [[
                    'field' => 'member_status',
                    'operator' => 'eq',
                    'value' => '正常',
                ]],
                'filterRelation' => 'all',
                'sorts' => [['field' => 'member_id', 'direction' => 'asc']],
                'groupBy' => [],
                'summaries' => [],
            ],
        ]);
    } catch (CashierV3CommandException $exception) {
        $largeAdvancedCode = $exception->getResultCode();
    } catch (UnifiedQueryException $exception) {
        $largeAdvancedCode = $exception->getErrorCode();
    }
    $largeDefaultData = $largeDefault['data'];
    ok(
        '超过一万会员的基础列表使用数据权限后的 SQL 稳定分页，高级筛选继续安全拒绝',
        ($largeDefault['envelope']['result']['status'] ?? '') === 'success'
            // 10001 条大样本（10000..20000）加上本用例先行建立的 901..904
            // 四位正常会员。该精确值防止 SQL 快速分页漏掉已授权的既有会员。
            && (int)($largeDefaultData['total'] ?? 0) === 10005
            && (int)($largeDefaultData['page'] ?? 0) === 2
            && count((array)($largeDefaultData['records'] ?? [])) === 20
            && !empty($largeDefaultData['security']['sqlPaginatedAfterDataScope'])
            && $largeAdvancedCode === 'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
        json_encode(compact(
            'largeAdvancedCode',
            'largeDefaultData'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERF-04'
    );
} catch (\Throwable $throwable) {
    ok(
        '会员首发字段真实 Gateway 集成未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . "\n" . $throwable->getTraceAsString()
    );
}

finish('unified-query-member-provider-integration');
