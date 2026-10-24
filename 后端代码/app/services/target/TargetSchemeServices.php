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
        // 业绩对比报表只读取显式绑定的方案，不能借用其他报表的目标引用。
        'operations_performance_comparison' => '业绩对比报表',
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
        // 空草稿或空方案作废后仍供创建者追溯，不向其他账号暴露尚无门店范围的方案。
        $ownEmptyIds = Db::name('target_scheme')->where('tenant_id', $tenant)->where('created_by', $actor)
            ->whereIn('status', ['draft', 'void'])->column('id');
        $ids = array_values(array_unique(array_merge(array_map('intval', $ids), array_map('intval', $ownEmptyIds))));
        if (!$ids) return [];
        $rows = Db::name('target_scheme')->where('tenant_id', $tenant)->whereIn('id', $ids)->where('status', '<>', 'deleted')->order('id', 'desc')->select()->toArray();
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
        if (!$scheme || $scheme['status'] === 'deleted') throw new \InvalidArgumentException('目标方案不存在');
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
        $activeBindings = Db::name('target_report_line_binding')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)
            ->field('line_id,report_code')->select()->toArray();
        $bindings = array_values(array_unique(array_column($activeBindings, 'report_code')));
        // 部分目标被替换后方案仍可生效；逐行显式返回当前引用，不能把整份历史明细说成仍在生效。
        // 预先按行分组，避免 3000 行 × 21000 引用的嵌套扫描。
        $reportsByLine = [];
        foreach ($activeBindings as $binding) $reportsByLine[(int)$binding['line_id']][] = $binding['report_code'];
        foreach ($lines as &$line) {
            $line['active_report_codes'] = $reportsByLine[(int)$line['id']] ?? [];
        }
        unset($line);
        $voidAudit = $scheme['status'] === 'void' ? Db::name('target_scheme_audit')->where('tenant_id', $tenant)
            ->where('scheme_id', $schemeId)->where('action', 'voided')->order('id', 'desc')->find() : null;
        $voidInfo = $voidAudit ? (json_decode((string)$voidAudit['after_json'], true) ?: []) + [
            'actor_id' => (int)$voidAudit['actor_id'], 'occurred_at' => (int)$voidAudit['occurred_at'],
        ] : null;
        return ['scheme' => $scheme, 'lines' => $lines, 'report_codes' => $bindings, 'void_info' => $voidInfo];
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
     * 生效唯一性是报表×门店×月份×指标×对象，不是整店。冲突预览不改变目标；
     * 用户确认的指纹必须仍匹配当前引用，才允许原子替换相同目标，保留其他有效行。
     */
    public function activate(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $expected = (int)($input['expected_version'] ?? 0);
        $reports = array_values(array_unique((array)($input['report_codes'] ?? [])));
        if ($expected <= 0 || !$reports) throw new \InvalidArgumentException('请选择报表并提交当前方案版本');
        foreach ($reports as $code) if (!isset(self::REPORTS[$code])) throw new \InvalidArgumentException('报表引用无效');
        sort($reports);
        $preview = !empty($input['conflict_preview']);
        $confirmation = (string)($input['conflict_token'] ?? '');
        return $this->command($tenant, $actor, $input, ['action' => 'activate', 'scheme_id' => $schemeId,
            'version' => $expected, 'reports' => $reports, 'preview' => $preview, 'confirmation' => $confirmation,
            'authorized_store_ids' => $stores],
            function () use ($tenant, $stores, $actor, $schemeId, $expected, $reports, $preview, $confirmation): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                if ($scheme['status'] !== 'draft') throw new \InvalidArgumentException('已生效方案不可重复启用，请建立新草稿');
                $lines = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->order('id')->select()->toArray();
                if (!$lines) throw new \InvalidArgumentException('方案没有目标明细');
                foreach ($lines as $line) if (!in_array((int)$line['store_id'], $stores, true)) throw new \InvalidArgumentException('当前权限不覆盖方案的全部门店');
                $existing = Db::name('target_report_line_binding')->where('tenant_id', $tenant)
                    ->whereIn('report_code', $reports)->whereIn('store_id', array_unique(array_column($lines, 'store_id')))
                    ->whereIn('month_key', array_unique(array_column($lines, 'month_key')))->order('id')->lock(true)->select()->toArray();
                $byKey = [];
                foreach ($existing as $binding) $byKey[$this->bindingKey($binding)] = $binding;
                $changes = []; $conflicts = [];
                foreach ($reports as $code) foreach ($lines as $line) {
                    $identity = array_intersect_key($line, array_flip(['store_id', 'month_key', 'metric_code', 'scope_type', 'scope_id']));
                    $identity['report_code'] = $code;
                    $old = $byKey[$this->bindingKey($identity)] ?? null;
                    $changes[] = ['identity' => $identity, 'line_id' => (int)$line['id'], 'old' => $old];
                    if ($old) $conflicts[] = $identity + ['binding_id' => (int)$old['id'],
                        'binding_version' => (int)$old['version'], 'old_scheme_id' => (int)$old['scheme_id'],
                        'old_line_id' => (int)$old['line_id'], 'new_line_id' => (int)$line['id']];
                }
                $token = hash('sha256', json_encode([$tenant, $schemeId, $expected, $reports, $conflicts]));
                if ($conflicts && !hash_equals($token, $confirmation)) {
                    if (!$preview) throw new \InvalidArgumentException('存在已生效的相同目标，请重新预览并确认替换');
                    // 旧方案可能包含越权门店，冲突只公开当前授权目标身份和方案编号，不泄露其名称或其他明细。
                    return ['status' => 'confirmation_required', 'conflict_token' => $token, 'conflicts' => $conflicts];
                }
                $now = time();
                $displaced = [];
                foreach ($changes as $change) {
                    $old = $change['old'];
                    $value = ['scheme_id' => $schemeId, 'line_id' => $change['line_id'],
                        'version' => $old ? (int)$old['version'] + 1 : 1, 'updated_by' => $actor, 'updated_at' => $now];
                    if ($old) {
                        $displaced[(int)$old['scheme_id']] = true;
                        Db::name('target_report_line_binding')->where('id', $old['id'])->update($value);
                    } else Db::name('target_report_line_binding')->insert($change['identity'] + $value + ['tenant_id' => $tenant]);
                }
                foreach (array_keys($displaced) as $oldId) {
                    $remaining = Db::name('target_report_line_binding')->where('tenant_id', $tenant)->where('scheme_id', $oldId)->count();
                    Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $oldId)->update([
                        'status' => $remaining ? 'active' : 'draft', 'version' => Db::raw('version+1'), 'updated_at' => $now, 'updated_by' => $actor,
                    ]);
                    $this->audit($tenant, $oldId, $actor, 'targets_replaced', [], ['replacement_scheme_id' => $schemeId,
                        'conflicts' => array_values(array_filter($conflicts, static fn($c) => $c['old_scheme_id'] === $oldId))]);
                }
                Db::name('target_scheme')->where('id', $schemeId)->update(['status' => 'active', 'version' => $expected + 1,
                    'updated_by' => $actor, 'updated_at' => $now]);
                $result = ['id' => $schemeId, 'version' => $expected + 1, 'status' => 'active', 'report_codes' => $reports];
                $this->audit($tenant, $schemeId, $actor, 'activated', ['replaced_bindings' => $conflicts], $result);
                return $result;
            });
    }

    /**
     * 作废保留方案及原始明细，只撤销该方案仍持有的逐行报表引用。
     * 版本、权限、幂等和审计同事务保护；报表无其他方案接替时目标为空。
     */
    public function void(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $expected = (int)($input['expected_version'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));
        if ($expected <= 0) throw new \InvalidArgumentException('请刷新目标方案后再作废');
        if ($reason === '' || mb_strlen($reason) > 200) throw new \InvalidArgumentException('请填写不超过200字的作废原因');
        return $this->command($tenant, $actor, $input,
            ['scheme_id' => $schemeId, 'version' => $expected, 'reason' => $reason],
            function () use ($tenant, $stores, $actor, $schemeId, $expected, $reason): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                if (!in_array($scheme['status'], ['draft', 'active'], true))
                    throw new \InvalidArgumentException('该目标方案已作废');
                $bindings = Db::name('target_report_line_binding')->where('tenant_id', $tenant)
                    ->where('scheme_id', $schemeId)->lock(true)->select()->toArray();
                // 被其他方案接替的目标不撤回，也不自动恢复曾被替换的旧目标。
                Db::name('target_report_line_binding')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->delete();
                $now = time();
                Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $schemeId)->update([
                    'status' => 'void', 'version' => $expected + 1, 'updated_by' => $actor, 'updated_at' => $now,
                ]);
                $result = ['id' => $schemeId, 'version' => $expected + 1, 'status' => 'void',
                    'reason' => $reason, 'actor_id' => $actor, 'occurred_at' => $now,
                    'removed_binding_count' => count($bindings)];
                $this->audit($tenant, $schemeId, $actor, 'voided',
                    ['status' => $scheme['status'], 'version' => $expected, 'bindings' => $bindings], $result);
                return $result;
            });
    }

    /** 删除是带审计的软删除：仅作废方案可移出日常入口，历史目标及作废记录不物理抹除。 */
    public function deleteVoided(array $context, int $schemeId, array $input): array
    {
        [$tenant, $stores, $actor] = $this->scope($context);
        $expected = (int)($input['expected_version'] ?? 0);
        if ($expected <= 0) throw new \InvalidArgumentException('请刷新目标方案后再删除');
        return $this->command($tenant, $actor, $input, ['action' => 'delete', 'scheme_id' => $schemeId, 'version' => $expected],
            function () use ($tenant, $stores, $actor, $schemeId, $expected): array {
                $scheme = $this->lockedScheme($tenant, $schemeId, $stores, $actor);
                if ((int)$scheme['version'] !== $expected) throw new \InvalidArgumentException('方案已更新，请刷新后重试');
                if ($scheme['status'] !== 'void') throw new \InvalidArgumentException('只有已作废方案可以删除');
                if (Db::name('target_report_line_binding')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->count())
                    throw new \InvalidArgumentException('方案仍被报表引用，不能删除');
                Db::name('target_scheme')->where('tenant_id', $tenant)->where('id', $schemeId)->update([
                    'status' => 'deleted', 'version' => $expected + 1, 'updated_by' => $actor, 'updated_at' => time()]);
                $result = ['id' => $schemeId, 'status' => 'deleted', 'version' => $expected + 1];
                $this->audit($tenant, $schemeId, $actor, 'deleted', $scheme, $result);
                return $result;
            });
    }

    /** 每个授权门店读取各自的报表引用，未绑定时返回空，绝不回退旧目标表。 */
    public function effective(array $context, string $reportCode, array $months = []): array
    {
        [$tenant, $stores] = $this->scope($context);
        if (!isset(self::REPORTS[$reportCode]) || !$stores) return ['scheme_id' => 0, 'scheme_ids' => [], 'lines' => []];
        $query = Db::name('target_report_line_binding')->alias('b')
            ->join('target_scheme_line l', 'l.id=b.line_id AND l.tenant_id=b.tenant_id AND l.scheme_id=b.scheme_id')
            ->join('target_scheme s', 's.id=b.scheme_id AND s.tenant_id=b.tenant_id')
            ->where('s.status', 'active')->where('b.tenant_id', $tenant)->where('b.report_code', $reportCode)->whereIn('b.store_id', $stores);
        if ($months) $query->whereIn('b.month_key', $months);
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
        if (!$scheme || $scheme['status'] === 'deleted') throw new \InvalidArgumentException('目标方案不存在');
        $existingStores = Db::name('target_scheme_line')->where('tenant_id', $tenant)->where('scheme_id', $schemeId)->distinct(true)->column('store_id');
        if (!$existingStores && (int)$scheme['created_by'] !== $actor) throw new \InvalidArgumentException('无权修改其他人的空方案');
        foreach ($existingStores as $id) if (!in_array((int)$id, $stores, true)) throw new \InvalidArgumentException('当前权限不覆盖方案的全部门店');
        return $scheme;
    }

    /** 低频目标管理命令统一锁序；先锁方案再查命令回执，防止空回执间隙锁和交叉替换相互等待。 */
    private function lockActivationScope(string $tenant): void
    {
        Db::name('target_scheme')->where('tenant_id', $tenant)->order('id')->lock(true)->column('id');
    }

    /** 数据库唯一键与预览/替换使用完全相同的目标身份。 */
    private function bindingKey(array $line): string
    {
        return implode(':', [$line['report_code'], $line['store_id'], $line['month_key'], $line['metric_code'], $line['scope_type'], $line['scope_id']]);
    }

    private function command(string $tenant, int $actor, array $input, array $payload, callable $write): array
    {
        if ($actor <= 0) throw new \InvalidArgumentException('无目标方案管理权限');
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 128) throw new \InvalidArgumentException('操作标识无效');
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Db::transaction(function () use ($tenant, $actor, $key, $hash, $write): array {
            $this->lockActivationScope($tenant);
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
