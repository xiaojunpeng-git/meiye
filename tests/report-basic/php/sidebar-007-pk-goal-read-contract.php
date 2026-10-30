<?php
declare(strict_types=1);

require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\Sidebar007PkGoalReadServices;

/** 现存旧目标只读，稳定门店月份键；未实现版本化写入前不返回假版本。 */
$reader=new Sidebar007PkGoalReadServices(static function(array $stores,int $date):array {
    if($stores!==[7,8])throw new RuntimeException('goal scope changed');
    return [['id'=>1,'store_id'=>7,'goal'=>'1200.50','date'=>(string)$date]];
});
$result=$reader->read([7,8],'2026-10');
if($result['by_store'][7]!==120050||$result['by_store'][8]!==0
    ||$result['stable_keys'][7]!=='sales_pk:2026-10:7'
    ||$result['expected_version']!==null||$result['mode']!=='legacy_read_only')
    throw new RuntimeException('PK 旧目标读回或未录零值口径错误');
$duplicate=new Sidebar007PkGoalReadServices(static fn(array $stores,int $date):array=>[
    ['store_id'=>7,'goal'=>'1','date'=>$date],['store_id'=>7,'goal'=>'2','date'=>$date]]);
$ambiguous=$duplicate->read([7],'2026-10');
if($ambiguous['by_store'][7]!==null||$ambiguous['gaps'][7][0]['code']!=='PK_GOAL_DUPLICATE')
    throw new RuntimeException('重复手录目标不能任取一条');
echo "sidebar-007-pk-goal-read-contract PASS\n";
