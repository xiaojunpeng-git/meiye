<?php
namespace app\services\ai\registry;

/**
 * Published Skill prose is the model's business guidance. It contains no
 * phrase catalogue or executable capability declaration. Query capabilities
 * remain in the metric registry and are supplied after permission narrowing.
 */
final class AiSkillDocument
{
    public static function storeOperations(): array
    {
        return self::read('store_operations', 'skill_store_operations', 16, '门店运营');
    }

    public static function intentUnderstanding(): array
    {
        return self::read('intent_understanding', 'skill_intent_understanding', 4, '用户意图理解');
    }

    public static function catalog(): array
    {
        $items=[];
        foreach ([self::intentUnderstanding(),self::storeOperations()] as $skill) $items[]=[
            'skill_code'=>$skill['skill_code'],'version'=>$skill['version'],'label'=>$skill['label'],
            'source_path'=>$skill['source_path'],'source_hash'=>$skill['source_hash'],'markdown'=>$skill['markdown'],
        ];
        return $items;
    }

    private static function read(string $directory,string $code,int $version,string $label): array
    {
        $path=dirname(__DIR__).'/skills/'.$directory.'/SKILL.md';
        $markdown=@file_get_contents($path);
        if (!is_string($markdown)||$markdown===''||strlen($markdown)>32768
            ||!preg_match('/^#\s+.+\R\R[\s\S]+/D',$markdown)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$markdown)) {
            throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
        }
        return ['skill_code'=>$code,'version'=>$version,'label'=>$label,
            'source_path'=>'app/services/ai/skills/'.$directory.'/SKILL.md',
            'source_hash'=>hash('sha256',$markdown),'markdown'=>$markdown];
    }
}
