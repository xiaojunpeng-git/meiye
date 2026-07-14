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
namespace app\controller\store\marketing\coupon;

use app\controller\store\AuthController;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponProductServices;
use app\services\product\brand\StoreBrandServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use think\facade\App;

/**
 * 已发布优惠券管理
 * Class StoreCouponIssue
 * @package app\controller\store\marketing
 */
class StoreCouponIssue extends AuthController
{
    public function __construct(App $app, StoreCouponIssueServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表头部
     * @return mixed
     */
    public function type_header()
    {
        $where = $this->request->getMore([
            ['coupon_type', ''], //1:满减券2:折扣券
            ['coupon_issue_type', ''], //来源：0:平台1:门店2:供应商
            ['type', ''], //优惠券类型 0-通用 1-品类券 2-商品券 3-品牌
            ['category', ''], //优惠券种类：1 普通券，2会员券
            ['receive_type', ''], //1 手动领取，3赠送券
            ['status', 1],//是否有效
            ['is_status', 1],//状态
            ['coupon_title', ''], //优惠券名称
            ['receive', ''],
        ]);
        $where['relation_id'] = $this->storeId;
        $list = $this->services->getHeader($this->storeId, $where);
        return $this->success(compact('list'));
    }

    /**
     * 获取优惠券列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['coupon_type', ''], //1:满减券2:折扣券
            ['coupon_issue_type', ''], //来源：0:平台1:门店2:供应商
            ['type', ''], //优惠券类型 0-通用 1-品类券 2-商品券 3-品牌
            ['category', ''], //优惠券种类：1 普通券，2会员券
            ['receive_type', ''], //1 手动领取，3赠送券
            ['status', 1],//是否有效
            ['is_status', 1],//状态
            ['coupon_title', ''], //优惠券名称
            ['receive', ''],
        ]);
        $where['relation_id'] = $this->storeId;
        $list = $this->services->getCouponIssueList($where);
        return $this->success($list);
    }

    /**
     * 添加优惠券
     * @return mixed
     */
    public function saveCoupon($id)
    {
        $data = $this->request->postMore([
            ['coupon_title', ''],
            ['coupon_price', 0.00],
            ['top_discount_price', 0.00],//折扣券最高优惠金额
            ['use_min_price', 0.00],
            ['coupon_time', 0],
            ['start_use_time', 0],
            ['end_use_time', 0],
            ['start_time', 0],
            ['end_time', 0],
            ['receive_type', 0],
            ['is_permanent', 0],
            ['total_count', 0],
            ['is_claimed', 0],//是否领取无限张数
            ['quantity_count', 1],//每个用户可领取的数量
            ['product_id', ''],
            ['category_id', []],
            ['brand_id', []],
            ['type', 0],
            ['sort', 0],
            ['status', 0],
            ['coupon_type', 1],
            ['category', 1], // 优惠券种类：1 普通券，2会员券
            ['rule', ''],
        ]);
        $data['relation_id'] = $this->storeId;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $coupon_verify = $storeServices->get($this->storeId, ['coupon_verify_status', 'coupon_self_built_status']);
        if (!$coupon_verify['coupon_self_built_status']) return $this->fail('您没有自建优惠券权限!');
        $data['is_verify'] = $coupon_verify['coupon_verify_status'] ? 1 : 0;
        if ($id && $data['is_verify']) {
            $is_verify = $this->services->value(['id' => $id], 'is_verify');
            if ($is_verify == -2) $data['is_verify'] = 0;
        }
        if ($data['is_verify']) {
            $data['verify_time'] = time();
        }
        if (!$data['is_permanent'] && $data['is_claimed']) {
            $data['is_claimed'] = 0;
            $data['quantity_count'] = $data['total_count'];
        }
        if (!$data['is_permanent'] && !$data['is_claimed'] && $data['quantity_count'] > $data['total_count']) {
            $data['quantity_count'] = $data['total_count'];
        }
        if ($data['category_id']) {
            $data['category_id'] = end($data['category_id']);
        }
        if ($data['brand_id']) {
            $data['brand_com'] = $data['brand_id'] ? implode(',', $data['brand_id']) : '';
            $data['brand_id'] = end($data['brand_id']);
        }
        if ($data['start_time'] && $data['start_use_time']) {
            if ($data['start_use_time'] < $data['start_time']) {
                return $this->fail('使用开始时间不能小于领取开始时间');
            }
        }
        if ($data['end_time'] && $data['end_use_time']) {
            if ($data['end_use_time'] < $data['end_time']) {
                return $this->fail('使用结束时间不能小于领取结束时间');
            }
        }
        //复制优惠券，清空其他数据
        switch ($data['type']) {
            case 0://通用
                $data['product_id'] = '';
                $data['category_id'] = 0;
                $data['brand_id'] = 0;
                break;
            case 1://分类
                $data['product_id'] = '';
                $data['brand_id'] = 0;
                break;
            case 2://商品
                $data['category_id'] = 0;
                $data['brand_id'] = 0;
                break;
            case 3://品牌
                $data['product_id'] = '';
                $data['category_id'] = 0;
                break;
        }
        //新人券不限量
        if ($data['receive_type'] == 2) {
            $data['is_permanent'] = 1;
            $data['total_count'] = 0;
        }
        if (!$data['coupon_price']) {
            return $this->fail($data['coupon_type'] == 1 ? '请输入优惠券金额' : '请输入优惠券折扣');
        }
        if ($data['coupon_type'] == 2 && ($data['coupon_price'] < 0 || $data['coupon_price'] > 100)) {
            return $this->fail('优惠券折扣为0～100数字');
        }
        $id = $id ?: 0;
        $res = $this->services->saveCoupon($data, $id, 1);
        if ($res) return $this->success($id ? '编辑成功' : '添加成功!');
    }

    /**
     * 修改优惠券状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function status($id, $status)
    {
        $this->services->update($id, ['status' => $status]);
        return $this->success('修改成功');
    }

    /**
     * 复制优惠券获取优惠券详情
     * @param int $id
     * @return mixed
     */
    public function copy($id = 0)
    {
        if (!$id) return $this->fail('参数错误');
        $info = $this->services->get($id);
        if ($info) $info = $info->toArray();
        if ($info['product_id'] != '') {
            $productIds = explode(',', $info['product_id']);
            /** @var StoreProductServices $product */
            $product = app()->make(StoreProductServices::class);
            $productImages = $product->getColumn([['id', 'in', $productIds]], 'image', 'id');
            foreach ($productIds as $item) {
                $info['productInfo'][] = [
                    'product_id' => $item,
                    'image' => $productImages[$item]
                ];
            }
        }
        if ($info['category_id'] != '') {
            /** @var StoreProductCategoryServices $categoryServices */
            $categoryServices = app()->make(StoreProductCategoryServices::class);
            $category = $categoryServices->get($info['category_id']);
            if ($category && $category['pid']) {
                $categoryPid = $categoryServices->get($category['pid']);
                if ($categoryPid && $categoryPid['pid']) {
                    $info['category_id'] = [$categoryPid['pid'], $category['pid'], $info['category_id']];
                } else {
                    $info['category_id'] = [$category['pid'], $info['category_id']];
                }
            } else {
                $info['category_id'] = [$info['category_id']];
            }
        }
        if ($info['brand_id'] != '' && $info['brand_com'] != '') {
            $info['brand_id'] = $info['brand_com'] ? array_map('intval', explode(',', $info['brand_com'])) : [];
        }
        return $this->success($info);
    }

    /**
     * 删除
     * @param string $id
     * @return mixed
     */
    public function delete($id)
    {
        $this->services->update($id, ['is_del' => 1]);
        /** @var StoreProductCouponServices $storeProductService */
        $storeProductService = app()->make(StoreProductCouponServices::class);
        //删除商品关联这个优惠券
        $storeProductService->delete(['issue_coupon_id' => $id]);
        /** @var StoreCouponProductServices $storeCouponProductService */
        $storeCouponProductService = app()->make(StoreCouponProductServices::class);
        //删除商品券辅助信息
        $storeCouponProductService->delete(['coupon_id' => $id]);
        return $this->success('删除成功!');
    }

    /**
     * 修改状态
     * @param $id
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function edit($id)
    {
        return $this->success($this->services->createForm($id));
    }

    /**
     * 领取记录
     * @param string $id
     * @return mixed|string
     */
    public function issue_log($id)
    {
        $list = $this->services->issueLog($id);
        return $this->success($list);
    }
}
