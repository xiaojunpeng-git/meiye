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

namespace app\services\system;


use app\dao\system\SystemUserApplyDao;
use app\services\BaseServices;
use app\dao\system\SystemRoleDao;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;
use think\facade\Route as Url;


/**
 * Class SystemUserApplyServices
 * @package app\services\system
 * @mixin SystemRoleDao
 */
class SystemUserApplyServices extends BaseServices
{

	/**
	 * 申请类型
	 * @var string[]
	 */
	protected array $typeName = [
		0 => '分销员',
		1 => '门店',
		2 => '供应商',
	];

	/**
	 * 处理url
	 * @var string[]
	 */
	protected $typeUrl = [
		0 => 'promoter',
		1 => 'store',
		2 => 'supplier',
	];

	/**
	 * @var string[]
	 */
	protected $statusName = [
		0 => '未处理',
		1 => '已通过',
		2 => '已拒绝',
	];

    /**
     * SystemUserApplyServices constructor.
     * @param SystemUserApplyDao $dao
     */
    public function __construct(SystemUserApplyDao $dao)
    {
        $this->dao = $dao;
    }


	/**
	 * 所有申请记录列表
	 * @param array $where
	 * @param string $field
	 * @return array
	 */
	public function getApplyList(array $where, string $field = '*')
	{
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, $field, ['user'], $page, $limit);
		foreach ($list as &$item) {
			if ($item['type'] > 0) {
				$item['nickname'] = $item['user']['nickname'] ?? '';
			} else {
				$item['nickname'] = $item['name'];
			}
			$item['real_name'] = $item['system_name'];
			$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
			$item['status_name'] = $this->statusName[$item['status']] ?? '未处理';
		}
		$count = $this->dao->count($where);
		return compact('list', 'count');
	}

	/**
	 * 用户申请记录
	 * @param int $uid
	 * @param int $type
	 * @return array
	 */
	public function getUserApply(int $uid, int $type = 2)
	{
		[$page, $limit] = $this->getPageValue();
		$where = ['uid' => $uid, 'type' => $type, 'is_del' => 0];
		$list = $this->dao->getList($where, '*', ['user'], $page, $limit);
		foreach ($list as &$item) {
			if ($item['type'] > 0) {
				$item['nickname'] = $item['user']['nickname'] ?? '';
			} else {
				$item['nickname'] = $item['name'];
			}
			$item['real_name'] = $item['system_name'];
			$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
		}
		return $list;
	}

	/**
	 * 添加申请
	 * @param int $id
	 * @param int $uid
	 * @param $data
	 * @param int $type
	 * @return int|mixed
	 */
	public function saveApply(int $id, int $uid, $data, int $type = 2)
	{
		$data['uid'] = $uid;
		$data['add_time'] = time();
		$data['type'] = $type;
		if ($id) {
			$data['status'] = 0;
			$data['status_time'] = 0;
			$data['fail_msg'] = '';
			$this->dao->update($id, $data);
		} else {
			$res = $this->dao->save($data);
			if (!$res) {
				throw new ValidateException('保存失败，请稍后再试');
			}
			$id = $res->id;
		}
		return $id;

	}

	/**
	 * 审核表单
	 * @param int $id
	 * @return mixed
	 */
	public function verifyForm(int $id, int $type = 2)
	{
		$info = $this->dao->get($id);
		if (!$info) {
			throw new ValidateException('申请记录不存在');
		}
		$f = [];
		$f[] = Form::radio('status', '审核状态：', 1)->options([['value' => 1, 'label' => '通过'], ['value' => 2, 'label' => '拒绝']])->appendControl(2, [
					Form::textarea('fail_msg', '拒绝原因：')->required('请输入拒绝原因')
				]);
		switch ($type) {
			case 1://门店
				$url = '/store';
				break;
			case 2://供应商
				$url = '/supplier';
				break;
			default:
				$url = '/supplier';
				break;
		}
		return create_form(($this->typeName[$type] ?? '' ) . '申请审核', $f, Url::buildUrl('/' . ($this->typeUrl[$type] ?? 'supplier') . '/apply/verify/' . $id), 'post');
	}

	/**
	 * 备注表单
	 * @param int $id
	 * @return mixed
	 */
	public function markForm(int $id, int $type = 2)
	{
		$info = $this->dao->get($id);
		if (!$info) {
			throw new ValidateException('申请记录不存在');
		}
		$info = $info->toArray();
		$f = [];
		$f[] = Form::textarea('mark', ($this->typeName[$type] ?? '' ) . '申请备注：', $info['mark'])->required('请输入拒绝原因');
		return create_form('备注', $f, Url::buildUrl('/' . ($this->typeUrl[$type] ?? 'supplier') . '/apply/mark/' . $id), 'post');
	}

	/**
	 * 审核申请记录
	 * @param int $id
	 * @param array $data
	 * @param int $type
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function verifyApply(int $id, array $data, int $type = 2)
	{
		$info = $this->dao->get($id);
		if (!$info) {
			throw new ValidateException('申请记录不存在');
		}
		$info = $info->toArray();
		if ($info['status'] != 0) {
			throw new ValidateException('请不要重复审核');
		}
		if (!isset($data['status']) || !in_array($data['status'], [1, 2])) {
			throw new ValidateException('审核状态异常，请稍后重试');
		}
		$status = $data['status'];
		$result = $this->transaction(function () use ($id, $type, $status, $info, $data) {
			$result = [];
			$update = ['status' => $status, 'fail_msg' => $data['fail_msg'] ?? '', 'status_time' => time()];
			if ($data['status'] == 2) {//拒绝
				if (!isset($data['fail_msg']) || !$data['fail_msg']) {
					throw new ValidateException('请输入拒绝原因');
				}
			} else {//1 通过
				switch ($type) {
					case 1://门店
						/** @var SystemStoreServices $systemStoreServices */
						$systemStoreServices = app()->make(SystemStoreServices::class);
						unset($data['status'], $data['fail_msg']);
						$result = $systemStoreServices->verifyAgreeCreate($id, $data);
						break;
					case 2://供应商
						/** @var SystemSupplierServices $systemSupplierServices */
						$systemSupplierServices = app()->make(SystemSupplierServices::class);
						$result = $systemSupplierServices->verifyAgreeCreate($id, $info);
						break;
					default://
						throw new ValidateException('暂不支持当前申请类型');
						break;
				}
				$update['relation_id'] = $result['id'] ?? 0;
			}
			$res = $this->dao->update($id, $update);
			if (!$res) {
				throw new ValidateException('审核保存失败');
			}
			return $result;
		});
		$info['account'] = $result['account'] ?? $info['phone'];
		switch ($type) {
			case 1://门店
				event('store.verify', [$info, $status]);
				break;
			case 2://供应商
				event('supplier.verify', [$info, $status]);
				break;
		}
		return $info;
	}




}
