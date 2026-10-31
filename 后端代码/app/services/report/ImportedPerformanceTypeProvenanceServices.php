<?php
declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/**
 * Historical imports may freeze a valid employee type without a live personnel
 * version. Only separately certified, unchanged import snapshots are accepted.
 * This reader never infers a historical type from current staff or an ID prefix.
 */
final class ImportedPerformanceTypeProvenanceServices
{
    public const PURPOSE='employee_type_snapshot_v1';
    public const FIELDS=['tenant_id','fact_id','natural_key','store_id','employee_id',
        'employee_type_snapshot','employee_type_authority_version','performance_type',
        'fact_direction','status','amount_cents','order_id','source_line_id',
        'source_document_type','checkout_request_id','business_date','reversal_of',
        'rule_code_snapshot','rule_version_snapshot','command_idempotency_key',
        'business_event_no','immutable_fingerprint'];

    /** Length-prefixed UTF-8 values distinguish null, empty and delimiter-bearing IDs. */
    public static function fingerprint(array $row):string
    {
        $bytes='';
        foreach(self::FIELDS as$field){
            if(!array_key_exists($field,$row))throw new \InvalidArgumentException('IMPORT_PROOF_FIELD_MISSING:'.$field);
            $v=$row[$field];if($v!==null&&!is_scalar($v))throw new \InvalidArgumentException('IMPORT_PROOF_FIELD_INVALID');
            $bytes.=$field.':'.($v===null?'-1:':strlen((string)$v).':'.(string)$v).';';
        }
        return hash('sha256',$bytes);
    }

    /** SQL expression is generated from fixed field names and a validated alias only. */
    public static function fingerprintSql(string $alias):string
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$alias))throw new \InvalidArgumentException('IMPORT_PROOF_ALIAS');
        $parts=[];
        foreach(self::FIELDS as$field){
            $col=$alias.'.`'.$field.'`';$value='CAST('.$col.' AS CHAR CHARACTER SET utf8mb4)';
            $parts[]="'".$field.":',IF(".$col." IS NULL,'-1:',CONCAT(LENGTH(".$value."),':',".$value.")),';'";
        }
        return 'SHA2(CONCAT('.implode(',',$parts).'),256)';
    }

    /**
     * No proof table means normal strict version validation, including during
     * rolling deployment. Each proof binds tenant+fact and the whole snapshot.
     */
    public static function unverifiedSql(string $alias):string
    {
        self::fingerprintSql($alias);
        $prefix=(string)config('database.connections.mysql.prefix');
        if(!preg_match('/^[A-Za-z0-9_]*$/D',$prefix))throw new \LogicException('IMPORT_PROOF_PREFIX');
        $table=$prefix.'cashier_v3_import_fact_provenance';
        $exists=Db::query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        return self::unverifiedPredicate($alias,(int)($exists[0]['n']??0)===1?$table:null);
    }

    /** Pure predicate used by the real reader and isolated SQL contract fixtures. */
    private static function unverifiedPredicate(string $alias,?string $table):string
    {
        $fingerprint=self::fingerprintSql($alias);
        if($table!==null&&!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new \InvalidArgumentException('IMPORT_PROOF_TABLE');
        $proof=$table!==null
            ?"EXISTS (SELECT 1 FROM `".$table."` ip WHERE ip.tenant_id=".$alias.".tenant_id AND ip.fact_id=".$alias.".fact_id AND ip.purpose='".self::PURPOSE."' AND ip.snapshot_sha256=".$fingerprint.")"
            :'0=1';
        return "(".$alias.".employee_type_snapshot NOT IN ('internal','partner','outsourced') OR (".$alias.".employee_type_authority_version=0 AND NOT (".$proof.")))";
    }
}
