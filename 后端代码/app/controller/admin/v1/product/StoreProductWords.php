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

use app\Request;
use app\services\product\product\StoreProductWordsServices;
use app\controller\admin\AuthController;
use think\facade\App;

/**
 * 热词
 * Class StoreProductWords
 * @package app\controller\admin\v1\product
 */
class StoreProductWords extends AuthController
{

	/**
	 * StoreProductReply constructor.
	 * @param App $app
	 * @param StoreProductWordsServices $service
	 */
	public function __construct(App $app, StoreProductWordsServices $service)
	{
		parent::__construct($app);
		$this->services = $service;
	}

    /**
     * 获取所有热词
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAllWords()
    {
        return $this->success($this->services->getAllWordsList());
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index(Request $request)
    {
        $where = $request->postMore([
            ['name', '']
        ]);
        $where['type'] = 0;
        $where['relation_id'] = 0;
        $where['is_del'] = 0;
        return $this->success($this->services->getWordsList($where));
    }

	/**
     * 显示指定的资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function info($id)
    {
        if (!$id) {
            return $this->fail('缺少ID');
        }
        $info = $this->services->get($id);
        if (!$info) {
            return $this->fail('获取热词失败');
        }
        return $this->success($info->toArray());
    }

    /**
     * 保存新建的资源
     *
     * @param \app\Request $request
     * @return \think\Response
     */
    public function save(Request $request, $id)
    {
        $data = $request->postMore([
            ['name', ''],//名称
			['color', ''],//颜色
			['bg_color', ''],//背景色
			['border_color', ''],//边框色
			['icon', ''],//图标
            ['sort', 0],//排序
			['is_search', 1],
            ['is_show', 1]//状态
        ]);
        validate(\app\validate\admin\product\StoreProductWordsValidate::class)->scene('get')->check(['name' => $data['name']]);
		$res = $this->services->saveData((int)$id, $data);
		return $this->success( $res ? '保存成功' : '保存失败');
    }


	/**
     * 修改状态
     * @param string $is_show
     * @param string $id
     */
    public function set_show($is_show = '', $id = '')
    {
        if ($is_show == '' || $id == '') return $this->fail('缺少参数');
        $this->services->setShow($id, $is_show);
        return $this->success($is_show == 1 ? '显示成功' : '隐藏成功');
    }


	/**
     * 删除
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        $this->services->del((int)$id);
        return $this->success('删除成功!');
    }
}
