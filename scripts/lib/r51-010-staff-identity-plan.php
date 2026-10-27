<?php
declare(strict_types=1);

/** Pure batch-scoped010 identity planner. Exact legal phones define one person
 * under explicit product approval. Never trim/guess names, connect to a DB,
 * execute a migration, create jobs/accounts, or modify historical references.
 * Caller supplies COMPLETE snapshot inventories and validates their batch.
 */
final class R51StaffIdentityPlanner
{
    private const MANAGERS = [1,2,3,4,5,6,7,8,9,10,106];
    private const STRUCTURE = ['store_id','status','is_del','position','uid','add_time'];

    /** Stable API: return ['private'=>executor payload, 'report'=>PII-free
     * archival report]. NEVER log the whole return value. Compatibility
     * create_employee/link_staff metadata lives in report.operations.
     */
    public static function plan(array $sourceStaff, array $targetStaff, array $employees, array $jobs): array
    {
        return self::build($sourceStaff,$targetStaff,$employees,$jobs);
    }

    /** Private executor-only payload, NEVER log/archive this result. Recompute
     * from the same transaction-locked snapshot as plan(), compare its digest
     * before applying. This method itself never writes or opens connections.
     */
    public static function privatePayload(array $sourceStaff, array $targetStaff, array $employees, array $jobs): array
    {
        return self::build($sourceStaff,$targetStaff,$employees,$jobs)['private'];
    }

    /** blocked suppresses every private operation. Reusing an employee
     * never updates its profile/UID/status. Every new employee has UID=NULL.
     * All source statuses participate; linking a departed row NEVER revives it.
     */
    private static function build(array $sourceStaff, array $targetStaff, array $employees, array $jobs): array
    {
        $input=['source_staff'=>$sourceStaff,'target_staff'=>$targetStaff,'employees'=>$employees,'jobs'=>$jobs];
        $report=['schema_version'=>2,'input_sha256'=>self::inputHash($input),
            'policy'=>'exact_valid_phone_identity','blocked'=>false,'input_errors'=>[],
            'employee_groups'=>[],'mappings'=>[],'skipped'=>[],'isolated'=>[],
            'forbidden_operations'=>['staff_status','staff_store','staff_id','jobs','accounts','permissions','organizations','historical_facts'],
            'side_effects'=>false];
        $private=['employee_inserts'=>[],'staff_links'=>[]];
        $indexed=[];
        foreach($input as$kind=>$rows){
            $indexed[$kind]=[];
            foreach($rows as$row){
                $id=is_array($row)?self::id($row['id']??null):0;
                if(!$id||isset($indexed[$kind][$id])){
                    $report['input_errors'][]=['inventory'=>$kind,'id'=>$id?:null,'code'=>$id?'DUPLICATE_PRIMARY_KEY':'INVALID_ROW_ID'];
                    continue;
                }
                $indexed[$kind][$id]=$row;
            }
            ksort($indexed[$kind],SORT_NUMERIC);
        }
        if($report['input_errors']){
            usort($report['input_errors'],static function($a,$b){return strcmp(json_encode($a),json_encode($b));});
            $report['blocked']=true; return self::finish($private,$report);
        }
        $sources=$indexed['source_staff'];$targets=$indexed['target_staff'];$people=$indexed['employees'];
        $groups=[];$peoplePhones=[];$jobStaff=[];
        foreach($people as$person)if(is_string($person['phone']??null))$peoplePhones['p:'.$person['phone']][]=$person;
        foreach($indexed['jobs']as$job)$jobStaff[self::id($job['staff_id']??null)][]=$job;
        foreach($sources as$id=>$source){
            $phone=$source['phone']??null;
            if(!is_string($phone)||$phone===''||!preg_match('/^1[3-9][0-9]{9}$/',$phone)||$phone!==trim($phone)){
                $report['isolated'][]=['staff_id'=>$id,'reasons'=>['INVALID_STRICT_PHONE'],'blocks_plan'=>false]; continue;
            }
            $groups['p:'.$phone][$id]=$source;
        }
        foreach($targets as$id=>$target)if(!isset($sources[$id])){
            $report['isolated'][]=['staff_id'=>$id,'reasons'=>['SOURCE_PRIMARY_KEY_MISSING'],'blocks_plan'=>true];
            $report['blocked']=true;
        }
        // Source IDs, not names or input ordering, choose the stable group key.
        uasort($groups,static function($a,$b){return min(array_keys($a))<=>min(array_keys($b));});
        foreach($groups as$group){
            ksort($group,SORT_NUMERIC);$minId=(int)array_key_first($group);
            $profile=$group[$minId];$phone=$profile['phone'];$owners=$peoplePhones['p:'.$phone]??[];
            $reasons=[];$fatal=false;$reuse=count($owners)===1;
            if(count($owners)>1){$reasons[]='EMPLOYEE_PHONE_NOT_UNIQUE';$fatal=true;}
            $employeeId=$reuse?(int)$owners[0]['id']:$minId;
            if(!$reuse&&isset($people[$employeeId])){$reasons[]='EMPLOYEE_ID_OCCUPIED';$fatal=true;}
            $groupLinks=[];$groupMappings=[];$groupSkips=[];$active=false;$allDeleted=true;
            foreach($group as$id=>$source){
                $target=$targets[$id]??null;
                if(!$target){$reasons[]='TARGET_PRIMARY_KEY_MISSING';continue;}
                foreach(self::STRUCTURE as$key)if(!array_key_exists($key,$source)||!array_key_exists($key,$target)
                    ||!self::sameValue($source[$key],$target[$key])){$reasons[]='STRUCTURE_MISMATCH_'.strtoupper($key);$fatal=true;}
                if(($target['phone']??null)!==$phone){$reasons[]='PHONE_SOURCE_TARGET_MISMATCH';$fatal=true;}
                if(!self::id($target['store_id']??null))$reasons[]='INVALID_STORE_ID';
                $linked=self::id($target['employee_id']??null);
                if(array_key_exists('employee_id',$target)&&$target['employee_id']!==null
                    &&$target['employee_id']!==0&&$target['employee_id']!=='0'&&!$linked){$reasons[]='INVALID_EXISTING_EMPLOYEE_ID';$fatal=true;}
                if($linked&&($linked!==$employeeId||!$reuse)){$reasons[]='PREEXISTING_IDENTITY_MISMATCH';$fatal=true;}
                if(in_array($id,self::MANAGERS,true)&&(!$reuse||$linked!==$employeeId)){
                    $reasons[]='PROTECTED_MANAGER_MAPPING_MISSING';$fatal=true;
                }
                foreach($jobStaff[$id]??[]as$job)if(self::id($job['employee_id']??null)!==$employeeId
                    ||self::id($job['store_id']??null)!==self::id($target['store_id']??null)){
                    $reasons[]='PREEXISTING_JOB_IDENTITY_MISMATCH';$fatal=true;
                }
                $active=$active||((string)$source['status']==='1'&&(string)$source['is_del']==='0');
                $allDeleted=$allDeleted&&(string)$source['is_del']==='1';
                $groupMappings[]=['source_staff_id'=>$id,'target_staff_id'=>$id,'employee_id'=>$employeeId,
                    'store_id'=>self::id($target['store_id']),'status'=>(int)$target['status'],'is_del'=>(int)$target['is_del'],
                    'action'=>$linked===$employeeId?'unchanged':'link'];
                if($linked===$employeeId)$groupSkips[]=['staff_id'=>$id,'employee_id'=>$employeeId,
                    'reason'=>in_array($id,self::MANAGERS,true)?'EXISTING_MANAGER_UNCHANGED':'ALREADY_CORRECTLY_MAPPED'];
                else $groupLinks[]=['staff_id'=>$id,'employee_id'=>$employeeId,'expected_employee_id'=>$target['employee_id']??null];
            }
            // A dangling external staff link must not be silently legitimized
            // by allocating an unrelated source group's minimum numeric ID.
            if(!$reuse)foreach($targets as$id=>$target)if(self::id($target['employee_id']??null)===$employeeId&&!isset($group[$id])){
                $reasons[]='EMPLOYEE_ID_REFERENCED_BY_OTHER_STAFF';$fatal=true;
            }
            if($reasons){
                $reasons=array_values(array_unique($reasons));sort($reasons,SORT_STRING);
                foreach($group as$id=>$source)$report['isolated'][]=['staff_id'=>$id,'reasons'=>$reasons,'blocks_plan'=>$fatal];
                $report['blocked']=$report['blocked']||$fatal;continue;
            }
            $report['employee_groups'][]=['source_staff_ids'=>array_keys($group),'employee_id'=>$employeeId,
                'profile_source_staff_id'=>$minId,'action'=>$reuse?'reuse_existing':'create','new_uid_null'=>!$reuse];
            foreach($groupMappings as$row)$report['mappings'][]=$row;
            foreach($groupSkips as$row)$report['skipped'][]=$row;
            foreach($groupLinks as$row)$private['staff_links'][]=$row;
            if(!$reuse){
                // Profile is copied from the stable minimum-ID source, not
                // compared to infer identity. No credential is copied/created.
                $private['employee_inserts'][]=['id'=>$employeeId,
                    'name'=>(string)($profile['staff_name']??''),'phone'=>$phone,
                    'avatar'=>(string)($profile['avatar']??'/static/images/staff/avatar_male.png'),'avatar_type'=>1,
                    'uid'=>null,'status'=>$active?1:0,'is_del'=>$allDeleted?1:0,
                    'add_time'=>(int)$profile['add_time']];
            }
        }
        return self::finish($private,$report);
    }

    /** Compare structural DB integer strings without turning NULL into zero. */
    private static function sameValue($a,$b): bool
    {
        return $a===null||$b===null?$a===$b:(is_int($a)||is_string($a))&&(is_int($b)||is_string($b))&&(string)$a===(string)$b;
    }

    /** Never coerce display values, fractional numbers or overflow into IDs. */
    private static function id($value): int
    {
        if(is_int($value))return $value>0?$value:0;
        if(!is_string($value)||!preg_match('/^[1-9][0-9]*$/D',$value))return 0;
        $id=(int)$value;return $id>0&&(string)$id===$value?$id:0;
    }

    /** Stable full-input hash includes private/unknown fields but emits none. */
    private static function inputHash(array $input): string
    {
        foreach($input as&$rows){
            $rows=array_map([self::class,'canonical'],$rows);
            usort($rows,static function($a,$b){return strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR));});
        }
        unset($rows);
        return hash('sha256',json_encode(self::canonical($input),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    /** Normalize associative keys only; nested list order stays meaningful. */
    private static function canonical($value)
    {
        if(!is_array($value))return $value;
        if($value&&array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);
        foreach($value as&$item)$item=self::canonical($item);
        unset($item);return $value;
    }

    /** Failure never exposes partial executable mutations. Report IDs and
     * counts remain available to explain/review an otherwise-safe proposal. */
    private static function finish(array $private,array $report): array
    {
        foreach(['mappings','skipped','isolated']as$key)usort($report[$key],static function($a,$b){
            return ($a['staff_id']??$a['target_staff_id'])<=>($b['staff_id']??$b['target_staff_id']);
        });
        usort($private['staff_links'],static function($a,$b){return $a['staff_id']<=>$b['staff_id'];});
        usort($private['employee_inserts'],static function($a,$b){return $a['id']<=>$b['id'];});
        $report['counts']=['source_phone_groups'=>count($report['employee_groups']),'mapped_staff'=>count($report['mappings']),
            'proposed_employee_inserts'=>count($private['employee_inserts']),'proposed_staff_links'=>count($private['staff_links']),
            'already_mapped'=>count($report['skipped']),'isolated'=>count($report['isolated'])];
        if($report['blocked'])$private=['employee_inserts'=>[],'staff_links'=>[]];
        $report['counts']['employee_inserts']=count($private['employee_inserts']);
        $report['counts']['staff_links']=count($private['staff_links']);
        $report['operations']=['create_employee'=>array_map(static function($person){return [
            'source_staff_id'=>$person['id'],'employee_id'=>$person['id'],
            'employee_status'=>$person['status'],'employee_is_del'=>$person['is_del'],'uid'=>null];
        },$private['employee_inserts']),'link_staff'=>$private['staff_links'],
            'jobs'=>[],'accounts'=>[],'permissions'=>[],'organizations'=>[],'business_facts'=>[]];
        // Legacy adapter counts remain available alongside explicit names.
        $report['counts']['create_employee']=$report['counts']['employee_inserts'];
        $report['counts']['link_staff']=$report['counts']['staff_links'];
        return ['private'=>$private,'report'=>$report];
    }
}
