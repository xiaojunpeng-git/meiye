<?php
declare(strict_types=1);
namespace app\services\report;

/**
 * Read-only eligibility proof for technically archived imported originals.
 * Archive flags stay unchanged; ordinary deletion and successful lifecycle
 * reversal must never be revived merely because an old numeric ID exists.
 */
final class ReportLegacyArchiveProofServices
{
    /** SQL aliases/table names are internal identifiers; no user values enter this expression. */
    public static function visibilitySql(string $old,string $header,string $operation,string $receipt):string
    {
        foreach([$old,$header,$operation,$receipt]as$name)
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new \InvalidArgumentException('archive proof identifier invalid');
        return "({$old}.is_system_del=0 OR ({$old}.is_system_del=1 "
            ."AND {$header}.tenant_id='0' AND {$header}.contract_version='legacy-import-data-v1' "
            ."AND {$header}.source_document_type='legacy_store_order' "
            ."AND {$header}.source_document_id=CAST({$old}.id AS CHAR) "
            ."AND {$header}.order_no={$old}.order_id AND {$header}.store_id={$old}.store_id "
            ."AND {$header}.member_id={$old}.uid "
            ."AND {$header}.order_status='settled' AND {$header}.order_direction='forward' "
            ."AND NOT EXISTS (SELECT 1 FROM {$operation} ap WHERE ap.tenant_id={$header}.tenant_id "
            ."AND ap.store_id={$header}.store_id AND ap.source_order_id={$header}.order_id "
            ."AND ap.source_type='sales' AND ap.operation_type IN ('refund','void') AND ap.status='succeeded') "
            ."AND NOT EXISTS (SELECT 1 FROM {$receipt} ar JOIN {$operation} ac "
            ."ON ac.tenant_id=ar.tenant_id AND ac.store_id=ar.store_id "
            ."AND ac.source_order_id=CAST(ar.card_holder_id AS CHAR) "
            ."WHERE ar.tenant_id={$header}.tenant_id AND ar.store_id={$header}.store_id "
            ."AND ar.sales_order_id={$header}.order_id AND ar.status='completed' "
            ."AND ac.source_type='imported_card' AND ac.status='succeeded' "
            ."AND ac.operation_type IN ('refund','void'))))";
    }
}
