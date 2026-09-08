<?php

namespace app\services\query\metric;

use app\services\query\UnifiedQueryJson;

/** Shared report query materialized views, never chat messages or an AI evidence cache.
 * Root and HMAC key must be supplied by trusted instance configuration.
 */
final class MetricReadViewStore
{
    private $root;
    private $key;
    private $clock;

    public function __construct(string $root, string $key, ?callable $clock = null)
    {
        if ($root === '' || $root[0] !== '/' || strlen($key) < 32 || strpos($root, "\0") !== false) $this->fail();
        if (is_link($root)) $this->fail();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) $this->fail();
        $real = realpath($root);
        if ($real === false || $real === '/' || !is_writable($real)) $this->fail();
        $this->root = $real;
        $this->key = $key;
        $this->clock = $clock ?: static function (): int { return time(); };
    }

    public function put(array $view): string
    {
        $this->checkLifetime($view);
        $ref = 'mrv_' . bin2hex(random_bytes(24));
        $view['read_consistency_ref'] = $ref;
        $body = UnifiedQueryJson::encode($view);
        if (strlen($body) > 1024 * 1024) $this->fail();
        $encoded = UnifiedQueryJson::encode(['body' => $body, 'signature' => hash_hmac('sha256', $body, $this->key)]);
        $path = $this->path($ref);
        $file = fopen($path, 'xb');
        if ($file === false) $this->fail();
        try {
            if (!chmod($path, 0600) || !flock($file, LOCK_EX)) $this->fail();
            if (fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) $this->fail();
        } catch (\Throwable $exception) {
            fclose($file);
            if (is_file($path) && !is_link($path)) unlink($path);
            throw $exception;
        }
        fclose($file);
        return $ref;
    }

    public function get(string $ref): array
    {
        $path = $this->path($ref);
        if (is_link($path) || !is_file($path) || filesize($path) > 2 * 1024 * 1024) $this->fail();
        $file = fopen($path, 'rb');
        if ($file === false) $this->fail();
        try {
            if (!flock($file, LOCK_SH)) $this->fail();
            $bytes = stream_get_contents($file, 2 * 1024 * 1024 + 1);
        } finally { fclose($file); }
        if (!is_string($bytes) || strlen($bytes) > 2 * 1024 * 1024) $this->fail();
        try { $envelope = UnifiedQueryJson::decode($bytes); } catch (\Throwable $e) { $this->fail(); }
        if (!is_string($envelope['body'] ?? null) || !is_string($envelope['signature'] ?? null)
            || !hash_equals(hash_hmac('sha256', $envelope['body'], $this->key), $envelope['signature'])) $this->fail();
        try { $view = UnifiedQueryJson::decode($envelope['body']); } catch (\Throwable $e) { $this->fail(); }
        if (($view['read_consistency_ref'] ?? null) !== $ref) $this->fail();
        $this->checkLifetime($view);
        return $view;
    }

    /** Scheduled instance cleanup; invalid artifacts are removed too, never used as evidence. */
    public function cleanup(): int
    {
        $removed = 0;
        foreach (new \DirectoryIterator($this->root) as $file) {
            if ($file->isDot() || $file->isLink() || !$file->isFile()) continue;
            $name = $file->getFilename();
            if (!preg_match('/^(mrv_[a-f0-9]{48})\.json$/D', $name, $match)) continue;
            try { $this->get($match[1]); } catch (MetricQueryContractException $e) {
                if (unlink($this->root . '/' . $name)) ++$removed;
            }
        }
        return $removed;
    }

    private function checkLifetime(array $view): void
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || !is_int($view['created_at'] ?? null) || !is_int($view['expires_at'] ?? null)
            || $view['created_at'] < 0 || $view['expires_at'] <= $view['created_at']
            || $view['expires_at'] - $view['created_at'] > 86400 || $now < $view['created_at'] || $now >= $view['expires_at']) $this->fail();
    }

    private function path(string $ref): string
    {
        if (!preg_match('/^mrv_[a-f0-9]{48}$/D', $ref)) $this->fail();
        return $this->root . '/' . $ref . '.json';
    }

    private function fail(): void { throw new MetricQueryContractException('METRIC_READ_VIEW_UNAVAILABLE', '原查询结果已失效或暂不可用，请重新提问。'); }
}
