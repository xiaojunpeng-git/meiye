<?php
namespace app\services\ai\presentation;

use app\services\query\UnifiedQueryExportTaskServices;

/** Projects only customer-visible table columns from a verified answer.
 * The screen may preview 20 rows; this server-side projection keeps every
 * already-read row and never interprets the question or queries facts again.
 */
final class AiExportTableProjection
{
    public static function fromAnswer(array $answer): array
    {
        $tables=[];
        self::collect($answer,'',$tables);
        return self::validate($tables);
    }

    public static function validate($tables): array
    {
        if (!is_array($tables)||count($tables)>8) self::fail();
        $cells=0;
        foreach ($tables as $table) {
            if (!is_array($table)||!is_string($table['title']??null)
                ||!is_string($table['summary']??null)||mb_strlen($table['summary'],'UTF-8')>16000
                ||!is_array($table['columns']??null)||!is_array($table['rows']??null)
                ||!$table['columns']||count($table['columns'])>64) self::fail();
            $keys=[];
            foreach ($table['columns'] as $column) {
                if (!is_array($column)||!is_string($column['key']??null)||$column['key']===''
                    ||!is_string($column['label']??null)||$column['label']===''
                    ||isset($keys[$column['key']])) self::fail();
                $keys[$column['key']]=true;
            }
            foreach ($table['rows'] as $row) {
                if (!is_array($row)||array_keys($row)!==array_keys($keys)) self::fail();
                foreach ($row as $value) if (!is_string($value)) self::fail();
            }
            $cells+=count($table['columns'])*(count($table['rows'])+3);
        }
        // The presentation and hidden exact-value audit tab share the same
        // worker budget; reject oversized files before spreadsheet allocation.
        if ($cells>UnifiedQueryExportTaskServices::MAX_EXPORT_CELLS) self::fail();
        return $tables;
    }

    private static function collect(array $answer,string $title,array &$tables): void
    {
        if (isset($answer['table'])) {
            $table=$answer['table'];$columns=$table['columns']??null;$rows=$table['rows']??null;
            if (!is_array($columns)||!is_array($rows)) self::fail();
            $visible=[];
            foreach ($rows as $row) {
                if (!is_array($row)) self::fail();
                $values=[];
                foreach ($columns as $column) {
                    $key=$column['key']??null;
                    if (!is_string($key)||!array_key_exists($key,$row)) self::fail();
                    $value=$row[$key];
                    if ($value!==null&&!is_scalar($value)) self::fail();
                    $values[$key]=$value===null?'—':(string)$value;
                }
                $visible[]=$values;
            }
            $tables[]=['title'=>$title,'summary'=>(string)($answer['summary']??''),'columns'=>$columns,'rows'=>$visible];
        }
        foreach ($answer['sections']??[] as $section) {
            if (!is_array($section)||!is_array($section['answer']??null)
                ||!is_string($section['title']??null)) self::fail();
            self::collect($section['answer'],$section['title'],$tables);
        }
    }

    private static function fail(): void { throw new \RuntimeException('AI_EXPORT_PRESENTATION_INVALID'); }
}
