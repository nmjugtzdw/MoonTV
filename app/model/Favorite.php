<?php

namespace app\model;

use think\Model;

class Favorite extends Model
{
    protected $table = 'favorites';
    protected $autoWriteTimestamp = true;
    protected $createTime = false;
    protected $updateTime = 'updated_at';

    protected $json = ['data'];
}