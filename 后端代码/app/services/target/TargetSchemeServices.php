<?php

declare(strict_types=1);

namespace app\services\target;

use app\services\query\metric\MetricDefinitionRegistry;
use think\facade\Db;

/**
 * 月目标方案的唯一写入和有效值入口。目标是人工配置，不修改交易事实；
 * 所有写操作在授权门店范围内，使用版本、幂等回执和审计保护。
 */
final class TargetSchemeServices
{
    public const REPORTS = [
        'group_dashboard' => '集团管理看板',
        'store_target' => '门店目标',
        'target_analysis' => '目标数据分析',
        'target_monthly' => '月度目标完成明细',
        'target_store' => '门店目标完成明细',
        'merchant_target_analysis' => '商家目标分析',
    ];

    /** 仅公布已由统一事实 Reader 支持月汇总的指标，旧指标字典条目不自动获得目标资格。 */
    private const METRICS = [
        'cash_performance' => ['label' => '现金业绩', 'scopes' => ['store', 'product', 'category']],
        'refund_performance' => ['label' => '退款业绩', 'scopes' => ['store', 'product', 'category']],
        'actual_performance' => ['label' => '实际业绩', 'scopes' => ['store', 'product', 'category']],
        'consume_amount' => ['label' => '消耗业绩', 'scopes' => ['store', 'product', 'category']],
        'sales_amount' => ['label' => '销售额', 'scopes' => ['store', 'product', 'category']],
        'sales_collected_amount' => ['label' => '销售收款', 'scopes' => ['store']],
        'sales_quantity' => ['label' => '销售数量', 'scopes' => ['store']],
        'sales_record_count' => ['label' => '销售记录数', 'scopes' => ['store']],
        'balance_deduction_amount' => ['label' => '余额抵扣金额', 'scopes' => ['store']],
        'recharge_amount' => ['label' => '充值本金', 'scopes' => ['store']],
        'completed_service_item_count' => ['label' => '完成项目数', 'scopes' => ['store', 'product', 'category']],
        'customer_active' => ['label' => '服务会员数', 'scopes' => ['store']],
    ];

    public function catalog(): array
    {
        $metrics = [];
        foreach (self::METRICS as $code => $entry) {
            $contract = MetricDefinitionRegistry::get($code);
            $metrics[] = ['code' => $code, 'name' => $entry['label'], 'unit' => $contract['storage_unit'],
                'scopes' => $entry['scopes'], 'metric_version' => $contract['metric_version'] ?? MetricDefinitionRegistry::VERSION];
        }
        return ['metrics' => $metrics, 'reports' => self::REPORTS, 'coverage_start' => MetricDefinitionRegistry::COVERAGE_START];
    }

    /** 可见方案仅包含授权门店的目标行；元数据不暴露其他门店的目标值。 */
    public function listing(array $context): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        if (!$stores) return [];
        // 方案是不可拆分的管理对象：只要含有一间越权门店，列表也不能泄露名称和状态。
        $ids = Db::name('target_scheme_line')->where('tenant_id', $tenant)->whereIn('store_id', $stores)->distinct(true)->column('scheme_id');
        // 新建的空草稿仅创建者可见，避免列表把尚无门店范围的草稿公开给所有人。
        $draftIds = Db::name('target_scheme')->where('tenant_id', $tenant)->where('created_by', $actor)->where('status', 'draft')->column('id');
        $ids = array_values(array_unique(array_merge(array_map('intval', $ids), array_map('intval', $draftIds))));
        if (!$ids) return [];
        $rows = Db::name('target_scheme')->where('tenant_id', $tenant)->whereIn('id', $ids)->order('id', 'desc')->select()->toArray();
        $visible = [];
        foreach ($rows as $row) {
            $all = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $row['id'])->column('store_id');
            if (!$all && (int)$row['created_by'] !== $actor) continue;
            $outside = array_filter($all, static fn($storeId) => !in_array((int)$storeId, $stores, true));
            if ($outside) continue;
            $row['line_count'] = count($all);
            $visible[] = $row;
        }
        return $visible;
    }

    public function detail(array $context, int $schemeId): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $scheme = Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $schemeId)->find();
        if (!$scheme) throw new \InvalidArgumentException('目标方案不存在');
        $lines = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->order('store_id,month_key,metric_code,scope_type,scope_id')->select()->toArray();
        if (!$lines && (int)$scheme['created_by'] !== $actor) throw new \InvalidArgumentException('无权查看该空方案');
        foreach ($lines as $line) if (!in_array((int)$line['store_id'], $stores, true)) throw new \InvalidArgumentException('当前权限不覆盖方案的全部门店');
        // 商品名称只供草稿回显；目标对象仍以保存的商品 ID 与交易发生时事实为准。
        $productIds = array_values(array_unique(array_map('intval', array_column(array_filter($lines,
            static fn($line) => $line['scope_type'] === 'product'), 'scope_id'))));
        $productNames = $productIds ? Db::name('store_product')->whereIn('id', $productIds)->column('store_name', 'id') : [];
        foreach ($lines as &$line) if ($line['scope_type'] === 'product')
            $line['scope_name'] = (string)($productNames[$line['scope_id']] ?? ('商品#'.$line['scope_id']));
        unset($line);
        $bindings = Db::name('target_report_binding')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->distinct(true)->column('report_code');
        return ['scheme' => $scheme, 'lines' => $lines, 'report_codes' => $bindings];
    }

    /** 创建草稿；创建对象没有“版本 0”，重放由命令唯一键保护。 */
    public function create(array $context, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        if (!$stores || $actor <= 0) throw new \InvalidArgumentException('无目标方案管理权限');
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('方案名称不能为空且不得超过120字');
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description) > 500) throw new \InvalidArgumentException('方案说明不得超过500字');
        $payload = ['name' => $name, 'description' => $description];
        return $this->command($tenant, $actor, $input, $payload, function () use ($tenant, $actor, $payload): array {
            $now = time();
            $id = (int)Db::name('target_scheme')->insertGetId(['tenant_id' => $tenant, 'name' => $payload['name'],
                'description' => $payload['description'], 'status' => 'draft', 'version' => 1,
                'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit($tenant, $id, $actor, 'created', [], $payload);
            return ['id' => $id, 'version' => 1, 'status' => 'draft'];
        });
    }

    /** 草稿元数据与目标明细共用方案版本；已生效方案保持快照，不能直接改名或改口径。 */
    public function updateDraft(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $expected = (int)($input['expected_version'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        if ($expected <= 0) throw new \InvalidArgumentException('请刷新目标方案后保存');
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('方案名称不能为空且不得超过120字');
        if (mb_strlen($description) > 500) throw new \InvalidArgumentException('方案说明不得超过500字');
        $payload = ['scheme_id' => $schemeId, 'version' => $expected, 'name' => $name, 'description' => $description];
        return $this->command($tenant, $actor, $input, $payload,
            function () use ($tenant, $stores, $actor, $schemeId, $expected, $name, $description): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ($scheme['status'] !== 'draft') throw new \InvalidArgumentException('已生效方案不能编辑，请建立新草稿');
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                $before = ['name' => $scheme['name'], 'description' => $scheme['description']];
                $after = ['name' => $name, 'description' => $description];
                Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $schemeId)->update($after + [
                    'version' => $expected + 1, 'updated_by' => $actor, 'updated_at' => time(),
                ]);
                $this->audit($tenant, $schemeId, $actor, 'metadata_updated', $before, $after);
                return ['id' => $schemeId, 'version' => $expected + 1];
            });
    }

    /** 整表校验后才替换草稿行；同方案内同目标身份只能有一行。 */
    public function replaceLines(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $raw = $input['lines'] ?? null;
        if (!is_array($raw) || !$raw || count($raw) > 3000) throw new \InvalidArgumentException('请提交1至3000条目标');
        $lines = []; $keys = [];
        foreach ($raw as $index => $line) {
            if (!is_array($line)) throw new \InvalidArgumentException('第'.($index + 1).'行格式错误');
            $normalized = $this->line($tenant, $stores, $line);
            $key = implode(':', [$normalized['store_id'], $normalized['month_key'], $normalized['metric_code'], $normalized['scope_type'], $normalized['scope_id']]);
            if (isset($keys[$key])) throw new \InvalidArgumentException('目标方案存在重复的门店、月份、指标和对象');
            $keys[$key] = true; $lines[] = $normalized;
        }
        $expected = (int)($input['expected_version'] ?? 0);
        if ($expected <= 0) throw new \InvalidArgumentException('请刷新目标方案后保存');
        return $this->command($tenant, $actor, $input, ['scheme_id' => $schemeId, 'version' => $expected, 'lines' => $lines],
            function () use ($tenant, $stores, $actor, $schemeId, $expected, $lines): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ($scheme['status'] !== 'draft') throw new \InvalidArgumentException('生效中的方案不能修改，请建立新草稿');
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                $before = Db::name('target_scheme_line')->where('scheme_id', $schemeId)->where('tenant_id', $tenant)->select()->toArray();
                Db::name('target_scheme_line')->where('scheme_id', $schemeId)->where('tenant_id', $tenant)->delete();
                $now = time();
                foreach ($lines as $line) Db::name('target_scheme_line')->insert($line + [
                    'scheme_id' => $schemeId, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                Db::name('target_scheme')->where('id', $schemeId)->update(['version' => $expected + 1, 'updated_by' => $actor, 'updated_at' => $now]);
                $this->audit($tenant, $schemeId, $actor, 'lines_replaced', $before, $lines);
                return ['id' => $schemeId, 'version' => $expected + 1, 'line_count' => count($lines)];
            });
    }

    /**
     * 激活与门店级报表引用在同一事务中完成。局部权限只能切换自身门店的引用；
     * 若重叠目标仍被未切换的报表引用则拒绝，避免读到失效的半套方案。
     */
    public function activate(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $expected = (int)($input['expected_version'] ?? 0);
        $reports = array_values(array_unique((array)($input['report_codes'] ?? [])));
        if ($expected <= 0 || !$reports) throw new \InvalidArgumentException('请选择报表并提交当前方案版本');
        foreach ($reports as $code) if (!isset(self::REPORTS[$code])) throw new \InvalidArgumentException('报表引用无效');
        sort($reports);
        return $this->command($tenant, $actor, $input, ['scheme_id' => $schemeId, 'version' => $expected, 'reports' => $reports],
            function () use ($tenant, $stores, $actor, $schemeId, $expected, $reports): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                if ($scheme['status'] !== 'draft') throw new \InvalidArgumentException('已生效方案不可重复启用，请建立新草稿');
                $lines = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->order('id')->select()->toArray();
                if (!$lines) throw new \InvalidArgumentException('方案没有目标明细');
                foreach ($lines as $line) if (!in_array((int)$line['store_id'], $stores, true)) throw new \InvalidArgumentException('当前权限不覆盖方案的全部门店');
                $affectedStores = array_values(array_unique(array_map('intval', array_column($lines, 'store_id'))));
                $conflictingBindings = [];
                foreach ($lines as $line) {
                    $conflict = Db::name('target_effective_line')->where('tenant_id', $tenant)
                        ->where('store_id', $line['store_id'])->where('month_key', $line['month_key'])
                        ->where('metric_code', $line['metric_code'])->where('scope_type', $line['scope_type'])
                        ->where('scope_id', $line['scope_id'])->lock(true)->find();
                    if ($conflict && (int)$conflict['scheme_id'] !== $schemeId)
                        $conflictingBindings[(int)$conflict['scheme_id']][(int)$line['store_id']] = true;
                }
                foreach ($conflictingBindings as $oldId => $storeSet) {
                    foreach (array_keys($storeSet) as $storeId) {
                        $bound = Db::name('target_report_binding')->where('tenant_id', $tenant)->where('scheme_id', $oldId)
                            ->where('store_id', $storeId)->lock(true)->column('report_code');
                        // 孤立有效行无法通过报表切换安全退役，明确拒绝而不是撞唯一键后返回数据库错误。
                        if (!$bound) throw new \InvalidArgumentException('重叠有效目标未绑定报表，请先核对目标数据');
                        foreach ($bound as $code) if (!in_array($code, $reports, true))
                            throw new \InvalidArgumentException('重叠目标仍被其他报表引用，请一并切换该门店引用');
                    }
                }
                $now = time();
                $displaced = [];
                foreach ($affectedStores as $storeId) {
                    foreach ($reports as $code) {
                        $bound = Db::name('target_report_binding')->where('tenant_id', $tenant)
                            ->where('report_code', $code)->where('store_id', $storeId)->lock(true)->find();
                        if ($bound && (int)$bound['scheme_id'] !== $schemeId) $displaced[(int)$bound['scheme_id']][$storeId] = true;
                        if ($bound) Db::name('target_report_binding')->where('id', $bound['id'])->update([
                            'scheme_id' => $schemeId, 'version' => (int)$bound['version'] + 1,
                            'updated_by' => $actor, 'updated_at' => $now,
                        ]);
                        else Db::name('target_report_binding')->insert(['tenant_id' => $tenant, 'report_code' => $code,
                            'store_id' => $storeId, 'scheme_id' => $schemeId, 'version' => 1,
                            'updated_by' => $actor, 'updated_at' => $now]);
                    }
                }
                foreach ($displaced as $oldId => $storeSet) {
                    foreach (array_keys($storeSet) as $storeId) {
                        // 只有该店再无任何报表引用旧方案时，才退役该店旧有效行；其他店不受影响。
                        $stillBound = Db::name('target_report_binding')->where('tenant_id', $tenant)
                            ->where('scheme_id', $oldId)->where('store_id', $storeId)->count();
                        if (!$stillBound) Db::name('target_effective_line')->where('tenant_id', $tenant)
                            ->where('scheme_id', $oldId)->where('store_id', $storeId)->delete();
                    }
                    $remaining = Db::name('target_effective_line')->where('tenant_id', $tenant)->where('scheme_id', $oldId)->count();
                    Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $oldId)->update([
                        'status' => $remaining ? 'active' : 'draft', 'version' => Db::raw('version+1'), 'updated_at' => $now,
                    ]);
                }
                foreach ($lines as $line) Db::name('target_effective_line')->insert([
                    'tenant_id' => $tenant, 'store_id' => $line['store_id'], 'month_key' => $line['month_key'],
                    'metric_code' => $line['metric_code'], 'scope_type' => $line['scope_type'], 'scope_id' => $line['scope_id'],
                    'scheme_id' => $schemeId, 'line_id' => $line['id'], 'activated_at' => $now,
                ]);
                Db::name('target_scheme')->where('id', $schemeId)->update(['status' => 'active', 'version' => $expected + 1,
                    'updated_by' => $actor, 'updated_at' => $now]);
                $result = ['id' => $schemeId, 'version' => $expected + 1, 'status' => 'active', 'report_codes' => $reports];
                $this->audit($tenant, $schemeId, $actor, 'activated', [], $result);
                return $result;
            });
    }

    /** 每个授权门店读取各自的报表引用，未绑定时返回空，绝不回退旧目标表。 */
    public function effective(array $context, string $reportCode, array $months = []): array
    {
        [$tenant, $stores] = $this->scope($context);
        if (!isset(self::REPORTS[$reportCode]) || !$stores) return ['scheme_id' => 0, 'scheme_ids' => [], 'lines' => []];
        $query = Db::name('target_effective_line')->alias('e')
            ->join('target_report_binding b', 'b.tenant_id=e.tenant_id AND b.scheme_id=e.scheme_id AND b.store_id=e.store_id')
            ->join('target_scheme_line l', 'l.id=e.line_id AND l.tenant_id=e.tenant_id')
            ->where('e.tenant_id', $tenant)->where('b.report_code', $reportCode)->whereIn('e.store_id', $stores);
        if ($months) $query->whereIn('e.month_key', $months);
        $lines = $query->field('l.*')->order('l.month_key,l.store_id,l.metric_code,l.scope_type,l.scope_id')->select()->toArray();
        $schemeIds = array_values(array_unique(array_map('intval', array_column($lines, 'scheme_id'))));
        return ['scheme_id' => count($schemeIds) === 1 ? $schemeIds[0] : 0, 'scheme_ids' => $schemeIds, 'lines' => $lines];
    }

    private function line(string $tenant, array $stores, array $input): array
    {
        $store = (int)($input['store_id'] ?? 0);
        if ($store <= 0 || !in_array($store, $stores, true)) throw new \InvalidArgumentException('目标门店超出当前权限');
        $month = trim((string)($input['month_key'] ?? ''));
        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $month)) throw new \InvalidArgumentException('目标月份无效');
        $metric = trim((string)($input['metric_code'] ?? ''));
        $type = trim((string)($input['scope_type'] ?? 'store'));
        if (!isset(self::METRICS[$metric]) || !in_array($type, self::METRICS[$metric]['scopes'], true)) throw new \InvalidArgumentException('指标不支持当前目标对象');
        $id = (int)($input['scope_id'] ?? 0);
        if ($type === 'store' && $id !== 0) throw new \InvalidArgumentException('全店目标对象必须为空');
        if ($type !== 'store' && $id <= 0) throw new \InvalidArgumentException('请选择商品或分类');
        // 门店商品是按门店上架的对象；不能拿另一门店商品 ID 设本店目标。
        if ($type === 'product' && !Db::name('store_product')->where('id', $id)->where('is_del', 0)
            ->where('type', 1)->where('relation_id', $store)->find()) throw new \InvalidArgumentException('该门店没有此商品');
        if ($type === 'category' && !Db::name('store_product_category')->where('id', $id)
            ->where('type', 0)->where('relation_id', 0)->find()) throw new \InvalidArgumentException('商品分类不存在');
        $raw = trim((string)($input['target_value'] ?? ''));
        if (!preg_match('/^\d{1,15}$/D', $raw)) throw new \InvalidArgumentException('目标值必须为非负整数，金额单位为分');
        $contract = MetricDefinitionRegistry::get($metric);
        return ['tenant_id' => $tenant, 'store_id' => $store, 'month_key' => $month,
            'metric_code' => $metric, 'scope_type' => $type, 'scope_id' => $id,
            // 分类目标冻结保存时自身及所有下级分类，避免组织商品分类调整后历史完成值漂移。
            'scope_category_ids_json' => json_encode($type === 'category' ? $this->categoryDescendants($id) : []),
            'target_value' => (int)$raw, 'unit_code' => $contract['storage_unit'],
            'metric_version' => (string)($contract['metric_version'] ?? MetricDefinitionRegistry::VERSION)];
    }

    private function categoryDescendants(int $categoryId): array
    {
        static $children = null;
        if ($children === null) {
            $children = [];
            foreach (Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)->field('id,pid')->select()->toArray() as $row)
                $children[(int)$row['pid']][] = (int)$row['id'];
        }
        $ids = [$categoryId]; $seen = [$categoryId => true];
        for ($index = 0; $index < count($ids); $index++) {
            foreach ($children[$ids[$index]] ?? [] as $childId) {
                if (isset($seen[$childId])) continue;
                $seen[$childId] = true; $ids[] = $childId;
            }
        }
        sort($ids);
        return $ids;
    }

    private function lockedScheme(string $tenant, int $schemeId, array $stores, int $actor): array
    {
        $scheme = Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $schemeId)->lock(true)->find();
        if (!$scheme) throw new \InvalidArgumentException('目标方案不存在');
        $existingStores = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->distinct(true)->column('store_id');
        if (!$existingStores && (int)$scheme['created_by'] !== $actor) throw new \InvalidArgumentException('无权修改其他人的空方案');
        foreach ($existingStores as $id) if (!in_array((int)$id, $stores, true)) throw new \InvalidArgumentException('当前权限不覆盖方案的全部门店');
        return $scheme;
    }

    private function command(string $tenant, int $actor, array $input, array $payload, callable $write): array
    {
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 128) throw new \InvalidArgumentException('操作标识无效');
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Db::transaction(function () use ($tenant, $actor, $key, $hash, $write): array {
            $old = Db::name('target_scheme_command')->where('tenant_id', $tenant)->where('idempotency_key', $key)->lock(true)->find();
            if ($old) {
                if ($old['request_hash'] !== $hash || (int)$old['actor_id'] !== $actor) throw new \InvalidArgumentException('操作标识已用于其他请求');
                return json_decode((string)$old['response_json'], true) ?: [];
            }
            $result = $write();
            Db::name('target_scheme_command')->insert(['tenant_id' => $tenant, 'actor_id' => $actor,
                'idempotency_key' => $key, 'request_hash' => $hash,
                'response_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => time()]);
            return $result;
        });
    }

    private function audit(string $tenant, int $schemeId, int $actor, string $action, array $before, array $after): void
    {
        Db::name('target_scheme_audit')->insert(['tenant_id' => $tenant, 'scheme_id' => $schemeId,
            'actor_id' => $actor, 'action' => $action,
            'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'occurred_at' => time()]);
    }

    private function scope(array $context): array
    {
        $tenant = (string)($context['tenant_id'] ?? '0');
        $stores = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        sort($stores);
        return [$tenant, $stores, (int)($context['actor_id'] ?? 0)];
    }
}
