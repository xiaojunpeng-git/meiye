<?php
namespace app\services\store;

use think\facade\Db;
use think\exception\ValidateException;

/** Current-store basic settings only. The platform and store share system_store;
 * organization, fees, delivery, staff credentials and historical facts are never written.
 */
final class StoreProfileSettingsServices
{
    private const FIELDS=['image','background_image','name','space_num','introduction','is_show','phone','address','detailed_address','province','city','area','street','longitude','latitude','day_time','day_start','day_end'];

    /** Read current master plus readonly organization, without QR/external side effects. */
    public function read(int $storeId): array
    {
        return $this->projection($this->master($storeId));
    }

    /** Locked content fingerprint also detects platform edits, whose legacy writer
     * does not maintain a store-side revision counter. No second editable profile.
     */
    public function version(array $row): string
    {
        $values=[];foreach(self::FIELDS as $field)$values[$field]=(string)($row[$field]??'');
        return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function master(int $storeId,bool $lock=false): array
    {
        if($storeId<=0)throw new ValidateException('缺少会话门店，请重新登录。');
        $query=Db::name('system_store')->where('id',$storeId)->where('is_del',0);
        if($lock)$query->lock(true);
        $row=$query->find();if(!$row)throw new ValidateException('当前门店不存在或已删除。');
        return $row;
    }

    private function projection(array $row): array
    {
        $profile=[];foreach(self::FIELDS as $field)$profile[$field]=(string)($row[$field]??'');
        $profile['space_num']=(int)$profile['space_num'];$profile['is_show']=(int)$profile['is_show'];
        $profile['address_ids']=array_values(array_filter(array_map('intval',[$row['province']??0,$row['city']??0,$row['area']??0,$row['street']??0])));
        $profile['day_time']=trim($profile['day_time'])===''?[]:array_map('trim',explode('-',$profile['day_time']));
        unset($profile['province'],$profile['city'],$profile['area'],$profile['street'],$profile['day_start'],$profile['day_end']);
        $organization=Db::name('organization_store')->alias('os')->leftJoin('organization o','o.id=os.org_id')->where('os.store_id',(int)$row['id'])->field('os.org_id,o.name')->find();
        return ['store_id'=>(int)$row['id'],'profile'=>$profile,'organization'=>['id'=>(int)($organization['org_id']??0),'name'=>(string)($organization['name']??'')],'expected_version'=>$this->version($row)];
    }

    /** Validate the exact editable field set. Address names come from the same
     * city_area dictionary as the platform; coordinates are geographic values,
     * not cashier money/quantities and retain their geographic decimal precision.
     */
    public function normalize(array $input): array
    {
        $allowed=['image','background_image','name','space_num','introduction','is_show','phone','address_ids','detailed_address','longitude','latitude','day_time'];
        if(array_diff(array_keys($input),$allowed))throw new ValidateException('包含不允许由门店修改的字段。');
        foreach($allowed as $field)if(!array_key_exists($field,$input))throw new ValidateException('门店资料不完整，请重新加载。');
        $out=[];
        foreach(['name'=>20,'introduction'=>100,'phone'=>25,'detailed_address'=>255,'image'=>255,'background_image'=>255]as $field=>$max){
            if(!is_string($input[$field]))throw new ValidateException('门店资料格式无效。');
            $value=trim($input[$field]);if(mb_strlen($value)>$max)throw new ValidateException('门店资料超过允许长度。');$out[$field]=$value;
        }
        if($out['name']===''||$out['phone']===''||$out['detailed_address']==='')throw new ValidateException('请填写门店名称、电话及详细地址。');
        if(!preg_match('/^[0-9+()（）\-\s]{3,25}$/uD',$out['phone']))throw new ValidateException('门店电话格式无效。');
        foreach(['image','background_image']as $field){if($out[$field]===''||(!preg_match('~^https?://~i',$out[$field])&&strpos($out[$field],'/')!==0)||strpos($out[$field],'//')===0)throw new ValidateException('请上传有效的门店照片和门头照片。');}
        $beds=$input['space_num'];if(!(is_int($beds)||(is_string($beds)&&preg_match('/^(0|[1-9][0-9]*)$/D',$beds)))||(float)$beds<0||(float)$beds>2147483647)throw new ValidateException('床位数必须为非负整数。');$out['space_num']=(int)$beds;
        if(!in_array($input['is_show'],[0,1,'0','1'],true))throw new ValidateException('营业状态无效。');$out['is_show']=(int)$input['is_show'];
        $times=$input['day_time'];if(!is_array($times)||array_values($times)!==$times||(!empty($times)&&count($times)!==2)||($out['is_show']&&count($times)!==2))throw new ValidateException('请选择完整的营业时间。');
        foreach($times as &$time){if(!is_string($time)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D',$time))throw new ValidateException('营业时间格式无效。');if(strlen($time)===5)$time.=':00';}unset($time);
        $out['day_time']=implode(' - ',$times);$out['day_start']=$times[0]??'';$out['day_end']=$times[1]??'';
        foreach(['longitude'=>180,'latitude'=>90]as $field=>$limit){$v=$input[$field];if((!is_string($v)&&!is_int($v)&&!is_float($v))||!is_numeric($v)||!is_finite((float)$v)||abs((float)$v)>$limit)throw new ValidateException('经纬度超出有效范围。');$out[$field]=sprintf('%.6F',(float)$v);}
        $ids=$input['address_ids'];if(!is_array($ids)||array_values($ids)!==$ids||count($ids)<2||count($ids)>4)throw new ValidateException('请选择门店所在地区。');
        $parent=0;$names=[];$fields=['province','city','area','street'];
        foreach($fields as $index=>$field){$out[$field]=0;if(!isset($ids[$index]))continue;$id=$ids[$index];if(!(is_int($id)||(is_string($id)&&ctype_digit($id)))||(int)$id<=0)throw new ValidateException('地区参数无效。');$row=Db::name('city_area')->where('id',(int)$id)->where('parent_id',$parent)->field('id,name')->find();if(!$row)throw new ValidateException('地区层级已变化，请重新选择。');$out[$field]=(int)$id;$parent=(int)$id;$names[]=$row['name'];}
        $out['address']=implode('',$names);if(mb_strlen($out['address'])>255)throw new ValidateException('地区名称超过允许长度。');return $out;
    }

    /** Master lock serializes same-store writes; audit + first-result replay commit
     * in the same transaction. Stale platform/store forms cannot overwrite edits.
     */
    public function save(int $storeId,array $operator,array $input,string $expected,string $token): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token)||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new ValidateException('保存标识无效，请重新加载。');
        ksort($input);$hash=hash('sha256',json_encode([$input,$expected],JSON_UNESCAPED_UNICODE));
        $result=Db::transaction(function()use($storeId,$operator,$input,$hash,$expected,$token){
            $before=$this->master($storeId,true);
            $prior=Db::name('cashier_v3_store_profile_audit')->where('store_id',$storeId)->where('request_token',$token)->find();
            if($prior){if($prior['input_hash']!==$hash)throw new ValidateException('重复保存标识对应了不同内容，请重新加载。');return json_decode($prior['after_json'],true);}
            if(!hash_equals($this->version($before),$expected))throw new ValidateException('门店资料已被其他人修改，请重新加载后再保存。');
            $data=$this->normalize($input);
            Db::name('system_store')->where('id',$storeId)->where('is_del',0)->update($data);
            $after=$this->projection($this->master($storeId));$old=$this->projection($before);
            Db::name('cashier_v3_store_profile_audit')->insert(['store_id'=>$storeId,'request_token'=>$token,'input_hash'=>$hash,'operator_id'=>(int)($operator['id']??0),'operator_name'=>mb_substr((string)($operator['name']??''),0,100),'before_json'=>json_encode($old,JSON_UNESCAPED_UNICODE),'after_json'=>json_encode($after,JSON_UNESCAPED_UNICODE),'created_at'=>time()]);
            return $after;
        });
        // Invalidate the same entry metadata cache after commit, never before it.
        // A cache outage retains the request key, so retry returns the saved result
        // and retries invalidation without a second write/audit.
        try{app()->make(SystemStoreServices::class)->clearEntryStoreCache();}
        catch(\Throwable $e){throw new ValidateException('门店资料已保存，但刷新缓存失败，请点击保存重试。');}
        return $result;
    }
}
