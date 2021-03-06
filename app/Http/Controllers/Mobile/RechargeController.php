<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Common\BaseController;
use App\Models\Common\Pay;
use App\Models\User\Recharge;
use EasyWeChat\Payment\Order as WxOrder;
use Illuminate\Http\Request;
use Omnipay\Omnipay;
use Storage;

class RechargeController extends BaseController
{
    // 充值
    public function getRecharge()
    {
        $pos_id = 'center';
        $title = '余额充值';
        // 取出来支付方式，不能用余额支付
        $paylist = Pay::where('status',1)->where('paystatus',1)->where('id','!=',3)->orderBy('id','asc')->get();
        return view($this->theme.'.user.recharge',compact('pos_id','title','paylist'));
    }
    public function postRecharge(Request $req)
    {
        try {
            if ($req->money <= 0) {
                return back()->with('message','充值金额不能小于0元！');
            }
            if ($req->pay == '') {
                return back()->with('message','请先选择正确的充值方式！');
            }
            $pay = Pay::findOrFail($req->pay);
            if ($pay->status !== 1 || $pay->paystatus !== 1) {
                return back()->with('message','请先选择正确的充值方式！');
            }
            $data = ['user_id'=>session('member')->id,'order_id'=>app('com')->orderid(),'money'=>$req->money];
            $res = Recharge::create($data);
            $pmod = $pay->code;
            $ip = $req->ip();
            return $this->$pmod($res->id,$pay,$ip);
          // return redirect(url('recharge/pay',['rid'=>$res->id]));
        } catch (\Exception $e) {
            dd($e);
            return back()->with('message','提交失败，稍后再试！');
        }
    }
    // 支付宝支付
    private function alipay($oid,$pay,$ip = '')
    {
        $set = json_decode($pay->setting);
        // 手机网站支付NEW
        $gateway = Omnipay::create('Alipay_AopWap');
        $gateway->setSignType('RSA'); //RSA/RSA2
        $gateway->setAppId($set->alipay_appid);
        $gateway->setPrivateKey(config('alipay.privatekey'));
        $gateway->setAlipayPublicKey(config('alipay.publickey'));
        $gateway->setReturnUrl(config('app.url').'/alipay/recharge_return');
        $gateway->setNotifyUrl(config('app.url').'/alipay/recharge_gateway');
        // 查订单信息
        $order = Recharge::findOrFail($oid);

        $request = $gateway->purchase()->setBizContent([
          'out_trade_no' => $order->order_id,
          'subject'      => cache('config')['title'].'充值订单',
          'total_amount'    => "$order->money",
          'product_code' => 'QUICK_WAP_PAY',
        ]);

        /**
         * @var LegacyExpressPurchaseResponse $response
         */
        $response = $request->send();

        // 下单后跳转到支付页面
        // $redirectUrl = $response->getRedirectUrl();
        //or 
        $response->redirect();
    }


    // 微信支付js
    private function weixin($oid,$pay,$ip)
    {
        try {
            $wechat = app('wechat');
            $payment = $wechat->payment;
            $openid = session('member')->openid;
            $order = Recharge::findOrFail($oid);
            // $price * 100
            $attributes = [
                'trade_type'       => 'JSAPI', // JSAPI，NATIVE，APP...
                'body'             => cache('config')['title'].'充值订单',
                'detail'           => cache('config')['title'].'充值订单',
                'out_trade_no'     => $order->order_id,
                'total_fee'        => $order->money * 100, // 单位：分
                'notify_url'       => config('app.url').'/weixin/recharge_return', // 支付结果通知网址，如果不设置则会使用配置里的默认地址
                'openid'           => $openid, // trade_type=JSAPI，此参数必传，用户在商户appid下的唯一标识，
            ];
            $wxorder = new WxOrder($attributes);
            $result = $payment->prepare($wxorder);
            $prepayId = $result->prepay_id;
            $config = $payment->configForJSSDKPayment($prepayId);
            $js = $wechat->js;
            $pos_id = 'center';
            $title = '会员充值-微信支付';
            return view($this->theme.'.pay.recharge_wxpay',compact('title','pos_id','config','js','oid','order'));
        } catch (\Exception $e) {
            // dd($e);
            Storage::disk('log')->prepend('weixin.log',json_encode($e->getData()).date('Y-m-d H:i:s'));
            return back()->with('message','微信支付失败，请稍后再试！');
        }
    }
}
