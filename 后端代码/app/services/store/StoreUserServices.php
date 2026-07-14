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


use app\dao\store\StoreUserDao;
use app\model\order\StoreOrder;
use app\model\store\SystemStore;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\level\UserLevelServices;
use app\services\user\UserServices;
use app\services\user\UserListStatServices;
use think\exception\ValidateException;

/**
 * 门店用户
 * Class StoreUser
 * @package app\services\store
 * @mixin StoreUserDao
 */
class StoreUserServices extends BaseServices
{
    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreUserDao $dao
     */
    public function __construct(StoreUserDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * @param array $where
	 * @param int $store_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(array $where, int $store_id)
    {
		$where['is_filter_del'] = 1;
        $where['store_id'] = $store_id;
        /** @var UserStoreUserServices $userStoreUserServices */
        $userStoreUserServices = app()->make(UserStoreUserServices::class);
        $fields = 'u.*';
        [$list, $count] = $userStoreUserServices->getWhereUserList($where, $fields);
        if ($list) {
            $uids = array_column($list, 'uid');
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userlabel = $userServices->getUserLablel($uids, 1, $store_id);
            $levelName = app()->make(SystemUserLevelServices::class)->getUsersLevel(array_unique(array_column($list, 'level')));
            $userLevel = app()->make(UserLevelServices::class)->getUsersLevelInfo($uids);
            $spread_names = $userServices->getColumn([['uid', 'in', $uids]], 'nickname', 'uid');
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffNames = $staffServices->getColumn([['store_id', '=', $store_id], ['uid', 'in', array_column($list, 'uid')], ['is_del', '=', 0]], 'uid,staff_name', 'uid');
            $statMap = UserListStatServices::getStatsByUids($uids, $store_id, $where);
            foreach ($list as &$item) {
                $order=StoreOrder::where("uid",$item['uid'])
                    ->order("add_time","desc")
                    ->where("refund_status",0)
                    ->where("order_type",2)
                    ->find();
                $item['order_time']="";
                $item['shouyi']="";
                if(!empty($order)){
                    $item['order_time']=date('Y-m-d H:i:s',$order['add_time']);
                    $staffs=StaffYeji::where("type",3)->where("link_id",$order['link_id'])->column("staff_name");
                    if(!empty($staffs)) {
                        $item['shouyi'] = implode(",", $staffs);
                    }
                }
                if(empty($item['belong_store_id'])){
                    $item['belong_store']="无";
                }else{
                    $item['belong_store']=SystemStore::where("id",$item['belong_store_id'])->value("name");
                }
                $item['status'] = ($item['status'] == 1) ? '正常' : '禁止';
                $item['birthday'] = $item['birthday'] ? date('Y-m-d', (int)$item['birthday']) : '';
                $item['spread_uid_nickname'] = $item['spread_uid'] ? ($spread_names[$item['spread_uid']] ?? '') . '/' . $item['spread_uid'] : '无';
                $item['staff_name'] = $staffNames[$item['uid']]['staff_name'] ?? '';
                //用户类型
				$item['user_type'] = $userServices->userTypeName[$item['user_type']] ?? '其他';
                //等级名称
                $item['level'] = $levelName[$item['level']] ?? '无';
                //用户等级
                $item['vip_name'] = false;
                $levelinfo = $userLevel[$item['uid']] ?? null;
                if ($levelinfo) {
                    if ($levelinfo && ($levelinfo['is_forever'] || time() < $levelinfo['valid_time'])) {
                        $item['vip_name'] = $item['level'] != '无' ? $item['level'] : false;
                    }
                }
                $item['labels'] = $userlabel[$item['uid']] ?? '';
                $item['isMember'] = $item['is_money_level'] > 0 ? 1 : 0;
                $stats = $statMap[(int)$item['uid']] ?? [];
                $item['cash_consume_amount'] = $stats['cash_consume_amount'] ?? '0.00';
                $item['cash_consume_count'] = $stats['cash_consume_count'] ?? 0;
                $item['writeoff_count'] = $stats['writeoff_count'] ?? 0;
            }
        }

        return compact('count', 'list');
    }


    /**
     * 同步写入门店用户
     * @param int $uid
     * @param int $store_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setStoreUser(int $uid, int $store_id)
    {
		try {
			$storeUser = $this->dao->getOne(['uid' => $uid, 'store_id' => $store_id]);
			if (!$storeUser) {
				if (!$this->dao->save(['uid' => $uid, 'store_id' => $store_id, 'add_time' => time()])) {
					throw new ValidateException('新增门店用户失败');
				}
			}
		} catch (\Throwable $e) {

		}
        return true;
    }

}
