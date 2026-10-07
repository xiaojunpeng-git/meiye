<?php
declare(strict_types=1);

namespace app\services\query\metric;

/**
 * Private, immutable member-set spool for a verified current-rights query.
 * The signed read view carries its random reference, count and SHA-256; names
 * and IDs never enter a browser-supplied filter or a second query definition.
 */
final class AiMemberRightsPopulationStore
{
    private const MAX_AGE_SECONDS=86400;
    private $root;

    public function __construct(?string $root=null)
    {
        $this->root=$root??rtrim((string)app()->getRuntimePath(),DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'ai-member-populations';
        if ($this->root==='' || is_link($this->root)
            || (!is_dir($this->root)&&!mkdir($this->root,0700,true))
            || !is_writable($this->root)) throw new \RuntimeException('AI_MEMBER_SET_STORAGE_UNAVAILABLE');
    }

    /** Freeze all rows in stable member-ID order; any missing or repeated row rejects the whole set. */
    public function put(iterable $rows,int $expectedCount): array
    {
        if ($expectedCount<1) throw new \RuntimeException('AI_MEMBER_SET_INVALID');
        $ref='mrp_'.bin2hex(random_bytes(24));$path=$this->path($ref);
        $file=fopen($path,'xb');
        if ($file===false) throw new \RuntimeException('AI_MEMBER_SET_STORAGE_UNAVAILABLE');
        $hash=hash_init('sha256');$count=0;$last=0;
        try {
            if (!chmod($path,0600)) throw new \RuntimeException('AI_MEMBER_SET_STORAGE_UNAVAILABLE');
            foreach ($rows as $row) {
                if (!is_array($row)||!is_int($row['member_id']??null)||$row['member_id']<=$last
                    ||!is_string($row['member_name']??null)||trim($row['member_name'])===''
                    ||!is_int($row['amount_cents']??null)||$row['amount_cents']<0) {
                    throw new \RuntimeException('AI_MEMBER_SET_INVALID');
                }
                $last=$row['member_id'];
                $line=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file,$line)!==strlen($line)) throw new \RuntimeException('AI_MEMBER_SET_STORAGE_UNAVAILABLE');
                hash_update($hash,$line);++$count;
            }
            if ($count!==$expectedCount || !fflush($file)) throw new \RuntimeException('AI_MEMBER_SET_INCOMPLETE');
        } catch (\Throwable $error) {
            fclose($file);unlink($path);throw $error;
        }
        fclose($file);
        return ['ref'=>$ref,'count'=>$count,'sha256'=>hash_final($hash)];
    }

    /** Verify the full file before yielding a single identity to a detail/export worker. */
    public function rows(array $snapshot): \Generator
    {
        $ref=$snapshot['ref']??null;$count=$snapshot['count']??null;$hash=$snapshot['sha256']??null;
        if (!is_string($ref)||!is_int($count)||$count<1||!is_string($hash)
            ||!preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new \RuntimeException('AI_MEMBER_SET_INVALID');
        $path=$this->path($ref);
        if (is_link($path)||!is_file($path)||time()-filemtime($path)>self::MAX_AGE_SECONDS
            ||!hash_equals($hash,(string)hash_file('sha256',$path))) throw new \RuntimeException('AI_MEMBER_SET_UNAVAILABLE');
        $file=fopen($path,'rb');
        if ($file===false) throw new \RuntimeException('AI_MEMBER_SET_UNAVAILABLE');
        $seen=0;$last=0;
        try {
            while (($line=fgets($file))!==false) {
                $row=json_decode($line,true,8,JSON_THROW_ON_ERROR);
                if (!is_array($row)||!is_int($row['member_id']??null)||$row['member_id']<=$last
                    ||!is_string($row['member_name']??null)||trim($row['member_name'])===''
                    ||!is_int($row['amount_cents']??null)||$row['amount_cents']<0) throw new \RuntimeException('AI_MEMBER_SET_INVALID');
                $last=$row['member_id'];++$seen;
                yield $row;
            }
            if ($seen!==$count) throw new \RuntimeException('AI_MEMBER_SET_INCOMPLETE');
        } finally { fclose($file); }
    }

    /** Supervisor deletes only this store's expired private files, never a live population. */
    public function cleanupExpired(int $limit=200): int
    {
        $removed=0;$now=time();
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($removed>=$limit) break;
            $name=$entry->getFilename();
            if (!preg_match('/^mrp_[a-f0-9]{48}\.jsonl$/D',$name)
                ||!$entry->isFile()||$entry->isLink()
                ||$now-$entry->getMTime()<=self::MAX_AGE_SECONDS) continue;
            if (unlink($entry->getPathname())) ++$removed;
        }
        return $removed;
    }

    private function path(string $ref): string
    {
        if (!preg_match('/^mrp_[a-f0-9]{48}$/D',$ref)) throw new \RuntimeException('AI_MEMBER_SET_INVALID');
        return $this->root.DIRECTORY_SEPARATOR.$ref.'.jsonl';
    }
}
