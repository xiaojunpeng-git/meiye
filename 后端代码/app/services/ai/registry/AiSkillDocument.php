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
        $keys = ['schema_version','skill_code','version','label','goal','domains','required_facts','ambiguities','completion','counterexamples','extension_rule'];
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
    private static function isList(array $values): bool { return array_keys($values) === range(0, count($values)-1); }
    private static function identifier($value): void { if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$value)) self::fail(); }
    private static function text($value, int $max): void { if (!is_string($value) || trim($value)==='' || mb_strlen($value,'UTF-8')>$max || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value)) self::fail(); }
    private static function fail(): void { throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID'); }
}
