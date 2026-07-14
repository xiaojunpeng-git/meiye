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
namespace app\controller\admin\v1\product;

use app\controller\admin\AuthController;
use app\services\product\category\StoreProductCategoryServices;
use think\facade\App;

/**
 * 商品分类控制器
 * Class StoreProductCategory
 * @package app\controller\admin\v1\product
 */
class StoreProductCategory extends AuthController
{
    /**
     * @var StoreProductCategoryServices
     */
    protected $service;

    /**
     * StoreCategory constructor.
     * @param App $app
     * @param StoreProductCategoryServices $service
     */
    public function __construct(App $app, StoreProductCategoryServices $service)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    /**
     * 分类列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['is_show', ''],
            ['pid', 0],
            ['cate_name', ''],
            ['id', ''],
        ]);
        $where['type'] = 0;
        $where['pid'] = (int)$where['pid'];
        $data = $this->service->getList($where, '*', ['AdminChildren']);
        return $this->success($data);
    }

    /**
     * 商品分类搜索
     * @return mixed
     */
    public function tree_list($type)
    {
        $list = $this->service->getTierList(1, $type);
        return $this->success($list);
    }

    /**
     * 获取分类cascader格式数据
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cascader_list($type = 0, $relation_id = 0)
    {
        return $this->success($this->service->cascaderList($type, $relation_id));
    }

    /**
     * 修改状态
     * @param string $is_show
     * @param string $id
     */
    public function set_show($is_show = '', $id = '')
    {
        if ($is_show == '' || $id == '') return $this->fail('缺少参数');
        $this->service->setShow($id, $is_show);
        return $this->success($is_show == 1 ? '显示成功' : '隐藏成功');
    }

    /**
     * 修改手机端卡包分类展示状态
     * @param string $mobile_card_show
     * @param string $id
     */
    public function set_mobile_card_show($mobile_card_show = '', $id = '')
    {
        if ($mobile_card_show === '' || $id === '') return $this->fail('缺少参数');
        $this->service->setMobileCardShow((int)$id, (int)$mobile_card_show);
        return $this->success($mobile_card_show == 1 ? '已开启手机端卡包展示' : '已关闭手机端卡包展示');
    }

    /**
     * 生成新增表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return $this->success($this->service->createForm());
    }

    /**
     * 保存新增分类
     * @return mixed
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['pid', []],
            ['cate_name', ''],
            ['pic', ''],
            ['big_pic', ''],
            ['sort', 0],
            ['is_show', 0]
        ]);
        if (!$data['cate_name']) {
            return $this->fail('请输入分类名称');
        }
        $data['pid'] = end($data['pid']);
        $data['type'] = 0;
        $data['relation_id'] = 0;
        $this->service->createData($data);
        return $this->success('添加分类成功!');
    }

    /**
     * 生成更新表单
     * @param $id
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function edit($id)
    {
        return $this->success($this->service->editForm((int)$id));
    }

    /**
     * 更新分类
     * @param $id
     * @return mixed
     */
    public function update($id)
    {
        $data = $this->request->postMore([
            ['pid', []],
            ['cate_name', ''],
            ['pic', ''],
            ['big_pic', ''],
            ['sort', 0],
            ['is_show', 0]
        ]);
        if (!$data['cate_name']) {
            return $this->fail('请输入分类名称');
        }
        $data['pid'] = end($data['pid']);
        $this->service->editData((int)$id, $data);
        return $this->success('修改成功!');
    }

    /**
     * 删除分类
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        $this->service->del((int)$id);
        return $this->success('删除成功!');
    }
}
