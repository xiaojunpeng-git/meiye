<?php

/** 本机只读回归：执行真实控制器与组织绑定查询，不改变门店、组织或权限资料。 */
require '/var/www/html/vendor/autoload.php';

use app\controller\admin\v1\store\SystemStore;
use app\services\agent\SystemRegionAgentServices;
use app\services\store\SystemStoreServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许本地 Docker 数据库');
}

/** 只替换 HTTP 请求/响应边界，保留真实列表、详情及共同组织投影实现。 */
class StoreOrganizationReadProbe extends SystemStore
{
    public function __construct(array $params = [])
    {
        $this->services = app()->make(SystemStoreServices::class);
        $this->adminType = 0;
        $this->agentId = 0;
        $this->request = new class($params) {
            private $params;
            public function __construct(array $params) { $this->params = $params; }
            public function param($key, $default = null) { return $this->params[$key] ?? $default; }
            public function getMore($definitions, $list = false)
            {
                $result = [];
                foreach ($definitions as $definition) {
                    $key = is_array($definition[0]) ? $definition[0][0] : $definition[0];
                    $result[$key] = $this->params[$key] ?? $definition[1];
                }
                return $list ? array_values($result) : $result;
            }
        };
    }

    public function success($message = '', $data = [])
    {
        return is_array($message) ? $message : $data;
    }
}

function storeOrganizationAssert($ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ', $label, PHP_EOL;
}

$queries = [];
Db::listen(static function ($sql) use (&$queries) { $queries[] = $sql; });
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $controller = new StoreOrganizationReadProbe();
    $project = new ReflectionMethod(SystemStore::class, 'withStoreOrganizations');
    $project->setAccessible(true);
    $binding = Db::name('organization_store')->alias('os')->join('organization o', 'o.id=os.org_id')
        ->where('os.store_id', 179)->field('os.org_id,o.name')->find();
    if (!$binding) throw new RuntimeException('缺少本地门店 179 的组织绑定');
    $before = Db::name('organization_store')->where('store_id', 179)->find();
    $input = [['id' => 179, 'name' => '保留门店字段', 'recommend_region_name' => '禁止使用的旧区域'],
        ['id' => 2147483647, 'recommend_region_name' => '禁止回退旧区域']];
    $queries = [];
    $rows = $project->invoke($controller, $input);
    storeOrganizationAssert(count($queries) === 1, '一页多门店只用一次组织批量查询');
    storeOrganizationAssert(array_column($rows, 'id') === [179, 2147483647], '仅装饰输入门店，不扩展范围或改变顺序');
    storeOrganizationAssert($rows[0]['organization_id'] === (int)$binding['org_id']
        && $rows[0]['organization_name'] === $binding['name'], '名称来自正式组织绑定，不来自旧区域');
    storeOrganizationAssert($rows[0]['name'] === $input[0]['name'] && !isset($input[0]['organization_name']), '保留其他门店字段且不改调用方输入');
    storeOrganizationAssert($rows[1]['organization_id'] === 0 && $rows[1]['organization_name'] === '', '未绑定组织时留空，不猜测旧区域归属');
    $queries = [];
    storeOrganizationAssert($project->invoke($controller, []) === [] && !$queries, '空分页不执行组织查询');
    $detail = $controller->get_info(179)['info'];
    storeOrganizationAssert($detail['organization_id'] === $rows[0]['organization_id']
        && $detail['organization_name'] === $rows[0]['organization_name'], '真实编辑详情复用同一组织投影');
    $result = $controller->index(app()->make(SystemRegionAgentServices::class));
    storeOrganizationAssert(isset($result['count']) && count($result['list']) <= $result['count'], '真实列表保持结果及总数契约');
    foreach ($result['list'] as $row) {
        $current = $controller->get_info($row['id'])['info'];
        if ($current['organization_id'] !== $row['organization_id']
            || $current['organization_name'] !== $row['organization_name']) {
            throw new RuntimeException('列表/详情组织不一致：门店 ' . $row['id']);
        }
    }
    storeOrganizationAssert(count($result['list']) > 0, '真实列表/详情逐店组织一致，共 ' . count($result['list']) . ' 家');
    storeOrganizationAssert(Db::name('organization_store')->where('store_id', 179)->find() === $before, '读取不改变组织绑定');
    // 通过真实入口检查筛选与总数；通过服务检查组织条件不能与权限 id/ids 作并集。
    $orgId = (int)$binding['org_id'];
    $options = app()->make(\app\services\organization\OrganizationResourceSelectorServices::class)
        ->searchScoped(['resource' => 'organization', 'keyword' => $binding['name']], [179]);
    storeOrganizationAssert(in_array($orgId, array_map('intval', array_column($options['list'], 'id')), true),
        '权限裁剪后的正式组织选项包含该店真实组织 ID，不读取旧区域树');
    $service = app()->make(SystemStoreServices::class);
    $scope = app()->make(\app\services\organization\OrganizationScopeService::class);
    $orgStores = $scope->getOrgStoreIds($orgId, true);
    $filtered = (new StoreOrganizationReadProbe(['organization_id' => $orgId]))
        ->index(app()->make(SystemRegionAgentServices::class));
    $expected = array_values(array_filter($result['list'], static function ($row) use ($orgStores) {
        return in_array((int)$row['id'], $orgStores, true);
    }));
    storeOrganizationAssert(array_column($filtered['list'], 'id') === array_column($expected, 'id')
        && (int)$filtered['count'] === count($expected) && count($expected) > 0, '真实入口按正式组织筛选，列表与总数一致');
    $parentId = (int)Db::name('organization')->where('id', $orgId)->value('pid');
    $parent = (new StoreOrganizationReadProbe(['organization_id' => $parentId ?: $orgId]))
        ->index(app()->make(SystemRegionAgentServices::class));
    storeOrganizationAssert(in_array(179, array_map('intval', array_column($parent['list'], 'id')), true), '选择上级组织包含下级绑定门店');
    $empty = (new StoreOrganizationReadProbe(['organization_id' => 2147483647]))
        ->index(app()->make(SystemRegionAgentServices::class));
    storeOrganizationAssert($empty['list'] === [] && (int)$empty['count'] === 0, '无门店或不存在的组织不回退全量');
    $combined = (new StoreOrganizationReadProbe(['organization_id' => $orgId, 'keywords' => '必不命中的门店关键词']))
        ->index(app()->make(SystemRegionAgentServices::class));
    storeOrganizationAssert($combined['list'] === [] && (int)$combined['count'] === 0, '组织与原有关键词筛选取交集');
    $outside = array_values(array_diff(array_map('intval', array_column($result['list'], 'id')), $orgStores))[0] ?? 2147483647;
    $restricted = $service->getStoreList(['organization_store_ids' => $orgStores, 'ids' => [$outside], 'id' => [$outside], 'is_del' => 0]);
    storeOrganizationAssert($restricted['list'] === [] && (int)$restricted['count'] === 0, '组织筛选不能与既有权限 id/ids 合并扩大范围');
    $cleared = (new StoreOrganizationReadProbe(['organization_id' => 0]))->index(app()->make(SystemRegionAgentServices::class));
    storeOrganizationAssert(array_column($cleared['list'], 'id') === array_column($result['list'], 'id')
        && $cleared['count'] === $result['count'], '清空组织恢复原有查询范围');
    echo 'EXPLAIN=', json_encode(Db::query('EXPLAIN SELECT os.store_id,os.org_id,o.name FROM eb_organization_store os LEFT JOIN eb_organization o ON o.id=os.org_id WHERE os.store_id IN (179,177)'), JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    Db::rollback();
}
