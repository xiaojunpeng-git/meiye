<?php
namespace app\services\ai\registry;

/**
 * Source-owned, human-readable runtime Skill documents.  Markdown explains the
 * business boundary; only the strictly validated JSON contract may participate
 * in registry construction.  A model never supplies, edits or executes it.
 */
final class AiSkillDocument
{
    public static function storeOperations(): array
    {
        return self::read('store_operations', 'skill_store_operations');
    }

    public static function catalog(): array
    {
        $skill = self::storeOperations();
        return [[
            'skill_code' => $skill['skill_code'], 'version' => $skill['version'],
            'label' => $skill['label'], 'source_path' => $skill['source_path'],
            'source_hash' => $skill['source_hash'], 'markdown' => $skill['markdown'],
        ]];
    }

    private static function read(string $directory, string $expectedCode): array
    {
        $path = dirname(__DIR__).'/skills/'.$directory.'/SKILL.md';
        $markdown = @file_get_contents($path);
        if (!is_string($markdown) || $markdown === '' || strlen($markdown) > 32768) self::fail();
        if (!preg_match('/^# .+\R\R[\s\S]*<!-- MOHE_SKILL_CONTRACT_BEGIN\R([\s\S]*?)\RMOHE_SKILL_CONTRACT_END -->/D', $markdown, $match)) self::fail();
        try { $contract = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { self::fail(); }
        $keys = ['schema_version','skill_code','version','label','goal','domains','required_facts','ambiguities','completion','counterexamples','semantic_projection','extension_rule'];
        if (!is_array($contract) || array_diff($keys, array_keys($contract)) || array_diff(array_keys($contract), $keys)
            || ($contract['schema_version'] ?? null) !== 'mohe-runtime-skill-v1' || ($contract['skill_code'] ?? null) !== $expectedCode
            || !is_int($contract['version'] ?? null) || $contract['version'] < 1) self::fail();
        foreach (['label'=>80,'goal'=>500,'completion'=>800,'extension_rule'=>800] as $key=>$max) self::text($contract[$key] ?? null, $max);
        foreach (['required_facts'=>16,'ambiguities'=>16] as $key=>$max) self::identifiers($contract[$key] ?? null, $max);
        self::texts($contract['counterexamples'] ?? null, 16, 240);
        if (!is_array($contract['domains']) || !self::isList($contract['domains']) || !$contract['domains'] || count($contract['domains']) > 16) self::fail();
        $seen=[];
        foreach ($contract['domains'] as $domain) {
            $domainKeys=['code','label','objects','questions'];
            if (!is_array($domain) || array_diff($domainKeys,array_keys($domain)) || array_diff(array_keys($domain),$domainKeys)) self::fail();
            self::identifier($domain['code'] ?? null); if (isset($seen[$domain['code']])) self::fail(); $seen[$domain['code']]=true;
            self::text($domain['label'] ?? null,80); self::texts($domain['objects'] ?? null,12,80); self::texts($domain['questions'] ?? null,12,160);
        }
        self::semanticProjection($contract['semantic_projection'] ?? null);
        $contract['source_path'] = 'app/services/ai/skills/'.$directory.'/SKILL.md';
        $contract['source_hash'] = hash('sha256', $markdown);
        $contract['markdown'] = $markdown;
        return $contract;
    }

    private static function identifiers($values, int $max): void
    {
        if (!is_array($values) || !self::isList($values) || !$values || count($values)>$max || count(array_unique($values))!==count($values)) self::fail();
        foreach ($values as $value) self::identifier($value);
    }
    private static function texts($values, int $max, int $length): void
    {
        if (!is_array($values) || !self::isList($values) || !$values || count($values)>$max) self::fail();
        foreach ($values as $value) self::text($value,$length);
    }
    /**
     * The semantic projection is a small, source-owned vocabulary only.  It is
     * deliberately not an object catalog, a metric declaration or permission
     * grant: all of those remain runtime server contracts.
     */
    private static function semanticProjection($projection): void
    {
        $keys=['objects','scenes','slots','preserve_words','gateway'];
        if (!is_array($projection) || array_diff($keys,array_keys($projection)) || array_diff(array_keys($projection),$keys)) self::fail();
        if (!is_array($projection['objects']) || !self::isList($projection['objects']) || !$projection['objects'] || count($projection['objects'])>16) self::fail();
        $objects=[];
        foreach ($projection['objects'] as $object) {
            $objectKeys=['code','label','aliases','model_kind','contract_label'];
            if (!is_array($object) || array_diff($objectKeys,array_keys($object)) || array_diff(array_keys($object),$objectKeys)) self::fail();
            self::identifier($object['code']??null);
            if (isset($objects[$object['code']])) self::fail(); $objects[$object['code']]=true;
            self::text($object['label']??null,80); self::texts($object['aliases']??null,16,80);
            if (!is_string($object['model_kind']??null) || !in_array($object['model_kind'],['store','person','member','product','project','category','partner','inventory'],true)) self::fail();
            self::text($object['contract_label']??null,160);
        }
        if (!is_array($projection['slots']) || !self::isList($projection['slots']) || !$projection['slots'] || count($projection['slots'])>12) self::fail();
        $slots=[];
        foreach ($projection['slots'] as $slot) {
            $slotKeys=['code','label','options'];
            if (!is_array($slot) || array_diff($slotKeys,array_keys($slot)) || array_diff(array_keys($slot),$slotKeys)) self::fail();
            self::identifier($slot['code']??null); if(isset($slots[$slot['code']]))self::fail();$slots[$slot['code']]=true;
            self::text($slot['label']??null,160);
            if(!is_array($slot['options'])||!self::isList($slot['options'])||!$slot['options']||count($slot['options'])>12)self::fail();
            $options=[];
            foreach($slot['options'] as $option) {
                if(!is_array($option)||array_diff(['code','label'],array_keys($option))||array_diff(array_keys($option),['code','label']))self::fail();
                self::identifier($option['code']??null); if(isset($options[$option['code']]))self::fail();$options[$option['code']]=true;
                self::text($option['label']??null,80);
            }
        }
        if (!is_array($projection['scenes']) || !self::isList($projection['scenes']) || !$projection['scenes'] || count($projection['scenes'])>16) self::fail();
        $scenes=[];
        foreach ($projection['scenes'] as $scene) {
            $sceneKeys=['code','object_codes','action_codes','slot_codes'];
            if(!is_array($scene)||array_diff($sceneKeys,array_keys($scene))||array_diff(array_keys($scene),$sceneKeys))self::fail();
            self::identifier($scene['code']??null); if(isset($scenes[$scene['code']]))self::fail();$scenes[$scene['code']]=true;
            self::identifiers($scene['object_codes']??null,8); self::identifiers($scene['action_codes']??null,8); self::identifiers($scene['slot_codes']??null,4);
            if(array_diff($scene['object_codes'],array_keys($objects))||array_diff($scene['slot_codes'],array_keys($slots)))self::fail();
        }
        self::texts($projection['preserve_words']??null,64,80);
        $gatewayKeys=['unknown_term_policy','missing_contract_policy','candidate_source'];
        if(!is_array($projection['gateway'])||array_diff($gatewayKeys,array_keys($projection['gateway']))||array_diff(array_keys($projection['gateway']),$gatewayKeys))self::fail();
        if($projection['gateway']['unknown_term_policy']!=='resolve_authorized_catalog_or_stop'||$projection['gateway']['missing_contract_policy']!=='guide_then_stop'||$projection['gateway']['candidate_source']!=='server_authorized_contract')self::fail();
    }
    private static function isList(array $values): bool { return array_keys($values) === range(0, count($values)-1); }
    private static function identifier($value): void { if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$value)) self::fail(); }
    private static function text($value, int $max): void { if (!is_string($value) || trim($value)==='' || mb_strlen($value,'UTF-8')>$max || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value)) self::fail(); }
    private static function fail(): void { throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID'); }
}
