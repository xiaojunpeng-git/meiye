// Executes the actual Vue adapter and PHP normalizer, without app/.env/DB boot.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
const root = fileURLToPath(new URL('../../', import.meta.url))
const view = readFileSync(root + '前端代码/cashier-v3/src/views/OrderCenterView.vue', 'utf8')
const extract = (name) => view.slice(view.indexOf(`function ${name}(`), view.indexOf('\n}\n', view.indexOf(`function ${name}(`)) + 2)
const snapshot = new Function('ORDER_TABS', `${extract('businessDateRangeFromQuery')}\n${extract('exportQuerySnapshot')}\nreturn exportQuerySnapshot`)([{ key: 'sales', pageCode: 'order_center_sales' }])
const range = [{ field: 'business_date', operator: 'gte', value: '2026-09-01' }, { field: 'business_date', operator: 'lte', value: '2026-09-08' }]
const raw = { dateFrom: '2026-09-01', dateTo: '2026-09-08', businessDateFrom: '2026-09-01', businessDateTo: '2026-09-08', topFilters: range, dataScope: 'normal', businessStatus: '', page: 1, pageSize: 20, status: 'normal', keyword: '', filters: [], sorts: [], groupBy: [], summaries: [], visibleFields: [], fieldVersions: {}, filterRelation: 'all' }
const original = JSON.stringify(raw)
const fixed = snapshot('sales', raw)
assert.equal(JSON.stringify(raw), original)
assert.deepEqual(fixed.topFilters, range)
for (const key of ['dateFrom', 'dateTo', 'businessDateFrom', 'businessDateTo', 'status', 'pageSize']) assert.equal(key in fixed, false)
assert.equal(fixed.limit, 20)
assert.deepEqual(snapshot('sales', { date_from: '2026-09-01', business_date_to: '2026-09-08' }).topFilters, range)
assert.deepEqual(snapshot('sales', { ...raw, dateFrom: '2020-01-01', businessDateFrom: '2020-01-01' }).topFilters, range)
const other = { field: 'member_name', operator: 'contains', value: 'fixture' }
assert.deepEqual(snapshot('sales', { ...raw, topFilters: [...range, other] }).topFilters, [...range, other])
const php = String.raw`
$root=$argv[1];
spl_autoload_register(function($class)use($root){if(strpos($class,'app\\')===0){$file=$root.'/后端代码/'.str_replace('\\','/',$class).'.php';if(is_file($file))require_once $file;}});
$registry=new \app\services\query\UnifiedQueryPageRegistry();
(new \app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryRegistrar())->register($registry);
$service=new \app\services\query\UnifiedQueryExecutionServices($registry,new \app\services\query\StructuredExpressionValidator($registry),new \app\services\query\StructuredExpressionEvaluator());
$input=json_decode(stream_get_contents(STDIN),true);
try { $service->normalizeUiQuery('order_center_sales',$input['raw']); throw new RuntimeException('Old payload must fail'); }
catch(\app\services\query\UnifiedQueryException $e) { if($e->getErrorCode()!=='UNIFIED_QUERY_REQUEST_INVALID'||strpos($e->getDetail()['reason'],'dateFrom')===false) throw $e; }
$q=$service->normalizeUiQuery('order_center_sales',$input['fixed']);
if($q['topFilterConditions']!==$input['range']) throw new RuntimeException('Date range changed');
foreach(['query','page'] as $scope) { $q=$input['fixed']; $q['export']=['scope'=>$scope]; $n=$service->normalizeUiQuery('order_center_sales',$q); if($n['export']['scope']!==$scope) throw new RuntimeException('Scope changed'); }
echo 'PASS actual PHP normalizer: legacy rejection, preserved dates, query/page scopes';`
const result = spawnSync(process.env.MOHE_TEST_PHP || 'php', ['-r', php, root], { input: JSON.stringify({ raw, fixed, range }), encoding: 'utf8' })
assert.equal(result.status, 0, result.stderr + result.stdout)
console.log(result.stdout)
console.log('PASS order export snapshot: source unchanged, aliases converted, dates and other filters retained')
