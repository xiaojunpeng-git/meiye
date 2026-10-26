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
namespace app\controller\supplier\product\label;

use app\controller\supplier\AuthController;
use app\services\product\label\StoreProductLabelServices;
use think\facade\App;
use app\Request;

/**
 * 商品标签
 * Class StoreProductLabel
 * @package app\controller\supplier\product\label
 */
class StoreProductLabel extends AuthController
{
    /**
     * StoreProductLabel constructor.
     * @param App $app
     * @param StoreProductLabelServices $services
     */
    public function __construct(App $app, StoreProductLabelServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


	/**
	 * 带标签的标签组树形结构
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function tree_list()
	{
		return $this->success($this->services->getProductLabelTreeList());
	}

    /**
     * 获取商品标签列表
     * @return mixed
     */
    public function allLabel()
    {
		return $this->success($this->services->getAllLabel());
    }

    /**
     * 添加、编辑商品标签
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function save(Request $request, $id)
    {
        $data = $request->postMore([
            ['label_cate', 0],
            ['label_name', ''],
        ]);
        if (!$data['label_cate']) {
            return $this->fail('请选择标签组');
        }
        if (!trim($data['label_name'])) {
            return $this->fail('请输入标签名称');
        }
        $label = $this->services->getOne(['label_cate' => $data['label_cate'], 'label_name' => $data['label_name'], 'type' => 2, 'relation_id' => $this->supplierId]);
        if ($id) {
            if ($label && $id != $label['id']) {
                return $this->fail('标签已经存在');
            }
            if ($this->services->update($id, $data)) {
                return $this->success('编辑成功');
            } else {
                return $this->fail('编辑失败');
            }
        } else {
            if ($label) {
                return $this->fail('标签已经存在');
            }
            $data['type'] = 2;
			$data['relation_id'] = $this->supplierId;
            $data['add_time'] = time();
            if ($this->services->save($data)) {
                return $this->success('保存成功');
            } else {
                return $this->fail('保存失败');
            }
        }
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$id || !$this->services->count(['id' => $id])) {
            return $this->fail('删除的数据不存在');
        }
        $this->services->delete($id);
		return $this->success('删除成功');
    }

    /**
     * 获取表单
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getLabelForm()
    {
        return $this->success($this->services->getLabelForm());
    }
}
