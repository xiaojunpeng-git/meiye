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
namespace app\services\supplier;

use app\dao\supplier\SystemSupplierDao;
use app\dao\system\admin\SystemAdminDao;
use app\services\BaseServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\system\SystemUserApplyServices;
use mohe\exceptions\AdminException;
use think\exception\ValidateException;

/**
 * 供应商
 * Class SystemSupplierServices
 * @package app\services\supplier
 * @mixin SystemSupplierDao
 */
class SystemSupplierServices extends BaseServices
{

    protected $adminDao = null;

    /**
     * 构造方法
     * SystemSupplierServices constructor.
     * @param SystemSupplierDao $dao
     * @param SystemAdminDao $adminDao
     */
    public function __construct(SystemSupplierDao $dao, SystemAdminDao $adminDao)
    {
        $this->dao = $dao;
        $this->adminDao = $adminDao;
    }

    /**
     * 获取供应商
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\DataNotFoundException
     */
    public function getSupplierInfo(int $id, string $field = '*', array $with = [])
    {
        $info = $this->dao->getOne(['id' => $id, 'is_del' => 0], $field, $with);
        if (!$info) {
            throw new ValidateException('供应商不存在');
        }
        return $info;
    }

	/**
	 * 供应商列表
	 * @param array $where
	 * @param array $field
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getSupplierList(array $where, array $field = ['*'])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getSupplierList($where, $field, $page, $limit);
		if ($list) {
			$prefix = config('admin.supplier_prefix');
			foreach ($list as &$item) {
				if (isset($item['add_time']) && $item['add_time']) $item['_add_time'] = date('Y-m-d H:i:s', $item['add_time']);
				$item['prefix'] = $prefix;
			}
		}
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
	 * 保存供应商
	 * @param array $data
	 * @return mixed
	 */
    public function create(array $data)
    {
		if ($this->adminDao->count(['account' => $data['account'], 'admin_type' => 2, 'is_del' => 0])) {
			throw new AdminException('管理员账号已存在');
		}
        return $this->transaction(function () use ($data) {
            $adminData = [
                'pwd' => $this->passwordHash($data['pwd']),
                'admin_type' => 2,
                'account' => $data['account'],
                'roles' => '',
                'real_name' => $data['name'],
                'phone' => $data['phone'],
                'add_time' => time(),
                'level' => 0
            ];
			unset($data['pwd'], $data['conf_pwd'], $data['account']);

            // 创建管理员
            $res = $this->adminDao->save($adminData);
            if (!$res) throw new AdminException('管理员添加失败');
			$data['admin_id'] = (int)$res->id;
			$data['add_time'] = time();

			// 创建供应商
			$relation_id = $this->dao->save($data)->id;
			if (!$relation_id) throw new AdminException('供应商添加失败');

            $this->adminDao->update($res->id, ['relation_id' => $relation_id]);
            return $relation_id;
        });
    }

    /**
     * 修改管理员
     * @param array $data
     * @return mixed
     */
    public function save(int $id, array $data)
    {
		if (!$supplierInfo = $this->dao->get($id)) {
			throw new AdminException('供应商不存在,无法修改');
		}
		if ($supplierInfo->is_del) {
			throw new AdminException('供应商已经删除');
		}
		if (!$adminInfo = $this->adminDao->get($supplierInfo['admin_id'])) {
			throw new AdminException('管理员不存在,无法修改');
		}
		if ($adminInfo->is_del) {
			throw new AdminException('管理员已经删除');
		}
		//修改账号
		if (isset($data['account']) && $data['account'] != $adminInfo->account && $this->adminDao->isAccountUsable($data['account'], $supplierInfo['admin_id'], 2)) {
			throw new AdminException('管理员账号已存在');
		}
        return $this->transaction(function () use ($id, $data, $adminInfo, $supplierInfo) {

			$adminData = [
				'pwd' => $this->passwordHash($data['pwd']),
            	'real_name' => $data['name'] ?? $adminInfo->real_name,
				'phone' => $data['phone'] ?? $adminInfo->phone,
            	'account' => $data['account'] ?? $adminInfo->account
			];
            // 修改管理员
            $res = $this->adminDao->update($adminInfo['id'], $adminData);
            if (!$res) throw new AdminException('管理员修改失败');

			// 修改供应商
			unset($data['pwd'], $data['conf_pwd'], $data['account']);
			$this->dao->update($id, $data);
            $res1 = $supplierInfo->save();
            if (!$res1) throw new AdminException('供应商修改失败');
            return true;
        });
    }

	/**
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function delete(int $id)
    {
		if (!$supplierInfo = $this->dao->get($id)) {
			throw new AdminException('供应商不存在,无法修改');
		}
		if ($supplierInfo->is_del) {
			throw new AdminException('供应商已经删除');
		}
		if (!$adminInfo = $this->adminDao->get($supplierInfo['admin_id'])) {
			throw new AdminException('管理员不存在,无法删除');
		}
		if ($adminInfo->is_del) {
			throw new AdminException('管理员已经删除');
		}
		/** @var StoreOrderServices $storeOrderServices */
		$storeOrderServices = app()->make(StoreOrderServices::class);
		$orderCount = $storeOrderServices->count(['supplier_id' => $id, 'status' => 0]);
		if (!$orderCount) {
			$orderCount = $storeOrderServices->count(['supplier_id' => $id, 'status' => 1]);
			if (!$orderCount) {
				$orderCount = $storeOrderServices->count(['supplier_id' => $id, 'status' => 5]);
			}
		}
		if ($orderCount) {
			throw new AdminException('删除失败,该供应商还有待处理订单');
		}
        return $this->transaction(function () use ($id, $supplierInfo, $adminInfo) {
            $adminInfo->status = 0;
            $adminInfo->is_del = 1;
            // 修改管理员
            $res = $adminInfo->save();
            if (!$res) throw new AdminException('管理员删除失败');

            $supplierInfo->is_show = 0;
            $supplierInfo->is_del = 1;
            // 修改供应商
            $res1 = $supplierInfo->save();
            if (!$res1) throw new AdminException('供应商删除失败');

			/** @var StoreBranchProductServices $storeBranchProducesServices */
			$storeBranchProducesServices = app()->make(StoreBranchProductServices::class);
			//删除供应商商品
			$storeBranchProducesServices->deleteProducts([], 2, $id);
			/** @var SystemAttachmentServices $attach */
			$attach = app()->make(SystemAttachmentServices::class);
			//删除附件
			$attach->delAttachment([], 4, $id);
            return true;
        });
    }


    /**
     * 平台供应商运营统计
     * @param int $supplierId
     * @param array $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function supplierChart(int $supplierId, array $time)
    {
        $list = $this->dao->getSupplierList(['is_del' => 0, 'is_show' => 1], ['id', 'supplier_name']);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        /** @var  StoreOrderRefundServices $orderRefundServices */
        $orderRefundServices = app()->make(StoreOrderRefundServices::class);
        $where = ['time' => $time];

        $order_where = ['paid' => 1, 'pid' => 0, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
        $refund_where = ['refund_type' => 6];
        foreach ($list as &$item) {
            $supplier_where = ['supplier_id' => $item['id']];
            $item['order_price'] = $orderServices->sum($where + $supplier_where + $order_where, 'pay_price', true);
            $item['order_count'] = $orderServices->count($where + $supplier_where + $order_where);
            $item['refund_order_price'] = $orderRefundServices->sum($where + $supplier_where + $refund_where, 'refunded_price', true);
            $item['refund_order_count'] = $orderRefundServices->count($where + $supplier_where + $refund_where);
        }
        return $list;
    }

	/**
	 * 供应商选择列表
	 * @param array $where
	 * @param array $field
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getSupplierSearch(array $where, array $field = ['*'])
    {
        return $this->dao->getSupplierList($where, $field, 0, 0);
    }

	/**
	 * 供应商入驻审核通过创建数据
	 * @param int $applyId
	 * @param array $info
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function verifyAgreeCreate(int $applyId, array $info = [])
	{
		if (!$applyId) {
			throw new ValidateException('缺少申请ID');
		}
		/** @var SystemUserApplyServices $applyServices */
		$applyServices = app()->make(SystemUserApplyServices::class);
		if (!$info) {
			$info = $applyServices->get($applyId);
			if (!$info) {
				throw new ValidateException('申请数据不存在');
			}
			$info = $info->toArray();
		}
		$data = [
			'supplier_name' => $info['system_name'],
			'account' => $this->getAccount($info['phone']),
			'phone' => $info['phone'],
			'name' => $info['name'],
			'pwd' => substr($info['phone'], -6)
		];
		$supplier_id = $this->create($data);
		return $this->dao->get($supplier_id)->toArray();
	}

	/**
	 * 获取同意申请 创建账号
	 * @param string $phone
	 * @return string
	 */
	public function getAccount(string $phone)
	{
		$account = '';
		if ($phone) {
			//当前手机号当作账号是否存在
			$adminDCount = $this->adminDao->count(['account' => $phone, 'admin_type' => 2, 'is_del' => 0]);
			$account = $phone;
			if ($adminDCount) {
				$account = $account . '_' . $adminDCount;
			}
		}
		return $account;
	}

}
