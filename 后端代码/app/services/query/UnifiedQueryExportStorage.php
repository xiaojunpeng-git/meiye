<?php

namespace app\services\query;

/**
 * 统一查询导出对象的受控 runtime 路径解析。
 */
class UnifiedQueryExportStorage
{
    public function absolutePath(string $storageKey): string
    {
        if (!preg_match('#^unified-query-exports/[A-Za-z0-9/_-]{1,400}\.xlsx$#D', $storageKey)
            || strpos($storageKey, '..') !== false
            || strpos($storageKey, '//') !== false) {
            throw new \RuntimeException('导出对象键不合法');
        }
        $runtimeRoot = rtrim((string)app()->getRuntimePath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;
        $base = $runtimeRoot . 'unified-query-exports' . DIRECTORY_SEPARATOR;
        $relative = substr($storageKey, strlen('unified-query-exports/'));
        $path = $base . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir($base) || !is_dir(dirname($path))) {
            return $path;
        }
        $baseReal = realpath($base);
        $directoryReal = realpath(dirname($path));
        if ($baseReal === false || $directoryReal === false
            || strpos($directoryReal . DIRECTORY_SEPARATOR, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
            throw new \RuntimeException('导出文件路径越界');
        }
        return $path;
    }
}
