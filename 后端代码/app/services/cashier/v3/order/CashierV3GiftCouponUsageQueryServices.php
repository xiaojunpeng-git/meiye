<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\activity\coupon\StoreCouponAllowanceServices;
use think\facade\Db;

/** Read-only per-instance usage of coupons issued by one authorized gift fact. No issuance or checkout state is written. */
final class CashierV3GiftCouponUsageQueryServices
{
    private const PAGE_SIZE = 20;

    public function query(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $recordId = trim((string)($payload['recordId'] ?? ''));
        if (!$scope->hasFeature('cashier.v3.order_center')
            || preg_match('/^(v3-direct-gift|v3-gift):([A-Za-z0-9:._-]{1,160})$/D', $recordId, $match) !== 1) {
            throw self::notFound();
        }
        $kind = $match[1] === 'v3-direct-gift' ? 'direct_gift' : 'recharge_gift';
        $base = $kind === 'direct_gift' ? 'cashier_v3_direct_gift' : 'cashier_v3_recharge_gift';
        $allowed = $scope->narrowVisibleStores(isset($payload['storeIds']) && is_array($payload['storeIds']) ? $payload['storeIds'] : null);
        if ($allowed === []) throw self::notFound();
        $query = Db::name('cashier_v3_gift_fact')->alias('f')
            ->join($base.'_authority a','a.tenant_id=f.tenant_id AND a.gift_id=f.source_id')
            ->join($base.'_item i','i.gift_id=f.source_id AND i.item_id=f.source_detail_id')
            ->where('f.tenant_id',$scope->tenantId())->where('f.gift_fact_id',$match[2])
            ->where('f.source_type',$kind)->where('f.gift_kind','coupon')->where('f.status','effective')
            ->whereIn('a.status',['issued','voided'])->whereIn('i.status',['issued','voided']);
        if ($allowed !== null) $query->whereIn('f.store_id',$allowed);
        $gift = $query->field('f.member_id,f.store_id,f.quantity,f.coupon_issue_id,a.gift_no,i.coupon_user_ids_json,i.coupon_issue_id AS item_issue_id,i.quantity AS item_quantity')->find();
        if (!$gift) throw self::notFound();
        // Authorization starts with the immutable gift and its exact issued IDs. Never accept arbitrary coupon IDs or match a title.
        $ids = json_decode((string)$gift['coupon_user_ids_json'],true);
        $quantity = (int)$gift['quantity'];
        if (!is_array($ids) || $quantity < 1 || $quantity > 1000 || count($ids) !== $quantity
            || (int)$gift['item_quantity'] !== $quantity || (int)$gift['coupon_issue_id'] !== (int)$gift['item_issue_id']) {
            throw self::incomplete();
        }
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) throw self::incomplete();
        if (count(array_unique($ids)) !== $quantity) throw self::incomplete();
        sort($ids,SORT_NUMERIC);
        $page = min(max(1,(int)($payload['page'] ?? 1)),max(1,(int)ceil($quantity/self::PAGE_SIZE)));
        $pageIds = array_slice($ids,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE);
        $rows = Db::name('store_coupon_user')->alias('c')->leftJoin('store_coupon_issue t','t.id=c.cid')
            ->whereIn('c.id',$pageIds)
            ->field('c.id,c.uid,c.original_recipient_uid,c.cid,c.coupon_title,c.coupon_price,c.status,c.is_fail,c.use_time,c.start_time,c.end_time,c.oid,t.coupon_type')
            ->select()->toArray();
        $byId = [];
        foreach ($rows as $row) {
            // A transferred coupon keeps the original gift recipient. Do not expose the new holder's name, phone or profile.
            $original = (int)$row['original_recipient_uid'] ?: (int)$row['uid'];
            if ($original !== (int)$gift['member_id'] || (int)$row['cid'] !== (int)$gift['coupon_issue_id']) throw self::incomplete();
            $byId[(int)$row['id']] = $row;
        }
        $allowanceIds = array_column(array_filter($rows,static function(array $r):bool {return (int)$r['coupon_type']===StoreCouponAllowanceServices::TYPE;}),'id');
        $balances = (new StoreCouponAllowanceServices())->remainingByCouponIds($allowanceIds);
        $uses = $this->latestUses($rows,$scope->tenantId());
        $records = []; $now = time();
        foreach ($pageIds as $id) {
            if (!isset($byId[$id])) {
                $records[] = ['couponNo'=>(string)$id,'couponName'=>'—','usageStatus'=>'资料缺失','availabilityStatus'=>'不可用',
                    'usedAt'=>null,'orderNo'=>null,'remainingAmount'=>null];
                continue;
            }
            $row = $byId[$id]; $multi = (int)$row['coupon_type'] === StoreCouponAllowanceServices::TYPE;
            $remaining = null; $total = null; $unknown = false;
            if ($multi) {
                try {
                    $total = StoreCouponAllowanceServices::wholeCents($row['coupon_price']);
                    // Lazy allowance accounts are absent before the first use. A timestamp without an account is not proof of a full balance.
                    $remaining = array_key_exists($id,$balances) ? (int)$balances[$id]
                        : ((int)$row['use_time']===0 && (int)$row['status']===0 ? $total : null);
                    $unknown = $remaining === null || $remaining < 0 || $remaining > $total;
                } catch (\Throwable $e) { $unknown = true; }
            }
            $used = $multi ? (!$unknown && $remaining < $total)
                : ((int)$row['status']===1 || (int)$row['use_time']>0);
            $usage = $unknown ? '资料待核实' : ($multi && $used && $remaining>0 ? '部分使用' : ($used ? '已使用' : '未使用'));
            $availability = (int)$row['is_fail']!==0 || (int)$row['status']===2 ? '已作废'
                : ((int)$row['end_time']>0 && (int)$row['end_time']<$now ? '已过期'
                    : ((int)$row['start_time']>$now ? '未生效' : '有效'));
            $use = $used ? ($uses[$id] ?? []) : [];
            $usedAt = (int)($use['time'] ?? (!$multi && $used ? $row['use_time'] : 0));
            $records[] = ['couponNo'=>(string)$id,'couponName'=>(string)$row['coupon_title'],'usageStatus'=>$usage,
                'availabilityStatus'=>$availability,'usedAt'=>$usedAt>0?date('Y-m-d H:i:s',$usedAt):null,
                'orderNo'=>$use['orderNo'] ?? null,
                'remainingAmount'=>$multi && !$unknown ? bcdiv((string)$remaining,'100',2) : null];
        }
        return ['recordId'=>$recordId,'giftRecordNo'=>(string)$gift['gift_no'],'records'=>$records,
            'total'=>$quantity,'page'=>$page,'pageSize'=>self::PAGE_SIZE,'dataAsOf'=>time()];
    }

    /** Batch last successful use only. Reversed allowance debits and editing checkout drafts never become usage. */
    private function latestUses(array $coupons,string $tenant):array
    {
        $uses=[];$ordinary=[];$multi=[];$members=[];$legacy=[];
        foreach($coupons as $coupon){
            $id=(int)$coupon['id'];
            if((int)$coupon['coupon_type']===StoreCouponAllowanceServices::TYPE)$multi[]=$id;
            else {$ordinary[]=$id;$members[]=(int)$coupon['uid'];}
            if((int)$coupon['oid']>0)$legacy[$id]=(int)$coupon['oid'];
        }
        if($ordinary){
            // Coupon IDs alone have no sale-line index. The current holder set also bounds idx_member_order and avoids a tenant-wide scan.
            $keys=Db::name('cashier_v3_sales_order_line')->alias('l')
                ->join('cashier_v3_sales_order o','o.tenant_id=l.tenant_id AND o.order_id=l.order_id')
                ->where('l.tenant_id',$tenant)->whereIn('l.member_id',array_values(array_unique($members)))
                ->whereIn('l.coupon_user_id',$ordinary)->where('o.order_status','settled')
                ->field('MAX(l.id) last_id')->group('l.coupon_user_id')->select()->toArray();
            if($keys)foreach(Db::name('cashier_v3_sales_order_line')->alias('l')
                ->join('cashier_v3_sales_order o','o.tenant_id=l.tenant_id AND o.order_id=l.order_id')
                ->whereIn('l.id',array_column($keys,'last_id'))->where('l.tenant_id',$tenant)
                ->field('l.coupon_user_id,o.order_no,o.settled_at')->select()->toArray() as $row)
                $uses[(int)$row['coupon_user_id']]=['orderNo'=>(string)$row['order_no'],'time'=>(int)$row['settled_at']];
            // Whole-order coupons after the first one are bound here rather than repeated on the sale line.
            $keys=Db::name('cashier_v3_checkout_order_coupon')->where('tenant_id',$tenant)->whereIn('coupon_user_id',$ordinary)
                ->where('status','consumed')->field('MAX(id) last_id')->group('coupon_user_id')->select()->toArray();
            if($keys)foreach(Db::name('cashier_v3_checkout_order_coupon')->alias('b')
                ->join('cashier_v3_sales_order o','o.tenant_id=b.tenant_id AND o.order_id=b.order_id')
                ->whereIn('b.id',array_column($keys,'last_id'))->where('b.tenant_id',$tenant)->where('o.order_status','settled')
                ->field('b.coupon_user_id,o.order_no,o.settled_at')->select()->toArray() as $row){
                    $id=(int)$row['coupon_user_id'];
                    if(!isset($uses[$id]) || (int)$row['settled_at'] >= $uses[$id]['time'])
                        $uses[$id]=['orderNo'=>(string)$row['order_no'],'time'=>(int)$row['settled_at']];
                }
        }
        if($multi){
            $keys=Db::name('coupon_allowance_movement')->alias('m')->where('m.tenant_id',$tenant)
                ->whereIn('m.coupon_user_id',$multi)->where('m.delta_cents','<',0)
                ->whereNotExists(static function($q){$q->name('coupon_allowance_movement')->alias('rev')->whereRaw('rev.reversal_of=m.id');})
                ->field('MAX(m.id) last_id')->group('m.coupon_user_id')->select()->toArray();
            if($keys)foreach(Db::name('coupon_allowance_movement')->alias('m')
                ->leftJoin('cashier_v3_sales_order o','o.tenant_id=m.tenant_id AND o.order_id=m.order_id')
                ->whereIn('m.id',array_column($keys,'last_id'))->where('m.tenant_id',$tenant)
                ->field('m.coupon_user_id,m.created_at,o.order_no')->select()->toArray() as $row)
                $uses[(int)$row['coupon_user_id']]=['orderNo'=>$row['order_no'] !== null?(string)$row['order_no']:null,'time'=>(int)$row['created_at']];
        }
        if($legacy){
            $orders=Db::name('store_order')->whereIn('id',array_values($legacy))->where('paid',1)->field('id,order_id')->select()->toArray();
            $orders=array_column($orders,'order_id','id');
            foreach($coupons as $coupon){$id=(int)$coupon['id'];if(!isset($uses[$id]) && isset($orders[(int)$coupon['oid']]))
                $uses[$id]=['orderNo'=>(string)$orders[(int)$coupon['oid']],'time'=>(int)$coupon['use_time']];}
        }
        return $uses;
    }

    private static function notFound():CashierV3CommandException
    {return new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND,'该赠券记录不存在或当前无权查看。');}
    private static function incomplete():CashierV3CommandException
    {return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,'赠券关联资料不完整，请联系管理员核对。');}
}
