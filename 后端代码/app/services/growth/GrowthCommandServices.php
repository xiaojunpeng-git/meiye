<?php
declare(strict_types=1);
namespace app\services\growth;

use think\facade\Db;
use mohe\exceptions\AdminException;

/** 成长命令的事务及幂等边界；回执是操作记录，不是表单版本或第二份业务快照。 */
final class GrowthCommandServices
{
    /** 同一操作者串行确认回执；业务回调与回执一起提交，失败不留下假成功。 */
    public function run(array $context, string $action, array $input, callable $operation): array
    {
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) throw new AdminException('请保留本次操作标识后重试');
        $actor = (string)$context['actor_key'];
        $hash = hash('sha256', self::json($input));
        // MySQL连接级短锁防止首次请求同时插入回执；不占用草稿编辑权。
        $lock = 'growth:' . substr(hash('sha256', $actor . ':' . $action . ':' . $key), 0, 48);
        $got = Db::query('SELECT GET_LOCK(?, 10) AS acquired', [$lock]);
        if ((int)($got[0]['acquired'] ?? 0) !== 1) throw new AdminException('操作正在确认，请稍后使用原标识重试');
        try {
            return Db::transaction(function () use ($context, $action, $input, $operation, $key, $actor, $hash): array {
                $row = Db::name('growth_receipt')->where('actor_key', $actor)->where('action', $action)->where('request_key', $key)->find();
                if ($row) {
                    if (!hash_equals((string)$row['request_hash'], $hash)) throw new AdminException('操作标识已用于不同内容');
                    $this->assertReplayAccess($context,$action,$input);
                    return self::decode((string)$row['response_json']);
                }
                $result = $operation();
                Db::name('growth_receipt')->insert(['actor_key'=>$actor,'action'=>$action,'request_key'=>$key,'request_hash'=>$hash,'response_json'=>self::json($result),'created_at'=>time()]);
                return $result;
            });
        } finally { Db::query('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    /** 回执只解决重复写入，不能延续已经被撤回的任务处理权或历史数据范围。 */
    private function assertReplayAccess(array $c,string $action,array $in): void
    {
        $taskId=(int)($in['task_id']??0);$handler=false;
        if(in_array($action,['practice.feedback','exam.save','exam.submit','exam.grade','exam.correct','exam.mistakes','question.feedback'],true)){$a=Db::name('growth_attempt')->where('id',(int)($in['attempt_id']??0))->find();if(!$a)throw new AdminException('原答卷已不可访问');$taskId=(int)$a['task_id'];$handler=in_array($action,['exam.grade','exam.correct'],true);}
        if($action==='work.review'){$r=Db::name('growth_submission')->where('id',(int)($in['submission_id']??0))->find();if(!$r)throw new AdminException('原提交已不可访问');$taskId=(int)$r['task_id'];$handler=true;}
        if(in_array($action,['appraisal.confirm','appraisal.object'],true)){$taskId=(int)Db::name('growth_task')->where('source_type','appraisal')->where('source_id',(int)($in['id']??0))->value('id');$handler=$action==='appraisal.confirm';}
        if($action==='activity.confirm')$handler=true;
        if($taskId){$task=app()->make(GrowthTaskServices::class)->task($c,$taskId);if($handler&&(int)$task['handler_employee_id']!==$c['employee_id'])throw new AdminException('原任务已交接，不能沿用旧处理回执读取结果');}
        if(strpos($action,'content.')===0&&!empty($in['id']))app()->make(GrowthContentServices::class)->owned($c,(int)$in['id']);
    }

    /** 正式事件与来源事务一起写入；通知失败时仍保有可恢复的权威事件。 */
    public function event(array $context, string $event, string $source, int $id, array $payload = []): void
    {
        $now = time();
        Db::name('growth_event')->insert(['event_type'=>$event,'source_type'=>$source,'source_id'=>$id,
            'employee_id'=>(int)($payload['employee_id'] ?? $context['employee_id'] ?? 0),
            'organization_id'=>(int)($payload['organization_id'] ?? $context['organization_id'] ?? 0),
            'store_id'=>(int)($payload['store_id'] ?? $context['store_id'] ?? 0),'actor_key'=>$context['actor_key'],
            'business_date'=>date('Y-m-d',$now),'occurred_at'=>$now,'settled_at'=>$now,'recorded_at'=>$now,'payload_json'=>self::json($payload)]);
    }

    /** 评分与次数先验证再转换，不能把小数或布尔开关截断成另一个有效业务值。 */
    public static function integer($value,int $min,int $max,string $label): int
    {
        if(is_bool($value)||(!is_int($value)&&!is_string($value))||filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<$min||(int)$value>$max)throw new AdminException($label.'须为'.$min.'至'.$max.'的整数');return (int)$value;
    }

    /** JSON只存扩展和冻结内容；人员、状态及时间等查询维度在各领域表中独立保存。 */
    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    /** 扩展内容解码为结构化数组，空值不能被当作业务成功事实。 */
    public static function decode(string $value): array { $data=json_decode($value,true); return is_array($data)?$data:[]; }
}
