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
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\activity\video\VideoServices;
use app\services\article\ArticleServices;
use app\services\diy\DiyServices;
use app\services\other\CacheServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use mohe\exceptions\AdminException;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\App;

/**
 * DIY
 * Class Diy
 * @package app\controller\admin\v1\diy
 */
class Diy extends AuthController
{
    /**
     * @var DiyServices
     */
    protected $services;

    /**
     * Diy constructor.
     * @param App $app
     * @param DiyServices $services
     */
    public function __construct(App $app, DiyServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * DIY列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['type', 1],
            ['name', ''],
            ['version', ''],
        ]);
		$where['is_del'] = 0;
        $data = $this->services->getDiyList($where);
        return $this->success($data);
    }

	/**
	 * 修改模版名称
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function updateName($id)
	{
		[$name] = $this->request->postMore([
			['name', '']
		], true);
		$id = (int)$id;
		if ($id) {
			$info = $this->services->get((int)$id);
			if (!$info) {
				return $this->fail('数据不存在');
			}
			if ($info['name'] == $name) {
				return $this->fail('模版名称无任何修改');
			}
			$info->name = $name;
			$info->save();
		} else {
			$res = $this->services->save([
				'type' => 1,
				'name' => $name,
				'is_diy' => 1,
				'add_time' => time(),
				'update_time' => time()
			]);
			if (!$res) return $this->fail('保存失败');
			$id = (int)$res->id;
		}
		return $this->success('保存成功', ['id' => $id]);
	}

    /**
     * 保存资源
     * @param int $id
     * @return mixed
     */
    public function saveData($id = 0)
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['title', ''],
            ['value', ''],
            ['type', 1],
            ['cover_image', ''],
            ['is_show', 0],
            ['is_bg_color', 0],
            ['is_bg_pic', 0],
            ['bg_tab_val', 0],
            ['color_picker', ''],
            ['bg_pic', ''],
        ]);
		$id = (int)$id;
        $value = is_string($data['value']) ? json_decode($data['value'], true) : $data['value'];
        $infoDiy = $id ? $this->services->get($id, ['is_diy']) : [];
		foreach ($value as $key => &$item) {
			if ($item['name'] === 'goodList') {
				if (isset($item['selectConfig']['list'])) {
					unset($item['selectConfig']['list']);
				}
				if (isset($item['goodsList']['list']) && is_array($item['goodsList']['list'])) {
					$limitMax = config('database.page.limitMax', 50);
					if (isset($item['numConfig']['val']) && isset($item['tabConfig']['tabVal']) && $item['tabConfig']['tabVal'] == 0 && $item['numConfig']['val'] > $limitMax) {
						return $this->fail('您设置得商品个数超出系统限制,最大限制' . $limitMax . '个商品');
					}
					$item['goodsList']['ids'] = array_column($item['goodsList']['list'], 'id');
					unset($item['goodsList']['list'], $item['productList']['list']);
				}
			} elseif ($item['name'] === 'articleList') {
				if (isset($item['selectList']['list']) && is_array($item['selectList']['list'])) {
					unset($item['selectList']['list']);
				}
			} elseif ($item['name'] === 'promotionList') {
				if (isset($item['tabConfig']['list']) && $item['tabConfig']['list']) {
					$list = $item['tabConfig']['list'];
					foreach ($list as &$tabValue) {
						if (isset($tabValue['goodsList']['list']) && is_array($tabValue['goodsList']['list'])) {
							$limitMax = config('database.page.limitMax', 50);
							if (isset($tabValue['numConfig']['val']) && isset($tabValue['tabConfig']['tabVal']) && $tabValue['tabConfig']['tabVal'] == 0 && $tabValue['numConfig']['val'] > $limitMax) {
								return $this->fail('您设置得商品个数超出系统限制,最大限制' . $limitMax . '个商品');
							}
							$tabValue['goodsList']['ids'] = array_column($tabValue['goodsList']['list'], 'id');
						}
						unset($tabValue['goodsList']['list'], $item['productList']['list']);
					}
					$item['tabConfig']['list'] = $list;
				}
			} elseif ($item['name'] === 'newVip') {
				unset($item['newVipList']['list']);
			} elseif ($item['name'] === 'shortVideo') {
				unset($item['videoList']);
			}
		}
		$data['value'] = json_encode($value);
		$data['version'] = uniqid();
        return $this->success($id ? '修改成功' : '保存成功', ['id' => $this->services->saveData($id, $data)]);
    }

    /**
     * 删除模板
     * @param $id
     * @return mixed
     */
    public function del($id)
    {
        $this->services->del($id);
        return $this->success('删除成功');
    }

    /**
     * 使用模板
     * @param $id
     * @return mixed
     */
    public function setStatus($id)
    {
        return $this->success($this->services->setStatus($id));
    }

    /**
     * 获取一条数据
     * @param int $id
     * @return mixed
     */
    public function getInfo($id, StoreProductServices $services, StoreNewcomerServices $newcomerServices, VideoServices $videoServices)
    {
        if (!(int)$id) return app('json')->fail('参数错误');
        $info = $this->services->get((int)$id);
        if ($info) {
            $info = $info->toArray();
        } else {
			return app('json')->fail('模板不存在');
        }
        $info['value'] = json_decode($info['value'], true);
        if ($info['value']) {
            /** @var ArticleServices $articleServices */
            $articleServices = app()->make(ArticleServices::class);
			/** @var StoreProductCategoryServices $storeCategoryServices */
			$storeCategoryServices = app()->make(StoreProductCategoryServices::class);
            if ($info['is_diy']) {
				$limitMax = config('database.page.limitMax', 50);
                foreach ($info['value'] as &$item) {
					switch ($item['name']) {
						case 'goodList'://商品列表
							$typeConfig = $item['typeConfig']['activeValue'] ?? 0;
							$where = [];
							$num = $item['numberConfig']['val'] ?? $limitMax;
							$sort = $item['goodsSort']['type'] ?? 0;
							if ($sort == 1) {
								$where['salesOrder'] = 'desc';
							} elseif ($sort == 2) {
								$where['priceOrder'] = 'desc';
							}
							$item['goodsList']['list'] = [];
                            $where['type'] = [0, 2];
							$where['is_show'] = 1;
							$where['is_del'] = 0;
							$where['is_verify'] = 1;
							switch ($typeConfig){
								case 1://指定商品
									$where['ids'] = $item['goodsList']['ids'] ?? [];
									$item['goodsList']['list'] = $services->getSearchList($where, 0, 0, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									break;
								case 2://指定品牌
									$where['brand_id'] = $item['brandList']['brandVal'] ?? [];
									$item['productList']['list'] = $services->getSearchList($where, 0, $num, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									break;
								case 3://指定分类
									$cateIds = $item['classList']['classVal'] ?? [];
									if ($cateIds) {
//										$ids = $storeCategoryServices->getColumn(['pid' => $cateIds], 'id');
//										if ($ids) {
//											$cateIds = array_unique(array_merge($cateIds, $ids));
											$where['cate_id'] = $cateIds;
//										}
									}
									$item['productList']['list'] = $services->getSearchList($where, 0, $num, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									break;
								case 4://商品标签
									$storeLabelIds = $item['goodsLabel']['activeValue'] ?? [];
									if ($storeLabelIds) {
										$where['store_label_id'] = $storeLabelIds;
									}
									$item['productList']['list'] = $services->getSearchList($where, 0, $num, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									break;
							}
							break;
						case 'articleList'://文章
							$data = [];
							if ($item['selectConfig']['activeValue'] ?? 0) {
								$data = $articleServices->getList(['cid' => $item['selectConfig']['activeValue'] ?? 0], 0, $item['numConfig']['val'] ?? 0);
							}
							$item['selectList']['list'] = $data['list'] ?? [];
							break;
						case 'promotionList'://促销列表
							if (isset($item['tabConfig']['list']) && $item['tabConfig']['list']) {
								$list = $item['tabConfig']['list'];
								foreach ($list as &$tabValue) {
									$where = [];
									$where['type'] = [0, 2];
									$where['is_show'] = 1;
									$where['is_del'] = 0;
									$where['is_verify'] = 1;
									//选择方式
									$selectValue = $tabValue['tabVal'] ?? 0;
									$num = $tabValue['numConfig']['val'] ?? $limitMax;
									$sort = $tabValue['goodsSort'] ?? 0;
									if ($sort == 1) {
										$where['salesOrder'] = 'desc';
									} elseif ($sort == 2) {
										$where['priceOrder'] = 'desc';
									}
									if ($selectValue == 1 && isset($tabValue['goodsList']['ids']) && count($tabValue['goodsList']['ids'])) {//手动选商品
										$where['ids'] = $tabValue['goodsList']['ids'];
										$tabValue['goodsList']['list'] = $services->getSearchList($where, 0, 0, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									} else {//选分类 、标签
										switch ($selectValue) {
											case 1://指定商品
												$where['ids'] = $item['goodsList']['ids'] ?? [];
												break;
											case 2://指定品牌
												$where['brand_id'] = $item['brandList']['brandVal'] ?? [];
												break;
											case 3://指定分类
												$cateIds = $item['classList']['classVal'] ?? [];
												if ($cateIds) {
													$ids = $storeCategoryServices->getColumn(['pid' => $cateIds], 'id');
													if ($ids) {
														$cateIds = array_unique(array_merge($cateIds, $ids));
														$where['cate_id'] = $cateIds;
													}
												}
												break;
											case 4://商品标签
												$storeLabelIds = $item['goodsLabel']['activeValue'] ?? [];
												if ($storeLabelIds) {
													$where['store_label_id'] = $storeLabelIds;
												}
												break;
										}
										$tabValue['goodsList']['list'] = $tabValue['productList']['list'] = $services->getSearchList($where, 0, $num, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
									}
								}
								$item['tabConfig']['list'] = $list;
							}
							break;
						case 'newVip'://新人专享
							$item['newVipList']['list'] = $newcomerServices->getDiyNewcomerList();
							break;
						case 'shortVideo'://短视频
							$item['videoList'] = $videoServices->getDiyVideoList(0);
							break;
					}
                }
            } else {
                if ($info['value']) {
                    if (isset($info['value']['d_goodList']['goodsList'])) {
                        $info['value']['d_goodList']['goodsList']['list'] = [];
                    }
                    if (isset($info['value']['d_goodList']['goodsList']['ids']) && count($info['value']['d_goodList']['goodsList']['ids'])) {
                        $info['value']['d_goodList']['goodsList']['list'] = $services->getSearchList(['ids' => $info['value']['d_goodList']['goodsList']['ids']]);
                    }
                }
            }
        }
        return $this->success(compact('info'));
    }

    /**
     * 获取uni-app路径
     * @return mixed
     */
    public function getUrl()
    {
        $url = sys_data('uni_app_link');
        if ($url) {
            foreach ($url as &$link) {
                $link['url'] = $link['link'];
                $link['parameter'] = trim($link['param']);
            }
        } else {
            /** @var CacheServices $cache */
            $cache = app()->make(CacheServices::class);
            $url = $cache->getDbCache('uni_app_url', null);
        }
        return $this->success(compact('url'));
    }

	/**
	 * 获取门店列表
	 * @return mixed
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function getSystemStore()
	{
		[$latitude, $longitude, $province, $city, $area, $street, $product_num] = $this->request->getMore([
			['store_type', 1],
			['keyword', ''],
			['latitude', ''],
			['longitude', ''],
			['province', 0],
			['city', 0],
			['area', 0],
			['street', 0],
			['product_num', 4],//商品数量
		], true);
		/** @var SystemStoreServices $systemStoreServices */
		$systemStoreServices = app()->make(SystemStoreServices::class);
		$where = ['province' => $province, 'city' => $city, 'area' => $area, 'street' => $street];
		$list = $systemStoreServices->getHomeStoreList(0, $where, $latitude, $longitude, '', 0, $product_num);
		return $this->success($list);
	}

    /**
     * 获取商品分类
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCategory()
    {
        /** @var StoreProductCategoryServices $categoryService */
        $categoryService = app()->make(StoreProductCategoryServices::class);
        $list = $categoryService->cascaderList();
//        $data = [];
//        foreach ($list as $value) {
//            $data[] = [
//                'id' => $value['id'],
//                'title' => $value['html'] . $value['cate_name']
//            ];
//        }
        return $this->success($list);
    }

    /**
     * 获取商品
     * @return mixed
     */
    public function getProduct()
    {
        $where = $this->request->getMore([
            ['id', 0],//搜索分类
            ['salesOrder', ''],//销量排序
            ['priceOrder', ''],//价格排序
			['supplier_id', 0],//供应商ID
			['store_id', 0],//门店ID
			['store_label_id', ''],//标签ID
			['ids', ''],//商品ID
			['brand_id', ''],//品牌ID
        ]);
		$cateId = $where['id'];
        $where['is_show'] = 1;
        $where['is_del'] = 0;
		$where['is_verify'] = 1;
		$where['type'] = [0, 2];
		$where['show_type'] = [0, 1];
		if ($where['supplier_id']) {
			$where['relation_id'] = $where['supplier_id'];
			$where['type'] = 2;
		} elseif ($where['store_id']) {
			$where['relation_id'] = $where['store_id'];
			$where['type'] = 1;
		} else {
			$where['pid'] = 0;
		}
		unset($where['supplier_id'], $where['store_id']);
        unset($where['id']);
		/** @var StoreProductCategoryServices $storeCategoryServices */
		$storeCategoryServices = app()->make(StoreProductCategoryServices::class);
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
        [$page, $limit] = $this->services->getPageValue();
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $list = $productService->getSearchList($where, $page, $limit, ['id,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price'], '', []);
        return $this->success($list);
    }

    /**
     * 获取门店核销开启状态
     * @return mixed
     */
    public function getStoreStatus()
    {
		$data['store_status'] = 0;
		if (sys_config('store_func_status', 1)) {
			$data['store_status'] = sys_config('store_self_mention', 0);
		}
        return $this->success($data);
    }

    /**
     * 设置模版默认数据
     * @param $id
     * @return mixed
     */
    public function setDefaultData($id)
    {
        if (!$id) return app('json')->fail('参数错误');
        $info = $this->services->get($id);
        if ($info) {
            $info->default_value = $info->value;
            $info->update_time = time();
            $info->save();
            event('diy.update');
            return $this->success('设置成功');
        } else {
			return app('json')->fail('模板不存在');
        }
    }

    /**
     * 还原模板数据
     * @param $id
     * @return mixed
     */
    public function Recovery($id)
    {
        if (!$id) return app('json')->fail('参数错误');
        $info = $this->services->get($id);
        if ($info) {
            $info->value = $info->default_value;
            $info->update_time = time();
            $info->save();
            event('diy.update');
            return $this->success('还原成功');
        } else {
			return app('json')->fail('模板不存在');
        }
    }

    /**
     * 获取二级分类
     * @return mixed
     */
    public function getByCategory()
    {
        $where = $this->request->getMore([
            ['pid', -1],
            ['name', '']
        ]);
        /** @var StoreProductCategoryServices $categoryServices */
        $categoryServices = app()->make(StoreProductCategoryServices::class);
        return $this->success($categoryServices->getALlByIndex($where));
    }

    /**
     * 获取首页推荐不同类型商品的轮播图和商品
     * @param $type
     * @return mixed
     */
    public function groom_list($type)
    {
        $info['list'] = $this->get_groom_list($type);
        return $this->success($info);
    }

    /**
     * 实际获取方法
     * @param $type
     * @return array
     */
    protected function get_groom_list($type, int $num = 0)
    {
        /** @var StoreProductServices $services */
        $services = app()->make(StoreProductServices::class);
        $info = [];
		$where = [];
        if ($type == 1) {// 精品推荐
			$where['is_best'] = 1;
            // 精品推荐个数
        } else if ($type == 2) {//  热门榜单
			$where['is_hot'] = 1;
        } else if ($type == 3) {// 首发新品
			$where['is_new'] = 1;
        } else if ($type == 4) {// 促销单品
			$where['is_benefit'] = 1;
        } else if ($type == 5) {// 会员商品
			$where['is_vip'] = 1;
        }
		$info = $services->getRecommendProduct(0, $where, $num);
        return $info;
    }

    /**
     * 一键换色
     * @param $status
     * @return mixed
     */
    public function colorChange($status, $type)
    {
        if (!$status) return app('json')->fail('参数错误');
        $info = $this->services->get(['template_name' => $type, 'type' => 3]);
        if ($info) {
            $info->value = $status;
            $info->update_time = time();
            $info->save();
            event('diy.update');

			$this->services->cacheStrUpdate('color_change_' . $type . '_3', $status);

            return $this->success('设置成功');
        } else {
			return app('json')->fail('模板不存在');
        }
    }

    /**
     * 获取颜色选择和分类模板选择
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getColorChange($type)
    {
        $status = (int)$this->services->getColorChange((string)$type);
        return $this->success(compact('status'));
    }

    /**
     * 获取单个diy小程序预览二维码
     * @param $id
     * @return mixed
     */
    public function getRoutineCode($id)
    {
        $image = $this->services->getRoutineCode((int)$id);
        return $this->success(compact('image'));
    }

    /**
     * 获取会员中心数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getMember()
    {
        $data = $this->services->getMemberData();
        return $this->success($data);
    }

	/**
	 * 保存个人中心数据
	 * @return mixed
	 */
	public function memberSaveData()
	{
		$data = $this->request->postMore([
			['member', []],
			['routine_my_banner', []],
			['routine_my_menus', []]
		]);
		$this->services->memberSaveData($data);
		event('diy.update');
		return $this->success('保存成功');
	}

    /**
     * 获取开屏广告
     * @return mixed
     */
    public function getOpenAdv()
    {
        /** @var CacheServices $cacheServices */
        $cacheServices = app()->make(CacheServices::class);
        $data = $cacheServices->getDbCache('open_adv','');
        $data = $data ?: [];
		$data['time'] = (float)($data['time'] ?? 3);
		$data['interval_time'] = (float)($data['interval_time'] ?? 24);
        return app('json')->success($data);
    }

    /**
     * 保存开屏广告
     * @return mixed
     */
    public function openAdvAdd()
    {
        $data = $this->request->postMore([
            ['status', 0],//状态
            ['time', 0],//广告时间
			['interval_time', 24],//再次出现间隔时间
            ['type', 'pic'],//类型
            ['value', []],
            ['video_link','']
        ]);
		if (!$data['type']) {
			$data['type'] = 'pic';
		}
        /** @var CacheServices $cacheServices */
        $cacheServices = app()->make(CacheServices::class);
        $cacheServices->setDbCache('open_adv', $data);
        event('diy.update');
        return app('json')->success('保存成功');
    }

	/**
     * 获取商品详情数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductDetailDiy()
    {
        $data = $this->services->getProductDetailDiy();
        return $this->success($data);
    }

	/**
	 * 保存商品详情数据
	 * @return mixed
	 */
	public function saveProductDetailDiy()
	{
		[$content] = $this->request->postMore([
			['product_detail_diy', []],
		], true);
		$this->services->saveProductDetailDiy($content);
		event('diy.update');
		return $this->success('保存成功');
	}

    /**
     * 获取悬浮窗数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSuspendedDiy()
    {
        $data = $this->services->getSuspendedDiy();
        return $this->success($data);
    }

    /**
     * 保存悬浮窗数据
     * @return mixed
     */
    public function saveSuspendedDiy()
    {
        [$content] = $this->request->postMore([
            ['suspended_window_diy', []],
        ], true);
        $this->services->saveSuspendedDiy($content);
        event('diy.update');
        return $this->success('保存成功');
    }

	/**
	 * 获取商品分类数据
	 * @return mixed
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function getProductCategoryDiy()
	{
		$data = $this->services->getProductCategoryDiy();
		return $this->success($data);
	}

	/**
	 * 保存商品分类数据
	 * @return mixed
	 */
	public function saveProductCategoryDiy()
	{
		[$content] = $this->request->postMore([
			['product_category_diy', []],
		], true);
		$this->services->saveProductCategoryDiy($content);
		event('diy.update');
		return $this->success('保存成功');
	}

	/**
 	* 获取新人礼商品
	* @param StoreNewcomerServices $newcomerServices
	* @return mixed
	 */
	public function newcomerList(StoreNewcomerServices $newcomerServices)
	{
		$where = $this->request->getMore([
            ['priceOrder', ''],
            ['salesOrder', ''],
        ]);
		return app('json')->success($newcomerServices->getDiyNewcomerList(0, $where));
	}
}
