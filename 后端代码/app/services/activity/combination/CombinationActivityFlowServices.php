<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 活动流水是一笔已付款原订单一行的只读投影，不是新增收款事实。
 * 权限先限定本人订单的购买门店，成功支付尝试按原交易号/金额匹配；重复凭据不任选一条。
 * 筛选先经统一执行器验证，再以同一SQL供分页、全量合计和导出使用。
 */
class CombinationActivityFlowServices
{
    private const PAGE = 'combination_activity_flow';

    /** 复用拼团业务域的组织/门店权限树，不另建一套范围选择口径。 */
    public function options(array $admin): array
    {
        $scope=new CombinationGroupListServices(); $scope->assertManagement($admin);
        return ['columns'=>$this->columns(),'scope'=>$scope->options($admin),
            'table_layout'=>['fixed'=>true,'summary_first'=>true]];
    }

    /** 列、来源说明、筛选类型和固定布局由后端统一声明，浏览器不推断渠道或金额。 */
    public function columns(): array
    {
        $specs = [
            ['member_name','会员','text',130,'付款订单中的姓名；缺失时显示会员档案姓名或昵称。'],
            ['phone','手机','text',130,'付款订单预留手机；缺失时显示会员档案手机。'],
            ['paid_amount','付款金额','amount',105,'该订单原始实付金额，含已退款订单；不是扣除退款后的净额。'],
            ['paid_at','付款时间','datetime',170,'订单确认支付成功的时间，不使用下单或成团时间。'],
            ['payment_status','订单状态','text',105,'成功全额退款后为已退款，其余已付款订单为已支付；退款处理中仍保留原付款事实。'],
            ['leader_name','团长','text',125,'当前所属拼团的团长姓名；历史参与记录缺失时留空。'],
            ['group_status','拼团状态','text',110,'当前团状态；未成团且已超时显示拼团失败，缺少团记录显示未记录。'],
            ['provider_name','原支付渠道','text',120,'原退款凭据或唯一匹配的成功支付尝试所记渠道；缺失或多义显示待核实，不读取现支付开关。'],
            ['channel_order_no','原渠道订单号','text',220,'原退款凭据或唯一成功支付尝试保存的渠道订单号。'],
            ['channel_transaction_id','原支付交易号','text',240,'原退款凭据或唯一成功支付尝试保存的交易号，余额支付不伪造渠道流水。'],
            ['channel_refund_id','渠道退款流水号','text',210,'已保存的原渠道退款响应流水号；尚无凭据时留空。'],
            ['fuyou_refund_trace_no','退款追踪号','text',200,'原退款响应保存的退款追踪号，不更换或推测渠道。'],
            ['refund_no','商户退款单号','text',220,'原退款申请保存的商户退款单号；无申请时留空。'],
        ];
        return array_map(static function ($s) {
            return ['key'=>$s[0], 'title'=>$s[1], 'type'=>$s[2], 'width'=>$s[3], 'source'=>$s[4],
                'fixed'=>in_array($s[0], ['member_name','phone'], true) ? 'left' : '',
                'summable'=>$s[0] === 'paid_amount',
                'operators'=>$s[2] === 'text' ? ['equal','not_equal','contains','is_null','is_not_null']
                    : ['equal','greater_or_equal','less_or_equal','between']];
        }, $specs);
    }

    /** 只读查询使用同一快照；合计在分页之前执行，不以当页或浏览器内容相加。 */
    public function page(int $activityId, array $input, array $admin, bool $export = false): array
    {
        $scope = new CombinationGroupListServices();
        $scope->assertManagement($admin);
        if ($activityId <= 0 || !Db::name('store_combination')->where('id', $activityId)->count())
            throw new ValidateException('拼团商品不存在');
        $allowed = $scope->allowedStores($admin);
        $plan = $this->plan($input);
        $query = $this->query($activityId, $input, $allowed, $plan);
        return Db::transaction(function () use ($query, $plan, $export, $activityId, $allowed) {
            $totals = (clone $query)->fieldRaw('COUNT(*) AS total,COALESCE(SUM(paid_amount),0) AS amount')->select()->toArray()[0];
            $count = (int)$totals['total'];
            $amount = bcadd((string)$totals['amount'], '0', 2);
            if ($export && $count > 10000) throw new ValidateException('导出超过10000条，请缩小筛选范围');
            $page = min($plan['pagination']['page'], max(1, (int)ceil($count / 20)));
            $rows = (clone $query)->order('order_id_key desc')->page($export ? 1 : $page, $export ? max(1,$count) : 20)->select()->toArray();
            foreach ($rows as &$row) $row = $this->present($row);
            unset($row);
            $columns = $this->columns();
            if ($export) {
                $keys = array_column($columns, 'key');
                // 合计作为导出第一行；金额保持原精度，不能使用页面的整元显示值回写。
                $summary = array_fill_keys($keys, ''); $summary['member_name'] = '合计金额'; $summary['paid_amount'] = $amount;
                return ['header'=>array_column($columns,'title'), 'filekey'=>$keys,
                    'filename'=>'活动流水-'.$activityId, 'export'=>array_merge([$summary], array_map(static function ($r) use ($keys) {
                        return array_intersect_key($r, array_fill_keys($keys,true));
                    },$rows)), 'sources'=>array_column($columns,'source','title'), 'count'=>$count, 'summary'=>['paid_amount'=>$amount]];
            }
            return ['list'=>$rows,'count'=>$count,'page'=>$page,'limit'=>20,'columns'=>$columns,
                'summary'=>['paid_amount'=>$amount], 'table_layout'=>['fixed'=>true,'summary_first'=>true],
                'data_as_of'=>date('c'),'metric_version'=>'combination-original-payment-v1','aggregation_caught_up'=>true];
        });
    }

    /** 使用共享查询验证器的字段和类型白名单；未知字段、运算符及异常值直接拒绝。 */
    private function plan(array $input): array
    {
        $filters = $input['filters'] ?? [];
        if (is_string($filters)) $filters = json_decode($filters, true);
        if (!is_array($filters)) throw new ValidateException('筛选条件无效');
        foreach (['date_from'=>'greater_or_equal','date_to'=>'less_or_equal'] as $key=>$op) {
            if (!empty($input[$key])) {
                $date = (string)$input[$key];
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d',$date);
                if (!$d || $d->format('Y-m-d') !== $date) throw new ValidateException('付款日期无效');
                $filters[] = ['fieldKey'=>'paid_at','operator'=>$op,'value'=>$date.($key==='date_from'?' 00:00:00':' 23:59:59')];
            }
        }
        if (!empty($input['date_from']) && !empty($input['date_to']) && $input['date_from'] > $input['date_to'])
            throw new ValidateException('付款日期范围无效');
        $registry = new UnifiedQueryPageRegistry(); $fields=[];
        foreach ($this->columns() as $c) $fields[] = UnifiedQueryPageRegistry::field($c['key'],$c['title'],$c['type'],true,false,['filter']);
        $registry->registerPage(self::PAGE,'活动流水',$fields,'order_id_key'); $registry->freeze();
        $engine = new UnifiedQueryExecutionServices($registry,new StructuredExpressionValidator($registry),new StructuredExpressionEvaluator());
        $plan = $engine->validatedPlan(self::PAGE,[],['filters'=>$filters,'page'=>max(1,(int)($input['page']??1)),'limit'=>20],['query_cutoff_date'=>date('Y-m-d')]);
        $columns = array_column($this->columns(),null,'key');
        foreach ($plan['filters'] as $f) if (!in_array($f['operator'],$columns[$f['field_key']]['operators'],true))
            throw new ValidateException('该字段不支持所选筛选方式');
        return $plan;
    }

    /** SQL只处理已验证计划中的稳定字段别名，所有值仍由ORM绑定；不接受任意SQL。 */
    protected function query(int $activityId, array $input, ?array $allowed, array $plan)
    {
        $source = $this->source($activityId, $input, $allowed)->buildSql();
        $q = Db::table([$source=>'flow']);
        foreach ($plan['filters'] as $f) {
            $field=$f['field_key']; $op=$f['operator']; $value=$f['value'];
            // 原时间戳按PHP统一业务时区解析，避免MySQL服务器处于UTC导致日期筛选偏移8小时。
            if ($field==='paid_at') $value=is_array($value) ? array_map('strtotime',$value) : strtotime((string)$value);
            if ($op==='contains') $q->whereLike($field,'%'.addcslashes((string)$value,'\\%_').'%');
            elseif ($op==='between') {
                $reversed=$field==='paid_amount' ? bccomp((string)$value[0],(string)$value[1],2)>0 : $value[0]>$value[1];
                if ($reversed) throw new ValidateException('筛选区间起止顺序无效');
                $q->whereBetween($field,$value);
            } elseif ($op==='is_null') $q->where($field,'');
            elseif ($op==='is_not_null') $q->where($field,'<>','');
            else $q->where($field,['equal'=>'=','not_equal'=>'<>','greater_or_equal'=>'>=','less_or_equal'=>'<='][$op],$value);
        }
        return $q;
    }

    /** 从原订单开始，未建团/已退款历史也保留；参与记录与渠道尝试均先归并，防止金额倍增。 */
    private function source(int $activityId, array $input, ?array $allowed)
    {
        $orders = Db::name('store_order')->where('type',3)->where('activity_id',$activityId)->where('paid',1);
        // 空范围必须拒绝全部，不能让占位0碰巧匹配历史缺失门店的付款订单。
        if ($allowed !== null) $orders->whereIn('store_id',$allowed ?: [0])->where('store_id','>',0);
        $selected = $input['store_ids'] ?? [];
        if (is_string($selected)) $selected = $selected === '' ? [] : explode(',',$selected);
        if (!is_array($selected) || count($selected)>1000) throw new ValidateException('门店筛选无效');
        foreach ($selected as $id) if (!ctype_digit((string)$id) || (int)$id<=0) throw new ValidateException('门店筛选无效');
        if ($selected) $orders->whereIn('store_id',array_map('intval',$selected));
        // Raw仅封装本方法由ORM生成的权限子查询，绝不接收请求提供的SQL。
        $ids = Db::raw((clone $orders)->field('id')->buildSql());
        $participants = Db::name('store_pink')->where('is_virtual',0)->whereIn('order_id_key',$ids)
            ->fieldRaw('order_id_key,MIN(id) AS participant_id,COUNT(*) AS participant_count')->group('order_id_key')->buildSql();
        $sx = Db::name('suixingpay_product_payment_attempt')->alias('a')->join('store_order so','so.id=a.order_id_key')
            ->whereIn('so.id',$ids)->where('a.status','paid')->whereColumn('a.business_order_no','so.order_id')
            ->whereColumn('a.transaction_id','so.trade_no')->whereRaw('a.amount_cents=so.pay_price*100')
            ->fieldRaw('so.id,COUNT(*) AS matches,MAX(a.channel_order_no) AS channel_order_no,MAX(a.transaction_id) AS transaction_id')->group('so.id')->buildSql();
        $fy = Db::name('fuyou_payment_attempt')->alias('a')->join('store_order so','so.order_id=a.business_order_no')
            ->whereIn('so.id',$ids)->where('a.status','paid')->where('a.business_type','product')
            ->whereColumn('a.transaction_id','so.trade_no')->whereRaw('a.amount_cents=so.pay_price*100')
            ->fieldRaw('so.id,COUNT(*) AS matches,MAX(a.channel_order_no) AS channel_order_no,MAX(a.transaction_id) AS transaction_id')->group('so.id')->buildSql();
        $q = Db::name('store_order')->alias('o')->whereIn('o.id',$ids)->leftJoin('user u','u.uid=o.uid')
            ->leftJoin([$participants=>'ps'],'ps.order_id_key=o.id')->leftJoin('store_pink p','p.id=ps.participant_id AND ps.participant_count=1')
            ->leftJoin('store_pink g','g.id=IF(p.k_id=0,p.id,p.k_id) AND g.k_id=0')
            ->leftJoin('store_order leader_order','leader_order.id=g.order_id_key')->leftJoin('user leader_user','leader_user.uid=g.uid')
            ->leftJoin('combination_order_refund r','r.order_id=o.id')->leftJoin([$sx=>'sx'],'sx.id=o.id')->leftJoin([$fy=>'fy'],'fy.id=o.id');
        $unique='(COALESCE(sx.matches,0)+COALESCE(fy.matches,0)=1)';
        $provider="COALESCE(NULLIF(r.provider,''),CASE WHEN o.pay_type='yue' THEN 'balance' WHEN $unique AND sx.matches=1 THEN 'suixingpay' WHEN $unique AND fy.matches=1 THEN 'fuyou' ELSE '' END)";
        return $q->fieldRaw("o.id AS order_id_key,o.order_id AS business_order_no,o.store_id,o.refund_status,o.status AS order_status,o.terminal_action,o.is_system_del,o.pay_price AS paid_amount,COALESCE(ps.participant_count,0) AS participant_count,
            COALESCE(NULLIF(o.real_name,''),NULLIF(u.real_name,''),u.nickname,'') AS member_name,COALESCE(NULLIF(o.user_phone,''),u.phone,'') AS phone,
            o.pay_time AS paid_at,
            IF(o.refund_status=2 OR r.state='success','已退款','已支付') AS payment_status,
            COALESCE(NULLIF(leader_order.real_name,''),NULLIF(leader_user.real_name,''),g.nickname,'') AS leader_name,
            COALESCE(g.id,0) AS group_id,COALESCE(g.status,0) AS root_status,
            CASE WHEN g.status=2 THEN '拼团成功' WHEN g.status=3 OR (g.status=1 AND g.stop_time<=".time().") THEN '拼团失败' WHEN g.status=1 THEN '拼团中' ELSE '未记录' END AS group_status,
            $provider AS provider,
            CASE $provider WHEN 'balance' THEN '余额' WHEN 'fuyou' THEN '富友' WHEN 'suixingpay' THEN '随行付' ELSE '待核实' END AS provider_name,
            COALESCE(NULLIF(r.channel_order_no,''),CASE WHEN o.pay_type<>'yue' AND $unique THEN COALESCE(sx.channel_order_no,fy.channel_order_no) ELSE '' END,'') AS channel_order_no,
            COALESCE(NULLIF(r.channel_transaction_id,''),CASE WHEN o.pay_type<>'yue' AND $unique THEN COALESCE(sx.transaction_id,fy.transaction_id) ELSE '' END,'') AS channel_transaction_id,
            COALESCE(r.channel_refund_id,'') AS channel_refund_id,COALESCE(r.fuyou_refund_trace_no,'') AS fuyou_refund_trace_no,COALESCE(r.refund_no,'') AS refund_no,COALESCE(r.state,'') AS refund_state");
    }

    /** 列表只给保守的操作入口，不进行锁定/冻结；真正退款仍走原命令的事务及权益校验。 */
    private function present(array $row): array
    {
        $row['paid_at']=(int)$row['paid_at']>0 ? date('Y-m-d H:i:s',(int)$row['paid_at']) : '';
        $row['paid_amount'] = bcadd((string)$row['paid_amount'],'0',2);
        $row['refund_action']=''; $row['refund_block_reason']='';
        if ($row['payment_status']==='已退款') $row['refund_block_reason']='已退款';
        elseif ((int)$row['participant_count']!==1 || !(int)$row['group_id']) $row['refund_block_reason']='缺少唯一参团记录，请核对原订单';
        elseif ((int)$row['root_status']===2) $row['refund_block_reason']='已成团，请在拼团列表办理整团退款';
        elseif ($row['provider']==='') $row['refund_block_reason']='原支付凭据缺失或不唯一';
        elseif (!in_array($row['refund_state'],['','failed','success'],true)) $row['refund_action']='query';
        elseif ((int)$row['terminal_action'] || (int)$row['is_system_del'] || (int)$row['order_status']!==0 || bccomp($row['paid_amount'],'0',2)<=0)
            $row['refund_block_reason']='订单不满足快捷退款条件';
        else $row['refund_action']=$row['refund_state']==='failed'?'retry':'refund';
        return $row;
    }

    /** 退款前重新按活动、原订单、本人购买门店和整团权限核验，不能相信浏览器隐藏字段。 */
    public function assertRefundOrder(int $activityId, int $orderId, array $admin): int
    {
        $scope=new CombinationGroupListServices(); $scope->assertManagement($admin);
        $order=Db::name('store_order')->where('id',$orderId)->where('activity_id',$activityId)->where('type',3)->where('paid',1)->find();
        $allowed=$scope->allowedStores($admin);
        if (!$order || ($allowed!==null && !in_array((int)$order['store_id'],$allowed,true))) throw new ValidateException('订单不存在或无权退款');
        $p=Db::name('store_pink')->where('order_id_key',$orderId)->where('is_virtual',0)->select()->toArray();
        if (count($p)!==1) throw new ValidateException('缺少唯一参团记录，不能退款');
        $id=(int)$p[0]['k_id'] ?: (int)$p[0]['id'];
        $root=$scope->assertGroup($id,$admin,true);
        if ((int)$root['cid']!==$activityId || (int)$root['status']===2) throw new ValidateException('拼团成功后请使用整团退款');
        return $id;
    }
}
