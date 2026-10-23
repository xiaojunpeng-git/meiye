<?php
declare(strict_types=1);
namespace app\services\growth;

use think\facade\Db;
use mohe\exceptions\AdminException;

/** 原件与预览均在私有目录；每次读取重新检查关联任务或内容权限。 */
final class GrowthAssetServices
{
    /** 课程文件限制50MB；识别实际媒体信息，转换失败保留状态供重试。 */
    public function upload(array $c,$file): array
    {
        if(!$file)throw new AdminException('请选择文件');
        $name=basename((string)$file->getOriginalName());$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['ppt','pptx','doc','docx','pdf','mp4','webm','jpg','jpeg','png','webp'],true))throw new AdminException('支持PPT、Word、PDF、MP4/WebM与常见图片');
        $size=(int)$file->getSize();if($size<=0||$size>50*1024*1024)throw new AdminException('文件须在50MB以内');
        $key=bin2hex(random_bytes(20)).'.'.$ext;$root=$this->root();
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new AdminException('无法创建私有资源目录');
        if(!copy($file->getRealPath(),$root.'/'.$key))throw new AdminException('文件保存失败');chmod($root.'/'.$key,0600);
        $id=(int)Db::name('growth_asset')->insertGetId(['owner_key'=>$c['actor_key'],'original_name'=>mb_substr($name,0,255),'format'=>$ext,'size_bytes'=>$size,'storage_key'=>$key,'state'=>'PROCESSING','metadata_json'=>'{}','created_at'=>time()]);
        return $this->convert($c,$id);
    }

    /** 实际识别媒体并转换可读预览；失败保留原件与失败状态供重试。 */
    public function convert(array $c,int $id): array
    {
        $row=$this->owned($c,$id);$path=$this->root().'/'.$row['storage_key'];$ext=$row['format'];
        try {
            $metadata=[];$preview=$row['storage_key'];$pages=0;$duration=0;
            $this->assertDocumentFormat($path,$ext);
            if(in_array($ext,['png','jpg','jpeg','webp'],true)) {
                $image=@getimagesize($path);if(!$image||$image[0]*$image[1]>80000000)throw new \RuntimeException('图片损坏或像素超过8000万');
                $metadata=['width'=>$image[0],'height'=>$image[1]];
            } elseif(in_array($ext,['mp4','webm'],true)) {
                $output=[];$exit=0;exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($path).' 2>/dev/null',$output,$exit);
                $duration=(int)ceil((float)($output[0]??0));if($exit!==0||$duration<=0)throw new \RuntimeException('视频无法识别，请使用有效MP4或WebM');
            } else {
                if($ext!=='pdf') {
                    $output=[];$exit=0;$profile=$this->root().'/profile-'.bin2hex(random_bytes(8));
                    exec('timeout 90 soffice -env:UserInstallation=file://'.$profile.' --headless --convert-to pdf --outdir '.escapeshellarg($this->root()).' '.escapeshellarg($path).' 2>&1',$output,$exit);
                    $preview=pathinfo($row['storage_key'],PATHINFO_FILENAME).'.pdf';
                    if($exit!==0||!is_file($this->root().'/'.$preview))throw new \RuntimeException('文档预览转换失败，请检查是否加密或损坏后重试');
                }
                $output=[];$exit=0;exec('pdfinfo '.escapeshellarg($this->root().'/'.$preview).' 2>/dev/null',$output,$exit);
                foreach($output as $line)if(preg_match('/^Pages:\s+(\d+)/',$line,$m))$pages=(int)$m[1];
                if($exit!==0||$pages<=0||$pages>500)throw new \RuntimeException('PDF不可读、已加密或超过500页');
            }
            Db::name('growth_asset')->where('id',$id)->update(['state'=>'READY','error_message'=>'','preview_key'=>$preview,'page_count'=>$pages,'duration_seconds'=>$duration,'metadata_json'=>GrowthCommandServices::json($metadata)]);
        } catch(\Throwable $e) { Db::name('growth_asset')->where('id',$id)->update(['state'=>'FAILED','error_message'=>mb_substr($e->getMessage(),0,255)]); }
        if(isset($profile))$this->removeConversionProfile($profile);
        return $this->summary($this->owned($c,$id));
    }

    /** 转换器能把伪装的纯文本当文档打开；先校验容器结构，避免把损坏或错格式文件发布。 */
    private function assertDocumentFormat(string $path,string $ext): void
    {
        if(in_array($ext,['docx','pptx'],true)){
            $zip=new \ZipArchive();if($zip->open($path)!==true)throw new \RuntimeException('文档格式损坏、已加密或与扩展名不符');
            $main=$ext==='docx'?'word/document.xml':'ppt/presentation.xml';$valid=$zip->locateName('[Content_Types].xml')!==false&&$zip->locateName($main)!==false;$zip->close();
            if(!$valid)throw new \RuntimeException('文档格式损坏或与扩展名不符');
        }elseif(in_array($ext,['doc','ppt'],true)){
            $handle=fopen($path,'rb');$signature=$handle?fread($handle,8):'';if($handle)fclose($handle);
            if($signature!==hex2bin('d0cf11e0a1b11ae1'))throw new \RuntimeException('旧版文档格式损坏或与扩展名不符');
        }
    }

    /** 资源管理按上传者或超级管理员校验，不接受客户端存储路径。 */
    public function owned(array $c,int $id): array
    {
        $row=Db::name('growth_asset')->where('id',$id)->find();
        if(!$row||(!$c['super']&&$row['owner_key']!==$c['actor_key']))throw new AdminException('资源不存在或不在当前范围');
        return $row;
    }

    /** 员工仅能读取分配给自己的冻结课程，猜资源编号不能获取其他附件。 */
    public function readable(array $c,int $id,int $taskId=0): array
    {
        try{return $this->owned($c,$id);}catch(AdminException $e){}
        // 外部证书没有学习任务，其附件从证书正式归属校验，不能借空task_id公开访问。
        if($taskId<=0){$cert=app()->make(GrowthAccessServices::class)->rewardQuery($c,'growth_certificate')->whereRaw("JSON_CONTAINS(JSON_EXTRACT(r.evidence_json,'$.asset_ids'),?)=1",[json_encode($id)])->find();if(!$cert)throw new AdminException('资源未关联当前可见业务');$row=Db::name('growth_asset')->where('id',$id)->find();if(!$row)throw new AdminException('证据资源不存在');return $row;}
        $task=app()->make(GrowthTaskServices::class)->task($c,$taskId);
        if($task['kind']!=='course'&&Db::name('growth_asset_reference')->where('asset_id',$id)->where('task_id',$taskId)->find()){
            $row=Db::name('growth_asset')->where('id',$id)->find();if(!$row)throw new AdminException('证据资源不存在');return $row;
        }
        $publication=Db::name('growth_publication')->where('id',$task['publication_id'])->find();
        $snapshot=GrowthCommandServices::decode((string)($publication['snapshot_json']??''));
        if(!in_array($id,array_map('intval',array_merge(array_column($snapshot['chapters']??[],'asset_id'),$snapshot['material_asset_ids']??[],[(int)($snapshot['cover_asset_id']??0)])),true))throw new AdminException('资源未关联当前学习任务');
        $row=Db::name('growth_asset')->where('id',$id)->find();if(!$row)throw new AdminException('资源不存在');return $row;
    }

    /** 每次读取重新授权，原件与预览沿用同一私有资源边界。 */
    public function file(array $c,int $id,int $taskId,bool $original=false): array
    {
        $row=$this->readable($c,$id,$taskId);if($row['state']!=='READY')throw new AdminException('资源尚未可读');
        $key=$original?$row['storage_key']:$row['preview_key'];$path=$this->root().'/'.basename($key);
        if(!is_file($path))throw new AdminException('资源文件缺失，请联系维护人');
        return ['path'=>$path,'name'=>$original?$row['original_name']:('课程预览.'.pathinfo($key,PATHINFO_EXTENSION))];
    }

    /** 仅返回资源安全元数据，不暴露私有磁盘路径。 */
    public function summary(array $row): array {return array_intersect_key($row,array_flip(['id','original_name','format','size_bytes','state','error_message','page_count','duration_seconds']));}
    /** 正式证据必须先通过拥有或已授权关联校验；客户端不能仅填写其他人的资源编号。 */
    public function validateEvidence(array $c,array $ids,int $taskId=0): array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));if(count($ids)>20)throw new AdminException('每次最多20份证据');
        foreach($ids as $id){try{$row=$this->owned($c,$id);}catch(AdminException $e){if(!$taskId)throw $e;$row=$this->readable($c,$id,$taskId);}if($row['state']!=='READY')throw new AdminException('证据文件尚未可用');}return $ids;
    }
    /** 正式业务事务内固定证据引用，重复关联由唯一键防重。 */
    public function reference(array $ids,string $type,int $id,int $taskId): void
    {
        foreach($ids as $asset)Db::name('growth_asset_reference')->insert(['asset_id'=>$asset,'reference_type'=>$type,'reference_id'=>$id,'task_id'=>$taskId,'created_at'=>time()]);
    }
    /** 隔离本地实例通过环境目录分离，路径不来自客户端输入。 */
    /** 仅清理本次随机Office转换目录，原件与正式预览仍由业务引用管理。 */
    private function removeConversionProfile(string $dir): void
    {
        if(dirname($dir)!==$this->root()||!preg_match('/^profile-[a-f0-9]{16}$/D',basename($dir))||!is_dir($dir))return;
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){if($file->isLink()||$file->isFile())@unlink($file->getPathname());elseif($file->isDir())@rmdir($file->getPathname());}@rmdir($dir);
    }
    private function root(): string {return rtrim((string)env('growth.asset_root',app()->getRuntimePath().'private/growth'),'/');}
}
