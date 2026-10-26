<?php

namespace app\services\query;

/**
 * Worker 按权威任务页面重建当前账号、权限和数据范围。
 */
interface UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string;

    public function resolve(array $authoritativeTask): array;
}
