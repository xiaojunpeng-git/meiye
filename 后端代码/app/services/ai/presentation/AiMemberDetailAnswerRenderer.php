<?php
namespace app\services\ai\presentation;

use RuntimeException;

/**
 * Presents the read-only member projection returned by the authoritative
 * cashier service. It exposes business summaries and rights only; private
 * contact/profile fields and internal identifiers never enter the AI answer.
 */
final class AiMemberDetailAnswerRenderer
{
    /** One bounded population, two tables at most; never one UI section per person. */
    public function renderSet(array $members,string $view): array
    {
        $rows=[];$rights=[];$rightColumns=[];
        foreach ($members as $member) {
            $answer=$this->render($member['detail'],$member['label'],$view);
            $row=['member'=>$member['label']];
            foreach ($answer['presentation']['facts'] as $i=>$fact) $row['f'.$i]=$fact['value'];
            $rows[]=$row;
            if (isset($answer['table'])) {
                $rightColumns=$answer['table']['columns'];
                foreach ($answer['table']['rows'] as $right) $rights[]=['member'=>$member['label']]+$right;
            }
        }
        if ($rows===[]) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $columns=[['key'=>'member','label'=>'会员']];
        foreach (['账户余额（元）','本金余额（元）','赠送余额（元）','有效卡项（张）','剩余项目（次）','剩余项目金额（元）'] as $i=>$label) {
            $columns[]=['key'=>'f'.$i,'label'=>$label];
        }
        $summary=['summary'=>'共'.count($rows).'位会员的当前权益，包含其所有门店权益。','cards'=>[],
            'table'=>['columns'=>$columns,'rows'=>$rows]];
        if ($view!=='rights'||$rights===[]) return $summary;
        return ['summary'=>$summary['summary'],'cards'=>[],'sections'=>[
            ['id'=>'q1','title'=>'会员权益汇总','answer'=>$summary],
            ['id'=>'q2','title'=>'会员卡项明细','answer'=>['summary'=>'各会员的有效卡项明细如下。','cards'=>[],
                'table'=>['columns'=>array_merge([['key'=>'member','label'=>'会员']],$rightColumns),'rows'=>$rights]]],
        ]];
    }

    public function render(array $detail,string $expectedLabel,string $view): array
    {
        if (($detail['projectionContractVersion']??null)!=='cashier-v3-member-detail-v3'
            || !is_array($detail['member']??null)||!is_array($detail['summary']??null)
            || !is_array($detail['cards']??null)||!in_array($view,['rights','summary'],true)) {
            throw new RuntimeException('AI_EVIDENCE_INVALID');
        }
        $member=$detail['member'];$summary=$detail['summary'];$name=trim((string)($member['name']??''));
        if ($name===''||$name!==$expectedLabel) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $facts=[
            ['label'=>'账户余额','value'=>$this->yuan($summary['accountBalance']??null),'unit'=>'元'],
            ['label'=>'本金余额','value'=>$this->yuan($summary['principalBalance']??null),'unit'=>'元'],
            ['label'=>'赠送余额','value'=>$this->yuan($summary['giftBalance']??null),'unit'=>'元'],
            ['label'=>'有效卡项','value'=>(string)$this->nonnegativeInt($summary['activeCardCount']??null),'unit'=>'张'],
            ['label'=>'剩余项目','value'=>(string)$this->nonnegativeInt($summary['remainingProjectTimes']??null),'unit'=>'次'],
            ['label'=>'剩余项目金额','value'=>$this->yuan($summary['remainingProjectAmount']??null),'unit'=>'元'],
        ];
        $answer=['summary'=>$name.'的会员权益如下。','cards'=>[],
            'presentation'=>['version'=>1,'headline'=>$name.'的会员权益','facts'=>$facts,'period_label'=>'数据截至：'.(string)($detail['dataAsOf']??''),'notes'=>[]]];
        if ($view==='rights') {
            $rows=[];
            foreach ($detail['cards'] as $card) {
                if (!is_array($card)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                $rows[]=['card'=>(string)($card['cardName']??'会员卡项'),'status'=>(string)($card['statusLabel']??''),
                    'remaining_times'=>(string)$this->nonnegativeInt($card['remainingTimes']??null),
                    'remaining_amount'=>$this->yuan($card['remainingAmount']??null),
                    'expires_at'=>(string)($card['expiresAt']??'长期有效')];
            }
            if ($rows!==[]) $answer['table']=['columns'=>[
                ['key'=>'card','label'=>'卡项'],['key'=>'status','label'=>'状态'],
                ['key'=>'remaining_times','label'=>'剩余次数'],['key'=>'remaining_amount','label'=>'剩余金额（元）'],
                ['key'=>'expires_at','label'=>'有效期'],
            ],'rows'=>$rows];
        }
        return $answer;
    }

    /** Money is read as exact yuan text and rounded for the product-wide display rule. */
    private function yuan($value): string
    {
        if (!is_string($value)&&!is_int($value)) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $text=(string)$value;
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D',$text)) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $negative=$text[0]==='-';$unsigned=$negative?substr($text,1):$text;
        [$whole,$fraction]=array_pad(explode('.',$unsigned,2),2,'0');
        $rounded=(int)$whole+((int)str_pad($fraction,2,'0')>=50?1:0);
        return ($negative&&$rounded!==0?'-':'').number_format($rounded,0,'.',',');
    }

    private function nonnegativeInt($value): int
    {
        if (!is_int($value)||$value<0) throw new RuntimeException('AI_EVIDENCE_INVALID');
        return $value;
    }
}
