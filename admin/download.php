<?php
/**
 * 图片下载中转
 *
 * 图片地址与后台协议/域名不一致时，前端 <a download> 会被浏览器忽略，
 * 这里由服务端读出文件并带上 attachment 头输出，不受跨域限制。
 *
 * 用法：admin/download.php?url=<图片URL>
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

session_start();
require_once __DIR__ . '/../config/database.php';

/**
 * 输出错误并结束：状态码 + 纯文本，前端可直接显示给用户
 */
function downloadFail($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    downloadFail(403, '未登录或登录已过期，请重新登录后台');
}

$url = trim($_GET['url'] ?? '');
if ($url === '') {
    downloadFail(400, '缺少 url 参数');
}

$db = Database::getInstance();
$mysqli = $db->getConnection();
if (!$mysqli) {
    downloadFail(500, '数据库未连接');
}

// 安全校验：只允许下载本站数据库里有记录的图片，避免被当作任意文件代理
$stmt = $mysqli->prepare("SELECT path, storage FROM images WHERE url = ? LIMIT 1");
if (!$stmt) {
    downloadFail(500, '数据库查询失败: ' . $mysqli->error);
}
$stmt->bind_param("s", $url);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    downloadFail(404, '未找到该图片记录，请刷新后台页面后重试');
}

// 推断文件名
$filename = basename(parse_url($url, PHP_URL_PATH) ?: '');
if ($filename === '') {
    $filename = 'image';
}
$filename = rawurldecode($filename);

/**
 * 统一输出：同时给普通文件名和 UTF-8 文件名，中文名不会变乱码
 */
function downloadOutput($filename, $mime, $length, $data = null, $file = null) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    if ($length > 0) {
        header('Content-Length: ' . $length);
    }
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($file !== null) {
        readfile($file);
    } else {
        echo $data;
    }
    exit;
}

set_time_limit(60);

// 本地存储：直接读磁盘文件
if (($record['storage'] ?? '') === 'local') {
    $localPath = dirname(__DIR__) . '/' . ltrim($record['path'] ?? '', '/');
    if (is_file($localPath) && is_readable($localPath)) {
        $mime = function_exists('mime_content_type') ? (mime_content_type($localPath) ?: 'application/octet-stream') : 'application/octet-stream';
        downloadOutput($filename, $mime, filesize($localPath), null, $localPath);
    }
    // 文件不在磁盘上，继续走下面的远程代理
}

// 远程存储（OSS/S3/又拍云）或本地文件缺失：由服务端代理拉取
$data = false;
$contentType = '';
$httpCode = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'ZQ-IMG/2.0 Downloader',
    ]);
    $data = curl_exec($ch);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($data === false) {
        downloadFail(502, '拉取图片失败: ' . ($curlError ?: '网络错误'));
    }
} else {
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 60,
            'follow_location' => 1,
            'user_agent'      => 'ZQ-IMG/2.0 Downloader',
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        downloadFail(502, '拉取图片失败，请检查服务器是否允许访问外网');
    }
}

if ($httpCode >= 400) {
    downloadFail(502, '拉取图片失败: 源站返回 HTTP ' . $httpCode);
}

if ($data === '') {
    downloadFail(502, '拉取图片失败: 源站返回空内容');
}

// 拿到的是网页而不是图片，说明源地址已失效，不能当成图片存下来
if (stripos($contentType, 'text/html') !== false) {
    downloadFail(502, '源地址返回的是网页而非图片，图片可能已被删除');
}

$mime = 'application/octet-stream';
if ($contentType && stripos($contentType, 'image/') === 0) {
    $mime = $contentType;
} elseif (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = finfo_buffer($finfo, $data);
    finfo_close($finfo);
    if ($detected && stripos($detected, 'image/') === 0) {
        $mime = $detected;
    }
}

downloadOutput($filename, $mime, strlen($data), $data);
