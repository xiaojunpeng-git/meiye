<?php
declare(strict_types=1);
namespace app\services\user;

use think\facade\Db;
use think\exception\ValidateException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\member\CashierV3DirectGiftIssuanceServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;

/** 调查奖励履约适配器：答卷记录应发，010独立赠送服务拥有权益、事件、领用和核销权威。 */
final class MemberSurveyGiftServices
{
    private function configurations():MemberSurveyRewardConfigurationServices{return new MemberSurveyRewardConfigurationServices();}
    /** 奖励只由调查任务配置；已发布任务继续读取原任务的冻结快照。 */
    public function config(int $id):array
    {
        $task=Db::name('member_survey_task')->where('id',$id)->find();if(!$task)throw new ValidateException('调查任务不存在');
        return $this->configurations()->getConfig(1,$id);
    }
    /** 只允许保存草稿任务奖励，已发布奖励不能被重新覆盖。 */
    public function saveConfig(int $id,array $input):void
    {
        Db::transaction(function()use($id,$input):void{
            $entity=Db::name('member_survey_task')->where('id',$id)->lock(true)->find();
            if(!$entity||$entity['status']!=='draft')throw new ValidateException('只可配置草稿任务奖励');
            $this->configurations()->saveConfig(1,$id,$this->normalize($input));
        });
    }
    /** 发布事务中冻结任务奖励内容和有效期；不再从问卷继承旧模板。 */
    public function freezeTaskConfig(int $taskId):void
    {
        Db::name('member_survey_reward_config')->where('gift_type',1)->where('relation_id',$taskId)->lock(true)->find();
        $config=$this->configurations()->getConfig(1,$taskId);
        $this->configurations()->saveConfig(1,$taskId,$this->normalize($config));
    }
    /** 公开扫码不猜最近到店；会员归属门店由答卷提交事务保证。 */
    public function validateTargets(int $taskId,array $uids):void{$this->normalize($this->config($taskId));}
    /** 对会员仅展示奖励名称和数量，不输出后台目录或管理身份。 */
    public function summary(int $taskId):array
    {
        $out=[];foreach(['product','coupon']as$kind)foreach($this->config($taskId)[$kind]as$row)$out[]=['type'=>$kind,'name'=>$row['store_name'],'quantity'=>$row['num'],'label'=>$kind==='coupon'?'优惠券':'品项'];return$out;
    }
    /** 调查和拼团复用目录与保存校验，调查任务的发布/答卷边界仍在本服务。 */
    public function options(int $storeId,string $keyword=''):array { return (new MemberRewardCatalogServices())->options($storeId,$keyword); }
    public function catalog(int $storeId,array $filters):array { return (new MemberRewardCatalogServices())->catalog($storeId,$filters); }
    private function normalize(array $input):array { return (new MemberRewardCatalogServices())->normalize($input); }

    /** 必须与唯一答卷同事务；本记录只代表应发，不以pending状态冒充权益已到账。 */
    public function enqueueInTx(int $taskId,int $uid,int $answerId,int $round):int
    {
        \app\services\cashier\v3\CashierV3TransactionGuard::assertInTransaction('survey.reward.enqueue');
        $config=$this->config($taskId);if(!$config['product']&&!$config['coupon'])return 0;
        // 新配置在会员首次选店和答卷同一事务中冻结归属；历史固定门店配置继续按原快照履约。
        if(($config['store_policy']??'')==='member'){
            $config['store_id']=(int)Db::name('user')->where('uid',$uid)->value('belong_store_id');
            if($config['store_id']<=0)throw new ValidateException('请先选择归属门店再提交问卷');
        }
        $key='survey:'.$taskId.':'.$uid.':'.($config['reward_each_round']?$round:0);
        $existing=Db::name('member_survey_reward')->where('reward_key',$key)->lock(true)->find();if($existing)return(int)$existing['id'];
        return(int)Db::name('member_survey_reward')->insertGetId(['task_id'=>$taskId,'uid'=>$uid,'answer_id'=>$answerId,'reward_round'=>$round,'reward_key'=>$key,'config_snapshot'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'status'=>'pending','created_at'=>time(),'updated_at'=>time()]);
    }
    /** 每次履约锁同一应发记录；失败只回滚本次赠送，答卷和应发关系继续保留。 */
    public function fulfil(int $id):array
    {
        if(!$id)return['status'=>'none','message'=>'问卷已提交'];
        try{return Db::transaction(function()use($id):array{
            $request=Db::name('member_survey_reward')->where('id',$id)->lock(true)->find();if(!$request)throw new ValidateException('奖励记录不存在');
            if($request['status']==='issued')return['status'=>'issued','gift_id'=>$request['gift_id'],'message'=>'奖励已发放'];
            $answer=Db::name('member_survey_answer')->where('id',$request['answer_id'])->where('task_id',$request['task_id'])->where('uid',$request['uid'])->find();
            $task=Db::name('member_survey_task')->where('id',$request['task_id'])->find();
            if(!$answer||!$task||(int)$task['published_at']<=0)throw new ValidateException('奖励来源答卷或发布记录缺失');
            $config=json_decode($request['config_snapshot'],true,512,JSON_THROW_ON_ERROR);$store=(int)$config['store_id'];
            $admin=Db::name('system_admin')->where('id',$task['creator_id'])->where('admin_type',0)->where('is_del',0)->where('status',1)->find();
            $binding=Db::name('organization_store')->where('store_id',$store)->find();
            $member=Db::name('user')->where('uid',$request['uid'])->where('status',1)->lock(true)->find();
            if(!$admin||!$binding||!$member||!empty($member['is_del'])||(!empty($member['delete_time'])&&$member['delete_time']!=='0000-00-00 00:00:00')||!Db::name('system_store')->where('id',$store)->where('is_del',0)->count())throw new ValidateException('奖励发放主体或会员不可用，请由总部核对');
            // 可信主体来自已发布任务的总部管理员及答卷提交时冻结的会员归属门店，绝不采用扫码参数。
            $scope=new CashierV3OperatorScope($store,(int)$admin['id'],(string)$binding['org_id'],'0');
            $dataScope=new CashierV3DataScopeContext((int)$admin['id'],0,$store,'0',(string)$binding['org_id'],[$store],'stores',[],false,'','survey-task:'.$task['id'],[],['id'=>(int)$admin['id'],'actor_type'=>'admin','name'=>'调查任务自动发放（'.$admin['account'].'）','authority'=>'published_member_survey']);
            $items=[];foreach(['product','coupon']as$kind)foreach($config[$kind]as$row){
                $catalogItemId=$kind==='product'?(int)$row['id']:0;
                if($catalogItemId>0&&($config['store_policy']??'')==='member')$catalogItemId=(new MemberRewardCatalogServices())->storeSkuForMaster($catalogItemId,$store);
                $items[]=['kind'=>$kind==='coupon'?'coupon':((int)$row['product_type']===6?'project':'product'),'catalogItemId'=>$catalogItemId,'couponIssueId'=>$kind==='coupon'?$row['id']:0,'quantity'=>$row['num'],'validityEnd'=>date('Y-m-d',time()+(int)$row['write_days']*86400)];
            }
            $key='SVY-'.substr(hash('sha256',$request['reward_key']),0,48);$recorder=new CashierV3BusinessEventRecorder();$execution=$recorder->newExecution('survey-reward',$key,$scope,$dataScope,'survey:'.$request['id']);
            $contract=['required_event_types'=>['gift.issued'],'allowed_event_types'=>['gift.issued'],'event_rules'=>['gift.issued'=>['min_count'=>count($items),'max_count'=>count($items),'aggregate_type'=>'direct_gift','source_type'=>'submit-direct-gift','aggregate_version'=>1]],'consumers'=>['gift.issued'=>['cashier_v3.direct_gift.reconcile']]];
            $gift=(new CashierV3DirectGiftIssuanceServices())->issueInTx(['handlingStoreId'=>$store,'reason'=>'完成调查任务#'.$task['id'].'·答卷#'.$answer['id'],'items'=>$items],$member,$scope,$dataScope,$key,$recorder,$execution,$contract);
            $recorder->assertRequiredPersistedInTx($execution,$contract);
            Db::name('member_survey_reward')->where('id',$id)->update(['status'=>'issued','gift_id'=>$gift['giftId'],'attempts'=>(int)$request['attempts']+1,'last_error'=>'','updated_at'=>time()]);
            return['status'=>'issued','gift_id'=>$gift['giftId'],'message'=>'奖励已发放'];
        });}catch(\Throwable$error){
            Db::name('member_survey_reward')->where('id',$id)->where('status','<>','issued')->inc('attempts')->update(['status'=>'pending','last_error'=>mb_substr($error->getMessage(),0,500),'updated_at'=>time()]);
            return['status'=>'pending','message'=>'问卷已提交，奖励待发，请稍后查看'];
        }
    }
    /** 查询和重试按真实会员身份/答卷关联，不能凭应发记录ID领取他人奖励。 */
    public function memberStatus(int $taskId,int $uid,bool $retry=false):array
    {
        if($uid<=0)throw new ValidateException('请先登录会员账号');
        $request=Db::name('member_survey_reward')->where('task_id',$taskId)->where('uid',$uid)->order('id','desc')->find();
        if(!$request)return['status'=>'none','message'=>'暂无待发奖励'];
        if($retry)return$this->fulfil((int)$request['id']);
        return['status'=>$request['status'],'gift_id'=>$request['gift_id'],'message'=>$request['status']==='issued'?'奖励已发放':'问卷已提交，奖励待发'];
    }
}
