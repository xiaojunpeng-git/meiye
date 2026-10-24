<?php
declare(strict_types=1);
namespace app\controller\admin\v1\growth;

use app\controller\admin\AuthController;
use app\services\growth\GrowthAccessServices;
use app\services\growth\GrowthCommandServices;
use app\services\growth\GrowthContentServices;
use app\services\growth\GrowthTaskServices;
use app\services\growth\GrowthAssetServices;
use think\facade\Db;

/** Vue2平台适配；每个API从固定业务菜单求权限，动作只保留稳定锚点。 */
final class Growth extends AuthController
{
    private function context(string $menu='content',bool $enabled=true): array
    {
        $access=app()->make(GrowthAccessServices::class);$c=$access->platform((array)$this->adminInfo,'admin-growth-'.$menu);
        if($enabled)$access->assertEnabled();return $c;
    }
    private function input(): array {return (array)$this->request->post();}
    /** 下拉候选按调用菜单返回；未安装完整模块仍允许设置页读取安装状态。 */
    public function catalog()
    {
        $menu=(string)$this->request->get('menu','content');
        if(!in_array($menu,['content','tasks','plans','exams','practice','inspection','profiles','reports','settings'],true))throw new \mohe\exceptions\AdminException('成长菜单无效');
        $c=$this->context($menu,false);$access=app()->make(GrowthAccessServices::class);$ids=$access->employees($c);
        $r=['operator_employee_id'=>$c['employee_id'],'employees'=>Db::name('employee')->whereIn('id',$ids)->where('status',1)->where('is_del',0)->field('id,name,phone')->order('id')->select()->toArray(),'course_publications'=>[],'activity_options'=>[],'exam_options'=>[],'question_options'=>[],'capability_options'=>[],'schema'=>app()->make(\app\services\growth\GrowthSchemaServices::class)->status(),'point_rule_options'=>[],'certificate_rule_options'=>[],'settings'=>$access->hasTable('growth_setting')?(Db::name('growth_setting')->where('id',1)->find()?:[]):[]];
        if(!$r['schema']['ready'])return $this->success($r);
        if(in_array($menu,['content','tasks','plans','inspection'],true)){
            $q=Db::name('growth_publication')->alias('p')->join('growth_content c','c.id=p.content_id')->where('p.state','PUBLISHED')->where('c.state','PUBLISHED')->whereColumn('p.id','c.current_publication_id');if(!$c['super'])$q->whereIn('c.owner_employee_id',$ids);
            $r['course_publications']=$q->field('p.id,p.title,c.id AS content_id')->order('p.id','desc')->limit(500)->select()->toArray();
        }
        if(in_array($menu,['content','plans','inspection'],true)){$q=Db::name('growth_activity');if(!$c['super'])$q->whereIn('owner_employee_id',$ids);$r['activity_options']=$q->field('id,title,kind')->order('id','desc')->limit(500)->select()->toArray();}
        if(in_array($menu,['content','plans'],true))$r['exam_options']=app()->make(\app\services\growth\GrowthQuestionServices::class)->queryOwned($c,'growth_exam')->where('state','OPEN')->field('id,title,kind')->order('id','desc')->limit(500)->select()->toArray();
        if(in_array($menu,['content','settings','exams'],true)){foreach(['point'=>'growth_point_rule','certificate'=>'growth_certificate_rule'] as $kind=>$table)$r[$kind.'_rule_options']=Db::name($table)->where('enabled',1)->field('id,title')->order('id')->select()->toArray();}
        if($menu==='content')$r['question_options']=app()->make(\app\services\growth\GrowthQuestionServices::class)->queryOwned($c,'growth_question')->where('state','ACTIVE')->where('type','<>','text')->field('id,stem,type')->order('id','desc')->limit(500)->select()->toArray();
        if($menu==='exams')$r['question_options']=app()->make(\app\services\growth\GrowthQuestionServices::class)->queryOwned($c,'growth_question')->where('state','ACTIVE')->field('id,stem,type')->order('id','desc')->limit(500)->select()->toArray();
        if($menu==='practice'){$q=Db::name('growth_capability');if(!$c['super'])$q->whereIn('owner_employee_id',$ids);$r['capability_options']=$q->field('id,title,position_id,standard_release_id')->order('id','desc')->limit(500)->select()->toArray();}
        return $this->success($r);
    }
    public function settings(){ $this->context('settings',false);return $this->success(Db::name('growth_setting')->where('id',1)->find()?:[]); }
    public function saveSettings()
    {
        $c=$this->context('settings',false);$in=$this->input();
        return $this->success(app()->make(\app\services\growth\GrowthSettingsServices::class)->save($c,$in,(int)$this->adminId));
    }
    public function processStatus(){return $this->success(app()->make(\app\services\growth\GrowthSettingsServices::class)->monitor($this->context('settings',false)));}
    public function retryProcess(){$c=$this->context('settings',false);app()->make(\app\services\growth\GrowthProcessServices::class)->tick();return $this->success(app()->make(\app\services\growth\GrowthSettingsServices::class)->monitor($c));}
    public function rewardSettings(){$c=$this->context('settings');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->settings());}
    public function saveRewardRule(){$c=$this->context('settings');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->saveRule($c,$this->input()));}
    public function saveLevels(){$c=$this->context('settings');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->saveLevels($c,$this->input()));}
    public function adjustPoints(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->adjust($c,$this->input()));}
    public function registerCertificate(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->registerCertificate($c,$this->input()));}
    public function certificateAction(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthRewardServices::class)->certificateAction($c,$this->input()));}
    public function overview(){return $this->success(app()->make(\app\services\growth\GrowthOverviewServices::class)->read($this->context('reports'),(array)$this->request->get()));}
    public function reviewCatalog(){return $this->success(app()->make(\app\services\growth\GrowthReviewServices::class)->catalog($this->context('reports')));}
    public function reviews(){return $this->success(app()->make(\app\services\growth\GrowthReviewServices::class)->listing($this->context('reports'),(array)$this->request->get()));}
    public function saveReview(){return $this->success(app()->make(\app\services\growth\GrowthReviewServices::class)->save($this->context('reports'),$this->input()));}
    public function reportFilters(){return $this->success(app()->make(\app\services\growth\GrowthReportServices::class)->filters($this->context('reports')));}
    public function report(){return $this->success(app()->make(\app\services\growth\GrowthReportServices::class)->read($this->context('reports'),(array)$this->request->get()));}
    public function reportDrill(){return $this->success(app()->make(\app\services\growth\GrowthReportServices::class)->drill($this->context('reports'),(array)$this->request->get()));}
    public function reportExport(){return $this->success(app()->make(\app\services\growth\GrowthReportServices::class)->export($this->context('reports'),$this->input()));}
    public function reportFile(){$path=app()->make(\app\services\growth\GrowthReportServices::class)->exportFile($this->context('reports'),(int)$this->request->get('id',0));return response(file_get_contents($path),200)->header(['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="growth-report.csv"','Cache-Control'=>'private, no-store']);}
    public function business(){return $this->success(app()->make(\app\services\growth\GrowthBusinessServices::class)->read($this->context('reports'),(array)$this->request->get()));}
    public function profileHistory(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthProfileServices::class)->history($c,(int)$this->request->get('employee_id',0),(array)$this->request->get()));}
    public function profilePeople(){return $this->success(app()->make(\app\services\growth\GrowthProfileServices::class)->people($this->context('profiles')));}
    public function profile(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthProfileServices::class)->read($c,(int)$this->request->get('employee_id',0)));}
    public function appraisalCatalog(){$c=$this->context('profiles');return $this->success(['standards'=>app()->make(\app\services\growth\GrowthStandardServices::class)->listing($c)]);}
    public function appraisals(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->listing($c,(array)$this->request->get()));}
    public function createAppraisal(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->create($c,$this->input()));}
    public function appraisal(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->detail($c,(int)$this->request->get('id',0)));}
    public function confirmAppraisal(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->confirm($c,$this->input()));}
    public function resolveObjection(){$c=$this->context('profiles');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->resolve($c,$this->input()));}
    public function capabilities(){return $this->success(app()->make(\app\services\growth\GrowthCapabilityServices::class)->listing($this->context('practice'),(array)$this->request->get()));}
    public function saveMentor(){return $this->success(app()->make(\app\services\growth\GrowthCapabilityServices::class)->saveMentor($this->context('practice'),$this->input()));}
    public function trainIssue(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->train($this->context('inspection'),$this->input()));}
    public function saveCapability(){$c=$this->context('practice');return $this->success(app()->make(\app\services\growth\GrowthAppraisalServices::class)->saveCapability($c,$this->input()));}
    public function inspections(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->listing($this->context('inspection'),(array)$this->request->get()));}
    public function inspection(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->detail($this->context('inspection'),(int)$this->request->get('id',0)));}
    public function createInspection(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->create($this->context('inspection'),$this->input()));}
    public function submitInspection(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->submit($this->context('inspection'),$this->input()));}
    public function handoverInspection(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->handover($this->context('inspection'),$this->input()));}
    public function confirmInspection(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->confirmInspection($this->context('inspection'),$this->input()));}
    public function issue(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->issueDetail($this->context('inspection'),(int)$this->request->get('id',0)));}
    public function rectifyIssue(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->rectify($this->context('inspection'),$this->input()));}
    public function reviewIssue(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->review($this->context('inspection'),$this->input()));}
    public function issueAction(){return $this->success(app()->make(\app\services\growth\GrowthInspectionServices::class)->action($this->context('inspection'),$this->input()));}
    public function help(){return $this->success(app()->make(\app\services\growth\GrowthHelpServices::class)->listing($this->context('practice'),(array)$this->request->get()));}
    public function replyHelp(){return $this->success(app()->make(\app\services\growth\GrowthHelpServices::class)->reply($this->context('practice'),$this->input()));}
    public function promoteHelp(){return $this->success(app()->make(\app\services\growth\GrowthHelpServices::class)->promote($this->context('practice'),$this->input()));}
    public function practice(){return $this->success(app()->make(\app\services\growth\GrowthPracticeServices::class)->listing($this->context('practice'),(array)$this->request->get()));}
    public function createActivity(){return $this->success(app()->make(\app\services\growth\GrowthPracticeServices::class)->create($this->context('practice'),$this->input()));}
    public function publishStandard(){return $this->success(app()->make(\app\services\growth\GrowthStandardServices::class)->publish($this->context((string)$this->request->post('menu','practice')),$this->input()));}
    public function workDetail(){return $this->success(app()->make(\app\services\growth\GrowthPracticeServices::class)->detail($this->context('practice'),(int)$this->request->get('task_id',0)));}
    public function reviewWork(){return $this->success(app()->make(\app\services\growth\GrowthPracticeServices::class)->review($this->context('practice'),$this->input()));}
    public function confirmActivity(){return $this->success(app()->make(\app\services\growth\GrowthPracticeServices::class)->confirmActivity($this->context('practice'),$this->input()));}
    public function onboarding(){return $this->success(app()->make(\app\services\growth\GrowthOnboardingServices::class)->listing($this->context('plans'),(array)$this->request->get()));}
    public function saveOnboarding(){return $this->success(app()->make(\app\services\growth\GrowthOnboardingServices::class)->save($this->context('plans'),$this->input()));}
    public function plans(){return $this->success(app()->make(\app\services\growth\GrowthPlanServices::class)->listing($this->context('plans'),(array)$this->request->get()));}
    public function publishMap(){return $this->success(app()->make(\app\services\growth\GrowthPlanServices::class)->publishMap($this->context('plans'),$this->input()));}
    public function publishPlan(){return $this->success(app()->make(\app\services\growth\GrowthPlanServices::class)->publishPlan($this->context('plans'),$this->input()));}
    public function planPeople(){return $this->success(app()->make(\app\services\growth\GrowthPlanServices::class)->changePeople($this->context('plans'),$this->input()));}
    public function questionFeedback(){return $this->success(app()->make(\app\services\growth\GrowthFeedbackServices::class)->listing($this->context('exams'),(array)$this->request->get()));}
    public function resolveQuestionFeedback(){return $this->success(app()->make(\app\services\growth\GrowthFeedbackServices::class)->resolve($this->context('exams'),$this->input()));}
    public function questions(){return $this->success(app()->make(\app\services\growth\GrowthQuestionServices::class)->catalog($this->context('exams'),(array)$this->request->get()));}
    public function saveQuestion(){return $this->success(app()->make(\app\services\growth\GrowthQuestionServices::class)->save($this->context('exams'),$this->input()));}
    public function importQuestions(){return $this->success(app()->make(\app\services\growth\GrowthQuestionServices::class)->import($this->context('exams'),$this->input()));}
    public function publishPaper(){return $this->success(app()->make(\app\services\growth\GrowthQuestionServices::class)->paper($this->context('exams'),$this->input()));}
    public function exams(){return $this->success(app()->make(\app\services\growth\GrowthExamServices::class)->listing($this->context('exams'),(array)$this->request->get()));}
    public function createExam(){return $this->success(app()->make(\app\services\growth\GrowthExamServices::class)->create($this->context('exams'),$this->input()));}
    public function attempt(){return $this->success(app()->make(\app\services\growth\GrowthExamServices::class)->view($this->context('exams'),(int)$this->request->get('id',0)));}
    public function grade(){return $this->success(app()->make(\app\services\growth\GrowthExamServices::class)->grade($this->context('exams'),$this->input()));}
    public function correctGrade(){return $this->success(app()->make(\app\services\growth\GrowthExamServices::class)->grade($this->context('exams'),$this->input(),true));}
    public function contents(){return $this->success(app()->make(GrowthContentServices::class)->listing($this->context(),(array)$this->request->get()));}
    public function content(){return $this->success(app()->make(GrowthContentServices::class)->detail($this->context(),(int)$this->request->get('id',0)));}
    public function saveContent(){return $this->success(app()->make(GrowthContentServices::class)->save($this->context(),$this->input()));}
    public function publishContent(){return $this->success(app()->make(GrowthContentServices::class)->publish($this->context(),$this->input()));}
    public function confirmContent(){return $this->success(app()->make(GrowthContentServices::class)->confirm($this->context(),$this->input()));}
    public function stopContent(){return $this->success(app()->make(GrowthContentServices::class)->stop($this->context(),$this->input()));}
    public function tasks(){return $this->success(app()->make(GrowthTaskServices::class)->listing($this->context('tasks'),(array)$this->request->get()));}
    public function task(){return $this->success(app()->make(GrowthTaskServices::class)->detail($this->context('tasks'),(int)$this->request->get('id',0)));}
    public function assignTasks(){return $this->success(app()->make(GrowthTaskServices::class)->assign($this->context('tasks'),$this->input()));}
    public function taskAction(){return $this->success(app()->make(GrowthTaskServices::class)->lifecycle($this->context('tasks'),$this->input()));}
    public function upload(){return $this->success(app()->make(GrowthAssetServices::class)->upload($this->context((string)$this->request->post('menu','content')),$this->request->file('file')));}
    public function retryAsset(){return $this->success(app()->make(GrowthAssetServices::class)->convert($this->context(),(int)$this->request->post('id',0)));}
    public function asset(){ $c=$this->context((string)$this->request->get('menu','content'));$r=app()->make(GrowthAssetServices::class)->file($c,(int)$this->request->get('id',0),(int)$this->request->get('task_id',0));return response(file_get_contents($r['path']),200)->header(['Content-Type'=>mime_content_type($r['path']),'Cache-Control'=>'private, no-store','Content-Disposition'=>'inline; filename="preview.'.pathinfo($r['path'],PATHINFO_EXTENSION).'"','X-Content-Type-Options'=>'nosniff']); }
}
