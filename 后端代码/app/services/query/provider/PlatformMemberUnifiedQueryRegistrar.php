<?php
namespace app\services\query\provider;
use app\services\query\UnifiedQueryPageRegistry;

/** 平台复用同一列契约，不复制会员金额/次数或导出算法。 */
class PlatformMemberUnifiedQueryRegistrar extends MemberUnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $this->registerPage($registry, PlatformMemberUnifiedQueryProvider::PAGE_CODE);
    }
}
