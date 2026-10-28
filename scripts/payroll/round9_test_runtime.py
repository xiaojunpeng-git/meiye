"""固定工作树业务类加载器；不混用Docker挂载的另一任务源码。仅作为一次性测试输入。"""
import base64
import hashlib
import json

def prelude(root):
    backend=root/'后端代码'
    classes={}
    for namespace,directory in [('app',backend/'app'),('crmeb',backend/'crmeb')]:
        for file in directory.rglob('*.php'):
            name=namespace+'\\'+str(file.relative_to(directory).with_suffix('')).replace('/','\\')
            body=file.read_bytes()
            classes[name]=[base64.b64encode(body).decode(),hashlib.sha256(body).hexdigest()]
    encoded=base64.b64encode(json.dumps(classes).encode()).decode()
    return """<?php
chdir('/var/www/html');require 'vendor/autoload.php';
$GLOBALS['fixed_source_classes']=json_decode(base64_decode('"""+encoded+"""'),true);
$GLOBALS['fixed_source_loaded']=[];
spl_autoload_register(static function($class){
 $source=$GLOBALS['fixed_source_classes'][$class]??null;
 if($source===null)return;
 eval(substr(base64_decode($source[0]),5));
 $GLOBALS['fixed_source_loaded'][$class]=$source[1];
},true,true);
eval('namespace app\\\\services\\\\payroll; function sys_config($key,$default=0){return $GLOBALS["payroll_test_mode"]??0;}');
"""
