<?php
namespace app\services\yeji;

use app\dao\article\ArticleDao;
use app\dao\yeji\StaffYejiDao;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\order\StoreReservationOrder;
use app\model\product\product\StoreProduct;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\model\user\UserRecharge;
use app\model\yeji\CashType;
use app\services\BaseServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use app\services\report\ReportServices;
use think\facade\Db;

/**
 * 文章
 * Class ArticleServices
 * @package app\services\article
 * @mixin ArticleDao
 */
class SatffYejiServices extends BaseServices
{
    /**
     * ArticleServices constructor.
     * @param ArticleDao $dao
     */
    public function __construct(StaffYejiDao $dao)
    {
        $this->dao = $dao;
    }

    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {

        }
        return compact('count', 'list');
    }

    public function saveOrder($data){
        //创建核销订单
        if(empty($data['link_id'])) {
            return true;
        }
        $writer=StoreOrderWriteoff::where('id',$data['link_id'])->find();
        if(empty($writer)){
            return true;
        }
        $order=StoreOrder::where('id',$data['order_id'])->find();
        if (empty($order) && !empty($writer['oid'])) {
            $data['order_id'] = (int)$writer['oid'];
            $order = StoreOrder::where('id', $data['order_id'])->find();
        }
        if (empty($order)) {
            return false;
        }
        $storeId = (int)($data['store_id'] ?? 0);
        if (!empty($writer['reservation_oid'])) {
            $reservationStoreId = (int)StoreReservationOrder::where('id', (int)$writer['reservation_oid'])->value('store_id');
            if ($reservationStoreId > 0) {
                $storeId = $reservationStoreId;
            } elseif ($storeId <= 0) {
                $storeId = (int)($writer['relation_id'] ?? 0);
            }
        } else {
            $staffStoreId = (int)SystemStoreStaff::where('id', (int)$writer['staff_id'])->value('store_id');
            if ($staffStoreId > 0) {
                $storeId = $staffStoreId;
            }
            if (!$storeId && !empty($order)) {
                $storeId = (int)($order['store_id'] ?? 0);
            }
            if (!$storeId) {
                $storeId = (int)($writer['relation_id'] ?? 0);
            }
            if (!$storeId) {
                $storeId = (int)($data['store_id'] ?? 0);
            }
        }
        $addTime=$data['add_time'] ?? time();
        $is_budan=$data['is_budan'] ?? 0;
        $isAuto=$data['is_auto'] ?? 0;
        $isGendan = (int)($data['is_gendan'] ?? $order['is_gendan'] ?? 0) ? 1 : 0;
        $gendanStaffId = (int)($data['gendan_staff_id'] ?? $order['gendan_staff_id'] ?? 0);
        if ($gendanStaffId > 0) {
            $isGendan = 1;
        }
        $orderInfo=[
            'uid' => $order['uid'],
            'order_id' => $this->getUniqueId(),
            'add_time'=>$addTime,
            'is_budan'=>$is_budan,
            'is_gendan'=>$isGendan,
            'gendan_staff_id' => $gendanStaffId,
            'pay_time'=>time(),
            'unique'=>generateUnique32Str(),
            'total_price'=>$data['price'],
            'pay_price'=>$data['price'],
            'order_type'=>2,
            'paid'=>1,
            'pid'=>-2,
            'status'=>2,
            'link_id'=>$data['link_id'],
            'link_order'=>$data['order_id'],
            'pay_type'=>$order['pay_type'],
            'store_id'=>$storeId,
            'is_auto'=>$isAuto,
            'source'=>$order['source'],
            'staff_id'=>$writer['staff_id'],
            'cash_choose'=>$order['cash_choose'],
            // 服务对象：与核销记录一致；业绩同步入参可能未带 service_object，从核销表回退
            'service_object' => (($data['service_object'] ?? '') !== '')
                ? $data['service_object']
                : (($writer['service_object'] ?? '') !== '' ? $writer['service_object'] : '本人')
        ];
        $orderServices = app()->make(StoreOrderCreateServices::class);
        $orderInfo['kua_store']=$orderServices->isKuadianhx($orderInfo['store_id'],$orderInfo['link_order']);
        $orderServices->save($orderInfo);
    }
    public function saveYeji($data,$idAddOrder=true,$isAdmin=false){
        $cartId=$data['cart_id'] ?? 0;
        // 拆单后购卡业绩：清理收银台下单时的旧 cart_id / 父订单 link_id 记录
        if (($data['type'] ?? 0) == 2 && !$isAdmin) {
            $checkoutCartId = (string)($data['checkout_cart_id'] ?? '');
            $parentOrderId = (int)($data['parent_order_id'] ?? 0);
            $goodsId = (int)($data['goods_id'] ?? 0);
            if ($checkoutCartId !== '' && (string)$cartId !== '' && $checkoutCartId !== (string)$cartId && $goodsId > 0) {
                Db::name('staff_yeji')
                    ->where('type', 2)
                    ->where('goods_id', $goodsId)
                    ->where('cart_id', $checkoutCartId)
                    ->delete();
            }
            if ($parentOrderId > 0 && $parentOrderId !== (int)($data['link_id'] ?? 0) && $goodsId > 0) {
                Db::name('staff_yeji')
                    ->where('type', 2)
                    ->where('goods_id', $goodsId)
                    ->where('link_id', $parentOrderId)
                    ->delete();
            }
        }
        $has=Db::name("staff_yeji")
            ->where("link_id",$data['link_id'])
            ->where("goods_id",$data['goods_id'])
            ->where("type",$data['type']);
        if($data['type'] == 2){
            //购卡需要识别购物车
            $has=$has->where("cart_id",$cartId);
        }
        $has=$has->select();
        $ids=[];
        $storeId=0;
        $addTime='';
        if($data['type'] == 3){
              //计算核销金额
              $writeOffData=StoreOrderWriteoff::where("id",$data['link_id'])->find();
              $storeId=$writeOffData['relation_id'] ?? 0;
              $addTime=$writeOffData['add_time'] ?? '';
              if($idAddOrder) {
                  $cart = StoreOrderCartInfo::where('id', $writeOffData['order_cart_id'])->find();
                  if ($cart['write_times'] == 0) {
                      $unit_price = 0;
                  } else {
                      $unit_price = bcdiv((string)$cart['pay_price'], (string)$cart['write_times'], 2);
                  }
                  $productId=StoreOrderCartInfo::where("oid",$data['order_id'])->where("cart_type",0)->value("product_id");
                  if(!empty($productId)){
                      $productInfo=StoreProduct::where("id",$productId)->find();
                      if($productInfo['card_num'] > 0 && $productInfo['card_num_type'] == 1){
                          $payPrice=StoreOrder::where("id",$data['order_id'])->value("pay_price");
                          //几选几套餐 按订单付款金额判断单次金额
                          $unit_price=bcdiv($payPrice,$productInfo['card_num'],2);
                      }
                  }
                  $saveOrder = $data;
                  $saveOrder['price'] = (float)bcmul((string)$unit_price, (string)$writeOffData['writeoff_num'], 2);
                  $this->saveOrder($saveOrder);
              }
        }
        if($data['type'] == 2){
           //购卡：现金业绩按订单/购物车实际现金部分计算
            $order = StoreOrder::where("id", $data['link_id'])->find();
            $storeId = $order['store_id'] ?? 0;
            $addTime = $order['add_time'] ?? '';
            $productId = StoreOrderCartInfo::where("oid", $data['link_id'])->where("cart_type", 0)->value("product_id");
            $mainId = 8154; //定制卡id
            $productIds = StoreProduct::where("pid", $mainId)->column("id");
            $productIds[] = $mainId;
            if (in_array($productId, $productIds)) {
                $data['price'] = $order['cash_pay_price'];
            } else {
                $first = StoreOrderCartInfo::where("oid", $data['link_id'])->where("cart_id", $cartId)->find();
                if ($first && isset($first['cash_pay_amount']) && $first['cash_pay_amount'] !== '' && $first['cash_pay_amount'] !== null) {
                    $data['price'] = bcadd((string)$first['cash_pay_amount'], '0', 2);
                } elseif ($first) {
                    $data['price'] = bcsub($first['pay_price'], $first['yue_pay_amount'] + $first['card_upgrade_amount'], 2);
                } else {
                    $data['price'] = bcadd((string)($order['cash_pay_price'] ?? '0'), '0', 2);
                }
            }
            // 单笔旧卡录入（cash_choose=9）不计现金业绩，等同纯余额
            if ((int)($order['cash_choose'] ?? 0) === CashType::OLD_CARD_ENTRY
                && ($order['pay_type'] ?? '') !== PayServices::COMBINATION_PAY) {
                $data['price'] = '0';
            }
            $staffChoose = $data['staffChoose'] ?? [];
            if ($staffChoose) {
                $cashPrice = bcadd((string)($data['price'] ?? '0'), '0', 2);
                $totalFrontend = '0';
                foreach ($staffChoose as $sc) {
                    $totalFrontend = bcadd($totalFrontend, (string)($sc['yeji'] ?? 0), 2);
                }
                if (bccomp($cashPrice, '0', 2) <= 0) {
                    foreach ($staffChoose as &$sc) {
                        $sc['yeji'] = 0;
                    }
                    unset($sc);
                    $data['staffChoose'] = $staffChoose;
                } elseif (bccomp($totalFrontend, $cashPrice, 2) !== 0) {
                    $data['staffChoose'] = $this->splitYejiAmongStaff($staffChoose, $cashPrice);
                }
            }
        }
        if($data['type'] == 1){
            //充值
            $order=UserRecharge::where("id",$data['link_id'])->find();
            $storeId=$order['store_id'] ?? 0;
            $addTime=$order['add_time'] ?? '';
        }
        foreach ($data['staffChoose'] as $k=>$v){
            $yeji=[];
            $yeji['staff_id']=$v['staff_id'];
            $yeji['staff_name']=$v['staff_name'];
            $yeji['position_label']=$v['position_label'];
            $yeji['position']=$v['position'];
            $yeji['position_level']=$v['position_level'];
            $yeji['position_level_label']=$v['position_level_label'];
            // 购卡：有效现金为 0 时现金业绩固定为 0；其余类型按前端录入保存
            if ((int)$data['type'] === 2 && bccomp((string)($data['price'] ?? '0'), '0', 2) <= 0) {
                $yeji['yeji'] = 0;
            } else {
                $yeji['yeji'] = $v['yeji'] ?? 0;
            }
            $yeji['deduct_card_yeji'] = (float)($v['deduct_card_yeji'] ?? 0);
            $yeji['is_dian']=$v['is_dian'] ?? 0;
            $yeji['type']=$data['type'];
            $yeji['link_id']=$data['link_id'];
            $yeji['cart_id']=$cartId;
            $yeji['price']=$data['price'];
            $yeji['goods_id']=$data['goods_id'];
            $yeji['order_id']=$data['order_id'] ?? 0;
            $yeji['true_price']=$data['true_price'] ?? 0;
            $yeji['once_price']=$data['once_price'] ?? 0;
            $yeji['value']=$data['value'] ?? 0;
            $yeji['write_times']=$data['write_times'] ?? 0;
            $find=Db::name("staff_yeji")
                ->where("link_id",$data['link_id'])
                ->where("staff_id",$v['staff_id'])
                ->where("goods_id",$data['goods_id'])
                ->where("type",$data['type']);
            if($data['type'] == 2){
                //购卡需要识别购物车
                $find=$find->where("cart_id",$cartId);
            }
            $find=$find->find();
            $ids[]=$v['staff_id'];
            if(empty($find)){
                //创建时间读取关联数据的时间
                $yeji['store_id']=$storeId;
                if(empty($addTime)){
                    $yeji['created_time']=date("Y-m-d H:i:s");
                }else{
                    $yeji['created_time']=date("Y-m-d H:i:s",$addTime);
                }
                Db::name("staff_yeji")->insert($yeji);
            }else{
                Db::name("staff_yeji")->where("id",$find['id'])->update($yeji);
            }
        }
        foreach ($has as $k=>$v){
            if(!in_array($v['staff_id'],$ids)){
                Db::name("staff_yeji")->where("id",$v['id'])->delete();
            }
        }
        return true;
    }

    //业绩排名 类型1销售业绩 2耗卡业绩
    public function yejiRanking($where){
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->ranking($where, $page, $limit);
        foreach ($list as &$item) {
             $item['store_name']=SystemStore::where("id",$item['store_id'])->value("name");
        }
        return $list;
    }

    /**
     * 劳动项目数排行（与员工业绩里「项目数」同一套计算）
     */
    public function projectRanking($where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->projectRanking($where, $page, $limit);
        foreach ($list as &$item) {
            $item['store_name'] = SystemStore::where('id', $item['store_id'])->value('name');
        }
        return $list;
    }

    //获取点客
    public function dianke($where){
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->dianke($where, $page, $limit);
        foreach ($list as &$item) {
            $item['store_name']=SystemStore::where("id",$item['store_id'])->value("name");
        }
        return $list;
    }

    //员工提成明细
    public function detailYeji(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        // 明细列表需展示分配业绩为0、订单现金支付为0的记录
        $where['include_zero_cash'] = 1;
        $list = $this->dao->getList($where, $page, $limit);
        $perAttr=[];
        foreach ($list as &$item) {
            if(empty($item['yeji'])){
                $item['yeji']=0;
            }
            $item['service_object'] = '';
            $item['labor_participant_names'] = '';
             if($item['type'] == 1){
                 //充值业绩
                $order=UserRecharge::where('id',$item['link_id'])->find();
                $item['wx_order_id']=StoreOrder::where("link_id",$order['id'])->where("order_type",1)->value("order_id");
                $cart=[
                        'store_name'=>'充值金额:'.$order['price'],'送:'.$order['give_price'],
                        'image'=>'https://qiniu007.cc3798.com/uploads/chong.jpg'
                    ];
                 $uid=$order['uid'];
             }else{
                 //销售业绩
                 $order=StoreOrder::where('id',$item['order_id'])->find();
                 $uid=$order['uid'];
                 $item['wx_order_id']=$order['order_id'];
                 $cart=StoreProduct::where("id",$item['goods_id'])->field("image,store_name")->find();
             }
             $item['dian']='';
             $item['commission']=0;
             if($item['type'] == 3){
                 // 劳动业绩：列表「订单号」应为核销时生成的核销子单号（order_type=2, link_id=核销记录id），不是原主单号
                 $hxOrderNo = StoreOrder::where('order_type', 2)
                     ->where('link_id', (int)($item['link_id'] ?? 0))
                     ->order('id', 'desc')
                     ->value('order_id');
                 if (!empty($hxOrderNo)) {
                     $item['wx_order_id'] = $hxOrderNo;
                 }
                 $dian="点";
                 if($item['is_dian'] != 1){
                     $dian="轮";
                 }
                 $item['staff_name']=$item['staff_name']."($dian)";
                 $yeji=$item['yeji'];
                 $item['yeji']=$item['yeji']."($dian)";
                 $item['dian']=$dian;
                 $item['price']=$yeji;
                 $item['project_num']=$this->dao->laborProjectShareFormatted(
                     (int)($item['link_id'] ?? 0),
                     (int)($item['goods_id'] ?? 0),
                     (int)($item['staff_id'] ?? 0)
                 );
                 $perAttr=$this->dao->findMonthYeji($item['staff_id'],$item['created_time'],$perAttr);
                 $key=$this->dao->getKey($item['staff_id'],$item['created_time']);
                 $monthYeji=$perAttr[$key] ?? 0;
                 $per=$this->dao->getPer((int)$item['staff_id'], (int)$item['goods_id'], $monthYeji, (string)($item['created_time'] ?? ''));
                 $item['commission']=bcmul($yeji,$per/100,2);
                // 服务对象：核销记录优先（与统计逻辑一致）
                $wso = StoreOrderWriteoff::where('id', (int) ($item['link_id'] ?? 0))->value('service_object');
                $item['service_object'] = ($wso !== null && $wso !== '')
                    ? (string) $wso
                    : (string) (($order['service_object'] ?? '') ?: '本人');
                if (trim($item['service_object']) === '') {
                    $item['service_object'] = '本人';
                }
                // 同一核销项目下参与劳动分配的全部服务人员（点/轮）
                $mates = Db::name('staff_yeji')
                    ->where('link_id', $item['link_id'])
                    ->where('goods_id', $item['goods_id'])
                    ->where('type', 3)
                    ->where('status', 0)
                    ->order('id asc')
                    ->field('staff_name,is_dian')
                    ->select()
                    ->toArray();
                $nameParts = [];
                foreach ($mates as $m) {
                    $d = (!empty($m['is_dian']) && (int) $m['is_dian'] === 1) ? '点' : '轮';
                    $nameParts[] = ($m['staff_name'] ?? '') . '(' . $d . ')';
                }
                $item['labor_participant_names'] = implode('、', $nameParts);
                /** @var StoreOrderServices $orderServices */
                $orderServices = app()->make(StoreOrderServices::class);
                $woDisplay = $orderServices->getWriteoffLinkProductDisplay(
                    (int)($item['link_id'] ?? 0),
                    (int)($item['order_id'] ?? 0),
                    (int)$uid
                );
                if ($woDisplay['name'] !== '') {
                    $woSuffix = $woDisplay['writeoff_num'] !== '' ? ',核销数量:' . $woDisplay['writeoff_num'] : '';
                    $cart = [
                        'store_name' => $woDisplay['name'] . $woSuffix,
                        'image' => $woDisplay['image'] ?? '',
                    ];
                } else {
                    $orderCartId = (int)StoreOrderWriteoff::where('id', (int)($item['link_id'] ?? 0))->value('order_cart_id');
                    if ($orderCartId > 0) {
                        $cartLine = StoreOrderCartInfo::where('id', $orderCartId)->find();
                        if (!empty($cartLine)) {
                            $ci = $cartLine['cart_info'] ?? [];
                            if (is_string($ci)) {
                                $ci = json_decode($ci, true) ?: [];
                            }
                            $lineName = (string)($ci['productInfo']['store_name'] ?? '');
                            if ($lineName !== '') {
                                $woNum = (string)StoreOrderWriteoff::where('id', (int)($item['link_id'] ?? 0))->value('writeoff_num');
                                $woSuffix = $woNum !== '' ? ',核销数量:' . $woNum : '';
                                $cart = [
                                    'store_name' => $lineName . $woSuffix,
                                    'image' => (string)($ci['productInfo']['image'] ?? ''),
                                ];
                            }
                        }
                    }
                }
              } else {
                 $item['project_num'] = '';
              }
             $user=User::where("uid",$uid)->field("real_name,phone")->find();
             if (empty($user)) {
                 $user = ['real_name' => '', 'phone' => ''];
             } elseif (is_object($user)) {
                 $user = $user->toArray();
             }
             if (empty($cart)) {
                 $cart = ['store_name' => '', 'image' => ''];
             } elseif (is_object($cart)) {
                 $cart = $cart->toArray();
             }
             $item['cart']=$cart;
             $item['user']=$user;
        }
        return $list;
    }


    //员工详情
    public function staffInfo(array $where){
         $con=$where;
         $info=$this->dao->staffInfo($where);
         $staff=SystemStoreStaff::where("id",$where['staff_id'])->find();
         $info['staff_name']=$staff['staff_name'];
         $info['phone']=$staff['phone'];
         $where['sum_type']=2;
         $info['service_num']=$this->dao->serviceNum($where); //服务客次
         $info['project_num']=$this->dao->projectNumFractional($where, (int)($where['staff_id'] ?? 0)); //劳动项目数（多人平分尾差归末位）
         $where['is_dian']=1;
         $info['service_zd']=$this->dao->serviceNum($where); //服务客次
         $info['service_commission']=$this->dao->totalCommission($con); //提成
         $date=explode("-",$where['created_time']);
         $date=strtotime(date("Y-m",strtotime($date[0])));
         $service=new ReportServices();
         // 手机端个人业绩「实发工资」显隐：sys_config mobile_yeji_salary_display，1=显示 2=隐藏（默认1）
         $salaryDisplay = 2;
         $info['salary_display'] = $salaryDisplay;
         if ($salaryDisplay === 2) {
             $info['salary'] = null;
         } else {
             $info['salary'] = $service->salaryOne($where['staff_id'], $date);
         }
         $info['date']=date("Y-m",$date);
         return $info;
    }

    /**
     * 修改记账支付方式后，按最新现金基数重算销售业绩（type 1 充值 / type 2 购卡）
     */
    public function recalcSalesYejiAfterCashChooseChange(int $linkId, int $type): void
    {
        if ($type === 2) {
            $carts = StoreOrderCartInfo::where('oid', $linkId)->where('cart_type', 0)->select();
            foreach ($carts as $cart) {
                $staffChoose = $this->buildStaffChooseFromExisting($linkId, 2, (int)$cart['cart_id'], (int)$cart['product_id']);
                if (!$staffChoose) {
                    continue;
                }
                $this->saveYeji([
                    'type' => 2,
                    'link_id' => $linkId,
                    'order_id' => $linkId,
                    'goods_id' => (int)$cart['product_id'],
                    'cart_id' => (int)$cart['cart_id'],
                    'once_price' => 0,
                    'true_price' => 0,
                    'value' => 0,
                    'write_times' => 0,
                    'staffChoose' => $staffChoose,
                ], false, true);
            }
            return;
        }
        if ($type === 1) {
            $staffChoose = $this->buildStaffChooseFromExisting($linkId, 1);
            if (!$staffChoose) {
                return;
            }
            $recharge = UserRecharge::where('id', $linkId)->find();
            $orderId = (int)StoreOrder::where('link_id', $linkId)->where('order_type', 1)->value('id');
            $cashPrice = '0';
            if ($orderId > 0) {
                $cashPrice = bcadd((string)(StoreOrder::where('id', $orderId)->value('cash_pay_price') ?? 0), '0', 2);
            } elseif ($recharge) {
                $cashPrice = bcadd((string)ValidCashOrderServices::calcOrderCashPayPrice(
                    $recharge['price'] ?? 0,
                    0,
                    (int)($recharge['cash_choose'] ?? 0),
                    [],
                    PayServices::CASH_PAY
                ), '0', 2);
            }
            $staffChoose = $this->splitYejiAmongStaff($staffChoose, $cashPrice);
            $this->saveYeji([
                'type' => 1,
                'link_id' => $linkId,
                'order_id' => $orderId,
                'goods_id' => 0,
                'cart_id' => 0,
                'price' => $cashPrice,
                'once_price' => $cashPrice,
                'true_price' => $cashPrice,
                'value' => 0,
                'write_times' => 0,
                'staffChoose' => $staffChoose,
            ], false, true);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildStaffChooseFromExisting(int $linkId, int $type, int $cartId = 0, int $goodsId = 0): array
    {
        $query = Db::name('staff_yeji')->where('link_id', $linkId)->where('type', $type);
        if ($type === 2) {
            $query->where('cart_id', $cartId)->where('goods_id', $goodsId);
        }
        $rows = $query->select();
        $staffChoose = [];
        foreach ($rows as $v) {
            $staffChoose[] = [
                'staff_id' => $v['staff_id'],
                'staff_name' => $v['staff_name'],
                'position_label' => $v['position_label'] ?? '',
                'position' => $v['position'],
                'position_level' => $v['position_level'],
                'position_level_label' => $v['position_level_label'],
                'yeji' => $v['yeji'],
                'deduct_card_yeji' => isset($v['deduct_card_yeji']) ? (float)$v['deduct_card_yeji'] : 0,
                'is_dian' => $v['is_dian'] ?? 0,
            ];
        }
        return $staffChoose;
    }

    /**
     * @param array<int, array<string, mixed>> $staffChoose
     * @return array<int, array<string, mixed>>
     */
    protected function splitYejiAmongStaff(array $staffChoose, string $cashPrice): array
    {
        $len = count($staffChoose);
        if ($len <= 0) {
            return $staffChoose;
        }
        if (bccomp($cashPrice, '0', 2) <= 0) {
            foreach ($staffChoose as &$row) {
                $row['yeji'] = 0;
            }
            unset($row);
            return $staffChoose;
        }
        $onceYeji = bcdiv($cashPrice, (string)$len, 2);
        $yu = bcsub($cashPrice, bcmul($onceYeji, (string)$len, 2), 2);
        $lastNk = $len - 1;
        foreach ($staffChoose as $k => &$row) {
            $row['yeji'] = $onceYeji;
            if (bccomp($yu, '0', 2) > 0 && $lastNk === $k) {
                $row['yeji'] = bcadd($onceYeji, $yu, 2);
            }
        }
        unset($row);
        return $staffChoose;
    }
}
