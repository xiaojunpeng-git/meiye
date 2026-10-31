<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** 报表只读转卡证明：原购买人不被改写，当前资产权限仍由权益权威先核验。 */
final class ReportCardTransferProofServices
{
    /** 返回可追溯持有人链；缺正式状态、断链或身份冲突一律不放行。 */
    public function memberIds(int $holder, int $order, int $original, int $current): array
    {
        if (min($holder,$order,$original,$current)<1) return [];
        if ($original===$current) return [$original];
        $state=Db::name('cashier_v3_card_state')->where('tenant_id','0')->where('card_holder_id',$holder)
            ->field('current_member_id')->find();
        if (!$state || (int)$state['current_member_id']!==$current) return [];
        $rows=Db::name('cashier_v3_card_operation')->where('tenant_id','0')->where('source_card_holder_id',$holder)
            ->where('origin_order_id',$order)->where('operation_type','card_transfer')->where('operation_status','succeeded')
            ->order('id','asc')->field('origin_member_id,member_id_before,member_id_after')->limit(1001)->select()->toArray();
        return self::verify($original,$current,$rows);
    }

    /** 按成功操作的稳定顺序验证整链，不能仅凭最后一条 after 就承认任意旧会员。 */
    public static function verify(int $original,int $current,array $rows): array
    {
        if (min($original,$current)<1 || count($rows)>1000) return [];
        $cursor=$original;$members=[$original];
        foreach($rows as $row){
            if((int)$row['origin_member_id']!==$original || (int)$row['member_id_before']!==$cursor
                || (int)$row['member_id_after']<1 || (int)$row['member_id_after']===$cursor)return [];
            $cursor=(int)$row['member_id_after'];$members[]=$cursor;
        }
        return $cursor===$current ? array_values(array_unique($members)) : [];
    }
}
