<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\report\BasicReportCatalogServices;
use app\services\report\BasicBusinessReportServices;

/** 领域列只登记一次；两端、列表、筛选与导出复用同一个白名单。 */
final class BasicBusinessQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        foreach (BasicReportCatalogServices::entries() as $report) {
            if ($report['code'] === BasicRegistrationQueryProvider::PAGE_CODE) continue;
            if ($report['code'] === \app\services\report\StoreReconciliationReportServices::CODE) continue;
            $fields = []; $keywords = [];
            foreach (BasicBusinessReportServices::fields($report['code']) as $field) {
                $fields[] = UnifiedQueryPageRegistry::field($field['key'], $field['label'], $field['type'],
                    $field['defaultVisible'], $field['defaultQuick']);
                if ($field['type'] === 'text' && $field['defaultVisible']) $keywords[] = $field['key'];
            }
            $registry->registerPage($report['code'], $report['name'], $fields, 'row_id',
                ['keywordFields' => $keywords, 'requiredFeature' => 'cashier.v3.data']);
        }
    }
}
