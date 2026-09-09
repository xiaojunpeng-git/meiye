<?php
namespace app\services\query\metric;

/** Local, permission-filtered object resolver. Objects never become model vocabulary.
 * Providers supply authoritative objects; labels/aliases are not permission grants.
 */
final class AnalysisObjectCatalog
{
    private $objects = [];

    public function __construct(array $objects, callable $authorize)
    {
        if (count($objects) > 10000) throw new \RuntimeException('ANALYSIS_OBJECT_CATALOG_TOO_LARGE');
        foreach ($objects as $object) {
            $fields = ['ref','kind','label','aliases','version','relations'];
            if (!is_array($object) || array_diff(array_keys($object), $fields) || array_diff($fields, array_keys($object))) $this->invalid();
            foreach (['ref','kind','version'] as $field) {
                if (!is_string($object[$field]) || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $object[$field])) $this->invalid();
            }
            foreach (['aliases','relations'] as $field) if (!is_array($object[$field]) || ($object[$field] && array_keys($object[$field]) !== range(0,count($object[$field])-1))) $this->invalid();
            foreach (array_merge([$object['label']],$object['aliases'],$object['relations']) as $text) {
                if (!is_string($text) || trim($text) === '' || strlen($text)>512 || preg_match('//u',$text)!==1 || preg_match('/[\x00-\x1f\x7f]/',$text)) $this->invalid();
            }
            // Authorization precedes candidates, names, aliases, counts and fingerprints.
            if ($authorize($object) !== true) continue;
            if (isset($this->objects[$object['ref']])) $this->invalid();
            $this->objects[$object['ref']] = $object;
        }
        ksort($this->objects);
    }

    /** Exact names can bind only when unique. No fuzzy or model-selected silent binding. */
    public function resolve(string $term, string $kind, ?string $relation = null): array
    {
        $eligible = array_values(array_filter($this->objects, static function(array $object) use($kind,$relation): bool {
            return $object['kind']===$kind && ($relation===null || in_array($relation,$object['relations'],true));
        }));
        $exact = array_values(array_filter($eligible, static function(array $object) use($term): bool {
            return in_array(trim($term), array_merge([$object['label']],$object['aliases']),true);
        }));
        if (count($exact)===1) return ['status'=>'resolved','objects'=>$exact,'catalog_ref'=>$this->fingerprint()];
        // Suggestions are explicitly choices, never an inferred replacement of the term.
        $candidates = $exact ?: $eligible;
        if (count($candidates)>200) return ['status'=>'narrow_required','objects'=>[],'catalog_ref'=>$this->fingerprint()];
        return ['status'=>$candidates?'choose':'unavailable','objects'=>$candidates,'catalog_ref'=>$this->fingerprint()];
    }

    public function fingerprint(): string
    { return hash('sha256',json_encode($this->objects,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }

    private function invalid(): void { throw new \RuntimeException('ANALYSIS_OBJECT_CATALOG_INVALID'); }
}
