<?php

namespace app\services\query\metric;

/**
 * 指标业务语言只从统一指标注册表与统一指标字典投影。
 *
 * 这里不维护第二份 AI 指标清单，也不保存 SQL、公式或报表字段。字典中的同名词
 * 如果同时指向多个已注册指标，会被标记为歧义而不会静默绑定其中任意一个。
 */
final class MetricSemanticCatalog
{
    /** @return array<string,array<string,mixed>> */
    public static function entries(): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $capabilities = MetricDefinitionRegistry::capabilities();
        $language=[];
        // Dictionary aliases such as a store-level label and its canonical
        // personnel metric are one registered semantic owner.  Fold every
        // user-ready alias definition into that canonical owner so natural
        // terms become available through registration metadata rather than a
        // question-specific AI branch.
        foreach ($dictionary->getDefinitions() as $definition) {
            if (!is_array($definition) || ($definition['user_ready']??false)!==true
                || !is_string($definition['code']??null)) continue;
            $canonical=MetricDefinitionRegistry::canonical($definition['code']);
            foreach (array_merge([(string)($definition['name']??'')],(array)($definition['aliases']??[])) as $term) {
                if (is_string($term) && trim($term)!=='') $language[$canonical][trim($term)]=true;
            }
        }
        $entries = [];
        foreach ($capabilities as $code => $capability) {
            $definition = $dictionary->getByCode($code);
            if (!is_array($definition) || ($definition['user_ready'] ?? false) !== true) {
                continue;
            }
            $terms = array_merge([(string)$definition['name']], (array)($definition['aliases'] ?? []),array_keys($language[$code]??[]));
            $terms = array_values(array_unique(array_filter(array_map('trim', $terms), static function ($term) {
                return $term !== '';
            })));
            usort($terms, static function ($left, $right) {
                return strlen($right) <=> strlen($left);
            });
            $entries[$code] = [
                'metric_code' => $code,
                'name' => (string)$definition['name'],
                'summary' => (string)($definition['summary'] ?? ''),
                'terms' => $terms,
                'ai_query_ready' => ($capability['ai_query_ready'] ?? false) === true,
                'storage_unit' => $capability['storage_unit'] ?? null,
            ];
        }
        return $entries;
    }

    /** @return array<string,string> metric code => safe generated regex */
    public static function unambiguousPatterns(): array
    {
        $owners = [];
        foreach (self::entries() as $code => $entry) {
            foreach ($entry['terms'] as $term) {
                $owners[$term][] = $code;
            }
        }
        $patterns = [];
        foreach (self::entries() as $code => $entry) {
            $terms = array_values(array_filter($entry['terms'], static function ($term) use ($owners) {
                return count(array_unique($owners[$term])) === 1;
            }));
            if ($terms) {
                $patterns[$code] = '/'.implode('|', array_map(static function ($term) {
                    return preg_quote($term, '/');
                }, $terms)).'/u';
            }
        }
        return $patterns;
    }

    /** @return array<string,string> */
    public static function names(array $codes = []): array
    {
        $entries = self::entries();
        $wanted = $codes ?: array_keys($entries);
        $names = [];
        foreach ($wanted as $code) {
            if (isset($entries[$code])) {
                $names[$code] = $entries[$code]['name'];
            }
        }
        return $names;
    }

    public static function optionLabel(string $code): string
    {
        $entry = self::entries()[$code] ?? null;
        if (!$entry) {
            throw new MetricQueryContractException('METRIC_NOT_REGISTERED', '当前指标尚未注册。');
        }
        return $entry['summary'] !== '' ? $entry['name'].'（'.$entry['summary'].'）' : $entry['name'];
    }

    /**
     * Resolve exact customer metric terms only when the active capability
     * boundary gives every term one and the same registered owner.
     *
     * This is deliberately not fuzzy language parsing: the understanding
     * model has already quoted the customer's measurement words and the
     * registry remains the only authority for their executable meaning.
     * Unknown or ambiguous terms return null and keep the normal controlled
     * clarification path.
     */
    public static function uniqueCodeForTerms(array $terms, array $allowedCodes = []): ?string
    {
        if ($terms === [] || count($terms) > 8) return null;
        $entries = self::entries();
        $allowed = $allowedCodes === []
            ? array_fill_keys(array_keys($entries), true)
            : array_fill_keys(array_values(array_unique(array_filter($allowedCodes, 'is_string'))), true);
        $resolved = null;
        foreach ($terms as $term) {
            if (!is_string($term) || trim($term) === '') return null;
            $term = trim($term);
            $owners = [];
            foreach ($entries as $code => $entry) {
                if (!isset($allowed[$code]) || ($entry['ai_query_ready'] ?? false) !== true) continue;
                if (in_array($term, (array)($entry['terms'] ?? []), true)) $owners[] = $code;
            }
            $owners = array_values(array_unique($owners));
            if (count($owners) !== 1) return null;
            if ($resolved !== null && $resolved !== $owners[0]) return null;
            $resolved = $owners[0];
        }
        return $resolved;
    }

    /**
     * Find one exact registered measurement phrase inside customer text.
     * Several aliases owned by the same metric are harmless; any cross-metric
     * match is ambiguous and therefore returns null.
     *
     * @return array{metric_code:string,term:string}|null
     */
    public static function uniqueTermInText(string $text, array $allowedCodes = []): ?array
    {
        if ($text === '' || preg_match('//u', $text) !== 1) return null;
        $entries = self::entries();
        $allowed = $allowedCodes === []
            ? array_fill_keys(array_keys($entries), true)
            : array_fill_keys(array_values(array_unique(array_filter($allowedCodes, 'is_string'))), true);
        $matches = [];
        $longest = 0;
        foreach ($entries as $code => $entry) {
            if (!isset($allowed[$code]) || ($entry['ai_query_ready'] ?? false) !== true) continue;
            foreach ((array)($entry['terms'] ?? []) as $term) {
                if (!is_string($term) || $term === '' || mb_strpos($text, $term, 0, 'UTF-8') === false) continue;
                $length = mb_strlen($term, 'UTF-8');
                if ($length > $longest) {
                    $matches = [];
                    $longest = $length;
                }
                if ($length === $longest) $matches[$code][$term] = true;
            }
        }
        if (count($matches) !== 1) return null;
        $code = array_key_first($matches);
        $terms = array_keys($matches[$code]);
        usort($terms, static function (string $left, string $right): int {
            return mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');
        });
        return ['metric_code' => $code, 'term' => $terms[0]];
    }

    /**
     * Return every unambiguous registered measurement explicitly present in
     * the text. This is language accountability only: callers still bind and
     * admit executable codes through the active capability contract. A term
     * owned by more than one metric is omitted, and one metric contributes
     * only its longest stated term.
     *
     * @return array<int,array{metric_code:string,term:string,ai_query_ready:bool}>
     */
    public static function registeredTermsInText(string $text, array $allowedCodes = []): array
    {
        if ($text==='' || preg_match('//u',$text)!==1) return [];
        $entries=self::entries();
        $allowed=$allowedCodes===[]?array_fill_keys(array_keys($entries),true)
            :array_fill_keys(array_values(array_unique(array_filter($allowedCodes,'is_string'))),true);
        $owners=[];
        foreach ($entries as $code=>$entry) {
            if (!isset($allowed[$code])) continue;
            foreach ((array)($entry['terms']??[]) as $term) if (is_string($term) && $term!==''
                && mb_strpos($text,$term,0,'UTF-8')!==false) $owners[$term][$code]=true;
        }
        $byCode=[];
        foreach ($owners as $term=>$codes) {
            if (count($codes)!==1) continue;
            $code=array_key_first($codes);
            if (!isset($byCode[$code]) || mb_strlen($term,'UTF-8')>mb_strlen($byCode[$code],'UTF-8')) $byCode[$code]=$term;
        }
        $out=[];
        foreach ($byCode as $code=>$term) $out[]=['metric_code'=>$code,'term'=>$term,
            'ai_query_ready'=>($entries[$code]['ai_query_ready']??false)===true];
        usort($out,static function(array $left,array $right)use($text):int {
            return mb_strpos($text,$left['term'],0,'UTF-8')<=>mb_strpos($text,$right['term'],0,'UTF-8');
        });
        return $out;
    }

    /**
     * Return ordered exact registry terms after choosing the longest
     * non-overlapping spans. This prevents a complete measurement such as a
     * compound registered title from also emitting a shorter metric whose
     * label appears only inside that title. Separate occurrences remain
     * independent; unknown and ambiguously owned terms are still omitted.
     *
     * @return array<int,array{metric_code:string,term:string,ai_query_ready:bool}>
     */
    public static function registeredNonOverlappingTermsInText(string $text,array $allowedCodes=[]): array
    {
        if ($text===''||preg_match('//u',$text)!==1) return [];
        $entries=self::entries();$allowed=$allowedCodes===[]?array_fill_keys(array_keys($entries),true)
            :array_fill_keys(array_values(array_unique(array_filter($allowedCodes,'is_string'))),true);
        $owners=[];
        foreach ($entries as $code=>$entry) {
            if (!isset($allowed[$code])||($entry['ai_query_ready']??false)!==true) continue;
            foreach ((array)($entry['terms']??[]) as $term) if (is_string($term)&&$term!=='') $owners[$term][$code]=true;
        }
        $candidates=[];
        foreach ($owners as $term=>$codes) {
            if (count($codes)!==1) continue;
            $code=array_key_first($codes);$offset=0;$length=mb_strlen($term,'UTF-8');
            while (($start=mb_strpos($text,$term,$offset,'UTF-8'))!==false) {
                $candidates[]=['metric_code'=>$code,'term'=>$term,'start'=>$start,'end'=>$start+$length,
                    'ai_query_ready'=>true];
                $offset=$start+1;
            }
        }
        usort($candidates,static function(array $left,array $right):int {
            $lengthOrder=($right['end']-$right['start'])<=>($left['end']-$left['start']);
            return $lengthOrder!==0?$lengthOrder:$left['start']<=>$right['start'];
        });
        $selected=[];$seen=[];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['metric_code']])) continue;
            $overlap=false;
            foreach ($selected as $kept) if ($candidate['start']<$kept['end']&&$candidate['end']>$kept['start']) {
                $overlap=true;break;
            }
            if ($overlap) continue;
            $seen[$candidate['metric_code']]=true;$selected[]=$candidate;
        }
        usort($selected,static function(array $left,array $right):int{return $left['start']<=>$right['start'];});
        return array_map(static function(array $item):array {
            return ['metric_code'=>$item['metric_code'],'term'=>$item['term'],'ai_query_ready'=>$item['ai_query_ready']];
        },$selected);
    }

    /**
     * Resolve a shorter audit term to an already selected longer registry
     * owner only when every occurrence of that short term is contained by
     * the same longer owner in the current message. An independently stated
     * occurrence therefore cannot be swallowed as a duplicate condition.
     */
    public static function containingSelectedOwnerCode(string $term,string $text,array $allowedCodes,array $selectedCodes): ?string
    {
        if ($term===''||$text===''||preg_match('//u',$term)!==1||preg_match('//u',$text)!==1) return null;
        $selected=array_values(array_filter(self::registeredNonOverlappingTermsInText($text,$allowedCodes),
            static function(array $item)use($selectedCodes):bool {
                return is_string($item['metric_code']??null)&&in_array($item['metric_code'],$selectedCodes,true)
                    &&is_string($item['term']??null)&&$item['term']!=='';
            }));
        if ($selected===[]) return null;
        $owners=[];$found=false;$offset=0;$termLength=mb_strlen($term,'UTF-8');
        while (($start=mb_strpos($text,$term,$offset,'UTF-8'))!==false) {
            $found=true;$end=$start+$termLength;$contained=[];
            foreach ($selected as $owner) {
                $ownerOffset=0;$ownerLength=mb_strlen($owner['term'],'UTF-8');
                while (($ownerStart=mb_strpos($text,$owner['term'],$ownerOffset,'UTF-8'))!==false) {
                    if ($start>=$ownerStart&&$end<=$ownerStart+$ownerLength) $contained[$owner['metric_code']]=true;
                    $ownerOffset=$ownerStart+1;
                }
            }
            if (count($contained)!==1) return null;
            $owners[array_key_first($contained)]=true;$offset=$start+1;
        }
        return $found&&count($owners)===1?array_key_first($owners):null;
    }

    /** Remove only registered dictionary terms before checking residual conditions. */
    public static function stripTerms(string $text, array $codes = []): string
    {
        $entries=self::entries();$wanted=$codes?:array_keys($entries);$terms=[];
        foreach($wanted as $code) foreach(($entries[$code]['terms']??[]) as $term) $terms[]=$term;
        $terms=array_values(array_unique($terms));usort($terms,static function($left,$right){return strlen($right)<=>strlen($left);});
        if(!$terms) return $text;
        return (string)preg_replace('/'.implode('|',array_map(static function($term){return preg_quote($term,'/');},$terms)).'/u',' ',$text);
    }
}
