<?php

namespace app\model;

use think\Model;

class PlayRecord extends Model
{
    protected $table = 'play_records';
    protected $autoWriteTimestamp = true;
    protected $createTime = false;
    protected $updateTime = 'updated_at';

    protected $json = ['data'];
}