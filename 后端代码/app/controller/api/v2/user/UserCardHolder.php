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
namespace app\controller\api\v2\user;

use app\Request;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserCardHolderServices;
use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy;
use think\facade\Db;


/**
 * 卡包类
 * Class UserCardHolder
 * @package app\controller\api\v2\user
 */
class UserCardHolder
{
    /**
     * @var UserCardHolderServices
     */
    protected $services;

    /**
     * UserCardHolder constructor.
     * @param UserCardHolderServices $services
     */
    public function __construct(UserCardHolderServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取卡包列表
     * @param Request $request
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCardHolder(Request $request)
    {
        $loginUid = (int)$request->uid();
        $getUid = (int)$request->get('uid', 0);
        $uid = $getUid ?: $loginUid;
        $viewerStoreId = null;
        if ($getUid && $getUid !== $loginUid) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            try {
                $staffInfo = $staffServices->getStaffInfoByUid($loginUid)->toArray();
            } catch (\Throwable $e) {
                $staffInfo = [];
            }
            $staffServices->assertViewCustomerCardPermission($staffInfo);
            // Staff may inspect a customer's imported rights only inside the
            // organization of the authenticated operating store. The client
            // cannot provide this store ID as a request parameter.
            $viewerStoreId = (int)($staffInfo['store_id'] ?? 0);
        }
        return app('json')->successful($this->services->getCardHolderList($uid, [], $viewerStoreId));
    }

    /**
     * 卡项信息
     * @param Request $request
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cardHolder(Request $request, $id)
    {
        if (!$id) {
            app('json')->fail('缺少参数');
        }
        $holder = Db::name('user_card_holder')->where('id', (int)$id)
            ->field('id,uid')->find();
        if (!$holder) {
            return app('json')->fail('数据有误');
        }
        if ((int)$holder['uid'] !== (int)$request->uid()) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            try {
                $staffInfo = $staffServices->getStaffInfoByUid((int)$request->uid())->toArray();
            } catch (\Throwable $e) {
                $staffInfo = [];
            }
            $staffServices->assertViewCustomerCardPermission($staffInfo);
            if (CashierV3HistoricalEntitlementScopePolicy::isImportedHolder((int)$id, '0')
                && CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                    [(int)$id], '0', (int)($staffInfo['store_id'] ?? 0)
                ) === []) {
                return app('json')->fail('无操作权限');
            }
        }
        $cardHolder = $this->services->oneCardHolder((int)$id);
        if ($cardHolder) {
            return app('json')->successful($cardHolder);
        } else {
            return app('json')->fail('数据有误');
        }
    }

    /**
     * 删除卡包
     * @param Request $request
     * @param $id
     * @return \think\Response
     */
    public function delCardHolder(Request $request, $id)
    {
        if (!$id) {
            return app('json')->fail('缺少参数');
        }
        $holder = Db::name('user_card_holder')->where('id', (int)$id)
            ->field('id,uid')->find();
        if (!$holder || (int)$holder['uid'] !== (int)$request->uid()) {
            return app('json')->fail('无操作权限');
        }
        // Source UI rights are the migration authority. A member-side delete
        // would silently remove the imported right while leaving its source
        // and scope audit intact; only the controlled write-off path may use it.
        if (CashierV3HistoricalEntitlementScopePolicy::isImportedHolder((int)$id, '0')) {
            return app('json')->fail('历史权益不可删除');
        }
        $res = $this->services->userDelCardHolder((int)$id);
        if ($res) {
            return app('json')->successful('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }
}
