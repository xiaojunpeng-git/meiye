<?php
declare(strict_types=1);
/** 只在隔离库针对本测试规则制造一次落库失败，验证事务回滚和自动恢复；结束总会移除触发器。 */
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');$app->initialize();
use think\facade\Db;
if(config('database.connections.mysql.database')!=='growth_qa_20261023')throw new RuntimeException('仅允许隔离测试库');
$in=json_decode(stream_get_contents(STDIN),true)?:[];$mode=$in['mode']??'';$rule=(int)($in['rule_id']??0);$out=[];
if($mode==='remove'){Db::execute('DROP TRIGGER IF EXISTS growth_qa_reward_failure');$out=['removed'=>true];}
elseif($mode==='install'){if(!Db::name('growth_point_rule')->where('id',$rule)->whereLike('title','联动恢复夹具-%')->find())throw new RuntimeException('只针对本测试规则');Db::execute('DROP TRIGGER IF EXISTS growth_qa_reward_failure');Db::execute("CREATE TRIGGER growth_qa_reward_failure BEFORE INSERT ON eb_growth_point_ledger FOR EACH ROW BEGIN IF NEW.rule_id=".$rule." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='growth recovery fixture'; END IF; END");$out=['installed'=>true];}
elseif($mode==='age'){$task=(int)($in['task_id']??0);$event=Db::name('growth_event')->where('event_type','TASK_COMPLETED')->where('source_type','task')->where('source_id',$task)->find();if(!$event||!Db::name('growth_task')->where('id',$task)->whereLike('title','联动恢复课程-%')->find())throw new RuntimeException('仅调整本测试事件重试等待');Db::name('growth_delivery_attempt')->where('event_id',$event['id'])->update(['created_at'=>time()-31]);$out=['event'=>$event];}
else throw new RuntimeException('恢复夹具模式无效');echo json_encode($out,JSON_UNESCAPED_UNICODE).PHP_EOL;
