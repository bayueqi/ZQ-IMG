<?php
// 为已有的数据库添加category字段
require_once 'config/database.php';

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    
    if (!$mysqli) {
        die('数据库连接失败');
    }
    
    // 检查images表是否存在category字段
    $result = $mysqli->query("SHOW COLUMNS FROM images LIKE 'category'");
    if ($result->num_rows == 0) {
        // 添加category字段
        $sql = "ALTER TABLE images ADD COLUMN category VARCHAR(50) DEFAULT '默认' COMMENT '图片分类'";
        if ($mysqli->query($sql) === TRUE) {
            echo "成功添加category字段到images表";
        } else {
            echo "添加字段失败: " . $mysqli->error;
        }
    } else {
        echo "category字段已存在";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>