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

namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\services\system\SystemUserApplyServices;
use think\facade\App;

/**
 * 加盟店申请
 * Class StoreApply
 * @package app\controller\admin\v1\store
 */
class StoreApply extends AuthController
{

	/**
	 * 加门店申请类型
	 * @var int
	 */
	protected $type = 1;

    /**
     * 构造方法
     * SupplierApply constructor.
     * @param App $app
     * @param SystemUserApplyServices $services
     */
    public function __construct(App $app, SystemUserApplyServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 获取供应商列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
			['data', '', '', 'time'],
			['status', ''],
            [['keyword', 's'], ''],
        ]);
        $where['is_del'] = 0;
		$where['type'] = $this->type;
        return $this->success($this->services->getApplyList($where));
    }


    /**
     * 获取供应商信息
     * @return mixed
     */
    public function read($id)
    {
        if (!$id) return $this->fail('缺少参数');
		$info = $this->services->get((int)$id);
		if (!$info) {
			return $this->fail('数据不存在');
		}
        return $this->success($info->toArray());
    }

	/**
	 *  审核商品表单
	 * @param $id
	 * @return mixed
	 */
	public function verifyForm($id)
	{
		if (!$id) {
			return $this->fail('缺少参数');
		}
		return $this->success($this->services->verifyForm((int)$id, $this->type));
	}

	/**
	 * 申请审核
	 * @param $id
	 * @return mixed
	 */
	public function verifyApply($id)
	{
		if (!$id) return $this->fail('缺少参数');
		$data = $this->request->postMore([
			['status', 1],//审核状态1：通过2：未通过
			['fail_msg', ''],
			['applicable_type', 1],//1；全部商品 2部分商品 3不同步商品
			['product_id', []],//同步商品Ids
			['product_status', 1],//自主添加商品
			['product_verify_status', 0],//商品免审
			['use_system_money', 1],//使用平台余额1:开启0:关闭
			['product_change_price_status', 0],//门店自主调价1:开启0关闭
			['is_alone', 0],//是否隔离
			['product_category_status', 0],//自建商品分类1:开启0关闭
		]);
		return $this->success('操作成功', $this->services->verifyApply((int)$id, $data, $this->type));
	}

	/**
	 * 备注表单
	 * @param $id
	 * @return mixed
	 */
	public function markForm($id)
	{
		if (!$id) {
			return $this->fail('缺少参数');
		}
		return $this->success($this->services->markForm((int)$id, $this->type));
	}

	/**
	 * 备注
	 * @param $id
	 * @return mixed
	 */
	public function mark($id)
	{
		if (!$id) return $this->fail('缺少参数');
		[$mark] = $this->request->postMore([
			['mark', ''],
		], true);
		if (!$mark) {
			return $this->fail('请输入备注');
		}
		$this->services->update($id, ['mark' => $mark]);
		return $this->success('备注成功！');
	}

	/**
	 * 删除申请
	 * @param $id
	 * @return mixed
	 */
	public function delete($id)
	{
		if (!$id) return $this->fail('删除失败，缺少参数');
		$this->services->update((int)$id, ['is_del' => 1]);
		return $this->success('删除成功！');
	}


}
