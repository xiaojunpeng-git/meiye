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
namespace app\services\order;

use app\dao\order\StoreOrderDao;
use app\dao\order\StoreOrderWriteoffDao;
use app\jobs\BatchHandleJob;
use app\jobs\order\AutoOrderUnpaidCancelJob;
use app\jobs\order\OrderJob;
use app\jobs\order\OrderStatusJob;
use app\jobs\order\SpliteOrderAfterJob;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\model\user\UserCardHolder;
use app\model\user\UserRecharge;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\services\activity\combination\StorePinkServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\BaseServices;
use app\services\message\SystemPrinterServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\other\queue\QueueAuxiliaryServices;
use app\services\other\queue\QueueServices;
use app\services\pay\PayServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\product\product\StoreProductLogServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\form\SystemFormServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserBillServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\user\UserAddressServices;
use app\services\user\level\UserLevelServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\services\FileService;
use mohe\services\FormBuilder as Form;
use mohe\services\SystemConfigService;
use mohe\traits\OptionTrait;
use mohe\traits\ServicesTrait;
use mohe\utils\Arr;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * Class StoreOrderServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderServices extends BaseServices
{
    use OptionTrait, ServicesTrait;

    /**
     * 订单类型
     * @var string[]
     */
    protected $type = [
        0 => '普通',
        1 => '秒杀',
        2 => '砍价',
        3 => '拼团',
        4 => '积分',
        5 => '套餐',
        6 => '预售',
        7 => '新人礼',
        8 => '抽奖',
        9 => '拼单',
        10 => '桌码',
        11 => '卡项',
        12 => '预约',
        13 => '配送',
    ];

    /**
     * 发货类型
     * @var string[]
     */
    public $deliveryType = ['send' => '商家配送', 'express' => '快递配送', 'fictitious' => '虚拟发货', 'delivery_part_split' => '拆分部分发货', 'delivery_split' => '拆分发货完成'];

    /**
     * StoreOrderProductServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    public function youzan($id){
        [$page, $limit] = $this->getPageValue();
        $phone=User::where("uid",$id)->value("phone");
        $list=Db::name("订单明细报表")
            ->where("phone",$phone)
            ->page($page, $limit)
            ->order('wcsj desc')
            ->select();
        $count = Db::name("订单明细报表")->where("phone",$phone)->count();
        return compact('list', 'count');
    }

   public function hexiao($id){
        $service=app()->make(StoreOrderWriteOffServices::class);
        $where['uid']=$id;
        $result=$service->getAllWriteOffRecords($where);
        return $result;
    }
    /**
     * 获得赠送记录
     */
    public function sendDetail($id){
        $list=[];
        $sendAll=StoreOrder::where("id",$id)->value("send_all");
        if(!empty($sendAll)){
            $sendAll=json_decode($sendAll,true);
            if (!is_array($sendAll)) {
                return $list;
            }
            foreach ($sendAll['product'] ?? [] as $k=>$v){
                 $v['type']=1;
                 $v['begin_time_label']=date("Y-m-d",$v['begin_time']);
                 $v['end_time_label']=date("Y-m-d",$v['end_time']);
                 $list[]=$v;
            }
            foreach ($sendAll['coupon'] ?? [] as $k=>$v){
                $v['type']=2;
                $v['begin_time_label']=date("Y-m-d",$v['begin_time']);
                $v['end_time_label']=date("Y-m-d",$v['end_time']);
                $list[]=$v;
            }
        }
        return $list;
    }
    /**
     * 从缓存中获取购买商品个数
     * @param int $uid
     * @param int $type
     * @param int $id
     * @return int
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/11/3
     */
    public function getBuyCountCache(int $uid, int $type, int $id)
    {
        $key = md5($uid . $type . $id);
        $res = $this->dao->cacheInfoById($key);
        if (null !== $res) {
            $num = $this->dao->getBuyCount($uid, $type, $id);
            $this->dao->cacheUpdate(['type' => $type, 'uid' => $uid, 'product_id' => $id, 'totalNum' => $num ?: 0], $key);
        } else {
            $num = $res['totalNum'] ?? 0;
        }
        return (int)$num;
    }


    /**
     * 获取系统设置订单取消时间
     * @param int $type
     * @return array|mixed
     */
    public function getOrderCancelTime(int $type = -1)
    {
        //系统预设取消订单时间段
        $keyValue = ['order_cancel_time', 'order_activity_time', 'order_bargain_time', 'order_seckill_time', 'order_pink_time', 'rebate_points_orders_time'];
        //获取配置
        $systemValue = SystemConfigService::more($keyValue);
        //格式化数据
        $systemValue = Arr::setValeTime($keyValue, is_array($systemValue) ? $systemValue : []);
        $secs[1] = $systemValue['order_seckill_time'] ?: $systemValue['order_activity_time'];
        $secs[2] = $systemValue['order_bargain_time'] ?: $systemValue['order_activity_time'];
        $secs[3] = $systemValue['order_pink_time'] ?: $systemValue['order_activity_time'];
        $secs[4] = $systemValue['rebate_points_orders_time'] ?: $systemValue['order_activity_time'];
        $secs[0] = $systemValue['order_cancel_time'];
        return $type == -1 ? $secs : ($secs[$type] ?? $secs[0]);
    }

    /**
     * 获取门店订单统计
     * @param int $storeId
     * @return array
     */
    public function getStoreOrderHeader(int $storeId)
    {
        return [
            'cashier' => $this->dao->count(['pid' => 0, 'type' => 106, 'is_system_del' => 0, 'store_id' => $storeId]),
            'delivery' => $this->dao->count(['pid' => 0, 'type' => 107, 'is_system_del' => 0, 'store_id' => $storeId]),
            'writeoff' => $this->dao->count(['pid' => 0, 'type' => 105, 'is_system_del' => 0, 'store_id' => $storeId]),
        ];
    }

    /**
     * 订单列表是否含搜索条件（search_user 为 "0" 时不能用 empty/布尔判断）
     * @param array $where
     * @return bool
     */
    public static function hasOrderListSearch(array $where): bool
    {
        foreach (['real_name', 'search_order_id', 'search_verify_code', 'search_product', 'search_user'] as $key) {
            if (trim((string)($where[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取列表
     * @param array $where
     * @param array $field
     * @param array $with
     * @param bool $abridge
     * @param string $order
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderList(array $where, array $field = ['*'], array $with = [], bool $abridge = false, string $order = 'add_time DESC,id DESC', bool $storeBackendListProductFormat = false)
    {
        [$page, $limit] = $this->getPageValue();
        $data = $this->dao->getOrderList($where, $field, $page, $limit, $with, $order);
        $count = $this->dao->count($where);
        $stat = [];
        $batch_url = "file/upload/1";
        if ($data) {
            $data = $this->tidyOrderList($data, true, $abridge);
            // 旧卡升级：把 card_upgrade_use_oid 映射成“新订单编号”，便于前端展示
            $upgradeUseIds = array_values(array_unique(array_filter(array_map('intval', array_column($data, 'card_upgrade_use_oid')))));
            $upgradeUseOrderIdMap = [];
            if ($upgradeUseIds) {
                $upgradeUseOrderIdMap = StoreOrder::whereIn('id', $upgradeUseIds)->column('order_id', 'id');
            }
            $supplierIds = array_column($data, 'supplier_id');
            $storeIds = array_column($data, 'store_id');
            $storeIdsTwo = array_column($data, 'kua_store');
            $storeIds=array_merge($storeIds,$storeIdsTwo);
            $supplierList = $storeList = [];
            if ($supplierIds) {
                /** @var SystemSupplierServices $supplierServices */
                $supplierServices = app()->make(SystemSupplierServices::class);
                $supplierList = $supplierServices->getColumn([['id', 'in', $supplierIds], ['is_del', '=', 0]], 'id,supplier_name', 'id');
            }
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            /** @var StoreOrderStatusServices $statusServices */
            $statusServices = app()->make(StoreOrderStatusServices::class);
            $orderTypes=['普通订单','充值订单','核销订单'];
            $yejiTypes=[2,1,3]; //购卡，充值，消耗
            foreach ($data as &$item) {
                $item['is_card_upgrade_old'] = (int)(($item['card_upgrade_use_oid'] ?? 0) > 0);
                $useOid = (int)($item['card_upgrade_use_oid'] ?? 0);
                $item['card_upgrade_use_order_id'] = $useOid ? ($upgradeUseOrderIdMap[$useOid] ?? '') : '';
                $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
                $total_cart_num = 0;
                //销售
                $theType=$yejiTypes[$item['order_type']] ?? 2;
                $linkId=$item['id'];
                if($theType != 2){
                     $linkId=$item['link_id'];
                }
                if($item['order_type'] == 0){
                    //普通订单
                    $theType=[1,2,3];
                    if($item['product_type'] == 5){
                        $theType=[1,2];
                    }
                    $staffs=StaffYeji::where("order_id",$linkId)->whereIn("type",$theType)->select();
                }else{
                    $staffs=StaffYeji::where("type",$theType)->where("link_id",$linkId)->select();
                }
                $staffsAttr=[];
                $xiaoshou=[];
                $shouyi=[];
                foreach ($staffs as $staffOne){
                      $dian="轮";
                      if($staffOne['is_dian'] == 1){
                          $dian="点";
                      }
                      if($item['order_type'] == 2){
                          $staffsAttr[]=$staffOne['staff_name']."($dian)";
                      }else{
                          if($staffOne['type'] == 3){
                              $shouyi[]=$staffOne['staff_name']."($dian)";
                          }else{
                              $xiaoshou[]=$staffOne['staff_name'];
                          }
                      }
                }
                $item['yeji_sales_staff'] = '';
                $item['yeji_craft_staff'] = '';
                if ($item['order_type'] == 2) {
                    $item['yeji_craft_staff'] = implode(',', $staffsAttr);
                    $item['yeji_staff'] = $item['yeji_craft_staff'];
                } elseif ($item['order_type'] == 1) {
                    $item['yeji_sales_staff'] = implode(',', $xiaoshou);
                    $item['yeji_staff'] = $item['yeji_sales_staff'];
                } else {
                    $item['yeji_sales_staff'] = implode(',', $xiaoshou);
                    $item['yeji_craft_staff'] = implode(',', $shouyi);
                    $sales = $item['yeji_sales_staff'];
                    $craft = $item['yeji_craft_staff'];
                    if ($sales !== '' && $craft !== '') {
                        $item['yeji_staff'] = $sales . ',' . $craft;
                    } else {
                        $item['yeji_staff'] = $sales !== '' ? $sales : $craft;
                    }
                }
                foreach ($item['_info'] as &$items) {
                    if (isset($items['cart_info']['cart_type']) && $items['cart_info']['cart_type'] > 0) continue;
                    $cart_num = $items['cart_info']['cart_num'];
                    $settle_price = $items['cart_info']['productInfo']['attrInfo']['settle_price'] ?? 0;
                    $total_cart_num += $cart_num;
                    $cart_ids = [];
                    $cart_ids[] = ['cart_id' => $items['cart_info']['id'], 'cart_num' => $items['cart_info']['cart_num']];
                    /** @var StoreOrderSplitServices $storeOrderSpliteServices */
                    $storeOrderSpliteServices = app()->make(StoreOrderSplitServices::class);
                    $cartInfos = $storeOrderSpliteServices->getSplitOrderCartInfo($item['id'], $cart_ids, $item);
                    $total_price = $pay_postage = 0;
                    foreach ($cartInfos as $cart) {
                        $_info = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
                        $total_price = bcadd((string)$total_price, bcmul((string)($_info['truePrice'] ?? 0), (string)$cart['cart_num'], 4), 4);
                        if (!in_array($item['shipping_type'], [2, 4])) {
                            $pay_postage = bcadd((string)$pay_postage, (string)($_info['postage_price'] ?? 0), 4);
                        }
                    }
                    //实际退款金额
                    $refund_pay_price = bcadd((string)$total_price, (string)$pay_postage, 2);
                    $refund_price = $refund_pay_price;
                    $items['cart_info']['refund_price'] = $refund_price;
                    //自定义
                    $cardName=UserCardHolder::where("uid",$item['uid'])->where("oid",$item['id'])->value("card_name");
                    if(!empty($cardName)) {
                        $items['cart_info']['productInfo']['store_name']=$cardName;
                    }
                }
                $take_time = $statusServices->value(['oid' => $item['id'], 'change_type' => ['take_delivery', 'take_part_split', 'take_split', 'user_take_delivery']], 'change_time');
                $item['take_time'] = date('Y-m-d H:i:s', $take_time);
                $item['is_all_refund'] = $refund_num == $total_cart_num;
                $item['plate_name'] = '平台';
                $item['order_type_label'] = $orderTypes[$item['order_type']] ?? '';
                $item['link_img']='';
                $item['link_name']='';
                $this->attachWriteoffOrderListLinkInfo($item);
                if($item['order_type'] == 1){
                    //充值
                     $give_price=UserRecharge::where('id',$item['link_id'])->value("give_price");
                     $item['pay_price']=$item['pay_price'].",赠送金额:".$give_price;
                }
                $item['store_name'] = $item['supplier_name'] = '';
                $item['kua_store_name']='';
                if ($item['store_id']) {
                    $item['store_name'] = $storeList[$item['store_id']]['name'] ?? '';
                    $item['plate_name'] = '门店：' . $item['store_name'];
                } elseif ($item['supplier_id']) {
                    $item['supplier_name'] = $supplierList[$item['supplier_id']]['supplier_name'] ?? '';
                    $item['plate_name'] = '供应商：' . $item['supplier_name'];
                }
                if(!empty($item['kua_store'])){
                    $item['kua_store_name']=$storeList[$item['kua_store']]['name'] ?? '';
                }else{
                    $item['kua_store_name']=$storeList[$item['store_id']]['name'] ?? '';
                }
                if ($storeBackendListProductFormat) {
                    $this->attachCartLineYejiStaff($item);
                    $this->formatStoreBackendOrderListCartRows($item);
                }
            }
        }
        return compact('data', 'count', 'stat', 'batch_url');
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSplitOrderList(array $where, array $field = ['*'], array $with = [], bool $storeBackendListProductFormat = false)
    {
        $data = $this->dao->getOrderList($where, $field, 0, 0, $with);
        if ($data) {
            $data = $this->tidyOrderList($data);
            /** @var StoreOrderStatusServices $statusServices */
            $statusServices = app()->make(StoreOrderStatusServices::class);
            foreach ($data as &$item) {
                $log = $statusServices->getColumn(['oid' => $item['id']], 'change_time', 'change_type');
                if (isset($log['delivery'])) {
                    $delivery = date('Y-m-d H:i:s', $log['delivery']);
                } elseif (isset($log['delivery_goods'])) {
                    $delivery = date('Y-m-d H:i:s', $log['delivery_goods']);
                } elseif (isset($log['delivery_fictitious'])) {
                    $delivery = date('Y-m-d H:i:s', $log['delivery_fictitious']);
                } else {
                    $delivery = '';
                }
                $item['delivery_time'] = $delivery;
                $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
                $cart_num = 0;
                foreach ($item['_info'] as &$items) {
                    if (isset($items['cart_info']['cart_type']) && $items['cart_info']['cart_type'] > 0) continue;
                    $cart_num += $items['cart_info']['cart_num'];
                    $cart_ids = [];
                    $cart_ids[] = ['cart_id' => $items['cart_info']['id'], 'cart_num' => $items['cart_info']['cart_num']];
                    /** @var StoreOrderSplitServices $storeOrderSpliteServices */
                    $storeOrderSpliteServices = app()->make(StoreOrderSplitServices::class);
                    $cartInfos = $storeOrderSpliteServices->getSplitOrderCartInfo($item['id'], $cart_ids, $item);
                    $total_price = $pay_postage = 0;
                    foreach ($cartInfos as $cart) {
                        $_info = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
//                        $total_price = bcadd((string)$total_price, bcmul((string)($_info['truePrice'] ?? 0), (string)$cart['cart_num'], 4), 4);
                        $linePayPrice = (string)($_info['pay_price'] ?? $_info['sum_true_price'] ?? '');
                        if ($linePayPrice === '' && isset($_info['truePrice'])) {
                            $linePayPrice = bcmul((string)$_info['truePrice'], (string)$cart['cart_num'], 4);
                        }
                        if ($linePayPrice === '' && isset($_info['price'])) {
                            $linePayPrice = (string)$_info['price'];
                        }
                        $total_price = bcadd((string)$total_price, $linePayPrice !== '' ? $linePayPrice : '0', 4);
                        if (!in_array($item['shipping_type'], [2, 4])) {
                            $pay_postage = bcadd((string)$pay_postage, (string)($_info['postage_price'] ?? 0), 4);
                        }
                    }

                    //实际退款金额
                    $items['cart_info']['refund_price'] = bcadd((string)$total_price, (string)$pay_postage, 2);
                }
                $item['is_all_refund'] = $refund_num == $cart_num;
                if ($storeBackendListProductFormat) {
                    $this->attachWriteoffOrderListLinkInfo($item);
                    $this->attachCartLineYejiStaff($item);
                    $this->formatStoreBackendOrderListCartRows($item);
                }
            }

        }
        return $data;
    }

    /**
     * 前端订单列表
     * @param array $where
     * @param array|string[] $field
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderApiList(array $where, array $field = ['*'], array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $order = isset($where['status']) && $where['status'] === '' ? 'id DESC' : 'add_time DESC,id DESC';
        $data = $this->dao->getOrderList($where, $field, $page, $limit, $with, $order);
        if ($data) {
            $storeIds = array_column($data, 'store_id');
            $storeList = [];
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            $site_name = sys_config('site_name');
            foreach ($data as &$item) {
                $item = $this->tidyOrder($item, true);
                [$pink_name, $color] = $this->tidyOrderType($item, true);
                $item['pink_name'] = $pink_name;
                $cart_num = 0;
                foreach ($item['cartInfo'] ?: [] as $key => $product) {
                    if (isset($item['_status']['_type']) && $item['_status']['_type'] == 3) {
                        $item['cartInfo'][$key]['add_time'] = isset($product['add_time']) ? date('Y-m-d H:i', (int)$product['add_time']) : '时间错误';
                    }
                    $item['cartInfo'][$key]['productInfo']['price'] = $product['truePrice'] ?? 0;

                    if (isset($product['cart_type']) && $product['cart_type'] > 0) continue;
                    $cart_num += $product['cart_num'];
                }
                if (count($item['refund'])) {
                    $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
                    $item['is_all_refund'] = $refund_num == $cart_num;
                } else {
                    $item['is_all_refund'] = false;
                }
                $item['plate_name'] = $site_name;
                if ($item['store_id']) {//门店
                    $item['plate_name'] = $storeList[$item['store_id']]['name'] ?? '';
                }
            }
        }
        return $data;
    }

    /**
     * 获取订单数量
     * @param int $uid
     * @param int $store_id
     * @param int $plat_type
     * @param array $where
     * @return array
     */
    public function getOrderData(int $uid = 0, int $store_id = -1, int $plat_type = -1, array $where = [])
    {
        $where = array_merge($where, ['pid' => 0, 'uid' => $uid, 'is_del' => 0, 'is_system_del' => 0, 'plat_type' => $plat_type]);
        $countWhere = $where;
        if ($store_id != -1) {
            $where['store_id'] = $store_id;
            $countWhere['store_id'] = $store_id;
        }
        $data['order_count'] = (string)$this->dao->count($where);
        $where = $where + ['paid' => 1];
        $data['sum_price'] = (string)$this->dao->sum($where, 'pay_price', true);
//        $countWhere = $store_id != -1 ? ['pid' => 0, 'store_id' => $store_id] : ['pid' => 0];
        if ($uid) {
            $countWhere['uid'] = $uid;
        }
        if ($plat_type != -1) {
            $countWhere['plat_type'] = $plat_type;
        }
        $pid_where = ['pid' => 0];
        $not_pid_where = ['not_pid' => 1];
        $data['unpaid_count'] = (string)$this->dao->count(['status' => 0] + $countWhere + $pid_where);
        $data['unshipped_count'] = (string)$this->dao->count(['status' => 1] + $countWhere + $pid_where);
        $data['received_count'] = (string)$this->dao->count(['status' => 10] + $countWhere + $pid_where);
        $data['evaluated_count'] = (string)$this->dao->count(['status' => 3] + $countWhere + $pid_where);
        $data['unwritoff_count'] = (string)$this->dao->count(['status' => 5] + $countWhere);
        $data['complete_count'] = (string)$this->dao->count(['status' => 4] + $countWhere + $pid_where);
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        $refund_where = ['is_cancel' => 0];
        if ($uid) $refund_where['uid'] = $uid;
        if ($store_id != -1) $refund_where['store_id'] = $store_id;
        $data['refunding_count'] = (string)$storeOrderRefundServices->count($refund_where + ['refund_type' => [0, 1, 2, 4, 5]]);
        $data['refunded_count'] = (string)$storeOrderRefundServices->count($refund_where + ['refund_type' => [3, 6]]);
        $data['refund_count'] = (string)bcadd($data['refunding_count'], $data['refunded_count'], 0);
        $data['yue_pay_status'] = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
        $data['pay_weixin_open'] = (int)sys_config('pay_weixin_open') ?? 0;//微信支付 1 开启 0 关闭
        $data['ali_pay_status'] = (bool)sys_config('ali_pay_status');//支付包支付 1 开启 0 关闭
        /** @var StoreDebtServices $debtServices */
        $debtServices = app()->make(StoreDebtServices::class);
        $debtSummary = $debtServices->getUserPendingSummary($uid);
        $data['debt_order_count'] = (string)($debtSummary['count'] ?? 0);
        $data['debt_pending_total'] = (string)($debtSummary['total_pending'] ?? 0);
        return $data;
    }

    /**
     * 工作台
     * @param int $uid
     * @param array $countWhere
     * @param int $plat_type
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function getStagingData()
    {
        $countWhere['plat_type'] = 0;
        $pid_where = ['pid' => 0, 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0];
        //待发货
        $data['unshipped_count'] = (string)$this->dao->count(['status' => 1] + $countWhere + $pid_where);

        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        $refund_where = ['is_cancel' => 0];
        $data['refunding_count'] = (string)$storeOrderRefundServices->count($countWhere + $refund_where + ['refund_type' => [0, 1, 2, 4, 5]]);
        $data['refunded_count'] = (string)$storeOrderRefundServices->count($countWhere + $refund_where + ['refund_type' => [3, 6]]);
        $data['refund_count'] = (string)bcadd($data['refunding_count'], $data['refunded_count'], 0);
        /** @var StoreProductServices $StoreProductServices */
        $StoreProductServices = app()->make(StoreProductServices::class);
        //已经售馨商品
        $data['outofstock'] = $StoreProductServices->getCount(['status' => 4, 'pid' => 0]);
        //警戒库存商品
        $store_stock = sys_config('store_stock', 0);
        $data['policeforce'] = $StoreProductServices->getCount(['type' => 0, 'relation_id' => 0, 'status' => 5, 'pid' => 0, 'store_stock' => $store_stock > 0 ? $store_stock : 2]);

        return $data;
    }

    /**
     * 获取用户最新的未支付订单信息
     * @param int $uid
     * @return mixed
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserNotPayOrder(int $uid)
    {
        $where = ['pid' => 0, 'uid' => $uid, 'is_del' => 0, 'is_system_del' => 0, 'paid' => 0, 'status' => 0];
        $order = $this->dao->getUserNotPayOrderDetail($where, 'id,order_id,pay_price,pay_type,type,add_time');
        if ($order) {
            $order = $order->toArray();
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cart_info = $cartServices->getOne(['oid' => $order['id']], 'id,cart_info');
            $order['img'] = '';
            $order['store_name'] = '';
            if ($cart_info) {
                $cart_info = $cart_info->toArray();
                $order['img'] = $cart_info['cart_info']['productInfo']['image'] ?? '';
                $order['store_name'] = $cart_info['cart_info']['productInfo']['store_name'] ?? '';
            }
            //系统预设取消订单时间段
            $secs = $this->getOrderCancelTime((int)($order['type'] ?? 0));
            $order['stop_time'] = $secs * 3600 + $order['add_time'];
        }
        if ($order && $order['stop_time'] <= time()) {
            return null;
        }
        return $order;
    }

    /**
     * 订单详情数据格式化
     * @param $order
     * @param bool $detail 是否需要订单商品详情
     * @param bool $isPic 是否需要订单状态图片
     * @return mixed
     */
    public function tidyOrder($order, bool $detail = false, bool $isPic = false)
    {
        $cashTypes=CashType::column("name","id");
        if ($detail == true && isset($order['id'])) {
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfos = $cartServices->getCartColunm(['oid' => $order['id']], 'id,cart_num,is_writeoff,surplus_num,cart_info,refund_num,product_type,is_support_refund,cart_type,promotions_id,type,relation_id,write_times,write_surplus_times,write_start,write_end,deduction_price,debt_amount,repaid_debt_amount', 'unique');
            $info = [];
            /** @var StoreProductReplyServices $replyServices */
            $replyServices = app()->make(StoreProductReplyServices::class);
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            /** @var StoreReservationOrderServices $reservationOrderServices */
            $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
            foreach ($cartInfos as $k => $cartInfo) {
                $cart = json_decode($cartInfo['cart_info'], true);
                $cart['cart_num'] = $cartInfo['cart_num'];
                $cart['surplus_num'] = $cartInfo['write_surplus_times'];
                $cart['refund_num'] = $cartInfo['refund_num'];
                $cart['write_times'] = $cartInfo['write_times'];
                $cart['write_surplus_times'] = $cartInfo['write_surplus_times'];
                $cart['write_start'] = $cartInfo['write_start'];
                $cart['write_end'] = $cartInfo['write_end'];
                $cart['product_type'] = $cartInfo['product_type'];
                $cart['deduction_price'] = $cartInfo['deduction_price'];
                $cart['debt_amount'] = (float)($cartInfo['debt_amount'] ?? 0);
                $cart['repaid_debt_amount'] = (float)($cartInfo['repaid_debt_amount'] ?? 0);
                $cart['unservice_num'] = 0;
                if ($order['type'] == 12 || $cart['product_type'] == 6) {//预约单 || 预约商品
                    $cart['unservice_num'] = $reservationOrderServices->count(['oid' => $order['id'], 'cart_info_id' => $cartInfo['id'], 'status' => [0, 1, 3], 'is_del' => 0, 'is_system_del' => 0]);
                }
                if ($order['type'] == 11 || $cart['product_type'] == 5) {//卡项商品 总核销次数
                    $cart['write_times'] = $cartServices->sum(['oid' => $order['id'], 'cart_type' => 2], 'write_times');
                    $cart['write_surplus_times'] = $cartServices->sum(['oid' => $order['id'], 'cart_type' => 2], 'write_surplus_times');
                }
                $cart['write_off'] = max(bcsub((string)$cart['write_times'], (string)$cart['write_surplus_times'], 0), 0);
                $cart['write_off'] = $cart['writeoffed_num'] = max((int)bcsub((string)$cart['write_off'], (string)$cart['unservice_num']), 0);
                $cart['supplier_id'] = $cart['store_id'] = 0;
                if ($cartInfo['type'] == 1) {
                    $cart['store_id'] = $cartInfo['relation_id'] ?? 0;
                } elseif ($cartInfo['type'] == 2) {
                    $cart['supplier_id'] = $cartInfo['relation_id'] ?? 0;
                }
                $cart['is_support_refund'] = $cartInfo['is_support_refund'];
                $cart['is_gift'] = $cartInfo['cart_type'] == 1 ? 1 : 0;
                $cart['promotions_id'] = $cartInfo['promotions_id'];
                $cart['unique'] = $k;
                //新增是否评价字段
                $cart['is_reply'] = $replyServices->count(['unique' => $k]);
                if (isset($cart['productInfo']['attrInfo'])) {
                    $cart['productInfo']['attrInfo'] = get_thumb_water($cart['productInfo']['attrInfo']);
                }
                $cart['productInfo'] = get_thumb_water($cart['productInfo']);
                if (!isset($cart['productInfo']['store_label'])) {
                    $cart['productInfo']['store_label'] = [];
                    if (isset($item['productInfo']['store_label_id']) && $item['productInfo']['store_label_id']) {
                        $item['productInfo']['store_label'] = $storeProductLabelServices->getLabelCache($item['productInfo']['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                    }
                }
                if (!isset($cart['productInfo']['store_name']) && isset($cart['productInfo']['title'])) {
                    $cart['productInfo']['store_name'] = $cart['productInfo']['title'];
                }
                //一种商品买多件  计算总优惠
                $cart['vip_sum_truePrice'] = bcmul((string)($cart['vip_truePrice'] ?? 0), (string)($cart['cart_num'] ?? 1), 2);
                $cart['is_valid'] = 1;
                $info[] = $cart;
                unset($cart);
            }
            $order['cartInfo'] = $info;
        }
        /** @var StoreOrderStatusServices $statusServices */
        $statusServices = app()->make(StoreOrderStatusServices::class);
        $status = [];
        $storeInfo = [];
        if ($order['store_id']) {
            $storeServices = app()->make(SystemStoreServices::class);
            $storeInfo = $storeServices->get((int)$order['store_id']);
        }
        //系统预设取消订单时间段
        $secs = $this->getOrderCancelTime((int)($order['type'] ?? 0));
        $order['stop_time'] = $secs * 3600 + $order['add_time'];
        if (!$order['paid'] && $order['is_user_del'] && !$order['is_del']) {
            $status['_type'] = -1;
            $status['_title'] = '交易取消';
            $status['_msg'] = '交易取消，感谢您的支持!';
            $status['_class'] = 'nobuy';
        } else if (!$order['paid'] && $order['pay_type'] == 'offline' && !$order['status'] >= 2) {
            $status['_type'] = 9;
            $status['_title'] = '线下支付';
            $status['_msg'] = '待商家确认收款,请耐心等待';
            $status['_class'] = 'nobuy';
        } else if (!$order['paid'] && !$order['is_del'] && !$order['is_user_del']) {
            $status['_type'] = 0;
            $status['_title'] = '待付款';
            $status['_msg'] = '请在' . date('m-d H:i:s', $order['stop_time']) . '前完成支付!';
            $status['_class'] = 'nobuy';
        } else if (!$order['paid'] && $order['is_del']) {
            $status['_type'] = 0;
            $status['_title'] = '交易删除';
            $status['_msg'] = '交易已删除，感谢您的支持!';
            $status['_class'] = 'nobuy';
        } else if ($order['refund_status'] == 2) {
            $status['_type'] = -2;
            $status['_title'] = '已退款';
            $status['_msg'] = '已为您退款,感谢您的支持';
            $status['_class'] = 'state-sqtk';
        } else if ($order['status'] == 4) {
            if ($order['delivery_type'] == 'send') {// 送货
                $status['_type'] = 2;
                if ($order['shipping_type'] == 3 && $order['store_delivery_type'] == 2) {
                    $status['_title'] = '配送中';
                    $status['_msg'] = '商家正在配送，请耐心等待~';
                } else {
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery'], 'change_time')) . '服务商已送货';
                }
                $status['_class'] = 'state-ysh';
            } elseif ($order['delivery_type'] == 'express') {//  发货
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_goods'], 'change_time')) . '服务商已发货';
                $status['_class'] = 'state-ysh';
            } elseif ($order['delivery_type'] == 'split') {//拆分发货
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_part_split'], 'change_time')) . '服务商已拆分多个包裹发货';
                $status['_class'] = 'state-ysh';
            } else {
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_fictitious'], 'change_time')) . '服务商已虚拟发货';
                $status['_class'] = 'state-ysh';
            }
        } elseif ($order['status'] == 5) {
            if ($order['shipping_type'] == 2) {
                $status['_type'] = 5;
                $status['_title'] = '部分核销';
                $status['_msg'] = '部分核销,请继续进行核销';
                $status['_class'] = 'state-nfh';
            } else {
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = '部分核销收货,请继续进行核销';
                $status['_class'] = 'state-ysh';
            }
        } else if ($order['refund_status'] == 1) {
            if (in_array($order['refund_type'], [0, 1, 2])) {
                $status['_type'] = -1;
                $status['_title'] = '申请退款中';
                $status['_msg'] = '商家审核中,请耐心等待';
                $status['_class'] = 'state-sqtk';
            } elseif ($order['refund_type'] == 4) {
                $status['_type'] = -1;
                $status['_title'] = '申请退款中';
                $status['_msg'] = '商家同意退款,请填写退货订单号';
                $status['_class'] = 'state-sqtk';
                if ($order['shipping_type'] == 1 || !$storeInfo) {//平台
                    $status['refund_name'] = sys_config('refund_name', '');
                    $status['refund_phone'] = sys_config('refund_phone', '');
                    $status['refund_address'] = sys_config('refund_address', '');
                } else {
                    $status['refund_name'] = $storeInfo['name'];
                    $status['refund_phone'] = $storeInfo['phone'];
                    $status['refund_address'] = $storeInfo['address'] . $storeInfo['detailed_address'];
                }
            } elseif ($order['refund_type'] == 5) {
                $status['_type'] = -1;
                $status['_title'] = '申请退款中';
                $status['_msg'] = '等待商家收货';
                $status['_class'] = 'state-sqtk';
                if ($order['shipping_type'] == 1 || !$storeInfo) {//平台
                    $status['refund_name'] = sys_config('refund_name', '');
                    $status['refund_phone'] = sys_config('refund_phone', '');
                    $status['refund_address'] = sys_config('refund_address', '');
                } else {
                    $status['refund_name'] = $storeInfo['name'];
                    $status['refund_phone'] = $storeInfo['phone'];
                    $status['refund_address'] = $storeInfo['address'] . $storeInfo['detailed_address'];
                }
            }
        } else if ($order['refund_status'] == 3) {
            $status['_type'] = -1;
            $status['_title'] = '部分退款（子订单）';
            $status['_msg'] = '拆分发货，部分退款';
            $status['_class'] = 'state-sqtk';
        } else if ($order['refund_status'] == 4) {
            $status['_type'] = -1;
            $status['_title'] = '子订单已全部申请退款中';
            $status['_msg'] = '拆分发货，全部退款';
            $status['_class'] = 'state-sqtk';
        } else if (!$order['status']) {
            if ($order['pink_id']) {
                /** @var StorePinkServices $pinkServices */
                $pinkServices = app()->make(StorePinkServices::class);
                if ($pinkServices->getCount(['id' => $order['pink_id'], 'status' => 1])) {
                    $status['_type'] = 1;
                    $status['_title'] = '拼团中';
                    $status['_msg'] = '等待其他人参加拼团';
                    $status['_class'] = 'state-nfh';
                } else if (in_array($order['shipping_type'], [1, 3])) {
                    $status['_type'] = 1;
                    if ($order['shipping_type'] == 3 && $order['store_delivery_type'] == 2) {
                        $status['_title'] = '待配送';
                        $status['is_reissue_order'] = 0;
                        if($order['store_id'] && $storeInfo) {
                            if($storeInfo['city_delivery_type'] > 0) $status['is_reissue_order'] = 1;
                        }
                    } else {
                        $status['_title'] = '未发货';
                    }
                    $status['_msg'] = '商品已下单,商家备货中';
                    $status['_class'] = 'state-nfh';
                } else {
                    $status['_type'] = 5;
                    $status['_title'] = '待核销';
                    $status['_msg'] = '待核销,请到门店进行核销';
                    $status['_class'] = 'state-nfh';
                }
            } else {
                if (in_array($order['shipping_type'], [1, 3])) {
                    $status['_type'] = 1;
                    if ($order['shipping_type'] == 3 && $order['store_delivery_type'] == 2) {
                        $status['_title'] = '待配送';
                        $status['is_reissue_order'] = 0;
                        if($order['store_id'] && $storeInfo) {
                            if($storeInfo['city_delivery_type'] > 0) $status['is_reissue_order'] = 1;
                        }
                    } else {
                        $status['_title'] = '未发货';
                    }
                    $status['_msg'] = '商品已下单,商家备货中';
                    $status['_class'] = 'state-nfh';
                } else {
                    $status['_type'] = 5;
                    $status['_title'] = '待核销';
                    $status['_msg'] = $order['type'] == 12 && $order['reservation_type'] == 3 ? '待服务人员上门服务，请耐心等待' : '待核销,请到门店进行核销';
                    $status['_class'] = 'state-nfh';
                }
            }
        } else if ($order['status'] == 1) {
            if ($order['delivery_type'] == 'send') {// 配送
                $status['_type'] = 2;
                if ($order['shipping_type'] == 3 && $order['store_delivery_type'] == 2) {
                    $status['_title'] = '配送中';
                    $status['_msg'] = '商家正在配送，请耐心等待~';
                } else {
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery'], 'change_time')) . '服务商已发货';
                }
                $status['_class'] = 'state-ysh';
            } elseif ($order['delivery_type'] == 'express') {//  发货
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_goods'], 'change_time')) . '服务商已发货';
                $status['_class'] = 'state-ysh';
            } elseif ($order['delivery_type'] == 'split') {//拆分发货
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_split'], 'change_time')) . '服务商已拆分多个包裹发货';
                $status['_class'] = 'state-ysh';
            } else {
                $status['_type'] = 2;
                $status['_title'] = '待收货';
                $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_fictitious'], 'change_time')) . '服务商已虚拟发货';
                $status['_class'] = 'state-ysh';
            }
        } else if ($order['status'] == 2) {
            $status['_type'] = 3;
            if ($order['shipping_type'] == 3 && $order['store_delivery_type'] == 2) {
                $status['_title'] = '待评价';
                $status['_msg'] = '订单已送达,快去评价一下吧';
            } else {
                $status['_title'] = '待评价';
                $status['_msg'] = '已收货,快去评价一下吧';
            }
            $status['_class'] = 'state-ypj';
        } else if ($order['status'] == 3) {
            $status['_type'] = 4;
            $status['_title'] = '交易完成';
            $status['_msg'] = '交易完成,感谢您的支持';
            $status['_class'] = 'state-ytk';
        }
        if (isset($order['pay_type'])){
            $status['_payType'] = ($status['_type'] ?? 0) == 0 ? '' : PayServices::PAY_TYPE[$order['pay_type']] ?? '其他方式';
            if($order['pay_type'] == PayServices::CASH_PAY && isset($order['cash_choose'])){
                $status['_payType'] = $cashTypes[$order['cash_choose']] ?? '';
              //  $status['_payType'] = $status['_payType'].'(记账收款)';
            }
        }
        $order['source_name']=$this->getSourceName($order);
        if (isset($order['delivery_type']))
            $status['_deliveryType'] = isset($this->deliveryType[$order['delivery_type']]) ? $this->deliveryType[$order['delivery_type']] : '其他方式';
        $order['_status'] = $status;
        $order['_pay_time'] = isset($order['pay_time']) && $order['pay_time'] != null ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        $order['_add_time'] = isset($order['add_time']) ? (strstr((string)$order['add_time'], '-') === false ? date('Y-m-d H:i:s', $order['add_time']) : $order['add_time']) : '';
        $order['_shipping_time'] = isset($order['shipping_time']) && $order['shipping_time'] ? date('Y-m-d H:i:s', $order['shipping_time']) : '';
        $order['_delivery_time'] = $order['delivery_time'] = isset($order['delivery_time']) && $order['delivery_time'] ? date('Y-m-d H:i:s', $order['delivery_time']) : '';
        $order['reservation_time'] = isset($order['reservation_time']) && $order['reservation_time'] ? date('Y-m-d', $order['reservation_time']) : '';

        $order['status_pic'] = '';
        //获取商品状态图片
        if ($isPic) {
            try {
                $order_details_images = sys_data('order_details_images') ?: [];
                $order_details_images = array_combine(array_column($order_details_images, 'order_status'), $order_details_images);
                $status_type = $order['_status']['_type'] == 5 ? 0 : $order['_status']['_type'];
                $order['status_pic'] = $order_details_images[$status_type]['pic'] ?? $order_details_images[1]['pic'] ?? '';
            } catch (\Throwable $e) {
            }
        }
        $order['offlinePayStatus'] = (int)sys_config('offline_pay_status') ?? 2;
        $order['offlinePayType'] = (int)sys_config('offline_pay_type') ?? 0;
        $order['spread_nickname'] = '';
        //自购返佣
        if ($order['uid'] == $order['spread_uid']) {
            $order['spread_nickname'] = isset($order['spread_nickname']) ? $order['spread_nickname'] . '(自购)' : '';
        }
        $order['longitude'] = $order['latitude'] = '';
        //处理地址定位
        if (isset($order['user_location']) && $order['user_location']) {
            [$longitude, $latitude] = explode(' ', $order['user_location']);
            $order['longitude'] = $longitude;
            $order['latitude'] = $latitude;
        }
        $debtAmount = (float)($order['debt_amount'] ?? 0);
        $repaidDebt = (float)($order['repaid_debt_amount'] ?? 0);
        /** @var StoreDebtServices $debtServices */
        $debtServices = app()->make(StoreDebtServices::class);
        $order['pending_debt_amount'] = $debtServices->resolveOrderActivePendingAmount((int)($order['id'] ?? 0), $order);
        return $order;
    }

    /**
     * 处理订单来源门店｜供应商
     * @param $order
     * @return array
     */
    public function tidayOrderPlatType($order)
    {
        $plat_type = 0;
        $plat_name = '平台';
        if ($order && isset($order['store_id']) && isset($order['supplier_id'])) {
            if ($order['store_id']) {
                $plat_type = 1;
                $plat_name = '门店';
            } elseif ($order['supplier_id']) {
                $plat_type = 2;
                $plat_name = '供应商';
            }
        }
        return [$plat_type, $plat_name];
    }

    /**
     * 整理订单类型
     * @param $order
     * @param bool $abridge
     * @return string[]
     */
    public function tidyOrderType($order, bool $abridge = false)
    {
        $pink_name = $color = '';
        if ($order && isset($order['type'])) {
            switch ($order['type']) {
                case 0://普通订单
                    if ($order['shipping_type'] == 1) {
                        $pink_name = $abridge ? '普通' : '[普通订单]';
                        $color = '#895612';
                    } else if ($order['shipping_type'] == 2) {
                        $pink_name = $abridge ? '核销' : '[核销订单]';
                        $color = '#8956E8';
                    } else if ($order['shipping_type'] == 3) {
                        if (isset($order['store_delivery_type']) && $order['store_delivery_type'] == 1) {
                            $pink_name = $abridge ? '普通' : '[普通订单]';
                            $color = '#FFA21B';
                        } elseif (isset($order['store_delivery_type']) && $order['store_delivery_type'] == 2) {
                            $pink_name = $abridge ? '配送' : '[配送订单]';
                            $color = '#FFA21B';
                        } else {
                            $pink_name = $abridge ? '分配' : '[分配订单]';
                            $color = '#FFA21B';
                        }
                    } else if ($order['shipping_type'] == 4) {
                        $pink_name = $abridge ? '收银' : '[收银订单]';
                        $color = '#2EC479';
                    }
                    break;
                case 1://秒杀
                    $pink_name = $abridge ? '秒杀' : '[秒杀订单]';
                    $color = '#32c5e9';
                    break;
                case 2://砍价
                    $pink_name = $abridge ? '砍价' : '[砍价订单]';
                    $color = '#12c5e9';
                    break;
                case 3://拼团
                    if (isset($order['pinkStatus'])) {
                        switch ($order['pinkStatus']) {
                            case 1:
                                $pink_name = $abridge ? '拼团' : '[拼团订单]正在进行中';
                                $color = '#f00';
                                break;
                            case 2:
                                $pink_name = $abridge ? '拼团' : '[拼团订单]已完成';
                                $color = '#00f';
                                break;
                            case 3:
                                $pink_name = $abridge ? '拼团' : '[拼团订单]未完成';
                                $color = '#f0f';
                                break;
                            default:
                                $pink_name = $abridge ? '拼团' : '[拼团订单]历史订单';
                                $color = '#457856';
                                break;
                        }
                    } else {
                        $pink_name = $abridge ? '拼团' : '[拼团订单]历史订单';
                        $color = '#457856';
                    }
                    break;
                case 4://积分
                    $pink_name = $abridge ? '积分' : '[积分订单]';
                    $color = '#12c5e9';
                    break;
                case 5://套餐
                    $pink_name = $abridge ? '套餐' : '[优惠套餐]';
                    $color = '#12c5e9';
                    break;
                case 6://预售
                    $pink_name = $abridge ? '预售' : '[预售订单]';
                    $color = '#12c5e9';
                    break;
                case 7://新人礼
                    $pink_name = $abridge ? '新人礼' : '[新人专享]';
                    $color = '#12c5e9';
                    break;
                case 8://抽奖
                    $pink_name = $abridge ? '抽奖' : '[抽奖订单]';
                    $color = '#12c5e9';
                    break;
                case 9://拼单
                    $pink_name = $abridge ? '拼单' : '[拼单订单]';
                    $color = '#12c5e9';
                    break;
                case 10://桌码
                    $pink_name = $abridge ? '桌码' : '[桌码订单]';
                    $color = '#F5222D';
                    break;
                case 11://卡项
                    $pink_name = $abridge ? '卡项' : '[卡项订单]';
                    $color = '#F5222D';
                    break;
                case 12://预约
                    $pink_name = $abridge ? '预约' : '[预约订单]';
                    $color = '#F5222D';
                    break;
            }
        }
        $pink_name='';
        return [$pink_name, $color];
    }

    public function getSourceName($order){
        if(isset($order['source'])) {
            $sourceName=CashSource::column("name","id");
            return $sourceName[$order['source']] ?? '老客扣卡';
        }else{
            return '老客扣卡';
        }
    }
    /**
     * 处理订单支付类型
     * @param $order
     * @return string
     */
    public function tidyOrderPayType($order): string
    {
        $pay_type_name = '';
        $cashTypes=CashType::column("name","id");
        if ($order && isset($order['pay_type'])) {
            switch ($order['pay_type']) {
                case PayServices::WEIXIN_PAY:
                    $pay_type_name = '微信支付';
                    break;
                case PayServices::YUE_PAY:
                    $pay_type_name = '余额支付';
                    break;
                case PayServices::OFFLINE_PAY:
                    $pay_type_name = '线下支付';
                    break;
                case PayServices::ALIAPY_PAY:
                    $pay_type_name = '支付宝支付';
                    break;
                case PayServices::CASH_PAY:
                    $pay_type_name = $cashTypes[$order['cash_choose']] ?? '';
                  //  $pay_type_name=$pay_type_name."(记账收款)";
                    break;
                case PayServices::COMBINATION_PAY:
                    $pay_type_name = '组合支付';
                    break;
                default:
                    $pay_type_name = '其他支付';
                    break;
            }
        }
        $orderType=$order['order_type'] ?? '';
        if($orderType == 2){
            $pay_type_name='次卡支付';
        }
        return $pay_type_name;
    }

    /**
     * 从订单购物车行解析商品名/图（含卡项自定义名）
     */
    protected function parseOrderCartRowProductDisplay($cartRow, int $parentOid = 0, int $uid = 0): array
    {
        $name = '';
        $image = '';
        if (empty($cartRow)) {
            return ['name' => $name, 'image' => $image];
        }
        $cartRow = is_array($cartRow) ? $cartRow : $cartRow->toArray();
        $cartInfo = $cartRow['cart_info'] ?? [];
        if (is_string($cartInfo)) {
            $cartInfo = json_decode($cartInfo, true) ?: [];
        }
        if (!is_array($cartInfo)) {
            $cartInfo = [];
        }
        $pi = $cartInfo['productInfo'] ?? [];
        $name = (string)($pi['store_name'] ?? $pi['title'] ?? '');
        $image = (string)($pi['image'] ?? ($pi['attrInfo']['image'] ?? ''));
        if ($name === '' && !empty($cartRow['product_id'])) {
            $product = StoreProduct::where('id', (int)$cartRow['product_id'])->find();
            if (!empty($product)) {
                $name = (string)($product['store_name'] ?? '');
                $image = $image ?: (string)($product['image'] ?? '');
            }
        }
        // 卡项明细(cart_type=2)为具体权益项目，保留快照名称；仅卡头或无名称时用卡包名称（如定制卡）
        $cartType = (int)($cartRow['cart_type'] ?? 0);
        if ($name === '' || $cartType === 0) {
            $oid = (int)($cartRow['oid'] ?? $parentOid);
            if ($oid > 0 && $uid > 0) {
                $cardName = UserCardHolder::where('uid', $uid)->where('oid', $oid)->value('card_name');
                if (!empty($cardName)) {
                    $name = (string)$cardName;
                }
            }
        }
        return ['name' => $name, 'image' => $image];
    }

    /**
     * 核销子单：解析商品展示（购物车快照 > 核销表 product_id > 主单购物车）
     */
    protected function resolveWriteoffOrderLinkDisplay(array $orderRow, ?array $write = null): array
    {
        $empty = ['name' => '', 'image' => '', 'writeoff_num' => ''];
        if ((int)($orderRow['order_type'] ?? 0) !== 2) {
            return $empty;
        }
        $linkId = (int)($orderRow['link_id'] ?? 0);
        if (!$linkId) {
            return $empty;
        }
        if ($write === null) {
            $writeModel = StoreOrderWriteoff::where('id', $linkId)->find();
            $write = empty($writeModel) ? null : (is_array($writeModel) ? $writeModel : $writeModel->toArray());
        }
        if (empty($write)) {
            return $empty;
        }
        $writeoffNum = (string)($write['writeoff_num'] ?? '');
        $parentOid = (int)($write['oid'] ?? $orderRow['link_order'] ?? 0);
        $uid = (int)($orderRow['uid'] ?? $write['uid'] ?? 0);
        $productId = (int)($write['product_id'] ?? 0);
        $name = '';
        $image = '';

        $cartRow = null;
        $orderCartId = (int)($write['order_cart_id'] ?? 0);
        if ($orderCartId > 0) {
            $cartRow = StoreOrderCartInfo::where('id', $orderCartId)->find();
        }
        if (empty($cartRow) && $parentOid > 0 && $productId > 0) {
            $cartRow = StoreOrderCartInfo::where('oid', $parentOid)
                ->where('product_id', $productId)
                ->where('cart_type', 2)
                ->order('id', 'desc')
                ->find();
            if (empty($cartRow)) {
                $cartRow = StoreOrderCartInfo::where('oid', $parentOid)
                    ->where('product_id', $productId)
                    ->order('id', 'desc')
                    ->find();
            }
        }
        if (empty($cartRow) && $parentOid > 0) {
            $cartRow = StoreOrderCartInfo::where('oid', $parentOid)->where('cart_type', 0)->order('id', 'asc')->find();
        }
        if (!empty($cartRow)) {
            $parsed = $this->parseOrderCartRowProductDisplay($cartRow, $parentOid, $uid);
            $name = $parsed['name'];
            $image = $parsed['image'];
        }

        if ($name === '' && $productId > 0) {
            $product = StoreProduct::where('id', $productId)->find();
            if (!empty($product)) {
                $name = (string)($product['store_name'] ?? '');
                $image = (string)($product['image'] ?? '');
            }
        }

        if ($name === '' && $parentOid > 0 && $uid > 0) {
            $cardName = UserCardHolder::where('uid', $uid)->where('oid', $parentOid)->value('card_name');
            if (!empty($cardName)) {
                $name = (string)$cardName;
            }
        }

        return ['name' => $name, 'image' => $image, 'writeoff_num' => $writeoffNum];
    }

    /**
     * 业绩明细等：按核销记录解析商品名/图（与订单列表核销展示一致）
     */
    public function getWriteoffLinkProductDisplay(int $linkId, int $parentOrderId = 0, int $uid = 0): array
    {
        return $this->resolveWriteoffOrderLinkDisplay([
            'order_type' => 2,
            'link_id' => $linkId,
            'link_order' => $parentOrderId,
            'uid' => $uid,
        ]);
    }

    /**
     * 订单列表：解析是否跟单（拆单子单、核销子单从原购单继承）
     */
    protected function resolveOrderListGendanInfo(array &$orderRow): void
    {
        $staffId = (int)($orderRow['gendan_staff_id'] ?? 0);
        $flag = (int)($orderRow['is_gendan'] ?? 0);
        if (!$staffId && !empty($orderRow['pid']) && (int)$orderRow['pid'] > 0) {
            $parent = StoreOrder::where('id', (int)$orderRow['pid'])->field('is_gendan,gendan_staff_id')->find();
            if ($parent) {
                $staffId = (int)($parent['gendan_staff_id'] ?? 0);
                if (!$flag) {
                    $flag = (int)($parent['is_gendan'] ?? 0);
                }
            }
        }
        if (!$staffId && (int)($orderRow['order_type'] ?? 0) === 2 && !empty($orderRow['link_order'])) {
            $linkOrder = StoreOrder::where('id', (int)$orderRow['link_order'])->field('is_gendan,gendan_staff_id')->find();
            if ($linkOrder) {
                $staffId = (int)($linkOrder['gendan_staff_id'] ?? 0);
                if (!$flag) {
                    $flag = (int)($linkOrder['is_gendan'] ?? 0);
                }
            }
        }
        if ($staffId > 0) {
            $flag = 1;
        }
        $orderRow['gendan_staff_id'] = $staffId;
        $orderRow['is_gendan'] = $flag;
        $orderRow['is_gendan_name'] = $flag ? '是' : '否';
        $orderRow['gendan_staff_name'] = $staffId > 0
            ? (string)(SystemStoreStaff::where('id', $staffId)->value('staff_name') ?: '')
            : '';
    }

    /**
     * 核销订单列表：补全 link_img / link_name（拆单列表等场景）
     */
    protected function attachWriteoffOrderListLinkInfo(array &$orderRow): void
    {
        if ((int)($orderRow['order_type'] ?? 0) !== 2 || empty($orderRow['link_id'])) {
            return;
        }
        if (trim((string)($orderRow['link_name'] ?? '')) !== '') {
            return;
        }
        $display = $this->resolveWriteoffOrderLinkDisplay($orderRow);
        if ($display['name'] === '') {
            return;
        }
        $orderRow['link_img'] = $display['image'];
        $orderRow['link_product_name'] = $display['name'];
        $orderRow['link_writeoff_num'] = $display['writeoff_num'];
        $orderRow['link_name'] = $display['name'] . ',核销数量:' . $display['writeoff_num'];
    }

    /**
     * 列表用服务对象标签 [本人] / [朋友]（核销单优先读核销记录）
     */
    protected function resolveOrderListServiceObjectTag(array &$orderRow): string
    {
        $orderSvc = trim((string)($orderRow['service_object'] ?? ''));
        if ($orderSvc === '' && !empty($orderRow['id'])) {
            $orderSvc = trim((string)(StoreOrder::where('id', (int)$orderRow['id'])->value('service_object') ?: ''));
        }
        if ((int)($orderRow['order_type'] ?? 0) === 2 && !empty($orderRow['link_id'])) {
            $writeSvc = trim((string)(StoreOrderWriteoff::where('id', (int)$orderRow['link_id'])->value('service_object') ?: ''));
            if ($writeSvc === '朋友' || $writeSvc === '本人') {
                $orderSvc = $writeSvc;
            }
        }
        $label = ($orderSvc === '朋友') ? '朋友' : '本人';
        $orderRow['service_object'] = $orderSvc;
        $orderRow['service_object_label'] = $label;
        return '[' . $label . ']';
    }

    /**
     * 商品名/展示文案前加 [本人]/[朋友]
     */
    protected function prefixOrderListServiceObjectTag(string $name, string $tag): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }
        $name = preg_replace('/^服务对象[：:](本人|朋友)\s*/u', '', $name);
        $name = preg_replace('/^\[(本人|朋友)\]/u', '', $name);
        if (preg_match('/^\[(本人|朋友)\]/u', $name)) {
            return $name;
        }
        return $tag . $name;
    }

    /**
     * 门店/总后台订单列表：为每个商品行附加手艺人/销售
     */
    protected function attachCartLineYejiStaff(array &$orderRow): void
    {
        $orderType = (int)($orderRow['order_type'] ?? 0);
        $orderStaff = (string)($orderRow['yeji_staff'] ?? '');

        if (!isset($orderRow['_info']) || !is_array($orderRow['_info'])) {
            return;
        }

        $orderSalesStaff = (string)($orderRow['yeji_sales_staff'] ?? '');
        $orderCraftStaff = (string)($orderRow['yeji_craft_staff'] ?? '');

        if ($orderType !== 0) {
            foreach ($orderRow['_info'] as &$wrap) {
                $wrap['line_yeji_staff'] = $orderStaff;
                $wrap['line_yeji_sales_staff'] = $orderSalesStaff;
                $wrap['line_yeji_craft_staff'] = $orderCraftStaff;
            }
            unset($wrap);
            return;
        }

        $orderId = (int)($orderRow['id'] ?? 0);
        $cartIds = [];
        $goodsIds = [];
        foreach ($orderRow['_info'] as $wrap) {
            if (!isset($wrap['cart_info']) || !is_array($wrap['cart_info'])) {
                continue;
            }
            $ci = $wrap['cart_info'];
            if (isset($ci['cart_type']) && (int)$ci['cart_type'] > 0) {
                continue;
            }
            $cartId = (int)($ci['cart_id'] ?? $ci['id'] ?? 0);
            $goodsId = (int)($ci['product_id'] ?? ($ci['productInfo']['id'] ?? 0));
            if ($cartId) {
                $cartIds[] = $cartId;
            }
            if ($goodsId) {
                $goodsIds[] = $goodsId;
            }
        }

        $salesStaffMap = [];
        $craftStaffMap = [];
        if ($orderId && ($cartIds || $goodsIds)) {
            $query = StaffYeji::where('order_id', $orderId)->whereIn('type', [1, 2, 3]);
            if ($cartIds && $goodsIds) {
                $query->where(function ($q) use ($cartIds, $goodsIds) {
                    $q->whereIn('cart_id', $cartIds)->whereOr(function ($q2) use ($goodsIds) {
                        $q2->whereIn('goods_id', $goodsIds);
                    });
                });
            } elseif ($cartIds) {
                $query->whereIn('cart_id', $cartIds);
            } else {
                $query->whereIn('goods_id', $goodsIds);
            }
            $rows = $query->select();
            foreach ($rows as $row) {
                $cartKey = (int)($row['cart_id'] ?? 0);
                $goodsKey = (int)($row['goods_id'] ?? 0);
                $name = (string)($row['staff_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $yejiType = (int)($row['type'] ?? 0);
                if ($yejiType === 3) {
                    $name .= (int)($row['is_dian'] ?? 0) === 1 ? '(点)' : '(轮)';
                    $targetMap = &$craftStaffMap;
                } else {
                    $targetMap = &$salesStaffMap;
                }
                foreach ([$cartKey, $goodsKey] as $key) {
                    if ($key <= 0) {
                        continue;
                    }
                    if (!isset($targetMap[$key])) {
                        $targetMap[$key] = [];
                    }
                    if (!in_array($name, $targetMap[$key], true)) {
                        $targetMap[$key][] = $name;
                    }
                }
                unset($targetMap);
            }
        }

        foreach ($orderRow['_info'] as &$wrap) {
            if (!isset($wrap['cart_info']) || !is_array($wrap['cart_info'])) {
                continue;
            }
            $ci = $wrap['cart_info'];
            $cartId = (int)($ci['cart_id'] ?? $ci['id'] ?? 0);
            $goodsId = (int)($ci['product_id'] ?? ($ci['productInfo']['id'] ?? 0));
            $salesNames = [];
            $craftNames = [];
            if ($cartId && !empty($salesStaffMap[$cartId])) {
                $salesNames = $salesStaffMap[$cartId];
            } elseif ($goodsId && !empty($salesStaffMap[$goodsId])) {
                $salesNames = $salesStaffMap[$goodsId];
            }
            if ($cartId && !empty($craftStaffMap[$cartId])) {
                $craftNames = $craftStaffMap[$cartId];
            } elseif ($goodsId && !empty($craftStaffMap[$goodsId])) {
                $craftNames = $craftStaffMap[$goodsId];
            }
            $wrap['line_yeji_sales_staff'] = $salesNames ? implode(',', $salesNames) : $orderSalesStaff;
            $wrap['line_yeji_craft_staff'] = $craftNames ? implode(',', $craftNames) : $orderCraftStaff;
            $sales = $wrap['line_yeji_sales_staff'];
            $craft = $wrap['line_yeji_craft_staff'];
            if ($sales !== '' && $craft !== '') {
                $wrap['line_yeji_staff'] = $sales . ',' . $craft;
            } else {
                $wrap['line_yeji_staff'] = $sales !== '' ? $sales : $craft;
            }
        }
        unset($wrap);
    }

    /**
     * 门店/总后台订单列表：规格去「默认」；商品名/核销/充值等展示前加 [本人]/[朋友]
     */
    protected function formatStoreBackendOrderListCartRows(array &$orderRow): void
    {
        $orderTag = $this->resolveOrderListServiceObjectTag($orderRow);
        $orderType = (int)($orderRow['order_type'] ?? 0);

        if (isset($orderRow['_info']) && is_array($orderRow['_info'])) {
            foreach ($orderRow['_info'] as &$wrap) {
                if (!isset($wrap['cart_info']) || !is_array($wrap['cart_info'])) {
                    continue;
                }
                $ci = &$wrap['cart_info'];
                if (!isset($ci['productInfo']) || !is_array($ci['productInfo'])) {
                    $ci['productInfo'] = [];
                }
                $pi = &$ci['productInfo'];

                $pt = (int)($pi['product_type'] ?? $ci['product_type'] ?? 0);
                $lineSvc = trim((string)($ci['service_object'] ?? ''));
                $tag = $orderTag;
                if ($orderType !== 2 && $pt === 6 && ($lineSvc === '朋友' || $lineSvc === '本人')) {
                    $tag = '[' . $lineSvc . ']';
                }

                if (isset($pi['attrInfo']) && is_array($pi['attrInfo'])) {
                    $pi['attrInfo']['suk'] = $this->normalizeStoreOrderListAttrSuk($pi['attrInfo']['suk'] ?? '');
                }

                $name = (string)($pi['store_name'] ?? $pi['title'] ?? '');
                if ($name === '') {
                    continue;
                }
                $pi['store_name'] = $this->prefixOrderListServiceObjectTag($name, $tag);
                if (isset($pi['title']) && (string)$pi['title'] !== '') {
                    $pi['title'] = $pi['store_name'];
                }
            }
            unset($wrap);
        }

        if ($orderType === 2) {
            $this->attachWriteoffOrderListLinkInfo($orderRow);
            if (trim((string)($orderRow['link_name'] ?? '')) !== '') {
                $orderRow['link_name'] = $this->prefixOrderListServiceObjectTag((string)$orderRow['link_name'], $orderTag);
            }
        } elseif ($orderType === 1) {
            $orderRow['recharge_list_label'] = $this->prefixOrderListServiceObjectTag('充值订单', $orderTag);
        }
    }

    /**
     * 订单列表规格展示：无规格或占位「默认」时不返回给前端
     */
    protected function normalizeStoreOrderListAttrSuk($suk): string
    {
        // trim 默认不去掉全角空格 U+3000，易残留为「有规格」导致前端多出一个「 | 」
        $s = trim((string)$suk, " \t\n\r\0\x0B\xe3\x80\x80");
        if ($s === '' || $s === '默认' || $s === '默认规格') {
            return '';
        }
        if ($s !== '' && preg_match('/^\s+$/u', $s)) {
            return '';
        }
        return $s;
    }

    /**
     * 数据转换
     * @param array $data
     * @param bool $is_cart_info
     * @return array|null
     */
    public function tidyOrderList(array $data, bool $is_cart_info = true, bool $abridge = false)
    {
        if (!$data) {
            return $data;
        }
        /** @var StoreOrderCartInfoServices $services */
        $services = app()->make(StoreOrderCartInfoServices::class);
        /** @var SystemStoreServices $systemStoreServices */
        $systemStoreServices = app()->make(SystemStoreServices::class);
        $combinationOrderIds = [];
        foreach ($data as $row) {
            if (($row['pay_type'] ?? '') === PayServices::COMBINATION_PAY && !empty($row['id'])) {
                $combinationOrderIds[] = (int)$row['id'];
            }
        }
        $combinationPayLineMap = [];
        if ($combinationOrderIds) {
            $combinationRows = CombinationOrder::whereIn('order_id', array_unique($combinationOrderIds))
                ->field('order_id,name,price,pay_sub_type,type,active_pay')
                ->select()
                ->toArray();
            foreach ($combinationRows as $combinationRow) {
                $combinationPayLineMap[(int)$combinationRow['order_id']][] = $combinationRow;
            }
        }
        /** @var StoreDebtServices $debtServices */
        $debtServices = app()->make(StoreDebtServices::class);
        $pendingDebtMap = $debtServices->resolveOrderActivePendingAmountMap($data);
        $debtRepayOriginIds = [];
        foreach ($data as $row) {
            if (!empty($row['is_debt_repay']) && !empty($row['debt_repay_origin_order_id'])) {
                $debtRepayOriginIds[] = (int)$row['debt_repay_origin_order_id'];
            }
        }
        $debtRepayOriginOrderSnMap = [];
        if ($debtRepayOriginIds) {
            $debtRepayOriginOrderSnMap = StoreOrder::whereIn('id', array_unique($debtRepayOriginIds))
                ->column('order_id', 'id');
        }
        foreach ($data as &$item) {
            if ($is_cart_info) $item['_info'] = $services->getOrderCartInfoCache((int)$item['id']);
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['_refund_time'] = isset($item['refund_reason_time']) && $item['refund_reason_time'] ? date('Y-m-d H:i:s', $item['refund_reason_time']) : '';
            $item['_pay_time'] = isset($item['pay_time']) && $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
            [$plat_type, $plat_name] = $this->tidayOrderPlatType($item);
            $item['plat_type'] = $plat_type;
            $item['plat_name'] = $plat_name;
            [$pink_name, $color] = $this->tidyOrderType($item, $abridge);
            $item['pink_name'] = $pink_name;
            $item['color'] = $color;
            $item['debt_repay_tag'] = !empty($item['is_debt_repay']) ? ($abridge ? '补交' : '补交订单') : '';
            $originOrderId = (int)($item['debt_repay_origin_order_id'] ?? 0);
            $item['debt_repay_origin_order_sn'] = ($originOrderId > 0 && !empty($item['is_debt_repay']))
                ? (string)($debtRepayOriginOrderSnMap[$originOrderId] ?? '')
                : '';
            $item['pay_type_name'] = $this->tidyOrderPayType($item);
            $item['source_name'] = $this->getSourceName($item);
            $debtAmount = (float)($item['debt_amount'] ?? 0);
            $repaidDebt = (float)($item['repaid_debt_amount'] ?? 0);
            $item['debt_amount'] = $debtAmount;
            $item['repaid_debt_amount'] = $repaidDebt;
            $item['pending_debt_amount'] = (float)($pendingDebtMap[(int)($item['id'] ?? 0)] ?? 0);
            $item['combination_pay_lines'] = ($item['pay_type'] ?? '') === PayServices::COMBINATION_PAY
                ? ($combinationPayLineMap[(int)($item['id'] ?? 0)] ?? [])
                : [];
            $this->resolveOrderListGendanInfo($item);
            $status_name = ['status_name' => '', 'pics' => []];
            if ($item['is_del'] || $item['is_system_del']) {
                $status_name['status_name'] = '已删除';
                $item['_status'] = -1;
            } else if ($item['is_user_del'] && !$item['is_del'] && !$item['is_system_del']) {
                $status_name['status_name'] = '已取消';
                $item['_status'] = -1;
            } else if ($item['paid'] == 0 && $item['status'] == 0) {
                $status_name['status_name'] = '待付款';
                $item['_status'] = 1;//未支付
                //系统预设取消订单时间段
                $secs = $this->getOrderCancelTime((int)($item['type'] ?? 0));
                $item['stop_time'] = $secs * 3600 + strtotime($item['add_time']);
            } else if ($item['paid'] == 1 && $item['status'] == 4 && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
                $status_name['status_name'] = '部分发货';
                $item['_status'] = 8;//已支付 部分发货
            } else if ($item['paid'] == 1 && $item['refund_status'] == 2) {
                $status_name['status_name'] = '已退款';
                $item['_status'] = 7;//已支付 已退款
            } else if ($item['paid'] == 1 && $item['status'] == 5 && $item['refund_status'] == 0) {
                $status_name['status_name'] = $item['shipping_type'] == 2 ? '部分核销' : '部分收货';
                $item['_status'] = 12;//已支付 部分核销
            } else if ($item['paid'] == 1 && $item['refund_status'] == 1) {
                $item['_status'] = 3;//已支付 申请退款中
                $refundReasonTime = $item['refund_reason_time'] ? date('Y-m-d H:i', $item['refund_reason_time']) : '';
                $refundReasonWapImg = json_decode($item['refund_reason_wap_img'], true);
                $refundReasonWapImg = $refundReasonWapImg ? $refundReasonWapImg : [];
                $img = [];
                if (count($refundReasonWapImg)) {
                    foreach ($refundReasonWapImg as $itemImg) {
                        if (strlen(trim($itemImg)))
                            $img[] = $itemImg;
                    }
                }
                $status_name['status_name'] = <<<HTML
<b style="color:#f124c7">申请退款</b><br/>
<span>退款原因：{$item['refund_reason_wap']}</span><br/>
<span>备注说明：{$item['refund_reason_wap_explain']}</span><br/>
<span>退款时间：{$refundReasonTime}</span><br/>
<span>退款凭证：</span>
HTML;
                $status_name['pics'] = $img;
            } else if ($item['paid'] == 1 && $item['refund_status'] == 4) {
                $item['_status'] = 10;//拆单发货 已全部申请退款
                $status_name['status_name'] = '退款中';
            } else if ($item['paid'] == 1 && $item['status'] == 0 && in_array($item['shipping_type'], [1, 3, 4]) && $item['refund_status'] == 0) {
                $status_name['status_name'] = '未发货';
                if ($item['shipping_type'] == 3 && $item['store_delivery_type'] == 2) {
                    $status_name['status_name'] = '待配送';
                    $status_name['is_reissue_order'] = 0;
                    if($item['store_id']) {
                        $city_delivery_type = $systemStoreServices->value(['id'=>$item['store_id']],'city_delivery_type');
                        if($city_delivery_type > 0) $status_name['is_reissue_order'] = 1;
                    }
                }
                $item['_status'] = 2;//已支付 未发货
            } else if ($item['paid'] == 1 && in_array($item['status'], [0, 1]) && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '未核销';
                $item['_status'] = 11;//已支付 待核销
            } else if ($item['paid'] == 1 && in_array($item['status'], [1, 5]) && in_array($item['shipping_type'], [1, 3, 4]) && $item['refund_status'] == 0) {
                $status_name['status_name'] = '待收货';
                if ($item['shipping_type'] == 3 && $item['store_delivery_type'] == 2) {
                    $status_name['status_name'] = '配送中';
                }
                $item['_status'] = 4;//已支付 待收货
            } else if ($item['paid'] == 1 && $item['status'] == 2 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '待评价';
                $item['_status'] = 5;//已支付 待评价
            } else if ($item['paid'] == 1 && $item['status'] == 3 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '已完成';
                $item['_status'] = 6;//已支付 已完成
            } else if ($item['paid'] == 1 && $item['refund_status'] == 3) {
                $item['_status'] = 9;//拆单发货 部分申请退款
                $status_name['status_name'] = '部分退款';
            }
            $item['status_name'] = $status_name;
            if ($item['store_id'] == 0 && $item['clerk_id'] == 0 && !isset($item['clerk_name'])) {
                $item['clerk_name'] = '总平台';
            }
            $item['spread_nickname'] = '';
            //自购返佣
            if ($item['uid'] == $item['spread_uid']) {
                $item['spread_nickname'] = isset($item['spread_nickname']) ? $item['spread_nickname'] . '(自购)' : '';
            }
            $item['longitude'] = $item['latitude'] = '';
            //处理地址定位
            if (isset($item['user_location']) && $item['user_location']) {
                [$longitude, $latitude] = explode(' ', $item['user_location']);
                $item['longitude'] = $longitude;
                $item['latitude'] = $latitude;
            }
        }
        return $data;
    }

    /**
     * 处理订单金额
     * @param $where
     * @return array
     */
    public function getOrderPrice($where)
    {
//        $where['pid'] = 0;//子订单不统计
        $whereData = [];
        $price['today_count_sum'] = 0; //今日订单总数
        $price['count_sum'] = 0; //订单总数
        $price['pay_price'] = 0;//支付金额
        $price['today_pay_price'] = 0;//今日支付金额
        if ($where['status'] == '') {
            $whereData['paid'] = 1;
            $whereData['refund_status'] = [0, 3];
        }
        $not_pid = $where;
        unset($not_pid['pid']);
        $not_pid['not_pid'] = 1;
        $sumNumber = $this->dao->search($where + $whereData)->field([
            'count(id) as count_sum',
        ])->find();
        $price['count_sum'] = $sumNumber && $sumNumber['count_sum'] ? $sumNumber['count_sum'] : 0;
        $sumNumber = $this->dao->search($whereData + $where)->field([
            'sum(pay_price) as sum_pay_price',
        ])->find();
        $price['pay_price'] = $sumNumber && $sumNumber['sum_pay_price'] ? $sumNumber['sum_pay_price'] : 0;
        $where['time'] = 'today';
        $not_pid['time'] = 'today';
        $sumNumber = $this->dao->search($where + $whereData)->field([
            'count(id) as today_count_sum',
        ])->find();
        $price['today_count_sum'] = $sumNumber && $sumNumber['today_count_sum'] ? $sumNumber['today_count_sum'] : 0;
        $sumNumber = $this->dao->search($whereData + $where + ['paid' => 1])->field([
            'sum(pay_price) as today_pay_price',
        ])->find();
        $price['today_pay_price'] = $sumNumber && $sumNumber['today_pay_price'] ? $sumNumber['today_pay_price'] : 0;
        return $price;
    }

    /**
     * 获取订单列表页面统计数据
     * @param $where
     * @return array
     */
    public function getBadge($where)
    {
        $price = $this->getOrderPrice($where);
        return [
            [
                'name' => '订单数量',
                'field' => '件',
                'count' => $price['count_sum'],
                'className' => 'md-basket',
                'col' => 6
            ],
            [
                'name' => '订单金额',
                'field' => '元',
                'count' => $price['pay_price'],
                'className' => 'md-pricetags',
                'col' => 6
            ],
            [
                'name' => '今日订单数量',
                'field' => '件',
                'count' => $price['today_count_sum'],
                'className' => 'ios-chatbubbles',
                'col' => 6
            ],
            [
                'name' => '今日支付金额',
                'field' => '元',
                'count' => $price['today_pay_price'],
                'className' => 'ios-cash',
                'col' => 6
            ],
        ];
    }

    /**
     *
     * @param array $where
     * @return mixed
     */
    public function orderStoreCount(array $where)
    {
        $defaultWhere = ['time' => $where['time'], 'is_system_del' => 0, 'order_type' => $where['order_type'], 'pay_type' => $where['pay_type'], 'product_type' => $where['product_type'], 'real_name' => $where['real_name'] ?? '', 'search_order_id' => $where['search_order_id'] ?? '', 'search_product' => $where['search_product'] ?? '', 'search_user' => $where['search_user'] ?? ''];
        if (isset($where['store_id'])) {
            $defaultWhere['store_id'] = $where['store_id'];
        }
        if (isset($where['staff_id'])) {
            $defaultWhere['staff_id'] = $where['staff_id'];
        }
        if (isset($where['supplier_id'])) {
            $defaultWhere['supplier_id'] = $where['supplier_id'];
        }
        //全部订单
        $data['all'] = (string)$this->dao->count(['pid' => 0] + $defaultWhere);

        $count_where = ['pid' => 0, 'type' => $where['type']] + $defaultWhere;
        //未支付
        $data['unpaid'] = (string)$this->dao->count($count_where + ['status' => 0]);
        //未发货
        $data['unshipped'] = (string)$this->dao->count($count_where + ['status' => 1]);
        //部分发货
        $data['partshipped'] = (string)$this->dao->count($count_where + ['status' => 7]);
        //待收货
        $data['untake'] = (string)$this->dao->count($count_where + ['status' => 2]);
        //待核销
        $data['write_off'] = (string)$this->dao->count($count_where + ['status' => 5]);
        //已核销
        $data['write_offed'] = (string)$this->dao->count($count_where + ['status' => 6]);
        //待评价
        $data['unevaluate'] = (string)$this->dao->count($count_where + ['status' => 3]);
        //交易完成
        $data['complete'] = (string)$this->dao->count($count_where + ['status' => 4]);
        //退款中
//        $data['refunding'] = (string)$this->dao->count(['status' => -1, 'time' => $where['time'], 'is_system_del' => 0, 'type' => $where['type']]);
        //已退款
//        $data['refund'] = (string)$this->dao->count(['status' => -2, 'time' => $where['time'], 'is_system_del' => 0, 'type' => $where['type']]);
        //删除订单
        $data['del'] = (string)$this->dao->count($count_where + ['status' => -4]);
        return $data;
    }

    /**
     *
     * @param array $where
     * @return mixed
     */
    public function orderCount(array $where)
    {
        $default_where = [
            'pid' => [0, -1],
            'time' => $where['time'],
            'pay_time' => $where['pay_time'],
            'take_time' => $where['take_time'],
            'deliveryType' => $where['deliveryType'],
            'interval_price_min' => $where['interval_price_min'],
            'interval_price_max' => $where['interval_price_max'],
            'pay_type' => $where['pay_type'],
            'field_key' => $where['field_key'],
            'real_name' => $where['real_name'],
            'search_order_id' => $where['search_order_id'] ?? '',
            'search_product' => $where['search_product'] ?? '',
            'search_user' => $where['search_user'] ?? '',
            'is_system_del' => 0
        ];
        $count_where = ['type' => $where['type'] ?? 0, 'store_id' => $where['store_id'] ?? 0, 'supplier_id' => $where['supplier_id'] ?? 0];
//		if ($count_where['store_id'] || $count_where['supplier_id'] || (isset($where['plat_type']) && in_array($where['plat_type'], [1, 2]))) {
//			$default_where['pid'] = 0;
//		}
        //全部订单
        $data['all'] = (string)$this->dao->count($default_where + $count_where);
        //普通订单
        $data['general'] = (string)$this->dao->count(['type' => 0] + $default_where + $count_where);
        //平台订单
        $data['plat'] = (string)$this->dao->count(['plat_type' => 0, 'pid' => 0] + $default_where + $count_where);
        //门店订单
        $data['store'] = (string)$this->dao->count(['plat_type' => 1, 'pid' => 0] + $default_where + $count_where);
        //供应商订单
        $data['supplier'] = (string)$this->dao->count(['plat_type' => 2, 'pid' => 0] + $default_where + $count_where);
        //拼团订单
        $data['pink'] = (string)$this->dao->count(['type' => 3] + $default_where);
        //秒杀订单
        $data['seckill'] = (string)$this->dao->count(['type' => 1] + $default_where);
        //砍价订单
        $data['bargain'] = (string)$this->dao->count(['type' => 2] + $default_where);
        //预售订单
        $data['presale'] = (string)$this->dao->count(['type' => 8] + $default_where);

        $data['statusAll'] = $data['all'];
        if (trim($where['type'], ' ') !== '') {
            switch ($where['type']) {
                case 0:
                    $data['statusAll'] = $data['general'];
                    break;
                case 1:
                    $data['statusAll'] = $data['seckill'];
                    break;
                case 2:
                    $data['statusAll'] = $data['bargain'];
                    break;
                case 3:
                    $data['statusAll'] = $data['pink'];
                    break;
                case 4:
                    break;
                case 8:
                    $data['statusAll'] = $data['presale'];
                    break;
                default:
                    $data['statusAll'] = $data['all'];
            }
        }
        if ($where['store_id'] || $where['supplier_id'] || in_array($where['plat_type'], [0, 1, 2])) {
            $default_where['pid'] = 0;
        } elseif (!in_array($where['status'], [-1, -2, -3])) {
            $default_where['pid'] = [0, -1];
        }
        $count_where = ['type' => $where['type'] ?? 0, 'plat_type' => $where['plat_type']] + $default_where;
        //未支付
        $data['unpaid'] = (string)$this->dao->count($count_where + ['status' => 0]);
        //未发货
        $data['unshipped'] = (string)$this->dao->count($count_where + ['status' => 1]);
        //部分发货
        $data['partshipped'] = (string)$this->dao->count($count_where + ['status' => 7]);
        //待收货
        $data['untake'] = (string)$this->dao->count($count_where + ['status' => 2]);
        //待核销
        $data['write_off'] = (string)$this->dao->count($count_where + ['status' => 5]);
        //已核销
        $data['write_offed'] = (string)$this->dao->count($count_where + ['status' => 6]);
        //待评价
        $data['unevaluate'] = (string)$this->dao->count($count_where + ['status' => 3]);
        //交易完成
        $data['complete'] = (string)$this->dao->count($count_where + ['status' => 4]);
        //退款中
//        $data['refunding'] = (string)$this->dao->count(['status' => -1, 'time' => $where['time'], 'is_system_del' => 0, 'type' => $where['type']]);
        //已退款
//        $data['refund'] = (string)$this->dao->count(['status' => -2, 'time' => $where['time'], 'is_system_del' => 0, 'type' => $where['type']]);
        //删除订单
        $data['del'] = (string)$this->dao->count($count_where + ['status' => -4]);
        return $data;
    }

    /**
     * 创建修改订单表单
     * @param int $id
     * @return array
     */
    public function updateForm(int $id)
    {
        $product = $this->dao->get($id);
        if (!$product) {
            throw new ValidateException('Data does not exist!');
        }
        $f = [];
        $f[] = Form::input('order_id', '订单编号：', $product->getData('order_id'))->disabled(true);
        $f[] = Form::number('total_price', '商品总价：', (float)$product->getData('total_price'))->min(0)->disabled(true);
        $f[] = Form::number('total_postage', '原始邮费：', (float)$product->getData('total_postage'))->min(0)->disabled(true);
        $f[] = Form::number('pay_postage', '实际支付邮费：', (float)$product->getData('pay_postage') ?: 0)->disabled(true);
        $f[] = Form::number('pay_price', '实际支付金额：', (float)$product->getData('pay_price'))->min(0);
        $f[] = Form::number('gain_integral', '赠送积分：', (float)$product->getData('gain_integral') ?: 0)->precision(0);
        return create_form('修改订单', $f, $this->url('/order/update/' . $id), 'PUT');
    }

    /**
     * 订单单个商品改价
     * @param int $id
     * @param array $data
     * @param bool $is_postage
     * @return false|float
     */
    public function changeOrderProductPrice(int $id, array $data = [], bool $is_postage = false)
    {
        if (!$id || !$data) {
            return false;
        }
        $ids = array_column($data, 'id');
        $data = array_combine($ids, $data);
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfoList = $cartInfoServices->getColumn([['oid', '=', $id], ['cart_id', 'IN', $ids]], 'cart_id,cart_info', 'cart_id');
        $cartInfo = [];
        $pay_price = 0.00;
        foreach ($cartInfoList as $key => $cart) {
            $info = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
            if (!isset($data[$key]['true_price'])) continue;
            //改成单类商品，多件的支付金额
            $payPrice = $data[$key]['true_price'];
            $info['change_price'] = bcsub((string)$info['pay_price'], (string)$payPrice, 2);
            $info['pay_price'] = max($payPrice, 0);
            $info['truePrice'] = bcdiv((string)$payPrice, (string)$info['cart_num'], 2);
            $info['postage_price'] = $is_postage ? 0 : ($info['postage_price'] ?? 0);
            $pay_price = bcadd((string)$pay_price, (string)$payPrice, 2);
            $cartInfo[] = $info;
        }
        $cartInfoServices->updateCartInfo($id, $cartInfo);
        return (float)$pay_price;
    }

    /**
     * 修改订单
     * @param int $id
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function updateOrder(int $id, array $data)
    {
        $order = $this->dao->getOne(['id' => $id, 'is_del' => 0]);
        if (!$order) {
            throw new ValidateException('订单不存在或已删除');
        }
        if ($order['paid']) {
            throw new ValidateException('订单已支付');
        }
        $gain_integral = $data['gain_integral'] ?? 0;
        $is_postage = $data['is_postage'] ?? 0;
        if (isset($data['cart_info'])) {//单个订单商品改价模式
            $pay_price = $this->changeOrderProductPrice($id, $data['cart_info'], !!$is_postage);
            unset($data);
            if ($is_postage) {//免邮
                $data['pay_postage'] = 0;
                $data['pay_price'] = $pay_price;
            } else {
                $data['pay_price'] = bcadd((string)$pay_price, (string)$order['pay_postage'], 2);
            }
            $data['gain_integral'] = $gain_integral;
        }

        //限制改价金额两位小数
        $data['pay_price'] = sprintf("%.2f", $data['pay_price']);
        $pay_price = $order['pay_price'];
        if ($order['change_price']) {//已经改过一次价
            $pay_price = bcadd((string)$pay_price, (string)$order['change_price'], 2);
        }
        //记录改价优惠金额
        $data['change_price'] = (float)bcsub((string)$pay_price, (string)($data['pay_price'] ?? 0), 2);
        $res = $this->dao->update($id, $data);

        //改价提醒
        event('order.price', [$order, $data['pay_price']]);
        //记录订单状态
        OrderStatusJob::dispatch([$id, 'order_edit', [
            'change_message' => '商品总价为：' . $order['pay_price'] . ' 修改实际支付金额为：' . $data['pay_price'],
            'change_manager_type' => $this->getItem('change_manager_type'),
            'change_manager_id' => $this->getItem('change_manager_id')
        ]]);
        return true;
    }

    /**
     * 订单图表
     * @param $cycle
     * @return array
     */
    public function orderCharts($cycle)
    {
        $datalist = [];
        $where = [];
        $series1 = ['normal' => ['color' => [
            'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
            'colorStops' => [
                [
                    'offset' => 0,
                    'color' => '#2D8CF0'
                ],
                [
                    'offset' => 0.5,
                    'color' => '#2D8CF0'
                ],
                [
                    'offset' => 1,
                    'color' => '#2D8CF0'
                ]
            ]
        ]]
        ];
        $series2 = ['normal' => ['color' => [
            'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
            'colorStops' => [
                [
                    'offset' => 0,
                    'color' => '#0FC6C2'
                ],
                [
                    'offset' => 0.5,
                    'color' => '#0FC6C2'
                ],
                [
                    'offset' => 1,
                    'color' => '#0FC6C2'
                ]
            ]
        ]]
        ];
        $chartdata = [];
        $data = [];//临时
        $chartdata['yAxis']['maxnum'] = 0;//最大值数量
        $chartdata['yAxis']['maxprice'] = 0;//最大值金额
        switch ($cycle) {
            case 'thirtyday':
                //上期
                $datebefor = date('Y-m-d 00:00:00', strtotime('-59 day'));
                $dateafter = date('Y-m-d 23:59:59', strtotime('-29 day'));
                //当前
                $now_datebefor = date('Y-m-d 00:00:00', strtotime('-29 day'));
                $now_dateafter = date('Y-m-d 23:59:59');
                for ($i = -29; $i <= 0; $i++) {
                    $datalist[date('Y-m-d', strtotime($i . ' day'))] = date('Y-m-d', strtotime($i . ' day'));
                }
                $order_list = $this->dao->orderAddTimeList($where, [$now_datebefor, $now_dateafter], 'day');
                if (empty($order_list)) return ['yAxis' => [], 'legend' => [], 'xAxis' => [], 'serise' => [], 'pre_cycle' => [], 'cycle' => []];
                $order_list = array_combine(array_column($order_list, 'day'), $order_list);
                $cycle_list = [];
                foreach ($datalist as $dk => $dd) {
                    if (isset($order_list[$dk]) && !empty($order_list[$dd])) {
                        $cycle_list[$dd] = $order_list[$dd];
                    } else {
                        $cycle_list[$dd] = ['count' => 0, 'day' => $dd, 'price' => ''];
                    }
                }
                foreach ($cycle_list as $k => $v) {
                    $data['day'][] = $v['day'];
                    $data['count'][] = $v['count'];
                    $data['price'][] = round($v['price'], 2);
                    if ($chartdata['yAxis']['maxnum'] < $v['count'])
                        $chartdata['yAxis']['maxnum'] = $v['count'];//日最大订单数
                    if ($chartdata['yAxis']['maxprice'] < $v['price'])
                        $chartdata['yAxis']['maxprice'] = $v['price'];//日最大金额
                }
                $chartdata['legend'] = ['订单金额', '订单数'];//分类
                $chartdata['xAxis'] = $data['day'];//X轴值
                $chartdata['series'][] = ['name' => $chartdata['legend'][0], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][1], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['count'], 'yAxisIndex' => 1];//分类2值
                break;
            case 'week':
                $weekarray = [['周日'], ['周一'], ['周二'], ['周三'], ['周四'], ['周五'], ['周六']];
                $datebefor = date('Y-m-d 00:00:00', strtotime('-1 week Monday'));
                $dateafter = date('Y-m-d 23:59:59', strtotime('-1 week Sunday'));
                $order_list = $this->dao->orderAddTimeList($where, [$datebefor, $dateafter], 'week');
                //数据查询重新处理
                $new_order_list = array_combine(array_column($order_list, 'day'), $order_list);
                $now_datebefor = date('Y-m-d 00:00:00', (time() - ((date('w') == 0 ? 7 : date('w')) - 1) * 24 * 3600));
                $now_dateafter = date('Y-m-d 23:59:59', strtotime("+1 day"));
                $now_order_list = $this->dao->orderAddTimeList($where, [$now_datebefor, $now_dateafter], 'week');
                //数据查询重新处理 key 变为当前值
                $new_now_order_list = array_combine(array_column($now_order_list, 'day'), $now_order_list);
                foreach ($weekarray as $dk => $dd) {
                    if (isset($new_order_list[$dk]) && !empty($new_order_list[$dk])) {
                        $weekarray[$dk]['pre'] = $new_order_list[$dk];
                    } else {
                        $weekarray[$dk]['pre'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                    if (isset($new_now_order_list[$dk]) && !empty($new_now_order_list[$dk])) {
                        $weekarray[$dk]['now'] = $new_now_order_list[$dk];
                    } else {
                        $weekarray[$dk]['now'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                }
                foreach ($weekarray as $k => $v) {
                    $data['day'][] = $v[0];
                    $data['pre']['count'][] = $v['pre']['count'];
                    $data['pre']['price'][] = round($v['pre']['price'], 2);
                    $data['now']['count'][] = $v['now']['count'];
                    $data['now']['price'][] = round($v['now']['price'], 2);
                    if ($chartdata['yAxis']['maxnum'] < $v['pre']['count'] || $chartdata['yAxis']['maxnum'] < $v['now']['count']) {
                        $chartdata['yAxis']['maxnum'] = $v['pre']['count'] > $v['now']['count'] ? $v['pre']['count'] : $v['now']['count'];//日最大订单数
                    }
                    if ($chartdata['yAxis']['maxprice'] < $v['pre']['price'] || $chartdata['yAxis']['maxprice'] < $v['now']['price']) {
                        $chartdata['yAxis']['maxprice'] = $v['pre']['price'] > $v['now']['price'] ? $v['pre']['price'] : $v['now']['price'];//日最大金额
                    }
                }
                $chartdata['legend'] = ['上周金额', '本周金额', '上周订单数', '本周订单数'];//分类
                $chartdata['xAxis'] = $data['day'];//X轴值
                $chartdata['series'][] = ['name' => $chartdata['legend'][0], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['pre']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][1], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['now']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][2], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['pre']['count'], 'yAxisIndex' => 1];//分类2值
                $chartdata['series'][] = ['name' => $chartdata['legend'][3], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['now']['count'], 'yAxisIndex' => 1];//分类2值
                break;
            case 'month':
                $weekarray = ['01' => ['1'], '02' => ['2'], '03' => ['3'], '04' => ['4'], '05' => ['5'], '06' => ['6'], '07' => ['7'], '08' => ['8'], '09' => ['9'], '10' => ['10'], '11' => ['11'], '12' => ['12'], '13' => ['13'], '14' => ['14'], '15' => ['15'], '16' => ['16'], '17' => ['17'], '18' => ['18'], '19' => ['19'], '20' => ['20'], '21' => ['21'], '22' => ['22'], '23' => ['23'], '24' => ['24'], '25' => ['25'], '26' => ['26'], '27' => ['27'], '28' => ['28'], '29' => ['29'], '30' => ['30'], '31' => ['31']];
                $datebefor = date('Y-m-01 00:00:00', strtotime('-1 month'));
                $dateafter = date('Y-m-d 23:59:59', strtotime(date('Y-m-01')));
                $order_list = $this->dao->orderAddTimeList($where, [$datebefor, $dateafter], "month");
                //数据查询重新处理
                $new_order_list = array_combine(array_column($order_list, 'day'), $order_list);
                $now_datebefor = date('Y-m-01 00:00:00');
                $now_dateafter = date('Y-m-d', strtotime("+1 day"));
                $now_order_list = $this->dao->orderAddTimeList($where, [$now_datebefor, $now_dateafter], "month");
                //数据查询重新处理 key 变为当前值
                $new_now_order_list = array_combine(array_column($now_order_list, 'day'), $now_order_list);
                foreach ($weekarray as $dk => $dd) {
                    if (isset($new_order_list[$dk]) && !empty($new_order_list[$dk])) {
                        $weekarray[$dk]['pre'] = $new_order_list[$dk];
                    } else {
                        $weekarray[$dk]['pre'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                    if (isset($new_now_order_list[$dk]) && !empty($new_now_order_list[$dk])) {
                        $weekarray[$dk]['now'] = $new_now_order_list[$dk];
                    } else {
                        $weekarray[$dk]['now'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                }
                foreach ($weekarray as $k => $v) {
                    $data['day'][] = $v[0];
                    $data['pre']['count'][] = $v['pre']['count'];
                    $data['pre']['price'][] = round($v['pre']['price'], 2);
                    $data['now']['count'][] = $v['now']['count'];
                    $data['now']['price'][] = round($v['now']['price'], 2);
                    if ($chartdata['yAxis']['maxnum'] < $v['pre']['count'] || $chartdata['yAxis']['maxnum'] < $v['now']['count']) {
                        $chartdata['yAxis']['maxnum'] = $v['pre']['count'] > $v['now']['count'] ? $v['pre']['count'] : $v['now']['count'];//日最大订单数
                    }
                    if ($chartdata['yAxis']['maxprice'] < $v['pre']['price'] || $chartdata['yAxis']['maxprice'] < $v['now']['price']) {
                        $chartdata['yAxis']['maxprice'] = $v['pre']['price'] > $v['now']['price'] ? $v['pre']['price'] : $v['now']['price'];//日最大金额
                    }
                }
                $chartdata['legend'] = ['上月金额', '本月金额', '上月订单数', '本月订单数'];//分类
                $chartdata['xAxis'] = $data['day'];//X轴值
                $chartdata['series'][] = ['name' => $chartdata['legend'][0], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['pre']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][1], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['now']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][2], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['pre']['count'], 'yAxisIndex' => 1];//分类2值
                $chartdata['series'][] = ['name' => $chartdata['legend'][3], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['now']['count'], 'yAxisIndex' => 1];//分类2值
                break;
            case 'year':
                $weekarray = ['01' => ['一月'], '02' => ['二月'], '03' => ['三月'], '04' => ['四月'], '05' => ['五月'], '06' => ['六月'], '07' => ['七月'], '08' => ['八月'], '09' => ['九月'], '10' => ['十月'], '11' => ['十一月'], '12' => ['十二月']];
                $datebefor = date('Y-01-01 00:00:00', strtotime('-1 year'));
                $dateafter = date('Y-12-31 23:59:59', strtotime('-1 year'));
                $order_list = $this->dao->orderAddTimeList($where, [$datebefor, $dateafter], 'year');
                //数据查询重新处理
                $new_order_list = array_combine(array_column($order_list, 'day'), $order_list);
                $now_datebefor = date('Y-01-01 00:00:00');
                $now_dateafter = date('Y-12-31 23:59:59');
                $now_order_list = $this->dao->orderAddTimeList($where, [$now_datebefor, $now_dateafter], 'year');
                //数据查询重新处理 key 变为当前值
                $new_now_order_list = array_combine(array_column($now_order_list, 'day'), $now_order_list);
                $y = date('Y');
                foreach ($weekarray as $dk => $dd) {
                    $order_dk = $y . '-' . $dk;
                    if (isset($new_order_list[$order_dk]) && !empty($new_order_list[$order_dk])) {
                        $weekarray[$dk]['pre'] = $new_order_list[$order_dk];
                    } else {
                        $weekarray[$dk]['pre'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                    if (isset($new_now_order_list[$order_dk]) && !empty($new_now_order_list[$order_dk])) {
                        $weekarray[$dk]['now'] = $new_now_order_list[$order_dk];
                    } else {
                        $weekarray[$dk]['now'] = ['count' => 0, 'day' => $weekarray[$dk][0], 'price' => '0'];
                    }
                }
                foreach ($weekarray as $k => $v) {
                    $data['day'][] = $v[0];
                    $data['pre']['count'][] = $v['pre']['count'];
                    $data['pre']['price'][] = round($v['pre']['price'], 2);
                    $data['now']['count'][] = $v['now']['count'];
                    $data['now']['price'][] = round($v['now']['price'], 2);
                    if ($chartdata['yAxis']['maxnum'] < $v['pre']['count'] || $chartdata['yAxis']['maxnum'] < $v['now']['count']) {
                        $chartdata['yAxis']['maxnum'] = $v['pre']['count'] > $v['now']['count'] ? $v['pre']['count'] : $v['now']['count'];//日最大订单数
                    }
                    if ($chartdata['yAxis']['maxprice'] < $v['pre']['price'] || $chartdata['yAxis']['maxprice'] < $v['now']['price']) {
                        $chartdata['yAxis']['maxprice'] = $v['pre']['price'] > $v['now']['price'] ? $v['pre']['price'] : $v['now']['price'];//日最大金额
                    }
                }
                $chartdata['legend'] = ['去年金额', '今年金额', '去年订单数', '今年订单数'];//分类
                $chartdata['xAxis'] = $data['day'];//X轴值
                $chartdata['series'][] = ['name' => $chartdata['legend'][0], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['pre']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][1], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['now']['price']];//分类1值
                $chartdata['series'][] = ['name' => $chartdata['legend'][2], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['pre']['count'], 'yAxisIndex' => 1];//分类2值
                $chartdata['series'][] = ['name' => $chartdata['legend'][3], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['now']['count'], 'yAxisIndex' => 1];//分类2值
                break;
            default:
                break;
        }
        //统计总数上期
        $pre_total = $this->dao->preTotalFind($where, [$datebefor, $dateafter]);
        if ($pre_total) {
            $chartdata['pre_cycle']['count'] = [
                'data' => $pre_total['count'] ?: 0
            ];
            $chartdata['pre_cycle']['price'] = [
                'data' => $pre_total['price'] ?: 0
            ];
        }
        //统计总数
        $total = $this->dao->preTotalFind($where, [$now_datebefor, $now_dateafter]);
        if ($total) {
            $cha_count = intval($pre_total['count']) - intval($total['count']);
            $pre_total['count'] = $pre_total['count'] == 0 ? 1 : $pre_total['count'];
            $chartdata['cycle']['count'] = [
                'data' => $total['count'] ?: 0,
                'percent' => round((abs($cha_count) / intval($pre_total['count']) * 100), 2),
                'is_plus' => $cha_count > 0 ? -1 : ($cha_count == 0 ? 0 : 1)
            ];
            $cha_price = round($pre_total['price'], 2) - round($total['price'], 2);
            $pre_total['price'] = $pre_total['price'] == 0 ? 1 : $pre_total['price'];
            $chartdata['cycle']['price'] = [
                'data' => $total['price'] ?: 0,
                'percent' => round(abs($cha_price) / $pre_total['price'] * 100, 2),
                'is_plus' => $cha_price > 0 ? -1 : ($cha_price == 0 ? 0 : 1)
            ];
        }
        return $chartdata;
    }


    /**
     * 首页头部统计
     * @return array
     */
    public function homeStatics()
    {
        /** @var UserServices $uSercice */
        $uSercice = app()->make(UserServices::class);
        /** @var StoreProductLogServices $productLogServices */
        $productLogServices = app()->make(StoreProductLogServices::class);
        // 销售额
        //今日销售额
        $today_sales = $this->dao->totalSales('today');
        //昨日销售额
        $yesterday_sales = $this->dao->totalSales('yesterday');
        //日同比
        $sales_today_ratio = $this->countRate($today_sales, $yesterday_sales);
        //周销售额
//        //本周
//        $this_week_sales = $this->dao->totalSales('week');
//        //上周
//        $last_week_sales = $this->dao->totalSales('last week');
//        //周同比
//        $sales_week_ratio = $this->countRate($this_week_sales, $last_week_sales);
        //总销售额
        $total_sales = $this->dao->totalSales('month');
        $sales = [
            'today' => $today_sales,
            'yesterday' => $yesterday_sales,
            'today_ratio' => $sales_today_ratio,
//            'week' => $this_week_sales,
//            'last_week' => $last_week_sales,
//            'week_ratio' => $sales_week_ratio,
            'total' => $total_sales . '元',
            'date' => '今日'
        ];
        //用户访问量
        //今日访问量
        $today_visits = $productLogServices->count(['time' => 'today', 'type' => 'visit']);
        //昨日访问量
        $yesterday_visits = $productLogServices->count(['time' => 'yesterday', 'type' => 'visit']);
        //日同比
        $visits_today_ratio = $this->countRate($today_visits, $yesterday_visits);
//        //本周访问量
//        $this_week_visits = $productLogServices->count(['time' => 'week', 'type' => 'visit']);
//        //上周访问量
//        $last_week_visits = $productLogServices->count(['time' => 'last week', 'type' => 'visit']);
//        //周同比
//        $visits_week_ratio = $this->countRate($this_week_visits, $last_week_visits);
        //总访问量
        $total_visits = $productLogServices->count(['time' => 'month', 'type' => 'visit']);
        $visits = [
            'today' => $today_visits,
            'yesterday' => $yesterday_visits,
            'today_ratio' => $visits_today_ratio,
//            'week' => $this_week_visits,
//            'last_week' => $last_week_visits,
//            'week_ratio' => $visits_week_ratio,
            'total' => $total_visits . 'Pv',
            'date' => '今日'
        ];
        // 订单量
        //今日订单量
        $today_order = $this->dao->totalOrderCount('today');
        //昨日订单量
        $yesterday_order = $this->dao->totalOrderCount('yesterday');
        //订单日同比
        $order_today_ratio = $this->countRate($today_order, $yesterday_order);
//        //本周订单量
//        $this_week_order = $this->dao->totalOrderCount('week');
//        //上周订单量
//        $last_week_order = $this->dao->totalOrderCount('last week');
//        //订单周同比
//        $order_week_ratio = $this->countRate($this_week_order, $last_week_order);
        //总订单量
        $total_order = $this->dao->totalOrderCount('month');
        $order = [
            'today' => $today_order,
            'yesterday' => $yesterday_order,
            'today_ratio' => $order_today_ratio,
//            'week' => $this_week_order,
//            'last_week' => $last_week_order,
//            'week_ratio' => $order_week_ratio,
            'total' => $total_order . '单',
            'date' => '今日'
        ];
        // 用户
        //今日新增用户
        $today_user = $uSercice->totalUserCount('today');
        //昨日新增用户
        $yesterday_user = $uSercice->totalUserCount('yesterday');
        //新增用户日同比
        $user_today_ratio = $this->countRate($today_user, $yesterday_user);
//        //本周新增用户
//        $this_week_user = $uSercice->totalUserCount('week');
//        //上周新增用户
//        $last_week_user = $uSercice->totalUserCount('last week');
//        //新增用户周同比
//        $user_week_ratio = $this->countRate($this_week_user, $last_week_user);
        //本月新增用户
        $total_user = $uSercice->totalUserCount('month');
        $user = [
            'today' => $today_user,
            'yesterday' => $yesterday_user,
            'today_ratio' => $user_today_ratio,
//            'week' => $this_week_user,
//            'last_week' => $last_week_user,
//            'week_ratio' => $user_week_ratio,
            'total' => $total_user . '人',
            'date' => '今日'
        ];
        $info = array_values(compact('sales', 'visits', 'order', 'user'));
        $info[0]['title'] = '销售额';
        $info[1]['title'] = '用户访问量';
        $info[2]['title'] = '订单量';
        $info[3]['title'] = '新增用户';
        $info[0]['total_name'] = '本月销售额';
        $info[1]['total_name'] = '本月访问量';
        $info[2]['total_name'] = '本月订单量';
        $info[3]['total_name'] = '本月新增用户';
        return $info;
    }

    /**
     * 打印订单
     * @param int $id
     * @param bool $isTable
     * @return bool
     */
    public function orderPrint(int $id, int $type = -1, int $relation_id = -1, int $print_event = -1)
    {
        $order = $this->dao->get($id);
        if (!$order) {
            throw new ValidateException('订单信息不存在!');
        }
        $order = $order->toArray();
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $product = $cartServices->getCartInfoPrintProduct((int)$order['id']);
        if (!$product) {
            throw new ValidateException('订单商品获取失败,无法打印!');
        }
        if ($type == -1 && $relation_id == -1) {//取订单属于那一端
            $type = $relation_id = 0;
            if (isset($order['store_id']) && $order['store_id']) {
                $store_id = (int)$order['store_id'];
                $type = 1;
                $relation_id = $store_id;
            } elseif (isset($order['supplier_id']) && $order['supplier_id']) {
                $supplier_id = (int)$order['supplier_id'];
                $type = 2;
                $relation_id = $supplier_id;
            }
        }
        try {
            $config = [
                'name' => sys_config('site_name'),
                'orderInfo' => $order,
                'product' => $product
            ];
            app()->make(SystemPrinterServices::class)->startPrint($config, $print_event, $type, $relation_id, 1);
            return true;
        } catch (\Exception $e) {
            \think\facade\Log::error('小票打印失败，原因：' . $e->getMessage());
            throw new ValidateException('小票打印失败，原因：' . $e->getMessage());
        }
    }

    /**
     * 获取订单确认数据
     * @param array $user
     * @param $cartId
     * @param bool $new
     * @param int $addressId
     * @param int $shipping_type
     * @param int $store_id
     * @param int $coupon_id
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderConfirmData(array $user, $cartId, bool $new, int $addressId, int $shipping_type = 1, int $store_id = 0, int $coupon_id = 0, int $is_store_delivery_type = 0)
    {
        $addr = $data = [];
        $uid = (int)$user['uid'];
        /** @var UserAddressServices $addressServices */
        $addressServices = app()->make(UserAddressServices::class);
        if ($addressId) {
            $addr = $addressServices->getAdderssCache($addressId);
        }
        //没传地址id或地址已删除未找到 ||获取默认地址
        if (!$addr) {
            $addr = $addressServices->getUserDefaultAddressCache($uid);
        }
        $data['upgrade_addr'] = 0;
        if ($addr) {
            $addr = is_object($addr) ? $addr->toArray() : $addr;
            if (isset($addr['upgrade']) && $addr['upgrade'] == 0) {
                $data['upgrade_addr'] = 1;
            }
        } else {
            $addr = [];
        }
        if ($store_id) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $store = $storeServices->getStoreInfo($store_id);
            if ($shipping_type == 3 && $is_store_delivery_type == 2) {
                if (!in_array(3, $store['delivery_type'])) {
                    throw new ValidateException('该门店暂未开启同城配送！');
                }
				$addr['isCityStoreDeliveryScope'] = $storeServices->checkCityStoreDeliveryScope($uid, $store_id, $addr);
				if (!$addr['isCityStoreDeliveryScope']) {
					throw new ValidateException('地址有误、商家自配未开启、不在配送范围内!');
				}
            }
        }
        /** @var StoreCartServices $cartServices */
        $cartServices = app()->make(StoreCartServices::class);
        //获取购物车信息
        $cartGroup = $cartServices->getUserProductCartListV1($uid, $cartId, $new, $addr, $shipping_type, $store_id, $coupon_id, false, $is_store_delivery_type);
        $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
        $data['storeFreePostage'] = $storeFreePostage;
        $validCartInfo = $cartGroup['valid'];
        $validProductIds = [];
        $gainIntegral = 0;
        if ($validCartInfo) {
            foreach ($validCartInfo as $cart) {
                if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {//赠品 卡项关联跳过
                    continue;
                }
                //订单商品营销设置：赠送积分
                $cartInfoGainIntegral = isset($cart['productInfo']['give_integral']) ? bcmul((string)$cart['cart_num'], (string)$cart['productInfo']['give_integral'], 0) : 0;
                $gainIntegral = bcadd((string)$gainIntegral, (string)$cartInfoGainIntegral, 0);
                $validProductIds[] = $cart['productInfo']['id'] ?? 0;
            }
        }
        $giveCartList = $cartGroup['giveCartList'] ?? [];
        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        if ($shipping_type == 3 && $is_store_delivery_type == 2 && $store_id) {
            $priceGroup = $computedServices->getOrderPriceShippingCost($uid, $store_id, $validCartInfo, $addr);
        } else {
            $priceGroup = $computedServices->getOrderPriceGroup($uid, $validCartInfo, $addr, $storeFreePostage);
        }
        $priceGroup['couponPrice'] = $cartGroup['couponPrice'] ?? 0;
        $priceGroup['useCoupon'] = $cartGroup['useCoupon'] ?? [];
        $priceGroup['firstOrderPrice'] = $cartGroup['firstOrderPrice'] ?? 0;
        $validCartInfo = array_merge($priceGroup['cartInfo'] ?? $validCartInfo, $giveCartList);
        $other = [
            'offlinePostage' => sys_config('offline_postage'),
            'integralRatio' => sys_config('integral_ratio'),
            'give_integral' => $cartGroup['giveIntegral'] ?? 0,
            'give_coupon' => $cartGroup['giveCoupon'] ?? [],
            'give_product' => $cartGroup['giveProduct'],
            'promotions' => $cartGroup['promotions']
        ];
        $deduction = $cartGroup['deduction'];
        $data['product_type'] = $deduction['product_type'] ?? 0;
        $data['valid_count'] = count($validCartInfo);
        $data['addressInfo'] = $addr;
        $data['type'] = $deduction['type'] ?? 0;
        $data['activity_id'] = $deduction['activity_id'] ?? 0;
        $data['seckill_id'] = $deduction['type'] == 1 ? $deduction['activity_id'] : 0;
        $data['bargain_id'] = $deduction['type'] == 2 ? $deduction['activity_id'] : 0;
        $data['combination_id'] = $deduction['type'] == 3 ? $deduction['activity_id'] : 0;
        $data['storeIntegralId'] = $deduction['type'] == 4 ? $deduction['activity_id'] : 0;
        $data['discount_id'] = $deduction['type'] == 5 ? $deduction['activity_id'] : 0;
        $data['luck_record_id'] = $deduction['type'] == 8 ? $deduction['activity_id'] : 0;
        $data['newcomer_id'] = $deduction['type'] == 7 ? $deduction['activity_id'] : 0;
        $data['collate_code_id'] = $deduction['type'] == 9 || $deduction['type'] == 10 ? $deduction['collate_code_id'] : 0;
        $data['deduction'] = in_array($deduction['product_type'], [1, 2]) || $deduction['activity_id'] > 0;
        $data['cartInfo'] = array_merge($cartGroup['cartInfo'], $giveCartList);
        // $data['giveCartInfo'] = $giveCartList;
        $data['custom_form'] = [];
        $data['custom_form_title'] = '';
        $data['system_form_type'] = $cartGroup['cartInfo'][0]['productInfo']['system_form_type'] ?? 1;
        if (isset($cartGroup['cartInfo'][0]['productInfo']['system_form_id']) && $cartGroup['cartInfo'][0]['productInfo']['system_form_id']) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $formInfo = $systemFormServices->get(['id' => $cartGroup['cartInfo'][0]['productInfo']['system_form_id']], ['id', 'name', 'value']);
            if ($formInfo) {
                $data['custom_form'] = is_string($formInfo['value']) ? json_decode($formInfo['value'], true) : $formInfo['value'];
                $data['custom_form_title'] = $formInfo['name'];
            }
        }
        $reservationInfo = ['cart_num' => $validCartInfo[0]['cart_num'] ?? 1, 'reservation_type' => 2, 'service_staff_id' => 0, 'real_name' => '', 'user_phone' => '', 'reservation_time' => '', 'reservation_time_id' => 0, 'reservation_start' => '', 'reservation_end' => '', 'service_duration_minutes' => 0, 'reservation_show_time' => ''];
        if ($data['product_type'] == 6) {
            $reservationTime = $validCartInfo[0]['reservation_time'] ?? '';
            $reservationTimeId = (int)($validCartInfo[0]['reservation_time_id'] ?? 0);
            $reservationStart = trim((string)($validCartInfo[0]['reservation_start'] ?? ''));
            $reservationEnd = trim((string)($validCartInfo[0]['reservation_end'] ?? ''));
            $reservationShowTime = $validCartInfo[0]['reservation_show_time'] ?? '';
            if (!$reservationShowTime && $reservationStart) {
                $reservationShowTime = $reservationStart . ($reservationEnd ? '-' . $reservationEnd : '');
            }
            if ($reservationTimeId && !$reservationStart) {
                /** @var StoreProductReservationTimeServices $reservationTimeServices */
                $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
                $reservationTimeInfo = $reservationTimeServices->get(['id' => $reservationTimeId]);
                if (!$reservationTimeInfo) {
                    throw new ValidateException('选择的时段无效');
                }
                $reservationShowTime = $reservationTimeInfo['show_time'] ?? $reservationShowTime;
            }
            $reservationInfo = [
                'cart_num' => $validCartInfo[0]['cart_num'] ?? 1,
                'reservation_type' => $validCartInfo[0]['reservation_type'] ?? 2,
                'service_staff_id' => $validCartInfo[0]['service_staff_id'] ?? 0,
                'real_name' => $validCartInfo[0]['real_name'] ?? '',
                'user_phone' => $validCartInfo[0]['user_phone'] ?? '',
                'reservation_time' => $reservationTime,
                'reservation_time_id' => $reservationTimeId,
                'reservation_start' => $reservationStart,
                'reservation_end' => $reservationEnd,
                'service_duration_minutes' => (int)($validCartInfo[0]['service_duration_minutes'] ?? 0),
                'reservation_show_time' => $reservationShowTime,
                'addon_items' => $validCartInfo[0]['addon_items'] ?? [],
                'sync_all' => $validCartInfo[0]['sync_all'] ?? [],
            ];
            if (!is_array($reservationInfo['addon_items'])) {
                $reservationInfo['addon_items'] = [];
            }
            if (!is_array($reservationInfo['sync_all'])) {
                $reservationInfo['sync_all'] = [];
            }
        }
        $data['reservationInfo'] = $other['reservationInfo'] = $reservationInfo;
        //赠送积分：优惠活动赠送+订单商品营销设置赠送+下单赠送
        $order_integral = $computedServices->getGiveIntegral($uid, (string)$priceGroup['totalPrice']);
        $data['give_integral'] = (int)$other['give_integral'] + (int)$gainIntegral + (int)$order_integral;
        //赠送优惠券：优惠活动赠送+订单商品营销设置赠送
        $data['give_coupon'] = [];
        $giveCouponIds = $other['give_coupon'] ?? [];
        /** @var StoreProductCouponServices $storeProductCouponServices */
        $storeProductCouponServices = app()->make(StoreProductCouponServices::class);
        $giveCouponIds = array_unique(array_merge($giveCouponIds, $storeProductCouponServices->getCouponIdsByProduct($validProductIds)));
        if ($giveCouponIds) {
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            $data['give_coupon'] = $couponIssueService->getColumn([['id', 'IN', $giveCouponIds]], 'id,coupon_title');
        }
        $data['priceGroup'] = $priceGroup;
        $data['orderKey'] = $this->cacheOrderInfo($uid, $validCartInfo, $priceGroup, $other, $addr, $cartGroup['invalid'] ?? [], $deduction);

        $userInfo = ['uid' => $user['uid'], 'nickname' => $user['nickname'], 'phone' => $user['phone'], 'now_money' => $user['now_money'], 'integral' => $user['integral']];
        $userInfo['vip'] = isset($priceGroup['vipPrice']) && $priceGroup['vipPrice'] > 0;
        $userInfo['vip_id'] = 0;
        $userInfo['discount'] = 0;
        //用户等级是否开启
        if (sys_config('member_func_status', 1)) {
            /** @var UserLevelServices $levelServices */
            $levelServices = app()->make(UserLevelServices::class);
            $userLevel = $levelServices->getUerLevelInfoByUid($uid);
            if ($userInfo['vip'] || $userLevel) {
                $userInfo['vip'] = true;
                $userInfo['vip_id'] = $userLevel['id'] ?? 0;
                $userInfo['discount'] = $userLevel['discount'] ?? 0;
            }
        }
        $userInfo['real_name'] = isset($user['real_name']) && $user['real_name'] ? $user['real_name'] : $user['nickname'];
        $userInfo['record_pone'] = isset($user['record_phone']) && $user['record_phone'] ? $user['record_phone'] : $user['phone'];
        /** @var UserBillServices $userBill */
        $userBill = app()->make(UserBillServices::class);
        $user_integral = $userBill->countIntegralBalance((int)$userInfo['integral'], (int)$uid);
        $userInfo['integral'] = (int)max($user_integral, 0);
        $data['userInfo'] = $userInfo;

        $data['offlinePostage'] = $other['offlinePostage'];
        $data['integralRatio'] = $other['integralRatio'];
        $data['integral_ratio_status'] = (int)sys_config('integral_ratio_status', 1);
        $data['store_func_status'] = (int)(sys_config('store_func_status', 1));//门店是否开启
        $data['store_self_mention'] = false;//门店核销
        $data['store_delivery_status'] = false;//门店配送
        if ($data['store_func_status']) {
            //门店核销是否开启
            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            $data['store_self_mention'] = sys_config('store_self_mention') && $systemStoreServices->count(['type' => 0, 'delivery_type' => 2]);
            $data['store_delivery_status'] = !!$systemStoreServices->count(['type' => 0, 'delivery_type' => 3]);
        }
        $data['store_func_status'] = $data['store_func_status'] && ($data['store_self_mention'] || $data['store_delivery_status']);

        $data['system_store'] = [];//门店信息
        /** @var UserInvoiceServices $userInvoice */
        $userInvoice = app()->make(UserInvoiceServices::class);
        $invoice_func = $userInvoice->invoiceFuncStatus();
        $data['invoice_func'] = $invoice_func['invoice_func'];
        $data['special_invoice'] = $invoice_func['special_invoice'];
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $data['svip_status'] = $svip_status = $userServices->checkUserIsSvip($uid);
        $svip_price = 0.00;
        //开启付费会员 且用户不是付费会员 //计算 开通付费会员节省金额
        if (sys_config('member_card_status', 1) && !$svip_status) {
            $payPrice = $cartServices->getPayPrice((string)$priceGroup['totalPrice'], (string)($priceGroup['firstOrderPrice'] ?? 0));
            [$vipPayPrice, $payPostage, $storePostageDiscount] = $cartServices->computeUserVipCart($uid, $cartId, $shipping_type, $new, $store_id, false, $coupon_id, $addr, $is_store_delivery_type);
            $svip_price = (float)max(bcadd((string)bcsub((string)$payPrice, (string)$vipPayPrice, 2), (string)$storePostageDiscount, 2), 0);
        }
        $data['svip_price'] = $svip_price;
        $data['usable_integral'] = $userInfo['integral'];
        $data['integral_open'] = sys_config('integral_ratio', 0) > 0;
        return $data;
    }

    /**
     * 缓存订单信息
     * @param int $uid
     * @param array $cartInfo
     * @param array $priceGroup
     * @param array $other
     * @param array $addr
     * @param array $invalidCartInfo
     * @param array $deduction
     * @param int $cacheTime
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function cacheOrderInfo(int $uid, array $cartInfo, array $priceGroup, array $other = [], array $addr = [], array $invalidCartInfo = [], array $deduction = [], int $cacheTime = 600)
    {
        $key = md5($this->getUniqueId((string)$uid) . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8));
        CacheService::redisHandler()->set('user_order_' . $uid . $key, compact('cartInfo', 'priceGroup', 'other', 'addr', 'invalidCartInfo', 'deduction'), $cacheTime);
        return $key;
    }

    /**
     * 获取订单缓存信息
     * @param int $uid
     * @param string $key
     * @return |null
     */
    public function getCacheOrderInfo(int $uid, string $key)
    {
        $cacheName = 'user_order_' . $uid . $key;
        if (!CacheService::redisHandler()->has($cacheName)) return null;
        return CacheService::redisHandler()->get($cacheName);
    }

    /**
     * 获取用户购买活动产品的次数
     * @param $uid
     * @param $seckill_id
     * @return int
     */
    public function activityProductCount(array $where)
    {
        return $this->dao->count($where);
    }

    /**
     * 获取拼团的订单id
     * @param int $pid
     * @param int $uid
     * @return mixed
     */
    public function getStoreIdPink(int $pid, int $uid)
    {
        return $this->dao->value(['uid' => $uid, 'pink_id' => $pid, 'is_del' => 0], 'order_id');
    }

    /**
     * 判断当前订单中是否有拼团
     * @param int $pid
     * @param int $uid
     * @return int
     */
    public function getIsOrderPink($pid = 0, $uid = 0)
    {
        return $this->dao->count(['uid' => $uid, 'pink_id' => $pid, 'refund_status' => 0, 'is_del' => 0]);
    }

    /**
     * 判断支付方式是否开启
     * @param $payType
     * @return bool
     */
    public function checkPaytype(string $payType)
    {
        $res = false;
        switch ($payType) {
            case PayServices::WEIXIN_PAY:
                $res = (bool)sys_config('pay_weixin_open');
                break;
            case PayServices::YUE_PAY:
                $res = sys_config('balance_func_status') && sys_config('yue_pay_status') == 1;
                break;
            case 'offline':
                $res = sys_config('offline_pay_status') == 1;
                break;
            case PayServices::ALIAPY_PAY:
                $res = sys_config('ali_pay_status') == 1;
                break;
        }
        return $res;
    }

    /**
     * 修改支付方式为线下支付
     * @param array $orderInfo
     * @return bool|\mohe\basic\BaseModel
     */
    public function setOrderTypePayOffline(array $orderInfo)
    {
        $res = $this->dao->update($orderInfo['order_id'], ['pay_type' => 'offline'], 'order_id');
        if ($res) {
            //支付成功后向管理员发送模板消息
            OrderJob::dispatchDo('sendServicesAndTemplate', [$orderInfo]);
        }
        return $res;
    }

    /**
     * 删除订单
     * @param $uni
     * @param $uid
     * @return bool
     */
    public function removeOrder(string $uni, int $uid)
    {
        $order = $this->getUserOrderDetail($uni, $uid);
        if (!$order) {
            throw new ValidateException('订单不存在!');
        }
        $order = $this->tidyOrder($order);
        if ($order['_status']['_type'] != 0 && $order['_status']['_type'] != -2 && $order['_status']['_type'] != 4)
            throw new ValidateException('该订单无法删除!');
        $order->is_del = 1;
        if ($order->save()) {
            //未支付和已退款的状态下才可以退积分退库存退优惠券
            if ($order['_status']['_type'] == 0 || $order['_status']['_type'] == -2) {
                $this->cancelOrder((int)$order['id'], $uid, '用户删除订单', 'del');
            }
            //记录订单状态
            OrderStatusJob::dispatch([$order['id'], 'remove_order', ['change_message' => '用户删除订单', 'change_manager_type' => 'user']]);
            return true;
        } else
            throw new ValidateException('订单删除失败!');
    }

    /**
     * 取消订单
     * @param int $id
     * @param int $uid
     * @param string $mark
     * @param string $type
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cancelOrder(int $id, int $uid = 0, string $mark = '用户取消订单', string $type = 'cancel')
    {
        $order = $this->dao->get($id);
        if (!$order || ($uid && $order['uid'] != $uid)) {
            throw new ValidateException('没有查到此订单');
        }
//		if ($order['paid'] && $type == 'cancel') {
//			throw new ValidateException('订单已经支付无法删除');
//		}
        if ($order['is_del']) {
            throw new ValidateException('订单已经删除');
        }

        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);
        $this->transaction(function () use ($refundServices, $order, $mark) {
            $orderUpdate = ['is_del' => 1, 'mark' => $mark];
            if ($order['type'] == 8 && $order['activity_id']) {//抽奖
                /** @var LuckLotteryRecordServices $lotteryRecordServices */
                $lotteryRecordServices = app()->make(LuckLotteryRecordServices::class);
                $data['oid'] = 0;
                $data['is_receive'] = 0;
                $data['receive_time'] = 0;
                $lotteryRecordServices->update($order['activity_id'], $data, 'id');
            }
            if ($order['type'] == 12) {//预约单
                /** @var StoreReservationOrderServices $reservationOrderService */
                $reservationOrderService = app()->make(StoreReservationOrderServices::class);
                $reservationOrderService->delete(['oid' => $order['id']]);
                //预约状态改成已完成
                $orderUpdate['reservation_status'] = 2;
            }
            //回退积分和优惠卷
            $res = $refundServices->integralAndCouponBack($order);
            //回退库存和销量
            $res = $res && $refundServices->regressionStock($order);
            //修改订单状态
            $res = $res && $this->dao->update($order['id'], $orderUpdate);
            if (!$res) {
                throw new ValidateException('订单号' . $order['order_id'] . ',取消订单失败');
            }
        });
        //订单取消事件
        event('order.cancel', [$order]);
        return true;
    }

    /**
     * 交易取消删除
     * @param array $order
     * @return mixed
     */
    public function cancel_user_del(array $order)
    {
        if ($order['paid']) {
            throw new ValidateException('订单已经支付无法取消');
        }
        $orderUpdate = ['is_user_del' => 1, 'mark' => '订单取消'];
        if ($order['type'] == 12) {//预约单
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->delete(['oid' => $order['id']]);
            //预约状态改成已完成
            $orderUpdate['reservation_status'] = 2;
        }
        return $this->dao->update($order['id'], $orderUpdate);
    }

    /**
     * 判断订单完成
     * @param StoreProductReplyServices $replyServices
     * @param array $uniqueList
     * @param $oid
     * @return mixed
     */
    public function checkOrderOver($replyServices, array $uniqueList, $oid)
    {
        //订单商品全部评价完成
        $replyCount = $replyServices->count(['sku_unique' => $uniqueList, 'oid' => $oid]);
        if ($replyCount == count($uniqueList)) {
            $res = $this->dao->update($oid, ['status' => '3']);
            if (!$res) throw new ValidateException('评价后置操作失败!');
            //记录订单状态
            OrderStatusJob::dispatch([$oid, 'check_order_over', ['change_message' => '用户评价', 'change_manager_type' => 'user']]);
        }
    }

    /**
     * 某个用户订单
     * @param int $uid
     * @param UserServices $userServices
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserOrderList(int $uid)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserWithTrashedInfo($uid);
        if (!$user) {
            throw  new ValidateException('数据不存在');
        }
        [$page, $limit] = $this->getPageValue();
        $where = ['uid' => $uid, 'pid' => [0, -1], 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0];
        $list = $this->dao->getStairOrderList($where, 'order_type,link_id,store_id,id,order_id,real_name,total_num,total_price,pay_price,FROM_UNIXTIME(pay_time,"%Y-%m-%d") as pay_time,paid,pay_type,type,activity_id,activity_append', $page, $limit);
        $yejiTypes=[2,1,3]; //购卡，充值，消耗
        foreach ($list as &$nv){
            $theType=$yejiTypes[$nv['order_type']] ?? 2;
            $linkId=$nv['id'];
            if($theType != 2){
                $linkId=$nv['link_id'];
            }
            if($nv['order_type'] == 0){
                //普通订单
                $staffs=StaffYeji::where("order_id",$linkId)->select();
            }else{
                $staffs=StaffYeji::where("type",$theType)->where("link_id",$linkId)->select();
            };
            $staffsAttr=[];
            $xiaoshou=[];
            $shouyi=[];
            foreach ($staffs as $staffOne){
                $dian="轮";
                if($staffOne['is_dian'] == 1){
                    $dian="点";
                }
                if($nv['order_type'] == 2){
                    $staffsAttr[]=$staffOne['staff_name']."($dian)";
                }else{
                    if($staffOne['type'] == 3){
                        $shouyi[]=$staffOne['staff_name']."($dian)";
                    }else{
                        $xiaoshou[]=$staffOne['staff_name'];
                    }
                }
            }
            if($nv['order_type'] == 2) {
                $nv['yeji_staff'] = implode(",", $staffsAttr);
            }
            if($nv['order_type'] == 1) {
                $nv['yeji_staff'] = implode(",", $xiaoshou);
            }
            if($nv['order_type'] == 0) {
                $nv['yeji_staff'] = implode(",", $xiaoshou);
                if(!empty($nv['yeji_staff'])){
                    $nv['yeji_staff']=$nv['yeji_staff'].",".implode(",", $shouyi);
                }else{
                    $nv['yeji_staff']=implode(",", $shouyi);
                }
            }
            $ids=StoreOrderCartInfo::where("oid",$nv['id'])->column("product_id");
            $nv['product_names']=StoreProduct::whereIn("id",$ids)->column("store_name");
            $nv['store_name']=SystemStore::where("id",$nv['store_id'])->value("name");
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 获取推广订单列表
     * @param int $uid
     * @param $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserStairOrderList(int $uid, $where)
    {
        $where_data = [];
        if (isset($where['type'])) {
            switch ((int)$where['type']) {
                case 1:
                    $where_data['spread_uid'] = $uid;
                    break;
                case 2:
                    $where_data['spread_two_uid'] = $uid;
                    break;
                default:
                    $where_data['spread_or_uid'] = $uid;
                    break;
            }
        }
        if (isset($where['data']) && $where['data']) {
            $where_data['time'] = $where['data'];
        }
        if (isset($where['order_id']) && $where['order_id']) {
            $where_data['order_id'] = $where['order_id'];
        }
        if (isset($where['nickname']) && $where['nickname']) {
            $where_data['real_name'] = $where['nickname'];
        }
        //推广订单只显示支付过并且未退款的订单
        $where_data['pid'] = 0;
        $where_data['paid'] = 1;
        $where_data['refund_status'] = 0;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getStairOrderList($where_data, '*', $page, $limit);
        $count = $this->dao->count($where_data);
        return compact('list', 'count');
    }

    /**
     * 订单导出
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getExportList(array $where, array $with = [], int $limit = 0)
    {
        if ($limit) {
            [$page] = $this->getPageValue();
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->search($where)->with($with)->page($page, $limit)->order('add_time DESC,id DESC')->select()->toArray();
        if ($list) {
            $supplierIds = array_column($list, 'supplier_id');
            $storeIds = array_column($list, 'store_id');
            $storeIdsTwo = array_column($list, 'kua_store');
            $storeIds=array_merge($storeIds,$storeIdsTwo);
            $supplierList = $storeList = [];
            if ($supplierIds) {
                /** @var SystemSupplierServices $supplierServices */
                $supplierServices = app()->make(SystemSupplierServices::class);
                $supplierList = $supplierServices->getColumn([['id', 'in', $supplierIds], ['is_del', '=', 0]], 'id,supplier_name', 'id');
            }
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            /** @var  $userServices */
            $userServices = app()->make(UserServices::class);
            $uids = array_unique(array_column($list, 'uid'));
            $userInfos = $userServices->getColumn([['uid', 'IN', $uids]], 'uid,phone,nickname,sex,level', 'uid');
            /** @var StoreOrderCartInfoServices $orderCart */
            $orderCart = app()->make(StoreOrderCartInfoServices::class);
            $orderTypes=['普通订单','充值订单','核销订单'];
            $yejiTypes=[2,1,3]; //购卡，充值，消耗
            foreach ($list as &$item) {
                //销售
                $theType=$yejiTypes[$item['order_type']] ?? 2;
                $linkId=$item['id'];
                if($theType != 2){
                    $linkId=$item['link_id'];
                }
                if($item['order_type'] == 0){
                    //普通订单
                    $staffs=StaffYeji::where("order_id",$linkId)->select();
                }else{
                    $staffs=StaffYeji::where("type",$theType)->where("link_id",$linkId)->select();
                }
                $staffsAttr=[];
                $xiaoshou=[];
                $shouyi=[];
                foreach ($staffs as $staffOne){
                    $dian="轮";
                    if($staffOne['is_dian'] == 1){
                        $dian="点";
                    }
                    if($item['order_type'] == 2){
                        $staffsAttr[]=$staffOne['staff_name']."($dian)";
                    }else{
                        if($staffOne['type'] == 3){
                            $shouyi[]=$staffOne['staff_name']."($dian)";
                        }else{
                            $xiaoshou[]=$staffOne['staff_name'];
                        }
                    }
                }
                $item['yeji_sales_staff'] = '';
                $item['yeji_craft_staff'] = '';
                if($item['order_type'] == 2) {
                    $item['yeji_craft_staff'] = implode(",", $staffsAttr);
                    $item['yeji_staff'] = $item['yeji_craft_staff'];
                } elseif ($item['order_type'] == 1) {
                    $item['yeji_sales_staff'] = implode(",", $xiaoshou);
                    $item['yeji_staff'] = $item['yeji_sales_staff'];
                } else {
                    $item['yeji_sales_staff'] = implode(",", $xiaoshou);
                    $item['yeji_craft_staff'] = implode(",", $shouyi);
                    $sales = $item['yeji_sales_staff'];
                    $craft = $item['yeji_craft_staff'];
                    if ($sales !== '' && $craft !== '') {
                        $item['yeji_staff'] = $sales . ',' . $craft;
                    } else {
                        $item['yeji_staff'] = $sales !== '' ? $sales : $craft;
                    }
                }
                $_info = $orderCart->getCartColunm(['oid' => $item['id']], 'cart_info', 'unique');
                foreach ($_info as $k => $v) {
                    $cart_info = is_string($v) ? json_decode($v, true) : $v;
                    if (!isset($cart_info['productInfo'])) $cart_info['productInfo'] = [];
                    $_info[$k] = $cart_info;
                    unset($cart_info);
                }
                $item['order_type_label'] = $orderTypes[$item['order_type']] ?? '';
                $item['link_img']='';
                $item['link_name']='';
                $item['send_price']=0;
                if ($item['order_type'] == 2) {
                    $this->attachWriteoffOrderListLinkInfo($item);
                }
                if($item['order_type'] == 1){
                    //充值
                    $give_price=UserRecharge::where('id',$item['link_id'])->value("give_price");
//                    $item['pay_price']=$item['pay_price'].",赠送金额:".$give_price;
                    $item['send_price']=$give_price;
                }
                $item['_info'] = $_info;
                $item['user_nickname'] = $userInfos[$item['uid']]['nickname'] ?? '';
                $item['user_real_phone'] = $userInfos[$item['uid']]['phone'] ?? '';
                $item['user_level'] = $userInfos[$item['uid']]['user_level'] ?? 0;
                $item['sex'] = $userInfos[$item['uid']]['sex'] ?? '';
                [$pink_name, $color] = $this->tidyOrderType($item);
                $item['pink_name'] = $pink_name;
                $item['color'] = $color;
                $item['pay_type_name'] = $this->tidyOrderPayType($item);
                $item['source_name'] = $this->getSourceName($item);
                $this->resolveOrderListGendanInfo($item);
                $item['order_type_label'] = $orderTypes[$item['order_type']] ?? '';
                $item['plate_name'] = '平台';
                $item['store_name'] = $item['supplier_name'] = '';
                if ($item['store_id']) {
                    $item['store_name'] = $storeList[$item['store_id']]['name'] ?? '';
                    $item['plate_name'] = '门店：' . $item['store_name'];
                } elseif ($item['supplier_id']) {
                    $item['supplier_name'] = $supplierList[$item['supplier_id']]['supplier_name'] ?? '';
                    $item['plate_name'] = '供应商：' . $item['supplier_name'];
                }
                if(!empty($item['kua_store'])){
                    $item['kua_store_name']=$storeList[$item['kua_store']]['name'] ?? '';
                }else{
                    $item['kua_store_name']=$storeList[$item['store_id']]['name'] ?? '';
                }
                $item['delivery_type_name'] = $this->deliveryType[$item['delivery_type']] ?? '';
                $userAddress = explode(' ', $item['user_address']);
                $item['user_address_province'] = $userAddress[0] ?? '';
                $item['user_address_city'] = $userAddress[1] ?? '';
                $item['user_address_district'] = $userAddress[2] ?? '';
                $item['user_address_detail'] = ($userAddress[3] ?? '') . ($userAddress[4] ?? '');
            }
        }
        return $list;
    }

    /**
     * 自动取消订单
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function runOrderUnpaidCancel(int $page = 0, int $limit = 0)
    {
        $list = $this->dao->getOrderUnPaid($page, $limit)->field(['*'])->select();
        if (!$list) {
            return true;
        }
        //系统预设取消订单时间段
        $secsArr = $this->getOrderCancelTime();
        foreach ($list as $order) {
            $type = $order['type'];
            $secs = $secsArr[$type] ?? $secsArr[0];
            if ($secs == 0) continue;
            $endTime = (int)bcadd((string)$order['add_time'], (string)bcmul((string)$secs, '3600', 0), 0);
            if ($endTime < time()) {
                try {
                    $this->cancelOrder((int)$order['id'], 0, '订单未支付已超过系统预设时间');
                } catch (\Throwable $e) {
                    Log::error('自动取消订单失败,失败原因:' . $e->getMessage(), $e->getTrace());
                }
            }
        }
        return true;
    }

    /**
     * 批量加入对接
     * @param int $count
     * @param int $limit
     */
    public function batchJoinJob(int $count, int $limit)
    {
        $pages = ceil($count / $limit);
        for ($i = 1; $i <= $pages; $i++) {
            AutoOrderUnpaidCancelJob::dispatch([$i, $limit]);
        }
        return true;
    }

    /**
     * 自动取消订单
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderUnpaidCancel()
    {
        $count = $this->dao->getOrderUnPaid()->count();
        $maxLimit = 100;
        if ($count > $maxLimit) {
            return $this->batchJoinJob($count, $maxLimit);
        }
        return $this->runOrderUnpaidCancel();
    }

    /**
     * 根据时间获取当天或昨天订单营业额
     * @param array $where
     * @return float|int
     */
    public function getOrderMoneyByWhere(array $where, string $sum_field, string $selectType, string $group = "")
    {

        switch ($selectType) {
            case "sum" :
                return $this->dao->getDayTotalMoney($where, $sum_field);
            case "group" :
                return $this->dao->getDayGroupMoney($where, $sum_field, $group);
        }
    }

    /**
     * 批量更新数据
     * @param array $ids
     * @param array $data
     * @param string|null $key
     * @return BaseModel
     */
    public function orderDel(array $ids, $redisKey, $queueId)
    {
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $res = $this->dao->batchUpdateOrder($ids, ['is_system_del' => 1]);
        if ($res) {
            $queueService->doSuccessSremRedis($ids, $redisKey, $queueId['type']);
        } else {
            $queueService->addQueueFail($queueId['id'], $redisKey);
            throw new AdminException('删除失败');
        }
    }

    /**获取发货excel文件数据
     * @param string $file
     * @param $row
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function readExpreExcel(string $file, $row = 2)
    {
        if (!$file) throw new AdminException('请上传发货数据表');
        /** @var FileService $readExcelService */
        $readExcelService = app()->make(FileService::class);
        $exprData = $readExcelService->readExcel($file, $row);
        if (!$exprData) throw new AdminException('发货数据为空');
        return $exprData;
    }

    /**
     * 队列发货
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function adminQueueOrderDo(array $data, bool $is_again = false)
    {
        if (!$data) return false;
        if ($data['queueType'] == 8 && !sys_config('config_export_open')) {
            throw new ValidateException('请先开启:设置->第三方设置->电子面单打印，并配置打印机以及发货信息');
        }
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        /** @var QueueAuxiliaryServices $auxiliaryService */
        $auxiliaryService = app()->make(QueueAuxiliaryServices::class);
        $queueWhere['type'] = $data['queueType'];
        $queueWhere['status'] = 0;
        if (isset($data['queueId']) && $data['queueId']) $queueWhere['id'] = $data['queueId'];
        $queueInfo = $queueService->getQueueOne($queueWhere);
        $ids = $auxiliaryService->getCacheOidList($queueInfo['id'], $data['cacheType']);
        $data['ids'] = array_column($ids, 'relation_id');
        $data['queueId'] = $queueInfo['id'];
        //if ($queueInfo['status'] == 2) throw new ValidateException('任务已完成');
        //把队列需要执行的入参数据存起来，以便队列执行失败后接着执行，同时队列状态改为正在执行状态。
        $queueService->setQueueDoing($data, $queueInfo['id'], $is_again);
        $oids = $auxiliaryService->getOrderExpreList(['binding_id' => $queueInfo['id'], 'type' => $data['cacheType'], 'status' => [0, 2]]);
        $oids = $oids ? array_column($oids, 'relation_id') : [];
        // $chunkPids = array_chunk($oids, 1000, true);
        $data['queueId'] = $queueInfo['id'];
        foreach ($oids as $v) {
            //加入队列
            BatchHandleJob::dispatch([$v, $data['queueType'], $data]);
        }
        return true;
    }

    /**
     * 对外接口获取订单状态
     * @param int $oid
     */
    public function outGetStatus(string $oid)
    {
        $order = $this->dao->getOne(['order_id' => $oid]);
        if (!$order['paid'] && $order['pay_type'] == 'offline' && !$order['status'] >= 2) {
            $status_name = '线下支付';
        } else if (!$order['paid']) {
            $status_name = '待付款';
        } else if ($order['status'] == 0 && $order['refund_status'] == 0) {
            $status_name = '待发货';
        } else if ($order['refund_status'] == 1) {
            $status_name = '申请退款中';
        } else if ($order['refund_status'] == 2) {
            $status_name = '已退款';
        } else if ($order['refund_status'] == 3) {
            $status_name = '部分退款（子订单）';
        } else if ($order['refund_status'] == 4) {
            $status_name = '子订单已全部申请退款中';
        } else if (!$order['status']) {
            $status_name = '未发货';
        } else if ($order['status'] == 1) {
            $status_name = '待收货';
        } else if ($order['status'] == 2) {
            $status_name = '待评价';
        } else if ($order['status'] == 3) {
            $status_name = '交易完成';
        }
        $data = [];
        $data['status_name'] = $status_name;
        $data['status'] = $order['status'];
        $data['paid'] = $order['paid'];
        $data['pay_type'] = $order['pay_type'];
        $data['refund_status'] = $order['refund_status'];
        return $data;
    }

    /**
     * 对外接口根据订单id查询收货方式
     * @param string $oid
     * @return array
     */
    public function outGetShippingType(string $oid)
    {
        $shipping_type = $this->dao->value(['order_id' => $oid], 'shipping_type');
        $shipping_type = $shipping_type == 1 ? '商家配送' : '到店核销';
        return compact('shipping_type');
    }

    /**
     * 对外接口根据订单id查询配送信息
     * @param string $oid
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function OutDeliveryType(string $oid)
    {
        $info = $this->dao->getOne(['order_id' => $oid], 'order_id,delivery_type,delivery_name,delivery_id');
        return $info ? $info->toArray() : [];
    }

    /**
     * 对外接口获取运费
     * @param int $cartId
     * @param int $uid
     * @param int $addressId
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function outGetPostage($cartId, int $uid, int $addressId, int $couponId = 0)
    {
        $addr = [];
        /** @var UserAddressServices $addressServices */
        $addressServices = app()->make(UserAddressServices::class);
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->get($uid);
        if ($addressId) {
            $addr = $addressServices->getAdderssCache($addressId);
        }
        //没传地址id或地址已删除未找到 ||获取默认地址
        if (!$addr) {
            $addr = $addressServices->getUserDefaultAddressCache($uid);
        }

        /** @var StoreCartServices $cartServices */
        $cartServices = app()->make(StoreCartServices::class);
        $cartGroup = $cartServices->getUserProductCartListV1($uid, $cartId, true, $addr);
        $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
        $validCartInfo = $cartGroup['valid'];
        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        $priceGroup = $computedServices->getOrderPriceGroup($uid, $validCartInfo, $addr, $storeFreePostage);
        $postage = $priceGroup['storePostage'] ?? 0;
        return compact('postage');
    }

    /**
     * 获取配送员订单统计列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliveryStatistics(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $where['paid'] = 1;
        $where['is_system_del'] = 0;
        $where['delivery_type'] = 'send';
        $where['refund_status'] = [0, 3];
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $list = $this->dao->getList((array)$where, ['*'], (int)$page, (int)$limit, ['user']);
        foreach ($list as $k => &$item) {
            $item['nickname'] = $userServices->value(['uid' => $item['uid']], 'nickname');
            $item['pay_type_name'] = $this->tidyOrderPayType($item);
            $item['source_name'] = $this->getSourceName($item);
        }
//        if ($list) {
//            $list = $this->tidyOrderList($list, false);
//        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 配送员订单统计
     * @param $store_id
     * @param $delivery_uid
     * @param $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStatisticsHeader($store_id, $delivery_uid, $time)
    {
        $where['is_del'] = 0;
        $where['paid'] = 1;
        $where['is_system_del'] = 0;
        $where['delivery_type'] = 'send';
        $where['store_id'] = $store_id;
        $where['refund_status'] = [0, 3];
        if ($delivery_uid) {
            $where['delivery_uid'] = $delivery_uid;
        }
        [$start, $end, $timeType, $xAxis] = $time;
        $order = $this->dao->orderAddTimeList($where, [$start, $end], $timeType, false);
        $price = array_column($order, 'price', 'day');
        $count = array_column($order, 'count', 'day');
        $data = $series = [];
        foreach ($xAxis as $key) {
            $data['配送订单金额'][] = isset($price[$key]) ? floatval($price[$key]) : 0;
            $data['配送单数'][] = isset($count[$key]) ? floatval($count[$key]) : 0;
        }
        foreach ($data as $key => $item) {
            $series[] = [
                'name' => $key,
                'data' => $item,
                'type' => 'line',
                'smooth' => 'true',
                'yAxisIndex' => 1,
            ];
        }
        return compact('xAxis', 'series');
    }

    /**
     * 门店线上支付订单详情
     * @param int $store
     * @param int $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function payCashierOrder(int $store, int $uid)
    {
        $order = $this->dao->payCashierOrder($store, $uid);
        if (!$order) throw new ValidateException('订单不存在');
        $order = $order->toArray();
        $order = $this->tidyOrder($order, true);
        $order['yue_pay_status'] = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
        $order['pay_weixin_open'] = (int)sys_config('pay_weixin_open') ?? 0;//微信支付 1 开启 0 关闭
        $order['ali_pay_status'] = (bool)sys_config('ali_pay_status');//支付包支付 1 开启 0 关闭
        return $order;
    }

    /**
     * 订单分配｜重新分配给你门店
     * @param int $id
     * @param int $store_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function shareOrder(int $id, int $store_id)
    {
        $orderInfo = $this->dao->get((int)$id);
        if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }
        //卡密商品
        if ($orderInfo['product_type'] == 1) {
            throw new ValidateException('订单中卡密商品门店暂不支持');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeInfo = $storeServices->getStoreInfo($store_id);
        if ($orderInfo['status'] != 0) {
            throw new ValidateException('订单已发货');
        }
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cart_info = $storeOrderCartInfoServices->getSplitCartList($id, 'cart_info');
        if (!$cart_info) {
            throw new ValidateException('订单已发货');
        }
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $id, 'refund_type' => [1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ValidateException('订单有售后申请请先处理');
        }
        $platProductIds = [];
        $platStoreProductIds = [];
        $storeProductIds = [];
        foreach ($cart_info as $cart) {
            $productInfo = $cart['productInfo'] ?? [];
            if (isset($productInfo['store_delivery']) && !$productInfo['store_delivery']) {//有商品不支持门店配送
                return [[], $cart_info];
            }
            switch ($productInfo['type'] ?? 0) {
                case 0://平台
                case 2://供应商
                    $platProductIds[] = $cart['product_id'];
                    break;
                case 1://门店
                    if ($productInfo['pid']) {//门店自有商品
                        $storeProductIds[] = $cart['product_id'];
                    } else {
                        $platStoreProductIds[] = $cart['product_id'];
                    }
                    break;
            }
        }
        if ($storeProductIds && $store_id != $orderInfo['store_id']) {
            throw new ValidateException('该门店商品未上架或未设置库存');
        }
        /** @var StoreBranchProductServices $branchProductServics */
        $branchProductServics = app()->make(StoreBranchProductServices::class);
        //转换成平台商品
        if ($platStoreProductIds) {
            $ids = $branchProductServics->getStoreProductIds($platStoreProductIds);
            $platProductIds = array_merge($platProductIds, $ids);
        }
        $productCount = count($platProductIds);
        //商品没下架 && 库存足够
        if ($productCount != $branchProductServics->count(['pid' => $platProductIds, 'is_show' => 1, 'is_del' => 0, 'type' => 1, 'relation_id' => $store_id])) {
            throw new ValidateException('该门店商品未上架或未设置库存');
        }
        /** @var StoreProductAttrValueServices $skuValueServices */
        $skuValueServices = app()->make(StoreProductAttrValueServices::class);
        foreach ($cart_info as $cart) {
            if (isset($cart['productInfo']['store_delivery']) && !$cart['productInfo']['store_delivery']) {//有商品不支持门店配送
                throw new ValidateException('有商品不支持门店配送');
            }
            $type=$cart['type'] ?? 0;
            switch ($type) {
                case 0:
                case 6:
                case 8:
                case 9:
                case 10:
                    $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $cart['product_id'], 'type' => 0], 'suk');
                    break;
                case 1:
                case 2:
                case 3:
                case 5:
                case 7:
                    $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $cart['activity_id'], 'type' => $cart['type']], 'suk');
                    break;
            }
            $branchProductInfo = $branchProductServics->isValidStoreProduct((int)$cart['product_id'], $store_id);
            if (!$branchProductInfo) {
                throw new ValidateException('该门店商品库存不足');
            }
            $attrValue = $skuValueServices->get(['suk' => $suk, 'product_id' => $branchProductInfo['id'], 'type' => 0]);
            if (!$attrValue) {
                throw new ValidateException('该门店商品库存不足');
            }
        }
        $res = $this->transaction(function () use ($id, $store_id, $orderInfo, $storeInfo, $cart_info, $branchProductServics) {

            if ($orderInfo['store_id'] > 0) {//重新分配门店
                //返还原来门店库存
                $res = $branchProductServics->regressionBranchProductStock($orderInfo, $cart_info, -1, 0);
            } else {
                //返还平台库存
                $res = $branchProductServics->regressionBranchProductStock($orderInfo, $cart_info, 0, -1);
            }
            //扣门店库存
            $res = $branchProductServics->regressionBranchProductStock($orderInfo, $cart_info, -1, 1, $store_id);
            $res = $res && $this->dao->update($id, ['store_id' => $storeInfo['id'], 'shipping_type' => $orderInfo['shipping_type'] == 1 ? 3 : $orderInfo['shipping_type']]);
            return $res;
        });
        $orderInfo['store_id'] = $storeInfo['id'];
        //删除之前的账单记录
        /** @var StoreFinanceFlowServices $storeFinanceFlowServices */
        $storeFinanceFlowServices = app()->make(StoreFinanceFlowServices::class);
        $storeFinanceFlowServices->update(['link_id' => $orderInfo['order_id']], ['is_del' => 1]);
        //分配后置方法
        SpliteOrderAfterJob::dispatchDo('splitAfter', [$orderInfo, true]);
        return $res;
    }

    /**
     * 获取退货商品列表
     * @param array $cart_ids
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundCartInfoList(array $cart_ids = [], int $id = 0)
    {
        $orderInfo = $this->dao->get($id);
        if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }
        $orderInfo = $this->tidyOrder($orderInfo, true);
        $cartInfo = $orderInfo['cartInfo'] ?? [];
        $data = [];
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id']) {
                    throw new ValidateException('请重新选择退款商品，或件数');
                }
            }
            $cart_ids = array_combine(array_column($cart_ids, 'cart_id'), $cart_ids);
            $i = 0;
            foreach ($cartInfo as &$item) {
                if (isset($cart_ids[$item['id']])) {
                    $data['cartInfo'][$i] = $item;
                    if (isset($cart_ids[$item['id']]['cart_num'])) $data['cartInfo'][$i]['cart_num'] = $cart_ids[$item['id']]['cart_num'];
                    $i++;
                }
            }
        }
        $data['_status'] = $orderInfo['_status'] ?? [];
        $data['cartInfo'] = $data['cartInfo'] ?? $cartInfo;
        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);
        $data['refund_num'] = $refundServices->sum(['store_order_id' => $id, 'is_cancel' => 0, 'is_del' => 0, 'refund_type' => [0, 1, 2, 4, 5]], 'refund_num');
        return $data;
    }

    /**
     * 拆单的发货订单数量
     * @param int $id
     * @param $order
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliverNum(int $id, $order): int
    {
        if (!$order) {
            $order = $this->get($id);
        }
        $ids = $id;
        $pid = (int)$order['pid'];
        if ($pid > 0) {
            $ids = $this->Value([['pid', '=', $pid], ['status', '=', 1]], 'GROUP_CONCAT(id)');
            if (!empty($ids)) {
                $ids = array_map('intval', array_filter(explode(',', $ids)));
            }
        }
        return $this->getCount(['id' => $ids, 'status' => 1]);
    }

    /**
     * 检测订单是否能退款
     * @param $oid
     * @return bool
     */
    public function isRefundAvailable(int $oid)
    {
        $refundTimeAvailable = (int)sys_config('refund_time_available');
        if ($refundTimeAvailable == 0) return true;
        /** @var StoreOrderStatusServices $statusServices */
        $statusServices = app()->make(StoreOrderStatusServices::class);
        $statusInfo = $statusServices->get(['oid' => $oid, 'change_type' => ['user_take_delivery', 'take_delivery']]);
        if (!$statusInfo) return true;
        $changeTime = preg_match('/^\d+$/', $statusInfo['change_time']) ? intval($statusInfo['change_time']) : strtotime($statusInfo['change_time']);
        if (($changeTime + ($refundTimeAvailable * 86400)) < time()) {
            return false;
        }
        return true;
    }


    /**
     * 获取确认订单页面是否展示快递配送和到店核销
     * @param $uid
     * @param $cartIds
     * @param $new
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function checkShipping($uid, $cartIds, $new)
    {
        if ($new) {
            $cartIds = explode(',', $cartIds);
            $cartInfo = [];
            $redis = CacheService::redisHandler();
            foreach ($cartIds as $key) {
                $info = $redis->get($key);
                if ($info) {
                    $cartInfo[] = $info;
                }
            }
        } else {
            /** @var StoreCartServices $cartServices */
            $cartServices = app()->make(StoreCartServices::class);
            $cartInfo = $cartServices->getCartList(['uid' => $uid, 'status' => 1, 'id' => $cartIds], 0, 0, ['productInfo', 'attrInfo']);
        }
        if (!$cartInfo) {
            throw new ValidateException('获取购物车信息失败');
        }
        $arr = [];
        $store_id = [];
        //delivery_type :1、快递，2、到店核销，3、门店配送
        $productType = 0;
        foreach ($cartInfo as $item) {
            $productInfo = $item['productInfo'] ?? [];
            if (!$productInfo) continue;
            $productType = $productInfo['product_type'] ?? 0;
            $delivery_type = is_string($productInfo['delivery_type']) ? explode(',', $productInfo['delivery_type']) : $productInfo['delivery_type'];
            if (in_array(1, $delivery_type)) {//支持平台配送 验证平台该商品
                if (isset($productInfo['type']) && $productInfo['type'] == 1 && isset($productInfo['pid']) && $productInfo['pid']) {
                    /** @var StoreProductServices $productServices */
                    $productServices = app()->make(StoreProductServices::class);
                    $platInfo = $productServices->getCacheProductInfo((int)$productInfo['pid']);
                    if (!$platInfo || $platInfo['stock'] <= 0) {
                        unset($delivery_type[array_search('1', $delivery_type)]);
                    }
                }
            }
            //适用门店：0：仅平台1：所有2：部分
            $applicable_type = $item['productInfo']['applicable_type'] ?? 1;
            if ($applicable_type == 0) {//仅平台适用 排除门店
                $delivery_type = array_diff($delivery_type, [2, 3]);
            }
            $arr = array_unique(array_merge($arr, $delivery_type));
            if (isset($item['store_id']) && $item['store_id']) {
                $store_id[] = $item['store_id'];
            } else if (isset($item['productInfo']['type']) && isset($item['productInfo']['relation_id']) && $item['productInfo']['type'] == 1 && $item['productInfo']['relation_id']) {
                $store_id[] = $item['productInfo']['relation_id'];
            }
        }
        $count = count($arr);
        if (!$count) {
            $arr = [1];
        }
        //平台配送
        if (in_array(1, $arr)) {
            $shopOperationType = sys_config('shop_operation_type', 1);
            if ($shopOperationType == 3) {//单店模式，不支持平台配送
                unset($arr[array_search(1, $arr)]);
            }
        }
        /** @var SystemStoreServices $SystemStoreServe */
        $SystemStoreServe = app()->make(SystemStoreServices::class);
        $store = $SystemStoreServe->getOne(['id' => $store_id, 'is_show' => 1, 'is_del' => 0]);
        if (!$store) $arr = [1];
        // 门店总开关
        if (!sys_config('store_func_status', 1)) {
            if (in_array(2, $arr)) unset($arr[array_search(2, $arr)]);
            if (in_array(3, $arr)) unset($arr[array_search(3, $arr)]);
        } else {
            if (in_array(2, $arr)) {//存在门店核销方式
                if (sys_config('store_self_mention', 1)) {//门店核销开启
                    //判断有没有满足核销的店铺
                    if ($productType != 6) {//预约商品不限制这个条件
                        if (!in_array(2, $store['delivery_type'])) {
                            unset($arr[array_search(2, $arr)]);
                        }
//                        if (!$SystemStoreServe->count(['id' => $store_id, 'delivery_type' => 2, 'is_show' => 1, 'is_del' => 0])) {
//                            unset($arr[array_search(2, $arr)]);
//                        }
                    }
                } else {
                    unset($arr[array_search(2, $arr)]);
                }
            }
            if (in_array(3, $arr) && $productType != 6) {
                $arr = array_unique(array_merge($arr, [1, 3]));
                if (!in_array(3, $store['delivery_type'])) {
                    unset($arr[array_search(3, $arr)]);
                }
                if (!in_array(1, $store['delivery_type'])) {
                    unset($arr[array_search(1, $arr)]);
                }
                //判断有没有满足配送的店铺
//                if (!$SystemStoreServe->count(['id' => $store_id, 'delivery_type' => 3, 'is_show' => 1, 'is_del' => 0])) {
//                    unset($arr[array_search(3, $arr)]);
//                }
            }
            if (in_array(3, $arr)) {
                /** @var DeliveryConfigServices $deliveryConfigServices */
                $deliveryConfigServices = app()->make(DeliveryConfigServices::class);
                $deliveryConfig = $deliveryConfigServices->get(['type' => 1, 'relation_id' => $store_id]);
                if (!$deliveryConfig) {
                    unset($arr[array_search(3, $arr)]);
                }
                if(!sys_config('city_delivery_status')) {
                    unset($arr[array_search(3, $arr)]);
                }
            }
        }
        $arr = array_merge(array_unique($arr));
        return ['type' => $arr];
    }

    /**
     * @param int $pid
     * @param int $order_id
     * @return bool
     * @throws \think\db\exception\DbException
     */
    public function checkSubOrderNotSend(int $pid, int $order_id)
    {
        $order_count = $this->dao->getSubOrderNotSend($pid, $order_id);
        if ($order_count > 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @param int $pid
     * @param int $order_id
     * @return bool
     * @throws \think\db\exception\DbException
     */
    public function checkSubOrderNotTake(int $pid, int $order_id)
    {
        $order_count = $this->dao->getSubOrderNotTake($pid, $order_id);
        if ($order_count > 0) {
            return false;
        } else {
            return true;
        }
    }

}
