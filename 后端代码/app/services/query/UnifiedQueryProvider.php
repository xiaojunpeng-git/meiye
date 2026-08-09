<?php

namespace app\services\query;

/**
 * 领域查询提供器合同。实现负责先注入数据权限，再生成统一查询投影。
 */
interface UnifiedQueryProvider
{
    public function pageCode(): string;

    public function query(array $context, array $payload): array;

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array;
}
