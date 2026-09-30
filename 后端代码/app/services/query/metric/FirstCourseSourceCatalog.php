<?php
namespace app\services\query\metric;

use think\facade\Db;

/**
 * Resolves a customer's source wording against the persisted two-level
 * directory. Historical inactive entries remain queryable; duplicate names
 * or prefixes never silently choose one ID.
 */
final class FirstCourseSourceCatalog
{
    public function resolve(string $term): array
    {
        $term=trim($term);
        if (preg_match('/^[a-z]$/D',$term)) $term=strtoupper($term);
        if ($term==='' || mb_strlen($term)>80) throw new \RuntimeException('AI_SOURCE_NOT_FOUND');
        $sources=Db::name('cashier_v3_business_source')->field('id,parent_id,name')->select()->toArray();
        $exact=[];$prefix=[];
        foreach ($sources as $source) {
            $id=(int)($source['id']??0);$name=trim((string)($source['name']??''));
            if ($id<1 || $name==='') continue;
            if ($name===$term) $exact[]=$source;
            if (preg_match('/^[A-Z]$/D',$term) && (int)($source['parent_id']??0)===0
                && preg_match('/^'.preg_quote($term,'/').'(?![A-Za-z])/u',$name)) $prefix[]=$source;
        }
        $matches=$exact!==[]?$exact:$prefix;
        if ($matches===[]) throw new \RuntimeException('AI_SOURCE_NOT_FOUND');
        if (count($matches)!==1) throw new \RuntimeException('AI_SOURCE_AMBIGUOUS');
        $match=$matches[0];$parent=(int)($match['parent_id']??0);
        if ($parent===0) return ['source_primary_id'=>(int)$match['id'],'source_secondary_id'=>0,'name'=>(string)$match['name']];
        foreach ($sources as $source) if ((int)($source['id']??0)===$parent
            && (int)($source['parent_id']??-1)===0) {
            return ['source_primary_id'=>$parent,'source_secondary_id'=>(int)$match['id'],'name'=>(string)$match['name']];
        }
        throw new \RuntimeException('AI_SOURCE_NOT_FOUND');
    }

    /** The Reader verifies the signed ID still names a directory entry. */
    public function label(int $primaryId,int $secondaryId=0): string
    {
        if ($primaryId<1 || $secondaryId<0) throw new \RuntimeException('AI_SOURCE_NOT_FOUND');
        $id=$secondaryId>0?$secondaryId:$primaryId;
        $row=Db::name('cashier_v3_business_source')->where('id',$id)->field('id,parent_id,name')->find();
        if (!is_array($row) || ($secondaryId>0 && (int)($row['parent_id']??0)!==$primaryId)
            || ($secondaryId===0 && (int)($row['parent_id']??-1)!==0)) throw new \RuntimeException('AI_SOURCE_NOT_FOUND');
        return trim((string)$row['name']);
    }
}
