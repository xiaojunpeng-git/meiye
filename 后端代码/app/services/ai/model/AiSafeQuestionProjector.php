<?php
namespace app\services\ai\model;

use app\services\ai\config\AiConfigStore;

/** Reconstruct a minimal question from public phrases, never forward an arbitrary raw
 * sentence on a regex claim that every person/name/number has been detected.
 * Unknown spans remain local and force local resolution, not deletion of conditions.
 */
final class AiSafeQuestionProjector
{
    public function project(string $question, array $configuration, array $privateLabels = [], array $semanticProjection = []): array
    {
        if (!AiConfigStore::allowsSanitizedQuestion($configuration)) throw new \RuntimeException('AI_EXTERNAL_SCOPE_REQUIRED');
        if ($question==='' || strlen($question)>8192 || preg_match('//u',$question)!==1) throw new \RuntimeException('AI_CONVERSATION_INVALID');
        // This is language grammar, not a scene/metric implementation. Unknown business
        // terms are resolved locally against a provider's objects and confirmed by the user.
        $phrases = ['今天','今日','昨天','昨日','本月','这个月','上个月','上月','当前权限范围',
            '现金业绩','消耗业绩','实际业绩','劳动业绩','销售业绩','销售数量','服务数量',
            '收了多少钱','收了多少','进账','收款','收入','销量','服务','业绩','数量',
            '做得最好','做的最好','最好的','最差的','最好','最差','最高','最低',
            '排名','排行','趋势','对比','比较','增长','下降','合计','总共','分别',
            '员工','人员','技师','美容师','手艺人','销售人','岗位','职位','门店','商品','课程','学习',
            '谁','哪些','哪个','多少','怎么样','如何','帮我','请问','我想知道','查询','看看','看一下',
            '排除','不包含','不含','只看','仅','全部','和','与','的','是','了','有','按','从','到'];
        // A published Skill can add public business vocabulary, but it cannot
        // add customer names, a metric grant or arbitrary text to the vendor
        // payload. Private labels still win below and are always masked first.
        $publicTerms=[];
        if ($semanticProjection!==[]) {
            if (!is_array($semanticProjection['objects']??null) || !is_array($semanticProjection['preserve_words']??null)) throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
            foreach ($semanticProjection['objects'] as $object) {
                if (!is_array($object) || !is_string($object['code']??null) || !is_string($object['label']??null) || !is_array($object['aliases']??null)) throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
                foreach (array_merge([$object['label']],$object['aliases']) as $word) {
                    if (!is_string($word) || trim($word)==='' || strlen($word)>80 || preg_match('//u',$word)!==1) throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
                    $phrases[]=$word;
                    // An ambiguous alias remains vocabulary but does not bind an
                    // object. It will be clarified by the server rather than
                    // silently selecting whichever declaration was loaded first.
                    if (!array_key_exists($word,$publicTerms)) $publicTerms[$word]=['kind'=>'object','code'=>$object['code'],'label'=>$object['label']];
                    elseif ($publicTerms[$word]['code']!==$object['code']) $publicTerms[$word]=null;
                }
            }
            foreach ($semanticProjection['preserve_words'] as $word) {
                if (!is_string($word) || trim($word)==='' || strlen($word)>80 || preg_match('//u',$word)!==1) throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
                $phrases[]=$word;
            }
        }
        $phrases=array_values(array_unique($phrases));
        usort($phrases,static function(string $a,string $b):int{return strlen($b)<=>strlen($a);});
        foreach ($privateLabels as $label) if (!is_string($label) || $label==='' || strlen($label)>512 || preg_match('//u',$label)!==1) throw new \RuntimeException('AI_PRIVATE_LABEL_INVALID');
        usort($privateLabels,static function(string $a,string $b):int{return strlen($b)<=>strlen($a);});
        $tokens=[]; $local=[]; $recognized=[]; $offset=0; $unknown='';
        $flush=static function() use(&$unknown,&$local,&$tokens):void {
            if ($unknown==='') return;
            $ref='local_condition_'.(count($local)+1); $local[$ref]=$unknown; $tokens[]='['.$ref.']'; $unknown='';
        };
        while ($offset<strlen($question)) {
            $rest=substr($question,$offset); $private=null;
            foreach ($privateLabels as $label) if (strncmp($rest,$label,strlen($label))===0) { $private=$label; break; }
            if ($private!==null) { $flush(); $unknown=$private; $flush(); $offset+=strlen($private); continue; }
            $matched=null;
            foreach ($phrases as $phrase) if (strncmp($rest,$phrase,strlen($phrase))===0) { $matched=$phrase; break; }
            if ($matched!==null) {
                $flush(); $tokens[]=$matched;
                if (isset($publicTerms[$matched]) && $publicTerms[$matched]!==null) $recognized[]=$publicTerms[$matched]+['text'=>$matched];
                $offset+=strlen($matched); continue;
            }
            preg_match('/^./us',$rest,$char); $value=$char[0];
            if (preg_match('/^[\s，。！？、,!?。]$/u',$value)) $flush(); else $unknown.=$value;
            $offset+=strlen($value);
        }
        $flush();
        return ['outbound'=>['schema_version'=>'sanitized-question-v1','question'=>implode(' ',$tokens),
            'has_unresolved_conditions'=>(bool)$local], 'local_conditions'=>$local, 'recognized_terms'=>$recognized];
    }
}
