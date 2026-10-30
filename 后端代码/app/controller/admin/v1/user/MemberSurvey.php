<?php
namespace app\controller\admin\v1\user;

use app\controller\admin\AuthController;
use app\services\user\MemberSurveyServices;
use app\services\user\MemberSurveyGiftServices;

class MemberSurvey extends AuthController
{
    /** 管理与发布只允许总部后台；会员扫码入口使用独立公开只读接口。 */
    private function headquartersOnly(): void
    {
        // 使用认证控制器校验过的会话；兼容旧平台“未登记API放行”时也必须显式核验菜单能力。
        $admin=(array)$this->adminInfo;
        $grants=!empty($admin['level'])?app()->make(\app\services\system\SystemRoleServices::class)->getRolesByAuth((array)($admin['roles']??[])):[];
        (new \app\services\user\MemberSurveyAccessPolicy())->assertPlatform($admin,$grants);
    }

    public function qr($id,MemberSurveyServices $service){$this->headquartersOnly();return app('json')->success($service->qrEntry((int)$id));}
    public function giftOptions(MemberSurveyGiftServices $service){$this->headquartersOnly();return app('json')->success($service->options((int)$this->request->get('store_id',0),(string)$this->request->get('keyword','')));}
    /** 商品目录仅服务总部奖励配置，门店归属和赠送资格仍在保存及发放事务中重验。 */
    public function giftCatalog(MemberSurveyGiftServices $service){$this->headquartersOnly();return app('json')->success($service->catalog((int)$this->request->get('store_id',0),(array)$this->request->get()));}
    /** 后台重试仅履约该任务已经提交的答卷，不创建答卷或增加赠品配置。 */
    public function retryRewards($id,MemberSurveyGiftServices $service)
    {
        $this->headquartersOnly();$ids=\think\facade\Db::name('member_survey_reward')->where('task_id',(int)$id)->where('status','pending')->order('id')->limit(20)->column('id');
        $out=[];foreach($ids as$rewardId)$out[]=$service->fulfil((int)$rewardId);return app('json')->success(['processed'=>count($out),'results'=>$out]);
    }

    public function questionnaireList(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->listQuestionnaires((int)$this->request->get('page',1),(int)$this->request->get('limit',20)));
    }

    public function questionnaireOptions(MemberSurveyServices $service){$this->headquartersOnly();return app('json')->success($service->questionnaireOptions((string)$this->request->get('keyword','')));}

    public function questionnaireRead($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->readQuestionnaire((int)$id));
    }

    public function questionnaireAnalysis($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->questionnaireAnalysis((int)$id));
    }

    public function questionnaireExportData($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->questionnaireExportData((int)$id));
    }

    public function questionnaireTextAnswers($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->questionnaireTextAnswers((int)$id,
            (string)$this->request->get('group_key', ''), (string)$this->request->get('question_id', ''),
            (int)$this->request->get('page', 1), (int)$this->request->get('limit', 20)));
    }

    public function questionnaireSave(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success(['id' => $service->saveQuestionnaire((array)$this->request->post(), (int)$this->adminId)]);
    }

    public function questionnaireUpdate($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success(['id' => $service->saveQuestionnaire((array)$this->request->post(), (int)$this->adminId, (int)$id)]);
    }

    public function index(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->listTasks((int)$this->request->get('page', 1), (int)$this->request->get('limit', 20), (string)$this->request->get('status', 'normal')));
    }

    public function read($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->readTask((int)$id));
    }

    public function members(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->searchMembers((string)$this->request->get('keyword', '')));
    }

    public function gifts($id, MemberSurveyGiftServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->config((int)$id));
    }

    public function updateGifts($id, MemberSurveyGiftServices $service)
    {
        $this->headquartersOnly();
        $service->saveConfig((int)$id, (array)$this->request->post('sendAll', []));
        return app('json')->success('赠送配置已保存');
    }

    public function save(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success(['id' => $service->save((array)$this->request->post(), (int)$this->adminId)]);
    }

    public function update($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success(['id' => $service->save((array)$this->request->post(), (int)$this->adminId, (int)$id)]);
    }

    /** 复制只产生草稿配置，不继承原任务的答卷、奖励和二维码。 */
    public function copy($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success(['id' => $service->copyTask((int)$id, (int)$this->adminId)]);
    }

    /** 服务层在同一事务锁定任务并保护已发生的答卷与赠送事实。 */
    public function delete($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        $service->deleteTask((int)$id);
        return app('json')->success('调查任务已删除');
    }

    public function targets($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->readTargets((int)$id));
    }

    public function updateTargets($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        $service->saveTargets((int)$id, (array)$this->request->post('filters', []));
        return app('json')->success('目标会员已保存');
    }

    public function preview(MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        $filters = $this->request->post('filters', []);
        return app('json')->success($service->preview((array)$filters));
    }

    public function publish($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->publish((int)$id));
    }

    public function stop($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        $service->stop((int)$id);
        return app('json')->success('已停止');
    }

    public function resend($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success('已发送，会员下次进入小程序将重新填写', $service->resend((int)$id));
    }

    /** 与查看结果使用同一总部权限边界，只导出指定任务的已提交回答。 */
    public function exportData($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->taskExportData((int)$id));
    }

    public function results($id, MemberSurveyServices $service)
    {
        $this->headquartersOnly();
        return app('json')->success($service->results((int)$id, (int)$this->request->get('page', 1), (int)$this->request->get('limit', 20)));
    }
}
