<?php
declare(strict_types=1);
namespace app\services\cashier\v3\order;
use think\facade\Db;

/** 只读拼团原购买与收款凭据；不把商城投影伪装为V3销售或业绩事实。 */
final class CashierV3GroupPurchaseFinancialProjection
{
    /** 输入只能是既有Reader已经按权限裁剪、分页后的订单；批读避免逐行查库。 */
    public function attach(array $rows): array
    {
        if (!$rows) return [];
        $ids=array_column($rows,'id');
        $orders=Db::name('store_order')->alias('o')->join('combination_order_fulfillment f','f.order_id=o.id')
            ->leftJoin('system_store s','s.id=o.store_id')->whereIn('o.id',$ids)->where('o.paid',1)->whereIn('f.state',['issued','delivering'])
            ->where('o.refund_status',0)->where('o.terminal_action',0)
            ->field('o.id,o.order_id,o.uid,o.store_id,o.pay_type,o.accounting_method,o.trade_no,o.pay_time,o.pay_price,o.total_price,o.cash_pay_price,o.yue_pay_price,o.debt_amount,o.coupon_price,o.total_postage,s.name AS current_store_name')->select()->toArray();
        $lines=Db::name('store_order_cart_info')->whereIn('oid',array_column($orders,'id'))->where('cart_type',0)->where('is_gift',0)->select()->toArray();
        $byOrder=[];foreach($lines as$l)$byOrder[(int)$l['oid']][]=$l;
        // 商户号仅从已付款渠道尝试读取，不使用当前可编辑商户配置冒充历史凭据。
        $attempts=Db::name('suixingpay_product_payment_attempt')->whereIn('order_id_key',array_column($orders,'id'))->where('status','paid')->select()->toArray();
        $attemptByOrder=[];foreach($attempts as$a)$attemptByOrder[(int)$a['order_id_key']]=$a;
        $evidence=[];foreach($orders as$o){$ls=$byOrder[(int)$o['id']]??[];if(count($ls)!==1)continue;$l=$ls[0];
            $paid=self::cents($o['pay_price']);$cash=self::cents($o['cash_pay_price']);$balance=self::cents($o['yue_pay_price']);$debt=self::cents($o['debt_amount']);$coupon=self::cents($o['coupon_price']);$linePaid=self::cents($l['pay_price']);
            // 当前拼团不支持欠款；只投影金额守恒且可核实的原支付，异常仍保留未知而非填零。
            if(min($paid,$cash,$balance,$debt,$coupon,$linePaid,self::cents($o['total_price']),self::cents($l['total_price']))<0||$debt!==0||$cash+$balance!==$paid||$linePaid!==$paid||self::cents($o['total_postage'])!==0)continue;
            if($cash>0&&!in_array($o['pay_type'],['weixin','alipay'],true))continue;
            $a=$attemptByOrder[(int)$o['id']]??null;$merchant='';
            if($a&&(string)$a['business_order_no']===(string)$o['order_id']&&(int)$a['amount_cents']===$paid&&(string)$a['transaction_id']===(string)$o['trade_no'])$merchant=(string)$a['merchant_id'];
            $evidence[(int)$o['id']]=compact('o','l','paid','cash','balance','debt','coupon','merchant');
        }
        foreach($rows as&$row)if(isset($evidence[(int)$row['id']]))$row['_groupFinancial']=$evidence[(int)$row['id']];unset($row);return$rows;
    }

    /** 列表、详情、统一筛选与导出消费同一投影；业务命令仍不能借用这些字段获得V3操作权限。 */
    public static function project(array $mapped,array $row): array
    {
        $e=$row['_groupFinancial']??null;if(!$e)return$mapped;
        $o=$e['o'];$l=$e['l'];$amount=$e['paid']/100;$coupon=$e['coupon']/100;$cash=$e['cash']/100;
        $mapped['storeName']=$mapped['store']=(string)$o['current_store_name'];$mapped['storeSnapshotStatus']='current_store_label';
        foreach(['receivableAmount'=>$amount,'receivable_amount'=>$amount,'actualReceivedAmount'=>$cash,'actual_received_amount'=>$cash,'debtAmount'=>0.0,'debt_amount'=>0.0,'couponDiscountAmount'=>$coupon,'coupon_discount_amount'=>$coupon,'amountBeforeCoupon'=>$amount+$coupon]as$k=>$v)$mapped[$k]=$v;
        $methods=[];$pay=[];$labels=['weixin'=>['wechat','微信'],'alipay'=>['alipay','支付宝']];
        if($cash>0&&isset($labels[$o['pay_type']])){[$code,$name]=$labels[$o['pay_type']];$methods[]=$name;$pay[]=['id'=>'group-payment-'.$o['id'],'paymentMethodCode'=>$code,'methodName'=>$name,'amount'=>$cash,'externalTransactionNo'=>(string)$o['trade_no'],'remark'=>$e['merchant']!==''?'随行付商户号：'.$e['merchant']:'','occurredAt'=>date('Y-m-d H:i:s',(int)$o['pay_time'])];}
        if($e['balance']>0){
            $methods[]='余额支付';
            // 新手机单统一记账名称，但余额仍为内部资金扣减，不写外部到账事实。
            if(\app\services\member\MemberOrderAccounting::isThirdParty($o))$pay[]=[
                'id'=>'group-balance-'.$o['id'],'paymentMethodCode'=>'member_balance','methodName'=>'余额支付',
                'amount'=>$e['balance']/100,'externalTransactionNo'=>'','remark'=>'',
                'occurredAt'=>date('Y-m-d H:i:s',(int)$o['pay_time'])];
        }$mapped['paymentDetails']=$pay;$mapped['paymentMethod']=$mapped['payment_method']=implode('、',$methods);$mapped['paymentDetailsDataStatus']='original_order_snapshot';
        // 主购买明细承担销售金额，cart_type=2权益载体不重复计入。
        foreach($mapped['items']as&$item)if((int)$item['orderItemId']===(int)$l['id']){$q=(float)$l['cart_num'];$item['unitPrice']=$q>0?($amount+$coupon)/$q:null;$item['originalAmount']=$item['amountBeforeCoupon']=$amount+$coupon;$item['payableAmount']=$amount;$item['actualReceivedAmount']=$cash;$item['couponDiscountAmount']=$coupon;$item['debtAmount']=0.0;}unset($item);
        $mapped['amountSummary']=['originalAmount'=>self::cents($o['total_price'])/100,'priceChangeDiscountAmount'=>null,'couponDiscountAmount'=>$coupon,'otherDiscountAmount'=>null,'payableAmount'=>$amount,'debtAmount'=>0.0,'actualReceivedAmount'=>$cash,'balancePaymentAmount'=>$e['balance']/100,'dataStatus'=>'original_order_snapshot'];
        // 明确保留原系统来源；不能因此启用V3退款/作废命令或宣称拥有V3现金业绩快照。
        $mapped['economicsDataStatus']='original_order_snapshot';
        // 保留 pay_type 和 trade_no 原始凭证，创建字段缺省的历史/旧待付款单不变。
        if(\app\services\member\MemberOrderAccounting::isThirdParty($o)) {
            foreach($mapped['paymentDetails'] as &$payment)if($payment['paymentMethodCode']!=='member_balance')$payment['paymentMethodCode']='third_party';
            unset($payment);
        }
        return \app\services\member\MemberOrderAccounting::project($mapped,$o,(string)$o['pay_type']);
    }
    private static function cents($value): int
    {
        if(!is_scalar($value)||!preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$value))return-1;
        return(int)bcmul((string)$value,'100',0);
    }
}
