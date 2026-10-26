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
namespace app\controller\admin\v1\marketing\integral;

use app\controller\admin\AuthController;
use app\Request;
use app\services\activity\integral\StoreIntegralCategoryServices;
use app\services\other\CategoryServices;
use think\facade\App;

/**
 * 积分商品分类
 * Class StoreProductLabelCate
 * @package app\controller\admin\v1\product\label
 */
class StoreIntegralCategory extends AuthController
{

	/**
	 * StoreIntegral constructor.
	 * @param App $app
	 * @param StoreIntegralCategoryServices $services
	 */
	public function __construct(App $app, StoreIntegralCategoryServices $services)
	{
		parent::__construct($app);
		$this->services = $services;
	}

    /**
     * 获取列表
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $where = $request->postMore([
            ['name', ''],
            ['is_show', '']
        ]);
        $where['group'] = CategoryServices::INTEGRAL_CATEGORY_GROUP;
        return $this->success($this->services->getList($where));
    }

    /**
     * 生成新增表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return $this->success($this->services->createForm());
    }

    /**
     * 保存
     * @return mixed
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['integral_min', 0],
            ['integral_max', 0],
            ['sort', 0],
            ['is_show', 0],
        ]);
        if ($data['integral_min'] >= $data['integral_max']) {
            return $this->fail('积分最低值不能大于积分最大值');
        }
        if (!trim($data['name'])) {
            return $this->fail('请输入积分商品分类名称');
        }
        if ($this->services->getOne(['name' => $data['name'], 'group' => CategoryServices::INTEGRAL_CATEGORY_GROUP])) {
            return $this->fail('积分商品分类已存在');
        }
        $integralNum = $this->services->search(['group' => CategoryServices::INTEGRAL_CATEGORY_GROUP])->field(['integral_min','integral_max'])->select();
        if ($integralNum) {
            foreach ($integralNum as $item) {
                if (($item['integral_min'] <= $data['integral_min'] && $item['integral_max'] >= $data['integral_min']) || ($item['integral_min'] <= $data['integral_max'] && $item['integral_max'] >= $data['integral_max'])) {
                    return $this->fail('积分商品分类积分范围不可重叠');
                }
            }
        }
        $data['type'] = 0;
        $data['relation_id'] = 0;
        $data['group'] = CategoryServices::INTEGRAL_CATEGORY_GROUP;
        $data['add_time'] = time();
        $this->services->save($data);
        return $this->success('添加积分商品分类成功!');
    }

    /**
     * 生成更新表单
     * @param $id
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function edit($id)
    {
        return $this->success($this->services->editForm((int)$id));
    }

    /**
     * 更新
     * @param $id
     * @return mixed
     */
    public function update($id)
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['integral_min', 0],
            ['integral_max', 0],
            ['sort', 0],
            ['is_show', 0],
        ]);
        if ($data['integral_min'] >= $data['integral_max']) {
            return $this->fail('积分最低值不能大于积分最大值');
        }
        if (!$data['name']) {
            return $this->fail('请输入积分商品分类名称');
        }
        $cate = $this->services->getOne(['name' => $data['name'], 'group' => CategoryServices::INTEGRAL_CATEGORY_GROUP]);
        if ($cate && $cate['id'] != $id) {
            return $this->fail('积分商品分类已存在');
        }
        $integralNum = $this->services->search(['not_id' => $id, 'group' => CategoryServices::INTEGRAL_CATEGORY_GROUP])->field(['integral_min','integral_max'])->select();
        if ($integralNum) {
            foreach ($integralNum as $item) {
                if (($item['integral_min'] <= $data['integral_min'] && $item['integral_max'] >= $data['integral_min']) || ($item['integral_min'] <= $data['integral_max'] && $item['integral_max'] >= $data['integral_max'])) {
                    return $this->fail('积分商品分类积分范围不可重叠');
                }
            }
        }

        $this->services->update($id, $data);
        return $this->success('修改积分商品分类成功!');
    }

    /**
     * 显示隐藏
     * @param $is_show
     * @param $id
     * @return \think\Response
     * User: liusl
     * DateTime: 2023/11/13 12:19
     */
    public function set_show($is_show = '', $id = '')
    {
        if ($is_show == '' || $id == '') return $this->fail('缺少参数');
        $this->services->update($id, compact('is_show'));
        return $this->success($is_show == 1 ? '显示成功' : '隐藏成功');
    }

    /**
     * 删除
     * @param $id
     * @return \think\Response
     * User: liusl
     * DateTime: 2023/11/13 12:21
     */
    public function delete($id)
    {
        if (!$id || !$this->services->count(['id' => $id])) {
            return $this->fail('删除的数据不存在');
        }
        $this->services->delete((int)$id);
        return $this->success('删除成功!');
    }
}
