<?php
namespace app\services\query\metric;

/**
 * Closed categorical source-filter contract shared by the AI compiler and
 * Reader. Only the registered first-course cohort may carry these IDs;
 * arbitrary member metrics must never inherit this filter silently.
 */
final class FirstCourseSourceFilter
{
    public static function valid(array $filters,array $metrics,array $set): bool
    {
        $keys=array_keys($filters);sort($keys,SORT_STRING);
        if ($keys!==['object_kind','source_primary_id'] && $keys!==['object_kind','source_primary_id','source_secondary_id']) return false;
        if (($filters['object_kind']??null)!=='member'
            ||!is_int($filters['source_primary_id']??null)||$filters['source_primary_id']<1
            ||!is_int($filters['source_secondary_id']??0)||($filters['source_secondary_id']??0)<0
            ||$metrics!==['member_first_course_count']) return false;
        return ($set['subject']??null)==='member' && ($set['relation']??null)==='all'
            && ($set['conditions']??null)===[['metric_code'=>'member_first_course_count','operator'=>'gt','value'=>0]];
    }
}
