<?php
namespace app\controller;

use app\BaseController;
use think\facade\View;
use app\service\SearchService;
use app\service\ConfigService;

class Index extends BaseController
{
    public function index()
    {
        View::assign('title', 'MoonTV - 首页');
        return View::fetch('index/index');
    }

    public function list($type = 'movie')
    {
        $titleMap = [
            'movie' => '电影',
            'tv' => '电视剧',
            'anime' => '动漫',
            'show' => '综艺',
            'variety' => '综艺', // 兼容 layout 中的 variety
            'shorts' => '短剧'
        ];
        
        View::assign([
            'title' => ($titleMap[$type] ?? '列表') . ' - MoonTV',
            'type' => $type,
            'typeName' => $titleMap[$type] ?? '列表',
        ]);
        return View::fetch('index/list');
    }

    public function search()
    {
        // 同时支持 q 和 wd 参数，优先使用 q
        $wd = input('get.q', '') ?: input('get.wd', '');
        View::assign([
            'title' => ($wd ? $wd . ' - ' : '') . '搜索 - MoonTV',
            'wd' => $wd,
        ]);
        return View::fetch('index/search');
    }

    public function history()
    {
        View::assign('title', '观看记录 - MoonTV');
        return View::fetch('index/history');
    }

    public function detail($id)
    {
        $source = input('get.source', '');
        View::assign([
            'title' => '详情 - MoonTV',
            'id' => $id,
            'source' => $source,
        ]);
        return View::fetch('index/detail');
    }

    public function play($id, $sid = 0, $nid = 0)
    {
        $source = input('get.source', '');
        View::assign([
            'title' => '播放 - MoonTV',
            'id' => $id,
            'sid' => $sid,
            'nid' => $nid,
            'source' => $source,
        ]);
        return View::fetch('index/play');
    }
    
    public function vip()
    {
        View::assign('title', 'VIP会员 - MoonTV');
        return View::fetch('index/vip');
    }

    public function orders()
    {
        View::assign('title', '我的订单 - MoonTV');
        return View::fetch('index/orders');
    }

    public function user()
    {
        View::assign('title', '个人中心 - MoonTV');
        return View::fetch('index/user');
    }
    
    public function login()
    {
        View::assign('title', '登录 - MoonTV');
        return View::fetch('index/login');
    }
    
    public function register()
    {
        View::assign('title', '注册 - MoonTV');
        return View::fetch('index/register');
    }
}