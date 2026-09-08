<?php
namespace app\services\ai\model;
/** Synthetic live-test diagnostics only. Real transport, bounded in-memory observation. */
function curl_setopt_array($handle,array $options) {
    $GLOBALS['moheLiveResponse']='';
    $writer=$options[CURLOPT_WRITEFUNCTION];
    $options[CURLOPT_WRITEFUNCTION]=static function($h,string $chunk)use($writer):int{
        if(strlen($GLOBALS['moheLiveResponse'])+strlen($chunk)<=131072)$GLOBALS['moheLiveResponse'].=$chunk;
        return $writer($h,$chunk);
    };
    return \curl_setopt_array($handle,$options);
}
