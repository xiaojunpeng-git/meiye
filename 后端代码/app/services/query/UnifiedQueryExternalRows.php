<?php
namespace app\services\query;

/**
 * 请求/任务私有的临时行集：可重放、外部归并排序，生命周期结束自动删除。
 * 只保存已由领域服务裁剪权限的查询投影；绝不作为业务事实或共享缓存。
 * 排序缓冲与归并文件数固定，深页/导出不能随命中数增长 PHP 行数组。
 */
final class UnifiedQueryExternalRows implements \IteratorAggregate, \Countable
{
    private const BUFFER_ROWS = 256;
    private const BUFFER_BYTES = 4194304;
    private const FAN_IN = 16;
    private const MAX_BYTES = 2147483648;
    private $directory;
    private $path;
    private $writer;
    private $count = 0;
    private $bytes = 0;
    private $sealed = false;

    public function __construct()
    {
        $this->directory = rtrim(sys_get_temp_dir(), '/') . '/mohe-query-' . bin2hex(random_bytes(16));
        if (!mkdir($this->directory, 0700)) throw new \RuntimeException('UNIFIED_QUERY_TEMP_CREATE_FAILED');
        $this->path = $this->directory . '/rows';
        $this->writer = $this->openWriter($this->path);
    }

    public function append(array $row): void
    {
        if ($this->sealed) throw new \LogicException('UNIFIED_QUERY_ROWS_SEALED');
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $this->bytes += strlen($encoded);
        if ($this->bytes > self::MAX_BYTES) throw new \RuntimeException('UNIFIED_QUERY_TEMP_DISK_BUDGET_EXCEEDED');
        self::write($this->writer, $encoded);
        ++$this->count;
    }

    public function count(): int { return $this->count; }

    public function getIterator(): \Traversable
    {
        $this->seal();
        yield from self::read($this->path);
    }

    /** 使用统一字段比较器；每层最多同时打开16个文件，不为深页保存整个前缀。 */
    public function sort(callable $compare, ?callable $checkpoint = null): void
    {
        $this->seal();
        $runs = []; $buffer = []; $bytes = 0;
        foreach ($this as $row) {
            $buffer[] = $row; $bytes += strlen(json_encode($row));
            if (count($buffer) >= self::BUFFER_ROWS || $bytes >= self::BUFFER_BYTES) {
                $runs[] = $this->run($buffer, $compare); $buffer = []; $bytes = 0;
                if ($checkpoint) $checkpoint();
            }
        }
        if ($buffer) $runs[] = $this->run($buffer, $compare);
        if (!$runs) return;
        while (count($runs) > 1) {
            $next = [];
            foreach (array_chunk($runs, self::FAN_IN) as $batch) {
                $path = $this->newPath(); $out = $this->openWriter($path);
                $heap = new class($compare) extends \SplHeap {
                    private $comparator;
                    public function __construct(callable $compare) { $this->comparator = $compare; }
                    protected function compare($a, $b): int {
                        return -call_user_func($this->comparator, $a['row'], $b['row']) ?: ($b['source'] <=> $a['source']);
                    }
                };
                $iterators = [];
                try {
                    foreach ($batch as $index => $file) {
                        $iterator = self::read($file); $iterator->rewind(); $iterators[$index] = $iterator;
                        if ($iterator->valid()) $heap->insert(['row'=>$iterator->current(),'source'=>$index]);
                    }
                    $written = 0;
                    while (!$heap->isEmpty()) {
                        $item = $heap->extract();
                        self::write($out, json_encode($item['row'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                        $iterator = $iterators[$item['source']]; $iterator->next();
                        if ($iterator->valid()) $heap->insert(['row'=>$iterator->current(),'source'=>$item['source']]);
                        if (++$written % 256 === 0 && $checkpoint) $checkpoint();
                    }
                } finally { fclose($out); unset($iterator, $iterators, $heap); }
                foreach ($batch as $file) unlink($file);
                $next[] = $path;
            }
            $runs = $next;
        }
        unlink($this->path); $this->path = $runs[0];
    }

    private function run(array $rows, callable $compare): string
    {
        usort($rows, $compare); $path = $this->newPath(); $out = $this->openWriter($path);
        try { foreach ($rows as $row) self::write($out, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n"); }
        finally { fclose($out); }
        return $path;
    }

    private function newPath(): string { return $this->directory . '/run-' . bin2hex(random_bytes(12)); }
    private function openWriter(string $path)
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) throw new \RuntimeException('UNIFIED_QUERY_TEMP_OPEN_FAILED');
        chmod($path, 0600); return $stream;
    }
    private function seal(): void
    {
        if (!$this->sealed) { fflush($this->writer); fclose($this->writer); $this->writer = null; $this->sealed = true; }
    }
    private static function write($stream, string $value): void
    {
        $offset = 0; $length = strlen($value);
        while ($offset < $length) {
            $n = fwrite($stream, substr($value, $offset));
            if ($n === false || $n === 0) throw new \RuntimeException('UNIFIED_QUERY_TEMP_WRITE_FAILED');
            $offset += $n;
        }
    }
    private static function read(string $path): \Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new \RuntimeException('UNIFIED_QUERY_TEMP_READ_FAILED');
        try {
            while (($line = fgets($stream)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                if (!is_array($row)) throw new \RuntimeException('UNIFIED_QUERY_TEMP_ROW_INVALID');
                yield $row;
            }
            if (!feof($stream)) throw new \RuntimeException('UNIFIED_QUERY_TEMP_READ_FAILED');
        } finally { fclose($stream); }
    }
    public function __destruct()
    {
        if (is_resource($this->writer)) fclose($this->writer);
        foreach (glob($this->directory . '/*') ?: [] as $path) @unlink($path);
        if ($this->directory) @rmdir($this->directory);
    }
}
