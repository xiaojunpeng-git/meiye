<?php
/** Synthetic checks for transaction-owned raw population reuse. No app, SQL,
 * credentials or business data. Reused rows must retain metric-specific
 * binding identity and all before/after permission fences. */
require __DIR__.'/fixture-autoload.php';
use app\services\query\metric\PersonnelAnalysisObjectServices;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\query\metric\MetricDefinitionRegistry;

$checks=0;
$check=static function(bool $pass,string $label)use(&$checks):void {++$checks;if (!$pass) throw new RuntimeException($label);};
$scope=['personnel_authorized'=>true,'store_ids'=>[1],'employee_id'=>0,'permission_version'=>'v1'];
$queries=0;$authorizations=0;$mutateAfter=null;$overflow=false;
class Opt3PopulationQuery {
    private $table;private $scope;private $overflow;private $field='';
    public function __construct(string $table,array $scope,bool $overflow){$this->table=$table;$this->scope=$scope;$this->overflow=$overflow;}
    public function __call($method,$args){if (isset($args[0])&&is_callable($args[0])) $args[0]($this);if (in_array($method,['field','fieldRaw'],true)) $this->field=$args[0];return $this;}
    public function select(){return $this;}
    public function cursor(): Generator {yield from $this->toArray();}
    public function toArray(): array {
        if ($this->table==='cashier_v3_entitlement_service_fact') return [];
        if (strpos($this->field,'SUM(')!==false) return [['employee_id'=>'7','business_date'=>'2026-10-01','metric_value'=>'120','fact_count'=>'2']];
        $row=['store_id'=>$this->scope['store_ids'][0],'employee_id'=>$this->scope['employee_id']?:7,'employee_name'=>'Synthetic staff'];
        return $this->overflow?array_fill(0,1001,$row):[$row];
    }
}
$factory=static function(string $table)use(&$queries,&$scope,&$overflow){++$queries;return new Opt3PopulationQuery($table,$scope,$overflow);};
$authorize=static function(string $metric)use(&$scope,&$authorizations,&$mutateAfter):array {
    ++$authorizations;if ($mutateAfter===$authorizations) $scope['permission_version'].='-changed';return $scope;
};
$objects=new PersonnelAnalysisObjectServices($factory,$authorize);
$range=['start'=>'2026-10-01','end'=>'2026-10-11'];$cache=[];$codes=['staff_labor_yeji','staff_project_num','staff_labor_fee','staff_service_num','staff_point_visit_num'];
$plain=[];foreach ($codes as $code) $plain[$code]=$objects->factParticipantSelection([$code],'0',$range);
$check($queries===5&&$authorizations===10,'default callers continue independent reads and both permission checks');
$before=$queries;$authBefore=$authorizations;$cached=[];
foreach ($codes as $code) $cached[$code]=$objects->factParticipantSelection([$code],'0',$range,$cache);
$check($queries-$before===1,'five registered columns read the identical labour population once');
$check($authorizations-$authBefore===10,'every cache hit still performs both metric-specific authority checks');
$check($cached===$plain,'complete selection fields and binding hashes equal uncached results');
$check(count(array_unique(array_column($cached,'binding_hash')))===5,'each metric retains its own binding identity');
$before=$queries;$objects->factParticipantSelection(['staff_sales_yeji'],'0',$range,$cache);
$check($queries===$before+1,'different performance source cannot reuse labour rows');
foreach (['date','tenant','stores','employee','permission'] as $dimension) {
    $before=$queries;$localRange=$range;$tenant='0';$priorScope=$scope;
    if ($dimension==='date') $localRange=['start'=>'2026-09-01','end'=>'2026-09-30'];
    elseif ($dimension==='tenant') $tenant='1';
    elseif ($dimension==='stores') $scope['store_ids']=[2];
    elseif ($dimension==='employee') $scope['employee_id']=8;
    else $scope['permission_version']='v2';
    $objects->factParticipantSelection(['staff_labor_yeji'],$tenant,$localRange,$cache);
    $check($queries===$before+1,$dimension.' is isolated in the raw-population key');$scope=$priorScope;
}
$before=$queries;$otherTransaction=[];$objects->factParticipantSelection(['staff_labor_yeji'],'0',$range,$otherTransaction);
$check($queries===$before+1,'new transaction cache cannot read an earlier projection');
$before=$queries;$mutateAfter=$authorizations+2;$caught=null;
try {$objects->factParticipantSelection(['staff_labor_yeji'],'0',$range,$cache);} catch (Throwable $error) {$caught=$error->getMessage();}
$check($caught==='AI_AUTHORIZATION_CHANGED','cache hit rejects a permission change at the post-read fence');
$check($queries===$before,'post-read fence also runs for a genuine cache hit');$mutateAfter=null;$scope['permission_version']='v1';
$scope['personnel_authorized']=false;$caught=null;
try {$objects->factParticipantSelection(['staff_labor_yeji'],'0',$range,$cache);} catch (Throwable $error) {$caught=$error->getMessage();}
$check($caught==='AI_PERSONNEL_PERMISSION_REQUIRED','revoked permission is rejected before a cache hit');$scope['personnel_authorized']=true;
$scope['permission_version']="\xB1\x31";$before=$queries;$caught=null;
try {$objects->factParticipantSelection(['staff_labor_yeji'],'0',$range,$cache);} catch (Throwable $error) {$caught=$error;}
$check($caught!==null&&$queries===$before,'invalid UTF-8 authority fails before SQL or a cache lookup collision');$scope['permission_version']='v1';
$overflow=true;$fresh=[];$caught=null;
try {$objects->factParticipantSelection(['staff_labor_yeji'],'0',$range,$fresh);} catch (Throwable $error) {$caught=$error->getMessage();}
$check($caught==='AI_OBJECT_SCOPE_TOO_LARGE'&&$fresh===[],'oversized rows never enter the transaction cache');$overflow=false;

// Exercise the actual view integration, not just the optional resolver API.
// These synthetic facts have nonzero personnel amounts and no service facts.
$view=(new ReflectionClass(MetricReadViewServices::class))->newInstanceWithoutConstructor();
$property=new ReflectionProperty($view,'personnel');if(PHP_VERSION_ID<80100)$property->setAccessible(true);$property->setValue($view,$objects);
$method=new ReflectionMethod($view,'baseRankingPresentation');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$reader=new GroupPerformanceMetricReadServices($factory,static function(){},static function(){},false,true);
$query=['metric_codes'=>['staff_sales_yeji'],'business_filters'=>['selection_ref'=>MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF]];
$binding=['tenant_id'=>'0','store_ids'=>[1]];$groups=['top'=>[['employee_id'=>7]]];
$personnel=$objects->factParticipantSelection(['staff_sales_yeji'],'0',$range);$beforeAuth=$authorizations;
$details=$method->invoke($view,$reader,$query,$binding,$range,$groups,$personnel);
$check(array_column($details,'metric_code')===$codes,'actual ranking preserves all five supplementary columns plus primary');
$check(array_column($details,'values')===[
    [['entity_id'=>7,'metric_value'=>120]],[['entity_id'=>7,'metric_value'=>120]],
    [['entity_id'=>7,'metric_value'=>120]],[['entity_id'=>7,'metric_value'=>0]],[['entity_id'=>7,'metric_value'=>0]],
],'all six registered measurements retain their existing synthetic values');
$check($authorizations-$beforeAuth===10,'view integration retains both authority fences for each supplement');
$before=$queries;$again=$method->invoke($view,$reader,$query,$binding,$range,$groups,$personnel);
$check($again===$details&&$queries>$before,'a later projection starts a new population cache');
echo 'PASS OPT3 personnel population cache: '.$checks." checks (synthetic only)\n";
