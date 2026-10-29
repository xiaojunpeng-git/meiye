<?php
namespace app\controller\api\v1\user;

use app\Request;
use app\services\user\MemberSurveyServices;

class MemberSurvey
{
    /** 公开二维码仅提供有效任务题目；提交与奖励仍使用强制会员认证路由。 */
    public function entry($token,MemberSurveyServices $service){return app('json')->success($service->entry((string)$token));}
    public function reward($id,Request $request,\app\services\user\MemberSurveyGiftServices $service){return app('json')->success($service->memberStatus((int)$id,(int)$request->uid()));}
    public function retry($id,Request $request,\app\services\user\MemberSurveyGiftServices $service){return app('json')->success($service->memberStatus((int)$id,(int)$request->uid(),true));}
    public function pending(Request $request, MemberSurveyServices $service)
    {
        return app('json')->success('成功', $service->pending((int)$request->uid()));
    }

    public function claim($id, Request $request, MemberSurveyServices $service)
    {
        return app('json')->success('成功', $service->claim((int)$request->uid(), (int)$id,
            (int)$request->post('repeat_on_entry', 0) === 1));
    }

    public function detail($id, Request $request, MemberSurveyServices $service)
    {
        return app('json')->success($service->memberDetail((int)$request->uid(), (int)$id));
    }

    public function submit($id, Request $request, MemberSurveyServices $service)
    {
        // 门店ID只代表未归属会员主动选择，最终有效性与已有归属保护均在事务内核验。
        $store=$request->post('store_id',0);
        if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$store))throw new \think\exception\ValidateException('所选门店格式不正确');
        $gift = $service->submit((int)$request->uid(), (int)$id, $request->post('answers', []), (int)$request->post('send_round', 0), (int)$store);
        return app('json')->success('提交成功', ['gift' => $gift]);
    }
}
