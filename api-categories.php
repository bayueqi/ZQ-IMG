<?php
require_once 'config/database.php';

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    
    // 获取所有分类
    $result = $mysqli->query("SELECT DISTINCT category FROM images ORDER BY category");
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    
    // 返回分类列表
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>