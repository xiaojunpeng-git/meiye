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
    /** 草稿可继承问卷奖励；发布任务只读取冻结的任务配置。 */
    public function config(int $id):array
    {
        $task=Db::name('member_survey_task')->where('id',$id)->find();if(!$task)throw new ValidateException('调查任务不存在');
        $own=Db::name('member_survey_reward_config')->where('gift_type',1)->where('relation_id',$id)->count();
        return !$own&&$task['status']==='draft'?$this->questionnaireConfig((int)$task['questionnaire_id']):$this->configurations()->getConfig(1,$id);
    }
    /** 读取问卷奖励模板，不从007旧赠送缓存自动迁移。 */
    public function questionnaireConfig(int $id):array
    {
        if(!Db::name('member_survey_questionnaire')->where('id',$id)->count())throw new ValidateException('问卷不存在');
        return $this->configurations()->getConfig(2,$id);
    }
    /** 总部保存问卷奖励模板，后续新任务可继承。 */
    public function saveQuestionnaireConfig(int $id,array $input):void{$this->save(2,$id,$input);}
    /** 只允许保存草稿任务奖励，已发布奖励不能被重新覆盖。 */
    public function saveConfig(int $id,array $input):void{$this->save(1,$id,$input);}
    /** 所属实体与配置同锁，已发布任务的奖励不可被后续问卷编辑覆盖。 */
    private function save(int $type,int $id,array $input):void
    {
        Db::transaction(function()use($type,$id,$input):void{
            $entity=Db::name($type===1?'member_survey_task':'member_survey_questionnaire')->where('id',$id)->lock(true)->find();
            if(!$entity||($type===1&&$entity['status']!=='draft'))throw new ValidateException('只可配置草稿任务或问卷奖励');
            $this->configurations()->saveConfig($type,$id,$this->normalize($input));
        });
    }
    /** 发布事务中冻结门店、内容、数量和有效期，后续配置不影响已发任务。 */
    public function snapshotQuestionnaireConfig(int $taskId,int $questionnaireId):void
    {
        $own=Db::name('member_survey_reward_config')->where('gift_type',1)->where('relation_id',$taskId)->lock(true)->find();
        $config=$own?$this->configurations()->getConfig(1,$taskId):$this->questionnaireConfig($questionnaireId);
        $this->configurations()->saveConfig(1,$taskId,$this->normalize($config));
    }
    /** 公开扫码无需到店历史；奖品必须有明确的发放门店，不能猜最近核销门店。 */
    public function validateTargets(int $taskId,array $uids):void{$this->normalize($this->config($taskId));}
    /** 对会员仅展示奖励名称和数量，不输出后台目录或管理身份。 */
    public function summary(int $taskId):array
    {
        $out=[];foreach(['product','coupon']as$kind)foreach($this->config($taskId)[$kind]as$row)$out[]=['type'=>$kind,'name'=>$row['store_name'],'quantity'=>$row['num'],'label'=>$kind==='coupon'?'优惠券':'品项'];return$out;
    }
    /** 后端目录用于配置，不接受商品名匹配或把平台ID当作门店SKU。 */
    public function options(int $storeId,string $keyword=''):array
    {
        $stores=Db::name('system_store')->where('is_del',0)->order('id')->field('id,name')->select()->toArray();
        if(!$storeId)return['stores'=>$stores,'products'=>[],'coupons'=>[]];
        if(!in_array($storeId,array_map('intval',array_column($stores,'id')),true))throw new ValidateException('发放门店不可用');
        $keyword=trim($keyword);if(mb_strlen($keyword)>50)throw new ValidateException('搜索内容过长');
        $products=Db::name('store_product_attr_value')->alias('s')->join('store_product p','p.id=s.product_id')
            ->where('p.relation_id',$storeId)->where('p.is_del',0)->whereIn('p.product_type',[0,6])->where('s.type',0)->where(function($q){$q->where('p.product_type',6)->whereOr(function($p){\app\services\product\product\PhysicalProductSelectionPolicy::apply($p->where('p.type',1),false,'p.');});})
            ->when($keyword!=='',static function($q)use($keyword){$q->whereLike('p.store_name','%'.$keyword.'%');})
            ->field('s.id,p.store_name,p.product_type,s.suk')->order('s.id','desc')->limit(100)->select()->toArray();
        $coupons=Db::name('store_coupon_issue')->whereIn('relation_id',[0,$storeId])->where('is_del',0)->where('status',1)
            ->when($keyword!=='',static function($q)use($keyword){$q->whereLike('title','%'.$keyword.'%');})->field('id,title')->order('id','desc')->limit(100)->select()->toArray();
        return['stores'=>$stores,'products'=>$products,'coupons'=>$coupons];
    }
    /** 问卷选品复用平台商品选择器的分页外观，但目录始终按明确发放门店和现有可赠类型收窄。 */
    public function catalog(int $storeId,array $filters):array
    {
        if($storeId<=0||!Db::name('system_store')->where('id',$storeId)->where('is_del',0)->count())throw new ValidateException('请先选择有效的奖励发放门店');
        $page=max(1,min(10000,(int)($filters['page']??1)));$limit=max(1,min(50,(int)($filters['limit']??20)));
        $keyword=trim((string)($filters['store_name']??''));if(mb_strlen($keyword)>50)throw new ValidateException('搜索内容过长');
        $type=(string)($filters['product_type']??'');if($type!==''&&!in_array($type,['0','6'],true))throw new ValidateException('问卷只能选择项目或实物产品');
        $status=(int)($filters['type']??1);if(!in_array($status,[1,2],true))throw new ValidateException('商品状态无效');
        $query=Db::name('store_product')->alias('p')->where('p.relation_id',$storeId)->where('p.is_del',0)
            ->where('p.is_verify',1)->where('p.is_show',$status===1?1:0)->whereIn('p.product_type',$type===''?[0,6]:[(int)$type]);
        // 实物只接收实体产品；目录资格与保存时的赠送校验一致，避免选中后才报不可赠。
        $query->where(function($q){$q->where('p.product_type',6)->whereOr(function($p){$p->where('p.product_type',0)->where('p.type',1);});});
        if($keyword!=='')$query->where(function($q)use($keyword){$q->whereLike('p.store_name','%'.$keyword.'%')->whereOr('p.id',$keyword);});
        $category=(int)($filters['cate_id']??0);if($category>0)$query->whereRaw('FIND_IN_SET(?, p.cate_id)',[$category]);
        $count=(int)(clone $query)->count();
        $list=$query->field('p.id,p.store_name,p.product_type,p.image,p.cate_id,p.store_label_id,p.is_show')->order('p.id','desc')->page($page,$limit)->select()->toArray();
        $ids=array_map('intval',array_column($list,'id'));
        $skus=$ids?Db::name('store_product_attr_value')->whereIn('product_id',$ids)->where('type',0)->field('id,product_id,suk')->order('id','asc')->select()->toArray():[];
        $first=[];foreach($skus as$sku)if(!isset($first[(int)$sku['product_id']]))$first[(int)$sku['product_id']]=$sku;
        $categoryIds=implode(',',array_filter(array_column($list,'cate_id')));
        $categoryService=app()->make(\app\services\product\category\StoreProductCategoryServices::class);
        $categories=$categoryIds!==''?$categoryService->getCateParentAndChildName($categoryIds):[];
        $labelService=app()->make(\app\services\product\label\StoreProductLabelServices::class);
        foreach($list as&$row){
            $row['attrValue']=isset($first[(int)$row['id']])?[$first[(int)$row['id']]]:[];
            $names=$row['cate_id']?$categoryService->getCateName(explode(',',$row['cate_id']),$categories):[];
            $row['cate_name']=$names?implode(',',$names):'';
            $labels=$row['store_label_id']?$labelService->getColumn([['relation_id','=',0],['type','=',0],['id','IN',$row['store_label_id']]],'id,label_name'):[];
            $row['store_label']=$labels?implode(',',array_column($labels,'label_name')):'';
        }
        unset($row);
        return['list'=>$list,'count'=>$count];
    }
    private function normalize(array $input):array
    {
        $config=$this->configurations()->emptyConfig();$storeRaw=$input['store_id']??0;if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$storeRaw))throw new ValidateException('奖励发放门店格式无效');$store=(int)$storeRaw;$config['store_id']=$store;
        if(isset($input['reward_each_round'])&&!is_bool($input['reward_each_round']))throw new ValidateException('每轮奖励选项无效');
        $config['reward_each_round']=$input['reward_each_round']??true;$seen=[];
        foreach(['product','coupon']as$kind){
            $rows=$input[$kind]??[];if(!is_array($rows)||count($rows)>20)throw new ValidateException('每类奖励最多设置20项');
            foreach($rows as$row){
                if(!is_array($row)||!preg_match('/^[1-9][0-9]?$/D',(string)($row['num']??''))||!preg_match('/^[1-9][0-9]{0,3}$/D',(string)($row['write_days']??'')))throw new ValidateException('奖励数量须为1至99的整数，有效天数须为1至3650');
                if(!preg_match('/^[1-9][0-9]{0,9}$/D',(string)($row['id']??'')))throw new ValidateException('奖励标识格式无效');$id=(int)$row['id'];$days=(int)$row['write_days'];if($days>3650||$id<=0||isset($seen[$kind.':'.$id]))throw new ValidateException('奖励内容重复或有效期无效');
                if($kind==='coupon'){$record=Db::name('store_coupon_issue')->where('id',$id)->where('status',1)->where('is_del',0)->whereIn('relation_id',[0,$store])->find();$name=$record['title']??'';$productType=0;}
                else{$sku=Db::name('store_product_attr_value')->where('id',$id)->where('type',0)->find();$record=$sku?Db::name('store_product')->where('id',$sku['product_id'])->where('relation_id',$store)->where('is_del',0)->whereIn('product_type',[0,6])->find():null;$name=$record['store_name']??'';$productType=(int)($record['product_type']??-1);if($record&&$productType===0&&!\app\services\product\product\PhysicalProductSelectionPolicy::apply(Db::name('store_product')->where('id',$record['id'])->where('type',1),false,'')->count())$record=null;}
                if(!$record)throw new ValidateException('奖励已失效或不属于所选发放门店');
                $config[$kind][]=['id'=>$id,'product_type'=>$productType,'store_name'=>$name,'num'=>(int)$row['num'],'write_days'=>$days];$seen[$kind.':'.$id]=true;
            }
        }
        if($seen&&!Db::name('system_store')->where('id',$store)->where('is_del',0)->count())throw new ValidateException('请先选择奖励发放门店');
        return$config;
    }
    /** 必须与唯一答卷同事务；本记录只代表应发，不以pending状态冒充权益已到账。 */
    public function enqueueInTx(int $taskId,int $uid,int $answerId,int $round):int
    {
        \app\services\cashier\v3\CashierV3TransactionGuard::assertInTransaction('survey.reward.enqueue');
        $config=$this->config($taskId);if(!$config['product']&&!$config['coupon'])return 0;
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
            // 可信主体来自已发布任务的总部管理员及显式发放门店，绝不采用扫码用户传入的操作人/门店。
            $scope=new CashierV3OperatorScope($store,(int)$admin['id'],(string)$binding['org_id'],'0');
            $dataScope=new CashierV3DataScopeContext((int)$admin['id'],0,$store,'0',(string)$binding['org_id'],[$store],'stores',[],false,'','survey-task:'.$task['id'],[],['id'=>(int)$admin['id'],'actor_type'=>'admin','name'=>'调查任务自动发放（'.$admin['account'].'）','authority'=>'published_member_survey']);
            $items=[];foreach(['product','coupon']as$kind)foreach($config[$kind]as$row)$items[]=['kind'=>$kind==='coupon'?'coupon':((int)$row['product_type']===6?'project':'product'),'catalogItemId'=>$kind==='product'?$row['id']:0,'couponIssueId'=>$kind==='coupon'?$row['id']:0,'quantity'=>$row['num'],'validityEnd'=>date('Y-m-d',time()+(int)$row['write_days']*86400)];
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
