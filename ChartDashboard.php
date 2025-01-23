<?php
// ChartDashboard.php
// เริ่มต้น Output Buffering
ob_start();

// ปรับการตั้งค่าการแสดงข้อผิดพลาด
if (isset($_GET['action'])) {
    // ปิดการแสดงข้อผิดพลาดสำหรับ AJAX
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
} else {
    // เปิดการแสดงข้อผิดพลาดสำหรับการเรียกหน้าเว็บปกติ
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

session_start();

// กำหนดค่าเวลาสำหรับการหมดเวลาเซสชัน (30 นาที)
define('SESSION_TIMEOUT', 1800);

// ตรวจสอบการเข้าสู่ระบบแอดมิน
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// ตรวจสอบการหมดเวลาเซสชัน
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time(); // อัพเดทเวลาการใช้งานล่าสุด

// ป้องกันการโจมตีแบบ Session Fixation
if (!isset($_SESSION['INITIATED'])) {
    session_regenerate_id(true);
    $_SESSION['INITIATED'] = true;
}

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    if (isset($_GET['action'])) {
        // สำหรับ AJAX ให้ตอบกลับด้วย JSON
        header('Content-Type: application/json');
        echo json_encode(["error" => "Connection failed."]);
    } else {
        die("Connection failed.");
    }
    exit;
}

/**
 * ฟังก์ชันสำหรับจัดกลุ่มข้อมูลตามช่วงเวลา (day/month/year/all)
 */
function getGroupByClause($groupBy, $timeField) {
    switch ($groupBy) {
        case 'day':
            return "DATE($timeField)";
        case 'month':
            return "DATE_FORMAT($timeField, '%Y-%m')";
        case 'year':
            return "YEAR($timeField)";
        default:
            return "DATE($timeField)";
    }
}

/**
 * ฟังก์ชันสำหรับดึงข้อมูลกราฟประเภทต่าง ๆ
 * รองรับ elephant_count, injury_death, operation_status
 */
function getChartData($conn, $chartType, $groupBy) {

    // Default groupByClause สำหรับตารางที่ใช้ฟิลด์ 'time' (detections, incident_details)
    $groupByClause = getGroupByClause($groupBy, 'time'); 

    switch ($chartType) {

        // 1) กราฟจำนวนเหตุการณ์ช้าง (elephant_count)
        case 'elephant_count':
            $query = "
                SELECT 
                    $groupByClause AS period,
                    COUNT(elephant) AS count
                FROM detections
                WHERE elephant > 0
                GROUP BY $groupByClause
                ORDER BY $groupByClause
            ";
            break;

        // 2) กราฟจำนวนบาดเจ็บ/เสียชีวิต (injury_death)
        case 'injury_death':
            $query = "
                SELECT 
                    $groupByClause AS date,
                    SUM(fatalities) AS total_fatalities,
                    SUM(injuries)  AS total_injuries
                FROM incident_details
                GROUP BY $groupByClause
                ORDER BY $groupByClause
            ";
            break;

        // 3) กราฟสถานะการดำเนินงาน (operation_status)
        case 'operation_status':

            // ฝั่ง solutions_admin ใช้คอลัมน์ 'action_date' เป็นฟิลด์เวลา
            $groupByClauseCompleted = getGroupByClause($groupBy, 'action_date');

            // ฝั่ง detections ใช้ฟิลด์ 'time'
            $groupByClausePending = getGroupByClause($groupBy, 'time');

            // ดึงข้อมูล completed จาก solutions_admin
            $sqlCompleted = "
                SELECT 
                    $groupByClauseCompleted AS period,
                    COUNT(*) AS completed
                FROM solutions_admin
                WHERE solution_status = 'completed'
                GROUP BY $groupByClauseCompleted
                ORDER BY $groupByClauseCompleted
            ";

            // ดึงข้อมูล pending จาก detections
            $sqlPending = "
                SELECT 
                    $groupByClausePending AS period,
                    COUNT(*) AS pending
                FROM detections
                WHERE status = 'pending'
                GROUP BY $groupByClausePending
                ORDER BY $groupByClausePending
            ";

            // Query ชุด completed
            $completedData = [];
            if ($stmt = $conn->prepare($sqlCompleted)) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $completedData[$row['period']] = (int)$row['completed'];
                }
                $stmt->close();
            }

            // Query ชุด pending
            $pendingData = [];
            if ($stmt = $conn->prepare($sqlPending)) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $pendingData[$row['period']] = (int)$row['pending'];
                }
                $stmt->close();
            }

            // รวมเป็น array ใหญ่โดยมี key เป็น period
            $allPeriods = array_unique(array_merge(array_keys($completedData), array_keys($pendingData)));
            sort($allPeriods); // เรียงตามลำดับเวลา

            $data = [];
            foreach ($allPeriods as $p) {
                $data[] = [
                    'period'    => $p,
                    'completed' => isset($completedData[$p]) ? $completedData[$p] : 0,
                    'pending'   => isset($pendingData[$p])   ? $pendingData[$p]   : 0,
                ];
            }
            return $data;

        // หากไม่ตรงกับ chartType ที่กำหนด
        default:
            return [];
    }

    // ส่วนประมวลผลการ query (เฉพาะ elephant_count, injury_death)
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return [];
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    return $data;
}


// ------------------------------------------------------------
// ตรวจสอบ action=fetch_data (ดึงข้อมูลกราฟ) ผ่าน AJAX
// ------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'fetch_data') {
    // ล้าง Output Buffer ก่อนส่ง JSON
    ob_clean();

    $allowedGroupBy = ['day', 'month', 'year']; 
    $chartType = isset($_GET['chart']) ? $_GET['chart'] : null;
    $groupBy   = isset($_GET['group_by']) && in_array($_GET['group_by'], $allowedGroupBy) 
                    ? $_GET['group_by'] 
                    : 'day';

    if ($chartType) {
        $data = getChartData($conn, $chartType, $groupBy);
        header('Content-Type: application/json');
        echo json_encode($data);
    } else {
        // หากไม่มี chartType หรือค่าไม่ถูกต้อง
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid chart type']);
    }
    exit;
}


// ------------------------------------------------------------
// ฟังก์ชัน getCounts() สำหรับดึงค่าต่างๆ (Card สรุปด้านบน)
// ------------------------------------------------------------
function getCounts($conn, $totalCameras) {
    $counts = [];
    $queries = [
        'elephantsOnRoadCount' => "SELECT COUNT(*) AS count FROM detections WHERE elephant = ?",
        'elephantsCarCount'    => "SELECT COUNT(*) AS count FROM detections WHERE elephant = ? AND alert = ?",
        'statusCountcompleted' => "SELECT COUNT(*) AS count FROM detections WHERE status = ?",
        'statusCountpending'   => "SELECT COUNT(*) AS count FROM detections WHERE status = ?",
    ];

    // elephantsOnRoadCount
    $stmt = $conn->prepare($queries['elephantsOnRoadCount']);
    $elephant = '1';
    $stmt->bind_param("s", $elephant);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['elephantsOnRoadCount'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // elephantsCarCount
    $stmt = $conn->prepare($queries['elephantsCarCount']);
    $alert = '1';
    $stmt->bind_param("ss", $elephant, $alert);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['elephantsCarCount'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // statusCountcompleted (นับจาก detections.status = 'completed')
    $stmt = $conn->prepare($queries['statusCountcompleted']);
    $statusCompleted = 'completed';
    $stmt->bind_param("s", $statusCompleted);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['statusCountcompleted'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // statusCountpending (นับจาก detections.status = 'pending')
    $stmt = $conn->prepare($queries['statusCountpending']);
    $statusPending = 'pending';
    $stmt->bind_param("s", $statusPending);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['statusCountpending'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // กล้องออนไลน์ (ส่งข้อมูลภายใน 40 วินาที)
    $stmt = $conn->prepare("SELECT DISTINCT id_cam FROM detections WHERE time >= (NOW() - INTERVAL 40 SECOND)");
    $stmt->execute();
    $result = $stmt->get_result();
    $onlineCameras = [];
    while ($row = $result->fetch_assoc()) {
        $onlineCameras[] = $row['id_cam']; 
    }
    $stmt->close();

    // หารายชื่อกล้องทั้งหมด (สมมติว่ามี 8 ตัว)
    $allCameras = [
        "SOURCE0_CAM_001",
        "SOURCE0_CAM_002",
        "SOURCE0_CAM_003",
        "SOURCE0_CAM_004",
        "SOURCE0_CAM_005",
        "SOURCE0_CAM_006",
        "SOURCE0_CAM_007",
        "SOURCE0_CAM_008",
    ];
    $offlineCameras = array_diff($allCameras, $onlineCameras);

    // นับจำนวนกล้องออนไลน์ / ออฟไลน์
    $camerasOnline  = count($onlineCameras);
    $camerasOffline = count($offlineCameras);

    // เก็บค่าใน array
    $counts['CamOffline']    = $camerasOffline;
    $counts['camerasOnline'] = $camerasOnline;

    return $counts;
}

// ------------------- ส่วนดึงข้อมูล/เตรียมแสดงหน้าเว็บ ------------------- //

// กำหนดจำนวนกล้องทั้งหมดเป็น 8 ตัว
$totalCameras = 8;

// ดึงข้อมูลสถิติต่างๆ สำหรับแสดงใน Cards
$counts = getCounts($conn, $totalCameras);

// ตรวจสอบการเรียกผ่าน AJAX (เฉพาะ fetch_camera_status)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_camera_status') {
    // ล้าง Output Buffer ก่อนส่ง JSON
    ob_clean();

    // ดึงรายชื่อกล้องที่ออนไลน์ (ภายใน 40 วินาที)
    $stmt = $conn->prepare("SELECT DISTINCT id_cam FROM detections WHERE time >= (NOW() - INTERVAL 40 SECOND)");
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Prepare failed']);
        exit;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $onlineCameras = [];
    while ($row = $result->fetch_assoc()) {
        $onlineCameras[] = $row['id_cam'];
    }
    $stmt->close();

    // รายชื่อกล้องทั้งหมด
    $allCameras = [
        "SOURCE0_CAM_001",
        "SOURCE0_CAM_002",
        "SOURCE0_CAM_003",
        "SOURCE0_CAM_004",
        "SOURCE0_CAM_005",
        "SOURCE0_CAM_006",
        "SOURCE0_CAM_007",
        "SOURCE0_CAM_008",
    ];
    $offlineCameras  = array_diff($allCameras, $onlineCameras);

    // นับจำนวนออนไลน์/ออฟไลน์
    $camerasOnline  = count($onlineCameras);
    $camerasOffline = count($offlineCameras);

    // ส่งข้อมูลเป็น JSON
    header('Content-Type: application/json');
    echo json_encode([
        'camerasOnline'  => $camerasOnline,
        'camerasOffline' => $camerasOffline,
        'offlineCameras' => array_values($offlineCameras)
    ]);
    exit;
}

// ------------------- ดึงข้อมูลสำหรับกราฟอื่น ๆ ที่คุณแสดงแบบ PHP -> JS ------------------- //

// 1) ดึงข้อมูลการตรวจจับในแต่ละเดือน (Chart 'visitorInsightsChart')
$sql = "SELECT 
          DATE_FORMAT(time, '%Y-%m') AS month,
          SUM(elephant) AS elephant_count,
          SUM(elephant AND alert) AS elephant_car_count
        FROM detections
        GROUP BY DATE_FORMAT(time, '%Y-%m')
        ORDER BY DATE_FORMAT(time, '%Y-%m')";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    die("Prepare failed.");
}
$stmt->execute();
$result = $stmt->get_result();
$monthlyData = [];
while($row = $result->fetch_assoc()) {
    $monthlyData[] = $row;
}
$stmt->close();

// 2) ดึงข้อมูลตำแหน่งติดตั้งกล้อง (lat_cam, long_cam)
$installPoints = [];
$sql = "SELECT lat_cam, long_cam, id_cam 
        FROM detections 
        WHERE lat_cam IS NOT NULL AND long_cam IS NOT NULL";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    die("Prepare failed.");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $installPoints[] = $row;
}
$stmt->close();

// 3) ดึงข้อมูลบาดเจ็บ/เสียชีวิต (Chart 'targetVsRealityChart')
$sql = "SELECT DATE(d.time) AS date, 
               SUM(id.fatalities) AS total_fatalities, 
               SUM(id.injuries)  AS total_injuries 
        FROM incident_details id 
        JOIN detections d ON id.incident_id = d.id 
        GROUP BY DATE(d.time) 
        ORDER BY DATE(d.time)";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    die("Prepare failed.");
}
$stmt->execute();
$result = $stmt->get_result();
$injuryDeathData = [];
while ($row = $result->fetch_assoc()) {
    $injuryDeathData[] = $row;
}
$stmt->close();

// แปลงข้อมูลเป็น JSON เพื่อส่งต่อไปใช้ใน JavaScript
$monthlyDataJson     = json_encode($monthlyData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$incidentDetailsJson = json_encode($injuryDeathData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$installPointsJson   = json_encode($installPoints,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard สรุปเหตุการณ์และความเสี่ยง</title>
    <!-- Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        #map {
            height: 100%;
            width: 100%;
            z-index: 0;
        }
        .z-9999 {
            z-index: 9999;
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100 overflow-hidden">

    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 transform 
                -translate-x-full transition-transform duration-200 ease-in-out md:translate-x-0">
        <div class="flex flex-col h-full">
            <div class="p-6">
                <h2 class="text-2xl font-semibold text-gray-700">Admin Menu</h2>
                <ul class="mt-6 space-y-4">
                    <li>
                        <a href="admin_dashboard.php" class="flex items-center p-2 text-gray-700 rounded hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt mr-3"></i> Dashboard หลัก
                        </a>
                    </li>
                    <li>
                        <a href="ChartDashboard.php" class="flex items-center p-2 text-gray-700 rounded bg-gray-100">
                            <i class="fas fa-chart-line mr-3"></i> Dashboard สรุปเหตุการณ์
                        </a>
                    </li>
                    <li>
                        <a href="admin_logout.php" class="flex items-center p-2 text-gray-700 rounded hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-3"></i> ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
            <div class="flex-grow"></div>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <div class="flex items-center justify-between p-4 bg-white border-b border-gray-200 md:hidden fixed 
                top-0 left-0 right-0 z-40">
        <button id="menu-btn" class="text-gray-600 focus:outline-none">
            <i class="fas fa-bars fa-lg"></i>
        </button>
        <h2 class="text-xl font-semibold text-gray-700">Dashboard สรุปเหตุการณ์</h2>
    </div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col ml-0 md:ml-64">
        <div class="flex-1 p-6 pt-20 md:pt-4 overflow-auto">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800">อุทยานแห่งชาติเขาใหญ่</h1>
                <p class="text-xl font-semibold text-gray-600">Dashboard สรุปเหตุการณ์และความเสี่ยง</p>
                <div class="mt-4">
                    <label for="timePeriod" class="mr-2 text-gray-700">เลือกช่วงเวลา:</label>
                    <select id="timePeriod" class="p-2 border rounded">
                        <option value="day">วัน</option>
                        <option value="month" selected>เดือน</option>
                        <option value="year">ปี</option>
                    </select>
                </div>
            </header>

            <!-- Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

                <!-- การแจ้งเตือนเหตุการณ์ช้าง -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">การแจ้งเตือนเหตุการณ์ช้าง</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ช้างบนถนน:</span>
                            <!-- ใช้ text-orange-500 ตรงกับกราฟ -->
                            <span class="text-xl font-bold" style="color: rgba(249,115,22,1);">
                                <?= number_format($counts['elephantsOnRoadCount']); ?> ตัว
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ช้างกับรถ:</span>
                            <!-- ใช้ text-red-600 ตรงกับกราฟ -->
                            <span class="text-xl font-bold text-red-600">
                                <?= number_format($counts['elephantsCarCount']); ?> ตัว
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ข้อมูลผู้ใช้งานระบบ -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">ข้อมูลผู้ใช้งานระบบ</h3>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">ผู้ใช้งานปัจจุบัน:</span>
                        <span class="text-xl font-bold text-blue-500">4 คน</span>
                    </div>
                </div>

                <!-- สถานะการดำเนินงาน (แสดงจาก detections.status = completed/pending) -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">สถานะการดำเนินงาน</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ดำเนินการแล้ว:</span>
                            <!-- ใช้ text-green-500 ตรงกับกราฟ -->
                            <span class="text-xl font-bold text-green-500">
                                <?= number_format($counts['statusCountcompleted']); ?> ครั้ง
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รอดำเนินการ:</span>
                            <!-- ใช้ text-yellow-500 ตรงกับกราฟ -->
                            <span class="text-xl font-bold text-yellow-500">
                                <?= number_format($counts['statusCountpending']); ?> ครั้ง
                            </span>
                        </div>
                    </div>
                </div>

                <!-- จำนวนกล้องเฝ้าระวัง -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">จำนวนกล้องเฝ้าระวัง</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ทั้งหมด:</span>
                            <span id="totalCameras" class="text-xl font-bold text-purple-500">
                                <?= number_format($totalCameras); ?> ตัว
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ใช้งานอยู่:</span>
                            <span id="camOnlineCount" 
                                class="text-xl font-bold text-indigo-500 cursor-pointer
                                <?= $counts['CamOffline'] > 0 ? 'underline text-indigo-600' : ''; ?>">
                                <?= number_format($counts['camerasOnline']); ?> ตัว
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts and Map -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- แผนที่ -->
                <div class="col-span-1 h-64 lg:h-full bg-white rounded-lg shadow-md">
                    <div id="map" class="h-full rounded-lg"></div>
                </div>

                <!-- Charts -->
                <div class="col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Chart 1: การแจ้งเตือนช้างและรถ -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">
                            การแจ้งเตือนช้างและรถ
                        </h3>
                        <div class="flex-1">
                            <canvas id="visitorInsightsChart" class="w-full h-full"></canvas>
                        </div>
                    </div>

                    <!-- Chart 2: Online vs Offline Sales (ตัวอย่างกราฟเทส) -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">Online vs Offline Sales</h3>
                        <div class="flex-1">
                            <canvas id="totalRevenueChart" class="w-full h-full"></canvas>
                        </div>
                    </div>

                    <!-- Chart 3: สถานะการดำเนินงาน (Completed vs Pending) -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">
                            สถานะการดำเนินงาน
                        </h3>
                        <div class="flex-1">
                            <canvas id="customerSatisfactionChart" class="w-full h-full"></canvas>
                        </div>
                    </div>

                    <!-- Chart 4: จำนวนผู้บาดเจ็บ/เสียชีวิต -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">
                            จำนวนผู้บาดเจ็บ/เสียชีวิต
                        </h3>
                        <div class="flex-1">
                            <canvas id="targetVsRealityChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="offlineCamerasModal" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 hidden z-9999">
        <div class="bg-white rounded-lg shadow-lg w-96">
            <!-- Header ของ Modal -->
            <div class="flex justify-between items-center p-4 border-b">
                <h2 class="text-xl font-semibold">กล้องที่ไม่ออนไลน์</h2>
                <button id="closeModal" class="text-gray-700 hover:text-gray-900">&times;</button>
            </div>
            <!-- เนื้อหาของ Modal -->
            <div class="p-4">
                <ul id="offlineCamerasListModal" class="list-disc pl-5"></ul>
            </div>
            <!-- Footer ของ Modal -->
            <div class="flex justify-end p-4 border-t">
                <button id="closeModalFooter" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">ปิด</button>
            </div>
        </div>
    </div>

    <!-- Leaflet.js -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <script>
        // -------- ตัวแปร/ข้อมูลจาก PHP -> JS --------
        const chartData        = <?= $monthlyDataJson; ?>;   // สำหรับกราฟ visitorInsightsChart
        const injuryDeathData  = <?= $incidentDetailsJson; ?>;
        const installPoints    = <?= $installPointsJson; ?>;
        const totalCameras     = <?= $totalCameras; ?>;

        // Timer สำหรับเปลี่ยนสถานะกล้องเป็น Offline
        let offlineTimer;

        // ---------------------------------------------------------
        // ฟังก์ชันอัปเดตจำนวนกล้องออนไลน์ใน DOM
        // ---------------------------------------------------------
        function updateCamOnlineDisplay(onlineCount) {
            const camOnlineElement = document.getElementById('camOnlineCount');
            camOnlineElement.textContent = onlineCount.toLocaleString() + ' ตัว';
            if (onlineCount < totalCameras) {
                camOnlineElement.classList.remove('text-indigo-500');
                camOnlineElement.classList.add('text-red-500', 'underline', 'text-indigo-600', 'cursor-pointer');
            } else {
                camOnlineElement.classList.remove('text-red-500', 'underline', 'text-indigo-600', 'cursor-pointer');
                camOnlineElement.classList.add('text-indigo-500');
            }
        }

        // ---------------------------------------------------------
        // Modal สำหรับแสดงรายชื่อกล้องที่ไม่ออนไลน์
        // ---------------------------------------------------------
        function updateCameraLists(offlineCameras) {
            const offlineListModal = document.getElementById('offlineCamerasListModal');
            offlineListModal.innerHTML = '';
            offlineCameras.forEach(cam => {
                const li = document.createElement('li');
                li.textContent = cam;
                offlineListModal.appendChild(li);
            });
        }
        function openModal() {
            document.getElementById('offlineCamerasModal').classList.remove('hidden');
        }
        function closeModal() {
            document.getElementById('offlineCamerasModal').classList.add('hidden');
        }

        // ---------------------------------------------------------
        // ฟังก์ชันดึงสถานะกล้องจากเซิร์ฟเวอร์
        // ---------------------------------------------------------
        let camerasOnline  = <?= $counts['camerasOnline']; ?>;
        let camerasOffline = <?= $counts['CamOffline']; ?>;

        function fetchCameraStatus() {
            fetch(`?action=fetch_camera_status&_=${new Date().getTime()}`) // กัน cache
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error('Error from server:', data.error);
                        return;
                    }
                    camerasOnline  = data.camerasOnline;
                    camerasOffline = data.camerasOffline;
                    updateCamOnlineDisplay(data.camerasOnline);
                    updateCameraLists(data.offlineCameras);
                    resetOfflineTimer();
                })
                .catch(error => console.error('Error fetching camera status:', error));
        }

        // ตั้ง Timer: ถ้าไม่อัปเดตภายใน 1 นาที จะถือว่ากล้องออฟไลน์
        function startOfflineTimer() {
            if (offlineTimer) {
                clearTimeout(offlineTimer);
            }
            offlineTimer = setTimeout(() => {
                const newCount = totalCameras - camerasOnline;
                updateCamOnlineDisplay(newCount);
            }, 60000);
        }
        function resetOfflineTimer() {
            startOfflineTimer();
        }

        // เริ่มทำงานเมื่อโหลดหน้า
        window.addEventListener('load', () => {
            fetchCameraStatus(); // เรียกครั้งแรก
            setInterval(fetchCameraStatus, 40000); // เรียกซ้ำทุก 40 วินาที
        });

        // ---------------------------------------------------------
        // Mobile sidebar toggle
        // ---------------------------------------------------------
        const menuBtn = document.getElementById('menu-btn');
        const sidebar = document.querySelector('.sidebar');
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('-translate-x-full');
        });
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                sidebar.classList.add('-translate-x-full');
            }
        });

        // Modal แสดงกล้อง Offline
        const camOnlineElement = document.getElementById('camOnlineCount');
        if (camOnlineElement) {
            camOnlineElement.addEventListener('click', () => {
                if (camerasOffline > 0) {
                    openModal();
                }
            });
        }
        document.getElementById('closeModal')?.addEventListener('click', closeModal);
        document.getElementById('closeModalFooter')?.addEventListener('click', closeModal);

        // ---------------------------------------------------------
        // สร้างกราฟด้วย Chart.js
        // ---------------------------------------------------------

        // 1) กราฟ "การแจ้งเตือนช้างและรถ"
        const ctxVisitor        = document.getElementById('visitorInsightsChart').getContext('2d');
        const labelsVisitor     = chartData.map(item => item.month);
        const elephantData      = chartData.map(item => item.elephant_count);
        const elephantCarData   = chartData.map(item => item.elephant_car_count);

        let visitorChart = new Chart(ctxVisitor, {
            type: 'line',
            data: {
                labels: labelsVisitor,
                datasets: [
                    {
                        // ช้างบนถนน (ตรงกับ text-orange-500 = #F97316)
                        label: 'ช้างอยู่บนถนน',
                        data: elephantData,
                        borderColor: 'rgba(249,115,22,1)',   // #F97316
                        backgroundColor: 'rgba(249,115,22,0.2)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        // ช้างกับรถ (ตรงกับ text-red-600 = #DC2626)
                        label: 'รถและช้างอยู่ในพื้นที่เดียวกัน',
                        data: elephantCarData,
                        borderColor: 'rgba(220,38,38,1)',   // #DC2626
                        backgroundColor: 'rgba(220,38,38,0.2)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // 2) กราฟตัวอย่าง Online vs Offline Sales (mock data)
        const ctxRevenue = document.getElementById('totalRevenueChart').getContext('2d');
        new Chart(ctxRevenue, {
            type: 'bar',
            data: {
                labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                datasets: [
                    {
                        label: 'Online Sales',
                        data: [15000, 12000, 25000, 18000, 16000, 20000, 22000],
                        backgroundColor: 'rgba(54, 162, 235, 0.6)'
                    },
                    {
                        label: 'Offline Sales',
                        data: [12000, 14000, 22000, 17000, 15000, 18000, 21000],
                        backgroundColor: 'rgba(75, 192, 192, 0.6)'
                    }
                ]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // 3) กราฟ "สถานะการดำเนินงาน (Completed vs Pending)"
        let customerSatisfactionChart = null; 
        function updateCustomerSatisfactionChart(chartData) {
            // chartData = [{ period: '2023-01', completed: 10, pending: 5 }, ...]
            const labels       = chartData.map(item => item.period);
            const completedArr = chartData.map(item => item.completed);
            const pendingArr   = chartData.map(item => item.pending);

            if (customerSatisfactionChart) {
                customerSatisfactionChart.destroy();
            }
            const ctx = document.getElementById('customerSatisfactionChart').getContext('2d');
            customerSatisfactionChart = new Chart(ctx, {
                type: 'line', 
                data: {
                    labels: labels,
                    datasets: [
                        {
                            // ดำเนินการแล้ว: ใช้สีเขียว #10B981
                            label: 'ดำเนินการแล้ว',
                            data: completedArr,
                            borderColor: 'rgba(16,185,129,1)',         // #10B981
                            backgroundColor: 'rgba(16,185,129,0.2)',
                            fill: true,
                            tension: 0.2
                        },
                        {
                            // รอดำเนินการ: ใช้สีเหลือง #F59E0B
                            label: 'รอดำเนินการ',
                            data: pendingArr,
                            borderColor: 'rgba(245,158,11,1)',        // #F59E0B
                            backgroundColor: 'rgba(245,158,11,0.2)',
                            fill: true,
                            tension: 0.2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // 4) กราฟ "จำนวนผู้บาดเจ็บ/เสียชีวิต"
        const ctxTargetReality  = document.getElementById('targetVsRealityChart').getContext('2d');
        const labelsTargetReality = injuryDeathData.map(item => item.date);
        const fatalities          = injuryDeathData.map(item => item.total_fatalities);
        const injuries            = injuryDeathData.map(item => item.total_injuries);

        let targetVsRealityChart = new Chart(ctxTargetReality, {
            type: 'bar',
            data: {
                labels: labelsTargetReality,
                datasets: [
                    {
                        label: 'จำนวนผู้เสียชีวิต',
                        data: fatalities,
                        backgroundColor: 'rgba(255, 0, 0, 0.6)',
                        borderColor: 'rgba(255, 0, 0, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'จำนวนผู้บาดเจ็บ',
                        data: injuries,
                        backgroundColor: 'rgba(255, 140, 0, 0.6)',
                        borderColor: 'rgba(255, 140, 0, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'จำนวน'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'วันที่'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'จำนวนผู้บาดเจ็บและเสียชีวิตต่อวัน'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    },
                    legend: {
                        position: 'top'
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });

        // ---------------------------------------------------------
        // ฟังก์ชันดึงข้อมูลกราฟผ่าน AJAX (เมื่อเปลี่ยนช่วงเวลา)
        // ---------------------------------------------------------
        function fetchChartData(timePeriod, chartType) {
            const params = new URLSearchParams({
                action: 'fetch_data',
                group_by: timePeriod,
                chart: chartType,
                _ : new Date().getTime()
            });
            return fetch(`?${params.toString()}`)
                .then(response => response.json())
                .catch(error => {
                    console.error('Error fetching chart data:', error);
                    return [];
                });
        }

        // ฟังก์ชันอัปเดตกราฟ elephant_count (เส้นช้างบนถนน) / อื่น ๆ
        function updateChart(data, chartType) {
            switch (chartType) {
                case 'elephant_count':
                    const labels = data.map(item => item.period);
                    const counts = data.map(item => item.count);
                    // update เฉพาะเส้น "ช้างบนถนน" dataset[0]
                    visitorChart.data.labels = labels;
                    visitorChart.data.datasets[0].data = counts; 
                    visitorChart.update();
                    break;

                case 'injury_death':
                    // ถ้าจะอัปเดตกราฟบาดเจ็บ/เสียชีวิตแบบแยก AJAX
                    // สามารถเพิ่มโค้ดอัปเดต targetVsRealityChart ได้
                    break;

                default:
                    console.warn('Unknown chart type:', chartType);
            }
        }

        // เมื่อเปลี่ยนช่วงเวลาใน select
        document.getElementById('timePeriod').addEventListener('change', function () {
            const timePeriod = this.value;

            // อัปเดตกราฟ elephant_count
            fetchChartData(timePeriod, 'elephant_count')
                .then(data => updateChart(data, 'elephant_count'));

            // อัปเดตกราฟ injury_death (ถ้าต้องการ)
            fetchChartData(timePeriod, 'injury_death')
                .then(data => {
                    // ตัวอย่าง: updateChart(data, 'injury_death')
                });

            // อัปเดตกราฟ operation_status (Customer Satisfaction)
            fetchChartData(timePeriod, 'operation_status')
                .then(data => {
                    updateCustomerSatisfactionChart(data);
                });
        });

        // ---------------------------------------------------------
        // สร้างแผนที่ Leaflet
        // ---------------------------------------------------------
        const map = L.map('map').setView([13.736717, 100.523186], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // เพิ่ม Marker กล้อง
        var singleIcon = L.icon({
            iconUrl: 'icons/IconLocation.png',
            iconSize: [30, 30],
            iconAnchor: [15, 30],
            popupAnchor: [0, -30]
        });
        installPoints.forEach(function(point) {
            const lat  = parseFloat(point.lat_cam);
            const lng  = parseFloat(point.long_cam);
            const name = point.id_cam ? `Camera ID: ${point.id_cam}` : 'Unknown Camera';
            if (!isNaN(lat) && !isNaN(lng)) {
                L.marker([lat, lng], { icon: singleIcon })
                 .bindPopup(`<strong>${name}</strong><br>Lat: ${lat}<br>Lng: ${lng}`)
                 .addTo(map);
            }
        });
        
        // เรียกใช้งานกราฟ operation_status ทันทีที่หน้าโหลดเสร็จ
        window.addEventListener('load', () => {
            // ดึงข้อมูลครั้งแรก (ค่าเริ่มต้นคือ group_by = month)
            fetchChartData('month', 'operation_status')
                .then(data => {
                    updateCustomerSatisfactionChart(data);
                });
        });
    </script>
</body>
</html>

<?php
// สิ้นสุด Output Buffering
ob_end_flush();
?>
