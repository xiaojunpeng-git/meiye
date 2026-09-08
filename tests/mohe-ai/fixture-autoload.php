<?php
// Local class loading only. Never bootstrap the application or load an environment file.
spl_autoload_register(static function(string $class): void {
    if (strpos($class,'app\\services\\')!==0 || !preg_match('/^[A-Za-z0-9_\\\\]+$/D',$class)) return;
    $relative=str_replace('\\','/',substr($class,4));
    $file=dirname(__DIR__,2).'/后端代码/app/'.$relative.'.php';
    if (is_file($file)) require_once $file;
});
