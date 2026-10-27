<?php
declare(strict_types=1);
namespace app\services\report;

use app\services\report\query\BasicRegistrationQueryProvider;
use think\facade\Db;

/** 工资人工补充和按月编辑冻结。只写补充表，不落薪、不发薪、不修改经营事实。 */
final class BasicSalaryManualServices
{
    public const FIELDS = [
        'haoqingshu'=>'好评数','canbu'=>'餐补','jiaban'=>'加班','jiangweibutie'=>'岗位补贴',
        'qitatiaozheng'=>'其他调整','kaoqin'=>'考勤','yishebaokoukuan'=>'医社保','gongjijin'=>'公积金',
        'geshui'=>'个税','qitakoukuan'=>'其他扣款','yuzhigongzi'=>'预支工资','beizhu'=>'备注',
    ];

    /** 数值整元/整数；空串明确清空，不能被缺省值或旧 JSON 再覆盖。 */
    public static function values(array $values): array
    {
        if (!$values || array_diff(array_keys($values),array_keys(self::FIELDS))) throw new \InvalidArgumentException('工资包含不允许编辑的字段');
        foreach ($values as $key=>&$value) {
            if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('工资人工值格式无效');
            $value=trim((string)$value);
            if ($key==='beizhu') {
                if (mb_strlen($value)>500) throw new \InvalidArgumentException('备注最多500字');
            } elseif ($value!=='' && !preg_match($key==='haoqingshu' ? '/^\d{1,9}$/D' : '/^-?\d{1,9}$/D',$value)) {
                throw new \InvalidArgumentException(self::FIELDS[$key].'必须为整数或留空');
            }
        }
        unset($value); ksort($values);
        return $values;
    }

    /** 从服务端行身份生成同一月份、门店、员工主题；跨店同员工不会共享人工值。 */
    public static function rowKey(string $month,int $store,int $employee): string
    {
        self::month($month);
        if ($store<1 || $employee<1) throw new \InvalidArgumentException('工资员工或门店身份无效');
        return 'salary:'.$month.':'.$store.':'.$employee;
    }

    public static function month(string $month): void
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m',$month,new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m')!==$month) throw new \InvalidArgumentException('工资月份无效');
    }

    /** 只读当前补充及版本，须在工资Reader的一致事务内调用。 */
    public static function read(string $month,int $store,int $employee): array
    {
        $row=Db::name('basic_salary_manual')->where('row_key',self::rowKey($month,$store,$employee))->find();
        return ['values'=>$row ? self::decode((string)$row['values_json']) : [],'version'=>$row ? (int)$row['version'] : 0];
    }

    /** 月份锁只限制门店人工项编辑；平台例外，实时工资计算和统一事实读取均不受影响。 */
    public static function state(string $month): array
    {
        self::month($month);
        $row=Db::name('basic_salary_month_control')->where('month',$month)->find();
        return ['month'=>$month,'frozen'=>self::legacyFreeze($month),'version'=>$row ? (int)$row['version'] : 0];
    }

    /** 沿用既有月份冻结标记，旧门店工资入口也读取它，不另造一套互不相通的冻结状态。 */
    private static function legacyFreeze(string $month,bool $lock=false): bool
    {
        $query=Db::name('salary_freeze')->where('date',strtotime($month.'-01 Asia/Shanghai'));
        if ($lock) $query->lock(true);
        $rows=$query->select()->toArray();
        if (count($rows)>1) throw new \RuntimeException('同月冻结记录重复，请核对');
        return $rows && (int)$rows[0]['is_freeze']===1;
    }

    /** 行锁、乐观版本、幂等回执与审计同事务；冻结和保存共锁月份，防止检查后穿透。 */
    public function save(array $context,array $payload,bool $freeze=false): array
    {
        $stores=BasicRegistrationQueryProvider::stores($context);
        $actor=(string)($context['basic_actor_key'] ?? '');
        if (!preg_match('/^(platform|store):[1-9]\d*$/D',$actor)) throw new \InvalidArgumentException('工资操作员身份无效');
        $platform=strpos($actor,'platform:')===0;
        if (empty($context[$freeze ? 'salary_freeze' : 'salary_edit']) || ($freeze && !$platform)) throw new \InvalidArgumentException('当前账号没有工资'.($freeze ? '冻结' : '编辑').'权限');
        $allowed=$freeze ? ['month','frozen','expected_version','idempotency_key']
            : ['month','store_id','employee_id','row_key','values','expected_version','idempotency_key'];
        if (array_diff(array_keys($payload),$allowed)) throw new \InvalidArgumentException('工资保存包含未知字段');
        $month=(string)($payload['month'] ?? ''); self::month($month);
        $expected=self::integer($payload['expected_version'] ?? null);
        $token=(string)($payload['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D',$token)) throw new \InvalidArgumentException('工资保存请求标识无效');
        $store=$freeze ? 0 : self::integer($payload['store_id'] ?? null);
        $employee=$freeze ? 0 : self::integer($payload['employee_id'] ?? null);
        $subject=$freeze ? 'month:'.$month : self::rowKey($month,$store,$employee);
        if (!$freeze && (!in_array($store,$stores,true) || ($payload['row_key'] ?? '')!==$subject)) throw new \InvalidArgumentException('工资行不属于当前授权范围');
        if ($freeze && !is_bool($payload['frozen'] ?? null)) throw new \InvalidArgumentException('冻结状态必须明确');
        $values=$freeze ? ['frozen'=>$payload['frozen']] : self::values(is_array($payload['values'] ?? null) ? $payload['values'] : []);
        $fingerprint=hash('sha256',json_encode([$subject,$expected,$values],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $receipt=function()use($actor,$token){return Db::name('basic_salary_manual_audit')->where('actor_key',$actor)->where('idempotency_key',$token)->find();};
        $replay=function(array $row)use($fingerprint):array{
            if (!hash_equals((string)$row['request_fingerprint'],$fingerprint)) throw new \InvalidArgumentException('重复请求内容不一致');
            return json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR)+['replayed'=>true];
        };
        if ($prior=$receipt()) return $replay($prior);
        try {
            return Db::transaction(function()use($month,$subject,$store,$employee,$actor,$platform,$freeze,$expected,$values,$token,$fingerprint,$receipt,$replay):array{
                // INSERT IGNORE 的唯一月份键让首次保存/冻结也串行；不修改旧工资快照。
                Db::execute('INSERT IGNORE INTO `'.Db::connect()->getConfig('prefix').'basic_salary_month_control` (`month`,`version`) VALUES (?,0)',[$month]);
                $control=Db::name('basic_salary_month_control')->where('month',$month)->lock(true)->find();
                if ($prior=$receipt()) return $replay($prior);
                $frozen=self::legacyFreeze($month,true);
                if (!$platform && $frozen) throw new \InvalidArgumentException('当月已冻结，门店不可编辑');
                if (!$freeze) {
                    // 不能凭猜测的 employee_id 创建行：必须存在该店员工关系、本月任职或员工事实；离职员工补发仍可登记。
                    $member=Db::name('staff_job_position')->where('store_id',$store)->where('employee_id',$employee)->where('is_del',0)
                        ->where('start_time','<=',strtotime($month.'-01 +1 month Asia/Shanghai')-1)
                        ->where(function($q)use($month){$q->where('end_time',0)->whereOr('end_time','>=',strtotime($month.'-01 Asia/Shanghai'));})->find();
                    $fact=Db::name('cashier_v3_performance_fact')->where('store_id',$store)->where('employee_id',$employee)->whereLike('business_date',$month.'-%')->find();
                    $roster=Db::name('system_store_staff')->where('store_id',$store)->where('employee_id',$employee)->find();
                    if (!$member && !$fact && !$roster) throw new \InvalidArgumentException('该员工不属于本店工资月份');
                }
                $before=$freeze ? array_merge($control,['frozen'=>$frozen]) : Db::name('basic_salary_manual')->where('row_key',$subject)->lock(true)->find();
                $version=$before ? (int)$before['version'] : 0;
                if ($version!==$expected) throw new \InvalidArgumentException('工资内容已变化，请重新查询后再编辑');
                $version++;
                if ($freeze) {
                    if ($values['frozen']===$frozen) throw new \InvalidArgumentException('冻结状态已变化，请重新查询');
                    Db::name('basic_salary_month_control')->where('month',$month)->update(['version'=>$version]);
                    $date=strtotime($month.'-01 Asia/Shanghai');
                    $legacy=Db::name('salary_freeze')->where('date',$date)->find();
                    $flag=$values['frozen'] ? 1 : 2;
                    if ($legacy) Db::name('salary_freeze')->where('id',$legacy['id'])->update(['is_freeze'=>$flag]);
                    else Db::name('salary_freeze')->insert(['date'=>$date,'is_freeze'=>$flag]);
                    $result=['month'=>$month,'frozen'=>$values['frozen'],'version'=>$version];
                } else {
                    $merged=array_replace($before ? self::decode($before['values_json']) : [],$values);
                    $after=['values_json'=>json_encode($merged,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'version'=>$version,'updated_at'=>time(),'actor_key'=>$actor];
                    if ($before) Db::name('basic_salary_manual')->where('row_key',$subject)->update($after);
                    else Db::name('basic_salary_manual')->insert($after+['row_key'=>$subject,'month'=>$month,'store_id'=>$store,'employee_id'=>$employee]);
                    $result=['row_key'=>$subject,'values'=>$merged,'version'=>$version];
                }
                Db::name('basic_salary_manual_audit')->insert(['subject_key'=>$subject,'store_id'=>$store,'actor_key'=>$actor,'action'=>$freeze ? 'freeze' : 'edit',
                    'idempotency_key'=>$token,'request_fingerprint'=>$fingerprint,'before_json'=>json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'result_json'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>time()]);
                return $result+['replayed'=>false];
            });
        } catch (\Throwable $error) {
            if ($prior=$receipt()) return $replay($prior);
            throw $error;
        }
    }

    private static function integer($value): int
    {
        if (!is_int($value) || $value<0 || $value>2147483647) throw new \InvalidArgumentException('工资身份或版本无效');
        return $value;
    }

    /** 损坏的人工记录必须拒绝读取，不能用空值遮盖已保存内容。 */
    private static function decode(string $json): array
    {
        $values=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($values)) throw new \RuntimeException('工资人工记录损坏');
        return self::values($values);
    }
}
