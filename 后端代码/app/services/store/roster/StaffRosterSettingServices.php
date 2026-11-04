<?php
declare(strict_types=1);
namespace app\services\store\roster;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 单一排班参数的版本、幂等与审计；配置值仍只保存在系统配置，不复制一套开关。 */
final class StaffRosterSettingServices
{
    public function read(): array
    {
        return Db::transaction(static function(): array {
            $row = StaffRosterSwitch::row();
            $revision = Db::name('staff_roster_setting_revision')->where('id', 1)->lock('LOCK IN SHARE MODE')->find();
            if (!$row || !$revision) throw new ValidateException('启用排班配置尚未升级。');
            return ['enabled' => StaffRosterSwitch::enabled() ? 1 : 0, 'version' => (int)$revision['version']];
        });
    }

    public function save(int $adminId, array $input, array $allowedStores): array
    {
        if (!in_array($input['enabled'] ?? null, [0, 1], true) || (int)($input['version'] ?? 0) <= 0) throw new ValidateException('排班参数或版本无效，请刷新后重试。');
        $token = (string)($input['request_token'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $token)) throw new ValidateException('请求标识无效。');
        return Db::transaction(function() use($adminId, $input, $token, $allowedStores): array {
            $config = StaffRosterSwitch::row(true);
            if (!$config) throw new ValidateException('启用排班配置尚未升级。');
            $key = ['store_id'=>0,'actor_type'=>'ADMIN','actor_id'=>$adminId,'request_token'=>$token];
            $hash = StaffRosterServices::fingerprint([$input]);
            $receipt = Db::name('staff_roster_command')->where($key)->lock(true)->find();
            if ($receipt) {
                if (!hash_equals($receipt['request_hash'], $hash)) throw new ValidateException('该请求已用于其他操作。');
                return json_decode($receipt['result_json'], true);
            }
            $revision = Db::name('staff_roster_setting_revision')->where('id',1)->lock(true)->find();
            if (!$revision || (int)$revision['version'] !== (int)$input['version']) throw new ValidateException('排班参数已被其他人修改，请刷新后重试。');
            $before = ['enabled'=>StaffRosterSwitch::enabled()?1:0,'version'=>(int)$revision['version']];
            if ($input['enabled'] === 1 && $before['enabled'] === 0) $this->assertCanEnable($allowedStores);
            $result = ['enabled'=>$input['enabled'],'version'=>$before['version']+1];
            Db::name('system_config')->where('id',$config['id'])->update(['value'=>(string)$input['enabled']]);
            Db::name('staff_roster_setting_revision')->where('id',1)->update(['version'=>$result['version']]);
            Db::name('staff_roster_command')->insert($key+['request_hash'=>$hash,'action'=>'SETTING','before_json'=>json_encode($before),'after_json'=>json_encode($result),'result_json'=>json_encode($result),'created_at'=>time()]);
            return $result;
        });
    }

    /** 关闭期间预约仍可正常新增；重新开启先检查既有预约，不通过开关暗中取消或换人。 */
    private function assertCanEnable(array $allowedStores): void
    {
        $headers = Db::name('cashier_v3_reservation')->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('lifecycle_generation',CashierV3ReservationLifecycleServices::GENERATION)->whereIn('status',['PENDING_CONFIRMATION','UNSTARTED','IN_SERVICE'])
            ->where('appointment_end_at','>',time())->select()->toArray();
        if (!$headers) return;
        $ids = array_column($headers,'id'); $staff = []; $legacy = [];
        foreach (Db::name('cashier_v3_reservation_staff_schedule')->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('reservation_id',$ids)->select()->toArray() as $r) $staff[$r['reservation_id']][(int)$r['staff_id']]=true;
        foreach (Db::name('cashier_v3_reservation_line')->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('reservation_id',$ids)->select()->toArray() as $r) foreach ((array)json_decode($r['artisan_staff_ids_json'],true) as $id) $legacy[$r['reservation_id']][(int)$id]=true;
        $all=[];foreach($headers as $h)foreach(array_keys($staff[$h['id']]??$legacy[$h['id']]??[]) as $id)$all[$id]=$id;
        StaffRosterAvailability::lock(array_values($all));
        // 取得员工锁后用当前读，排除刚完成/取消的预约并覆盖同批员工新提交的预约。
        $current=StaffRosterAvailability::reservations(array_values($all),time(),PHP_INT_MAX);
        $availability = new StaffRosterAvailability(); $conflicts=[];
        foreach($current as $h) foreach(array_keys($staff[$h['id']]??$legacy[$h['id']]??[]) as $id) {
            if($availability->allows($id,(int)$h['store_id'],(int)$h['appointment_start_at'],(int)$h['appointment_end_at']))continue;
            $allowed=in_array((int)$h['store_id'],$allowedStores,true);
            $conflicts[]=['staff_name'=>$allowed?(string)Db::name('system_store_staff')->where('id',$id)->value('staff_name'):'其他门店员工',
                'time'=>date('Y-m-d H:i',(int)$h['appointment_start_at']),'reservation_no'=>$allowed?$h['reservation_no']:'其他门店预约'];
        }
        if($conflicts)throw new StaffRosterConflict($conflicts);
    }
}
