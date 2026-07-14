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
namespace app\controller\admin\v1\yeji;

use app\controller\admin\AuthController;

use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\yeji\StaffYeji;
use app\model\yeji\CashType;
use app\model\product\product\StoreProduct;
use app\model\store\SystemStoreStaff;
use app\model\user\UserRecharge;
use app\model\yeji\YejiCommission;
use app\Request;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use app\services\store\SystemStoreStaffServices;
use app\services\yeji\SatffYejiServices;
use app\services\yeji\YejiCommissionServices;
use app\services\yeji\YejiRangeServices;
use think\facade\App;
use think\facade\Db;

/**
 * 订单管理
 * Class StoreOrder
 * @package app\controller\admin\v1\order
 */
class Yeji extends AuthController
{


    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, SatffYejiServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function yeji(Request $request)
    {
        $where = $request->getMore([
            ['keyword', ''],
            ['created_time'],//时间
            ['type', ''],
            ['link_id', ''],
            ['order_id', ''],
        ]);
        $yejiService=app()->make(SatffYejiServices::class);
        return app('json')->success($yejiService->getList($where));
    }

    //业绩区间
    public function yejiRange(Request $request){
        $where = $request->getMore([

        ]);
        $yejiService=app()->make(YejiRangeServices::class);
        return app('json')->success($yejiService->getList($where));
    }

    //业绩提成
    public function yejiCommission(Request $request){
        $where = $request->getMore([
                ['keyword', '']
        ]);
        $yejiService=app()->make(YejiCommissionServices::class);
        return app('json')->success($yejiService->getList($where));
    }

    public function saveRange(Request $request,$id){
        $data = $request->postMore([
            ['yeji_min',0],
            ['yeji_max',0],
        ]);
        $yejiService=app()->make(YejiRangeServices::class);
        $yejiService->saveRange($data,$id);
        return $this->success('设置成功!');
    }

    public function saveCommission(Request $request,$id){
        $data = $request->all();
        unset($data['id']);
        $yejiService=app()->make(YejiCommissionServices::class);
        $yejiService->saveCommission($data,$id);
        return $this->success('设置成功!');
    }
    public function delRange($id){
        if (!$id) {
            return app('json')->fail('缺少参数ID');
        }
        $yejiService=app()->make(YejiRangeServices::class);
         $yejiService->delete((int)$id);
        return app('json')->success('删除成功');
    }

    public function delCommission($id){
        if (!$id) {
            return app('json')->fail('缺少参数ID');
        }
        $yejiService=app()->make(YejiCommissionServices::class);
        $yejiService->delete((int)$id);
        return app('json')->success('删除成功');
    }

    public function setCommission(Request $request){
        $data = $request->getMore([
            ['id', 0]
        ]);
        $yejiService=app()->make(YejiCommissionServices::class);
        return $this->success($yejiService->edit($data['id']));
    }

    public function yejiColumn(){
        $yejiService=app()->make(YejiCommissionServices::class);
        return $this->success($yejiService->getColumn());
    }

    public function setRange(Request $request){
        $data = $request->getMore([
            ['id', 0]
        ]);
        $yejiService=app()->make(YejiRangeServices::class);
        return $this->success($yejiService->edit($data['id']));
    }

    public function allList(Request $request, SystemStoreStaffServices $services)
    {
        $where = $request->postMore([
            ['name', ''],
            ['keyword', ''],
            ['store_id', ''],
        ]);
        if ($where['name'] === '' && $where['keyword'] !== '') {
            $where['name'] = $where['keyword'];
        }
        unset($where['keyword']);
        $where['is_del'] = 0;
        $where['status'] = 1;
        return app('json')->success($services->geAllList($where));
    }

    //保存业绩（与门店后台一致，便于总后台门店订单维护）
    public function save_yeji(){
        $data = $this->request->postMore([
            ['link_id', 0],
            ['cart_id', 0],
            ['goods_id', 0],
            ['type', 0],
            ['price', 0],
            ['once_price', 0],
            ['order_id', 0],
            ['true_price', 0],
            ['value', 0],
            ['write_times', 0],
            ['staffChoose', []],
        ]);
        if($data['type'] == 2){
            $order=StoreOrder::where("id",$data['link_id'])->find();
            $productType=StoreProduct::where("id",$data['goods_id'])->value("product_type");
            if($order['pay_type'] == 'yue' && $productType !== 0){
                return app('json')->fail('该订单为余额支付类型不可设置销售业绩！');
            }
        }
        $staffYeji = app()->make(SatffYejiServices::class);
        $staffYeji->saveYeji($data,false,true);
        return app('json')->success('成功！');
    }

    //获取业绩 '类型 1充值 2购卡 3消耗'
    public function getYeji(){
        $data = $this->request->postMore([
            ['link_id', 0],
            ['cart_id', 0],
            ['type', 0],
            ['goods_id', 0],
            ['price', 0]
        ]);
        $yeji=[];
        switch ($data['type']){
            case 1:
                $data['price']=UserRecharge::where("id",$data['link_id'])->value("price");
                $orderId=StoreOrder::where("link_id",$data['link_id'])->where("order_type",1)->value("id");
                $yeji=[
                    'goods_id'=>0,
                    'price'=>$data['price'],
                    'once_price'=>$data['price'],
                    'true_price'=>$data['price'],
                    'write_times'=>0,
                    'value'=>0,
                    'link_id'=>$data['link_id'],
                    'order_id'=>$orderId,
                    'type'=>$data['type'],
                    'staffChoose'=>[]
                ];
                break;
            case 2:
                $order = StoreOrder::where('id', $data['link_id'])->find();
                $productId = StoreOrderCartInfo::where('oid', $data['link_id'])->where('cart_type', 0)->value('product_id');
                $mainId = 8154;
                $productIds = StoreProduct::where('pid', $mainId)->column('id');
                $productIds[] = $mainId;
                $cartRow = StoreOrderCartInfo::where('oid', $data['link_id'])->where('cart_id', $data['cart_id'])->find();
                if (in_array($productId, $productIds)) {
                    $cashPrice = bcadd((string)($order['cash_pay_price'] ?? '0'), '0', 2);
                    $balancePrice = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
                } else {
                    if ($cartRow) {
                        if (isset($cartRow['cash_pay_amount']) && $cartRow['cash_pay_amount'] !== '' && $cartRow['cash_pay_amount'] !== null) {
                            $cashPrice = bcadd((string)$cartRow['cash_pay_amount'], '0', 2);
                        } else {
                            $cashPrice = bcsub(
                                (string)$cartRow['pay_price'],
                                bcadd((string)($cartRow['yue_pay_amount'] ?? 0), (string)($cartRow['card_upgrade_amount'] ?? 0), 2),
                                2
                            );
                        }
                        $balancePrice = bcadd((string)($cartRow['yue_pay_amount'] ?? '0'), '0', 2);
                    } else {
                        $cashPrice = (string)$data['price'];
                        $balancePrice = '0';
                    }
                }
                $yeji=[
                    'goods_id'=>$data['goods_id'],
                    'cart_id'=>$data['cart_id'],
                    'price'=>$cashPrice,
                    'balance_price'=>$balancePrice,
                    'once_price'=>$cashPrice,
                    'true_price'=>$cashPrice,
                    'write_times'=>0,
                    'value'=>0,
                    'link_id'=>$data['link_id'],
                    'order_id'=>$data['link_id'],
                    'type'=>$data['type'],
                    'staffChoose'=>[]
                ];
                break;
            case 3:
                $writeroff=StoreOrderWriteoff::where('id',$data['link_id'])->find();
                $product_id=$writeroff['product_id'];
                $pid=StoreProduct::where('id',$product_id)->value("pid");
                if(empty($pid)){
                    $pid=$product_id;
                }
                $data['goods_id']=$product_id;
                $oncePrice=YejiCommission::where("product_id",$pid)->value("yeji");
                if(empty($oncePrice)){
                    $oncePrice=0;
                }
                $price=bcmul($oncePrice,$writeroff['writeoff_num']);
                $yeji=[
                    'goods_id'=>$product_id,
                    'price'=>$price,
                    'once_price'=>$oncePrice,
                    'true_price'=>$oncePrice,
                    'write_times'=>0,
                    'value'=>$writeroff['writeoff_num'],
                    'link_id'=>$data['link_id'],
                    'order_id'=>$writeroff['oid'],
                    'type'=>$data['type'],
                    'staffChoose'=>[]
                ];
                break;
        }
        $has=Db::name("staff_yeji")
            ->where("link_id",$data['link_id'])
            ->where("goods_id",$data['goods_id'])
            ->where("type",$data['type']);
        if($data['type'] == 2){
             $has=$has->where("cart_id",$data['cart_id']);
        }
        $has=$has->select();
        foreach ($has as $k=>$v){
            $yeji['staffChoose'][]=[
                'staff_id'=>$v['staff_id'],
                'staff_name'=>$v['staff_name'],
                'position_label'=>$v['position_label'] ?? '',
                'position'=>$v['position'],
                'position_level'=>$v['position_level'],
                'position_level_label'=>$v['position_level_label'],
                'yeji'=>(float)($v['yeji'] ?? 0),
                'deduct_card_yeji'=>isset($v['deduct_card_yeji']) ? (float)$v['deduct_card_yeji'] : 0,
                'is_dian'=>$v['is_dian']
            ];
        }
        if (isset($yeji['price'])) {
            $yeji['price'] = (float)$yeji['price'];
        }
        if (isset($yeji['once_price'])) {
            $yeji['once_price'] = (float)$yeji['once_price'];
        }
        if (isset($yeji['true_price'])) {
            $yeji['true_price'] = (float)$yeji['true_price'];
        }
        if (isset($yeji['balance_price'])) {
            $yeji['balance_price'] = (float)$yeji['balance_price'];
        }
        return app('json')->successful('成功！',$yeji);
    }

    public function getCartYeji(Request $request){
        $data = $request->postMore([
            ['id',0],
        ]);
        $cartInfo=StoreOrderCartInfo::where("cart_id",$data['id'])->find();
        $staffs=StaffYeji::where("type",2)
            ->where("goods_id",$cartInfo['product_id'])
            ->where("link_id",$cartInfo['oid'])
            ->where("cart_id",$data['id'])
            ->column("staff_name");
        $result['yeji_staff']=implode(",",$staffs);
        return app('json')->success('ok',$result);
    }

    //获得订单备注信息
    public function getRemark(){
        $data = $this->request->postMore([
            ['order_id', 0],
            ['type']
        ]);
        if($data['type'] == 1){
            $order=StoreOrder::where('id',$data['order_id'])->find();
            $payType=$order['pay_type'];
        }else{
            $order=UserRecharge::where('id',$data['order_id'])->find();
            $payType=$order['recharge_type'];
        }
        $types=CashType::where("status",1)->select();
        $cashTypes=[];
        foreach ($types as $k=>$v){
            $cashTypes[$v['id']]=$v['name'];
        }
        $pay_type_name = $cashTypes[$order['cash_choose']] ?? '';
        $result['cash_types']=$types;
        $result['the_type']=$data['type'];
        $result['pay_type']=$payType;
        $result['cash_choose']=$order['cash_choose'];
        $result['pay_type_name']=$pay_type_name;
        $result['type']=1;
        $result['list']=[];
        $result['remark_info']=json_decode($order['remark_info'],true);
        if($payType == PayServices::COMBINATION_PAY){
             $result['type']=2;
             $result['list']=CombinationOrder::where($data)->select();
             foreach ($result['list'] as &$nv){
                 $nv['remarkInfo']=json_decode($nv['remarkInfo'],true);
             }
        }
        if(empty($result['remark_info'])){
            $result['remark_info']=[];
        }
        return app('json')->successful('成功！',$result);
    }

    /**
     * 修改现金收款付款方式（与门店后台一致）
     */
    public function saveRemark()
    {
        $data = $this->request->postMore([
            ['id', 0],
            ['type', 0],
            ['cash_choose', 0],
        ]);
        if (empty($data['cash_choose'])) {
            return $this->fail('请选择付款方式！');
        }
        $newCashChoose = (int)$data['cash_choose'];
        if ((int)$data['type'] === 1) {
            $order = StoreOrder::where('id', $data['id'])->find();
            $payTypeField = 'pay_type';
        } else {
            $order = UserRecharge::where('id', $data['id'])->find();
            $payTypeField = 'recharge_type';
        }
        if (empty($order)) {
            return $this->fail('订单记录不存在');
        }
        if (isset($order['order_type']) && (int)$order['order_type'] === 2) {
            return $this->fail('核销订单不支持修复付款方式！');
        }
        if (($order[$payTypeField] ?? '') !== PayServices::CASH_PAY) {
            return $this->fail('只有现金收款方式才允许修改！');
        }
        $oldCashChoose = (int)($order['cash_choose'] ?? 0);
        if ($oldCashChoose === $newCashChoose) {
            return $this->success('成功');
        }
        Db::transaction(function () use ($data, $newCashChoose) {
            $staffYeji = app()->make(SatffYejiServices::class);
            if ((int)$data['type'] === 1) {
                ValidCashOrderServices::syncStoreOrderCashPay((int)$data['id'], $newCashChoose);
                $staffYeji->recalcSalesYejiAfterCashChooseChange((int)$data['id'], 2);
            } else {
                ValidCashOrderServices::syncRechargeOrderCashPay((int)$data['id'], $newCashChoose);
                $staffYeji->recalcSalesYejiAfterCashChooseChange((int)$data['id'], 1);
            }
        });
        return $this->success('成功');
    }

    /**
     * 修改订单来源
     */
    public function saveSource()
    {
        $data = $this->request->postMore([
            ['id', 0],
            ['source', 0],
        ]);
        if (empty($data['source'])) {
            return $this->fail('请选择来源！');
        }
        $order = StoreOrder::where('id', $data['id'])->find();
        if (empty($order)) {
            return $this->fail('订单记录不存在');
        }
        if ((int)$order['order_type'] === 2) {
            return $this->fail('核销订单不支持修改来源！');
        }
        $order->source = $data['source'];
        $order->save();
        return $this->success('成功');
    }

    /**
     * 修改跟单状态
     */
    public function saveGendan()
    {
        $data = $this->request->postMore([
            ['id', 0],
            ['is_gendan', 0],
            ['gendan_staff_id', 0],
        ]);
        if (!$data['id']) {
            return $this->fail('请选择订单');
        }
        $order = StoreOrder::where('id', $data['id'])->find();
        if (empty($order)) {
            return $this->fail('订单记录不存在');
        }
        $gendanStaffId = (int)($data['gendan_staff_id'] ?? 0);
        $order->gendan_staff_id = $gendanStaffId > 0 ? $gendanStaffId : 0;
        $order->is_gendan = $gendanStaffId > 0 ? 1 : ((int)($data['is_gendan'] ?? 0) ? 1 : 0);
        $order->save();
        return $this->success('成功');
    }
}
