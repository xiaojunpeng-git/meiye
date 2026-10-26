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

namespace app\controller\admin\v1\diy;


use app\controller\admin\AuthController;
use app\services\activity\lottery\LuckLotteryServices;
use app\services\diy\DiyServices;
use app\services\diy\PageCategoryServices;
use app\services\diy\PageLinkServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\store\SystemStoreServices;
use think\facade\App;

/**
 * Class PageLink
 * @package app\controller\admin\v1\diy
 */
class PageLink extends AuthController
{

    /**
     * PageLink constructor.
     * @param App $app
     * @param PageLinkServices $services
     */
    public function __construct(App $app, PageLinkServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 获取页面链接分类
     * @return mixed
     */
    public function getCategory(PageCategoryServices $services)
    {
        return $this->success($services->getCategroyList());
    }

    /**
     * 获取页面链接
     * @param $cate_id
     * @return mixed
     */
    public function getLinks($cate_id, PageCategoryServices $pageCategoryServices)
    {
        if (!$cate_id) return $this->fail('缺少参数');
        $category = $pageCategoryServices->get((int)$cate_id);
        if (!$category) {
            return $this->fail('页面分类不存在');
        }
        switch ($category['type']) {
            case 'special'://主题
                /** @var DiyServices $diyServices */
                $diyServices = app()->make(DiyServices::class);
                $data = $diyServices->getDiyList(['type' => [1, 2], 'is_del' => 0, 'not_template_name' => ['goodSelect', 'shopping', 'fresh']]);
                break;
            case 'product_category'://商品分类
				[$pid] = $this->request->getMore([
					['pid', 0]
				], true);
                /** @var StoreProductCategoryServices $storeCategoryServices */
                $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
                $data = $storeCategoryServices->getList(['pid' => $pid, 'is_show' => 1, 'type' => 0, 'relation_id' => 0]);
                break;
			case 'store_home'://门店首页
				$where = $this->request->getMore([
					['keywords', ''],
				]);
				/** @var SystemStoreServices $systemStoreServices */
				$systemStoreServices = app()->make(SystemStoreServices::class);
				$where['is_del'] = 0;
				$field = ['id', 'cate_id', 'name', 'phone', 'address', 'detailed_address', 'image', 'is_show', 'day_time', 'day_start', 'day_end'];
				$data = $systemStoreServices->getStoreList($where, $field, '', '', 0, ['categoryName']);
				break;
            case 'lottery'://抽奖链接
                $where = $this->request->getMore([
                    ['keyword', ''],
                ]);
                /** @var LuckLotteryServices $lotteryServices */
                $lotteryServices = app()->make(LuckLotteryServices::class);
                $where['is_del'] = 0;
                $where['status'] = 1;
                $where['factor'] = 1;
                $data = $lotteryServices->getList($where);
                break;
            default:
                $data = $this->services->getLinkList(['cate_id' => $cate_id]);
                break;
        }
        return $this->success($data);
    }

    /**
     * 保存链接
     * @param $cate_id
     * @param PageCategoryServices $pageCategoryServices
     * @return mixed
     */
    public function saveLink($cate_id, PageCategoryServices $pageCategoryServices)
    {
        $data = $this->request->getMore([
            ['name', ''],
            ['url', '']
        ]);
        if (!$cate_id || !$data['name'] || !$data['url']) return $this->fail('缺少参数');
        $category = $pageCategoryServices->get((int)$cate_id);
        if (!$category) {
            return $this->fail('页面分类不存在');
        }
        $data['cate_id'] = $cate_id;
        $data['add_time'] = time();
        if (!$this->services->save($data)) {
            return $this->fail('添加失败');
        }
        return $this->success('添加成功');
    }

    /**
     * 删除链接
     * @param $id
     * @return mixed
     */
    public function del($id)
    {
        if (!$id) return $this->fail('参数错误');
        $this->services->del($id);
        return $this->success('删除成功!');
    }

}
