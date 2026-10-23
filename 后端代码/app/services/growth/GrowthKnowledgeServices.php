<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 知识查阅只公开已发布内容，沿用当前人员范围；收藏与阅读记录不改变原内容。 */
final class GrowthKnowledgeServices
{
    private function query(array $c)
    {
        $q=Db::name('growth_content')->alias('c')->join('growth_publication p','p.id=c.current_publication_id')->where('c.kind','knowledge')->where('c.state','PUBLISHED')->where('p.state','PUBLISHED');
        app()->make(GrowthAudienceServices::class)->apply($q,$c);return $q;
    }
    /** 只列已发布且匹配冻结受众的知识，收藏属于本人。 */
    public function listing(array $c,array $in): array
    {
        $q=$this->query($c);if(!empty($in['keyword']))$q->whereLike('p.title','%'.mb_substr((string)$in['keyword'],0,100).'%');if(!empty($in['category']))$q->where('c.category',(string)$in['category']);
        if(!empty($in['favorite']))$q->whereIn('c.id',Db::name('growth_favorite')->where('employee_id',$c['employee_id'])->column('content_id'));
        $total=$q->count();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($in['page']??1)));$rows=$q->field('c.id,p.title,c.category,c.description,p.id AS publication_id,p.settled_at')->order('p.id','desc')->page($page,20)->select()->toArray();
        $favorites=array_map('intval',Db::name('growth_favorite')->where('employee_id',$c['employee_id'])->column('content_id'));foreach($rows as &$r)$r['favorite']=in_array((int)$r['id'],$favorites,true);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>$pages,'page_size'=>20,'categories'=>$this->query($c)->where('c.category','<>','')->distinct(true)->column('c.category')];
    }
    /** 查阅引用原发布内容创建本人阅读任务，不因此获得分配他人权限。 */
    public function open(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'knowledge.open',$in,function()use($c,$in){$r=$this->query($c)->where('c.id',(int)($in['id']??0))->field('c.id,p.id AS publication_id,p.title')->find();if(!$r)throw new AdminException('知识已停用或不在当前范围');
            // 同一发布内容只生成一条本人查阅任务；刷新不会重复派发或重复奖励。
            Db::name('employee')->where('id',$c['employee_id'])->lock(true)->find();$id=app()->make(GrowthTaskServices::class)->insertTask($c,$c['employee_id'],['assignment_key'=>'knowledge:'.$r['publication_id'],'publication_id'=>$r['publication_id'],'title'=>$r['title'],'kind'=>'course','required'=>0,'source_type'=>'knowledge','source_id'=>$r['id'],'rules_json'=>'{}']);return ['task_id'=>$id];});
    }
    /** 收藏开关按本人及原条目去重，不修改他人的确认历史。 */
    public function favorite(array $c,array $in): array
    {
        return app()->make(GrowthCommandServices::class)->run($c,'knowledge.favorite',$in,function()use($c,$in){$id=(int)($in['id']??0);if(!$this->query($c)->where('c.id',$id)->find())throw new AdminException('知识不在当前范围');Db::name('employee')->where('id',$c['employee_id'])->lock(true)->find();$q=Db::name('growth_favorite')->where('employee_id',$c['employee_id'])->where('content_id',$id);$old=$q->find();$desired=!empty($in['favorite']);if($desired&&!$old)Db::name('growth_favorite')->insert(['employee_id'=>$c['employee_id'],'content_id'=>$id,'created_at'=>time()]);elseif(!$desired&&$old)$q->delete();return ['favorite'=>$desired];});
    }
}
