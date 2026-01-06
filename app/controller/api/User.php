<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\model\User as UserModel;
use think\facade\Db;

class User extends BaseController
{
    protected $middleware = [\app\middleware\AuthCheck::class];

    /**
     * 每日签到
     */
    public function checkin()
    {
        $uid = $this->request->uid;
        $user = UserModel::find($uid);
        
        if (!$user) {
            return Ret::error('用户不存在', 404);
        }

        // 检查最后签到时间
        // 注意：数据库字段是 last_checkin_at TIMESTAMP
        // 获取今日日期
        $today = date('Y-m-d');
        $lastCheckin = $user->last_checkin_at ? date('Y-m-d', strtotime($user->last_checkin_at)) : '';

        if ($lastCheckin === $today) {
            return Ret::error('今日已签到，请明天再来', 400);
        }

        // 开启事务
        Db::startTrans();
        try {
            // 赠送天数 (默认1天)
            $days = 1;
            
            // 更新签到时间和VIP时间
            $user->last_checkin_at = date('Y-m-d H:i:s');
            
            // 增加VIP时间
            $now = time();
            if ($user->is_vip && strtotime($user->vip_expire_time) > $now) {
                // 已有VIP，顺延
                $newExpire = date('Y-m-d H:i:s', strtotime($user->vip_expire_time) + $days * 86400);
            } else {
                // 无VIP或已过期，从现在开始
                $newExpire = date('Y-m-d H:i:s', $now + $days * 86400);
            }
            
            $user->is_vip = 1;
            $user->vip_expire_time = $newExpire;
            $user->save();

            Db::commit();
            return Ret::success([
                'days' => $days,
                'expire_time' => $newExpire
            ], '签到成功，VIP +1天');

        } catch (\Exception $e) {
            Db::rollback();
            return Ret::error('签到失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取签到状态
     */
    public function checkinStatus()
    {
        $uid = $this->request->uid;
        $user = UserModel::find($uid);
        
        if (!$user) {
            return Ret::error('用户不存在', 404);
        }
        
        $today = date('Y-m-d');
        $lastCheckin = $user->last_checkin_at ? date('Y-m-d', strtotime($user->last_checkin_at)) : '';
        
        return Ret::success([
            'is_checked' => $lastCheckin === $today
        ]);
    }
}