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

namespace app\services\order;


use app\dao\order\StoreOrderStatusDao;
use app\services\BaseServices;
use app\services\message\service\StoreServiceServices;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\admin\SystemAdminServices;
use app\services\user\UserServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

/**
 * 订单状态
 * Class StoreOrderStatusServices
 * @package app\services\order
 * @mixin StoreOrderStatusDao
 */
class StoreOrderStatusServices extends BaseServices
{
    use ServicesTrait;

    /**
     * 构造方法
     * StoreOrderStatusServices constructor.
     * @param StoreOrderStatusDao $dao
     */
    public function __construct(StoreOrderStatusDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 订单状态分页
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStatusList(array $where)
    {
        $list = $this->dao->getStatusList($where);
        foreach ($list as &$item) {
            if (is_int($item['change_time'])) $item['change_time'] = date('Y-m-d H:i:s', $item['change_time']);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * @param int $orderId
     * @param string $changeType
     * @param array $data
     * @param int $changeManagerId
     * @param string $changeManagerType
     * @return bool
     */
    public function saveStatus(int $orderId, string $changeType = 'create', array $data = [], int $changeManagerId = 0, string $changeManagerType = 'user')
    {
        if (!$orderId || !$data) {
            return true;
        }
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        $orderInfo = $orderService->get($orderId);
        if (!$orderInfo) {
            return true;
        }
        $changeManagerId = !$changeManagerId && $changeManagerType == 'user' ? $orderInfo['uid'] : $changeManagerId;
        $statusData = [
            'oid' => $orderId,
            'change_time' => time(),
            'change_type' => $changeType,
            'change_message' => $data['change_message'] ?? '',
            'change_manager_type' => $changeManagerType,
            'change_manager_id' => $changeManagerId
        ];
        if ($changeManagerId) {
            switch ($changeManagerType) {
                case 'user':
                    /** @var UserServices $userServices */
                    $userServices = app()->make(UserServices::class);
                    $userInfo = $userServices->getUserCacheInfo($changeManagerId);
                    if ($userInfo) {
                        $statusData['change_manager'] = $userInfo['nickname'] ?? '用户操作';
                    }
                    break;
                case 'admin':
                    /** @var SystemAdminServices $adminServices */
                    $adminServices = app()->make(SystemAdminServices::class);
                    $adminInfo = $adminServices->get($changeManagerId, ['id', 'real_name']);
                    if ($adminInfo) {
                        $statusData['change_manager'] = $adminInfo['real_name'] ?? '管理员操作';
                    }
                    break;
                case 'kefu':
                    /** @var StoreServiceServices $serviceServices */
                    $serviceServices = app()->make(StoreServiceServices::class);
                    $kefuInfo = $serviceServices->getOne(['uid' => $changeManagerId, 'is_del' => 0], 'id,nickname');
                    if ($kefuInfo) {
                        $statusData['change_manager'] = $kefuInfo['nickname'] ?? '客服操作';
                    }
                    break;
                case 'store':
                    /** @var SystemStoreStaffServices $systemStoreStaffServices */
                    $systemStoreStaffServices = app()->make(SystemStoreStaffServices::class);
                    $staffInfo = $systemStoreStaffServices->get($changeManagerId, ['id', 'staff_name']);
                    if ($staffInfo) {
                        $statusData['change_manager'] = $staffInfo['staff_name'] ?? '门店店员操作';
                    }
                    break;
                case 'delivery':
                    /** @var DeliveryServiceServices $deliverServiceServices */
                    $deliverServiceServices = app()->make(DeliveryServiceServices::class);
                    $staffInfo = $deliverServiceServices->get($changeManagerId, ['id', 'nickname']);
                    if ($staffInfo) {
                        $statusData['change_manager'] = $staffInfo['nickname'] ?? '配送员操作';
                    }
                    break;
                case 'supplier':
                    /** @var SystemAdminServices $systemAdminServices */
                    $systemAdminServices = app()->make(SystemAdminServices::class);
                    $supplierInfo = $systemAdminServices->get($changeManagerId, ['id', 'real_name']);
                    if ($supplierInfo) {
                        $statusData['change_manager'] = $supplierInfo['real_name'] ?? '供应商管理员操作';
                    }
                    break;
                case 'system':
                    $statusData['change_manager'] = '系统执行';
                    break;
                default:
                    $statusData['change_manager'] = '管理员操作';
                    break;
            }
        }
        if (!isset($statusData['change_manager'])) $statusData['change_manager'] = '管理员操作';
        $this->dao->save($statusData);
        return true;
    }


}
