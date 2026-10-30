<?php
declare(strict_types=1);
namespace app\services\user;

use think\exception\ValidateException;

/** 任务token无损压缩到32字符scene；调用实例自己的微信配置，绝不接受前端AppID或接口域名。 */
class MemberSurveyMiniProgramCodeServices
{
    public function make(string $token):array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new ValidateException('问卷入口无效');
        $appid=$this->appId();if(!preg_match('/^wx[a-z0-9]{16}$/D',$appid))throw new ValidateException('请先配置本实例会员小程序');
        $environment=(string)config('member_survey.mini_env_version','release');
        if(!in_array($environment,['release','trial','develop'],true))throw new ValidateException('小程序二维码环境配置无效');
        $scene=strtr(base64_encode(hex2bin($token)),'+/','-_');$page='pages/users/survey/index';
        try{$image=$this->fetchCode($scene,$page,$environment);}catch(\Throwable $e){throw new ValidateException('小程序码生成失败，请核对会员小程序配置及对应版本');}
        // 微信实际可返回PNG或JPEG；只接受图片魔数，不把JSON错误响应冒充二维码。
        $mime=substr($image,0,8)==="\x89PNG\r\n\x1a\n"?'image/png':(substr($image,0,3)==="\xff\xd8\xff"?'image/jpeg':'');
        if(strlen($image)>2*1024*1024||$mime==='')throw new ValidateException('微信未返回有效小程序码，请确认对应版本已上传');
        return ['kind'=>'miniprogram','app_id'=>$appid,'page'=>$page,'scene'=>$scene,'env_version'=>$environment,'image'=>'data:'.$mime.';base64,'.base64_encode($image)];
    }

    /** 受控适配边界便于离线测试，不把密钥、access_token或微信原始错误返回客户端。 */
    protected function appId():string{return trim((string)sys_config('routine_appId',''));}
    protected function fetchCode(string $scene,string $page,string $environment):string
    {
        $response=\mohe\services\wechat\MiniProgram::qrcodeService()->getUnlimit($scene,['page'=>$page,'width'=>430,'env_version'=>$environment,'check_path'=>$environment==='release']);
        if(!$response instanceof \Psr\Http\Message\ResponseInterface)throw new \RuntimeException('Wechat code image unavailable');
        return (string)$response->getBody();
    }
}
