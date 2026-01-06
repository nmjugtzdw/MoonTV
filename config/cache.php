<?php
return [
    // 默认缓存驱动：强制使用 file，避免读取 .env 失败
    'default' => 'file',

    // 仓库配置
    'stores'  => [
        'file' => [
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        'redis' => [
            'type'   => 'redis',
            'host'   => '127.0.0.1',
            'port'   => 6379,
            'prefix' => '',
            'expire' => 0,
        ],
    ],
];