<?php

namespace app\service;

use think\facade\Db;
use think\facade\Cache;

class ConfigService
{
    protected static $config = null;
    protected static $cacheKey = 'admin_config_cache';

    /**
     * 获取完整配置
     * @return array
     */
    public static function getConfig()
    {
        if (self::$config) {
            return self::$config;
        }

        // 优先检查 config.json 是否有更新 (即使有缓存也检查)
        // 这是一个轻量级检查，确保配置修改能及时生效
        $configFile = root_path() . 'public/config.json';
        if (!file_exists($configFile)) {
           $configFile = root_path() . '../config.json';
        }
        
        $shouldReload = false;
        $dbData = null;

        if (file_exists($configFile)) {
            // 获取数据库更新时间
            $record = Db::name('admin_config')->where('id', 1)->field('id, updated_at, config_data')->find();
            
            if ($record) {
                // 如果数据库记录存在，对比时间戳
                $fileTime = filemtime($configFile);
                $dbTime = isset($record['updated_at']) ? strtotime($record['updated_at']) : 0;
                
                // 如果文件比数据库新（增加10秒缓冲），强制刷新
                if ($fileTime > $dbTime + 10) {
                    $shouldReload = true;
                    $dbData = $record;
                }
            } else {
                // 数据库没记录，也需要加载
                $shouldReload = true;
            }
        }

        // 如果不需要重载，尝试从缓存读取
        if (!$shouldReload) {
            $cached = Cache::get(self::$cacheKey);
            if ($cached) {
                self::$config = json_decode($cached, true);
                return self::$config;
            }
        }

        // 需要重载，或者缓存未命中：读取数据库并同步文件
        if (!isset($record)) {
             $record = Db::name('admin_config')->where('id', 1)->find();
        }

        // 1. 如果需要重载 (Sync Logic)
        if ($shouldReload && file_exists($configFile)) {
             // 如果有旧配置就基于旧配置，否则用默认配置
             if ($record && !empty($record['config_data'])) {
                 self::$config = json_decode($record['config_data'], true);
             } else {
                 self::$config = self::getDefaultConfig();
             }
             
             // 同步文件配置
             self::syncSourceFromJs(self::$config, $configFile);
             // 保存回数据库
             self::saveConfig(self::$config);
             return self::$config;
        }

        // 2. 正常读取 (Normal Load)
        if ($record && !empty($record['config_data'])) {
            self::$config = json_decode($record['config_data'], true);
            // 写入缓存
            Cache::set(self::$cacheKey, $record['config_data'], 3600);
        } else {
            // 数据库空，尝试初次加载文件
            $configFromFile = self::loadConfigFromFile();
            if ($configFromFile) {
                self::$config = $configFromFile;
                self::saveConfig(self::$config);
            } else {
                self::$config = self::getDefaultConfig();
                self::saveConfig(self::$config);
            }
        }

        return self::$config;
    }

    /**
     * 从 config.json 同步播放源配置
     * @param array $config 引用传递，直接修改配置数组
     * @param string $file 文件路径
     */
    protected static function syncSourceFromJs(&$config, $file)
    {
        $jsonContent = @file_get_contents($file);
        if (!$jsonContent) return;

        $fileConfig = json_decode($jsonContent, true);
        if (!$fileConfig || !isset($fileConfig['api_site'])) return;

        $sourceConfig = [];
        foreach ($fileConfig['api_site'] as $key => $site) {
            if (empty($site['api'])) continue;
            $sourceConfig[] = [
                'key' => $key,
                'name' => $site['name'] ?? $key,
                'api' => rtrim($site['api'], '/'),
                'detail' => $site['detail'] ?? '',
                'from' => $site['from'] ?? $key,
                'disabled' => false
            ];
        }

        if (!empty($sourceConfig)) {
            $config['SourceConfig'] = $sourceConfig;
            // 也可以同步 cache_time
            if (isset($fileConfig['cache_time'])) {
                $config['SiteConfig']['SiteInterfaceCacheTime'] = intval($fileConfig['cache_time']);
            }
        }
    }

    /**
     * 保存配置
     * @param array $config
     * @return bool
     */
    public static function saveConfig($config)
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        
        $exists = Db::name('admin_config')->where('id', 1)->count();
        if ($exists) {
            Db::name('admin_config')->where('id', 1)->update([
                'config_data' => $json,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::name('admin_config')->insert([
                'id'          => 1,
                'config_data' => $json,
            ]);
        }

        // 更新内存和缓存
        self::$config = $config;
        Cache::set(self::$cacheKey, $json, 3600);

        return true;
    }

    /**
     * 从config.json文件加载配置
     * @return array|null
     */
    protected static function loadConfigFromFile()
    {
        // root_path() 返回 php-moontv/ 目录
        // 尝试从项目根目录（php-moontv的上一级）的config.json读取
        // 注意：考虑 open_basedir 限制，如果无法访问上级目录，捕获异常并不再尝试
        $configFile = null;
        $parentConfig = root_path() . '../config.json';
        $publicConfig = root_path() . 'public/config.json';
        
        try {
            if (@file_exists($parentConfig)) {
                $configFile = $parentConfig;
            } elseif (@file_exists($publicConfig)) {
                $configFile = $publicConfig;
            }
        } catch (\Exception $e) {
            // 如果出错（例如 open_basedir），尝试直接使用 public 目录
            $configFile = $publicConfig;
        }

        // 如果上面检测依然为 null 或者文件不存在
        if (!$configFile || !@file_exists($configFile)) {
            // 最后尝试一下 public 目录
             $configFile = $publicConfig;
        }
        
        if (!@file_exists($configFile)) {
            \think\facade\Log::info("config.json文件不存在: " . $configFile);
            return null;
        }

        $jsonContent = @file_get_contents($configFile);
        if (empty($jsonContent)) {
            \think\facade\Log::error("config.json文件读取失败或为空: " . $configFile);
            return null;
        }

        $fileConfig = json_decode($jsonContent, true);
        if (!$fileConfig || !is_array($fileConfig)) {
            \think\facade\Log::error("config.json格式错误: " . json_last_error_msg());
            return null;
        }

        \think\facade\Log::info("从config.json加载配置成功，包含 " . (isset($fileConfig['api_site']) ? count($fileConfig['api_site']) : 0) . " 个播放源");

        // 转换config.json格式到内部配置格式
        $config = self::getDefaultConfig();
        
        // 转换api_site到SourceConfig格式
        if (isset($fileConfig['api_site']) && is_array($fileConfig['api_site'])) {
            $sourceConfig = [];
            foreach ($fileConfig['api_site'] as $key => $site) {
                if (empty($site['api'])) {
                    continue;
                }
                
                $sourceConfig[] = [
                    'key' => $key, // 重要：确保 key 被正确设置
                    'name' => $site['name'] ?? $key,
                    'api' => rtrim($site['api'], '/'),
                    'detail' => $site['detail'] ?? '',
                    'from' => $site['from'] ?? $key,
                    'disabled' => false
                ];
            }
            
            if (!empty($sourceConfig)) {
                $config['SourceConfig'] = $sourceConfig;
                \think\facade\Log::info("成功转换 " . count($sourceConfig) . " 个播放源配置");
            }
        }

        // 如果有cache_time，设置到SiteConfig
        if (isset($fileConfig['cache_time'])) {
            $config['SiteConfig']['SiteInterfaceCacheTime'] = intval($fileConfig['cache_time']);
        }

        // 如果有homepage_source，设置到SiteConfig
        if (isset($fileConfig['homepage_source'])) {
            $config['SiteConfig']['HomepageSource'] = $fileConfig['homepage_source'];
        }

        return $config;
    }

    /**
     * 获取默认配置结构
     * @return array
     */
    protected static function getDefaultConfig()
    {
        return [
            'ConfigFile' => '{}',
            'SiteConfig' => [
                'SiteName' => 'MoonTV',
                'Announcement' => '本网站仅提供影视信息搜索服务...',
                'SearchDownstreamMaxPage' => 5,
                'SiteInterfaceCacheTime' => 7200,
                'HomepageSource' => 'douban', // 默认首页数据源：douban, baidu, redbull, etc...
                'DoubanProxyType' => 'direct', // 默认使用直连（根据测试，直连最快0.3秒，CDN需要3秒）
                'DoubanProxy' => '',
                'DoubanImageProxyType' => 'direct',
                'DoubanImageProxy' => '',
                'DisableYellowFilter' => false,
                'DanmakuApiBaseUrl' => '',
                'TVBoxEnabled' => false,
                'TVBoxPassword' => '',
            ],
            'UserConfig' => [
                'AllowRegister' => true,
                'Users' => [], // {username, role, group, banned}
                'Groups' => [],
            ],
            'SourceConfig' => [
                [
                    'key' => 'baidu',
                    'name' => '百度云资源',
                    'api' => 'https://api.apibdzy.com/api.php/provide/vod/from/dbm3u8/at/json',
                    'detail' => '',
                    'from' => 'dbm3u8',
                    'disabled' => false
                ],
                [
                    'key' => 'hongniu',
                    'name' => '红牛资源',
                    'api' => 'https://www.hongniuzy2.com/api.php/provide/vod/from/hnm3u8/at/json',
                    'detail' => '',
                    'from' => 'hnm3u8',
                    'disabled' => false
                ]
            ], // {key, name, api, detail, from, disabled}
            'CustomCategories' => [],
            'SubscriptionConfig' => [],
            'PaymentConfig' => [
                'enabled' => false,
                'provider' => 'yipay',
                'yipay' => [
                    'merchantId' => '',
                    'apiUrl' => '',
                    'apiKey' => '',
                    'notifyUrl' => '',
                    'returnUrl' => '',
                ],
                'registerGiftDays' => 3,
            ],
        ];
    }
}