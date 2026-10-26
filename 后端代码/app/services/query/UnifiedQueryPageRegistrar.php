<?php

namespace app\services\query;

/**
 * 领域页面只在统一查询 registry 冻结前登记字段与访问合同。
 */
interface UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void;
}
