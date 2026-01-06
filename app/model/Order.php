<?php

namespace app\model;

use think\Model;

class Order extends Model
{
    protected $table = 'orders';
    protected $autoWriteTimestamp = true;
    // 修正：数据库字段名是 `created_at` 和 `updated_at`
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
    // protected $type = [
    //     'created_at' => 'timestamp',
    //     'updated_at' => 'timestamp',
    // ];
}