<?php 
// admin_dashboard.php

// ปิดการแสดงข้อผิดพลาด (DEBUG) -- ปิดใน production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// เริ่มต้น session
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (Admin)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อ DB
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("Database connection is not established.");
    echo "<!DOCTYPE html>
    <html lang='th'>
    <head>
        <meta charset='UTF-8'>
        <title>ข้อผิดพลาด</title>
        <link href='https://cdn.tailwindcss.com' rel='stylesheet'>
    </head>
    <body class='flex items-center justify-center h-screen bg-gray-100'>
        <div class='text-center'>
            <h1 class='text-3xl font-bold mb-4'>เกิดข้อผิดพลาด</h1>
            <p class='text-gray-700'>ไม่สามารถเชื่อมต่อกับฐานข้อมูลได้ กรุณาลองใหม่ภายหลัง</p>
        </div>
    </body>
    </html>";
    exit;
}

// ฟังก์ชันกัน null
function safe_htmlspecialchars($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// ฟังก์ชันแปลสถานะจากอังกฤษเป็นไทย
function translateStatus($status) {
    switch ($status) {
        case 'pending':
            return 'รอดำเนินการ';
        case 'completed':
            return 'ดำเนินการแล้ว';
        default:
            return 'ไม่ทราบสถานะ';
    }
}

// ฟังก์ชัน Reverse Geocoding
function getAddressFromCoords($lat, $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&addressdetails=1";
    
    // ระบุ User-Agent ตามนโยบายของ Nominatim
    $opts = [
        'http' => [
            'header' => "User-Agent: YourAppName/1.0 (your.email@example.com)\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === FALSE) {
        return "ไม่ทราบสถานที่";
    }
    $data = json_decode($response, true);
    if (isset($data['address'])) {
        $address = $data['address'];
        // สร้างที่อยู่จากข้อมูลที่ได้รับ
        $parts = [];
        if (isset($address['road'])) {
            $parts[] = $address['road'];
        }
        if (isset($address['village'])) {
            $parts[] = "หมู่บ้าน " . $address['village'];
        } elseif (isset($address['town'])) {
            $parts[] = "เมือง " . $address['town'];
        } elseif (isset($address['hamlet'])) {
            $parts[] = "ชุมชน " . $address['hamlet'];
        }
        if (isset($address['suburb'])) {
            $parts[] = "ตำบล " . $address['suburb'];
        } elseif (isset($address['neighbourhood'])) {
            $parts[] = "ย่าน " . $address['neighbourhood'];
        }
        if (isset($address['county'])) {
            $parts[] = "อำเภอ " . $address['county'];
        }
        if (isset($address['state'])) {
            $parts[] = "จังหวัด " . $address['state'];
        }
        if (isset($address['postcode'])) {
            $parts[] = "รหัสไปรษณีย์ " . $address['postcode'];
        }
        return implode(", ", $parts);
    } else {
        return "ไม่ทราบสถานที่";
    }
}

// ฟังก์ชันแปลง Intensity Level และกำหนดคลาสสี
function getIntensityInfo($elephant, $alert, $distance) {
    if (floatval($distance) <= 1) {
        return ['text' => 'ฉุกเฉิน', 'class' => 'bg-red-600 text-white'];
    } elseif ($elephant && $alert) {
        return ['text' => 'ความเสี่ยงสูง', 'class' => 'bg-red-300 text-red-800'];
    } elseif ($elephant && !$alert) {
        return ['text' => 'ความเสี่ยงปานกลาง', 'class' => 'bg-yellow-200 text-gray-700'];
    } else {
        return ['text' => 'ปกติ', 'class' => 'bg-white text-black'];
    }
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Pagination setup
$perPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$start = ($page - 1) * $perPage;

// อ่านข้อมูลจากไฟล์ camera_locations.txt เป็น associative array
$file = 'camera_locations.txt';
$camera_locations = [];
if (file_exists($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode("=", $line, 2);
        if (count($parts) == 2) {
            $id = trim($parts[0]);
            $location = trim($parts[1]);
            $camera_locations[$id] = $location;
        }
    }
} else {
    error_log("camera_locations.txt not found.");
}

// Query detections + images พร้อมฟิลด์ status และคำนวณจำนวนสัตว์
$sql_detections = "
    SELECT
        detections.id,
        detections.id_cam,
        detections.lat_cam, detections.long_cam,
        detections.elephant,
        detections.lat_ele, detections.long_ele,
        detections.distance_ele,
        detections.alert,
        detections.status,
		detections.car,
		detections.elephant_count,
        images.timestamp,
        images.image_path
    FROM detections
    LEFT JOIN images ON detections.image_id = images.id
    ORDER BY detections.id DESC
    LIMIT ?, ?
";
$stmt_detections = $conn->prepare($sql_detections);
if (!$stmt_detections) {
    error_log("Prepare failed: " . $conn->error);
    die("Prepare failed for detections: " . $conn->error);
}
$stmt_detections->bind_param("ii", $start, $perPage);
$stmt_detections->execute();
$result_detections = $stmt_detections->get_result();

$markers = [];
$missing_coordinates = [];
$last_id = 0;

// สร้างแคชที่อยู่เพื่อหลีกเลี่ยงการเรียก API ซ้ำ
$address_cache = [];

if ($result_detections && $result_detections->num_rows > 0) {
    while ($row = $result_detections->fetch_assoc()) {
        // จัดการ path ของรูป
        if (!empty($row['image_path'])) {
            if (strpos($row['image_path'], 'uploads/') === 0) {
                $full_image_path = 'https://aprlabtop.com/elephant_api/' . $row['image_path'];
            } else {
                $full_image_path = 'https://aprlabtop.com/elephant_api/uploads/' . $row['image_path'];
            }
        } else {
            $full_image_path = '';
        }

        // ตรวจสอบว่า elephant_lat และ elephant_long เป็นอาร์เรย์หรือไม่
        $elephant_lats = json_decode($row['lat_ele'], true);
        $elephant_longs = json_decode($row['long_ele'], true);
        
        // กำหนด elephant_count
        if (is_array($elephant_lats) && is_array($elephant_longs) && count($elephant_lats) === count($elephant_longs)) {
            $elephant_count = count($elephant_lats);
            $valid_prefs = [
                'lat' => $elephant_lats,
                'lng' => $elephant_longs
            ];
        } elseif (is_numeric($elephant_lats) && is_numeric($elephant_longs)) {
            $elephant_count = 1;
            $valid_prefs = [
                'lat' => [$elephant_lats],
                'lng' => [$elephant_longs]
            ];
        } else {
            $elephant_count = 0;
            $valid_prefs = [
                'lat' => [],
                'lng' => []
            ];
        }

        // ตรวจสอบพิกัดกล้อง/ช้างว่า null หรือไม่
        $has_null_coords = (
            is_null($row['lat_cam']) || is_null($row['long_cam']) ||
            empty($valid_prefs['lat']) || empty($valid_prefs['lng'])
        );

        // ระบุที่อยู่จากไฟล์ camera_locations.txt หรือ Reverse Geocoding
        $camera_address = "ไม่ทราบสถานที่";
        if (!empty($row['id_cam'])) {
            if (isset($camera_locations[$row['id_cam']])) {
                $camera_address = $camera_locations[$row['id_cam']];
            } else {
                // ถ้าไม่พบ id_cam ในไฟล์ ให้ใช้ reverse geocoding
                if (!is_null($row['lat_cam']) && !is_null($row['long_cam'])) {
                    if (is_numeric($row['lat_cam']) && is_numeric($row['long_cam'])) {
                        $coord_key = $row['lat_cam'] . "," . $row['long_cam'];
                        if (isset($address_cache[$coord_key])) {
                            $camera_address = $address_cache[$coord_key];
                        } else {
                            $camera_address = getAddressFromCoords($row['lat_cam'], $row['long_cam']);
                            $address_cache[$coord_key] = $camera_address;
                        }
                    }
                }
            }
        }
		    // ประมวลผลจำนวนรถ
		$car = isset($row['car']) && is_numeric($row['car']) ? intval($row['car']) : 0;

		// ประมวลผลจำนวนช้าง
		$elephant_count = isset($row['elephant_count']) && is_numeric($row['elephant_count']) ? intval($row['elephant_count']) : 0;

		// สร้างอาร์เรย์สำหรับสิ่งที่ตรวจจับ
		$detection_types = [];

		if ($car > 0) {
			$detection_types[] = "รถ ". $car . " คัน";
		}

		if ($elephant_count > 0) {
			$detection_types[] = "ช้าง ". $elephant_count . " ตัว";
		}
		
		// สร้างข้อความแสดงผล
    	$detection_display = !empty($detection_types) ? implode(", ", $detection_types) : '<span class="text-red-800">ไม่มีการตรวจจับ</span>';

        // กำหนด Intensity Info
        $intensityInfo = getIntensityInfo($row['elephant'], $row['alert'], $row['distance_ele']);

        // แยกเป็นสองกลุ่ม: มี/ไม่มีพิกัดครบ
        if ($has_null_coords) {
            $missing_coordinates[] = [
                'id'             => $row['id'],
                'timestamp'      => $row['timestamp'],
                'lat_cam'        => $row['lat_cam'],
                'long_cam'       => $row['long_cam'],
                'elephant_lat'   => $valid_prefs['lat'],
                'elephant_long'  => $valid_prefs['lng'],
                'distance_ele'   => $row['distance_ele'],
                'image_path'     => $full_image_path,
                'alert'          => filter_var($row['alert'], FILTER_VALIDATE_BOOLEAN),
                'elephant'       => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN),
                'status'         => $row['status'],
                'camera_address' => $camera_address,
				'car'      => $car,
                'intensity_text' => $intensityInfo['text'],
                'intensity_class'=> $intensityInfo['class'],
                'elephant_count' => $elephant_count,
				'detection_display' => $detection_display 
            ];
        } else {
            $markers[] = [
                'id'             => $row['id'],
                'lat_cam'        => $row['lat_cam'],
                'long_cam'       => $row['long_cam'],
                'elephant_lat'   => $valid_prefs['lat'],
                'elephant_long'  => $valid_prefs['lng'],
                'distance_ele'   => $row['distance_ele'],
                'timestamp'      => $row['timestamp'],
                'elephant'       => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN),
                'image_path'     => $full_image_path,
                'alert'          => filter_var($row['alert'], FILTER_VALIDATE_BOOLEAN),
                'status'         => $row['status'],
                'camera_address' => $camera_address,
				'car'      => $car,
                'intensity_text' => $intensityInfo['text'],
                'intensity_class'=> $intensityInfo['class'],
                'elephant_count' => $elephant_count,
				'detection_display' => $detection_display
            ];
        }
		
		
        // เก็บ ID การตรวจจับล่าสุด
        if ($row['id'] > $last_id) {
            $last_id = $row['id'];
        }
    }
}
$stmt_detections->close();

// ฟังก์ชัน updateStatus (ถ้ามีการอัปเดตสถานะผ่านฟอร์มหรือ AJAX)
function updateStatus($id, $status) {
    global $conn;
    if (empty($status)) {
        error_log("Status is empty for detection ID: " . $id);
        return false;
    }
    $stmt = $conn->prepare("UPDATE detections SET status = ? WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        error_log("Failed to update status: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

// Handle AJAX POST for status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : '';

    // Validate status
    $allowed_statuses = ['pending', 'completed'];
    if (in_array($status, $allowed_statuses) && $id > 0) {
        // Transaction
        $conn->begin_transaction();
        try {
            // 1. Update status in solutions_admin
            $update_solution_status_sql = "UPDATE solutions_admin SET solution_status = ? WHERE detection_id = ?";
            $stmt_update_solution_status = $conn->prepare($update_solution_status_sql);
            if (!$stmt_update_solution_status) {
                throw new Exception("Failed to prepare update status query in solutions_admin.");
            }
            $stmt_update_solution_status->bind_param("si", $status, $id);
            if (!$stmt_update_solution_status->execute()) {
                throw new Exception("Failed to update status in solutions_admin: " . $stmt_update_solution_status->error);
            }
            $stmt_update_solution_status->close();

            // 2. Update status in detections
            $update_detection_status_sql = "UPDATE detections SET status = ? WHERE id = ?";
            $stmt_update_detection_status = $conn->prepare($update_detection_status_sql);
            if (!$stmt_update_detection_status) {
                throw new Exception("Failed to prepare update status query in detections.");
            }
            $stmt_update_detection_status->bind_param("si", $status, $id);
            if (!$stmt_update_detection_status->execute()) {
                throw new Exception("Failed to update status in detections: " . $stmt_update_detection_status->error);
            }
            $stmt_update_detection_status->close();

            $conn->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid status or ID.']);
    }
    exit; 
}

// นับจำนวนแถวทั้งหมดสำหรับ pagination
$sql_count = "SELECT COUNT(id) AS total FROM detections";
$count_result = $conn->query($sql_count);
if ($count_result) {
    $total_rows = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $perPage);
} else {
    $total_rows = 0;
    $total_pages = 1;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
    body {
        font-family: 'Prompt', sans-serif;
    }
    .modal {
        display: none;
    }
    .clickable-row {
        cursor: pointer;
    }
    .clickable-row:hover {
        background-color: #f1f5f9; /* bg-gray-100 */
    }
    .edit-icon {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        font-size: 16px;
        text-decoration: none;
    }
    .edit-icon i {
        margin-right: 5px;
        font-size: 18px;
    }
    .edit-icon:hover {
        color: #007bff;
        transition: color 0.3s ease;
    }
    </style>
</head>
<body class="bg-white text-gray-800">
<div class="flex h-screen">
    <!-- Sidebar -->
    <div class="w-64 bg-white border-r border-gray-200 fixed inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out z-50">
        <div class="p-6">
            <h2 class="text-2xl font-semibold mb-6 text-gray-700">Admin Menu</h2>
            <ul class="space-y-4">
                <li><a href="admin_dashboard.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                    <i class="fas fa-tachometer-alt mr-3 text-gray-600"></i> Dashboard หลัก
                </a></li>
                <li><a href="ChartDashboard.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                    <i class="fas fa-chart-line mr-3 text-gray-600"></i> Dashboard สรุปเหตุการณ์
                </a></li>
                <li><a href="manage_images.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                    <i class="fas fa-images mr-3 text-gray-600"></i> จัดการรูปภาพ
                </a></li>
                <li><a href="test_map.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                    <i class="fas fa-map-marked-alt mr-3 text-gray-600"></i> แผนที่
                </a></li>
                <li><a href="admin_logout.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                    <i class="fas fa-sign-out-alt mr-3 text-gray-600"></i> ออกจากระบบ
                </a></li>
            </ul>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="flex-1 ml-0 md:ml-64">
        <div class="container mx-auto py-6 px-4">
            <!-- Header -->
            <header class="flex justify-between items-center mb-6">
                <h1 class="text-4xl font-bold">Admin Dashboard</h1>
                <button id="sidebarToggle" class="md:hidden text-gray-700 focus:outline-none">
                    <i class="fas fa-bars fa-2x"></i>
                </button>
            </header>

            <!-- Header Alert -->
            <div id="headerAlert" class="fixed top-20 right-5 bg-red-600 text-white px-4 py-2 rounded shadow-lg opacity-0 transform -translate-y-4 transition-all duration-300">
                <span id="headerAlertMessage"></span>
                <button id="closeHeaderAlert" class="ml-4">&times;</button>
            </div>

            <!-- Popup Notification -->
            <div id="animalPopup" class="fixed top-20 right-5 bg-red-600 text-white px-4 py-2 rounded shadow-lg opacity-0 transform -translate-y-4 transition-all duration-300">
                <span id="popupMessage"></span>
                <button id="closePopup" class="ml-4">&times;</button>
            </div>

            <!-- Modal for Image -->
            <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
                <div class="bg-white rounded-lg p-4 relative max-w-3xl w-full">
                    <span class="absolute top-2 right-2 text-2xl font-bold cursor-pointer" id="closeImageModal">&times;</span>
                    <img id="modalImage" src="" alt="Detection Image" class="w-full h-auto rounded">
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold mb-4">ข้อมูลการตรวจจับ</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto border border-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-gray-600 font-semibold">เวลาดำเนินการ</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">ตำแหน่งกล้อง</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">สิ่งที่ตรวจจับ</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">ระดับความรุนแรง</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">รูป</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">แผนที่</th>
                                <th class="px-4 py-2 text-gray-600 font-semibold">สถานะการดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody id="detection-table-body">
                            <?php if (count($markers) > 0 || count($missing_coordinates) > 0): ?>
                                <?php foreach ($markers as $marker): ?>
                                    <tr id="row-<?= safe_htmlspecialchars($marker['id']) ?>" data-id="<?= safe_htmlspecialchars($marker['id']) ?>" class="clickable-row">
                                        <td class="border px-4 py-2"><?= safe_htmlspecialchars($marker['timestamp']) ?></td>
                                        <td class="border px-4 py-2">
                                            <?= safe_htmlspecialchars("กล้องตัวนี้อยู่ที่ " . $marker['camera_address']); ?>
                                        </td>
										<td class="border px-4 py-2">
											<?= $marker['detection_display'] ?>
										</td>
                                        <td class="border px-4 py-2 <?= safe_htmlspecialchars($marker['intensity_class']) ?>">
                                            <?= safe_htmlspecialchars($marker['intensity_text']) ?>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <?php if (!empty($marker['image_path'])): ?>
                                                <button
                                                    class="bg-purple-500 text-white px-3 py-1 rounded hover:bg-purple-600 view-image-button"
                                                    data-image="<?= safe_htmlspecialchars($marker['image_path']) ?>"
                                                >
                                                    View Image
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-500">No Image</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <a href="chang_v3.php?type=cam&id=<?= safe_htmlspecialchars($marker['id']) ?>&lat=<?= safe_htmlspecialchars($marker['lat_cam']) ?>&lng=<?= safe_htmlspecialchars($marker['long_cam']) ?>" class="text-blue-500 hover:underline">Map</a>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <?php
                                                // แสดงสถานะที่แปลแล้ว
                                                echo translateStatus($marker['status']);
                                                
                                                // ถ้าสถานะเป็น 'pending' ให้แสดงไอคอนดินสอเพื่อลิงก์ไป solutions_admin
                                                if ($marker['status'] === 'pending') {
                                                    echo ' <a href="solutions_admin.php?id=' . safe_htmlspecialchars($marker['id']) . '" class="edit-icon text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></a>';
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php foreach ($missing_coordinates as $marker): ?>
                                    <tr id="row-<?= safe_htmlspecialchars($marker['id']) ?>" data-id="<?= safe_htmlspecialchars($marker['id']) ?>" class="clickable-row">
                                        <td class="border px-4 py-2"><?= safe_htmlspecialchars($marker['timestamp']) ?></td>
                                        <td class="border px-4 py-2">
                                            <?= safe_htmlspecialchars("กล้องตัวนี้อยู่ที่ " . $marker['camera_address']); ?>
                                        </td>
										<td class="border px-4 py-2">
											<?= $marker['detection_display'] ?>
										</td>
                                        <td class="border px-4 py-2 <?= safe_htmlspecialchars($marker['intensity_class']) ?>">
                                            <?= safe_htmlspecialchars($marker['intensity_text']) ?>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <?php if (!empty($marker['image_path'])): ?>
                                                <button
                                                    class="bg-purple-500 text-white px-3 py-1 rounded hover:bg-purple-600 view-image-button"
                                                    data-image="<?= safe_htmlspecialchars($marker['image_path']) ?>"
                                                >
                                                    View Image
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-500">No Image</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <a href="test_map.php?type=cam&id=<?= safe_htmlspecialchars($marker['id']) ?>&lat=<?= safe_htmlspecialchars($marker['lat_cam']) ?>&lng=<?= safe_htmlspecialchars($marker['long_cam']) ?>" class="text-blue-500 hover:underline">Map</a>
                                        </td>
                                        <td class="border px-4 py-2">
                                            <?php
                                                echo translateStatus($marker['status']);
                                                if ($marker['status'] === 'pending') {
                                                    echo ' <a href="solutions_admin.php?id=' . safe_htmlspecialchars($marker['id']) . '" class="edit-icon text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></a>';
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="border px-4 py-2 text-center">
                                        ไม่มีข้อมูลที่ต้องแสดง
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="flex justify-between items-center mt-6">
                    <div>
                        <span class="text-gray-600">Page <?= safe_htmlspecialchars($page) ?> of <?= safe_htmlspecialchars($total_pages) ?></span>
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= safe_htmlspecialchars($page - 1) ?>"
                               class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600"
                            >
                                Prev
                            </a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= safe_htmlspecialchars($page + 1) ?>"
                               class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600"
                            >
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>

<script>
    // Data from PHP
    let missingData = <?php echo json_encode($missing_coordinates, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    let initialMarkers = <?php echo json_encode($markers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    let lastDetectionID = <?= $last_id ?>;

    // Escape HTML
    function safe_htmlspecialchars(str) {
        if (typeof str !== 'string') return '';
        return str
            .replace(/&/g,"&amp;")
            .replace(/</g,"&lt;")
            .replace(/>/g,"&gt;")
            .replace(/"/g,"&quot;")
            .replace(/'/g,"&#039;");
    }

    // ฟังก์ชันแปลง Intensity Level
    function getIntensityLevel(elephant, alert, distance) {
        if (distance <= 1) {
            return 'ฉุกเฉิน';
        } else if (elephant && alert) {
            return 'ความเสี่ยงสูง';
        } else if (elephant && !alert) {
            return 'ความเสี่ยงปานกลาง';
        } else {
            return 'ปกติ';
        }
    }

    // ฟังก์ชันแปลง Intensity Level เป็นคลาสสี
    function getIntensityClass(intensityLevel) {
        switch(intensityLevel) {
            case 'ฉุกเฉิน':
                return 'bg-red-600 text-white';
            case 'ความเสี่ยงสูง':
                return 'bg-red-300 text-red-800';
            case 'ความเสี่ยงปานกลาง':
                return 'bg-yellow-200 text-gray-700';
            default:
                return 'bg-white text-black';
        }
    }

    // Modal (image)
    function openImageModal(path) {
        if (!path) return;
        const modal = document.getElementById("imageModal");
        const modalImg = document.getElementById("modalImage");
        modalImg.src = path;
        modal.classList.remove('hidden');
    }
    function closeImageModal() {
        const modal = document.getElementById("imageModal");
        modal.classList.add('hidden');
        document.getElementById("modalImage").src = "";
    }
    window.addEventListener('click', e => {
        if (e.target.id === "imageModal") closeImageModal();
    });

    // เพิ่มแถวข้อมูล (กรณีพิกัดกล้อง/ช้างไม่ครบ)
    function addMissingDetectionToTable(d) {
        const tb = document.getElementById("detection-table-body");
        const row = document.createElement("tr");

        const intensityLevel = getIntensityLevel(d.elephant, d.alert, d.distance_ele);
        const intensityClass = getIntensityClass(intensityLevel);

        let cameraAddressText = '';
        if (d.camera_address !== "ไม่ทราบสถานที่") {
            cameraAddressText = safe_htmlspecialchars(d.camera_address);
        } else {
            if (d.lat_cam !== null && d.long_cam !== null) {
                cameraAddressText = `${safe_htmlspecialchars(d.lat_cam)}, ${safe_htmlspecialchars(d.long_cam)}`;
            } else {
                cameraAddressText = '<span class="text-red-800">Camera coords missing</span>';
            }
        }

        row.innerHTML = `
            <td class="border px-4 py-2">${safe_htmlspecialchars(d.timestamp)}</td>
            <td class="border px-4 py-2">${cameraAddressText}</td>
            <td class="border px-4 py-2">${d.detection_display}</td>
            <td class="border px-4 py-2 ${intensityClass}">${intensityLevel}</td>
            <td class="border px-4 py-2">
                ${
                    d.image_path
                    ? `<button class="bg-purple-500 text-white px-3 py-1 rounded hover:bg-purple-600 view-image-button" data-image="${safe_htmlspecialchars(d.image_path)}">View Image</button>`
                    : '<span class="text-gray-500">No Image</span>'
                }
            </td>
            <td class="border px-4 py-2">
                <a href="test_map.php?type=cam&id=${encodeURIComponent(d.id)}&lat=${encodeURIComponent(d.lat_cam)}&lng=${encodeURIComponent(d.long_cam)}" class="text-blue-500 hover:underline">Map</a>
            </td>
            <td class="border px-4 py-2">
                ${safe_htmlspecialchars(d.status)}
                ${ d.status === 'pending' ? ` <a href="solutions_admin.php?id=${safe_htmlspecialchars(d.id)}" class="edit-icon text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></a>` : '' }
            </td>
        `;
        tb.prepend(row);

        // ลบแถวท้ายสุดถ้าเกิน 10
        while(tb.rows.length > 10){
            tb.deleteRow(-1);
        }
    }

    // เพิ่มแถวข้อมูล (กรณีมีพิกัดกล้อง/ช้างครบ)
    function addDetectionToTable(d){
        const tb = document.getElementById('detection-table-body');
        const row = document.createElement('tr');

        const intensityLevel = getIntensityLevel(d.elephant, d.alert, d.distance_ele);
        const intensityClass = getIntensityClass(intensityLevel);

        row.innerHTML=`
            <td class="border px-4 py-2">${safe_htmlspecialchars(d.timestamp)}</td>
            <td class="border px-4 py-2">กล้องตัวนี้อยู่ที่ ${safe_htmlspecialchars(d.camera_address)}</td>
            <td class="border px-4 py-2">${d.detection_display}</td>
            <td class="border px-4 py-2 ${intensityClass}">${intensityLevel}</td>
            <td class="border px-4 py-2">
                ${
                    d.image_path
                    ? `<button class="bg-purple-500 text-white px-3 py-1 rounded hover:bg-purple-600 view-image-button" data-image="${safe_htmlspecialchars(d.image_path)}">View Image</button>`
                    : '<span class="text-gray-500">No Image</span>'
                }
            </td>
            <td class="border px-4 py-2">
                <a href="test_map.php?type=cam&id=${encodeURIComponent(d.id)}&lat=${encodeURIComponent(d.lat_cam)}&lng=${encodeURIComponent(d.long_cam)}" class="text-blue-500 hover:underline">Map</a>
            </td>
            <td class="border px-4 py-2">
                ${safe_htmlspecialchars(d.status)}
                ${ d.status === 'pending' ? ` <a href="solutions_admin.php?id=${safe_htmlspecialchars(d.id)}" class="edit-icon text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></a>` : '' }
            </td>
        `;
        tb.prepend(row);

        // ลบแถวท้ายสุดถ้าเกิน 10
        while(tb.rows.length > 10){
            tb.deleteRow(-1);
        }
    }

    let alertTimeout = null;
    let lastDetectionTime = null;

    function showHeaderAlert(msg, colorClass) {
        const hd = document.getElementById("headerAlert");
        const mg = document.getElementById("headerAlertMessage");
        if (!hd || !mg) return;
        hd.className = `fixed top-20 right-5 ${colorClass} px-4 py-2 rounded shadow-lg transition-all duration-300`;
        mg.textContent = msg;
        hd.classList.remove('opacity-0', '-translate-y-4');
        hd.classList.add('opacity-100', 'translate-y-0');
    }
    function hideHeaderAlert() {
        const hd = document.getElementById("headerAlert");
        if (!hd) return;
        hd.classList.remove('opacity-100', 'translate-y-0');
        hd.classList.add('opacity-0', '-translate-y-4');
    }

    function showPopup(msg, colorClass) {
        const popup = document.getElementById("animalPopup");
        const pm = document.getElementById("popupMessage");
        if (!popup || !pm) return;
        popup.className = `fixed top-20 right-5 ${colorClass} px-4 py-2 rounded shadow-lg transition-all duration-300`;
        pm.textContent = msg;
        popup.classList.remove('opacity-0', '-translate-y-4');
        popup.classList.add('opacity-100', 'translate-y-0');
        resetAlertTimeout();
    }
    function hidePopup() {
        const popup = document.getElementById("animalPopup");
        if (!popup) return;
        popup.classList.remove('opacity-100', 'translate-y-0');
        popup.classList.add('opacity-0', '-translate-y-4');
        document.getElementById('popupMessage').textContent = '';
        clearTimeout(alertTimeout);
    }

    function resetAlertTimeout() {
        clearTimeout(alertTimeout);
        alertTimeout = setTimeout(() => {
            hideHeaderAlert();
            hidePopup();
            lastDetectionTime = null;
        }, 60000); // 1 นาที
    }

    function handleNewDetection(d) {
        let message = '';
        let colorClass = '';
        let needAlert = false;

        if (d.distance_ele <= 1) {
            message = `⚠️ ฉุกเฉิน! ช้างเข้าใกล้มาก! ตำแหน่ง ${safe_htmlspecialchars(d.camera_address)}`;
            colorClass = 'bg-red-600 text-white';
            needAlert = true;
        }
        else {
            if (d.elephant && d.alert) {
                message = `⚠️ เจอช้างและรถ! ตำแหน่ง ${safe_htmlspecialchars(d.camera_address)}`;
                colorClass = 'bg-red-300 text-red-800';
                needAlert = true;
            }
            else if (d.elephant && !d.alert) {
                message = `⚠️ พบช้าง! (ไม่มี Alert รถ) ตำแหน่ง ${safe_htmlspecialchars(d.camera_address)}`;
                colorClass = 'bg-yellow-200 text-gray-700';
                needAlert = true;
            }
        }

        if (needAlert) {
            showPopup(message, colorClass);
            showHeaderAlert("แจ้งเตือน: " + message, colorClass);
            lastDetectionTime = Date.now();
        }
    }

    function fetchNewData() {
		fetch(`../elephant_api/get_detections.php?last_id=${lastDetectionID}`, {
			credentials: 'same-origin'
		})
        .then(res => {
            if (!res.ok) throw new Error("Network error: " + res.statusText);
            return res.json();
        })
        .then(dt => {
            if (dt && dt.status === 'success' && Array.isArray(dt.data)) {
                let newIDfound = false;
                dt.data.forEach(d => {
                    if (d.id > lastDetectionID) {
                        if (!d.lat_cam || !d.long_cam || !d.elephant_lat || !d.elephant_long) {
                            addMissingDetectionToTable(d);
                        } else {
                            addDetectionToTable(d);
                        }
                        handleNewDetection(d);
                        newIDfound = true;
                        if (d.id > lastDetectionID) {
                            lastDetectionID = d.id;
                        }
                    }
                });
                if (newIDfound) {
                    lastDetectionTime = Date.now();
                }
            }
        })
        .catch(err => {
            console.error("Error fetch new data:", err);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('closeHeaderAlert').addEventListener('click', hideHeaderAlert);
        document.getElementById('closePopup').addEventListener('click', hidePopup);
        document.getElementById('closeImageModal').addEventListener('click', closeImageModal);

        // เช็ค Timeout ทุก 1 วิ
        setInterval(() => {
            if (lastDetectionTime && (Date.now() - lastDetectionTime > 60000)) {
                hideHeaderAlert();
                hidePopup();
                lastDetectionTime = null;
            }
        }, 1000);
        
        // ดึงข้อมูลใหม่ทุก 5 วิ
        setInterval(fetchNewData, 5000);

        // Event delegation สำหรับ table-body
        document.getElementById('detection-table-body').addEventListener('click', function(event) {
            const row = event.target.closest('tr.clickable-row');
            if (row) {
                if (event.target.closest('select') || event.target.closest('button') || event.target.closest('a') || event.target.closest('form')) {
                    return;
                }
                const id = row.getAttribute('data-id');
                if (id) {
                    window.location.href = `solutions_admin.php?id=${encodeURIComponent(id)}`;
                }
            }
            // ตรวจสอบปุ่ม View Image
            if (event.target && event.target.classList.contains('view-image-button')) {
                const imagePath = event.target.getAttribute('data-image');
                openImageModal(imagePath);
            }
        });
    });
</script>

<!-- Optional loading screen -->
<div class="fixed inset-0 flex items-center justify-center bg-white bg-opacity-95 z-50 rounded-lg hidden">
    <div class="flex flex-col items-center">
        <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        <span class="mt-2 text-gray-700">Loading...</span>
    </div>
</div>

</body>
</html>
