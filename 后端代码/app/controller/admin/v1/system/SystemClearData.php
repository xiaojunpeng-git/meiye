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
namespace app\controller\admin\v1\system;

use app\services\other\HotDataServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\product\product\StoreProductRelationServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserRelationServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\services\CacheService;
use think\exception\ValidateException;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\system\SystemClearServices;
use app\services\product\product\StoreProductServices;
use app\services\system\attachment\SystemAttachmentServices;


/**
 * 清除默认数据理控制器
 * Class SystemClearData
 * @package app\controller\admin\v1\system
 */
class SystemClearData extends AuthController
{
    /**
     * 构造方法
     * SystemClearData constructor.
     * @param App $app
     * @param SystemClearServices $services
     */
    public function __construct(App $app, SystemClearServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
        //生产模式下不允许清除数据
        if (!env('APP_DEBUG', false)) {
            throw new ValidateException('生产模式下，禁止操作');
        }
    }

    /**
     * 清除方法入口
     * @param $type
     * @return mixed
     */
    public function index($type)
    {
        switch ($type) {
            case 'temp':
                return $this->userTemp();
                break;
            case 'recycle':
                return $this->recycleProduct();
                break;
            case 'store':
                return $this->storeData();
                break;
            case 'order':
                return $this->orderData();
                break;
            case 'kefu':
                return $this->kefuData();
                break;
            case 'wechat':
                return $this->wechatData();
                break;
            case 'attachment':
                return $this->attachmentData();
                break;
            case 'article':
                return $this->articledata();
                break;
            case 'system':
                return $this->systemdata();
                break;
            case 'user':
                return $this->userRelevantData();
                break;
            default:
                return $this->fail('参数有误');
        }
    }

    /**
     * 清除用户生成的临时文件
     * @return mixed
     */
    public function userTemp()
    {
        /** @var SystemAttachmentServices $services */
        $services = app()->make(SystemAttachmentServices::class);
        $ids = implode(',', $services->getColumn(['module_type' => 2], 'att_id'));
        $services->del($ids);
        $services->delete(2, 'module_type');
        return $this->success('清除数据成功!');
    }

    /**
     * 清除回收站商品
     * @return mixed
     */
    public function recycleProduct()
    {
        /** @var StoreProductServices $product */
        $product = app()->make(StoreProductServices::class);
        $ids = $product->getColumn(['is_del' => 1], 'id');
		if ($ids) {//回收站商品
			foreach ($ids as $id) {
				try {
					$product->delProduct((int)$id);
				} catch (\Throwable $e) {
				}
			}
		}
        return $this->success('清除数据成功!');
    }

    /**
     * 清除用户数据
     * @return mixed
     */
    public function userRelevantData()
    {
        $this->services->clearData([
            'store_user', 'user', 'user_address', 'user_belong_store', 'user_bill', 'user_brokerage', 'user_brokerage_frozen', 'user_card',
			'user_collage_code', 'user_collage_code_partake', 'user_enter', 'user_extract', 'user_friends', 'user_group', 'user_invoice',
			'user_label', 'user_label_relation', 'user_level', 'user_money', 'user_notice', 'user_notice_see', 'user_recharge', 'user_relation',
			'user_search', 'user_sign', 'user_spread', 'user_visit', 'user_visit_store', 'wechat_user',
			'wechat_message', 'store_visit', 'store_service_record',
            'store_service_speechcraft', 'store_service_record', 'store_service_log', 'store_service_feedback', 'store_service',
            'store_product_reply', 'store_product_log', 'store_pink', 'store_order', 'store_order_cart_info', 'store_order_economize',
			'store_order_invoice', 'store_order_promotions', 'store_order_refund', 'store_order_status', 'store_order_writeoff', 'store_coupon_user', 'store_coupon_issue_user', 'store_cart',
            'store_bargain_user_help', 'store_bargain_user', 'sms_record', 'qrcode', 'other_order_status', 'other_order', 'member_card',
            'member_card_batch', 'delivery_service', 'queue_auxiliary', 'queue_list', 'luck_lottery_record', 'agent_level_task_record',
            'system_message'
        ], true);
		/** @var SystemStoreStaffServices $storeStaffServices */
		$storeStaffServices = app()->make(SystemStoreStaffServices::class);
		$storeStaffServices->update([['uid', '>', 0]],['uid' => 0]);
        $this->services->delDirAndFile('./public/uploads/store/comment');
        return $this->success('清除数据成功!');
    }

    /**
     * 清除商城数据
     * @return mixed
     */
    public function storeData()
    {
        $this->services->clearData([
			'store_user', 'user', 'user_address', 'user_belong_store', 'user_bill', 'user_brokerage', 'user_brokerage_frozen', 'user_card',
			'user_collage_code', 'user_collage_code_partake', 'user_enter', 'user_extract', 'user_friends', 'user_group', 'user_invoice',
			'user_label', 'user_label_relation', 'user_level', 'user_money', 'user_notice', 'user_notice_see', 'user_recharge', 'user_relation',
			'user_search', 'user_sign', 'user_spread', 'user_visit', 'user_visit_store', 'wechat_user',
			'store_coupon_product', 'store_discounts_products',
			'store_product', 'store_product_attr', 'store_product_attr_result', 'store_product_attr_value', 'store_product_cate',
			'store_product_category', 'store_product_category_brand', 'store_product_coupon', 'store_product_description', 'store_product_ensure',
			'store_product_label', 'store_product_label_auxiliary', 'store_product_log', 'store_product_relation', 'store_product_reply', 'store_product_reply_comment',
			'store_product_rule', 'store_product_specs', 'store_product_stock_record', 'store_product_unit', 'store_product_virtual', 'store_product_words',
			'live_anchor', 'live_goods', 'live_room', 'live_room_goods', 'store_bargain', 'store_bargain_user', 'store_bargain_user_help', 'store_cart',
			'store_activity', 'store_newcomer', 'store_combination', 'store_coupon_issue', 'store_coupon_issue_user', 'store_coupon_user', 'store_seckill', 'store_visit', 'store_integral',
			'queue_list', 'queue_auxiliary', 'other_order', 'other_order_status', 'store_delivery_order',
			'store_order', 'store_order_cart_info', 'store_order_economize', 'store_order_invoice', 'store_order_refund', 'store_order_promotions',
			'store_order_status', 'store_pink', 'store_order_writeoff', 'store_reservation_order'
        ], true);
        return $this->success('清除数据成功!');
    }

    /**
     * 清除订单数据
     * @return mixed
     */
    public function orderData()
    {
        $this->services->clearData([
            'queue_list', 'queue_auxiliary', 'other_order', 'other_order_status', 'store_delivery_order',
			'store_order', 'store_order_cart_info', 'store_order_economize', 'store_order_invoice', 'store_order_refund', 'store_order_promotions',
            'store_order_status', 'store_pink', 'store_order_writeoff', 'store_reservation_order'
        ], true);
        return $this->success('清除数据成功!');
    }

    /**
     * 清除客服数据
     * @return mixed
     */
    public function kefuData()
    {
        $this->services->clearData([
            'queue_auxiliary', 'store_service', 'store_service_feedback', 'store_service_log', 'store_service_record', 'store_service_speechcraft'
        ], true);
        $this->services->delDirAndFile('./public/uploads/store/service');
        return $this->success('清除数据成功!');
    }

    /**
     * 清除微信管理数据
     * @return mixed
     */
    public function wechatData()
    {
        $this->services->clearData([
            'wechat_key', 'wechat_media', 'wechat_message', 'wechat_news_category', 'wechat_reply'
        ], true);
        $this->services->delDirAndFile('./public/uploads/wechat');
        return $this->success('清除数据成功!');
    }

    /**
     * 清除所有附件
     * @return mixed
     */
    public function attachmentData()
    {
        $this->services->clearData([
            'system_attachment', 'system_attachment_category'
        ], true);
        $this->services->delDirAndFile('./public/uploads/');
        return $this->success('清除上传文件成功!');
    }

    /**
     * 清楚内容数据
     * @return mixed
     */
    public function articledata()
    {
        $this->services->clearData([
            'article_category', 'article', 'article_content'
        ], true);
        return $this->success('清除数据成功!');
    }

    /**
     * 清楚系统记录
     * @return mixed
     */
    public function systemdata()
    {
        $this->services->clearData([
            'system_notice_admin', 'system_log'
        ], true);
        return $this->success('清除数据成功!');
    }

    /**
     * 替换域名方法
     * @return mixed
     */
    public function replaceSiteUrl()
    {
        [$url] = $this->request->postMore([
            ['url', '']
        ], true);
        if (!$url)
            return $this->fail('请输入需要更换的域名');
        if (!verify_domain($url))
            return $this->fail('域名不合法');
        $this->services->replaceSiteUrl($url);
        return $this->success('替换成功！');
    }

    /**
     * 预热营销商品库存
     * @param HotDataServices $hotDataServices
     * @return mixed
     */
    public function hotProductStock(HotDataServices $hotDataServices)
    {
        $key = 'hot_product_stock';
        $re = CacheService::get($key);
        if (!$re) {
            $f = [1, 2, 3, 4, 5];
            try {
                foreach ($f as $type) {
                    $hotDataServices->hot($type);
                }
            } catch (\Throwable $e) {

            }
            CacheService::set($key, time(), 86400);
        }
        return $this->success('预热成功！');
    }
}
