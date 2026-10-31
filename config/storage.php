<?php

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;
use Upyun\Config;

/**
 * 拼图片 URL 用的协议。后台「URL协议」填了自定义前缀（如 https://i1.wp.com/）原样返回；
 * 只填 http:// 或 https:// 时一律输出 https —— 安装时 $_SERVER['HTTPS'] 常取不到
 * （Nginx + PHP-FPM 不自动传），会把地址误存成 http。
 */
function effective_protocol($configured) {
    if ($configured !== 'http://' && $configured !== 'https://') {
        return $configured;
    }
    return 'https://';
}

/**
 * 按 path + 存储方式查已有图片记录
 *
 * 图片按内容哈希命名，同一张图重复上传会落到同一个 path，靠这里命中已有记录。
 */
function findImageByPath($path, $storage) {
    global $mysqli;

    $stmt = $mysqli->prepare("SELECT id, url, path, size, category, upload_ip FROM images WHERE path = ? AND storage = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ss", $path, $storage);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * 命中已有记录：按原样返回，链接与之前一致
 */
function respondExistingImage($row, $width, $height) {
    $path = $row['path'];
    $name = basename($path);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'status' => true,
        'name' => $name,
        'data' => [
            'url' => $row['url'],
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'size' => (int)$row['size'],
            'path' => $path
        ],
        'url' => $row['url']
    ]);
}

/**
 * 处理本地存储
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $uploadDirWithDatePath 带日期路径的上传目录
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleLocalStorage($finalFilePath, $newFilePath, $uploadDirWithDatePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip, $category = '默认') {
    global $mysqli;
    $config = Database::getConfig($mysqli);

    logMessage("文件存储在本地: $finalFilePath");
    $fileUrl = effective_protocol($config['protocol']) . $_SERVER['HTTP_HOST'] . '/' . $uploadDirWithDatePath . basename($finalFilePath);
    insertImageRecord($fileUrl, $finalFilePath, 'local', $compressedSize, $upload_ip, $user_id, $category);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'status' => true,
        'name' => basename($finalFilePath),
        'data' => [
            'url' => $fileUrl,
            'name' => basename($finalFilePath),
            'width' => $compressedWidth,
            'height' => $compressedHeight,
            'size' => $compressedSize,           
            'path' => $finalFilePath
        ],
        'url' => $fileUrl
    ]);
}

/**
 * 处理OSS上传
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleOSSUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip, $category = '默认') {
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $ossClient = new OssClient(
            $config['oss_access_key_id'],
            $config['oss_access_key_secret'],
            $config['oss_endpoint']
        );
        
        $ossFilePath = basename($finalFilePath);
        $ossClient->uploadFile($config['oss_bucket'], $ossFilePath, $finalFilePath);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到OSS成功: $ossFilePath");
        $fileUrl = effective_protocol($config['protocol']) . $config['oss_cdn_domain'] . '/' . $ossFilePath;
        insertImageRecord($fileUrl, $ossFilePath, 'oss', $compressedSize, $upload_ip, $user_id, $category);

        respondAndExit([
            'result' => 'success',
            'code' => 200,
            'status' => true,
            'name' => basename($finalFilePath),
            'data' => [
                'url' => $fileUrl,
                'name' => basename($finalFilePath),
                'width' => $compressedWidth,
                'height' => $compressedHeight,
                'size' => $compressedSize,                
                'path' => $ossFilePath
            ],
            'url' => $fileUrl
        ]);
    } catch (OssException $e) {
        logMessage('文件上传到OSS失败: ' . $e->getMessage());
        respondAndExit([
            'result' => 'error',
            'code' => 500,
            'message' => '文件上传到OSS失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 处理S3上传
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleS3Upload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip, $category = '默认') {
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $s3Client = new S3Client([
            'region' => $config['s3_region'],
            'version' => 'latest',
            'endpoint' => $config['protocol'] . $config['s3_endpoint'],
            'credentials' => [
                'key' => $config['s3_access_key_id'],
                'secret' => $config['s3_access_key_secret'],
            ],
        ]);
        
        $s3FilePath = basename($finalFilePath);
        $result = $s3Client->putObject([
            'Bucket' => $config['s3_bucket'],
            'Key' => $s3FilePath,
            'SourceFile' => $finalFilePath,
            'ACL' => 'public-read',
        ]);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到S3成功: $s3FilePath");

        if (empty($config['s3_custom_url_prefix'])) {
            $fileUrl = $result['ObjectURL'];
        } else {
            $fileUrl = effective_protocol($config['protocol']) . $config['s3_custom_url_prefix'] . '/' . $s3FilePath;
        }

        insertImageRecord($fileUrl, $s3FilePath, 's3', $compressedSize, $upload_ip, $user_id, $category);

        respondAndExit([
            'result' => 'success',
            'code' => 200,
            'status' => true,
            'name' => basename($finalFilePath),
            'data' => [
                'url' => $fileUrl,
                'name' => basename($finalFilePath),
                'width' => $compressedWidth,
                'height' => $compressedHeight,
                'size' => $compressedSize,                
                'path' => $s3FilePath
            ],
            'url' => $fileUrl
        ]);
    } catch (S3Exception $e) {
        logMessage('文件上传到S3失败: ' . $e->getMessage());
        respondAndExit([
            'result' => 'error',
            'code' => 500,
            'message' => '文件上传到S3失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 处理又拍云上传
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleUpyunUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip, $category = '默认') {
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $serviceConfig = new \Upyun\Config(
            $config['upyun_bucket'],
            $config['upyun_operator'],
            $config['upyun_password']
        );
        $upyun = new \Upyun\Upyun($serviceConfig);
        
        $upyunFilePath = basename($finalFilePath);
        $fileContent = file_get_contents($finalFilePath);
        $upyun->write($upyunFilePath, $fileContent);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到又拍云成功: $upyunFilePath");
        $fileUrl = effective_protocol($config['protocol']) . $config['upyun_domain'] . '/' . $upyunFilePath;
        insertImageRecord($fileUrl, $upyunFilePath, 'upyun', $compressedSize, $upload_ip, $user_id, $category);

        respondAndExit([
            'result' => 'success',
            'code' => 200,
            'status' => true,
            'name' => basename($finalFilePath),
            'data' => [
                'url' => $fileUrl,
                'name' => basename($finalFilePath),
                'width' => $compressedWidth,
                'height' => $compressedHeight,
                'size' => $compressedSize,                
                'path' => $upyunFilePath
            ],
            'url' => $fileUrl
        ]);
    } catch (\Exception $e) {
        logMessage('文件上传到又拍云失败: ' . $e->getMessage());
        respondAndExit([
            'result' => 'error',
            'code' => 500,
            'message' => '文件上传到又拍云失败: ' . $e->getMessage()
        ]);
    }
}
