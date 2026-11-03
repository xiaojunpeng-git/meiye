<?php
declare(strict_types=1);
namespace app\services\activity\combination;

use app\services\user\MemberRewardCatalogServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\member\CashierV3DirectGiftIssuanceServices;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 拼团只负责奖励资格和冻结配置，赠送/撤销仍由共享权益领域写事实。
 * 开团回执是可靠工作队列，通知失败、重复支付回调和重试均不能多发；不生成销售业绩。
 */
final class CombinationLeaderRewardServices
{
    private const CONFIG = 'combination_leader_reward_config';
    private const REWARD = 'combination_leader_reward';
    private const ITEM = 'combination_leader_reward_item';

    /** 未启用或未配置奖励不影响拼团购买，也不为旧团补造开团快照。 */
    public function emptyConfig(): array
    {
        return ['enabled'=>false, 'store_policy'=>'member', 'store_id'=>0, 'product'=>[], 'coupon'=>[]];
    }

    /** 配置读取无写入；已开团永远读取自己的快照。 */
    public function config(int $activityId): array
    {
        $json = Db::name(self::CONFIG)->where('activity_id', $activityId)->value('config_json');
        return $json ? json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR) : $this->emptyConfig();
    }

    /** 与活动保存同事务；共用目录验证，禁止客户端传入配置管理员或任意门店SKU。 */
    public function saveConfigInTx(int $activityId, array $input, int $adminId): void
    {
        CashierV3TransactionGuard::assertInTransaction('combination.reward.config');
        Db::name('store_combination')->where('id',$activityId)->lock(true)->find();
        if ($adminId <= 0 || !Db::name('system_admin')->where('id',$adminId)->where('is_del',0)->where('status',1)->count())
            throw new ValidateException('奖励配置人不可用');
        if (isset($input['enabled']) && !is_bool($input['enabled'])) throw new ValidateException('奖励开关无效');
        $config = (new MemberRewardCatalogServices())->normalize(array_merge($input, ['store_policy'=>'member','store_id'=>0]));
        foreach ($config['product'] as $item) if ((int)$item['product_type'] !== 6) throw new ValidateException('团长奖励只支持项目和优惠券');
        $config['enabled'] = $input['enabled'] ?? false;
        unset($config['reward_each_round']);
        if ($config['enabled'] && !$config['product'] && !$config['coupon']) throw new ValidateException('请配置团长成团赠送内容');
        $row = Db::name(self::CONFIG)->where('activity_id',$activityId)->lock(true)->find();
        $data = ['config_json'=>$this->json($config), 'admin_id'=>$adminId, 'updated_at'=>time()];
        if ($row) Db::name(self::CONFIG)->where('activity_id',$activityId)->update($data);
        else Db::name(self::CONFIG)->insert($data + ['activity_id'=>$activityId,'created_at'=>time()]);
    }

    /** 仅在真实开团创建事务调用；即使配置为空也冻结，后改活动不会给旧团补奖。 */
    public function freezeGroupInTx(int $rootPinkId): void
    {
        CashierV3TransactionGuard::assertInTransaction('combination.reward.freeze');
        $root = Db::name('store_pink')->where('id',$rootPinkId)->lock(true)->find();
        if (!$root || (int)$root['k_id'] !== 0 || (int)$root['uid'] <= 0 || !empty($root['is_virtual'])) return;
        if (Db::name(self::REWARD)->where('group_id',$rootPinkId)->lock(true)->find()) return;
        $saved = Db::name(self::CONFIG)->where('activity_id',$root['cid'])->lock(true)->find();
        $config = $saved ? json_decode($saved['config_json'],true,512,JSON_THROW_ON_ERROR) : $this->emptyConfig();
        $store = (int)Db::name('user')->where('uid',$root['uid'])->value('belong_store_id');
        // 未归属会员的本次购买门店是唯一明确的办理门店；不用推广人门店替换团长权益归属。
        if ($store <= 0) $store = (int)Db::name('store_order')->where('id',$root['order_id_key'])->value('store_id');
        Db::name(self::REWARD)->insert(['group_id'=>$rootPinkId,'activity_id'=>(int)$root['cid'],
            'leader_order_id'=>(int)$root['order_id_key'],'member_id'=>(int)$root['uid'],'store_id'=>$store,
            'admin_id'=>(int)($saved['admin_id'] ?? 0),'config_snapshot'=>$this->json($config),
            'status'=>!empty($config['enabled'])?'waiting':'disabled','created_at'=>time(),'updated_at'=>time()]);
    }

    /** 未成团团长退款后的既有接任流程只转移开团配置，不按此刻活动配置重建或重复发奖。 */
    public function onLeaderChangedInTx(int $oldGroupId, int $newGroupId): void
    {
        CashierV3TransactionGuard::assertInTransaction('combination.reward.leaderChanged');
        $previous=Db::name(self::REWARD)->where('group_id',$oldGroupId)->lock(true)->find();
        if(!$previous)return; // 历史无配置团保持原状，不为其制造奖励。
        $root=Db::name('store_pink')->where('id',$newGroupId)->lock(true)->find();
        if(!$root||(int)$root['k_id']!==0||(int)$root['uid']<=0||!empty($root['is_virtual'])||(int)$root['status']!==1)
            throw new ValidateException('新团长身份或拼团状态不可用');
        if(Db::name(self::REWARD)->where('group_id',$newGroupId)->lock(true)->find())return;
        if(!in_array($previous['status'],['waiting','disabled'],true)||Db::name(self::ITEM)->where('reward_id',$previous['id'])->count())
            throw new ValidateException('已成团奖励不能通过更换团长重复领取');
        $store=(int)Db::name('user')->where('uid',$root['uid'])->value('belong_store_id');
        if($store<=0)$store=(int)Db::name('store_order')->where('id',$root['order_id_key'])->value('store_id');
        Db::name(self::REWARD)->insert(['group_id'=>$newGroupId,'activity_id'=>$previous['activity_id'],
            'leader_order_id'=>(int)$root['order_id_key'],'member_id'=>(int)$root['uid'],'store_id'=>$store,'admin_id'=>$previous['admin_id'],
            'config_snapshot'=>$previous['config_snapshot'],'status'=>$previous['status'],'created_at'=>time(),'updated_at'=>time()]);
        Db::name(self::REWARD)->where('id',$previous['id'])->update(['status'=>'revoked','cancel_reason'=>'原团长退款，已由团内其他会员接任','updated_at'=>time()]);
    }

    /** 权威成团状态已写入后调用；定时重放同样能恢复漏掉的调度，不依赖通知回执。 */
    public function onGroupCompleted(int $rootPinkId): void
    {
        $this->prepare($rootPinkId);
        $this->processGroup($rootPinkId);
    }

    /** 原团长订单成功全退才撤销奖励；请求退款或成员退款不能冒充成功终态。 */
    public function onLeaderOrderRefunded(int $orderId): void
    {
        $order = Db::name('store_order')->where('id',$orderId)->find();
        if (!$order || (int)$order['refund_status'] !== 2) return;
        $group = (int)Db::name(self::REWARD)->where('leader_order_id',$orderId)->value('group_id');
        if ($group > 0) $this->cancel($group,'团长原订单已全额退款');
    }

    /** 整团取消入口必须已完成取消状态；不修改拼团或资金，只撤销它已发的赠品。 */
    public function onGroupCancelled(int $rootPinkId): void
    {
        $root = Db::name('store_pink')->where('id',$rootPinkId)->find();
        if ($root && ((int)$root['status'] === 3 || (int)$root['is_refund'] === 1)) $this->cancel($rootPinkId,'拼团已取消');
    }

    /** 有界工作批次扫描持久回执；失败可重复运行，绝不从历史无快照团补发。 */
    public function tick(int $limit = 50): array
    {
        // 只选已到业务时点的回执，未成团不会占用队列；已发奖但退款回调中断也可补撤销。
        $groups = Db::name(self::REWARD)->alias('r')
            ->join('store_pink p','p.id=r.group_id')->join('store_order o','o.id=r.leader_order_id')
            ->where(function($q):void {
                $q->whereIn('r.status',['pending','revoke_pending'])->whereOr(function($q):void {
                    $q->whereIn('r.status',['waiting','issued'])->where(function($q):void {
                        $q->where('p.status',3)->whereOr('p.is_refund',1)->whereOr('o.refund_status',2)
                            ->whereOr(function($q):void { $q->where('r.status','waiting')->where('p.status',2); });
                    });
                });
            })->order('r.updated_at,r.id')->limit(max(1,min(200,$limit)))->column('r.group_id');
        foreach ($groups as $id) {
            try {
                $this->onGroupCancelled((int)$id);
                $order = (int)Db::name(self::REWARD)->where('group_id',$id)->value('leader_order_id');
                $this->onLeaderOrderRefunded($order);
                $this->onGroupCompleted((int)$id);
            } catch (\Throwable $error) {
                // 单团损坏不能阻塞队列后续；具体发放错误已经逐份记录。
                \think\facade\Log::error('拼团奖励调度失败 group='.(int)$id.' '.$error->getMessage());
            } finally {
                // 缺失SKU、停售模板等可恢复故障往队尾移动，不能饿死后续成功团。
                Db::name(self::REWARD)->where('group_id',$id)->update(['updated_at'=>time()]);
            }
        }
        return ['processed_groups'=>count($groups),'pending_items'=>(int)Db::name(self::ITEM)->whereIn('status',['pending','revoke_pending'])->count()];
    }

    /** 新选品接口沿用拼团商品/创建授权，不要求额外拥有拼团记录列表或调查管理权限。 */
    public function assertConfigurationAccess(array $admin): void
    {
        if(empty($admin['id']))throw new ValidateException('请重新登录');
        if(app()->make(\app\services\organization\EmployeeDataScopeServices::class)->isSuperAdmin($admin))return;
        $grants=app()->make(\app\services\system\SystemRoleServices::class)->getRolesByAuth((array)($admin['roles']??[]),1);
        foreach((array)$grants as $grant)if(in_array($grant['unique_auth']??'', ['marketing-store_combination','marketing-store_combination-create'],true))return;
        throw new ValidateException('无拼团商品配置权限');
    }

    /** 管理入口按现有拼团权限和实际奖励门店裁剪，会员不能猜ID重试别人的奖励。 */
    public function adminStatus(int $groupId, array $admin, bool $retry = false): array
    {
        $access = new CombinationGroupListServices(); $access->assertManagement($admin);
        $access->assertGroup($groupId,$admin);
        $reward = Db::name(self::REWARD)->where('group_id',$groupId)->find();
        if (!$reward) return ['status'=>'none','status_label'=>'该团未配置团长奖励','items'=>[]];
        $stores = $access->allowedStores($admin);
        if (is_array($stores) && !in_array((int)$reward['store_id'],array_map('intval',$stores),true)) throw new ValidateException('无权查看该团奖励');
        if ($retry) { $this->onLeaderOrderRefunded((int)$reward['leader_order_id']); $this->onGroupCancelled($groupId); $this->onGroupCompleted($groupId); }
        $reward = Db::name(self::REWARD)->where('group_id',$groupId)->find();
        $items = Db::name(self::ITEM)->where('reward_id',$reward['id'])->order('item_no')->select()->toArray();
        return ['status'=>$reward['status'],'status_label'=>$this->statusLabel($reward['status']), 'items'=>array_map(function($item) {
            $snapshot=json_decode($item['item_snapshot'],true);return ['id'=>(int)$item['id'],'name'=>$snapshot['store_name'],
                'status_label'=>$this->statusLabel($item['status']),'gift_id'=>$item['gift_id'],'message'=>$item['last_error']];
        },$items)];
    }

    /** 一次成功团只展开一次逐份奖励，虚拟补位的团直接终止奖励资格。 */
    private function prepare(int $groupId): void
    {
        Db::transaction(function() use ($groupId): void {
            $root=Db::name('store_pink')->where('id',$groupId)->lock(true)->find();
            $reward=Db::name(self::REWARD)->where('group_id',$groupId)->lock(true)->find();
            if (!$root || !$reward || $reward['status']!=='waiting' || (int)$root['status']!==2) return;
            $members=Db::name('store_pink')->where(function($q)use($groupId){$q->where('id',$groupId)->whereOr('k_id',$groupId);})->lock(true)->select()->toArray();
            $real=0;$virtual=false;
            foreach($members as $member) {
                if ((int)$member['uid']<=0 || !empty($member['is_virtual'])) { $virtual=true; continue; }
                $paid=Db::name('store_order')->where('id',$member['order_id_key'])->where('uid',$member['uid'])->where('paid',1)->count();
                // status=2是实际参加成功团的权威结果；普通团员之后退款不取消团长奖励资格。
                if ($paid && (int)$member['status']===2) $real++;
            }
            if ($virtual || $real < (int)$root['people']) {
                Db::name(self::REWARD)->where('id',$reward['id'])->update(['status'=>'ineligible','updated_at'=>time()]); return;
            }
            // 成团时 stop_time 已由唯一拼团命令改写为真实完成时间，补漏不能延长奖励有效期。
            $config=json_decode($reward['config_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $now=min(time(),max(1,(int)$root['stop_time']));$number=0;
            foreach(['product','coupon'] as $kind) foreach($config[$kind] as $item) for($i=0;$i<(int)$item['num'];$i++) {
                $snapshot=$item+['kind'=>$kind,'validity_end'=>date('Y-m-d',$now+(int)$item['write_days']*86400)];
                Db::name(self::ITEM)->insert(['reward_id'=>$reward['id'],'item_no'=>++$number,'item_snapshot'=>$this->json($snapshot),'status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
            }
            Db::name(self::REWARD)->where('id',$reward['id'])->update(['status'=>'pending','completed_at'=>$now,'updated_at'=>$now]);
        });
    }

    /** 取消先持久化意图，未发的不再发；已发逐份撤销，已用部分留待人工处理。 */
    private function cancel(int $groupId, string $reason): void
    {
        Db::transaction(function()use($groupId,$reason):void {
            Db::name('store_pink')->where('id',$groupId)->lock(true)->find();
            $row=Db::name(self::REWARD)->where('group_id',$groupId)->lock(true)->find();
            if(!$row||in_array($row['status'],['disabled','revoked','ineligible','manual_review'],true))return;
            Db::name(self::ITEM)->where('reward_id',$row['id'])->where('status','pending')->update(['status'=>'revoked','updated_at'=>time()]);
            Db::name(self::ITEM)->where('reward_id',$row['id'])->where('status','issued')->update(['status'=>'revoke_pending','updated_at'=>time()]);
            Db::name(self::REWARD)->where('id',$row['id'])->update(['status'=>'revoke_pending','cancel_reason'=>$reason,'updated_at'=>time()]);
        });
        $this->processGroup($groupId);
    }

    /** 锁同一回执后调用唯一赠送领域；每份独立事务允许安全重试和部分撤销。 */
    private function processGroup(int $groupId): void
    {
        $reward=Db::name(self::REWARD)->where('group_id',$groupId)->find();
        if(!$reward||!in_array($reward['status'],['pending','revoke_pending'],true))return;
        $ids=Db::name(self::ITEM)->where('reward_id',$reward['id'])->whereIn('status',['pending','revoke_pending'])->order('id')->limit(100)->column('id');
        foreach($ids as $id) {
            try { Db::transaction(function()use($groupId,$id):void {
                $root=Db::name('store_pink')->where('id',$groupId)->lock(true)->find();
                $reward=Db::name(self::REWARD)->where('group_id',$groupId)->lock(true)->find();
                $item=Db::name(self::ITEM)->where('id',$id)->lock(true)->find();
                if(!$item||!in_array($item['status'],['pending','revoke_pending'],true))return;
                $order=Db::name('store_order')->where('id',$reward['leader_order_id'])->lock(true)->find();
                if($item['status']==='pending' && ($reward['status']!=='pending'||!$root||(int)$root['status']!==2||!$order||(int)$order['refund_status']!==0)) return;
                [$operator,$scope,$member]=$this->scopes($reward);
                $key='CGR-'.$reward['id'].'-'.$item['item_no']; $recorder=new CashierV3BusinessEventRecorder();
                $execution=$recorder->newExecution('combination-leader-reward',$key.':'.$item['status'],$operator,$scope,'group:'.$groupId);
                $replayed=false;
                if($item['status']==='pending') {
                    $content=json_decode($item['item_snapshot'],true,512,JSON_THROW_ON_ERROR);
                    $catalog=$content['kind']==='product'?(new MemberRewardCatalogServices())->storeSkuForMaster((int)$content['id'],(int)$reward['store_id']):0;
                    $contract=$this->contract('gift.issued');
                    $result=(new CashierV3DirectGiftIssuanceServices())->issueInTx(['handlingStoreId'=>(int)$reward['store_id'],'reason'=>'拼团成团团长奖励 #'.$groupId,
                        'items'=>[['kind'=>$content['kind']==='coupon'?'coupon':'project','catalogItemId'=>$catalog,'couponIssueId'=>$content['kind']==='coupon'?(int)$content['id']:0,'quantity'=>1,'validityEnd'=>$content['validity_end']]]],
                        $member,$operator,$scope,$key,$recorder,$execution,$contract);
                    $changes=['status'=>'issued','gift_id'=>$result['giftId']];
                } else {
                    $fact=Db::name('cashier_v3_gift_fact')->where('source_type','direct_gift')->where('source_id',$item['gift_id'])->where('reversal_of','')->lock(true)->find();
                    if(!$fact)throw new ValidateException('奖励赠送事实缺失，请核对');
                    $contract=$this->contract('gift.voided');
                    $result=(new CashierV3OrderCenterVoidServices())->executeInTx('void-order-center-gift',[
                        'operator_scope'=>$operator,'data_scope'=>$scope,'event_recorder'=>$recorder,'event_execution'=>$execution,'event_contract'=>$contract,'idempotency_key'=>$key.':revoke',
                        'payload'=>['recordId'=>'v3-direct-gift:'.$fact['gift_fact_id'],'reason'=>$reward['cancel_reason']]]);
                    // 若此前已由订单中心安全作废，同一权威回执直接承认，不再写第二条冲销事件。
                    $replayed=!empty($result['data']['void']['replayed']);
                    $changes=['status'=>'revoked'];
                }
                if(!$replayed)$recorder->assertRequiredPersistedInTx($execution,$contract);
                Db::name(self::ITEM)->where('id',$id)->update($changes+['attempts'=>(int)$item['attempts']+1,'last_error'=>'','updated_at'=>time()]);
            }); } catch (\Throwable $error) {
                // 业务已使用不是失败重试；只标记本份待处理，不扣会员余额、不撤回其他已用权益。
                $used=$error instanceof CashierV3CommandException && in_array($error->getDetail()['reason']??'', ['gift_project_usage_exists','gift_project_projection_changed','gift_coupon_already_changed'],true);
                $message=$error instanceof CashierV3CommandException || $error instanceof ValidateException ? $error->getMessage() : '奖励处理暂未完成，请重试或联系管理员';
                if(!($error instanceof CashierV3CommandException) && !($error instanceof ValidateException)) \think\facade\Log::error('拼团奖励执行失败 item='.(int)$id.' '.$error->getMessage());
                $change=['last_error'=>mb_substr($message,0,500),'updated_at'=>time()];
                if($used)$change['status']='manual_review';
                Db::name(self::ITEM)->where('id',$id)->whereIn('status',['pending','revoke_pending'])->inc('attempts')->update($change);
            }
        }
        Db::transaction(function()use($groupId):void {
            $reward=Db::name(self::REWARD)->where('group_id',$groupId)->lock(true)->find();
            if(!$reward||!in_array($reward['status'],['pending','revoke_pending'],true))return;
            $states=Db::name(self::ITEM)->where('reward_id',$reward['id'])->column('status');
            if(array_intersect($states,['pending','revoke_pending']))return;
            $status=in_array('manual_review',$states,true)?'manual_review':($reward['status']==='revoke_pending'?'revoked':'issued');
            Db::name(self::REWARD)->where('id',$reward['id'])->update(['status'=>$status,'updated_at'=>time()]);
        });
    }

    /** 管理身份来自活动配置记录，会员和办理门店来自开团快照，不能冒充门店员工。 */
    private function scopes(array $reward): array
    {
        $admin=Db::name('system_admin')->where('id',$reward['admin_id'])->where('is_del',0)->where('status',1)->find();
        $store=(int)$reward['store_id'];$binding=Db::name('organization_store')->where('store_id',$store)->find();
        $member=Db::name('user')->where('uid',$reward['member_id'])->where('status',1)->lock(true)->find();
        if(!$admin||!$binding||!$member||!empty($member['is_del'])||(!empty($member['delete_time'])&&$member['delete_time']!=='0000-00-00 00:00:00')||!Db::name('system_store')->where('id',$store)->where('is_del',0)->count()) throw new ValidateException('团长奖励的会员、门店或配置人已不可用，请核对');
        $operator=new CashierV3OperatorScope($store,(int)$admin['id'],(string)$binding['org_id'],'0');
        $scope=new CashierV3DataScopeContext((int)$admin['id'],0,$store,'0',(string)$binding['org_id'],[$store],'stores',[],false,'','combination-group:'.$reward['group_id'],[],
            ['id'=>(int)$admin['id'],'actor_type'=>'admin','name'=>'拼团团长奖励自动处理','authority'=>'configured_combination_reward']);
        return [$operator,$scope,$member];
    }

    /** 礼物事实及其冲销是唯一事件；禁止加入销售、收款或人员业绩消费者。 */
    private function contract(string $event): array
    {
        return ['required_event_types'=>[$event],'allowed_event_types'=>[$event],
            'event_rules'=>[$event=>['min_count'=>1,'max_count'=>1,'aggregate_type'=>'direct_gift','source_type'=>$event==='gift.issued'?'submit-direct-gift':'void-order-center-gift','aggregate_version'=>$event==='gift.issued'?1:2]],
            'consumers'=>[$event=>['cashier_v3.direct_gift.reconcile']]];
    }

    private function statusLabel(string $state): string
    {
        return ['waiting'=>'待成团','disabled'=>'未开启奖励','pending'=>'待发放，可重试','issued'=>'已发放','ineligible'=>'虚拟补齐或人数不足，不发奖励','revoke_pending'=>'待撤销，可重试','revoked'=>'已撤销','manual_review'=>'部分奖励已使用，待人工处理'][$state]??'待核对';
    }
    private function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
}
