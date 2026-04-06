<?php
header("Content-Type: application/json; charset=utf-8");
try {
    $pdo = new PDO("mysql:host=localhost;dbname=sacosbf10_bbdd_bf10;charset=utf8mb4", "sacosbf10_bbdd2", "Serv1saco2026");
    echo json_encode(["ok" => true, "msg" => "Connected!"]);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
