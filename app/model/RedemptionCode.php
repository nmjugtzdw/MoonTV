<?php

namespace app\model;

use think\Model;

class RedemptionCode extends Model
{
    protected $table = 'redemption_codes';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = false;
}