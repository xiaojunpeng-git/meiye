<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\services\user;

use app\dao\user\UserBelongStoreDao;
use app\services\BaseServices;
use app\services\report\StoreUnifiedReportPhaseThreeFoundationServices;
use app\services\store\StoreUserServices;
use think\facade\Db;
use think\exception\ValidateException;


/**
 * 用户归属门店
 * Class UserBelongStoreServices
 * @package app\services\user
 * @mixin UserBelongStoreDao
 */
class UserBelongStoreServices extends BaseServices
{

    /**
     * 绑定类型名称
     * @var string[]
     */
    public $typeName = [
        'admin' => '管理员操作绑定',
        'order' => '门店下单',
        'svip' => '开通会员卡',
        'spread' => '绑定推广人',
        'visit' => '访问店铺页面',
        'we_com' => '添加企业微信',
        'scan_code' => '扫公众号码并关注',
        'offline' => '线下下单/储值/购买付费会员',
        'expire' => '有效期到期解绑',
        'admin_expire' => '管理员操作解绑',
        'shop' => '更换', //更换店员同步更换归属门店
        'admin_transfer' => '平台会员调店',
    ];

    /**
     * UserBelongStoreServices constructor.
     * @param UserBelongStoreDao $dao
     */
    public function __construct(UserBelongStoreDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 记录用户归属门店关系
     * @param int $uid
     * @param int $store_id
     * @param int $staff_id
     * @param int $add_time
     * @param int $admin_id
     * @return bool
     */
    public function setUserBelongStore(int $uid, int $store_id, string $type = 'order', int $staff_id = 0, int $add_time = 0, int $admin_id = 0)
    {
        if (!$uid || !$store_id) return false;
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return false;
        }
        $status = false;
        switch ($type) {
            case 'admin'://管理员操作
                $status = true;
                break;
            case 'order'://下单
                $status = (bool)sys_config('belong_store_order', 1);
                break;
            case 'svip'://svip
                $status = (bool)sys_config('belong_store_svip', 1);
                break;
            case 'spread'://推广
                $status = (bool)sys_config('belong_store_spread', 1);
                break;
            case 'shop'://专属店员对应门店
                $status1 = (bool)sys_config('belong_store_salesman', 1);
                $status2 = (bool)sys_config('belong_store_unbind_status', 1);
                if ($status1 || $status2) $status = true;
                break;
        }
        //没开启绑定归属
        if (!$status) {
            return false;
        }
        $data = ['type' => $type, 'uid' => $uid, 'store_id' => $store_id, 'staff_id' => $staff_id, 'add_time' => $add_time ?: time(), 'admin_id' => $admin_id];
        //验证是否换绑
        $changeBelongStatus = sys_config('belong_store_change_svip', 0);
        if (!$userInfo['belong_store_id'] || ($type == 'svip' && $changeBelongStatus) || $type == 'admin' || $type == 'shop') {//无绑定 || (开通付费会员类型 && 换绑开启) || 管理员手动操作 || 专属店员对应门店
            if ($userInfo['belong_store_id']) {//换绑
                $data['group'] = 2;
            } else {
                $data['group'] = 1;
            }
            Db::transaction(function () use ($data, $uid, $store_id, $userServices): void {
                $history = $this->dao->save($data);
                $historyId = (int)($history->id ?? 0);
                if ($historyId <= 0) {
                    throw new \RuntimeException('会员归属历史保存失败');
                }
                $userServices->update($uid, ['belong_store_id' => $store_id]);
                (new StoreUnifiedReportPhaseThreeFoundationServices())->recordMemberStoreAssignmentInTx(
                    ['tenant_id' => '0'],
                    [
                        'member_id' => $uid,
                        'assigned' => true,
                        'store_id' => $store_id,
                        'effective_at' => (int)$data['add_time'],
                        'source_type' => 'USER_BELONG_STORE',
                        'source_event_id' => (string)$historyId,
                        'idempotency_key' => 'phase3-member-store:' . $historyId,
                    ]
                );
            });
            $userServices->cacheTag()->clear();
        }
        return true;
    }

    /**
     * 平台会员调店命令。
     *
     * 该命令只更新会员主表的 belong_store_id，并追加带原因的归属历史；订单、权益、余额、
     * 绑定店员等其他业务字段都不在写集合内。行锁保证并发时以最新归属为准，相同目标门店
     * 的重复提交按幂等成功处理，不重复写历史。
     *
     * @return array{changed:bool,from_store_id:int,to_store_id:int,history_id:int}
     */
    public function transferUserStore(int $uid, int $targetStoreId, string $reason, int $adminId, bool $clearCache = true): array
    {
        $reason = trim($reason);
        if ($uid <= 0) {
            throw new ValidateException('会员不存在');
        }
        if ($targetStoreId <= 0) {
            throw new ValidateException('请选择目标门店');
        }
        if (mb_strlen($reason) < 2 || mb_strlen($reason) > 200) {
            throw new ValidateException('调店原因请输入2-200个字符');
        }

        $result = Db::transaction(function () use ($uid, $targetStoreId, $reason, $adminId): array {
            // 锁定会员主记录后再读取旧门店，防止两个调店命令互相覆盖而丢失审计链。
            $user = Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$user || !empty($user['delete_time'])) {
                throw new ValidateException('会员不存在或已注销');
            }
            $targetStore = Db::name('system_store')
                ->where(['id' => $targetStoreId, 'is_del' => 0, 'is_show' => 1])
                ->field('id,name')
                ->find();
            if (!$targetStore) {
                throw new ValidateException('目标门店不存在或已停用');
            }

            $fromStoreId = (int)($user['belong_store_id'] ?? 0);
            if ($fromStoreId === $targetStoreId) {
                return [
                    'changed' => false,
                    'from_store_id' => $fromStoreId,
                    'to_store_id' => $targetStoreId,
                    'history_id' => 0,
                ];
            }

            $now = time();
            // 名称作为事件发生时快照保存，后续门店或管理员改名不应改写历史含义。
            $fromStoreName = $fromStoreId > 0
                ? (string)Db::name('system_store')->where('id', $fromStoreId)->value('name')
                : '未设置';
            $operatorName = (string)Db::name('system_admin')->where('id', $adminId)->value('real_name');
            if ($operatorName === '') {
                $operatorName = (string)Db::name('system_admin')->where('id', $adminId)->value('account');
            }
            $historyId = (int)Db::name('user_belong_store')->insertGetId([
                'group' => $fromStoreId > 0 ? 2 : 1,
                'type' => 'admin_transfer',
                'is_store' => 1,
                'uid' => $uid,
                'store_id' => $targetStoreId,
                'from_store_id' => $fromStoreId,
                'staff_id' => 0,
                'admin_id' => $adminId,
                'reason' => $reason,
                'from_store_name' => $fromStoreName !== '' ? $fromStoreName : ('门店#' . $fromStoreId),
                'to_store_name' => (string)$targetStore['name'],
                'operator_name' => $operatorName !== '' ? $operatorName : ('管理员#' . $adminId),
                'add_time' => $now,
            ]);
            if ($historyId <= 0) {
                throw new \RuntimeException('会员调店记录保存失败');
            }

            // 写集合严格限定为会员归属门店；报表事实仅记录同一归属事件的历史投影。
            $updated = Db::name('user')->where('uid', $uid)->update(['belong_store_id' => $targetStoreId]);
            if ($updated !== 1) {
                throw new \RuntimeException('会员归属门店更新失败');
            }
            (new StoreUnifiedReportPhaseThreeFoundationServices())->recordMemberStoreAssignmentInTx(
                ['tenant_id' => '0'],
                [
                    'member_id' => $uid,
                    'assigned' => true,
                    'store_id' => $targetStoreId,
                    'effective_at' => $now,
                    'source_type' => 'USER_BELONG_STORE',
                    'source_event_id' => (string)$historyId,
                    'idempotency_key' => 'phase3-member-store:' . $historyId,
                ]
            );

            return [
                'changed' => true,
                'from_store_id' => $fromStoreId,
                'to_store_id' => $targetStoreId,
                'history_id' => $historyId,
            ];
        });

        // 批量调用方在每批事务提交后统一清理缓存，避免逐会员重复清空；单人调店保持原行为。
        if ($result['changed'] && $clearCache) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userServices->cacheTag()->clear();
        }
        return $result;
    }

    /**
     * 返回平台调店可选的全部启用门店，不套用管理员组织范围；只提供门店 ID 和名称。
     */
    public function getTransferStoreOptions(): array
    {
        return Db::name('system_store')
            ->where(['is_del' => 0, 'is_show' => 1])
            ->field('id,name')
            ->order('id asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取会员的平台调店历史。
     *
     * 只读取 admin_transfer 事件，避免把自动归属、店员换绑等记录混入“调店详情”；
     * 名称优先使用写入时快照，旧数据缺少快照时才回查当前名称并给出可识别兜底值。
     *
     * @return array{list:array,count:int}
     */
    public function getTransferHistory(int $uid): array
    {
        [$page, $limit] = $this->getPageValue();
        $query = Db::name('user_belong_store')->where([
            'uid' => $uid,
            'is_store' => 1,
            'type' => 'admin_transfer',
        ]);
        $count = (int)(clone $query)->count();
        $list = $query
            ->field('id,from_store_id,store_id,admin_id,reason,from_store_name,to_store_name,operator_name,add_time')
            ->page($page, $limit)
            ->order('add_time desc,id desc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            if ((string)$item['from_store_name'] === '') {
                $item['from_store_name'] = (int)$item['from_store_id'] > 0
                    ? ((string)Db::name('system_store')->where('id', (int)$item['from_store_id'])->value('name') ?: ('门店#' . $item['from_store_id']))
                    : '未设置';
            }
            if ((string)$item['to_store_name'] === '') {
                $item['to_store_name'] = (string)Db::name('system_store')->where('id', (int)$item['store_id'])->value('name') ?: ('门店#' . $item['store_id']);
            }
            if ((string)$item['operator_name'] === '') {
                $item['operator_name'] = (string)Db::name('system_admin')->where('id', (int)$item['admin_id'])->value('real_name') ?: ('管理员#' . $item['admin_id']);
            }
            $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
        }
        unset($item);

        return compact('list', 'count');
    }

    /**
     * 店员绑定记录
     * @param int $uid
     * @param int $staff_id
     * @param int $store_id
     * @param int $scene 1=>下单｜2=>访问店铺页面｜3=>添加企业微信｜4=>扫门店推广码
     * @param int $is_group 是否管理员解绑
     * @return bool
     */
    public function setUserBelongStoreStaff(int $uid = 0, int $staff_id = 0, int $store_id = 0, int $scene = 1, int $is_group = 0)
    {
        if (!$uid || !$staff_id) return true;
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return true;
        }
        if (in_array($scene, [1, 2, 3, 4])) {
            $binding_scene = sys_config('binding_scene');
            $status = in_array($scene, $binding_scene);

            //没开启绑定归属
            if (!$status) {
                return true;
            }

            switch ($scene) {
                case 1:
                    $type = 'order';
                    break;
                case 2:
                    $type = 'visit';
                    break;
                case 3:
                    $type = 'we_com';
                    break;
                case 4:
                    $type = 'scan_code';
                    break;
                default:
                    $type = 'order';
            }
            $period_of_validity = sys_config('period_of_validity', 1);
            switch ($period_of_validity) {
                case 1:
                    if ($userInfo['salesman_id']) {
                        return true;
                    } else {
                        $group = 1;
                    }
                    break;
                case 2:
                    $period_validity_day = sys_config('period_validity_day', 1);
                    if ($userInfo['salesman_id'] && $userInfo['salesman_time'] && ($period_validity_day * 86400 + $userInfo['salesman_time']) <= time()) {
                        $group = 2;
                    } elseif ($userInfo['salesman_id'] && $userInfo['salesman_time'] && ($period_validity_day * 86400 + $userInfo['salesman_time']) > time()) {
                        return true;
                    } else {
                        $group = 1;
                    }
                    break;
                case 3:
                    if ($userInfo['salesman_id']) {//换绑
                        if ($userInfo['salesman_id'] == $staff_id) {
                            return true;
                        } else {
                            $group = 2;
                        }
                    } else {
                        $group = 1;
                    }
                    break;
                default:
                    $group = 1;
            }
            $salesman = ['salesman_id' => $staff_id, 'salesman_time' => time()];
        } else if ($scene == 5) { //管理员操作
            $type = 'admin';
            if ($userInfo['salesman_id']) {//换绑
                $group = 2;
            } else {
                $group = 1;
            }
            $salesman = ['salesman_id' => $staff_id, 'salesman_time' => time()];
        } else { //解绑
            if ($is_group) {
                $type = 'admin_expire';
            } else {
                $type = 'expire';
            }
            $group = 3;
            $salesman = ['salesman_id' => 0, 'salesman_time' => 0];
        }

        $data = ['group' => $group, 'type' => $type, 'is_store' => 0, 'uid' => $uid, 'store_id' => $store_id, 'staff_id' => $staff_id, 'add_time' => time()];
        $this->dao->save($data);
        $userServices->update($uid, $salesman);
        $belong_store_salesman = sys_config('belong_store_salesman', 1);
        $belong_store_unbind_status = sys_config('belong_store_unbind_status', 1);
        if (($belong_store_salesman || $belong_store_unbind_status) && $group != 3) {
            $this->setUserBelongStore((int)$uid, (int)$store_id, 'shop', (int)$staff_id);
        }
        if (!in_array($type, ['admin_expire', 'expire'])) {
            /** @var StoreUserServices $storeUserServices */
            $storeUserServices = app()->make(StoreUserServices::class);
            $storeUserServices->setStoreUser($uid, $store_id);
        }
        $userServices->cacheTag()->clear();
        return true;
    }

    /**
     * 获取绑定门店记录
     * @param array $where
     * @param string $field
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getBelongStoreList(array $where, string $field = '*', array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $field, $with, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {
            $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
            switch ($item['group']) {
                case 1:
                    $item['group_name'] = '绑定';
                    break;
                case 2:
                    $item['group_name'] = '换绑';
                    break;
                case 3:
                    $item['group_name'] = '解绑';
                    break;
                default:
                    $item['group_name'] = '未知';
            }
            $item['type_name'] = $this->typeName[$item['type']] ?? '未知类型';
        }
        return compact('list', 'count');
    }

}
