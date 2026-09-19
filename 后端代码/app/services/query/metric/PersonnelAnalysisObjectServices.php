<?php
namespace app\services\query\metric;

/** Customer-specific object metadata, resolved locally after personnel report authority.
 * The callback must rebuild current metric/grain permissions, not merely AI entry access.
 */
final class PersonnelAnalysisObjectServices
{
    private $query; private $authorize;
    public function __construct(callable $queryFactory,callable $authorize)
    { $this->query=$queryFactory; $this->authorize=$authorize; }

    public function catalog(string $metric): array
    {
        $scope=$this->scope($metric);
        $rows=$this->staff($scope)->leftJoin('staff_job_position jp','jp.staff_id=ss.id AND jp.is_del=0 AND jp.status=1 AND jp.end_time=0')
            ->leftJoin('position p','p.id=jp.position_id AND p.status=1')
            ->leftJoin('system_store st','st.id=ss.store_id AND st.is_del=0')
            ->field('ss.store_id,ss.employee_id,e.name employee_name,st.name store_name,ss.cashier_craftsman_enabled,ss.cashier_salesperson_enabled,p.id position_id,p.name position_name')
            ->order('ss.id','asc')->limit(10001)->select()->toArray();
        if (count($rows)>10000) throw new \RuntimeException('AI_OBJECT_SCOPE_TOO_LARGE');
        $positions=[];$roles=[];$people=[];
        foreach ($rows as $row) {
            $id=(int)($row['position_id']??0); $label=trim((string)($row['position_name']??''));
            $employee=(int)($row['employee_id']??0);$name=trim((string)($row['employee_name']??''));$storeName=trim((string)($row['store_name']??''));
            if ($employee>0 && $name!=='') {
                $key='person:'.$employee;
                if (!isset($people[$key])) $people[$key]=['ref'=>$key,'kind'=>'person','label'=>$name,'aliases'=>[],
                    'version'=>'','relations'=>[$metric],'_name'=>$name,'_stores'=>[]];
                if ($storeName!=='') $people[$key]['_stores'][$storeName]=true;
            }
            if ($id>0 && $label!=='') $positions['position:'.$id]=['ref'=>'position:'.$id,'kind'=>'position','label'=>$label,'aliases'=>[],
                'version'=>hash('sha256',$id.':'.$label),'relations'=>[$metric]];
            // These are platform-owned role aliases, not an AI phrase map:
            // the local catalog resolves a model-understood analytical role
            // to the same qualification record used by performance facts.
            if ((int)$row['cashier_craftsman_enabled']===1) $roles['role:craftsman']=['ref'=>'role:craftsman','kind'=>'position','label'=>'有手艺人资格的在职人员（按当前任职）','aliases'=>['手艺人','技师'], 'version'=>'2','relations'=>[$metric]];
            if ((int)$row['cashier_salesperson_enabled']===1) $roles['role:salesperson']=['ref'=>'role:salesperson','kind'=>'position','label'=>'有销售人资格的在职人员（按当前任职）','aliases'=>['销售人','销售顾问'], 'version'=>'2','relations'=>[$metric]];
        }
        // Two authorized employees may legitimately share one name.  Keep the
        // plain name as a local matching alias (so it is still masked before a
        // model call), but give the clarification UI an authoritative store
        // context instead of two indistinguishable radio choices.  Unique
        // names remain unchanged and no store/name phrase is hard-coded.
        $nameCounts=[];
        foreach ($people as $person) $nameCounts[$person['_name']]=($nameCounts[$person['_name']]??0)+1;
        foreach ($people as &$person) {
            $name=$person['_name'];$stores=array_keys($person['_stores']);sort($stores,SORT_STRING);
            if (($nameCounts[$name]??0)>1) {
                $context=$stores?implode('、',$stores):'所属门店待核对';
                $person['label']=$name.'（'.$context.'）';$person['aliases']=[$name];
            }
            $person['version']=hash('sha256',$person['ref'].':'.$person['label'].':'.implode('|',$stores));
            unset($person['_name'],$person['_stores']);
        }
        unset($person);
        if (call_user_func($this->authorize,$metric)!==$scope) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        return ['objects'=>array_values($positions+$roles+$people),'scope'=>$scope];
    }

    public function selection(string $metric,string $reference): array
    {
        $catalog=$this->catalog($metric);$matches=array_values(array_filter($catalog['objects'],static function($o)use($reference){return $o['ref']===$reference;}));
        if (count($matches)!==1) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        $query=$this->staff($catalog['scope']);
        if ($reference==='role:craftsman') $query->where('ss.cashier_craftsman_enabled',1);
        elseif ($reference==='role:salesperson') $query->where('ss.cashier_salesperson_enabled',1);
        elseif (preg_match('/^person:([1-9][0-9]*)$/D',$reference,$match)) $query->where('ss.employee_id',(int)$match[1]);
        elseif (preg_match('/^position:([1-9][0-9]*)$/D',$reference,$match)) $query->whereExists(function($position)use($match){
            $position->name('staff_job_position')->alias('jp')->whereRaw('jp.staff_id=ss.id')->where('jp.position_id',(int)$match[1])
                ->where('jp.is_del',0)->where('jp.status',1)->where('jp.end_time',0);
        });
        else throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        $rows=$query->field('ss.store_id,ss.employee_id,e.name employee_name')->order('ss.store_id','asc')->order('ss.employee_id','asc')->limit(1001)->select()->toArray();
        if (count($rows)>1000) throw new \RuntimeException('AI_OBJECT_SCOPE_TOO_LARGE');
        $pairs=[];$names=[];
        foreach ($rows as $row) {
            $store=(int)$row['store_id'];$employee=(int)$row['employee_id'];$label=trim((string)$row['employee_name']);
            if (!in_array($store,$catalog['scope']['store_ids'],true)||$employee<1||$label==='') throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
            $pairs[$store.':'.$employee]=['store_id'=>$store,'employee_id'=>$employee];$names[$employee]=$label;
        }
        if (call_user_func($this->authorize,$metric)!==$catalog['scope']) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        $selection=['ref'=>$reference,'label'=>$matches[0]['label'],'pairs'=>array_values($pairs),'names'=>$names,'scope'=>$catalog['scope']];
        $selection['binding_hash']=hash('sha256',json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $selection;
    }

    /**
     * Returns the current authorised personnel population for a registered
     * condition-set read.  Unlike selection(), this is deliberately a
     * population, not a model-selected role or a name match: every candidate
     * must be visible for every requested metric before a zero or a failed
     * condition can be stated about that person.
     *
     * @param array<int,string> $metrics
     */
    public function conditionSelection(array $metrics): array
    {
        if (!$metrics || count($metrics)>4 || count(array_unique($metrics))!==count($metrics)) {
            throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        }
        foreach ($metrics as $metric) if (!is_string($metric) || $metric==='') throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');

        $scopes=[];
        foreach ($metrics as $metric) $scopes[$metric]=$this->scope($metric);
        $stores=null;
        foreach ($scopes as $scope) {
            $current=$scope['store_ids']; sort($current,SORT_NUMERIC);
            $stores=$stores===null?$current:array_values(array_intersect($stores,$current));
        }
        if (!$stores) throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
        $scope=$scopes[$metrics[0]]; $scope['store_ids']=$stores;
        $rows=$this->staff($scope)->field('ss.store_id,ss.employee_id,e.name employee_name')
            ->order('ss.store_id','asc')->order('ss.employee_id','asc')->limit(10001)->select()->toArray();
        if (count($rows)>10000) throw new \RuntimeException('AI_OBJECT_SCOPE_TOO_LARGE');
        $pairs=[];$names=[];
        foreach ($rows as $row) {
            $store=(int)($row['store_id']??0);$employee=(int)($row['employee_id']??0);$name=trim((string)($row['employee_name']??''));
            if (!in_array($store,$stores,true)||$employee<1||$name==='') throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
            $pairs[$store.':'.$employee]=['store_id'=>$store,'employee_id'=>$employee];
            $names[$employee]=$name;
        }
        // Repeat every metric authority after materialising the population so
        // a permission change cannot turn an omitted person into a reported 0.
        foreach ($scopes as $metric=>$before) if (call_user_func($this->authorize,$metric)!==$before) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        $selection=['ref'=>'cohort:active_personnel','label'=>'当前有权限的在职人员','pairs'=>array_values($pairs),'names'=>$names,
            'scope'=>$scope,'metric_codes'=>array_values($metrics)];
        $selection['binding_hash']=hash('sha256',json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $selection;
    }

    private function scope(string $metric): array
    {
        $scope=call_user_func($this->authorize,$metric);
        if (!is_array($scope) || ($scope['personnel_authorized']??false)!==true || !is_array($scope['store_ids']??null)
            || !$scope['store_ids'] || !is_string($scope['permission_version']??null) || !is_int($scope['employee_id']??null)
            || $scope['employee_id']<0) throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
        foreach ($scope['store_ids'] as $id) if (!is_int($id)||$id<1) throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
        return $scope;
    }
    private function staff(array $scope)
    {
        $query=call_user_func($this->query,'system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')
            ->whereIn('ss.store_id',$scope['store_ids'])->where('ss.status',1)->where('ss.is_del',0)
            ->where('ss.employee_id','>',0)->where('e.status',1)->where('e.is_del',0);
        if ($scope['employee_id']>0) $query->where('e.id',$scope['employee_id']);
        return $query;
    }
}
