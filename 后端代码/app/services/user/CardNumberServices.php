<?php
declare(strict_types=1);

namespace app\services\user;

use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 平台唯一 7 位卡号：安全随机生成 + 唯一索引并发兜底。
 * 禁止 MAX()+1 / rand() / 时间戳 / 按门店单独取号。
 */
class CardNumberServices extends BaseServices
{
    public const MIN_NO = 1000000;
    public const MAX_NO = 9999999;
    public const MAX_RETRY = 20;
    public const CAPACITY = 9000000;
    public const PATTERN = '/^[1-9][0-9]{6}$/';

    /** @var bool|null */
    private static $schemaReady = null;

    /** @var int|null */
    private static $lastUsageWarnLevel = null;

    public function isValidCardNo(?string $cardNo): bool
    {
        $cardNo = trim((string)$cardNo);
        return $cardNo !== '' && (bool)preg_match(self::PATTERN, $cardNo);
    }

    /**
     * 字段缺失时阻断新购卡/导入/迁移新建（禁止业务代码现场 ALTER）。
     */
    public function assertSchemaReady(): void
    {
        if (self::$schemaReady === null) {
            self::$schemaReady = $this->hasCardNoColumn();
        }
        if (!self::$schemaReady) {
            Log::error('card_no schema missing: block new user_card_holder write');
            throw new ValidateException('卡号字段未升级，禁止新建持卡记录');
        }
    }

    public function hasCardNoColumn(): bool
    {
        try {
            return !empty(Db::query("SHOW COLUMNS FROM `eb_user_card_holder` LIKE 'card_no'"));
        } catch (\Throwable $e) {
            Log::error('card_no schema check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function clearSchemaCache(): void
    {
        self::$schemaReady = null;
        self::$lastUsageWarnLevel = null;
    }

    /**
     * 分配一个当前未占用的随机 7 位卡号（最终仍以唯一索引为准）。
     *
     * @param callable|null $existsChecker function(string $cardNo): bool
     */
    public function allocateRandom(string $source = 'purchase', ?callable $existsChecker = null): string
    {
        $this->assertSchemaReady();
        $this->maybeWarnCapacity();

        $checker = $existsChecker ?: function (string $cardNo): bool {
            return $this->exists($cardNo);
        };

        for ($i = 0; $i < self::MAX_RETRY; $i++) {
            $cardNo = (string)random_int(self::MIN_NO, self::MAX_NO);
            try {
                if (!$checker($cardNo)) {
                    return $cardNo;
                }
            } catch (\Throwable $e) {
                Log::warning('card_no exists check failed retry=' . $i . ' err=' . $e->getMessage());
            }
        }

        Log::error('card_no allocateRandom exhausted retries source=' . $source);
        throw new ValidateException('卡号分配失败，请稍后重试');
    }

    /**
     * 导入/迁移：合法且未占用则保留，否则随机生成。
     */
    public function resolvePreferredOrAllocate(?string $preferred, string $source = 'import', ?callable $existsChecker = null): string
    {
        $preferred = trim((string)$preferred);
        if ($this->isValidCardNo($preferred)) {
            $exists = $existsChecker
                ? (bool)$existsChecker($preferred)
                : $this->exists($preferred);
            if (!$exists) {
                return $preferred;
            }
        }
        return $this->allocateRandom($source, $existsChecker);
    }

    public function exists(string $cardNo): bool
    {
        return (int)Db::name('user_card_holder')->where('card_no', $cardNo)->count() > 0;
    }

    /**
     * 为待写入数组填充 card_no；若写入时撞唯一索引，调用方应换号重试。
     */
    public function fillCardNo(array &$data, ?string $preferred = null, string $source = 'purchase'): string
    {
        if (!empty($data['card_no']) && $this->isValidCardNo((string)$data['card_no'])) {
            return (string)$data['card_no'];
        }
        $cardNo = $this->resolvePreferredOrAllocate($preferred, $source);
        $data['card_no'] = $cardNo;
        return $cardNo;
    }

    /**
     * 带唯一冲突重试的写入回调。
     *
     * @param callable $writer function(string $cardNo): mixed  抛出重复键异常则重试
     */
    public function withAllocateRetry(callable $writer, ?string $preferred = null, string $source = 'purchase')
    {
        $this->assertSchemaReady();
        $cardNo = $this->resolvePreferredOrAllocate($preferred, $source);
        $last = null;
        for ($i = 0; $i < self::MAX_RETRY; $i++) {
            try {
                return $writer($cardNo);
            } catch (\Throwable $e) {
                $last = $e;
                if (!$this->isDuplicateCardNoError($e)) {
                    throw $e;
                }
                Log::warning('card_no unique conflict retry=' . ($i + 1) . ' source=' . $source . ' no=' . $cardNo);
                $cardNo = $this->allocateRandom($source);
            }
        }
        Log::error('card_no withAllocateRetry failed source=' . $source . ' err=' . ($last ? $last->getMessage() : ''));
        throw new ValidateException('卡号分配失败，请稍后重试');
    }

    public function isDuplicateCardNoError(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'uk_user_card_holder_card_no') !== false) {
            return true;
        }
        if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'card_no') !== false) {
            return true;
        }
        // PDO SQLSTATE 23000
        if (method_exists($e, 'getCode') && (string)$e->getCode() === '23000') {
            return stripos($msg, 'card_no') !== false || stripos($msg, 'uk_user_card_holder_card_no') !== false;
        }
        return false;
    }

    public function maybeWarnCapacity(): void
    {
        try {
            $used = (int)Db::name('user_card_holder')->whereNotNull('card_no')->where('card_no', '<>', '')->count();
            $ratio = $used / self::CAPACITY;
            $level = 0;
            if ($ratio >= 0.9) {
                $level = 90;
            } elseif ($ratio >= 0.8) {
                $level = 80;
            }
            if ($level > 0 && self::$lastUsageWarnLevel !== $level) {
                self::$lastUsageWarnLevel = $level;
                Log::warning(sprintf('card_no capacity warn level=%d used=%d capacity=%d', $level, $used, self::CAPACITY));
            }
            if ($used >= self::CAPACITY) {
                throw new ValidateException('卡号可用空间已耗尽，停止发卡');
            }
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 统计失败不阻断取号
        }
    }
}
