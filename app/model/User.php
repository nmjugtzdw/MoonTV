<?php

namespace app\model;

use think\Model;

class User extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $table = 'users';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = false; // users表没有updated_at字段，根据sql看只有created_at

    /**
     * 检查用户是否VIP
     * @return bool
     */
    public function isVip()
    {
        // 管理员直接视为VIP
        if ($this->role === 'admin') {
            return true;
        }

        if ($this->is_vip) {
            // 检查过期时间
            if ($this->vip_expire_time && strtotime($this->vip_expire_time) > time()) {
                return true;
            }
            // 如果过期，自动更新状态（可选，或者在查询时判断）
            return false;
        }
        return false;
    }
}