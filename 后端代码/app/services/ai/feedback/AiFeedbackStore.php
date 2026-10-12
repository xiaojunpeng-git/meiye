<?php
declare(strict_types=1);

namespace app\services\ai\feedback;

use PDO;
use RuntimeException;

/** One current evaluation per instance and Run; saved answers are snapshots, never query authority. */
final class AiFeedbackStore
{
    private PDO $db;
    private string $table;
    private string $instance;

    public function __construct(PDO $db, string $prefix, string $instance)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$instance)) {
            throw new RuntimeException('AI_FEEDBACK_CONFIGURATION_INVALID');
        }
        $this->db=$db;
        $this->table=$prefix.'mohe_ai_feedback';
        $this->instance=$instance;
    }

    /** The gateway has already checked the signed owner, question and published answer. */
    public function save(array $owner,array $source,string $rating,string $question,array $answer): array
    {
        if (!in_array($rating,['useful','not_useful'],true) || $question==='' || strlen($question)>16000) throw new RuntimeException('AI_FEEDBACK_INVALID');
        $answerJson=json_encode($answer,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($answerJson) || strlen($answerJson)>1048576) throw new RuntimeException('AI_FEEDBACK_INVALID');
        $summary=(string)($answer['summary']??'');
        if ($summary==='' && isset($answer['sections']) && is_array($answer['sections'])) {
            $summary=implode("\n",array_map(static fn($part)=>(string)($part['answer']['summary']??''),$answer['sections']));
        }
        $now=(int)floor(microtime(true)*1000);
        $values=[$this->instance,$source['run_id'],(int)$source['generation'],(int)$owner['account_id'],(string)$owner['terminal'],
            $rating,$question,$answerJson,$summary,(string)$source['status'],(string)$source['reason'],(string)$source['model_version'],(int)$source['created_at'],$now,$now];
        // MySQL's unique key serializes simultaneous clicks. A retry or changed vote
        // updates the same snapshot instead of creating a second sample.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
            $sql='INSERT INTO '.$this->table.' (instance_id,run_id,generation,account_id,terminal,rating,question,answer_json,answer_summary,run_status,run_reason,model_version,run_created_at,created_at,updated_at) VALUES ('.implode(',',array_fill(0,15,'?')).') '
                .'ON DUPLICATE KEY UPDATE rating=VALUES(rating),updated_at=VALUES(updated_at)';
        } else {
            $sql='INSERT INTO '.$this->table.' (instance_id,run_id,generation,account_id,terminal,rating,question,answer_json,answer_summary,run_status,run_reason,model_version,run_created_at,created_at,updated_at) VALUES ('.implode(',',array_fill(0,15,'?')).') '
                .'ON CONFLICT(instance_id,run_id) DO UPDATE SET rating=excluded.rating,updated_at=excluded.updated_at';
        }
        $this->db->prepare($sql)->execute($values);
        return ['rating'=>$rating,'updated_at'=>$now];
    }

    /** Platform management list is a server-paged collection, scoped to this instance. */
    public function listing(array $filters): array
    {
        // Reject malformed filters before casting them into SQL predicates or
        // page offsets; a broken client must not turn into a PHP warning.
        $rawPage=$filters['page']??1;
        if ((!is_int($rawPage) && (!is_string($rawPage) || !ctype_digit($rawPage)))
            || (int)$rawPage<1 || (int)$rawPage>100000) throw new RuntimeException('AI_FEEDBACK_INVALID');
        $page=(int)$rawPage;
        $where=['instance_id=?'];$bindings=[$this->instance];
        $rating=$filters['rating']??'';
        if (!is_string($rating)) throw new RuntimeException('AI_FEEDBACK_INVALID');
        if ($rating!=='') {
            if (!in_array($rating,['useful','not_useful'],true)) throw new RuntimeException('AI_FEEDBACK_INVALID');
            $where[]='rating=?';$bindings[]=$rating;
        }
        $keyword=$filters['keyword']??'';
        if (!is_string($keyword)) throw new RuntimeException('AI_FEEDBACK_INVALID');
        $keyword=trim($keyword);
        if ($keyword!=='') {
            if (mb_strlen($keyword,'UTF-8')>100) throw new RuntimeException('AI_FEEDBACK_INVALID');
            $where[]='question LIKE ?';$bindings[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$keyword).'%';
        }
        $predicate=implode(' AND ',$where);
        $count=$this->db->prepare('SELECT COUNT(*) FROM '.$this->table.' WHERE '.$predicate);
        $count->execute($bindings);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/20));$page=min($page,$pages);
        $query=$this->db->prepare('SELECT id,run_id,generation,account_id,terminal,rating,question,answer_summary,run_status,run_reason,model_version,run_created_at,created_at,updated_at FROM '.$this->table.' WHERE '.$predicate.' ORDER BY updated_at DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20));
        $query->execute($bindings);
        return ['rows'=>$query->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'page_size'=>20,'pages'=>$pages];
    }

    public function detail(int $id): array
    {
        if ($id<1) throw new RuntimeException('AI_FEEDBACK_INVALID');
        $query=$this->db->prepare('SELECT * FROM '.$this->table.' WHERE instance_id=? AND id=?');
        $query->execute([$this->instance,$id]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('AI_FEEDBACK_NOT_FOUND');
        $row['answer']=json_decode((string)$row['answer_json'],true);
        unset($row['answer_json']);
        return $row;
    }
}
