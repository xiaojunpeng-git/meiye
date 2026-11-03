<?php
declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/** 对账收款到订单中心的只读身份桥。日期沿用收款Reader，订单日期、金额及历史事实均不改写。 */
final class StoreReconciliationOrderNavigation
{
    /** 每次查询/翻页/导出重新按当前权限与当前收款事实定位，浏览器不能提交可扩权的订单ID人口。 */
    public function selection(array $context, array $input): array
    {
        $input = self::normalize($input);
        $stores = array_map('intval', $context['visible_store_ids'] ?? []);
        if (!in_array($input['storeId'], $stores, true)) throw new \InvalidArgumentException('对账门店不在当前可查看范围内。');
        // 只收窄可信上下文；不新增业务权限、不把平台管理员替换为门店员工。
        $context['visible_store_ids'] = [$input['storeId']];
        $context['basic_scope_mode'] = 'stores';
        $result = (new StoreReconciliationReportServices())->query($context, [
            'start_date'=>$input['from'], 'end_date'=>$input['to'],
            'reconciliation_mode'=>'stores', 'detail'=>['method_code'=>$input['methodCode'], 'column_key'=>'store_'.$input['storeId']],
        ]);
        if (!empty($result['navigation_error'])) throw new \InvalidArgumentException($result['navigation_error']);
        return ['ids'=>$result['navigation_record_ids'], 'documents'=>$result['navigation_document_nos']];
    }

    /** UI查询及导出冻结计划共用严格的条件白名单，只保存日期/门店/渠道，不保存金额或客户端订单人口。 */
    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['storeId','methodCode','from','to'])
            || !is_int($input['storeId'] ?? null) || $input['storeId'] < 1
            || !is_string($input['methodCode'] ?? null)
            || !preg_match('/^(legacy_[1-9][0-9]*|v3_[a-z_]+)$/D', $input['methodCode'])) {
            throw new \InvalidArgumentException('对账订单定位条件无效，请返回对账单重新查询。');
        }
        foreach (['from','to'] as $key) {
            $date = $input[$key] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) throw new \InvalidArgumentException('请选择有效的对账下单日期。');
        }
        if ($input['from'] > $input['to']) throw new \InvalidArgumentException('对账开始日期不能晚于结束日期。');
        return ['storeId'=>$input['storeId'],'methodCode'=>$input['methodCode'],'from'=>$input['from'],'to'=>$input['to']];
    }

    /** 主键、租户、门店、会员和原单号同时吻合才使用正式销售头，禁止只按相同单号去重或猜测导入前缀。 */
    public function recordIds(array $receipts, string $tenant): array
    {
        $legacyIds = []; $nativeIds = [];
        foreach ($receipts as $r) {
            $ref = $r['order_reference'] ?? null;
            if (!is_array($ref) || !is_string($ref['type'] ?? null) || !is_string($ref['id'] ?? null) || $ref['id'] === '') throw new \InvalidArgumentException('收款缺少可核实的原单身份。');
            if ($ref['type'] === 'legacy_store_order') $legacyIds[] = $ref['id'];
            else $nativeIds[] = $ref['id'];
        }
        if (count(array_unique($legacyIds)) + count(array_unique($nativeIds)) > 100) throw new \InvalidArgumentException('本格涉及超过100张订单，请缩小对账日期范围。');
        $legacy = $legacyIds ? Db::name('store_order')->whereIn('id', $legacyIds)
            ->field('id,order_id,store_id,uid,pid,link_id,order_type,is_debt_repay,pay_time')->select()->toArray() : [];
        $legacy = array_column($legacy, null, 'id');
        $headers = [];
        if ($legacyIds) foreach (Db::name('cashier_v3_sales_order')->where('tenant_id',$tenant)
            ->where('source_document_type','legacy_store_order')->whereIn('source_document_id',$legacyIds)
            ->where('order_status','settled')->where('order_direction','forward')
            ->field('order_id,order_no,store_id,member_id,source_document_id')->select()->toArray() as $h) $headers['legacy:'.$h['source_document_id']][] = $h;
        if ($nativeIds) foreach (Db::name('cashier_v3_sales_order')->where('tenant_id',$tenant)->whereIn('order_id',$nativeIds)
            ->where('order_status','settled')->where('order_direction','forward')
            ->field('order_id,order_no,store_id,member_id')->select()->toArray() as $h) $headers['native:'.$h['order_id']][] = $h;
        $ids = [];
        foreach ($receipts as $r) {
            $ref = $r['order_reference']; $old = $ref['type'] === 'legacy_store_order';
            $matches = array_values(array_filter($headers[($old?'legacy:':'native:').$ref['id']] ?? [], static function ($h) use ($r): bool {
                return (int)$h['store_id'] === (int)$r['store_id'] && (int)$h['member_id'] === (int)$r['member_id'] && $h['order_no'] === $r['document_no'];
            }));
            if (count($matches) > 1) throw new \InvalidArgumentException('收款对应多个正式订单，请核对原单。');
            if ($matches) $id = 'sales:'.$matches[0]['order_id'];
            elseif ($old) {
                $o = $legacy[$ref['id']] ?? [];
                if (!$o || (int)$o['store_id'] !== (int)$r['store_id'] || (int)$o['uid'] !== (int)$r['member_id'] || $o['order_id'] !== $r['document_no']) throw new \InvalidArgumentException('历史收款原单身份不一致。');
                if (!empty($o['is_debt_repay'])) {
                    $matches = Db::name('store_debt_repay')->where('repay_order_id',(int)$o['id'])
                        ->where('pay_store_id',(int)$r['store_id'])->where('uid',(int)$r['member_id'])->column('id');
                    if (count($matches) !== 1) throw new \InvalidArgumentException('历史补交收款缺少唯一原单关联。');
                    $id = 'supplement:legacy-supplement:'.$matches[0];
                } elseif ((int)$o['pid'] === -2 && (int)$o['link_id'] > 0) {
                    // 旧充值壳单沿现有充值关联及收款时间窗口核实，不按两个系统的单号文本猜测。
                    $recharge = Db::name('user_recharge')->where('id',(int)$o['link_id'])->where('store_id',(int)$r['store_id'])
                        ->where('uid',(int)$r['member_id'])->whereBetween('add_time',[(int)$o['pay_time']-3600,(int)$o['pay_time']+3600])->find();
                    if (!$recharge) throw new \InvalidArgumentException('历史充值收款缺少原单关联。');
                    $id = 'recharge:recharge:'.$o['link_id'];
                } elseif ((int)$o['order_type'] === 0) $id = 'sales:legacy-sale-'.$ref['id'];
                else throw new \InvalidArgumentException('历史收款尚未关联到可查看的业务单据。');
            } elseif ($ref['type'] === 'recharge' && preg_match('/^RCH:([1-9][0-9]*)$/D',$ref['id'],$m)) {
                $o = Db::name('user_recharge')->where('id',(int)$m[1])->where('store_id',(int)$r['store_id'])->where('uid',(int)$r['member_id'])->where('order_id',$r['document_no'])->find();
                if (!$o) throw new \InvalidArgumentException('充值收款原单身份不一致。');
                $id = 'recharge:recharge:'.$m[1];
            } elseif (in_array($ref['type'],['debt_repayment','recharge_debt_repayment'],true)) {
                $sales = $ref['type'] === 'debt_repayment';
                $o = Db::name($sales?'cashier_v3_debt_repayment':'cashier_v3_recharge_debt_repayment')->where('tenant_id',$tenant)
                    ->where('repayment_id',$ref['id'])->where('store_id',(int)$r['store_id'])->where('member_id',(int)$r['member_id'])->where('repayment_no',$r['document_no'])->find();
                if (!$o) throw new \InvalidArgumentException('补交收款原单身份不一致。');
                $id = 'supplement:'.($sales?'v3-sales-supplement:':'v3-supplement:').$ref['id'];
            } else throw new \InvalidArgumentException('收款尚未关联到可查看的业务单据。');
            $ids[$id] = true;
        }
        if (count($ids) > 100) throw new \InvalidArgumentException('本格涉及超过100张订单，请缩小对账日期范围。');
        return array_keys($ids);
    }
}
