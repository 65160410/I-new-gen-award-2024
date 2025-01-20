<?php
session_start();

// ตรวจสอบการเข้าสู่ระบบแอดมิน
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include '../elephant_api/db.php';

// กำหนดรายการกล้องทั้งหมด
$allCameras = [
    'SOURCE0_CAM_001',
    'SOURCE0_CAM_002',
    'SOURCE0_CAM_003',
    'SOURCE0_CAM_004',
    'SOURCE0_CAM_005',
    'SOURCE0_CAM_006',
    'SOURCE0_CAM_007',
    'SOURCE0_CAM_008'
];

/**
 * ฟังก์ชันสำหรับคำนวณจำนวนกล้องที่ Offline
 */
function getOfflineCount($conn, $allCameras) {
    $offlineCount = 0;
    $placeholders = implode(',', array_fill(0, count($allCameras), '?'));
    $sql = "SELECT id_cam, MAX(time) AS last_time FROM detections WHERE id_cam IN ($placeholders) GROUP BY id_cam";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return count($allCameras); // หากมีข้อผิดพลาดถือว่าทุกกล้อง Offline
    }

    $types = str_repeat('s', count($allCameras));
    $stmt->bind_param($types, ...$allCameras);
    $stmt->execute();
    $result = $stmt->get_result();

    $lastTimes = [];
    while ($row = $result->fetch_assoc()) {
        $lastTimes[$row['id_cam']] = strtotime($row['last_time']);
    }
    $stmt->close();

    $currentTime = time();
    $threshold = 30; // วินาที

    foreach ($allCameras as $cam) {
        if (isset($lastTimes[$cam])) {
            if (($currentTime - $lastTimes[$cam]) > $threshold) {
                $offlineCount++;
            }
        } else {
            $offlineCount++;
        }
    }

    return $offlineCount;
}

$offlineCount = getOfflineCount($conn, $allCameras);

// ส่งข้อมูลไปยัง JavaScript ในรูปแบบ JSON
header('Content-Type: application/json');
echo json_encode(['offlineCount' => $offlineCount]);
?>
