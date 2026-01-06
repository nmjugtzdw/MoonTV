<?php

namespace app\model;

use think\Model;

class VipPackage extends Model
{
    protected $table = 'vip_packages';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}