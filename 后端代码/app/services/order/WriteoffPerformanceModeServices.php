<?php

namespace app\services\order;

use app\services\BaseServices;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 总部统一核销业绩计算方式
 */
class WriteoffPerformanceModeServices extends BaseServices
{
    public const MODE_COMMISSION = 'commission';
    public const MODE_WRITEOFF_AMOUNT = 'writeoff_amount';

    public const CONFIG_KEY = 'writeoff_performance_mode';

    /**
     * 读取当前模式；非法/空值回退 commission。
     */
    public function getMode(): string
    {
        $raw = sys_config(self::CONFIG_KEY, self::MODE_COMMISSION);
        $mode = $this->normalizeMode($raw);
        return $mode !== '' ? $mode : self::MODE_COMMISSION;
    }

    /**
     * 后台读取：模式 + 可选项文案。
     */
    public function getModeInfo(): array
    {
        $mode = $this->getMode();
        return [
            'mode' => $mode,
            'options' => [
                ['value' => self::MODE_COMMISSION, 'label' => '按业绩提成计算'],
                ['value' => self::MODE_WRITEOFF_AMOUNT, 'label' => '按核销金额计算'],
            ],
            'scope' => 'global',
            'desc' => '总部统一设置，对所有门店生效；切换后只影响新产生的核销业绩，历史记录不变。',
        ];
    }

    /**
     * 总部设置模式；幂等键命中则返回原结果。
     *
     * @return array{mode:string,changed:bool,idempotent:bool}
     */
    public function setMode(string $mode, int $adminId, string $idempotencyKey): array
    {
        $mode = $this->normalizeMode($mode);
        if ($mode === '') {
            throw new ValidateException('核销业绩计算方式无效');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new ValidateException('缺少幂等键');
        }
        if ($adminId <= 0) {
            throw new ValidateException('仅总部可设置核销业绩计算方式');
        }

        $cacheKey = 'writeoff_perf_mode_idem:' . md5($idempotencyKey);
        $cached = CacheService::redisHandler()->get($cacheKey);
        if (is_array($cached) && isset($cached['mode'])) {
            return [
                'mode' => (string)$cached['mode'],
                'changed' => false,
                'idempotent' => true,
            ];
        }

        $before = $this->getMode();
        $changed = $before !== $mode;

        if ($changed) {
            $row = Db::name('system_config')
                ->where('menu_name', self::CONFIG_KEY)
                ->where('is_store', 0)
                ->find();
            if (!$row) {
                throw new ValidateException('核销业绩计算方式配置不存在，请先执行数据库升级');
            }
            Db::name('system_config')
                ->where('id', (int)$row['id'])
                ->update(['value' => json_encode($mode, JSON_UNESCAPED_UNICODE)]);
            SystemConfigService::clear();

            Db::name('system_log')->insert([
                'admin_id' => $adminId,
                'admin_name' => (string)(Db::name('system_admin')->where('id', $adminId)->value('real_name') ?: ''),
                'path' => 'system/writeoff-performance-mode',
                'page' => '核销业绩计算方式',
                'method' => 'PUT',
                'ip' => (string)(request()->ip() ?: ''),
                'type' => 'system',
                'add_time' => time(),
            ]);
        }

        $result = [
            'mode' => $mode,
            'changed' => $changed,
            'idempotent' => false,
        ];
        CacheService::redisHandler()->set($cacheKey, $result, 86400);
        return $result;
    }

    /**
     * 按总部口径覆盖手艺人 yeji：
     * - commission：原样返回
     * - writeoff_amount：按人数或已有比例整数分摊，合计精确等于 writeoffAmount
     */
    public function applyToStaffChoose(array $staffChoose, int $writeoffAmount, string $mode): array
    {
        $mode = $this->normalizeMode($mode) ?: self::MODE_COMMISSION;
        if ($mode !== self::MODE_WRITEOFF_AMOUNT) {
            return $staffChoose;
        }

        $writeoffAmount = WriteoffIntegerAmount::truncate($writeoffAmount);
        $n = count($staffChoose);
        if ($n <= 0) {
            return $staffChoose;
        }

        $weights = [];
        $weightSum = 0;
        foreach ($staffChoose as $idx => $staff) {
            $w = WriteoffIntegerAmount::truncate($staff['yeji'] ?? 0);
            if ($w <= 0) {
                $w = 1;
            }
            $weights[$idx] = $w;
            $weightSum += $w;
        }

        // 若前端已给出非零 yeji，按比例；否则等权（上面把 0 当成 1）
        $hasPositive = false;
        foreach ($staffChoose as $staff) {
            if (WriteoffIntegerAmount::truncate($staff['yeji'] ?? 0) > 0) {
                $hasPositive = true;
                break;
            }
        }
        if (!$hasPositive) {
            $weights = array_fill(0, $n, 1);
            $weightSum = $n;
        }

        $allocated = 0;
        $keys = array_keys($staffChoose);
        $lastKey = $keys[$n - 1];
        foreach ($keys as $key) {
            if ($key === $lastKey) {
                $staffChoose[$key]['yeji'] = $writeoffAmount - $allocated;
            } else {
                $part = $weightSum > 0
                    ? intdiv($writeoffAmount * $weights[$key], $weightSum)
                    : 0;
                $staffChoose[$key]['yeji'] = $part;
                $allocated += $part;
            }
        }

        return $staffChoose;
    }

    /**
     * 剥 JSON 引号并校验枚举。
     */
    public function normalizeMode($raw): string
    {
        if (is_array($raw)) {
            $raw = $raw[0] ?? '';
        }
        $mode = trim((string)$raw);
        $mode = trim($mode, "\"'");
        if ($mode === 'amount') {
            $mode = self::MODE_WRITEOFF_AMOUNT;
        }
        if ($mode === self::MODE_COMMISSION || $mode === self::MODE_WRITEOFF_AMOUNT) {
            return $mode;
        }
        return '';
    }
}
