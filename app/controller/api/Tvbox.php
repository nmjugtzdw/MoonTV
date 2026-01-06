<?php

namespace app\controller\api;

use app\BaseController;
use app\service\ConfigService;
use app\service\SearchService;
use think\facade\Cache;

class Tvbox extends BaseController
{
    /**
     * TVBox 配置接口
     */
    public function config()
    {
        $pwd = input('get.pwd') ?: input('get.password');
        $un = input('get.un');

        // TVBox 接口通常不需要鉴权或者使用简单密码
        $config = ConfigService::getConfig();
        $tvboxEnabled = $config['SiteConfig']['TVBoxEnabled'] ?? false;
        $tvboxPassword = $config['SiteConfig']['TVBoxPassword'] ?? '';

        if (!$tvboxEnabled) {
             return json(['error' => 'TVBox 接口未开启'], 403);
        }

        if (!empty($tvboxPassword) && $pwd !== $tvboxPassword) {
            return json(['error' => '密码错误'], 401);
        }
        
        // 构建 sites
        $allSources = $config['SourceConfig'];
        
        // 如果有用户名，按分组过滤（简单起见这里先全量返回，或后续实现）
        $sources = array_filter($allSources, function ($s) {
            return empty($s['disabled']);
        });

        $sites = [];
        // 添加自定义分类入口（指向本站API）
        $sites[] = [
            'key' => 'douban_custom',
            'name' => '豆瓣｜自定义',
            'type' => 1,
            'api' => request()->domain() . '/api/tvbox/categories',
            'searchable' => 0,
            'quickSearch' => 0,
            'filterable' => 0,
        ];

        foreach ($sources as $s) {
            $sites[] = [
                'key' => $s['key'],
                'name' => $s['name'],
                'type' => 1, // 假设都是 xml/json 接口
                'api' => $s['api'],
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'ext' => $s['detail'] ?? ''
            ];
        }

        $data = [
            'sites' => $sites,
            'parses' => [], // 解析接口配置
            'lives' => [], // 直播配置
            'ads' => []
        ];

        return json($data);
    }

    /**
     * 自定义分类接口 (代理)
     */
    public function categories()
    {
        // 模拟 TVBox category 接口返回
        // 实际上这里应该根据 filter 或者是直接返回推荐内容
        // 简化实现：返回一些预设分类
        
        $t = input('get.t');
        $q = input('get.q');
        $pg = input('get.pg', 1);

        // 如果有 wd 参数，说明是搜索
        $wd = input('get.wd');
        if ($wd) {
            // 调用搜索服务
            // 注意：TVBox 搜索通常是并发调用 sites 里的接口，
            // 如果这个接口被配置为 site，那么它应该返回符合 CMS 标准的搜索结果
            // 但这里我们是在 config 中配置为 'api/tvbox/categories'，通常用于首页分类展示
            
            // 假设这里不处理搜索，搜索由 TVBox 直接调各源
             return json(['list' => []]);
        }

        // 首页分类列表
        if (empty($t)) {
             $config = ConfigService::getConfig();
             $categories = $config['CustomCategories'] ?? [];
             
             $classes = [];
             foreach ($categories as $c) {
                 $classes[] = [
                     'type_id' => $c['query'], // 这里用 query 作为 id
                     'type_name' => $c['name']
                 ];
             }
             
             // 默认推荐
             // 应该去调豆瓣接口或者其他推荐接口，这里简化
             return json([
                 'class' => $classes,
                 'list' => [], // 首页推荐列表
                 'filters' => []
             ]);
        }
        
        // 分类详情列表
        // 需要去豆瓣或聚合接口获取
        // 简单返回空，或者对接 SearchService 的资源推荐
        return json([
            'page' => $pg,
            'pagecount' => 1,
            'limit' => 20,
            'total' => 0,
            'list' => []
        ]);
    }
}