<?php
namespace app\services\query\metric;

/**
 * Resolves a member named in a question without exporting a member directory
 * to the model.  Candidates are exact, permission-filtered matches of bounded
 * fragments from the current question; the resulting reference is then
 * rechecked immediately before the registered metric is read.
 */
final class MemberAnalysisObjectServices
{
    private $query; private $authorize;

    public function __construct(callable $queryFactory, callable $authorize)
    { $this->query=$queryFactory; $this->authorize=$authorize; }

    /** @return array{objects:array<int,array<string,mixed>>,scope:array<string,mixed>} */
    public function mentioned(string $question, array $metrics): array
    {
        $scope=$this->scope();
        if ($question==='' || !$metrics) return ['objects'=>[],'scope'=>$scope];
        $terms=$this->terms($question);
        if (!$terms) return ['objects'=>[],'scope'=>$scope];
        $query=$this->members($scope)->where(function($where)use($terms):void {
            $where->whereIn('u.real_name',$terms)->whereOr('u.nickname','in',$terms);
        })->field('u.uid,u.real_name,u.nickname')->order('u.uid','asc')->limit(33);
        $rows=$query->select()->toArray();
        if (count($rows)>32) throw new \RuntimeException('AI_OBJECT_SCOPE_TOO_LARGE');
        $objects=[];
        foreach ($rows as $row) {
            $uid=(int)($row['uid']??0);$real=trim((string)($row['real_name']??''));$nickname=trim((string)($row['nickname']??''));
            $label=$real!==''?$real:$nickname;
            if ($uid<1 || $label==='' || !in_array($label,$terms,true) && !in_array($nickname,$terms,true)) continue;
            $aliases=[]; if ($nickname!=='' && $nickname!==$label) $aliases[]=$nickname;
            $objects['member:'.$uid]=['ref'=>'member:'.$uid,'kind'=>'member','label'=>$label,'aliases'=>$aliases,
                'version'=>hash('sha256',$uid."\0".$real."\0".$nickname),'relations'=>array_values($metrics)];
        }
        if (call_user_func($this->authorize)!==$scope) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        return ['objects'=>array_values($objects),'scope'=>$scope];
    }

    /** Rebuilds the current authoritative member/store relation for a frozen reference. */
    public function selection(string $reference): array
    {
        if (!preg_match('/^member:([1-9][0-9]*)$/D',$reference,$match)) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        $scope=$this->scope();$uid=(int)$match[1];
        $row=$this->members($scope)->where('u.uid',$uid)->field('u.uid,u.real_name,u.nickname')->find();
        $real=trim((string)($row['real_name']??''));$nickname=trim((string)($row['nickname']??''));$label=$real!==''?$real:$nickname;
        if ((int)($row['uid']??0)!==$uid || $label==='') throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        if (call_user_func($this->authorize)!==$scope) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        $selection=['ref'=>$reference,'label'=>$label,'member_id'=>$uid,'scope'=>$scope];
        $selection['binding_hash']=hash('sha256',json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $selection;
    }

    private function scope(): array
    {
        $scope=call_user_func($this->authorize);
        if (!is_array($scope) || ($scope['member_authorized']??false)!==true || !is_array($scope['store_ids']??null)
            || !$scope['store_ids'] || !is_string($scope['permission_version']??null)
            || ($scope['scope_mode']??null)==='self_participant') throw new \RuntimeException('AI_MEMBER_PERMISSION_REQUIRED');
        foreach ($scope['store_ids'] as $id) if (!is_int($id)||$id<1) throw new \RuntimeException('AI_MEMBER_PERMISSION_REQUIRED');
        return $scope;
    }

    /** The relation table, not a client-owned store or an unscoped user lookup, is the access boundary. */
    private function members(array $scope)
    {
        return call_user_func($this->query,'user')->alias('u')->where('u.status',1)->where('u.is_del',0)
            ->whereIn('u.uid',function($relation)use($scope):void {
                $relation->name('store_user')->where('status',1)->whereIn('store_id',$scope['store_ids'])->field('uid');
            });
    }

    /**
     * Produces only bounded exact candidates.  It is intentionally unaware of
     * Chinese question templates, people names and business metrics: the model
     * still decides the object kind and intent; this method only protects a
     * current authorized label before that model call.
     */
    private function terms(string $question): array
    {
        $chars=preg_split('//u',$question,-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || count($chars)>512) $chars=array_slice((array)$chars,0,512);
        $out=[];$length=count($chars);
        for($start=0;$start<$length && count($out)<512;$start++) {
            $value='';
            for($size=1;$size<=24 && $start+$size<=$length && count($out)<512;$size++) {
                $value.=$chars[$start+$size-1];
                // A member label is a natural-language identity. Purely
                // numeric fragments are amounts, dates, counts or identifiers
                // in ordinary questions and must not become a private-person
                // selection merely because an authorized member happens to
                // use the same numeric nickname. Keep this language-neutral:
                // Chinese and Latin names are both accepted through \p{L}.
                if ($size<2 || preg_match('/[\x00-\x1f\x7f]/u',$value)
                    || preg_match('/\p{L}/u',$value)!==1) continue;
                $out[$value]=true;
            }
        }
        $terms=array_keys($out);sort($terms,SORT_STRING);return $terms;
    }
}
