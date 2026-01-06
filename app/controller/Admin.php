<?php
namespace app\controller;

use app\BaseController;
use think\facade\View;

class Admin extends BaseController
{
    public function index()
    {
        return View::fetch('admin/index');
    }

    public function login()
    {
        return View::fetch('admin/login');
    }
    
    public function users()
    {
        return View::fetch('admin/users/index');
    }

    public function vip()
    {
        return View::fetch('admin/vip/index');
    }

    public function system()
    {
        return View::fetch('admin/system/index');
    }

    public function cards()
    {
        return View::fetch('admin/cards/index');
    }

    public function orders()
    {
        return View::fetch('admin/orders/index');
    }

    public function danmaku()
    {
        return View::fetch('admin/danmaku/index');
    }
}