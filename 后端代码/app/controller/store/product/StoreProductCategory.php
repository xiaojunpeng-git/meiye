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
namespace app\controller\store\product;

use app\controller\store\AuthController;
use app\services\product\category\StoreProductCategoryServices;
use app\services\store\SystemStoreServices;
use think\facade\App;

/**
 * 商品分类控制器
 * Class StoreProductCategory
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
        ]);
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
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
        $relation_id = $type ? $this->storeId : 0;
        $list = $this->service->getTierList(1, $type, $relation_id);
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
    public function cascader_list($type = 1)
    {
        $relation_id = $type ? $this->storeId : 0;
        return $this->success($this->service->cascaderList($type,$relation_id));
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
     * 生成新增表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return $this->success($this->service->createForm(1, $this->storeId));
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
        $data['type'] = 1;
        $data['relation_id'] = $this->storeId;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $product_category_status = $storeServices->value(['id'=>$this->storeId], 'product_category_status');
        if(!$product_category_status) return $this->fail('您暂无操作自建分类的权限');
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
        return $this->success($this->service->editForm((int)$id, 1, $this->storeId));
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
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $product_category_status = $storeServices->value(['id'=>$this->storeId], 'product_category_status');
        if(!$product_category_status) return $this->fail('您暂无操作自建分类的权限');
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
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $product_category_status = $storeServices->value(['id'=>$this->storeId], 'product_category_status');
        if(!$product_category_status) return $this->fail('您暂无操作自建分类的权限');
        $this->service->del((int)$id);
        return $this->success('删除成功!');
    }
}
