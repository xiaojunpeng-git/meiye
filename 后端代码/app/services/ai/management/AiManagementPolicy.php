<?php
namespace app\services\ai\management;

use app\services\ai\registry\AiBusinessManifest;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiRegistryValue;

/** Editable presentation and bounded execution policy, never a source of metrics or handlers. */
final class AiManagementPolicy
{
    public static function defaults(): array
    {
        $m=AiBusinessManifest::definitions(); $scenes=[]; $workflows=[];
        foreach($m['scenes'] as $code=>$s) $scenes[$code]=['label'=>$s['label'],'goal'=>$s['goal'],'examples'=>[]];
        foreach($m['workflows'] as $code=>$w) $workflows[$code]=['enabled'=>true,'allow_export'=>$w['allow_export'],'max_path_ms'=>$w['max_path_ms'],'node_timeouts'=>array_column($w['nodes'],'timeout_ms','id')];
        return ['schema_version'=>'mohe-management-v1','source_registry_hash'=>AiRegistryValue::hash($m),
            'guidance'=>['max_rounds'=>3,'slot_order'=>['metric_code','start_date','compare_start','rank_direction','rank_limit'],
                'prompts'=>['metric_code'=>'您想了解哪一种业绩？','start_date'=>'您想查询哪个时间段？','compare_start'=>'您想和哪个时间段比较？','rank_direction'=>'您想看业绩较高还是较低的门店？','rank_limit'=>'当前支持查询五家，是否按五家查询？']],
            'scenes'=>$scenes,'workflows'=>$workflows];
    }
    public static function validate(array $d): array
    {
        $base=self::defaults(); $m=AiBusinessManifest::definitions();
        self::keys($d,array_keys($base));
        if($d['schema_version']!==$base['schema_version']||$d['source_registry_hash']!==$base['source_registry_hash']) self::fail('AI_MANAGEMENT_SOURCE_CHANGED');
        self::keys($d['guidance'],['max_rounds','slot_order','prompts']);
        if(!in_array($d['guidance']['max_rounds'],[3,4,5],true)) self::fail();
        $slots=$base['guidance']['slot_order']; $order=$d['guidance']['slot_order'];
        if(!is_array($order)||!AiRegistryValue::isList($order)||count($order)!==count($slots)||array_diff($order,$slots)||count(array_unique($order))!==count($slots)) self::fail();
        self::keys($d['guidance']['prompts'],$slots);
        foreach($d['guidance']['prompts'] as $text) self::text($text,240);
        self::keys($d['scenes'],array_keys($base['scenes']));
        foreach($d['scenes'] as $scene) {
            self::keys($scene,['label','goal','examples']);self::text($scene['label'],80);self::text($scene['goal'],500);
            if(!is_array($scene['examples'])||!AiRegistryValue::isList($scene['examples'])||count($scene['examples'])>12) self::fail();
            foreach($scene['examples'] as $text) self::text($text,240);
        }
        self::keys($d['workflows'],array_keys($base['workflows']));
        foreach($d['workflows'] as $code=>$w) {
            self::keys($w,['enabled','allow_export','max_path_ms','node_timeouts']);$original=$base['workflows'][$code];
            if(!is_bool($w['enabled'])||!is_bool($w['allow_export'])||($w['allow_export']&&!$original['allow_export'])) self::fail();
            self::keys($w['node_timeouts'],array_keys($original['node_timeouts']));
            foreach($w['node_timeouts'] as $id=>$timeout) if(!is_int($timeout)||$timeout<100||$timeout>$original['node_timeouts'][$id]) self::fail('AI_MANAGEMENT_BUDGET_INVALID');
            if(!is_int($w['max_path_ms'])||$w['max_path_ms']<array_sum($w['node_timeouts'])||$w['max_path_ms']>$original['max_path_ms']) self::fail('AI_MANAGEMENT_BUDGET_INVALID');
        }
        // Bound persisted document; labels are plain UI content and never promoted to model instructions.
        if(strlen(json_encode($d,JSON_UNESCAPED_UNICODE))>32768) self::fail();
        return $d;
    }
    public static function applyManifest(array $document): array
    {
        $d=self::validate($document); $m=AiBusinessManifest::definitions();
        foreach($d['scenes'] as $code=>$s) {$m['scenes'][$code]['label']=$s['label'];$m['scenes'][$code]['goal']=$s['goal'];}
        foreach($d['workflows'] as $code=>$w) {
            $m['workflows'][$code]['allow_export']=$w['allow_export'];$m['workflows'][$code]['max_path_ms']=$w['max_path_ms'];
            foreach($m['workflows'][$code]['nodes'] as &$node) $node['timeout_ms']=$w['node_timeouts'][$node['id']];unset($node);
        }
        // Existing registry performs final graph/schema checks; disabled gates remain outside source definitions.
        new AiBusinessRegistry($m);return $m;
    }
    public static function registry(array $document): AiBusinessRegistry { return new AiBusinessRegistry(self::applyManifest($document)); }
    public static function catalog(): array
    {
        $m=AiBusinessManifest::definitions();return ['source_registry_hash'=>AiRegistryValue::hash($m),'registry_version'=>$m['registry_version'],'scenes'=>$m['scenes'],'tools'=>$m['tools'],'actions'=>$m['actions'],'workflows'=>$m['workflows'],'export_node'=>$m['export_node'],'defaults'=>self::defaults(),
            'analysis_inventory'=>\app\services\query\metric\AnalysisCapabilityCatalogFactory::make()->inventory()];
    }
    /** Reorders only unresolved groups; date endpoints stay together. Does not interpret user text. */
    public static function decorateEnvelope(array $document,array $envelope): array
    {
        $d=self::validate($document);if(($envelope['kind']??null)!=='clarification') return $envelope;
        $all=array_merge($envelope['fields']??[],$envelope['pending_fields']??[]);$map=[];
        foreach($all as $field) $map[$field['key']]=$field;
        $ordered=[];
        foreach($d['guidance']['slot_order'] as $key) {
            if(isset($map[$key])) {$ordered[]=$map[$key];unset($map[$key]);}
            $end=['start_date'=>'end_date','compare_start'=>'compare_end'][$key]??null;
            if($end&&isset($map[$end])) {$ordered[]=$map[$end];unset($map[$end]);}
        }
        if($map||!$ordered) self::fail('AI_MANAGEMENT_GUIDANCE_INVALID');
        $first=array_shift($ordered);$fields=[$first];
        if(in_array($first['key'],['start_date','compare_start'],true)) $fields[]=array_shift($ordered);
        $envelope['fields']=$fields;$envelope['pending_fields']=$ordered;$envelope['guidance_step']=$first['key'];
        $envelope['question']=$d['guidance']['prompts'][$first['key']];return $envelope;
    }
    private static function keys($value,array $keys): void {if(!is_array($value)||array_diff($keys,array_keys($value))||array_diff(array_keys($value),$keys)) self::fail();}
    private static function text($s,int $max): void {if(!is_string($s)||trim($s)===''||mb_strlen($s,'UTF-8')>$max||preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$s)) self::fail();}
    private static function fail(string $reason='AI_MANAGEMENT_DOCUMENT_INVALID'): void {throw new \RuntimeException($reason);}
}
