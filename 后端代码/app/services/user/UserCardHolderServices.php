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
declare (strict_types=1);

namespace app\services\user;

use app\model\order\StoreOrderCartInfo;
use app\model\order\StorePink;
use app\model\store\SystemStore;
use app\model\user\UserCardHolder;
use app\services\BaseServices;
use app\dao\user\UserCardHolderDao;
use app\services\order\StoreOrderServices;
use app\services\order\StoreReservationOrderServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\product\product\StoreProductServices;
use app\services\product\product\StoreProductRelationServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\store\SystemStoreServices;
use think\exception\ValidateException;

/**
 *
 * Class UserCardHolderServices
 * @package app\services\user
 * @mixin UserCardHolderDao
 */
class UserCardHolderServices extends BaseServices
{

    /**
     * UserCardHolderServices constructor.
     * @param UserCardHolderDao $dao
     */
    public function __construct(UserCardHolderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取卡包列表
     * @param int $uid
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCardHolderList(int $uid, array $where = [])
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $where['uid'] = $uid;
        if(isset($where['store_id']) && $where['store_id'] <= 0) unset($where['store_id']);
        $list = $this->dao->getList($where, $page, $limit, []);
        $count = $this->dao->count($where);
        $time = time();
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductCategoryServices $categoryServices */
        $categoryServices = app()->make(StoreProductCategoryServices::class);
        foreach ($list as $key => &$item) {
            // 统一返回完整 7 位卡号字段；无值时为空串（不回落订单核销码）
            $item['card_no'] = trim((string)($item['card_no'] ?? ''));
            $order = $orderServices->get($item['oid'], ['order_id', 'card_upgrade_use_oid']);
            if (!$order) {
                unset($list[$key]);
                continue;
            }
            $orderArr = is_array($order) ? $order : $order->toArray();
            if (!empty($orderArr['is_debt_repay'])) {
                unset($list[$key]);
                continue;
            }
            $item['order_id'] = $orderArr['order_id'] ?? '';
            // 旧卡订单已用于抵扣升级新单后不可再使用（与前端「卡升级」态一致）
            $item['is_card_upgraded'] = (int) ($orderArr['card_upgrade_use_oid'] ?? 0) > 0;
            $item['store_name']=SystemStore::where("id",$item['store_id'])->value("name");
            $product_card = $productServices->get($item['product_id'], ['image', 'card_cover', 'card_cover_image', 'card_cover_color']);
            if (!$product_card) {
                unset($list[$key]);
                continue;
            }
            $item['image'] = $product_card['image'];
            $item['card_cover'] = $product_card['card_cover'];
            $item['card_cover_image'] = $product_card['card_cover_image'];
            $item['card_cover_color'] = $product_card['card_cover_color'];
            $rootCate = $this->resolveProductCardPackCategory((int)$item['product_id'], $productServices, $categoryServices);
            $item['cate_id'] = $rootCate['cate_id'];
            $item['cate_name'] = $rootCate['cate_name'];
            $item['uncategorized'] = $rootCate['uncategorized'];
            if ($item['product_type'] == 5) {
                $relatedList = $cartServices->getOrderCardRelatedCartInfo((int)$item['oid'], $uid);
                foreach ($relatedList as &$related) {
                    $relatedProductId = (int)($related['product_id'] ?? 0);
                    $relatedCate = $this->resolveProductCardPackCategory($relatedProductId, $productServices, $categoryServices);
                    $related['cate_id'] = $relatedCate['cate_id'];
                    $related['cate_name'] = $relatedCate['cate_name'];
                    $related['uncategorized'] = $relatedCate['uncategorized'];
                    $_info = is_array($related['cart_info'] ?? null) ? $related['cart_info'] : [];
                    $productInfo = $_info['productInfo'] ?? [];
                    $attrInfo = $productInfo['attrInfo'] ?? [];
                    $related['project_name'] = $productInfo['store_name'] ?? '';
                    $related['image'] = $attrInfo['image'] ?? ($productInfo['image'] ?? '');
                    $related['duration'] = (int)($productInfo['project_service_duration'] ?? 0);
                    $related['holder_id'] = (int)$item['id'];
                }
                unset($related);
                $item['related_list'] = $relatedList;
                $cart_info = array_column($relatedList, 'cart_info');
            } else {
                $cartInfo = $cartServices->getCartInfoList(['oid' => $item['oid']], ['cart_info']);
                $cart_info = [];
                foreach ($cartInfo as $k => $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $cart_info[] = is_string($v['cart_info'] ?? null) ? json_decode($v['cart_info'], true) : ($v['cart_info'] ?? []);
                }
            }
            $item['cart_info'] = $cart_info;
            if ($item['write_valid'] == 1) {
                $item['status'] = 1; // 核销中
            } else if ($time < $item['write_start']) {
                $item['status'] = 0; // 核销未开始
            } elseif ($time >= $item['write_start'] && $time < $item['write_end']) {
                $item['status'] = 1; // 核销中
            } else {
                $item['status'] = 2; // 已过期
            }
            $item['write_start'] = date('Y-m-d H:i:s', $item['write_start']);
            $item['write_end'] = date('Y-m-d H:i:s', $item['write_end']);
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        $list = array_values($list);
        return compact('list', 'count');
    }

    /**
     * 解析商品在手机端卡包中应归属的分类
     * 商品可属于多个分类：仅保留开启「手机端卡包分类」的一级分类；均无则归入未分类
     * @param int $productId
     * @param StoreProductServices $productServices
     * @param StoreProductCategoryServices $categoryServices
     * @return array{cate_id:int,cate_name:string,uncategorized:bool}
     */
    protected function resolveProductCardPackCategory(int $productId, StoreProductServices $productServices, StoreProductCategoryServices $categoryServices): array
    {
        $uncategorized = ['cate_id' => 0, 'cate_name' => '', 'uncategorized' => true];
        if ($productId <= 0) {
            return $uncategorized;
        }
        $cateIdStr = (string)$productServices->value(['id' => $productId], 'cate_id');
        $cateIds = $this->parseCateIdString($cateIdStr);
        if (!$cateIds) {
            $cateIds = $this->getProductRelationCategoryIds($productId);
        }
        if (!$cateIds) {
            $pid = (int)$productServices->value(['id' => $productId], 'pid');
            if ($pid > 0 && $pid !== $productId) {
                return $this->resolveProductCardPackCategory($pid, $productServices, $categoryServices);
            }
        }
        if (!$cateIds) {
            return $uncategorized;
        }
        $rootMap = [];
        foreach ($cateIds as $cid) {
            $root = $this->resolveRootCategoryMeta($cid, $categoryServices);
            if ($root && $root['cate_id'] > 0) {
                $rootMap[$root['cate_id']] = $root;
            }
        }
        if (!$rootMap) {
            return $uncategorized;
        }
        $enabled = array_values(array_filter($rootMap, function ($item) {
            return (int)($item['mobile_card_show'] ?? 1) === 1;
        }));
        if (!$enabled) {
            return $uncategorized;
        }
        usort($enabled, function ($a, $b) {
            if ($a['sort'] !== $b['sort']) {
                return $b['sort'] <=> $a['sort'];
            }
            return $a['cate_id'] <=> $b['cate_id'];
        });
        $picked = $enabled[0];
        return [
            'cate_id' => (int)$picked['cate_id'],
            'cate_name' => (string)$picked['cate_name'],
            'uncategorized' => false,
        ];
    }

    /**
     * 根据分类ID解析所属一级分类信息
     * @param int $cateId
     * @param StoreProductCategoryServices $categoryServices
     * @return array|null
     */
    protected function resolveRootCategoryMeta(int $cateId, StoreProductCategoryServices $categoryServices): ?array
    {
        if ($cateId <= 0) {
            return null;
        }
        $current = $categoryServices->get($cateId, ['id', 'pid', 'cate_name', 'mobile_card_show', 'sort']);
        if (!$current) {
            return null;
        }
        $rootArr = is_array($current) ? $current : $current->toArray();
        $guard = 0;
        while ((int)($rootArr['pid'] ?? 0) > 0 && $guard < 10) {
            $parent = $categoryServices->get((int)$rootArr['pid'], ['id', 'pid', 'cate_name', 'mobile_card_show', 'sort']);
            if (!$parent) {
                break;
            }
            $rootArr = is_array($parent) ? $parent : $parent->toArray();
            $guard++;
        }
        if ((int)($rootArr['id'] ?? 0) <= 0 || ($rootArr['cate_name'] ?? '') === '') {
            return null;
        }
        return [
            'cate_id' => (int)$rootArr['id'],
            'cate_name' => (string)$rootArr['cate_name'],
            'mobile_card_show' => (int)($rootArr['mobile_card_show'] ?? 1),
            'sort' => (int)($rootArr['sort'] ?? 0),
        ];
    }

    /**
     * 解析商品分类 ID 字符串
     * @param string $cateIdStr
     * @return array
     */
    protected function parseCateIdString(string $cateIdStr): array
    {
        if ($cateIdStr === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $cateIdStr)))));
    }

    /**
     * 从商品关联表读取分类 ID
     * @param int $productId
     * @return array
     */
    protected function getProductRelationCategoryIds(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        /** @var StoreProductRelationServices $relationServices */
        $relationServices = app()->make(StoreProductRelationServices::class);
        $relationIds = $relationServices->getColumn(['product_id' => $productId, 'type' => 1, 'status' => 1], 'relation_id');
        if (!$relationIds) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $relationIds))));
    }

    /**
     * 获取卡项信息
     * @param int $id
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function oneCardHolder(int $id,bool $is_price = false)
    {
        $cardHolder = $this->dao->get($id);
        if (!$cardHolder) {
            throw new ValidateException('信息有误');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $cardHolder = $cardHolder->toArray();
        $time = time();
        $cardHolder['store_name'] = '';
        $cardHolder['store_image'] = '';
        if ($cardHolder['store_id']) {
            $store = $storeServices->get($cardHolder['store_id'], ['name', 'image']);
            $cardHolder['store_name'] = $store['name'];
            $cardHolder['store_image'] = $store['image'];
        }
        $order = $orderServices->get($cardHolder['oid'], ['order_id','pay_price']);
        $cardHolder['order_id'] = $order['order_id'];
        $cardHolder['pay_price'] = $order['pay_price'];
        $product_card = $productServices->get($cardHolder['product_id'], ['pid', 'image', 'card_cover', 'card_cover_image', 'card_cover_color']);
        if (!$product_card) {
            return null;
        }
        $cardHolder['pid'] = $product_card['pid'];
        $cardHolder['image'] = $product_card['image'];
        $cardHolder['card_cover'] = $product_card['card_cover'];
        $cardHolder['card_cover_image'] = $product_card['card_cover_image'];
        $cardHolder['card_cover_color'] = $product_card['card_cover_color'];
        if ($cardHolder['write_valid'] == 1) {
            $cardHolder['status'] = 1; // 核销中
        } else if ($time < $cardHolder['write_start']) {
            $cardHolder['status'] = 0; // 核销未开始
        } elseif ($time >= $cardHolder['write_start'] && $time < $cardHolder['write_end']) {
            $cardHolder['status'] = 1; // 核销中
        } else {
            $cardHolder['status'] = 2; // 已过期
        }
        if ($cardHolder['write_start'] && $cardHolder['write_end']) {
            $cardHolder['write_start'] = date('Y-m-d H:i:s', $cardHolder['write_start']);
            $cardHolder['write_end'] = date('Y-m-d H:i:s', $cardHolder['write_end']);
        }
        $cardHolder['add_time'] = date('Y-m-d H:i:s', $cardHolder['add_time']);
        $writeoffedNum = $this->getWriteSurplusTimes($cardHolder['oid']);
        $cardHolder['write_surplus_times'] = bcsub((string)$cardHolder['write_times'], (string)$writeoffedNum, 0);
        $cardHolder['remaining_price'] = 0;
        if($is_price) {
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cart_info = $cartServices->getCartColunm(['oid' => $cardHolder['oid'], 'cart_type' => 2], '*');
            $cart = $cartServices->getOne(['oid' => $cardHolder['oid'], 'cart_type' => 0]);
            $pay_price = 0;
            $remaining_price = 0;
            $count = count($cart_info);
            foreach ($cart_info as $k => &$v) {
                $_info = is_string($v['cart_info']) ? json_decode($v['cart_info'], true) : $v['cart_info'];
                $v['cart_info'] = $_info;
                $v['waiting_service'] = 0;
                if ($k > $count - 2) {
                    $v['payPrice'] = bcsub((string)$cart['pay_price'], (string)$pay_price, 2);
                } else {
                    $v['payPrice'] = $cartServices->setActualPaymentPrice($cart_info, $v['product_id'],$v['sku_unique'], $cart['pay_price']);
                    $pay_price = bcadd((string)$pay_price, (string)$v['payPrice'], 2);
                }
                $remaining_price = bcadd((string)$remaining_price, bcmul(bcdiv((string)$v['payPrice'], (string)$v['write_times'], 4), (string)$v['write_surplus_times'], 2), 2);
            }
            $cardHolder['remaining_price'] = $cardHolder['write_times'] == $cardHolder['write_surplus_times'] ? $cardHolder['pay_price'] : $remaining_price;
        }
        return $cardHolder;
    }

    /**
     * 计算剩余核销数量
     * @param int $oid
     * @return int|mixed
     */
    public function getWriteSurplusTimes(int $oid)
    {
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartInfoServices->getCartColunm(['oid' => $oid, 'cart_type' => 2], 'id,cart_id,cart_num,surplus_num,is_writeoff,cart_info,product_type,is_support_refund,cart_type,write_times,write_surplus_times');
        /** @var StoreReservationOrderServices $reservationOrderServices */
        $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
        $writeoffedNum = 0;
        foreach ($cartInfo as &$item) {
            $writeoffed_num = bcsub((string)$item['write_times'], (string)$item['write_surplus_times']);
            $item['unservice_num'] = 0;
            if ($item['product_type'] == 6) {//预约单 || 预约商品
                $item['unservice_num'] = $reservationOrderServices->count(['oid' => $oid, 'cart_info_id' => $item['id'], 'status' => [0, 1, 3], 'is_del' => 0, 'is_system_del' => 0]);
            }
            $writeoffedNum += max((int)bcsub((string)$writeoffed_num, (string)$item['unservice_num']), 0);
        }
        return $writeoffedNum;
    }

    /**
     * 删除卡包
     * @param int $id
     * @return mixed
     */
    public function userDelCardHolder(int $id)
    {
        return $this->dao->update(['id' => $id], ['is_del' => 1]);
    }

    /**
     * 添加卡包
     * @param array $data
     * @return \mohe\basic\BaseModel|false|\think\Model
     */
    public function setCardHolder($orderInfo, array $data, $info)
    {
        if (!$data || !$orderInfo) return false;
        if (!empty($orderInfo['is_debt_repay'])) {
            return false;
        }
        /** @var StoreCardRelatedServices $relatedServices */
        $relatedServices = app()->make(StoreCardRelatedServices::class);
        if ($this->dao->be(['uid' => $orderInfo['uid'], 'oid' => $orderInfo['id']])) return false;
        if (!in_array($info['productInfo']['product_type'], [4, 5])) return false;
        $productId=$info['productInfo']['id'];
        if(in_array($orderInfo['source'],[8,9,10])){
            $productId=$info['productInfo']['product_id'] ?? 0;
            $pkStatus=StorePink::where("order_id_key",$orderInfo['id'])->value("status");
            if($pkStatus != 2) {
                $data['is_del'] = 1;
            }
        }
        $data['uid'] = $orderInfo['uid'];
        $data['oid'] = $orderInfo['id'];
        $data['card_name'] = $info['productInfo']['store_name'];
        $data['store_id'] = $orderInfo['store_id'];
        $data['product_id'] = $productId;
        $data['product_type'] = $info['productInfo']['product_type'];
        $data['verify_code'] = $orderInfo['verify_code'];
        $data['write_valid'] = $info['productInfo']['attrInfo']['write_valid'];
//        if ($info['productInfo']['product_type'] == 5) {
//            $write_times = $relatedServices->getRelatedProductWrite($data['product_id']);
//        } else {
//            $write_times = bcmul((string)$info['productInfo']['attrInfo']['write_times'], (string)$orderInfo['total_num'], 0);
//        }
        $info=StoreOrderCartInfo::where("oid",$orderInfo['id'])->where("cart_type",0)->find();
        $write_times=StoreOrderCartInfo::where("oid",$orderInfo['id'])->where("cart_type",2)->sum("write_times");
        $data['write_times']=$write_times;
        $data['write_surplus_times']=$write_times;
        $data['write_start']=$info['write_start'];
        $data['write_end']=$info['write_end'];
        $data['add_time'] = time();
        /** @var CardNumberServices $cardNumberServices */
        $cardNumberServices = app()->make(CardNumberServices::class);
        return $cardNumberServices->withAllocateRetry(function (string $cardNo) use (&$data) {
            $data['card_no'] = $cardNo;
            return $this->dao->save($data);
        }, null, 'purchase');
    }

    /**
     * 后台退款信息
     * @param $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cardBenefitsData(int $id)
    {
        $cardHolder = $this->dao->getOne(['oid' => $id]);
        if (!$cardHolder) {
            throw new ValidateException('信息有误');
        }
        $cardHolder = $cardHolder->toArray();
		$where = ['oid' => $id];
		if ($cardHolder['product_type'] == 5) {//卡项
			$where['cart_type'] = 2;
		}
		/** @var StoreOrderServices $orderService */
		$orderService = app()->make(StoreOrderServices::class);
		$orderInfo = $orderService->get($id);
		if (!$orderInfo) {
			throw new ValidateException('信息有误');
		}
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cart_info = $cartServices->getCartColunm($where, '*');
        $cart = $cartServices->getOne(['oid' => $cardHolder['oid'], 'cart_type' => 0]);
        $cartWriteTimes = 0;
        foreach ($cart_info as $cartItem) {
            $cartWriteTimes += (int)($cartItem['write_times'] ?? 0);
        }
        if ($cartWriteTimes > 0) {
            $cardHolder['write_times'] = $cartWriteTimes;
        }
        $writeoffedNum = $this->getWriteSurplusTimes($id);
        $cardHolder['write_surplus_times'] = bcsub((string)$cardHolder['write_times'], (string)$writeoffedNum, 0);
        $pay_price = 0;
        $remaining_price = 0;
        $count = count($cart_info);
        foreach ($cart_info as $k => &$v) {
            $_info = is_string($v['cart_info']) ? json_decode($v['cart_info'], true) : $v['cart_info'];
            $v['cart_info'] = $_info;
            $v['waiting_service'] = 0;
            if ($k > $count - 2) {
                $v['payPrice'] = bcsub((string)$cart['pay_price'], (string)$pay_price, 2);
            } else {
                $v['payPrice'] = $cartServices->setActualPaymentPrice($cart_info, $v['product_id'], $v['sku_unique'], $cart['pay_price']);
                $pay_price = bcadd((string)$pay_price, (string)$v['payPrice'], 2);
            }
            $remaining_price = bcadd((string)$remaining_price, bcmul(bcdiv((string)$v['payPrice'], (string)$v['write_times'], 4), (string)$v['write_surplus_times'], 2), 2);
        }
        // 未核销时按实付金额退款，避免按次数分摊时 bcdiv 精度截断少 0.01
        $cardHolder['remaining_price'] = (int)$writeoffedNum === 0
            ? (string)$orderInfo['pay_price']
            : $remaining_price;
        $cardHolder['cart_info'] = $cart_info;
		$cardHolder['pay_price'] = $orderInfo['pay_price'];
        return $cardHolder;
    }
}
