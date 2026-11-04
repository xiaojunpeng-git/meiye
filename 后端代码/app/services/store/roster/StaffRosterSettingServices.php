<?php
declare(strict_types=1);
namespace app\services\store\roster;
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

    /** 开关只改变后续预约校验和日历显示；不扫描、阻断或改写已有预约，版本/幂等/审计保护仍保留。 */
    public function save(int $adminId, array $input): array
    {
        if (!in_array($input['enabled'] ?? null, [0, 1], true) || (int)($input['version'] ?? 0) <= 0) throw new ValidateException('排班参数或版本无效，请刷新后重试。');
        $token = (string)($input['request_token'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $token)) throw new ValidateException('请求标识无效。');
        return Db::transaction(function() use($adminId, $input, $token): array {
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
            $result = ['enabled'=>$input['enabled'],'version'=>$before['version']+1];
            Db::name('system_config')->where('id',$config['id'])->update(['value'=>(string)$input['enabled']]);
            Db::name('staff_roster_setting_revision')->where('id',1)->update(['version'=>$result['version']]);
            Db::name('staff_roster_command')->insert($key+['request_hash'=>$hash,'action'=>'SETTING','before_json'=>json_encode($before),'after_json'=>json_encode($result),'result_json'=>json_encode($result),'created_at'=>time()]);
            return $result;
        });
    }

}
