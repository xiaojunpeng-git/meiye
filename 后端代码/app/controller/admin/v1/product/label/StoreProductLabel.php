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
namespace app\controller\admin\v1\product\label;

use app\controller\admin\AuthController;
use app\services\product\label\StoreProductLabelServices;
use think\facade\App;
use app\Request;

/**
 * 商品标签
 * Class StoreProductLabel
 * @package app\controller\admin\v1\product\label
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
	 * 标签列表
	 * @return mixed
	 */
	public function index($label_cate = 0)
	{
		return $this->success($this->services->getList(['label_cate' => $label_cate, 'type' => 0, 'relation_id' => 0]));
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
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
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
			['label_cate', 0],//分组ID
			['label_name', ''],//标签名称
			['style_type', 1],//样式类型1：自定义2：图片
			['color', ''],//颜色
			['bg_color', ''],//背景色
			['border_color', ''],//边框色
			['icon', ''],//图标
			['is_show', 1],//移动端是否展示
			['status', 1],//状态是否开启
			['sort', 0],//排序
		]);
        if (!$data['label_cate']) {
            return $this->fail('请选择标签组');
        }
        if (!trim($data['label_name'])) {
            return $this->fail('请输入标签名称');
        }
        $label = $this->services->getOne(['label_cate' => $data['label_cate'], 'label_name' => $data['label_name'], 'type' => 0]);
        if ($id) {
            if ($label && $id != $label['id']) {
                return $this->fail('标签已经存在');
            }
            if ($this->services->update($id, $data)) {
				$data['id'] = $id;
				$this->services->cacheUpdate($data);
                return $this->success('编辑成功');
            } else {
                return $this->fail('编辑失败');
            }
        } else {
            if ($label) {
                return $this->fail('标签已经存在');
            }
            $data['type'] = 0;
			$data['relation_id'] = 0;
            $data['add_time'] = time();
            if ($this->services->save($data)) {
				$data['id'] = $id;
				$this->services->cacheUpdate($data);
                return $this->success('保存成功');
            } else {
                return $this->fail('保存失败');
            }
        }
    }

	/**
	 * 修改状态
	 * @param $id
	 * @param $status
	 * @return mixed
	 */
	public function set_status($id, $status)
	{
		$this->services->update($id, ['status' => $status]);

		//修改状态同步缓存
		$this->services->cacheSaveValue($id, 'status', $status);

		return $this->success($status == 0 ? '关闭成功' : '开启成功');
	}

	/**
	 * 修改移动端是否展示
	 * @param $id
	 * @param $status
	 * @return mixed
	 */
	public function set_show($id, $show)
	{
		$this->services->update($id, ['is_show' => $show]);

		//修改状态同步缓存
		$this->services->cacheSaveValue($id, 'is_show', $show);

		return $this->success($show == 0 ? '关闭成功' : '开启成功');
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
		$info = $this->services->get((int)$id);
		if (in_array($id, [1, 2, 3, 4, 5]) && $info['label_cate'] == 1) {//系统默认
			return $this->fail('默认标签不允许删除');
		}
        $this->services->delete($id);
		$this->services->cacheDelById($id);
		return $this->success('删除成功');
    }

	/**
	 * 删除指定资源
	 *
	 * @param int $id
	 * @return \think\Response
	 */
	public function del(Request $request)
	{
		[$ids, $all, $where] = $request->postMore([
			['ids', ''],
			['all', 0],
			['where', []],
		], true);
		if ($all == 0) {//单页不走队列
			if (!$ids) {
				return $this->fail('请选择需要删除的标签');
			}
		} else {
			$ids = $this->services->getColumn($where + ['type' => 0, 'relation_id' => 0], 'id');
		}
		$ids = is_string($ids) ? stringToIntArray($ids) : $ids;
		//默认标签不允许删除
		$ids = array_diff($ids, [1, 2, 3, 4, 5]);
		if ($ids) {
			$this->services->del($ids);
			foreach ($ids as $id) {
				$this->services->cacheDelById($id);
			}
		}
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
