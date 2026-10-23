<?php
declare(strict_types=1);
namespace app\services\growth;

use think\facade\Db;
use mohe\exceptions\AdminException;

/** 课程与知识权威内容；编辑草稿、冻结发布和员工阅读使用明确的不同业务状态。 */
final class GrowthContentServices
{
    /** 后端分页与完整命中数使用同一授权查询，草稿不会向普通员工曝光。 */
    public function listing(array $c,array $input): array
    {
        $q=Db::name('growth_content');
        if (!$c['super']) $q->whereIn('owner_employee_id',app()->make(GrowthAccessServices::class)->employees($c));
        if (!empty($input['keyword'])) $q->whereLike('title','%'.mb_substr((string)$input['keyword'],0,100).'%');
        if (!empty($input['kind'])) $q->where('kind',(string)$input['kind']);
        $total=(clone $q)->count(); $pages=max(1,(int)ceil($total/20)); $page=min($pages,max(1,(int)($input['page']??1)));
        $rows=$q->field('id,kind,title,category,description,state,current_publication_id,updated_at')->order('id','desc')->page($page,20)->select()->toArray();
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>20,'total_pages'=>$pages];
    }

    /** 后台读取当前完整草稿及发布历史，资源只附安全元数据。 */
    public function detail(array $c,int $id): array
    {
        $row=$this->owned($c,$id); $row['draft']=GrowthCommandServices::decode($row['draft_json']); unset($row['draft_json']);
        foreach($row['draft']['chapters']??[] as $index=>$ch)if((int)$ch['asset_id']>0){$asset=Db::name('growth_asset')->where('id',$ch['asset_id'])->find();if($asset)$row['draft']['chapters'][$index]['asset']=app()->make(GrowthAssetServices::class)->summary($asset);}
        $row['draft']['materials']=$this->materialSummaries($row['draft']);
        $row['publications']=Db::name('growth_publication')->where('content_id',$id)->field('id,state,created_at,confirmed_at,confirmer_employee_id')->order('id','desc')->select()->toArray();
        return $row;
    }

    /** 完整替换可编辑草稿；不比较客户端版本、更新时间或哈希。 */
    public function save(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'content.save',$in,function()use($c,$in){
            $id=(int)($in['id']??0); if($id) $this->owned($c,$id,true);
            $draft=$this->normalize($in,false);$this->validateAttachments($c,$draft,false);$draft=app()->make(GrowthCourseRulesServices::class)->normalize($c,$draft,$in);$draft['reward_selection']=app()->make(GrowthSettingsServices::class)->rewards((array)($in['reward_selection']??[]));$draft['reminder_overrides']=app()->make(GrowthSettingsServices::class)->reminders((array)($in['reminder_overrides']??[]));$draft['rule_source']=['type'=>empty($in['rule_template']['key'])?'enterprise':'template','template_key'=>mb_substr((string)($in['rule_template']['key']??''),0,80),'template_title'=>mb_substr((string)($in['rule_template']['title']??''),0,180),'adjusted_fields'=>array_slice(array_map('strval',(array)($in['rule_source']['adjusted_fields']??[])),0,50)];$draft['audience']=app()->make(GrowthAudienceServices::class)->normalize($c,(array)($in['audience']??[])); $now=time();
            $data=['kind'=>$draft['kind'],'title'=>$draft['title'],'category'=>$draft['category'],'description'=>$draft['description'],'draft_json'=>GrowthCommandServices::json($draft),'updated_at'=>$now];
            if($id) Db::name('growth_content')->where('id',$id)->update($data);
            else $id=(int)Db::name('growth_content')->insertGetId($data+['created_by'=>$c['actor_key'],'owner_employee_id'=>$c['employee_id'],'created_at'=>$now]);
            return ['id'=>$id,'message'=>'草稿已保存'];
        });
    }

    /** 发布所提交完整内容，不能在点击发布后回读他人刚保存的草稿替换它。 */
    public function publish(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'content.publish',$in,function()use($c,$in){
            $id=(int)($in['id']??0); $this->owned($c,$id,true);
            $draft=$this->normalize($in,true);$this->validateAttachments($c,$draft,true);$draft=app()->make(GrowthCourseRulesServices::class)->normalize($c,$draft,$in);$draft['reward_selection']=app()->make(GrowthSettingsServices::class)->rewards((array)($in['reward_selection']??[]));$draft['reminder_overrides']=app()->make(GrowthSettingsServices::class)->reminders((array)($in['reminder_overrides']??[]));$draft['rule_source']=['type'=>empty($in['rule_template']['key'])?'enterprise':'template','template_key'=>mb_substr((string)($in['rule_template']['key']??''),0,80),'template_title'=>mb_substr((string)($in['rule_template']['title']??''),0,180),'adjusted_fields'=>array_slice(array_map('strval',(array)($in['rule_source']['adjusted_fields']??[])),0,50)];$draft['audience']=app()->make(GrowthAudienceServices::class)->normalize($c,(array)($in['audience']??[]));
            foreach($draft['chapters'] as $chapter) if($chapter['asset_id']>0) {
                $asset=app()->make(GrowthAssetServices::class)->owned($c,$chapter['asset_id']);
                if($asset['state']!=='READY') throw new AdminException('章节资源尚未完成可读预览');
                $formats=['ppt'=>['ppt','pptx'],'word'=>['doc','docx'],'pdf'=>['pdf'],'video'=>['mp4','webm'],'image'=>['jpg','jpeg','png','webp']];
                if(!in_array($asset['format'],$formats[$chapter['type']]??[],true))throw new AdminException('章节形式与实际上传文件不一致');
            }
            $handler=(int)($draft['confirmer_employee_id']??0);
            if($handler>0) app()->make(GrowthAccessServices::class)->assertActiveEmployee($c,$handler);
            $state=$handler>0?'PENDING':'PUBLISHED'; $now=time();
            $publication=(int)Db::name('growth_publication')->insertGetId(['content_id'=>$id,'title'=>$draft['title'],'snapshot_json'=>GrowthCommandServices::json($draft),'state'=>$state,'confirmer_employee_id'=>$handler,'published_by'=>$c['actor_key'],'created_at'=>$now,'settled_at'=>$handler?0:$now]);
            Db::name('growth_content')->where('id',$id)->update(['title'=>$draft['title'],'draft_json'=>GrowthCommandServices::json($draft),'state'=>$state,'current_publication_id'=>$publication,'updated_at'=>$now]);
            app()->make(GrowthCommandServices::class)->event($c,'CONTENT_'.$state,'publication',$publication);
            return ['id'=>$id,'publication_id'=>$publication,'state'=>$state,'message'=>$handler?'已交负责人确认':'课程已发布'];
        });
    }

    /** 只确认指派的这一份冻结发布内容，不回读可能被别人覆盖的草稿。 */
    public function confirm(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'publication.confirm',$in,function()use($c,$in){
            $id=(int)($in['publication_id']??0); $p=Db::name('growth_publication')->where('id',$id)->lock(true)->find();
            if(!$p || (int)$p['confirmer_employee_id']!==(int)$c['employee_id']) throw new AdminException('仅指派负责人可确认');
            if($p['state']!=='PENDING') throw new AdminException('本次发布已处理');
            Db::name('growth_publication')->where('id',$id)->update(['state'=>'PUBLISHED','confirmed_at'=>time(),'settled_at'=>time()]);
            Db::name('growth_content')->where('id',$p['content_id'])->where('current_publication_id',$id)->update(['state'=>'PUBLISHED']);
            app()->make(GrowthCommandServices::class)->event($c,'CONTENT_PUBLISHED','publication',$id);
            return ['publication_id'=>$id,'state'=>'PUBLISHED'];
        });
    }

    /** 停用仅阻止新分配；历史任务仍用原发布资源，正式快照不可删除。 */
    public function stop(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'content.stop',$in,function()use($c,$in){
            $id=(int)($in['id']??0);$this->owned($c,$id,true);
            if(trim((string)($in['reason']??''))==='') throw new AdminException('请填写停用原因');
            Db::name('growth_content')->where('id',$id)->update(['state'=>'STOPPED','updated_at'=>time()]);
            app()->make(GrowthCommandServices::class)->event($c,'CONTENT_STOPPED','content',$id,['reason'=>$in['reason']]);
            return ['id'=>$id,'state'=>'STOPPED'];
        });
    }

    /** 内容管理按维护者与当前数据范围授权，可按写事务要求锁定。 */
    public function owned(array $c,int $id,bool $lock=false): array
    {
        $q=Db::name('growth_content')->where('id',$id); if($lock)$q->lock(true); $row=$q->find();
        if(!$row) throw new AdminException('课程不存在');
        if(!$c['super'] && $row['created_by']!==$c['actor_key']) app()->make(GrowthAccessServices::class)->assertEmployee($c,(int)$row['owner_employee_id']);
        return $row;
    }

    /** 封面与资料随同发布快照冻结；仅接受发布者有权使用的私有资源。 */
    private function validateAttachments(array $c,array $draft,bool $publish): void
    {
        $assets=app()->make(GrowthAssetServices::class);$ids=$draft['material_asset_ids'];if($draft['cover_asset_id'])$ids[]=$draft['cover_asset_id'];
        foreach($ids as $id){$r=$assets->owned($c,$id);if($publish&&$r['state']!=='READY')throw new AdminException('封面或资料尚未可预览');if($id===$draft['cover_asset_id']&&!in_array($r['format'],['jpg','jpeg','png','webp'],true))throw new AdminException('课程封面须为图片');}
    }
    /** 展示资料只附加安全元数据，不把私有磁盘路径放入快照或接口。 */
    public function materialSummaries(array $snapshot): array
    {
        $rows=[];foreach($snapshot['material_asset_ids']??[] as $id){$r=Db::name('growth_asset')->where('id',(int)$id)->find();if($r)$rows[]=app()->make(GrowthAssetServices::class)->summary($r);}return $rows;
    }

    /** 内容使用安全文本和结构化章节，不接受任意脚本HTML或公开资源URL。 */
    private function normalize(array $in,bool $publish): array
    {
        $title=trim((string)($in['title']??'')); if(mb_strlen($title)>180 || ($publish&&$title==='')) throw new AdminException('请填写180字以内课程名称');
        $kind=(string)($in['kind']??'course'); if(!in_array($kind,['course','knowledge'],true)) throw new AdminException('内容类型无效');
        if(count((array)($in['chapters']??[]))>100)throw new AdminException('每份内容最多100章，请按主题拆分课程');
        $chapters=[];
        foreach(array_values((array)($in['chapters']??[])) as $i=>$ch) {
            $type=(string)($ch['type']??'text');
            if(!in_array($type,['text','image','video','ppt','word','pdf'],true)) throw new AdminException('章节形式无效');
            $key=(string)($ch['key']??('chapter-'.($i+1)));
            if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key)) throw new AdminException('章节标识无效');
            $mode=(string)($ch['completion']??'confirm');
            if(!in_array($mode,['confirm','coverage'],true)||($mode==='coverage'&&$type!=='video')) throw new AdminException('文档和图文使用阅读确认，覆盖要求只适用于视频');
            $coverage=(int)($ch['coverage_percent']??90); if($coverage<1||$coverage>100)throw new AdminException('视频覆盖比例须为1至100');
            $body=trim((string)($ch['body']??''));if(mb_strlen($body)>100000||count(preg_split('/\R/u',$body))>2000)throw new AdminException('正文请控制在10万字与2000段以内，较长内容可拆分章节或上传文档');$asset=(int)($ch['asset_id']??0);
            if($publish&&($type==='text'?$body==='':$asset<=0)) throw new AdminException('请补齐每章正文或上传有效文件');
            $chapters[]=['key'=>$key,'title'=>mb_substr(trim((string)($ch['title']??('第'.($i+1).'章'))),0,180),'type'=>$type,'body'=>$body,'asset_id'=>$asset,'required'=>(bool)($ch['required']??true),'completion'=>$mode,'coverage_percent'=>$coverage];
        }
        if(count(array_unique(array_column($chapters,'key')))!==count($chapters))throw new AdminException('章节标识不能重复');
        if($publish&&!$chapters)throw new AdminException('请至少添加一个有效章节');
        $minutes=$in['estimated_minutes']??0;if(is_bool($minutes)||filter_var($minutes,FILTER_VALIDATE_INT)===false||(int)$minutes<0||(int)$minutes>100000)throw new AdminException('预计学习时长须为0至100000的整数分钟');
        return ['lecturer'=>mb_substr(trim((string)($in['lecturer']??'')),0,100),'estimated_minutes'=>(int)$minutes,'cover_asset_id'=>max(0,(int)($in['cover_asset_id']??0)),'material_asset_ids'=>array_slice(array_values(array_unique(array_filter(array_map('intval',(array)($in['material_asset_ids']??[]))))),0,30),'title'=>$title,'kind'=>$kind,'category'=>mb_substr(trim((string)($in['category']??'')),0,100),'description'=>mb_substr((string)($in['description']??''),0,10000),'chapters'=>$chapters,'confirmer_employee_id'=>(int)($in['confirmer_employee_id']??0)];
    }
}
