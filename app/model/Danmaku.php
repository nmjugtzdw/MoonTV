<?php
namespace app\model;

use think\Model;

class Danmaku extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $table = 'danmaku';
    
    // 自动时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}