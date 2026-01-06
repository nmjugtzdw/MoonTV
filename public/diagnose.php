<?php
// 数据库配置
$db_host = '127.0.0.1';
$db_name = 'moontv';
$db_user = 'moontv';
$db_pass = '5KfiyBiPfbMchTTX';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>MoonTV 诊断与修复工具</h2>";

// 1. 尝试连接数据库并重置配置
echo "<h3>1. 数据库配置重置</h3>";
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 清空配置表
    $sql = "TRUNCATE TABLE admin_config";
    $pdo->exec($sql);
    echo "<p style='color:green'>✔ 成功清空 admin_config 表。下次访问网站将重新初始化默认配置。</p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>✘ 数据库连接失败: " . $e->getMessage() . "</p>";
}

// 2. 测试采集源连通性
echo "<h3>2. 采集源连通性测试</h3>";
$apis = [
    '百度云资源' => 'https://api.apibdzy.com/api.php/provide/vod/from/dbm3u8/at/json?ac=list',
    '红牛资源'   => 'https://www.hongniuzy2.com/api.php/provide/vod/from/hnm3u8/at/json?ac=list'
];

foreach ($apis as $name => $url) {
    echo "<p>正在测试 <strong>$name</strong> ...</p>";
    $start = microtime(true);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5秒超时
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    // 忽略 SSL 证书验证（防止服务器证书库过旧导致失败）
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    $time = round(microtime(true) - $start, 3);
    
    if ($result) {
        $json = json_decode($result, true);
        if (isset($json['list']) || isset($json['data'])) {
             echo "<p style='color:green'>✔ 连接成功 (耗时 {$time}秒) - 接口返回正常</p>";
        } else {
             echo "<p style='color:orange'>⚠ 连接成功 (耗时 {$time}秒) - 但返回数据格式不对: " . substr($result, 0, 100) . "...</p>";
        }
    } else {
        echo "<p style='color:red'>✘ 连接失败 (耗时 {$time}秒): $error</p>";
        echo "<p style='color:gray;font-size:12px'>HTTP状态码: " . $info['http_code'] . "</p>";
    }
}

echo "<hr>";
echo "<p>如果上方显示数据库重置成功，且至少有一个采集源连接成功，请 <a href='/'>返回首页</a> 刷新查看。</p>";
echo "<p>如果采集源全部连接失败，说明你的服务器无法访问外部网络，需要检查服务器防火墙或 DNS 设置。</p>";
?>