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

namespace app\services\store;


use app\dao\store\StoreStaffShiftHandoverDao;
use app\services\BaseServices;
use think\facade\Cache;

/**
 * 收银台交班
 * Class StoreStaffShiftHandoverServices
 * @package app\services\store
 * @mixin StoreStaffShiftHandoverDao
 */
class StoreStaffShiftHandoverServices extends BaseServices
{
    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreStaffShiftHandoverDao $dao
     */
    public function __construct(StoreStaffShiftHandoverDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 添加记录
     * @param $staff_id
     * @return \mohe\basic\BaseModel|\think\Model|\think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setShiftHandover($staff_id)
    {
        if(!$staff_id) return app('json')->fail('参数有误！');
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffInfo = $staffServices->getStaffInfo($staff_id);
        if (!$staffInfo) {
            return app('json')->fail('店员不存在！');
        }
        $token_login = 'is_staff_user_cashier_login_' . $staffInfo['id'] . '_cashier_' . $staffInfo['pwd'];
        Cache::delete($token_login);
        return $this->dao->save([
            'relation_id'=>$staffInfo['store_id'],
            'staff_id'=>$staff_id,
            'shift_start_time'=>$staffInfo['last_time'],
            'shift_end_time'=>time(),
            'add_time'=>time()
        ]);
    }
    /**
     * 交接班列表数据
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreStaffShiftHandoverData(array $where, array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*',$with,$page, $limit);
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        if ($list) {
            $store_id = $where['relation_id'];
            foreach ($list as &$item) {
                $orderData = $this->dao->getStaffShiftHandoverData($store_id,$item['staff_id'],$item['shift_start_time'],$item['shift_end_time']);
                $staffInfo = $staffServices->get($item['staff_id']);
                $item['account'] = $staffInfo['account'] ?? '';
                $item['staff_name'] = $staffInfo['staff_name'] ?? '';
                $item['sum'] = $orderData['sum'];
                $item['sum_price'] = $orderData['sumPrice'];
                $item['refund_price'] = $orderData['refund_price'];
                $item['order_price'] = $orderData['order_price'];
                $item['other_price'] = $orderData['other_price'];
                $item['recharge_price'] = $orderData['recharge_price'];
            }
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }
}
