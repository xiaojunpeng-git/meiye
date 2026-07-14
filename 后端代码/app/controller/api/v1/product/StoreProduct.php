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
namespace app\controller\api\v1\product;

use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\diy\DiyServices;
use app\services\other\QrcodeServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\user\UserServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * 商品类
 * Class StoreProduct
 * @package app\controller\api\v1\product
 */
class StoreProduct
{
    /**
     * 商品services
     * @var StoreProductServices
     */
    protected $services;

    public function __construct(StoreProductServices $services)
    {
        $this->services = $services;
    }

    /**
     * 商品列表
     * @param Request $request
     * @return mixed
     */
    public function lst(Request $request, StoreProductCategoryServices $services)
    {
        $where = $request->getMore([
            [['sid', 'd'], 0],//二级分类ID
            [['cid', 'd'], 0],//一级分类ID
			[['tid', 'd'], 0],//三级分类ID
            ['keyword', '', '', 'store_name'],
            ['priceOrder', ''],
            ['salesOrder', ''],
            [['news', 'd'], 0, '', 'is_new'],
            [['type', ''], '', '', 'status'],
            ['ids', ''],
            [['selectId', 'd'], ''],
			['cate_id', ''],
            ['productId', ''],
			['brand_id', ''],
            ['promotions_id', 0],
            [['collate_code_id', 'd'], 0], //拼单ID 、桌码ID
			['store_label_id', ''],//商品标签
			['coupon_id', ''],//优惠券id
			['coupon_issue_type', ''],//优惠券来源
        ]);
        if ($where['selectId'] && (!$where['sid'] || !$where['cid'])) {
            if ($services->value(['id' => $where['selectId']], 'pid')) {
                $where['sid'] = $where['selectId'];
            } else {
                $where['cid'] = $where['selectId'];
            }
        }
		$where['ids'] = stringToIntArray($where['ids']);
		$where['store_label_id'] = stringToIntArray($where['store_label_id']);
		$where['brand_id'] = stringToIntArray($where['brand_id']);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? stringToIntArray($cateId) : $cateId;
			$cateId = array_merge($cateId, $services->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;

        $type = 'mid';
        $field = ['image', 'recommend_image'];
        if ($where['store_name'] || $where['productId']) {//搜索
            $field = ['image'];
        }
		$where['show_type'] = [0, 1];
        $list = $this->services->getGoodsList($where, (int)$request->uid());
        return app('json')->successful(get_thumb_water($list, $type, $field));
    }

    /**
     * 搜索获取商品品牌列表
     * @param Request $request
     * @param StoreProductCategoryServices $services
     * @return mixed
     */
    public function brand(Request $request, StoreProductCategoryServices $services)
    {
        $where = $request->getMore([
            [['sid', 'd'], 0],
            [['cid', 'd'], 0],
            ['selectId', '']
        ]);
        if ($where['selectId'] && (!$where['sid'] || !$where['cid'])) {
            if ($services->value(['id' => $where['selectId']], 'pid')) {
                $where['sid'] = $where['selectId'];
            } else {
                $where['cid'] = $where['selectId'];
            }
        }
		$cate_id = [];
		if ($where['sid']) {
			$cate_id = [$where['sid']];
		} elseif ($where['cid']) {
			$cate_id = array_merge([$where['cid']], $services->getColumn(['pid' => $where['cid'], 'is_show' => 1], 'id'));
		}
        return app('json')->successful($this->services->getBrandList(['cate_id' => $cate_id]));
    }

	/**
	 * 商品搜索页面参数：活动、品牌、标签
	 * @param Request $request
	 * @param StoreProductCategoryServices $services
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function searchFilter(Request $request, StoreProductCategoryServices $services)
	{
		$where = $request->getMore([
			[['sid', 'd'], 0],
			[['cid', 'd'], 0],
			['keyword', '', '', 'store_name'],
			[['news', 'd'], 0, '', 'is_new'],
			[['type', ''], '', '', 'status'],
			['ids', ''],
			['selectId', ''],
			['productId', ''],
			['promotions_id', 0],
			['promotions_type', 0],
		]);
		if ($where['selectId'] && (!$where['sid'] || !$where['cid'])) {
			$level = $services->value(['id' => (int)$where['selectId']], 'level') ?? 0;
			$levelArr = $services->cateField;
			$where[$levelArr[$level] ?? 'cid'] = $where['selectId'];
		}
		if ($where['ids'] && is_string($where['ids'])) {
			$where['ids'] = stringToIntArray($where['ids']);
		}
		if (!$where['ids']) {
			unset($where['ids']);
		}
		return app('json')->successful($this->services->searchFilter((int)$request->uid(), $where));
	}

    /**
     * 商品分享二维码 推广员
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function code(Request $request, $id)
    {
        $id = (int)$id;
        /** @var SystemAttachmentServices $attach */
        $attach = app()->make(SystemAttachmentServices::class);
        if (!$id || !$this->services->isValidProduct($id)) {
            return app('json')->fail('商品不存在或已下架');
        }
        $userType = $request->get('user_type', 'wechat');
        $user = $request->user();
		$uid = (int)$user['uid'];
        try {
            switch ($userType) {
                case 'wechat':
                    //公众号
                    $name = $id . '_product_detail_' . $uid . '_is_promoter_' . $user['is_promoter'] . '_wap.jpg';

                    /** @var QrcodeServices $qrcodeService */
                    $qrcodeService = app()->make(QrcodeServices::class);
                    if (sys_config('share_qrcode', 0) && request()->isWechat()) {
                        $url = $qrcodeService->getTemporaryQrcode('product-' . $id . '-'. $uid, $uid)->url;
                    } else {
                        $url = $qrcodeService->getWechatQrcodePath($name, '/pages/goods_details/index?id=' . $id . '&spread=' . $uid . '&spid=' . $uid);
                    }

                    if ($url === false)
                        return app('json')->fail('二维码生成失败');
                    else {
//                        $codeTmp = $code = $url ? image_to_base64($url) : false;
//                        if (!$codeTmp) {
//                            $putCodeUrl = put_image($url);
//                            $code = $putCodeUrl ? image_to_base64(app()->request->domain(true) . '/' . $putCodeUrl) : false;
//                            $code ?? unlink(public_path() . $putCodeUrl);
//                        }
                        return app('json')->successful(['code' => $url]);
                    }
                    break;
                case 'routine':
					$name = $id . '_' . $user['uid'] . '_' . $user['is_promoter'] . '_product.jpg';
					/** @var QrcodeServices $qrcodeService */
					$qrcodeService = app()->make(QrcodeServices::class);
					$url = $qrcodeService->getRoutineQrcodePath($id, (int)$user['uid'], 0, $name);
                    if ($url === false)
                        return app('json')->fail('二维码生成失败');
                    else
                        return app('json')->successful(['code' => $url]);
            }
        } catch (\Exception $e) {
            return app('json')->fail($e->getMessage(), [
                'code' => $e->getCode(),
                'line' => $e->getLine(),
                'message' => $e->getMessage()
            ]);
        }
    }

	/**
	 * 获取商品的详情内容
	 * @param $id
	 * @return mixed
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/28
	 */
	public function detailContent($id)
	{
		$productInfo = $this->services->getCacheProductInfo($id);
		return app('json')->success(['description' => $productInfo['description'] ?? '']);
	}

	/**
	 * 商品详情
	 * @param Request $request
	 * @param $id
	 * @param $type
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 * @throws \Throwable
	 */
    public function detail(Request $request, $id, $type = 0)
    {
		if (!$id || !is_numeric($id)) {
			return app('json')->fail('参数错误');
		}
		$uid = $request->hasMacro('uid')? (int)$request->uid() : 0;
        $data = $this->services->productDetail($uid, (int)$id, (int)$type);
        return app('json')->successful($data);
    }

	/**
	* 推荐商品列表
	* @param Request $request
	* @param DiyServices $diyServices
	* @param $id
	* @return mixed
	* @throws DataNotFoundException
	* @throws DbException
	* @throws ModelNotFoundException
	 */
    public function recommend(Request $request, DiyServices $diyServices, $id)
    {
		$list = [];
		$diy = $diyServices->getProductDetailDiy();
		//推荐开启
		if (isset($diy['showRecommend']) && $diy['showRecommend']) {
			$num = (int)($diy['recommendNum'] ?? 12);
			$storeInfo = [];
			if ($id) {
				$storeInfo = $this->services->getCacheProductInfo($id);
			}
			$uid = $request->uid();
			$where = [];
			$where['is_vip_product'] = 0;
			$where['is_verify'] = 1;
			$where['pid'] = 0;
			if ($uid) {
				/** @var UserServices $userServices */
				$userServices = app()->make(UserServices::class);
				$is_vip = $userServices->value(['uid' => $uid], 'is_money_level');
				$where['is_vip_product'] = $is_vip ? -1 : 0;
			}
			//推荐
			if (isset($storeInfo['recommend_list']) && $storeInfo['recommend_list']) {
				$recommend_list = explode(',', $storeInfo['recommend_list']);
				$list = get_thumb_water($this->services->getProducts(['ids' => $recommend_list, 'is_del' => 0, 'is_show' => 1] + $where, '', $num, ['couponId']));
			} else {
				$list = get_thumb_water($this->services->getProducts(['store_label_id' => 5, 'is_del' => 0, 'is_show' => 1] + $where, 'rand()', $num, ['couponId']));
			}
		}
        return app('json')->success($list);
    }

	/**
 	* 获取商品关联活动信息
	* @param Request $request
	* @param StoreCouponIssueServices $couponIssueServices
	* @param StoreDiscountsServices $storeDiscountsServices
	* @param StorePromotionsServices $storePromotionsServices
	* @param DiyServices $diyServices
	* @param $id
	* @return mixed
	* @throws DataNotFoundException
	* @throws DbException
	* @throws ModelNotFoundException
	 */
	public function activity(Request $request, StoreCouponIssueServices $couponIssueServices, StoreDiscountsServices $storeDiscountsServices, StorePromotionsServices $storePromotionsServices, DiyServices $diyServices, $id)
	{
		[$promotions_type, $unique, $store_id] = $request->getMore([
            [['promotions_type', 'd'], 0],
			['unique', ''],//商品sku惟一值
			['store_id', 0],//选择门店ID
        ], true);
		$storeInfo = [];
		$id = (int)$id;
		if ($id) {
			$storeInfo = $this->services->getCacheProductInfo($id);
		}
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$data = ['activity' => [], 'coupons' => [], 'discounts_products' => [], 'promotions' => [], 'activity_background' => [], 'computed' => ['deduction' => []]];
		//预售不参与
		if (!$storeInfo || $storeInfo['is_presale_product']) {
			return app('json')->success($data);
		}
		$diy = $diyServices->getProductDetailDiy();
		if (isset($diy['showService']) && in_array(0, $diy['showService']) && !($storeInfo['type'] == 1 && !$storeInfo['pid']))
			$data['activity'] = $this->services->getActivityList($storeInfo, false);
		$coupons = $couponIssueServices->getProductCouponList($uid, $id, 'id,type,category,coupon_type,is_claimed,quantity_count,top_discount_price,coupon_title,coupon_price,use_min_price,start_time,end_time,applicable_type,applicable_store_id,coupon_issue_type,relation_id',  20);
		$couponsReceive = $couponIssueServices->getIssueCouponListReceive($uid, $id, 'id,type,category,coupon_type,is_claimed,quantity_count,top_discount_price,coupon_title,coupon_price,use_min_price,start_time,end_time,applicable_type,applicable_store_id,coupon_issue_type,relation_id', 20);
        $coupon = array_merge($coupons,$couponsReceive);
        $idArr = array_column($coupon, 'id');
        array_multisort($idArr, SORT_DESC, $coupon);
        $data['coupons'] = [];
        foreach ($coupon as $key => $item) {
            if ($item['use_status'] == 1) {
                $data['coupons'][]=$item;
            }
        }
        $data['coupons'] = array_slice($data['coupons'],0,20);
        if (isset($diy['showMatch']) && $diy['showMatch']) {
			$num = (int)($diy['matchNum'] ?? 2);
			$discounts_products = $storeDiscountsServices->getDiscounts($id, $uid, $num, 'id,type,title,image,is_limit,limit_num,product_ids');
			if ($discounts_products) {
				foreach ($discounts_products as &$item) {
					$products = [];
					if (isset($item['products']) && $item['products']) {
						foreach ($item['products'] as $product) {
							$products[] = ['id' => $product['id'] ?? '', 'store_name' => $product['store_name'] ?? $product['title'] ?? '', 'image' => $product['image']];
						}
					}
					$item['products'] = $products;
				}
				unset($item);
			}
			$data['discounts_products'] = $discounts_products;
		}
		if (isset($diy['showService']) && in_array(0, $diy['showService']) && !($storeInfo['type'] == 1 && !$storeInfo['pid'])) {
			$promotions_type = $promotions_type ? [(int)$promotions_type] : [1, 2, 3, 4, 6];
			$ids = $storeInfo['pid'] ? [$storeInfo['pid']] : [$id];
			if ($storeInfo['type'] == 1) {
				$store_id = (int)($storeInfo['relation_id'] ?? 0);
			}
            [$promotions, $productRelation] = $storePromotionsServices->getProductsPromotions($ids, $promotions_type, '*', ['giveProducts' => function ($query) {
                $query->field('promotions_id,product_id,limit_num,surplus_num')->with(['productInfo' => function ($query) {
                    $query->field('id,store_name,image');
                }]);
            }, 'giveCoupon' => function ($query) {
                $query->field('promotions_id,coupon_id,limit_num,surplus_num')->with(['coupon' => function ($query) {
                    $query->field('id,type,coupon_type,is_claimed,quantity_count,top_discount_price,coupon_title,coupon_price,use_min_price');
                }]);
            }, 'promotions' => function ($query) {
                $query->field('id,pid,promotions_type,promotions_cate,threshold_type,threshold,discount_type,n_piece_n_discount,discount,give_integral,give_coupon_id,give_product_id,give_product_unique')->with(['giveProducts' => function ($query) {
                    $query->field('promotions_id, product_id,limit_num,surplus_num')->with(['productInfo' => function ($query) {
                        $query->field('id,store_name');
                    }]);
                }, 'giveCoupon' => function ($query) {
                    $query->field('promotions_id, coupon_id,limit_num,surplus_num')->with(['coupon' => function ($query) {
                        $query->field('id,type,coupon_type,coupon_title,coupon_price,use_min_price');
                    }]);
                }]);
            }], 'promotions_type', (int)$store_id);
            if ($promotions) {
				foreach ($promotions as $key => $item) {
					if ($item['promotions_type'] == 6) {
						$data['activity_background'] = ['id' => $item['id'], 'name' => $item['name'], 'image' => $item['image']];
					} else {
						$data['promotions'][] = [
							'id' => $item['id'],
							'type' => $item['type'],
							'title' => $item['title'],
							'name' => $item['name'],
                            'give_integral' => $item['give_integral'],
							'promotions_type' => $item['promotions_type'],
							'threshold_type' => $item['threshold_type'],
							'threshold' => $item['threshold'],
							'discount_type' => $item['discount_type'],
							'discount' => $item['discount'],
							'desc' => $item['desc'],
							'start_time' => $item['start_time'] ?  date('Y-m-d', $item['start_time']) : '',
							'stop_time' => $item['stop_time'] ?  date('Y-m-d', $item['stop_time']) : '',
                            'giveProducts' => $item['giveProducts'] ?? [],
                            'giveCoupon' => $item['giveCoupon'] ?? []
						];
					}
				}
			}
		}
		$computed = $this->services->computedProductPayPrice($uid, $id, (string)$unique, (int)$store_id);
		$data['computed'] = $computed;
		return app('json')->success($data);
	}

    /**
     * 为你推荐
     * @param Request $request
     * @param UserServices $userServices
     * @return mixed
     */
    public function product_hot(Request $request, UserServices $userServices)
    {
		$uid = (int)$request->uid();
        $vip_user = $userServices->checkUserIsSvip($uid) ? -1 : 0;
		$where = ['store_label_id' => 1, 'is_show' => 1, 'is_del' => 0, 'is_verify' => 1, 'is_vip_product' => $vip_user];
		$where = array_merge($where, $this->services->getWhereByEntryRules($uid));
        $list = $this->services->getProducts($where, '', 0, ['couponId']);
        return app('json')->success(get_thumb_water($list, 'mid'));
    }

	/**
	 * 获取首页推荐不同类型商品的轮播图和商品
	 * @param Request $request
	 * @param $type
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 * @throws \throwable
	 */
    public function groom_list(Request $request, $type)
    {
        $info['banner'] = [];
        $info['list'] = [];
		$uid = (int)$request->uid();
		$where = [];
        if ($type == 1) {// 精品推荐
            $info['banner'] = sys_data('routine_home_bast_banner') ?: [];// 首页精品推荐图片
			$where['is_best'] = 1;
        } else if ($type == 2) {//  热门榜单
            $info['banner'] = sys_data('routine_home_hot_banner') ?: [];// 热门榜单 猜你喜欢推荐图片
			$where['is_hot'] = 1;
        } else if ($type == 3) {// 首发新品
            $info['banner'] = sys_data('routine_home_new_banner') ?: [];// 首发新品推荐图片
			$where['is_new'] = 1;
        } else if ($type == 4) {// 促销单品
            $info['banner'] = sys_data('routine_home_benefit_banner') ?: [];// 促销单品推荐图片
			$where['is_benefit'] = 1;
        } else if ($type == 5) {// 会员商品
            $where['is_vip'] = 1;
        }
		$where = array_merge($where, $this->services->getWhereByEntryRules($uid));
		$info['list'] = $this->services->getRecommendProduct($uid, $where);
        return app('json')->successful($info);
    }


	/**
	 * 获取预售列表
	 * @param SystemStoreServices $storeServices
	 * @param Request $request
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
    public function presaleList(SystemStoreServices $storeServices, Request $request)
    {
		$where = $request->getMore([
			[['time_type', 'd'], 0],
			['limit', 0],
			['is_list', 0],//预售列表 1:是 0:否（首页diy获取）
		]);
		$where['type'] = [0, 2];
		$limit = 0;
		if (!$where['is_list']) {//首页diy获取
			$limit = (int)$where['limit'];
		}
		unset($where['limit'], $where['is_list']);
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$entryRules = $storeServices->getStoreIdByEntryRules($uid);
		$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
		if ($shop_operation_type == 3) {//单店模式不返回
			$storeId = $entryRules['store_id'] ?? 0;
			if ($storeId) {
				$where['applicable_store_id'] = $storeId;
				$list = $this->services->getPresaleList($uid, $where, $limit);
			} else {
				$list = [];
			}
		} else {
			$list = $this->services->getPresaleList($uid, $where, $limit);
		}
        return app('json')->successful($list);
    }

	/**
	 * 搜索页面推荐排序商品
	 * @param Request $request
	 * @param $type
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 * @throws \throwable
	 */
	public function searchRecommendList(Request $request, $type)
	{
		$uid = 0;
		if ($request->hasMacro('uid')) $uid = $request->uid();
		switch((int)$type) {
			case 1: //销量
				$order = 'sales desc, sort desc, id desc';
				break;
			case 2: //评分
				$order =  'star desc, sort desc, id desc';
				break;
			case 3: //收藏
				$order =  'collect desc, sort desc, id desc';
				break;
			default :
				$order = 'sales desc, sort desc, id desc';
				break;
		};
		$where = ['show_type' => [0, 1]];
		$where = array_merge($where, $this->services->getWhereByEntryRules($uid));
		return app('json')->successful($this->services->getRecommendProduct($uid, $where, 8, 'small', $order));
	}

}
