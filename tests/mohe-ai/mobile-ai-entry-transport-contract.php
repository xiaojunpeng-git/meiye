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
$check(is_string($entry) && strpos($entry,"currentMobilePlatform() === 'MP_WEIXIN'")!==false
    && strpos($entry,"'ai-panel--native-mini': platformUsesNativeCanvas")!==false,
    'the WeChat panel applies its capsule-safe layout only on the native mini-program target');
$check(is_string($entry) && strpos($entry,'.ai-panel--native-mini{padding-top:84px}')!==false,
    'the native mini-program panel reserves the capsule strip before rendering AI actions');
$check(is_string($entry) && strpos($entry,'class="ai-user-turn"')!==false
    && strpos($entry,'>你问</text>')!==false
    && strpos($entry,'class="ai-answer-turn"')!==false
    && strpos($entry,'>魔核 AI</text>')!==false,
    'each retained turn labels the customer question and the AI answer as separate visual blocks');
$check(is_string($entry) && strpos($entry,"runtimeKey = 'mohe-ai:v1:runtime:'")!==false
    && strpos($entry,'function persistActive(retired : boolean = false,claim : boolean = false,replaceResolved : boolean = false)')!==false && strpos($entry,'function resumeActive(record : any)')!==false,
    'an accepted or pending Run survives a panel reload long enough to resume or cancel it');
$check(is_string($entry) && strpos($entry,'if (closeRequested) { cancelRun(); return }')!==false
    && strpos($entry,"pending.client_session_id = sessionId; pending.window_token = bootstrap.window_token")!==false,
    'a restored closed panel replays only the idempotent admission and then cancels the recovered Run');
$check(is_string($entry) && strpos($entry,'clearActive(); progress.value = r.message')!==false,
    'known admission failures clear only the stale local task record instead of blocking the next question');
$check(is_string($entry) && strpos($entry,'function needsCompatibilityExecution(value : any)')!==false
    && strpos($entry,"value.execution_mode == 'compatibility'")!==false
    && strpos($entry,'function executeCompatibility(source : any,expectedLifecycle : number)')!==false
    && strpos($entry,"'/runs/' + source.run_id + '/execute'")!==false
    && strpos($entry,'New servers accept only after durable queueing.')!==false,
    'the device uses per-Run execution mode, not a stale bootstrap snapshot, and keeps compatibility for staged servers');
$check(is_string($entry) && strpos($entry,'A reload often reads exactly the last persisted version.')!==false
    && strpos($entry,"if (!r.responseKnown) { progress.value = closeRequestedState.value")!==false,
    'a same-version or unknown-network resume preserves the active Run and continues status confirmation');
$check(is_string($entry) && strpos($entry,"queuedClarificationId == run.clarification.id")!==false
    && strpos($entry,"run.clarification_rejected === true")!==false,
    'a durably queued choice keeps polling, and a worker-rejected choice re-opens the same guidance instead of hiding it forever');
$check(is_string($entry) && strpos($entry,'queued_clarification_id:queuedClarificationId')!==false
    && strpos($entry,"queuedClarificationId = typeof record.queued_clarification_id")!==false,
    'a queued clarification marker survives a panel reload and is restored before the status confirmation');
$check(is_string($entry) && strpos($entry,'guidance_submission:guidanceSubmission')!==false
    && strpos($entry,'function validStoredGuidanceSubmission')!==false,
    'a clarification submission is persisted before transport and is only restored when its local envelope is well formed');
$check(is_string($entry) && strpos($entry,'function ownsActiveRecord()')!==false
    && strpos($entry,"stored.owner_id != null && stored.owner_id != entryId")!==false
    && strpos($entry,'owner_id:entryId')!==false
    && strpos($entry,'function mayWriteActiveRecord(claim : boolean = false,replaceResolved : boolean = false)')!==false
    && strpos($entry,'persistActive(retired : boolean = false,claim : boolean = false,replaceResolved : boolean = false)')!==false
    && strpos($entry,'clearActive(force : boolean = false)')!==false
    && strpos($entry,'onUnmounted(() => { lifecycle++; persistActive(true)')!==false
    && strpos($entry,'onUnmounted(() => { cancelRun()')===false,
    'page navigation preserves only the owning component’s Run and does not turn component disposal into a customer cancellation');
$check(is_string($entry) && strpos($entry,'let isNewRequest = pending == null')!==false
    && strpos($entry,'let restoringCancelledRequest = !isNewRequest && closeRequested')!==false,
    'a fresh question after an idle panel close clears the old cancellation intent while recovery keeps it');
$check(is_string($entry) && strpos($entry,'if (unavailable(r)) { abandonUnavailableRun(); return }')!==false
    && strpos($source,"reason:reason")!==false,
    'an expired or inaccessible Run clears local recovery state instead of polling forever');
$check(is_string($entry) && strpos($entry,'if (pending != null) executeCompatibility(run,expectedLifecycle)')!==false,
    'a staged-server Run can resume its already persisted execution input after a page reload');
$check(is_string($entry) && strpos($entry,'if (r.responseKnown) { guidanceSubmission = null; guidanceUnknown.value = false; progress.value = r.message; persistActive() }')!==false,
    'a known rejected clarification clears its local retry envelope before a later page restore');
$gateway=file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/ai/AiGatewayServices.php');
$check(is_string($gateway) && strpos($gateway,'clarificationSubmissionState')!==false
    && strpos($gateway,"if (\$state!=='new') return \$this->runs->get")!==false,
    'a delayed known clarification observes the current Run instead of replacing a newer queue envelope');

echo 'PASS mobile AI entry transport: '.$checks." checks\n";
