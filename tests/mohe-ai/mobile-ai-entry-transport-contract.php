<?php
/** Merchant AI bootstrap shares the same HTTP-success contract as the mobile shell.
 * The source test is intentionally static because UTS transport is compiled by
 * the WeChat toolchain, outside PHP's runtime. */
$source=file_get_contents(dirname(__DIR__,2).'/前端代码/mobile-vue3/src/shared/api/mobile-ai-client.uts');
if (!is_string($source)) throw new RuntimeException('mobile AI client source unavailable');
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition) throw new RuntimeException('FAIL '.$label); ++$checks; };

$check(strpos($source,"response.ok !== true || body == null")!==false,
    'AI bootstrap accepts the shared mobile HTTP success signal');
$check(strpos($source,"body.status !== 200")===false,
    'AI bootstrap never requires a legacy status field absent from MobileApiResponse');
$check(strpos($source,"typeof body.message == 'string'")!==false,
    'merchant-contract error messages remain visible when a request actually fails');
$check(strpos($source,'done({ok:true,data:body})')!==false,
    'AI bootstrap exposes the top-level MobileApiResponse payload to the entry');
$entry=file_get_contents(dirname(__DIR__,2).'/前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue');
$check(is_string($entry) && strpos($entry,'role="button"')!==false && strpos($entry,'aria-label="打开魔核 AI"')!==false,
    'the visible AI entry exposes an accessible interactive control');

echo 'PASS mobile AI entry transport: '.$checks." checks\n";
