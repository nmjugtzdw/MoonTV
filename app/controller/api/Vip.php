<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\model\VipPackage;
use app\model\Order;
use app\model\RedemptionCode;
use app\model\User;
use app\service\ConfigService;
use app\model\SystemConfig;
use think\facade\Db;

class Vip extends BaseController
{
    protected $middleware = [
        \app\middleware\AuthCheck::class => ['except' => ['notify', 'packages']]
    ];

    /**
     * 获取套餐列表
     */
    public function packages()
    {
        $list = VipPackage::where('is_enabled', 1)->order('sort_order', 'asc')->select();
        return Ret::success($list);
    }

    /**
     * 创建订单
     */
    public function createOrder()
    {
        $packageId = input('post.package_id');
        $payType = input('post.pay_type'); // alipay, wxpay

        if (empty($packageId) || empty($payType)) {
            return Ret::error('参数错误', 400);
        }

        $package = VipPackage::find($packageId);
        if (!$package || !$package->is_enabled) {
            return Ret::error('套餐不存在或已下架', 404);
        }

        // 优先使用数据库中的系统配置
        $paymentConfig = SystemConfig::get('payment');
        
        if (empty($paymentConfig) || empty($paymentConfig['enabled'])) {
            // 尝试回退到 ConfigService
            $fileConfig = ConfigService::getConfig();
            if (empty($fileConfig['PaymentConfig']['enabled'])) {
                return Ret::error('支付功能未开启', 403);
            }
            $paymentConfig = [
                'api_url' => $fileConfig['PaymentConfig']['yipay']['apiUrl'],
                'pid'     => $fileConfig['PaymentConfig']['yipay']['merchantId'],
                'key'     => $fileConfig['PaymentConfig']['yipay']['apiKey'],
                'notify_url' => $fileConfig['PaymentConfig']['yipay']['notifyUrl'],
                'return_url' => $fileConfig['PaymentConfig']['yipay']['returnUrl'],
            ];
        }

        // 生成订单号
        $orderNo = date('YmdHis') . rand(1000, 9999);
        $uid = $this->request->uid;

        $order = Order::create([
            'order_no'     => $orderNo,
            'username'     => $this->request->username,
            'package_id'   => $package->id,
            'package_name' => $package->name,
            'days'         => $package->days,
            'amount'       => $package->price,
            'status'       => 0, // 0:待支付
            'pay_type'     => $payType
        ]);

        // 对接易支付
        // 构造兼容旧 buildYipayUrl 的配置结构
        $yipayConfig = [
            'merchantId' => $paymentConfig['pid'],
            'apiKey'     => $paymentConfig['key'],
            'apiUrl'     => $paymentConfig['api_url'],
            'notifyUrl'  => request()->domain() . '/api/vip/notify', // 自动生成回调地址
            'returnUrl'  => request()->domain() . '/user',           // 支付后跳回用户中心
        ];
        
        $payUrl = $this->buildYipayUrl($yipayConfig, $order, $payType);

        return Ret::success([
            'order_no' => $orderNo,
            'pay_url'  => $payUrl
        ]);
    }

    /**
     * 卡密兑换
     */
    public function redeem()
    {
        $code = input('post.code');
        if (empty($code)) {
            return Ret::error('请输入卡密', 400);
        }

        // 开启事务
        Db::startTrans();
        try {
            // 查询卡密 (使用 lock=true 加锁防止并发)
            $cdk = RedemptionCode::where('code', $code)->where('status', 'unused')->lock(true)->find();
            
            if (!$cdk) {
                Db::rollback();
                return Ret::error('卡密无效或已被使用', 400);
            }

            // 更新卡密状态
            $cdk->status = 'used';
            $cdk->used_by = $this->request->username;
            $cdk->used_at = date('Y-m-d H:i:s');
            $cdk->save();

            // 增加用户VIP时间 (逻辑与 Notify 保持一致)
            $user = User::find($this->request->uid);
            $days = intval($cdk->value);
            $now = time();
            $currentExpire = !empty($user->vip_expire_time) ? strtotime($user->vip_expire_time) : 0;
            
            if ($user->is_vip == 1 && $currentExpire > $now) {
                $baseTime = $currentExpire;
            } else {
                $baseTime = $now;
            }
            
            $newExpireTimestamp = $baseTime + ($days * 86400);
            $newExpireStr = date('Y-m-d H:i:s', $newExpireTimestamp);
            
            // 直接更新数据库
            User::where('id', $user->id)->update([
                'is_vip' => 1,
                'vip_expire_time' => $newExpireStr
            ]);

            Db::commit();
            return Ret::success(['added_days' => $days, 'expire_time' => $newExpireStr], '兑换成功');

        } catch (\Exception $e) {
            Db::rollback();
            return Ret::error('兑换失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 构建易支付跳转URL
     */
    protected function buildYipayUrl($config, $order, $type)
    {
        $params = [
            'pid' => $config['merchantId'],
            'type' => $type,
            'out_trade_no' => $order->order_no,
            'notify_url' => $config['notifyUrl'],
            'return_url' => $config['returnUrl'],
            'name' => $order->package_name,
            'money' => $order->amount,
            'sitename' => 'MoonTV'
        ];

        // 签名
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $signStr .= "$k=$v&";
            }
        }
        $signStr = substr($signStr, 0, -1) . $config['apiKey'];
        $params['sign'] = md5($signStr);
        $params['sign_type'] = 'MD5';

        $query = http_build_query($params);
        $apiUrl = rtrim($config['apiUrl'], '/') . '/'; // Ensure trailing slash
        return $apiUrl . 'submit.php?' . $query;
    }

    /**
     * 易支付回调
     */
    public function notify()
    {
        $params = input('get.'); // 易支付通常GET/POST回调都有可能，视接口而定
        if (empty($params)) {
            $params = input('post.');
        }

        // 获取配置
        $paymentConfig = SystemConfig::get('payment');
        $apiKey = $paymentConfig ? $paymentConfig['key'] : '';
        
        if (empty($apiKey)) {
             $config = ConfigService::getConfig();
             $apiKey = $config['PaymentConfig']['yipay']['apiKey'];
        }

        // 签名验证
        $sign = $params['sign'] ?? '';
        if (empty($sign)) {
            return 'fail'; // 无签名参数
        }
        
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $signStr .= "$k=$v&";
            }
        }
        $signStr = substr($signStr, 0, -1) . $apiKey;
        $calcSign = md5($signStr);

        if ($sign !== $calcSign) {
            trace("Pay Notify Sign Error: Calc {$calcSign} != Recv {$sign}", 'error');
            return 'fail';
        }

        $orderNo = $params['out_trade_no'];
        trace("Pay Notify Success: OrderNo {$orderNo}", 'info');
        $tradeNo = $params['trade_no'];
        $status = $params['trade_status'];

        if ($status !== 'TRADE_SUCCESS') {
            return 'success'; // 支付未成功，但也返回success告知收到了
        }

        $order = Order::where('order_no', $orderNo)->find();
        if (!$order) {
            return 'fail'; // 订单不存在
        }

        if ($order->status == 1) {
            return 'success'; // 已处理过
        }

        // 开启事务
        Db::startTrans();
        try {
            // 更新订单
            $order->status = 1;
            $order->trade_no = $tradeNo;
            $order->notify_time = date('Y-m-d H:i:s');
            $order->save();

            // 发放VIP逻辑修正
            // 必须严格匹配用户名，防止空格或大小写问题
            $user = User::where('username', $order->username)->find();

            if ($user) {
                $days = intval($order->days);
                // 确保 days 有效，防止为0
                if ($days <= 0) $days = 30; // 默认30天保底

                $now = time();
                // 强制转为整数时间戳
                $currentExpire = !empty($user->vip_expire_time) ? strtotime($user->vip_expire_time) : 0;
                
                // 关键修正：确保 currentExpire 是未来的时间才累加，否则从现在开始
                if ($user->is_vip == 1 && $currentExpire > $now) {
                    $baseTime = $currentExpire;
                } else {
                    $baseTime = $now;
                }
                
                $newExpireTimestamp = $baseTime + ($days * 86400);
                $newExpireStr = date('Y-m-d H:i:s', $newExpireTimestamp);
                
                // 使用 update 直接更新，防止模型缓存干扰
                User::where('id', $user->id)->update([
                    'is_vip' => 1,
                    'vip_expire_time' => $newExpireStr
                ]);
                
                // 同时更新 $user 对象以备后续使用（虽然这里不需要了）
                $user->is_vip = 1;
                $user->vip_expire_time = $newExpireStr;
                
                trace("User {$user->username} VIP extended to {$user->vip_expire_time}", 'info');
            } else {
                trace("Pay Notify Error: User {$order->username} not found", 'error');
            }

            Db::commit();
            return 'success';
        } catch (\Exception $e) {
            Db::rollback();
            return 'fail';
        }
    }
}