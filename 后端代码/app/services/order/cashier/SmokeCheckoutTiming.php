<?php
declare(strict_types=1);

namespace app\services\order\cashier;

/**
 * 仅压测：X-Smoke-Profile:1 时记录分段耗时（毫秒），不改变业务逻辑。
 */
class SmokeCheckoutTiming
{
    /** @var bool */
    private static $enabled = false;

    /** @var array<string, float> */
    private static $marks = [];

    public static function bootFromRequest(): void
    {
        try {
            $req = request();
            self::$enabled = $req && (string)$req->header('X-Smoke-Profile', '') === '1';
        } catch (\Throwable $e) {
            self::$enabled = false;
        }
        self::$marks = [];
        if (self::$enabled) {
            self::$marks['_start'] = microtime(true);
        }
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function mark(string $name): void
    {
        if (!self::$enabled) {
            return;
        }
        self::$marks[$name] = microtime(true);
    }

    /**
     * @return array<string, float>
     */
    public static function exportMs(): array
    {
        if (!self::$enabled || !isset(self::$marks['_start'])) {
            return [];
        }
        $start = (float)self::$marks['_start'];
        $out = [];
        $prev = $start;
        foreach (self::$marks as $k => $t) {
            if ($k === '_start') {
                continue;
            }
            $out[$k . '_ms'] = round(($t - $prev) * 1000, 2);
            $prev = $t;
        }
        $out['total_marked_ms'] = round(($prev - $start) * 1000, 2);
        return $out;
    }
}
