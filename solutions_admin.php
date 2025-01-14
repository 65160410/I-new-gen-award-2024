<?php
// solutions_admin.php

// เปิดการแสดงข้อผิดพลาด (DEBUG) -- ปิดใน production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// Include database connection
include '../elephant_api/db.php';

// Check DB connection
if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection failed. Please try again later.");
}

// Function to sanitize output
function safe_htmlspecialchars($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Reverse Geocoding Function using Nominatim API
function getAddressFromCoords($lat, $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&addressdetails=1";
    
    // Specify User-Agent as per Nominatim's policy
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
        // Construct address from received data
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

// Function to determine Intensity Level and CSS class
function getIntensityInfo($elephant, $alert, $distance) {
    if (floatval($distance) <= 1) {
        return ['text' => 'ฉุกเฉิน', 'class' => 'bg-red-600 text-white'];
    } elseif ($elephant && $alert) {
        return ['text' => 'ความเสี่ยงสูง', 'class' => 'bg-red-300 text-red-800'];
    } elseif ($elephant && !$alert) { // Adjust condition as needed
        return ['text' => 'ความเสี่ยงปานกลาง', 'class' => 'bg-yellow-200 text-gray-700'];
    } else {
        return ['text' => 'ปกติ', 'class' => 'bg-white text-black'];
    }
}

// Function to translate status from English to Thai
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

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Read camera_locations.txt into associative array
$camera_locations = [];
$camera_locations_file = 'camera_locations.txt'; // Ensure this path is correct
if (file_exists($camera_locations_file)) {
    $lines = file($camera_locations_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

// Get detection_id from URL
$detection_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($detection_id <= 0) {
    die("Invalid Detection ID.");
}

// Fetch detection data
$sql_detection = "SELECT * FROM detections WHERE id = ?";
$stmt_detection = $conn->prepare($sql_detection);
if (!$stmt_detection) {
    die("Prepare failed: " . $conn->error);
}
$stmt_detection->bind_param("i", $detection_id);
$stmt_detection->execute();
$result_detection = $stmt_detection->get_result();
$detection = $result_detection->fetch_assoc();
$stmt_detection->close();

if (!$detection) {
    die("Detection not found.");
}

// Debugging: แสดงข้อมูล detection
// echo "<pre>Detection Data: ";
// print_r($detection);
// echo "</pre>";

// Compute camera_address using camera_id from camera_locations.txt or reverse geocoding
if (!empty($detection['camera_id'])) {
    if (isset($camera_locations[$detection['camera_id']])) {
        $detection['camera_address'] = $camera_locations[$detection['camera_id']];
    } elseif (!is_null($detection['lat_cam']) && !is_null($detection['long_cam'])) {
        // Perform reverse geocoding
        $detection['camera_address'] = getAddressFromCoords($detection['lat_cam'], $detection['long_cam']);
    } else {
        $detection['camera_address'] = "ไม่ทราบสถานที่";
    }
} else {
    $detection['camera_address'] = "ไม่ทราบสถานที่";
}

// Debugging: แสดง camera_address
// echo "<pre>Camera Address: " . $detection['camera_address'] . "</pre>";

// Compute intensity information
$intensityInfo = getIntensityInfo($detection['elephant'], $detection['alert'], $detection['distance_ele']);
$detection['intensity_text'] = $intensityInfo['text'];
$detection['intensity_class'] = $intensityInfo['class'];

// Fetch existing solution details
$sql_details = "SELECT * FROM solutions_admin WHERE detection_id = ? ORDER BY action_date DESC";
$stmt_details = $conn->prepare($sql_details);
if (!$stmt_details) {
    die("Prepare failed: " . $conn->error);
}
$stmt_details->bind_param("i", $detection_id);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
$solution_details = $result_details->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// Handle form submission to add new solution detail
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    // Retrieve and sanitize form inputs
    $detection_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $solution_method = isset($_POST['solution_method']) ? trim($_POST['solution_method']) : '';
    $responsible_person = isset($_POST['responsible_person']) ? trim($_POST['responsible_person']) : '';
    $damage_occurred = isset($_POST['damage_occurred']) ? trim($_POST['damage_occurred']) : '';
    $action_date = isset($_POST['action_date']) ? $_POST['action_date'] : '';
    $solution_status = isset($_POST['solution_status']) ? strtolower(trim($_POST['solution_status'])) : ''; // แปลงเป็นตัวพิมพ์เล็ก

    // Validate inputs
    $errors = [];
    if (empty($solution_method)) {
        $errors[] = "กรุณาใส่วิธีแก้ไขปัญหา.";
    }
    if (empty($responsible_person)) {
        $errors[] = "กรุณาใส่ชื่อผู้รับผิดชอบ.";
    }
    if (empty($action_date)) {
        $errors[] = "กรุณาเลือกวันที่ดำเนินการ.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $action_date)) {
        $errors[] = "วันที่ดำเนินการไม่ถูกต้อง.";
    }
    if (empty($solution_status)) {
        $errors[] = "กรุณาเลือกสถานะการแก้ไข.";
    }

    // Validate solution_status value
    $allowed_statuses = ['pending', 'completed'];
    if (!in_array($solution_status, $allowed_statuses)) {
        $errors[] = "สถานะการแก้ไขไม่ถูกต้อง.";
    }

    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();

        try {
            // 1. Update status in solutions_admin table for all records with the detection_id
            // **หมายเหตุ:** ถ้าต้องการอัปเดตสถานะเฉพาะรายการล่าสุด ให้ปรับเปลี่ยนที่นี่
            $update_solution_status_sql = "UPDATE solutions_admin SET solution_status = ? WHERE detection_id = ?";
            $stmt_update_solution_status = $conn->prepare($update_solution_status_sql);
            if (!$stmt_update_solution_status) {
                throw new Exception("Failed to prepare update status query in solutions_admin.");
            }
            $stmt_update_solution_status->bind_param("si", $solution_status, $detection_id);
            if (!$stmt_update_solution_status->execute()) {
                throw new Exception("Failed to update status in solutions_admin: " . $stmt_update_solution_status->error);
            }
            $stmt_update_solution_status->close();

            // 2. Update status in detections table to match the solution status
            $update_detection_status_sql = "UPDATE detections SET status = ? WHERE id = ?";
            $stmt_update_detection_status = $conn->prepare($update_detection_status_sql);
            if (!$stmt_update_detection_status) {
                throw new Exception("Failed to prepare update status query in detections.");
            }
            $stmt_update_detection_status->bind_param("si", $solution_status, $detection_id);
            if (!$stmt_update_detection_status->execute()) {
                throw new Exception("Failed to update status in detections: " . $stmt_update_detection_status->error);
            }
            $stmt_update_detection_status->close();

            // 3. Insert new solution detail into solutions_admin
            $insert_solution_sql = "INSERT INTO solutions_admin (detection_id, solution_method, responsible_person, damage_occurred, action_date, solution_status) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_insert_solution = $conn->prepare($insert_solution_sql);
            if (!$stmt_insert_solution) {
                throw new Exception("Failed to prepare insert solution query.");
            }
            $stmt_insert_solution->bind_param("isssss", $detection_id, $solution_method, $responsible_person, $damage_occurred, $action_date, $solution_status);
            if (!$stmt_insert_solution->execute()) {
                throw new Exception("Failed to insert solution detail: " . $stmt_insert_solution->error);
            }
            $stmt_insert_solution->close();

            // Commit transaction
            $conn->commit();

            // Regenerate CSRF token to prevent CSRF attacks
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf_token = $_SESSION['csrf_token']; // อัปเดตค่าในตัวแปร

            // Redirect to the same page with success message
            header("Location: solutions_admin.php?id=$detection_id&success=1");
            exit;

        } catch (Exception $e) {
            // Rollback transaction in case of error
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}

// Fetch detection data again in case it was updated
$sql_detection = "SELECT * FROM detections WHERE id = ?";
$stmt_detection = $conn->prepare($sql_detection);
if (!$stmt_detection) {
    die("Prepare failed: " . $conn->error);
}
$stmt_detection->bind_param("i", $detection_id);
$stmt_detection->execute();
$result_detection = $stmt_detection->get_result();
$detection = $result_detection->fetch_assoc();
$stmt_detection->close();

if (!$detection) {
    die("Detection not found.");
}

// Compute camera_address using camera_id from camera_locations.txt or reverse geocoding
if (!empty($detection['camera_id'])) {
    if (isset($camera_locations[$detection['camera_id']])) {
        $detection['camera_address'] = $camera_locations[$detection['camera_id']];
    } elseif (!is_null($detection['lat_cam']) && !is_null($detection['long_cam'])) {
        // Perform reverse geocoding
        $detection['camera_address'] = getAddressFromCoords($detection['lat_cam'], $detection['long_cam']);
    } else {
        $detection['camera_address'] = "ไม่ทราบสถานที่";
    }
} else {
    $detection['camera_address'] = "ไม่ทราบสถานที่";
}

// Compute intensity information
$intensityInfo = getIntensityInfo($detection['elephant'], $detection['alert'], $detection['distance_ele']);
$detection['intensity_text'] = $intensityInfo['text'];
$detection['intensity_class'] = $intensityInfo['class'];

// Fetch existing solution details again
$sql_details = "SELECT * FROM solutions_admin WHERE detection_id = ? ORDER BY action_date DESC";
$stmt_details = $conn->prepare($sql_details);
if (!$stmt_details) {
    die("Prepare failed: " . $conn->error);
}
$stmt_details->bind_param("i", $detection_id);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
$solution_details = $result_details->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// Determine if detection is completed
$isCompleted = ($detection['status'] === 'completed');
$isPending = ($detection['status'] === 'pending');

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Solutions Admin</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Prompt', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Navbar -->
    <nav class="bg-white shadow">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <a href="admin_dashboard.php" class="text-xl font-bold text-blue-600">Admin Dashboard</a>
            <a href="admin_logout.php" class="text-gray-600 hover:text-red-600">ออกจากระบบ</a>
        </div>
    </nav>

    <div class="container mx-auto py-8 px-4">
        <!-- Detection Information Card -->
        <div class="bg-white shadow-md rounded-lg p-6 mb-8">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">Detection Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <p><strong>เวลาดำเนินการ:</strong> <?= isset($detection['time']) ? safe_htmlspecialchars($detection['time']) : 'N/A' ?></p>
                <p><strong>ตำแหน่งกล้อง:</strong> <?= safe_htmlspecialchars($detection['camera_address']) ?></p>
                <p><strong>สิ่งที่ตรวจจับ:</strong> <?= ($detection['lat_ele'] !== null && $detection['long_ele'] !== null) ? safe_htmlspecialchars($detection['lat_ele']) . ", " . safe_htmlspecialchars($detection['long_ele']) : '<span class="text-red-600">Elephant missing</span>' ?></p>
                <p><strong>ระดับความรุนแรง:</strong> <span class="<?= safe_htmlspecialchars($detection['intensity_class']) ?> px-2 py-1 rounded"><?= safe_htmlspecialchars($detection['intensity_text']) ?></span></p>
                <p><strong>สถานะการดำเนินการ:</strong> <?= safe_htmlspecialchars(translateStatus($detection['status'])) ?></p>
            </div>
        </div>

        <!-- Success Alert -->
        <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                <span class="block sm:inline">เพิ่มรายละเอียดการแก้ไขสำเร็จ!</span>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?= safe_htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Form to Add Solution Details -->
        <?php if (!$isCompleted): ?>
            <form method="post" class="bg-white shadow-md rounded-lg p-6 mb-8" id="solutionForm">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= safe_htmlspecialchars($csrf_token) ?>">

                <h2 class="text-2xl font-semibold mb-4 text-gray-800">เพิ่มรายละเอียดการแก้ไข</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-gray-700 mb-2">วิธีแก้ไขปัญหา:</label>
                        <textarea name="solution_method" class="w-full border border-gray-300 p-3 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3" required placeholder="อธิบายวิธีแก้ปัญหาที่ใช้.."><?= isset($_POST['solution_method']) ? safe_htmlspecialchars($_POST['solution_method']) : '' ?></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-2">ผู้รับผิดชอบ:</label>
                        <input type="text" name="responsible_person" class="w-full border border-gray-300 p-3 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= isset($_POST['responsible_person']) ? safe_htmlspecialchars($_POST['responsible_person']) : '' ?>" required placeholder="ระบุชื่อผู้รับผิดชอบ">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-gray-700 mb-2">ความเสียหายที่เกิดขึ้น:</label>
                        <textarea name="damage_occurred" class="w-full border border-gray-300 p-3 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3"  placeholder="อธิบายความเสียหายที่เกิดขึ้น (ถ้ามี)"><?= isset($_POST['damage_occurred']) ? safe_htmlspecialchars($_POST['damage_occurred']) : '' ?></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-2">วันที่ดำเนินการ:</label>
                        <input type="date" name="action_date" class="w-full border border-gray-300 p-3 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= isset($_POST['action_date']) ? safe_htmlspecialchars($_POST['action_date']) : '' ?>" required>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-2">สถานะการแก้ไข:</label>
                        <select name="solution_status" class="w-full border border-gray-300 p-3 rounded bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">-- เลือกสถานะ --</option>
                            <option value="pending" <?= (isset($_POST['solution_status']) && strtolower($_POST['solution_status']) === 'pending') ? 'selected' : '' ?>>รอดำเนินการ</option>
                            <option value="completed" <?= (isset($_POST['solution_status']) && strtolower($_POST['solution_status']) === 'completed') ? 'selected' : '' ?>>ดำเนินการแล้ว</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-between mt-6">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition duration-200">เพิ่มรายละเอียด</button>
                </div>
            </form>
        <?php endif; ?>
<div id="solutionDiv">
        <!-- Existing Solution Details -->
        <h2 class="text-2xl font-semibold mb-4 text-gray-800">บุคคลยืนยันตัวตน</h2>
        <?php if (count($solution_details) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr>
                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">วิธีแก้ไขปัญหา</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ผู้รับผิดชอบ</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ความเสียหายที่เกิดขึ้น</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">วันที่ดำเนินการ</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">สถานะการแก้ไข</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($solution_details as $detail): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 border-b border-gray-200"><?= safe_htmlspecialchars($detail['solution_method']) ?></td>
                                <td class="px-6 py-4 border-b border-gray-200"><?= safe_htmlspecialchars($detail['responsible_person']) ?></td>
                                <td class="px-6 py-4 border-b border-gray-200"><?= safe_htmlspecialchars($detail['damage_occurred']) ?></td>
                                <td class="px-6 py-4 border-b border-gray-200"><?= safe_htmlspecialchars($detail['action_date']) ?></td>
                                <td class="px-6 py-4 border-b border-gray-200">
                                    <span class="<?= strtolower($detail['solution_status']) === 'completed' ? 'bg-green-100 text-green-800 px-2 py-1 rounded' : 'bg-yellow-100 text-yellow-800 px-2 py-1 rounded' ?>">
                                        <?= safe_htmlspecialchars(translateStatus($detail['solution_status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-600">ไม่มีรายละเอียดการแก้ไขสำหรับการตรวจจับนี้.</p>
        <?php endif; ?>
</div>
        <div class="flex justify-between mt-6">
            <a href="admin_dashboard.php" class="text-gray-600 hover:text-blue-600">← กลับไปที่ Dashboard</a>
        </div>
    </div>

    <!-- Optional loading screen -->
    <div class="fixed inset-0 flex items-center justify-center bg-white bg-opacity-95 z-50 rounded-lg hidden">
        <div class="flex flex-col items-center">
            <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
            <span class="mt-2 text-gray-700">Loading...</span>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
document.addEventListener("DOMContentLoaded", function() {
    var isCompleted = <?php echo $detection['status'] === 'completed' ? 'true' : 'false'; ?>;
    
    // ตรวจสอบสถานะการดำเนินการ
    if (isCompleted) {
        document.getElementById('solutionDiv').style.display = 'block'; // แสดงรายละเอียดการแก้ไขที่มีอยู่
        document.getElementById('solutionForm').style.display = 'none'; // ซ่อนฟอร์มเพิ่มรายละเอียด
    } else {
        document.getElementById('solutionDiv').style.display = 'none'; // ซ่อนรายละเอียดการแก้ไขที่มีอยู่
        document.getElementById('solutionForm').style.display = 'block'; // แสดงฟอร์มเพิ่มรายละเอียด
    }
});

    </script>
</body>
</html>
