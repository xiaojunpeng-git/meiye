<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\services\product\ensure;

use app\dao\product\ensure\StoreProductEnsureDao;
use app\services\BaseServices;
use app\services\product\product\StoreProductServices;
use mohe\services\FormBuilder as Form;
use FormBuilder\Factory\Iview;
use think\facade\Route as Url;

/**
 * 商品保障服务
 * Class StoreProductEnsureServices
 * @package app\services\product\ensure
 * @mixin StoreProductEnsureDao
 */
class StoreProductEnsureServices extends BaseServices
{

    /**
     * StoreProductEnsureServices constructor.
     * @param StoreProductEnsureDao $dao
     */
    public function __construct(StoreProductEnsureDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取所有保障服务
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getAllEnsure(int $type = 0, int $relation_id = 0)
	{
		$where['relation_id'] = $relation_id;
		$where['type'] = $type;
		$where['status'] = 1;
		return $this->dao->getList($where, 'id,name');
	}

	/**
	 * 获取缓存内的数据
	 * @param array $ids
	 * @param array|null $field
	 * @return array|mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/14
	 */
	public function getEnsurCache(array $ids, array $field = null)
	{
		if (app()->config->get('cache.is_data')) {
			$list = $this->dao->cacheInfoByIds($ids);
		} else {
			$list = null;
		}

		if (!$list) {
			$list = $this->dao->getList(['ids' => $ids, 'status' => 1]);
			foreach ($list as $item) {
				$this->dao->cacheUpdate($item);
			}
		}

		if ($field && $list) {
			$newList = [];
			foreach ($list as $item) {
				$data = [];
				foreach ($field as $k) {
					$data[$k] = $item[$k] ?? null;
				}
				$newList[] = $data;
			}
			$list = $newList;
		}

		return $list;
	}

    /**
     * 获取保障服务列表（带标签）
     * @param array $where
     * @return array
     */
    public function getEnsureList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        if ($list) {
            /** @var StoreProductServices $storeProductServices */
            $storeProductServices = app()->make(StoreProductServices::class);
            foreach ($list as &$item) {
                $item['product_count'] = $storeProductServices->getUseEnsureCount((int)$item['id']);
            }
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }


	/**
	 * 创建新增表单
	 * @return mixed
	 */
    public function createForm()
    {
        return create_form('添加保障服务', $this->form(), Url::buildUrl('/product/ensure'), 'POST');
    }

	/**
	 * 创建编辑表单
	 * @param int $id
	 * @return mixed
	 */
    public function editForm(int $id)
    {
        $info = $this->dao->get($id);
        return create_form('编辑保障服务', $this->form($info), $this->url('/product/ensure/' . $id), 'PUT');
    }

    /**
     * 生成表单参数
     * @param array $info
     * @return array
     */
    public function form($info = [])
    {
        $f[] = Form::input('name', '保障服务条款：', $info['name'] ?? '')->maxlength(100)->required();
        $f[] = Form::textarea('desc', '内容描述：', $info['desc'] ?? '')->required();
        $f[] = Form::frameImage('image', '图标(100px*100)：', Url::buildUrl(config('admin.admin_prefix') .  '/widget.images/index', array('fodder' => 'image')), $info['image'] ?? '')->icon('ios-add')->width('960px')->appendValidate(Iview::validateStr()->message('请选择图标(建议尺寸：100px*100px)')->required())->height('505px')->modal(['footer-hide' => true]);
        $f[] = Form::number('sort', '排序：', (int)($info['sort'] ?? 0))->min(0)->min(0);
        return $f;
    }
}
