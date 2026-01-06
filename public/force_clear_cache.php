<?php
/**
 * 强制清除所有缓存
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Cache;

echo "<h1>强制清除所有缓存</h1>";
echo "<pre>";

// 清除所有缓存
try {
    Cache::clear();
    echo "✓ 已清除所有 Cache 缓存\n\n";
} catch (\Exception $e) {
    echo "× Cache 清除失败: " . $e->getMessage() . "\n\n";
}

// 删除缓存文件
$cacheDir = __DIR__ . '/../runtime/cache';
$cleared = 0;

if (is_dir($cacheDir)) {
    function deleteAllFiles($dir) {
        global $cleared;
        if (!file_exists($dir)) return;
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                deleteAllFiles($path);
                @rmdir($path);
            } else {
                if (@unlink($path)) {
                    $cleared++;
                }
            }
        }
    }
    
    deleteAllFiles($cacheDir);
    echo "✓ 已删除 {$cleared} 个缓存文件\n\n";
}

echo "缓存清除完成！\n";
echo "请按 Ctrl+F5 强制刷新浏览器页面\n";
echo "</pre>";
echo "<p><a href='/'>返回首页（记得按 Ctrl+F5 强制刷新）</a></p>";