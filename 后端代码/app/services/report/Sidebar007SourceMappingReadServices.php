<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 读取已执行的来源字典迁入审计键。007 原 ID 与 V3 快照 ID 只能按执行时
 * 冻结的一对一映射关联；旧字典同号和当前显示名称均不能作为历史来源证明。
 */
final class Sidebar007SourceMappingReadServices
{
    private $auditReader;
    private $dictionaryReader;

    /** 注入点只供离线合同测试；正式路径只读迁入审计和当前字典身份。 */
    public function __construct(?callable $auditReader = null, ?callable $dictionaryReader = null)
    {
        $this->auditReader = $auditReader ?: [$this, 'audit'];
        $this->dictionaryReader = $dictionaryReader ?: [$this, 'dictionary'];
    }

    /**
     * 无迁入审计时返回显式缺口，不猜测 ID。目标字典名称只用来验证既有
     * 映射未漂移，绝不根据同名自动寻找映射或改写历史订单快照。
     */
    public function read(): array
    {
        $audit = ($this->auditReader)();
        if ($audit === null) return ['complete'=>false, 'by_007_id'=>[], 'by_v3_id'=>[],
            'gap'=>'SOURCE_007_MAPPING_AUDIT_UNAVAILABLE'];
        if (!is_array($audit) || !isset($audit['mapping_json'], $audit['applied_at']))
            throw new \LogicException('客源迁入审计结构无效');
        $rows = json_decode((string)$audit['mapping_json'], true);
        if (!is_array($rows) || count($rows) !== 12 || !ctype_digit((string)$audit['applied_at'])
            || (int)$audit['applied_at'] <= 0) throw new \LogicException('客源迁入审计不完整');
        $byOld = []; $byNew = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \LogicException('客源映射行无效');
            $old = filter_var($row['source007Id'] ?? null, FILTER_VALIDATE_INT);
            $new = filter_var($row['target010Id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string)($row['name'] ?? ''));
            if ($old === false || $old < 1 || $old > 12 || $new === false || $new < 1
                || $name === '' || isset($byOld[$old]) || isset($byNew[$new]))
                throw new \LogicException('客源迁入审计身份重复或缺失');
            $byOld[$old] = ['source007_id'=>$old, 'v3_id'=>$new, 'name'=>$name];
            $byNew[$new] = $old;
        }
        if (count($byOld) !== 12) throw new \LogicException('客源迁入审计缺少旧 ID');
        $dictionary = ($this->dictionaryReader)(array_keys($byNew));
        if (!is_array($dictionary) || count($dictionary) !== 12)
            throw new \LogicException('客源目标字典与迁入审计不一致');
        $seen = [];
        foreach ($dictionary as $source) {
            $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !isset($byNew[$id]) || isset($seen[$id])
                || (string)($source['name'] ?? '') !== $byOld[$byNew[$id]]['name'])
                throw new \LogicException('客源目标字典身份或名称已漂移');
            $seen[$id] = true;
        }
        ksort($byOld, SORT_NUMERIC);
        return ['complete'=>true, 'by_007_id'=>$byOld, 'by_v3_id'=>$byNew,
            'applied_at'=>(int)$audit['applied_at'], 'source'=>'approved_import_audit'];
    }

    /** 一次迁入所写的专用审计表只提供映射证据；表不存在时报告缺口。 */
    private function audit(): ?array
    {
        $exists = Db::query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='codex_010_source007_audit'");
        if ((int)($exists[0]['n'] ?? 0) !== 1) return null;
        $rows = Db::query('SELECT mapping_json,applied_at FROM codex_010_source007_audit WHERE id=1 LIMIT 1');
        return $rows[0] ?? null;
    }

    /** 映射必须仍指向原迁入的字典身份，不能用现时同名条目替代。 */
    private function dictionary(array $ids): array
    {
        return Db::name('cashier_v3_business_source')->whereIn('id', $ids)
            ->field('id,name')->limit(13)->select()->toArray();
    }
}
