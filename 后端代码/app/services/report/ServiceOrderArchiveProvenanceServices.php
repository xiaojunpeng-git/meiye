<?php
declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/**
 * A technically archived original service suborder is eligible only while its
 * complete current row equals a separately certified sealed migration result.
 * This never clears deletion flags, infers provenance from an ID prefix, or
 * authorizes the purchase order's evidence to stand in for its service child.
 */
final class ServiceOrderArchiveProvenanceServices
{
    public const PURPOSE='legacy_service_order_archive_v1';

    /** Match the historical executor's PDO string-valued, sorted complete-row hash. */
    public static function fingerprint(array $row):string
    {
        foreach($row as &$value){
            if($value!==null&&!is_scalar($value))throw new \InvalidArgumentException('ARCHIVE_PROOF_ROW_VALUE');
            if($value!==null)$value=(string)$value;
        }
        unset($value);ksort($row);
        return hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
            |JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }

    /**
     * Only caller-scoped original rows enter this lookup; the returned ID set
     * cannot expand store scope. Missing proof storage keeps archive exclusion.
     * Source batch/archive/plan hashes are written only by the audited executor.
     */
    public function certified(array $orders,?callable $proofReader=null):array
    {
        $candidates=[];
        foreach($orders as $order){
            $id=(int)($order['id']??0);
            if($id<1||isset($candidates[$id]))throw new \LogicException('ARCHIVE_PROOF_ORDER_ID');
            if((int)($order['order_type']??-1)!==2||(int)($order['is_system_del']??0)!==1)continue;
            $candidates[$id]=$order;
        }
        if(!$candidates)return [];
        $proofReader=$proofReader ?: [$this,'proofs'];
        $rows=$proofReader(array_map(static fn(int $id):string=>'legacy-order:'.$id,array_keys($candidates)));
        $approved=[];
        foreach($rows as $proof){
            $raw=(string)($proof['fact_id']??'');
            if(!preg_match('/^legacy-order:([1-9][0-9]*)$/D',$raw,$m))continue;
            $id=(int)$m[1];if(!isset($candidates[$id]))throw new \LogicException('ARCHIVE_PROOF_SCOPE');
            if(($proof['tenant_id']??null)!=='0'||($proof['purpose']??'')!==self::PURPOSE
                ||trim((string)($proof['source_batch_key']??''))===''
                ||!preg_match('/^[a-f0-9]{64}$/D',(string)($proof['source_archive_sha256']??''))
                ||!preg_match('/^[a-f0-9]{64}$/D',(string)($proof['plan_sha256']??'')))continue;
            if(hash_equals(self::fingerprint($candidates[$id]),(string)($proof['snapshot_sha256']??'')))$approved[$id]=true;
        }
        return $approved;
    }

    /** Exact tenant, purpose and original IDs only; no current employee or domain-specific branch. */
    private function proofs(array $ids):array
    {
        $table=Db::name('cashier_v3_import_fact_provenance')->getTable();
        $exists=Db::query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if((int)($exists[0]['n']??0)!==1)return [];
        $out=[];
        foreach(array_chunk($ids,500) as $chunk){
            $rows=Db::name('cashier_v3_import_fact_provenance')->where('tenant_id','0')
                ->where('purpose',self::PURPOSE)->whereIn('fact_id',$chunk)->select()->toArray();
            $out=array_merge($out,$rows);
        }
        return $out;
    }
}
