<?php
declare(strict_types=1);
/** 只读验证非空任务列表候选；候选仅在CLI内存执行，不替换线上代码或改写任务。 */
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(PHP_SAPI!=='cli'||!isset($targets[$site]))throw new RuntimeException('Explicit survey instance required');
$root='/www/wwwroot/'.$site.'.cc3798.com';chdir($root);require 'vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() d')[0]['d']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong instance database');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$path='app/services/user/MemberSurveyServices.php';
if(hash_file('sha256',$root.'/'.$path)!==$input['expected_before_sha'])throw new RuntimeException('Live survey service changed');
$source=base64_decode($input['candidate_source'],true);if(!is_string($source)||hash('sha256',$source)!==$input['candidate_sha'])throw new RuntimeException('Candidate source mismatch');
Db::execute('SET SESSION TRANSACTION READ ONLY');Db::startTrans();
try{
 $tables=['member_survey_task','member_survey_questionnaire','member_survey_target','member_survey_answer','member_survey_popup','member_survey_reward_config','member_survey_reward'];
 $snapshot=static function()use($tables):array{$out=[];foreach($tables as$t)$out[$t]=hash('sha256',json_encode(Db::name($t)->order('id')->select()->toArray(),JSON_THROW_ON_ERROR));return$out;};
 $before=$snapshot();
 if(class_exists('app\\services\\user\\MemberSurveyServices',false))throw new RuntimeException('Candidate service already loaded');
 eval(substr($source,5));
 $result=(new app\services\user\MemberSurveyServices())->listTasks(1,20);$checks=0;
 foreach($result['list']as$row){
  $id=(int)$row['id'];$all=(int)Db::name('member_survey_answer')->where('task_id',$id)->count();
  $round=(int)Db::name('member_survey_answer')->where('task_id',$id)->where('send_round',$row['send_round'])->count();
  $target=(int)Db::name('member_survey_target')->where('task_id',$id)->where('source','<>','qrcode')->count();
  if((int)$row['total_answer_count']!==$all||(int)$row['answer_count']!==$round||(int)$row['target_count']!==$target)throw new RuntimeException('Task counters do not match source records');$checks++;
 }
 if($before!==$snapshot())throw new RuntimeException('Read-only survey data changed');
 echo json_encode(['result'=>'PASS','site'=>$site,'before_service_sha256'=>$input['expected_before_sha'],'candidate_sha256'=>$input['candidate_sha'],'task_total'=>$result['count'],'task_rows_verified'=>$checks,'task_ids'=>array_map('intval',array_column($result['list'],'id')),'survey_data_sha256'=>$before,'survey_data_unchanged'=>true,'runtime_source_file_unchanged'=>hash_file('sha256',$root.'/'.$path)===$input['expected_before_sha'],'platform_entry_sha256'=>hash_file('sha256',$root.'/public/system.html'),'member_entry_sha256'=>hash_file('sha256',$root.'/public/index.html'),'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}finally{Db::rollback();}
