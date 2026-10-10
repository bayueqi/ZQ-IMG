<?php
/**
 * 数据备份 / 恢复接口
 *
 * 用途：把当前数据库的 images、configs、users 三张表导出为一份 JSON 文件；
 *      换数据库（比如换 mysql 实例 / 换主机）时，用这份 JSON 重新导入即可。
 *
 * 说明：
 * - 只处理「数据」，不搬移物理图片文件。换库后若存储方式为 local，
 *   需要把原来的 i/ 目录一并拷过去，图片才能正常访问。
 * - users 表里 password 已经是 password_hash 的哈希值，导入后可照常登录。
 */
session_start();
require_once '../config/database.php';

// 未登录不允许访问
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE));
}

$db = Database::getInstance();
$mysqli = $db->getConnection();

if (!$mysqli) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => '数据库未连接'], JSON_UNESCAPED_UNICODE));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'export':
        handleExport($mysqli);
        break;
    case 'import':
        handleImport($mysqli);
        break;
    default:
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE));
}

/**
 * 导出三张表数据为 JSON
 */
function handleExport($mysqli) {
    $tables = ['images', 'configs', 'users'];
    $dump = [
        'format'      => 'zq-img-backup',
        'version'     => 1,
        'exported_at' => date('Y-m-d H:i:s'),
        'tables'      => [],
    ];

    foreach ($tables as $table) {
        $res = $mysqli->query("SELECT * FROM `$table`");
        if ($res === false) {
            // 表不存在时跳过，不中断整体导出
            $dump['tables'][$table] = [];
            continue;
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $dump['tables'][$table] = $rows;
    }

    $dump['counts'] = [
        'images'  => count($dump['tables']['images']),
        'configs' => count($dump['tables']['configs']),
        'users'   => count($dump['tables']['users']),
    ];

    $filename = 'zq-img-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 导入 JSON 数据
 *
 * 支持两种模式：
 * - merge：不删除已有数据。images 按 (path) 去重，configs / users 按 (key / username) UPSERT。
 * - replace：先清空三张表再整体写入（谨慎使用）。
 */
function handleImport($mysqli) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        exit(json_encode(['success' => false, 'message' => '未收到上传文件'], JSON_UNESCAPED_UNICODE));
    }

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
        exit(json_encode(['success' => false, 'message' => '文件格式不正确，请选择本程序导出的备份文件'], JSON_UNESCAPED_UNICODE));
    }

    $mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';

    $stat = [
        'mode'            => $mode,
        'images_imported' => 0,
        'images_skipped'  => 0,
        'configs'         => 0,
        'users'           => 0,
    ];

    $mysqli->begin_transaction();
    try {
        if ($mode === 'replace') {
            $mysqli->query("DELETE FROM images");
            $mysqli->query("DELETE FROM configs");
            $mysqli->query("DELETE FROM users");
        }

        $stat['images_imported'] = importImages($mysqli, $data['tables']['images'] ?? [], $mode, $stat);
        $stat['configs']         = importConfigs($mysqli, $data['tables']['configs'] ?? []);
        $stat['users']           = importUsers($mysqli, $data['tables']['users'] ?? []);

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        exit(json_encode(['success' => false, 'message' => '导入失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }

    exit(json_encode([
        'success' => true,
        'message' => '导入完成',
        'stat'    => $stat,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * 导入 images 表
 *
 * merge 模式：按 path 去重；若导出数据里的 id 与现有记录的 id 冲突，
 *             则让 MySQL 重新分配 id（置 NULL），避免主键冲突中断导入。
 */
function importImages($mysqli, $rows, $mode, &$stat) {
    if (empty($rows)) return 0;

    // 记录当前已有的 path 与 id，用于 merge 去重与冲突规避
    $existingPaths = [];
    $existingIds = [];
    if ($mode === 'merge') {
        $res = $mysqli->query("SELECT id, path FROM images");
        while ($r = $res->fetch_assoc()) {
            $existingPaths[$r['path']] = true;
            $existingIds[(int)$r['id']] = true;
        }
    }

    $sql = "INSERT INTO images (id, user_id, url, path, storage, size, category, upload_ip, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);

    $imported = 0;
    foreach ($rows as $row) {
        $path = $row['path'] ?? '';
        if ($path === '') {
            $stat['images_skipped']++;
            continue;
        }
        if ($mode === 'merge' && isset($existingPaths[$path])) {
            $stat['images_skipped']++;
            continue;
        }

        // id 冲突时交由 MySQL 自增分配
        $id = isset($row['id']) && $row['id'] !== '' ? (int)$row['id'] : null;
        if ($id !== null && isset($existingIds[$id])) {
            $id = null;
        }

        $user_id   = (isset($row['user_id']) && $row['user_id'] !== null && $row['user_id'] !== '') ? (int)$row['user_id'] : null;
        $url       = $row['url'] ?? '';
        $storage   = $row['storage'] ?? 'local';
        $size      = isset($row['size']) ? (int)$row['size'] : 0;
        $category  = $row['category'] ?? '默认';
        $upload_ip = $row['upload_ip'] ?? '';
        $created   = $row['created_at'] ?? date('Y-m-d H:i:s');

        $stmt->bind_param("iisssisss", $id, $user_id, $url, $path, $storage, $size, $category, $upload_ip, $created);
        $stmt->execute();
        $imported++;

        // 记录新插入的 id，防止同一批数据内部重复
        if ($id !== null) {
            $existingIds[$id] = true;
        } elseif (($newId = $mysqli->insert_id) > 0) {
            $existingIds[$newId] = true;
        }
    }

    $stmt->close();
    return $imported;
}

/**
 * 导入 configs 表（按 key 覆盖）
 */
function importConfigs($mysqli, $rows) {
    if (empty($rows)) return 0;

    $stmt = $mysqli->prepare(
        "INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), description = VALUES(description)"
    );

    $count = 0;
    foreach ($rows as $row) {
        if (!isset($row['key'])) continue;
        $key  = $row['key'];
        $val  = $row['value'] ?? '';
        $desc = $row['description'] ?? '';
        $stmt->bind_param("sss", $key, $val, $desc);
        $stmt->execute();
        $count++;
    }

    $stmt->close();
    return $count;
}

/**
 * 导入 users 表（按 username 覆盖）
 */
function importUsers($mysqli, $rows) {
    if (empty($rows)) return 0;

    $stmt = $mysqli->prepare(
        "INSERT INTO users (username, password, token) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE password = VALUES(password), token = VALUES(token)"
    );

    $count = 0;
    foreach ($rows as $row) {
        if (!isset($row['username'])) continue;
        $username = $row['username'];
        $password = $row['password'] ?? '';
        $token    = $row['token'] ?? bin2hex(random_bytes(16));
        $stmt->bind_param("sss", $username, $password, $token);
        $stmt->execute();
        $count++;
    }

    $stmt->close();
    return $count;
}
