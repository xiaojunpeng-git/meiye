<?php
/** 本地真实 MySQL 查询回归；仅连接 Docker ruihao，临时表随连接释放，不写现存门店。 */
require '/var/www/html/vendor/autoload.php';

use app\Request;
use app\controller\api\v1\store\Store;
use app\dao\store\SystemStoreDao;
use app\services\store\SystemStoreServices;
use app\services\store\StoreSelectableRegionServices;
use app\services\store\StoreUserServices;
use app\services\product\product\StoreProductServices;
use app\services\product\branch\StoreBranchProductServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (getenv('STORE_REGIONS_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local Docker database only');

function checkRegion($ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ', $label, PHP_EOL;
}

// Infrastructure fixtures supply entry/product/user context; the actual API,
// scope intersection, DAO SQL and region projection remain production methods.
class RegionStoreProbe extends SystemStoreServices {
    public $entry = 0;
    public $scope = [];
    public function getStoreIdByEntryRules(int $uid = 0, array $param = [], bool $isCache = false): array {
        return ['store_id' => $this->entry, 'shop_operation_type' => 1];
    }
    public function getRegionStoreIds(int $storeId) { return $this->scope; }
}
class RegionRequest extends Request {
    public $input = [];
    public function param($name = '', $default = null, $filter = '') {
        return $this->input[explode('/', $name)[0]] ?? $default;
    }
    public function getMore(array $params, bool $isList = false, bool $filter = true): array {
        $out = [];
        foreach ($params as $p) $out[is_array($p[0]) ? $p[0][0] : $p[0]] = $this->input[is_array($p[0]) ? $p[0][0] : $p[0]] ?? $p[1];
        return $isList ? array_values($out) : $out;
    }
}

$template = Db::name('system_store')->where('id', '>', 0)->find();
checkRegion((bool)$template, 'Local store schema fixture available');
unset($template['id']);
$originalDigest = hash('sha256', json_encode(Db::name('system_store')->order('id')->select()->toArray()));
foreach (['system_store', 'city_area'] as $table) {
    $ddl = Db::query('SHOW CREATE TABLE eb_' . $table)[0]['Create Table'];
    Db::execute(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl, 1));
}
Db::name('city_area')->insertAll([
    ['id' => 1, 'name' => '广东省', 'parent_id' => 0, 'level' => 1],
    ['id' => 2, 'name' => '广州市', 'parent_id' => 1, 'level' => 2],
    ['id' => 3, 'name' => '海珠区', 'parent_id' => 2, 'level' => 3],
    ['id' => 4, 'name' => '深圳市', 'parent_id' => 1, 'level' => 2],
    ['id' => 5, 'name' => '南山区', 'parent_id' => 4, 'level' => 3],
    ['id' => 6, 'name' => '浙江省', 'parent_id' => 0, 'level' => 1],
    ['id' => 7, 'name' => '杭州市', 'parent_id' => 6, 'level' => 2],
    ['id' => 8, 'name' => '海珠区', 'parent_id' => 7, 'level' => 3],
]);
for ($id = 1; $id <= 30; $id++) {
    Db::name('system_store')->insert(array_merge($template, ['id' => $id, 'name' => 'REGION-' . $id,
        'province' => $id === 30 ? 6 : 1, 'city' => $id === 30 ? 7 : 2, 'area' => $id === 30 ? 8 : 3,
        'is_show' => 1, 'is_del' => 0, 'is_alone' => 0, 'latitude' => '23.1', 'longitude' => '113.2']));
}
Db::name('system_store')->where('id', 1)->update(['province' => 0, 'city' => 0, 'area' => 0, 'address' => '不得从广州店文字猜地区']);
Db::name('system_store')->where('id', 2)->update(['city' => 4, 'area' => 3]); // cross-parent invalid district
Db::name('system_store')->where('id', 3)->update(['is_show' => 0]);
Db::name('system_store')->where('id', 4)->update(['is_del' => 1]);
Db::name('system_store')->where('id', 5)->update(['is_alone' => 1]);
$before = Db::name('system_store')->order('id')->select()->toArray();
$service = new RegionStoreProbe(new SystemStoreDao());
$controller = new Store($service);
$request = new RegionRequest();
$request->uid = 17;
$app->instance('request', $request);
$app->instance(StoreUserServices::class, new class extends StoreUserServices {
    public function __construct() {}
    public function getColumn($where, $field) { return [1, 30]; }
});
$app->instance(StoreProductServices::class, new class extends StoreProductServices {
    public function __construct() {}
    public function get($id, $field = []) {return ['pid' => 0, 'product_type' => $id === 100 ? 6 : 5, 'applicable_type' => 2, 'applicable_store_id' => $id === 100 ? [10, 30] : [30]];}
});
$app->instance(StoreBranchProductServices::class, new class extends StoreBranchProductServices {
    public function __construct() {}
    public function getApplicableStoreIds(int $productId, int $type = 0) {return [$productId === 300 ? [] : [10, 30], 2, 5];}
});
$api = function (array $input) use ($controller, $request) {
    $request->input = array_merge(['latitude' => '23.1', 'longitude' => '113.2'], $input);
    $response = $controller->getStoreList($request)->getData();
    if ((int)$response['status'] !== 200) throw new RuntimeException(json_encode($response));
    return $response['data'];
};

$queries = [];
Db::listen(function ($sql) use (&$queries) {$queries[] = $sql;});
$tree = $api(['region_options' => 1, 'province' => 999, 'page' => 1, 'limit' => 20]);
checkRegion(array_column($tree['regions'], 'id') === [1, 6], 'Options include matching stores beyond the first 20 and ignore current region');
checkRegion($tree['incomplete_count'] === 2, 'Unknown and cross-parent regions counted without guessing addresses');
checkRegion($tree['regions'][1]['children'][0]['children'][0]['id'] === 8, 'Same-name districts retain their distinct parent IDs');
checkRegion(count(array_filter($queries, function ($sql) {return strpos($sql, 'eb_city_area') !== false;})) === 1, 'One batched dictionary query, no per-store dictionary scans');
checkRegion(!isset($tree['regions'][0]['phone']), 'Options expose no store phone, location or identity');
$all = $api([]);
checkRegion(in_array(1, array_column($all, 'id')) && !array_intersect([3, 4, 5], array_column($all, 'id')), 'All stores retain unknown regions and exclude closed/deleted/isolated stores');
$province = $api(['province' => 6, 'latitude' => '', 'longitude' => '']);
checkRegion(array_column($province, 'id') === [30], 'Manual province filtering works without GPS');
$cleared = $api(['latitude' => '', 'longitude' => '']);
checkRegion(in_array(1, array_column($cleared, 'id')) && in_array(30, array_column($cleared, 'id')), 'Clearing manual region works without GPS and retains unclassified stores');
$city = $api(['province' => 1, 'city' => 4]);
checkRegion(array_column($city, 'id') === [2], 'Whole-city filter preserves incomplete district stores');
$district = $api(['province' => 6, 'city' => 7, 'area' => 8]);
checkRegion(array_column($district, 'id') === [30], 'District IDs filter only their real parent region');
$request->input = ['province' => 6, 'city' => 7, 'is_sum' => 1];
checkRegion($controller->getStoreList($request)->getData() === app('json')->success(1)->getData(), 'Original store-count response envelope remains unchanged');
$single = $service->getNearbyStore(['is_all' => 1], '', '', '', 1);
checkRegion(isset($single['id'], $single['delivery_config']) && !isset($single[0]), 'Original single-nearest-store response and delivery configuration remain intact');
$request->input = ['page' => 1, 'limit' => 20];
checkRegion(count($service->getNearbyStore(['is_all' => 1], '', '')) === 20, 'Original normal-list pagination remains unchanged');
$empty = $api(['region_options' => 1, 'keyword' => 'no-match']);
checkRegion($empty === ['regions' => [], 'incomplete_count' => 0], 'No matching stores returns an explicit empty region tree');
$common = $api(['region_options' => 1, 'store_type' => 2]);
checkRegion(array_column($common['regions'], 'id') === [6] && $common['incomplete_count'] === 1, 'Common stores use the same user-store scope');
$request->uid = 0;
checkRegion($api(['region_options' => 1, 'store_type' => 2])['regions'] === [], 'Anonymous users cannot obtain another user common-store regions');
$request->uid = 17;
$service->scope = [10];
checkRegion(array_column($api(['region_options' => 1])['regions'], 'id') === [1], 'Entry region scope is preserved');
$service->scope = [];
$service->entry = 5;
checkRegion(array_column($api(['region_options' => 1])['regions'], 'id') === [1], 'An isolated entry keeps only its own store');
$service->entry = 0;
checkRegion(array_column($api(['region_options' => 1, 'product_id' => 100, 'card_product_id' => 200])['regions'], 'id') === [6], 'Product and component-card restrictions intersect before projecting regions');
checkRegion($api(['region_options' => 1, 'product_id' => 300])['regions'] === [], 'A product with no applicable stores cannot fall back to all regions');
$bad = new RegionRequest();
$bad->input = ['province' => 1, 'latitude' => 'not-a-number', 'longitude' => '113.2'];
checkRegion($controller->getStoreList($bad)->getData()['status'] !== 200, 'Invalid supplied coordinates cannot reach distance SQL');
checkRegion(Db::name('system_store')->order('id')->select()->toArray() === $before, 'All reads leave historical store data byte-for-byte unchanged');
echo 'EXPLAIN=', json_encode(Db::query('EXPLAIN SELECT id,province,city,area FROM eb_system_store WHERE is_del=0 AND is_show=1 AND is_alone=0'), JSON_UNESCAPED_UNICODE), PHP_EOL;
foreach (['system_store', 'city_area'] as $table) Db::execute('DROP TEMPORARY TABLE eb_' . $table);
checkRegion(hash('sha256', json_encode(Db::name('system_store')->order('id')->select()->toArray())) === $originalDigest, 'Temporary fixtures released; permanent store table digest unchanged');
echo "PASS local MySQL region filter integration\n";
