<?php
namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\store\StoreProfileSettingsServices;
use app\services\system\attachment\SystemAttachmentServices;
use think\facade\Db;

/** V3-only adapter: middleware owns store/role identity; clients supply no store ID. */
final class StoreProfileSettings extends AuthController
{
    private function identity(): array
    {
        $id=(int)($this->request->storeId??0);if($id<=0&&$this->request->hasMacro('storeId'))$id=(int)$this->request->storeId();
        if($id<=0)throw new \RuntimeException('缺少会话门店，请重新登录。');
        $info=$this->request->hasMacro('cashierInfo')?(array)$this->request->cashierInfo():(array)($this->request->cashierInfo??[]);
        return [$id,['id'=>(int)($info['id']??$info['staff_id']??$info['employee_id']??0),'employee_id'=>(int)($info['employee_id']??0),'phone'=>(string)($info['phone']??''),'name'=>(string)($info['staff_name']??$info['name']??$info['account']??'')],empty($info['_cashier_v3_delegated'])];
    }

    /** Settings-only header metadata does not rebuild financial workbench state.
     * New stores can maintain basics before a product catalog is initialized.
     */
    private function viewContext(int $storeId,array $operator,bool $canEdit): array
    {
        $positions=Db::name('staff_job_position')->alias('j')->join('position p','p.id=j.position_id')
            ->where('j.employee_id',$operator['employee_id'])->where('j.store_id',$storeId)->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)->where('p.status',1)->column('p.name');
        return ['can_edit'=>$canEdit,'map_key'=>(string)sys_config('tengxun_map_key',''),
            'navigation'=>['rules'=>(new \app\services\organization\StorePermissionCatalogServices())->snapshot()['rules']],
            'operator'=>['name'=>$operator['name'],'phone'=>$operator['phone'],'positions'=>array_values(array_unique($positions))]];
    }

    public function index()
    {
        [$id,$operator,$canEdit]=$this->identity();
        $data=(new StoreProfileSettingsServices())->read($id)+$this->viewContext($id,$operator,$canEdit);
        return $this->success('ok',$data);
    }

    /** Entire request is allowlisted; attempted organization/store/finance writes fail. */
    public function save()
    {
        try{
            [$id,$operator,$canEdit]=$this->identity();if(!$canEdit)return $this->fail('当前为查看模式，不能保存门店资料。');
            $body=$this->request->post();
            // ForceStoreSession adds this key after rejecting foreign stores. Only
            // its explicit injected marker may remove it from the client allowlist.
            if(!empty($this->request->forceStoreSessionInjectedPostStoreId))unset($body['store_id']);
            if(array_diff(array_keys($body),['profile','expected_version','request_token']))return $this->fail('包含不允许修改的参数。');
            if(!is_array($body['profile']??null)||!is_string($body['expected_version']??null)||!is_string($body['request_token']??null))return $this->fail('门店资料参数无效。');
            $data=(new StoreProfileSettingsServices())->save($id,$operator,$body['profile'],$body['expected_version'],$body['request_token']);
            $data+=$this->viewContext($id,$operator,true);
            return $this->success('门店资料已保存',$data);
        }catch(\think\exception\ValidateException $e){return $this->fail($e->getMessage());}
        catch(\Throwable $e){return $this->fail('门店资料未能保存，请稍后重试；若持续失败，请联系管理员。');}
    }

    /** Same public region dictionary as platform, queried lazily per parent. */
    public function cities()
    {
        $this->identity();$pid=(int)$this->request->param('pid',0);if($pid<0)return $this->fail('地区参数无效。');
        return $this->success('ok',Db::name('city_area')->where('parent_id',$pid)->field('id,name')->order('id')->select()->toArray());
    }

    /** Reuse instance upload/storage pipeline, explicitly bound to session store.
     * Accept image bytes only; no arbitrary URL fetch, SVG or client-chosen owner.
     */
    public function upload()
    {
        try{
            [$id,$operator,$canEdit]=$this->identity();if(!$canEdit)return $this->fail('当前为查看模式，不能上传照片。');
            $file=$this->request->file('file');if(!$file||$file->getSize()>10*1024*1024)return $this->fail('请选择不超过10MB的图片。');
            $info=@getimagesize($file->getPathname());if(!$info||!in_array($info['mime']??'',['image/jpeg','image/png','image/gif','image/webp'],true))return $this->fail('仅支持JPG、PNG、GIF或WebP图片。');
            $src=app()->make(SystemAttachmentServices::class)->storeUpload(0,'file',$id);
            return $this->success('照片已上传',['src'=>$src]);
        }catch(\Throwable $e){return $this->fail('照片上传失败，请稍后重试。');}
    }
}
