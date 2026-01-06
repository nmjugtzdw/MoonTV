<?php

namespace app\model;

use think\Model;

class SearchHistory extends Model
{
    protected $table = 'search_history';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = false;
}