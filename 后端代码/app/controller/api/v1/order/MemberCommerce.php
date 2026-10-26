<?php
namespace app\controller\api\v1\order;

use app\Request;
use app\services\member\MemberCommerceServices;

/** Auth middleware supplies the UID; member IDs and money authority are never accepted. */
final class MemberCommerce
{
    private function input(Request $request): array
    {
        return $request->postMore([['store_id',0], ['kind','purchase'], ['items',[]], ['cart_ids',''],
            ['salespeople',[]], ['coupon_id',0], ['use_integral',0],
            ['payment_mode',''], ['balance_yuan','0'], ['price','0'],
            ['package_id',0], ['fingerprint',''], ['idempotency_key',''], ['external_method','wechat']]);
    }

    public function quote(Request $request)
    {
        return app('json')->success((new MemberCommerceServices())->quote((int)$request->uid(), $this->input($request)));
    }

    public function cartEntry(Request $request)
    {
        return app('json')->success((new MemberCommerceServices())->cartEntry((int)$request->uid(), (string)$request->get('cart_ids','')));
    }

    public function create(Request $request)
    {
        $channel = strtolower((string)$request->header('Form-type', 'h5'));
        if ($channel === 'app-plus') $channel = 'app';
        return app('json')->success((new MemberCommerceServices())->create((int)$request->uid(), $this->input($request), $channel));
    }

    public function detail(Request $request, string $id)
    {
        return app('json')->success((new MemberCommerceServices())->detail((int)$request->uid(), $id));
    }

    public function listing(Request $request)
    {
        return app('json')->success((new MemberCommerceServices())->listing((int)$request->uid(),
            $request->getMore([['page',1], ['status',''], ['keyword','']])));
    }

    public function pay(Request $request, string $id)
    {
        return app('json')->success((new \app\services\member\MemberCommercePaymentServices())->pay((int)$request->uid(), $id));
    }

    public function cancel(Request $request, string $id)
    {
        return app('json')->success((new MemberCommerceServices())->cancel((int)$request->uid(), $id));
    }
}
