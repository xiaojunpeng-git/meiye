<?php
namespace app\services\ai\context;

use app\services\ai\config\AiPrivateStorage;
use RuntimeException;

/**
 * Owns the lifecycle of a trusted prior-query reference.  It never projects
 * result data to a model and never grants authority: a restored view is
 * replayed by the caller under the authority of the current request.
 */
final class VerifiedQueryContext
{
    private $private;
    private $verifyToken;
    private $issueToken;
    private $identity;
    private $assertBinding;
    private $replayView;

    /**
     * The token/signature primitives remain shared gateway infrastructure.
     * Injecting these narrow operations keeps context-reference claims in one
     * place without introducing a second signing scheme.
     */
    public function __construct(AiPrivateStorage $private, callable $verifyToken, callable $issueToken,
        callable $identity, callable $assertBinding, callable $replayView)
    {
        $this->private=$private;
        $this->verifyToken=$verifyToken;
        $this->issueToken=$issueToken;
        $this->identity=$identity;
        $this->assertBinding=$assertBinding;
        $this->replayView=$replayView;
    }

    /** Signed evidence supplies prior conditions, never prior figures or additional data authority. */
    public function restore(array $context,array $owner,string $reference): array
    {
        $untrusted=json_decode(base64_decode(strtr(explode('.',$reference)[0],'-_','+/')),true);
        if (!is_array($untrusted) || !is_string($untrusted['window']??null)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $proof=call_user_func($this->verifyToken,$reference,$context,$untrusted['window'],'context');
        if (($proof['conversation']??'')!==$owner['conversation_id'] || !is_string($proof['evidence']??null)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $stored=$this->private->read($proof['evidence']); $originalOwner=$owner;$originalOwner['window_id']=$proof['window'];
        call_user_func($this->assertBinding,$stored,$originalOwner,$proof['run'],$proof['generation']);
        if (!isset($stored['query'],$stored['view_ref'])) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $query=$stored['query'];
        // The read view is an encrypted source-answer snapshot. It is used
        // only for a later result reference and is replayed under current
        // authority; no row, name, ID or amount is returned to the model.
        $view=call_user_func($this->replayView,$context,$query,$stored['view_ref']);
        return ['query'=>$query,'view'=>$view];
    }

    /**
     * Issues a reference only for an answer backed by a verified query. The
     * original window is retained for evidence binding; current authority is
     * deliberately checked when restore() replays the view.
     */
    public function issue(array $context,array $owner,array $run,array $stored): ?string
    {
        if (!isset($stored['query'])) return null;
        return call_user_func($this->issueToken,[
            'type'=>'context','identity'=>call_user_func($this->identity,$context),'window'=>$owner['window_id'],
            'conversation'=>$owner['conversation_id'],'run'=>$run['run_id'],'generation'=>$run['generation'],
            'evidence'=>$run['evidence_ref'],'expires'=>intdiv($run['expires_at'],1000),
        ]);
    }
}
