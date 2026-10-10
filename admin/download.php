<?php
/**
 * 图片下载中转
 *
 * 为什么需要它：浏览器里 <a download> 与 fetch 都受同源/CORS 限制，
 * 当图片地址协议或域名与后台不一致时，前端无法直接触发下载。
 * 这里由服务端把图片读出来，带上 Content-Disposition: attachment 头输出，
 * 浏览器必定弹出下载，不受跨域影响。
 *
 * 用法：admin/download.php?url=<图片URL>
 */
session_start();
require_once '../config/database.php';

// 未登录不允许访问
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    http_response_code(403);
    exit('未登录');
}

$db = Database::getInstance();
$mysqli = $db->getConnection();

$url = $_GET['url'] ?? '';
if ($url === '') {
    http_response_code(400);
    exit('缺少 url 参数');
}

// 安全校验：只允许下载本站数据库里有记录的图片，避免被当作任意文件代理
$stmt = $mysqli->prepare("SELECT path, storage FROM images WHERE url = ? LIMIT 1");
$stmt->bind_param("s", $url);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit('未找到该图片记录');
}

// 推断文件名
$filename = basename(parse_url($url, PHP_URL_PATH) ?: '');
if ($filename === '' || $filename === false) {
    $filename = 'image.jpg';
}

// 本地存储：直接读磁盘文件，最稳
if (($record['storage'] ?? '') === 'local') {
    $localPath = __DIR__ . '/../' . $record['path'];
    if (is_file($localPath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($localPath));
        header('Cache-Control: no-store');
        readfile($localPath);
        exit;
    }
}

// 远程存储（OSS/S3/又拍云）或本地文件缺失：由服务端代理拉取
$ctx = stream_context_create([
    'http' => [
        'timeout' => 30,
        'follow_location' => 1,
        'user_agent' => 'ZQ-IMG/2.0 Downloader',
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
$data = @file_get_contents($url, false, $ctx);

if ($data === false) {
    http_response_code(502);
    exit('拉取远程图片失败');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store');
echo $data;
exit;
