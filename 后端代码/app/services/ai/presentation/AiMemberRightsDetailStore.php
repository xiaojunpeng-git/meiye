<?php
declare(strict_types=1);

namespace app\services\ai\presentation;

/** Private immutable rights-detail spool; the public answer keeps only 20 rows. */
final class AiMemberRightsDetailStore
{
    private const MAX_AGE_SECONDS=86400;
    private $root;

    public function __construct(?string $root=null)
    {
        $this->root=$root??rtrim((string)app()->getRuntimePath(),DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'ai-member-rights-details';
        if ($this->root==='' || is_link($this->root)
            || (!is_dir($this->root)&&!mkdir($this->root,0700,true))
            || !is_writable($this->root)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
    }

    /** The producer rechecks member visibility and cashier totals in batches before publication. */
    public function put(iterable $records,int $expectedCount): array
    {
        if ($expectedCount<1) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INVALID');
        $ref='mrd_'.bin2hex(random_bytes(24));$path=$this->path($ref);
        $file=fopen($path,'xb');
        if ($file===false) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
        $hash=hash_init('sha256');$count=0;$last=0;
        try {
            if (!chmod($path,0600)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
            foreach ($records as $record) {
                $id=$record['detail']['member']['memberId']??null;
                if (!is_int($id)||$id<=$last||($record['selection_ref']??null)!=='member:'.$id
                    ||!is_string($record['label']??null)
                    ||!is_int($record['selected_amount_cents']??null)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INVALID');
                (new AiMemberDetailAnswerRenderer())->render($record['detail'],$record['label'],'summary');
                $last=$id;$line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file,$line)!==strlen($line)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
                hash_update($hash,$line);++$count;
            }
            if ($count!==$expectedCount||!fflush($file)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INCOMPLETE');
        } catch (\Throwable $error) {
            fclose($file);unlink($path);throw $error;
        }
        fclose($file);
        return ['ref'=>$ref,'count'=>$count,'sha256'=>hash_final($hash)];
    }

    /** Check the signed-view hash before yielding any potentially sensitive row. */
    public function rows(array $snapshot): \Generator
    {
        $ref=$snapshot['ref']??null;$count=$snapshot['count']??null;$hash=$snapshot['sha256']??null;
        if (!is_string($ref)||!is_int($count)||$count<1||!is_string($hash)
            ||!preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INVALID');
        $path=$this->path($ref);
        if (is_link($path)||!is_file($path)||time()-filemtime($path)>self::MAX_AGE_SECONDS
            ||!hash_equals($hash,(string)hash_file('sha256',$path))) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
        $file=fopen($path,'rb');
        if ($file===false) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_UNAVAILABLE');
        $seen=0;$last=0;
        try {
            while (($line=fgets($file))!==false) {
                $record=json_decode($line,true,24,JSON_THROW_ON_ERROR);
                $id=$record['detail']['member']['memberId']??null;
                if (!is_int($id)||$id<=$last||($record['selection_ref']??null)!=='member:'.$id
                    ||!is_string($record['label']??null)||!is_int($record['selected_amount_cents']??null)) {
                    throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INVALID');
                }
                $last=$id;++$seen;yield $record;
            }
            if ($seen!==$count) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INCOMPLETE');
        } finally { fclose($file); }
    }

    /** Expired asset snapshots must not linger after their signed answer expires. */
    public function cleanupExpired(int $limit=200): int
    {
        $removed=0;$now=time();
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($removed>=$limit) break;
            $name=$entry->getFilename();
            if (!preg_match('/^mrd_[a-f0-9]{48}\.jsonl$/D',$name)
                ||!$entry->isFile()||$entry->isLink()
                ||$now-$entry->getMTime()<=self::MAX_AGE_SECONDS) continue;
            if (unlink($entry->getPathname())) ++$removed;
        }
        return $removed;
    }

    private function path(string $ref): string
    {
        if (!preg_match('/^mrd_[a-f0-9]{48}$/D',$ref)) throw new \RuntimeException('AI_RIGHTS_SNAPSHOT_INVALID');
        return $this->root.DIRECTORY_SEPARATOR.$ref.'.jsonl';
    }
}
