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

namespace app\services\system\form;

use app\dao\system\form\SystemFormDataDao;
use app\services\BaseServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;


/**
 * 系统表单
 * Class SystemFormDataServices
 * @package app\services\system\form
 * @mixin SystemFormDataDao
 */
class SystemFormDataServices extends BaseServices
{
	use ServicesTrait;

    /**
     * DiyServices constructor.
     * @param SystemFormDataDao $dao
     */
    public function __construct(SystemFormDataDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取表单收集数据列表
	 * @param int $id
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getFormDataList(int $id = 0, array $where = [])
	{
		$where['is_del'] = 0;
		if ($id) $where['system_form_id'] = $id;
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, ['*'], $page, $limit, ['user', 'systemForm', 'product', 'order']);
		$count = $this->dao->count($where);
		return compact('list', 'count');
	}

	/**
	 * 处理系统表单数据
	 * @param int $id
	 * @param int $type
	 * @return bool
	 */
	public function setFormData(int $id, int $type = 1)
	{
		if (!$id) {
			return false;
		}
		switch ($type) {
			case 1://订单
				/** @var StoreOrderServices $orderServices */
				$orderServices = app()->make(StoreOrderServices::class);
				$orderInfo = $orderServices->get($id, ['id', 'uid', 'custom_form']);
				if (!$orderInfo || !$orderInfo['custom_form']) {
					return false;
				}
				/** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
				$storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
				$cartList = $storeOrderCartInfoServices->getCartInfoList(['oid' => $id, 'cart_type' => 0], ['id', 'cart_info']);
				$system_form_id = 0;
				$product_id = 0;
				foreach ($cartList as $cartInfo) {
					$info = is_string($cartInfo['cart_info']) ? json_decode($cartInfo['cart_info'], true) : $cartInfo['cart_info'];
					$system_form_id = $info['productInfo']['system_form_id'] ?? 0;
					$product_id = $info['productInfo']['id'];
					if ($system_form_id) {
						break;
					}
				}
				if (!$system_form_id) {
					return false;
				}
				$form = [
					'type' => 1,
					'relation_id' => $orderInfo['id'] ?? 0,
					'uid' => $orderInfo['uid'] ?? 0,
					'system_form_id' => $system_form_id,
					'product_id' => $product_id,
					'value' => is_string($orderInfo['custom_form']) ? json_decode($orderInfo['custom_form'], true) : $orderInfo['custom_form']
				];
				break;
			case 2://预约单

				break;
			default:
				return false;
				break;
		}
		$this->saveFormData($form, $type);
		return true;
	}

	/**
	 * 保存系统表单收集数据
	 * @param array $form
	 * @param int $type
	 * @return bool
	 */
	public function saveFormData(array $form, int $type = 1)
	{
		if (!$form) {
			throw new ValidateException('缺少表单收集数据');
		}
		$values = $form['value'] ?? [];
		if ($values) {
			/** @var SystemFormServices $systemFormServices */
			$systemFormServices = app()->make(SystemFormServices::class);
			if (isset($values[0])) {//二位数组
				$dataAll = [];
				foreach ($values as $value) {
					if (!$value) continue;
					$data = ['type' => $type, 'add_time' => time()];
					$form['value'] = json_encode($systemFormServices->handleForm($value));
					switch ($type) {
						case 1://订单
							$data = array_merge($data, $form);
							break;
					}
					$dataAll[] = $data;
				}
				if ($dataAll) {
					$this->dao->saveAll($dataAll);
				}
			} else {
				$data = ['type' => $type, 'add_time' => time()];
				$value = $values;
				$form['value'] = $systemFormServices->handleForm($value);
				$form['value'] = json_encode($form['value']);
				switch ($type) {
					case 1://订单
						$data = array_merge($data, $form);
						break;
				}
				if ($data) {
					$this->dao->save($data);
				}
			}
		}
		return true;
	}

}
