<?php
declare(strict_types=1);
namespace app\services\user;

use think\facade\Db;
use think\exception\ValidateException;

/** 调查奖励配置独立于旧收银缓存；发布时冻结配置，发放权益由010赠送领域负责。 */
final class MemberSurveyRewardConfigurationServices
{
    public const GIFT_TYPE_SURVEY = 1;
    public const GIFT_TYPE_SURVEY_QUESTIONNAIRE = 2;
    /** 未配置奖励也允许答卷，不以默认赠品补写奖励。 */
    public function emptyConfig(): array { return ['store_id'=>0,'reward_each_round'=>true,'product'=>[],'coupon'=>[]]; }

    /** 配置读取无任何补写或旧缓存迁移副作用。 */
    public function getConfig(int $type, int $id): array
    {
        $body=Db::name('member_survey_reward_config')->where('gift_type',$type)->where('relation_id',$id)->value('config');
        if(!$body)return $this->emptyConfig();
        $config=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($config))throw new ValidateException('调查奖励配置异常');
        return $config+$this->emptyConfig();
    }

    /** 批量只读配置供管理列表使用，不读取或触发旧发奖缓存。 */
    public function getConfigMap(int $type,array $ids):array
    {
        if(!$ids)return[];$out=[];
        foreach(Db::name('member_survey_reward_config')->where('gift_type',$type)->whereIn('relation_id',$ids)->select()->toArray()as$row)
            $out[(int)$row['relation_id']]=json_decode($row['config'],true,512,JSON_THROW_ON_ERROR)+$this->emptyConfig();
        return$out;
    }

    /** 调用者必须先锁定所属问卷或任务，避免保存奖励与发布快照交错。 */
    public function saveConfig(int $type,int $id,array $config):void
    {
        $row=Db::name('member_survey_reward_config')->where('gift_type',$type)->where('relation_id',$id)->lock(true)->find();
        $data=['config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'updated_at'=>time()];
        if($row)Db::name('member_survey_reward_config')->where('id',$row['id'])->update($data);
        else Db::name('member_survey_reward_config')->insert($data+['gift_type'=>$type,'relation_id'=>$id,'created_at'=>time()]);
    }
}
