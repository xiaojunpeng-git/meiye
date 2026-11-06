<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\member\CashierV3MemberModule;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;
use think\exception\ValidateException;

/**
 * 平台会员人工增减本金/赠金。只复用会员列表的数据范围，不新增功能权限。
 * 钱包、原子流水、变更事实、业务事件及管理回执在同一事务完成；不产生收款/充值业绩。
 * 管理回执保留本次前后值、原因与平台操作人，既有余额事实不被覆盖。
 */
final class PlatformMemberBalanceAdjustmentServices
{
    public const ACTION = 'adjust-member-balance';

    public function read(int $adminId, array $payload): array
    {
        [$scope, $member] = $this->scope($adminId, $payload, false);
        $balance = (new CashierV3MemberBalanceProvider())->readSnapshot((int)$member['uid'], $scope['operator'], $scope['data']);
        return $this->projection($member, $balance);
    }

    /** 请求重放先读原回执；另一次调整须携带打开弹窗时的真实钱包版本。 */
    public function adjust(int $adminId, array $payload): array
    {
        $this->scope($adminId, $payload, false);
        $allowed = ['memberId','type','ben_money','give_money','mark','expectedVersion','requestId','idempotencyKey','selectedStoreIds','pageCode'];
        if (array_diff(array_keys($payload), $allowed)) throw new ValidateException('余额修改包含不支持的字段');
        $type = (string)($payload['type'] ?? '');
        if (!in_array($type, ['1','2'], true)) throw new ValidateException('请选择增加或减少');
        $ben = $this->amount($payload['ben_money'] ?? null, '本金');
        $give = $this->amount($payload['give_money'] ?? null, '赠金');
        if ($ben + $give === 0) throw new ValidateException('本金和赠金不能都是0！');
        $mark = $payload['mark'] ?? '';
        // 现有流水备注列上限512字；为系统摘要预留空间，禁止成功后悄悄截断用户备注。
        if (!is_string($mark) || mb_strlen($mark) > 500) throw new ValidateException('备注不能超过500个字');
        if (!is_int($payload['expectedVersion'] ?? null) && !is_string($payload['expectedVersion'] ?? null)) throw new ValidateException('请重新打开修改余额窗口');
        $expected = (string)($payload['expectedVersion'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $expected)) throw new ValidateException('请重新打开修改余额窗口');
        return (new MemberManagementCommandServices())->execute('ADMIN', $adminId, self::ACTION, $payload,
            function () use ($adminId, $payload, $type, $ben, $give, $mark, $expected): array {
                // 事务内重读平台身份、会员状态及归属；不能借平台管理员同编号的门店员工授权。
                $currentAdmin = Db::name('system_admin')->where('id', $adminId)->lock(true)->find();
                // 锁定读是当前事实；外层事务即使已有旧快照，也不能沿用已停用的平台身份。
                if (!$currentAdmin || (int)$currentAdmin['status'] !== 1 || (int)$currentAdmin['is_del'] !== 0 || (int)$currentAdmin['admin_type'] !== 0) throw new ValidateException('平台登录已失效');
                [$scope, $member] = $this->scope($adminId, $payload, true);
                $uid = (int)$member['uid']; $provider = new CashierV3MemberBalanceProvider();
                $before = $provider->lockSnapshotInTx($uid, $scope['operator'], $scope['data']);
                if ((string)$before['accountVersion'] !== $expected) throw new ValidateException('会员余额已变化，请重新打开窗口后再修改');
                if ($type === '2' && ($ben * 100 > $before['principalCents'] || $give * 100 > $before['giftCents'])) {
                    throw new ValidateException($ben * 100 > $before['principalCents'] ? '用户剩余本金低于变动值!' : '用户剩余赠金低于变动值!');
                }
                $key = (string)$payload['requestId'];
                $receiptId = (int)Db::name('member_management_receipt')->where(['actor_type'=>'ADMIN','actor_id'=>$adminId,'request_key'=>$key])->value('id');
                if ($receiptId <= 0) throw new ValidateException('余额操作凭证未就绪');
                $bill = $type === '1' ? 'system_add' : 'system_sub';
                $atomic = new UserBalanceAtomicServices();
                $args = [$uid, $ben . '.00', $give . '.00', $bill, $receiptId, $type === '1' ? '系统操作增加余额' : '系统操作减少余额', 'mba-' . hash('sha256', 'ADMIN|' . $adminId . '|' . $key)];
                $changed = $type === '1' ? $atomic->creditBenGive(...$args) : $atomic->deductBenGive(...$args);
                $after = $provider->lockSnapshotInTx($uid, $scope['operator'], $scope['data']);
                $sign = $type === '1' ? 1 : -1;
                if ($after['accountVersion'] !== $before['accountVersion'] + 1
                    || $after['principalCents'] !== $before['principalCents'] + $sign * $ben * 100
                    || $after['giftCents'] !== $before['giftCents'] + $sign * $give * 100) throw new ValidateException('余额修改对账失败，已取消操作');
                // 原子服务负责钱包及本赠分量守恒；备注属于同一流水，失败一并回滚。
                Db::name('user_money')->where('id', (int)$changed['money_id'])->update(['mark' => ($type === '1' ? '系统增加余额' : '系统扣除余额') . ($mark !== '' ? '，' . $mark : '')]);
                $now = time(); $date = date('Y-m-d', $now);
                $operationId = 'MBA-' . substr(hash('sha256', 'ADMIN|' . $adminId . '|' . $key), 0, 40);
                $operatorName = (string)$scope['data']->operatorProfile()['staff_name'];
                $audit = ['operationId'=>$operationId,'actorType'=>'ADMIN','operatorId'=>$adminId,'operatorName'=>$operatorName,
                    'memberId'=>$uid,'type'=>(int)$type,'principalDeltaCents'=>$sign*$ben*100,'bonusDeltaCents'=>$sign*$give*100,
                    'before'=>$before,'after'=>$after,'remark'=>$mark,'occurredAt'=>$now];
                $recorder = new CashierV3BusinessEventRecorder();
                $execution = $recorder->newExecution(self::ACTION, $key, $scope['operator'], $scope['data'], 'platform-member:' . $adminId);
                $contract = ['required_event_types'=>['balance.changed'],'allowed_event_types'=>['balance.changed'],
                    'event_rules'=>['balance.changed'=>['min_count'=>1,'max_count'=>1,'aggregate_type'=>'member_balance','source_type'=>self::ACTION,'aggregate_version'=>1]],'consumers'=>[]];
                $event = $recorder->recordInTx($execution, $contract, ['event_type'=>'balance.changed','aggregate_type'=>'member_balance',
                    'aggregate_id'=>$operationId,'aggregate_version'=>1,'source_type'=>self::ACTION,'source_id'=>$operationId,
                    'member_id'=>$uid,'business_date'=>$date,'occurred_at'=>$now,'settled_at'=>$now,'recorded_at'=>$now,
                    'aggregate_name_snapshot'=>$this->name($member),'store_name_snapshot'=>$scope['storeName'],'payload'=>$audit]);
                $eventRow = Db::name('cashier_v3_business_event')->where('id', (int)$event['event_id'])->find();
                $fact = ['fact_id'=>$operationId,'business_event_no'=>$event['event_no'],'fact_type'=>'balance_changed','fact_direction'=>'forward',
                    'natural_key'=>'member_balance_adjustment:' . $operationId,'command_idempotency_key'=>$key,'fact_version'=>1,'status'=>'effective',
                    'tenant_id'=>$scope['data']->tenantId(),'organization_id'=>$eventRow['organization_id'],'organization_path_snapshot'=>$eventRow['organization_path'],
                    'organization_name_snapshot'=>$eventRow['organization_name_snapshot'],'store_id'=>$scope['operator']->storeId(),'store_name_snapshot'=>$scope['storeName'],
                    'member_id'=>$uid,'member_name_snapshot'=>$this->name($member),'operator_id'=>$adminId,'operator_name_snapshot'=>$operatorName,
                    'business_date'=>$date,'business_timezone'=>'Asia/Shanghai','occurred_at'=>$now,'settled_at'=>$now,'recorded_at'=>$now,
                    'checkout_request_id'=>$operationId,'order_id'=>$operationId,'order_no_snapshot'=>$operationId,
                    'source_document_type'=>'member_balance_adjustment','source_line_id'=>(string)$receiptId,'balance_change_type'=>$bill,
                    'balance_account_id'=>$after['authorityKey'],'account_version'=>$after['accountVersion'],
                    'principal_delta_cents'=>$sign*$ben*100,'bonus_delta_cents'=>$sign*$give*100,
                    'principal_after_cents'=>$after['principalCents'],'bonus_after_cents'=>$after['giftCents']];
                $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                Db::name('cashier_v3_balance_fact')->insert($fact);
                $recorder->assertRequiredPersistedInTx($execution, $contract);
                return ['balanceAdjustment'=>$this->projection($member, $after),'adjustment'=>$audit + ['ledgerId'=>(int)$changed['money_id'],'eventNo'=>$event['event_no']]];
            });
    }

    /** 入口仅接受平台身份；组织筛选只能缩小范围，不附加编辑/余额功能权限。 */
    private function scope(int $adminId, array $payload, bool $lock): array
    {
        if (isset($payload['pageCode']) && $payload['pageCode'] !== 'platform_member_list') throw new ValidateException('查询页面不匹配');
        $rawUid = $payload['memberId'] ?? null;
        if ((!is_int($rawUid) && !is_string($rawUid)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$rawUid)) throw new ValidateException('请选择会员');
        $uid = (int)$rawUid;
        $authority = new PlatformMemberScopeServices();
        $store = $authority->activeMemberStoreId($uid, $lock);
        if ($store <= 0) throw new ValidateException('该会员暂无归属门店，请先调店后操作');
        $scope = $authority->build($adminId, 'read', $store);
        $selected = array_values(array_unique(array_map('intval', (array)($payload['selectedStoreIds'] ?? []))));
        $allowed = array_map('intval', array_column($scope['stores'], 'id'));
        if (array_diff($selected, $allowed) || ($selected && !in_array($store, $selected, true))) throw new ValidateException('会员不在当前所选组织范围');
        $member = CashierV3MemberModule::findManageableMember((string)$uid, $scope['operator'], $scope['data'], $lock);
        if ($authority->activeMemberStoreId($uid, $lock) !== $store) throw new ValidateException('会员归属已变化，请重新打开窗口');
        $scope['storeName'] = (string)Db::name('system_store')->where('id', $store)->value('name');
        return [$scope, $member];
    }

    private function projection(array $member, array $balance): array
    {
        return ['memberId'=>(int)$member['uid'],'name'=>$this->name($member),'phone'=>(string)$member['phone'],
            'avatar'=>(string)($member['avatar'] ?? ''),'balance'=>$this->money($balance['totalCents']),
            'principal'=>$this->money($balance['principalCents']),'bonus'=>$this->money($balance['giftCents']),
            'expectedVersion'=>$balance['accountVersion']];
    }

    /** 只归一输入整元，不改写既有历史小数余额。 */
    private function amount($value, string $label): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,6})$/D', (string)$value)) throw new ValidateException('修改' . $label . '须为0至9999999的整数');
        return (int)$value;
    }
    private function name(array $member): string { return trim((string)($member['real_name'] ?? '')) ?: (string)($member['nickname'] ?? ''); }
    private function money(int $cents): string { return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT); }
}
