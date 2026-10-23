<?php
declare(strict_types=1);
namespace app\controller\mobile\merchant;

use app\services\growth\GrowthMobileContextServices;
use app\services\growth\GrowthTaskServices;
use app\services\growth\GrowthAssetServices;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\Db;

/** 美容师适配器只交给业务服务校验，不接受客户端传入员工身份。 */
final class Growth extends BaseController
{
    private function context(): array{return app()->make(GrowthMobileContextServices::class)->resolve($this->request);}
    private function ok(array $r){return MobileApiResponse::success(['growth'=>$r],MobileApiResponse::MERCHANT_CONTRACT);}
    public function knowledge(){return $this->ok(app()->make(\app\services\growth\GrowthKnowledgeServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function openKnowledge(){return $this->ok(app()->make(\app\services\growth\GrowthKnowledgeServices::class)->open($this->context(),(array)$this->request->post()));}
    public function favoriteKnowledge(){return $this->ok(app()->make(\app\services\growth\GrowthKnowledgeServices::class)->favorite($this->context(),(array)$this->request->post()));}
    public function help(){return $this->ok(app()->make(\app\services\growth\GrowthHelpServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function askHelp(){return $this->ok(app()->make(\app\services\growth\GrowthHelpServices::class)->ask($this->context(),(array)$this->request->post()));}
    public function replyHelp(){return $this->ok(app()->make(\app\services\growth\GrowthHelpServices::class)->reply($this->context(),(array)$this->request->post()));}
    public function tasks(){return $this->ok(app()->make(GrowthTaskServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function task(){return $this->ok(app()->make(GrowthTaskServices::class)->detail($this->context(),(int)$this->request->get('id',0)));}
    public function referenceResult(){return $this->ok(app()->make(\app\services\growth\GrowthCourseRulesServices::class)->reference($this->context(),(array)$this->request->post()));}
    public function inlineAnswer(){return $this->ok(app()->make(\app\services\growth\GrowthCourseRulesServices::class)->answer($this->context(),(array)$this->request->post()));}
    public function learn(){return $this->ok(app()->make(GrowthTaskServices::class)->learn($this->context(),(array)$this->request->post()));}
    public function profileHistory(){$c=$this->context();return $this->ok(app()->make(\app\services\growth\GrowthProfileServices::class)->history($c,$c['employee_id'],(array)$this->request->get()));}
    public function profile(){$c=$this->context();return $this->ok(app()->make(\app\services\growth\GrowthProfileServices::class)->read($c,$c['employee_id']));}
    public function appraisals(){return $this->ok(app()->make(\app\services\growth\GrowthAppraisalServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function appraisal(){return $this->ok(app()->make(\app\services\growth\GrowthAppraisalServices::class)->detail($this->context(),(int)$this->request->get('id',0)));}
    public function confirmAppraisal(){return $this->ok(app()->make(\app\services\growth\GrowthAppraisalServices::class)->confirm($this->context(),(array)$this->request->post()));}
    public function objectAppraisal(){return $this->ok(app()->make(\app\services\growth\GrowthAppraisalServices::class)->object($this->context(),(array)$this->request->post()));}
    public function resolveObjection(){return $this->ok(app()->make(\app\services\growth\GrowthAppraisalServices::class)->resolve($this->context(),(array)$this->request->post()));}
    public function inspections(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function inspection(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->detail($this->context(),(int)$this->request->get('id',0)));}
    public function submitInspection(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->submit($this->context(),(array)$this->request->post()));}
    public function confirmInspection(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->confirmInspection($this->context(),(array)$this->request->post()));}
    public function issue(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->issueDetail($this->context(),(int)$this->request->get('id',0)));}
    public function rectifyIssue(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->rectify($this->context(),(array)$this->request->post()));}
    public function reviewIssue(){return $this->ok(app()->make(\app\services\growth\GrowthInspectionServices::class)->review($this->context(),(array)$this->request->post()));}
    public function practice(){return $this->ok(app()->make(\app\services\growth\GrowthPracticeServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function work(){return $this->ok(app()->make(\app\services\growth\GrowthPracticeServices::class)->detail($this->context(),(int)$this->request->get('task_id',0)));}
    public function submitWork(){return $this->ok(app()->make(\app\services\growth\GrowthPracticeServices::class)->submit($this->context(),(array)$this->request->post()));}
    public function reviewWork(){return $this->ok(app()->make(\app\services\growth\GrowthPracticeServices::class)->review($this->context(),(array)$this->request->post()));}
    public function confirmActivity(){return $this->ok(app()->make(\app\services\growth\GrowthPracticeServices::class)->confirmActivity($this->context(),(array)$this->request->post()));}
    public function upload(){return $this->ok(app()->make(\app\services\growth\GrowthAssetServices::class)->upload($this->context(),$this->request->file('file')));}
    public function startExam(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->start($this->context(),(array)$this->request->post()));}
    /** 练习即时解析不构成正式交卷，正式考试不可调用。 */
    public function checkPractice(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->practiceFeedback($this->context(),(array)$this->request->post()));}
    public function saveAnswers(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->answer($this->context(),(array)$this->request->post(),false));}
    public function submitExam(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->answer($this->context(),(array)$this->request->post(),true));}
    public function questionFeedback(){return $this->ok(app()->make(\app\services\growth\GrowthFeedbackServices::class)->submit($this->context(),(array)$this->request->post()));}
    public function practiceMistakes(){return $this->ok(app()->make(\app\services\growth\GrowthMistakeServices::class)->create($this->context(),(array)$this->request->post()));}
    public function attempt(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->view($this->context(),(int)$this->request->get('id',0)));}
    public function grade(){return $this->ok(app()->make(\app\services\growth\GrowthExamServices::class)->grade($this->context(),(array)$this->request->post()));}
    public function messages(){ $c=$this->context();$q=Db::name('growth_message')->alias('m')->leftJoin('growth_message_target mt','mt.message_id=m.id')->where('m.employee_id',$c['employee_id'])->field('m.*,mt.target_type,mt.target_id');[$rows,$page]=\app\services\growth\GrowthPage::take($q,$this->request->get('page',1),'m.id');return $this->ok(['rows'=>$rows]+$page); }
    public function readMessage(){ $c=$this->context();Db::name('growth_message')->where('employee_id',$c['employee_id'])->where('id',(int)$this->request->post('id',0))->where('read_at',0)->update(['read_at'=>time()]);return $this->ok(['read'=>true]); }
    public function assetInfo(){return $this->ok(app()->make(GrowthAssetServices::class)->summary(app()->make(GrowthAssetServices::class)->readable($this->context(),(int)$this->request->get('id',0),(int)$this->request->get('task_id',0))));}
    public function asset(){ $r=app()->make(GrowthAssetServices::class)->file($this->context(),(int)$this->request->get('id',0),(int)$this->request->get('task_id',0));return response(file_get_contents($r['path']),200)->header(['Content-Type'=>mime_content_type($r['path']),'Cache-Control'=>'private, no-store','Content-Disposition'=>'inline; filename="preview.'.pathinfo($r['path'],PATHINFO_EXTENSION).'"','X-Content-Type-Options'=>'nosniff']); }
}
