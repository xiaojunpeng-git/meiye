<?php
/** R54: object detail profiles stay registry-owned and fact-backed. */
require_once __DIR__ . '/fixture-autoload.php';

use app\services\ai\execution\AiRankingPresentationMetricResolver;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\ServiceCustomerMetricReadServices;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};
$caps=MetricDefinitionRegistry::capabilities();
$check(AiRankingPresentationMetricResolver::detail($caps,'staff_sales_yeji','person')===[
    'staff_sales_yeji','staff_labor_yeji','staff_project_num','staff_labor_fee',
    'staff_service_num','staff_point_visit_num'],
    'employee details retain the six requested registered fields in business order');
$check(AiRankingPresentationMetricResolver::detail($caps,'sales_amount','project')===[
    'sales_amount','completed_service_item_count','sales_quantity'],
    'project detail does not advertise an incomplete consumption source');
$check(($caps['staff_labor_fee']['storage_unit']??null)==='fen'
    &&($caps['staff_point_visit_num']['storage_unit']??null)==='customer_tenth',
    'handwork fee retains cents and point visits retain exact tenth-count storage');

// Two jointly serving technicians share ordinary service visits. Only the
// explicitly designated technician receives the point visit, once per
// customer/store/day even when two projects were completed that day.
$rows=[];
foreach ([['l1','2026-09-29'],['l2','2026-09-29'],['l3','2026-09-30']] as $line) {
    foreach ([7,8] as $employeeId) {
        $rows[]=[
            'service_store_id'=>1,'store_id'=>1,'business_date'=>$line[1],
            'checkout_request_id'=>'req-'.$line[0],'source_line_id'=>$line[0],
            'service_fact_id'=>'sf-'.$line[0],'member_id'=>41,'service_object'=>'SELF',
            'friend_counts_as_customer'=>1,'craftsmen_snapshot_json'=>'[]',
            'labor_amount_cents'=>100,'labor_fee_amount_cents'=>10,
            'fact_id'=>'p-'.$line[0].'-'.$employeeId,'fact_direction'=>'forward','reversal_of'=>'',
            'employee_id'=>$employeeId,'employee_name'=>$employeeId===7?'甲技师':'乙技师',
            'role_snapshot'=>$employeeId===7?'craftsman:point':'craftsman:round',
        ];
    }
}
$factory=static function(string $table)use($rows) {
    return new class($rows) {
        private $rows;
        public function __construct(array $rows){$this->rows=$rows;}
        public function __call($name,$args){return $this;}
        public function select(){return $this;}
        public function toArray(){return $this->rows;}
    };
};
$service=(new ServiceCustomerMetricReadServices($factory,static function($query,$alias):void {}))
    ->employeeMetrics('0',[1],['start'=>'2026-09-29','end'=>'2026-09-30']);
$check(($service['summary_by_store_employee']['1|7']['visit_tenths']??null)===10
    &&($service['summary_by_store_employee']['1|8']['visit_tenths']??null)===10,
    'ordinary service visits are shared across two technicians and two days');
$check(($service['summary_by_store_employee']['1|7']['point_visit_tenths']??null)===20
    &&($service['summary_by_store_employee']['1|8']['point_visit_tenths']??null)===0,
    'point visits are undivided, designated-person only, and daily-deduplicated');

echo "r54-detail-profiles: {$checks} checks passed (synthetic; not business acceptance)\n";
