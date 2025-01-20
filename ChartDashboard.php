<?php
//ChartDashboard.php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Connection failed.");
}

/* 
 * ฟังก์ชันสำหรับจัดกลุ่มข้อมูลตามช่วงเวลา
 */
function getGroupByClause($groupBy, $timeField) {
    switch ($groupBy) {
        case 'day':
            return "DATE($timeField)";
        case 'week':
            return "YEARWEEK($timeField, 1)";
        case 'month':
            return "DATE_FORMAT($timeField, '%Y-%m')";
        case 'year':
            return "YEAR($timeField)";
        case 'all':
            return "1";
        default:
            return "DATE($timeField)";
    }
}

/**
 * ฟังก์ชันสำหรับดึงข้อมูลจากตาราง detections โดยใช้ prepared statements
 */
function getChartData($conn, $chartType, $groupBy) {
    try {
        $groupByClause = getGroupByClause($groupBy, 'time');
        $limitMap = [
            'day' => 7,
            'week' => 4,
            'month' => 12,
            'year' => 5,
            'all' => 0,
        ];
        $limit = $limitMap[$groupBy] ?? 7;

        switch ($chartType) {
            case 'risk_level':
                $query = "
                    SELECT 
                        $groupByClause AS period,
                        alert,
                        COUNT(*) AS count 
                    FROM detections 
                    GROUP BY 
                        $groupByClause,
                        alert 
                    ORDER BY 
                        $groupByClause DESC
                ";
                break;

            case 'elephant_count':
                $query = "
                    SELECT 
                        $groupByClause AS period, 
                        COUNT(elephant) AS count 
                    FROM detections 
                    WHERE elephant > 0 
                    GROUP BY 
                        $groupByClause
                    ORDER BY 
                        $groupByClause DESC
                ";
                break;

            case 'camera_locations':
                $query = "
                    SELECT 
                        camera_location,
                        $groupByClause AS period,
                        COUNT(elephant) AS count 
                    FROM detections 
                    WHERE elephant > 0 
                    GROUP BY 
                        camera_location, 
                        $groupByClause
                    ORDER BY 
                        camera_location, 
                        $groupByClause DESC
                ";
                break;

            case 'summary_events':
                $query = "
                    SELECT 
                        COUNT(*) AS total_events,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS resolved_events
                    FROM detections
                ";
                break;

            case 'resolved_percentage_per_level':
                $query = "
                    SELECT 
                        alert AS risk_level,
                        COUNT(*) AS total_events,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS resolved_events
                    FROM detections
                    GROUP BY alert
                ";
                break;

            case 'top_areas':
                $query = "
                    SELECT 
                        camera_location AS area,
                        COUNT(*) AS count 
                    FROM detections
                    GROUP BY camera_location
                    ORDER BY count DESC
                    LIMIT 10
                ";
                break;

            default:
                return [];
        }

        // เพิ่ม LIMIT ถ้ามี
        if ($limit > 0 && in_array($chartType, ['risk_level', 'elephant_count', 'camera_locations'])) {
            $query .= " LIMIT ?";
        }

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Error in query preparation: " . $conn->error);
        }

        if ($limit > 0 && in_array($chartType, ['risk_level', 'elephant_count', 'camera_locations'])) {
            $stmt->bind_param("i", $limit);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        if (in_array($chartType, ['summary_events', 'resolved_percentage_per_level'])) {
            return $data[0] ?? [];
        }

        return $data;

    } catch (Exception $e) {
        error_log($e->getMessage());
        return [];
    }
}

// ฟังก์ชันสำหรับดึงค่าต่างๆ พร้อมกันในหนึ่งฟังก์ชันเพื่อลดจำนวนการเชื่อมต่อฐานข้อมูล
function getCounts($conn) {
    $counts = [];
    $queries = [
        'elephantsOnRoadCount' => "SELECT COUNT(*) AS count FROM detections WHERE elephant = ?",
        'elephantsCarCount' => "SELECT COUNT(*) AS count FROM detections WHERE elephant = ? AND alert = ?",
        'statusCountcompleted' => "SELECT COUNT(*) AS count FROM detections WHERE status = ?",
        'statusCountpending' => "SELECT COUNT(*) AS count FROM detections WHERE status = ?",
        'CamOffline' => "SELECT COUNT(*) AS count FROM detections WHERE CamStatus = 'offline'", // แทนที่ด้วยคำสั่งจริง
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

    // statusCountcompleted
    $stmt = $conn->prepare($queries['statusCountcompleted']);
    $statusCompleted = 'completed';
    $stmt->bind_param("s", $statusCompleted);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['statusCountcompleted'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // statusCountpending
    $stmt = $conn->prepare($queries['statusCountpending']);
    $statusPending = 'pending';
    $stmt->bind_param("s", $statusPending);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['statusCountpending'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // CamOffline
    $stmt = $conn->prepare($queries['CamOffline']);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts['CamOffline'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    return $counts;
}

// ตรวจสอบการเรียกผ่าน AJAX
if (isset($_GET['action']) && $_GET['action'] === 'fetch_data') {
    $chartType = filter_input(INPUT_GET, 'chart', FILTER_SANITIZE_STRING);
    $groupBy = filter_input(INPUT_GET, 'group_by', FILTER_SANITIZE_STRING) ?? 'day';

    $allowedCharts = ['risk_level', 'elephant_count', 'camera_locations', 'summary_events', 'resolved_percentage_per_level', 'top_areas'];
    $allowedGroupBy = ['day', 'week', 'month', 'year', 'all'];

    if (!in_array($chartType, $allowedCharts) || !in_array($groupBy, $allowedGroupBy)) {
        echo json_encode([]);
        exit;
    }

    $data = getChartData($conn, $chartType, $groupBy);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ดึงข้อมูลสถิติต่างๆ
$counts = getCounts($conn);

// ดึงข้อมูลการตรวจจับในแต่ละเดือน
$sql = "SELECT 
          DATE_FORMAT(time, '%Y-%m') AS month,
          SUM(elephant) AS elephant_count,
          SUM(elephant AND alert) AS elephant_car_count
        FROM detections
        GROUP BY DATE_FORMAT(time, '%Y-%m')
        ORDER BY DATE_FORMAT(time, '%Y-%m')";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

$monthlyData = [];
while($row = $result->fetch_assoc()) {
    $monthlyData[] = $row;
}
$stmt->close();

// ดึงข้อมูลตำแหน่งติดตั้งกล้อง
$installPoints = [];
$sql = "SELECT lat_cam, long_cam, id_cam FROM detections WHERE lat_cam IS NOT NULL AND long_cam IS NOT NULL";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $installPoints[] = $row;
}
$stmt->close();

// ดึงข้อมูลจากตาราง incident_details พร้อมวันที่
// สมมติว่าตาราง incident_details มีฟิลด์ incident_date ซึ่งเป็นวันที่เกิดเหตุ
$sql = "SELECT DATE(d.time) AS date, SUM(id.fatalities) AS total_fatalities, SUM(id.injuries) AS total_injuries 
        FROM incident_details id 
        JOIN detections d ON id.incident_id = d.id 
        GROUP BY DATE(d.time) 
        ORDER BY DATE(d.time)";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$injuryDeathData = [];
while ($row = $result->fetch_assoc()) {
    $injuryDeathData[] = $row;
}
$stmt->close();

// ดึงจำนวนกล้องทั้งหมด
$totalCameras = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM detections");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $totalCameras = (int)$row['count'];
}
$stmt->close();

// ส่งข้อมูลไปยัง JavaScript ในรูปแบบ JSON อย่างปลอดภัย
$monthlyDataJson = json_encode($monthlyData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$incidentDetailsJson = json_encode($injuryDeathData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// ส่งค่าจำนวนกล้องทั้งหมดและใช้งานอยู่ไปยัง JavaScript
echo "<script>
    const totalCameras = $totalCameras;
    let camOfflineCount = " . $counts['CamOffline'] . ";
</script>";

// ปิดการเชื่อมต่อฐานข้อมูล
$conn->close();
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
        /* กำหนดความสูงของแผนที่ให้ยืดหยุ่น */
        #map {
            height: 100%;
            width: 100%;
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100 overflow-hidden">
    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 transform -translate-x-full transition-transform duration-200 ease-in-out md:translate-x-0">
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
            <!-- เพิ่มพื้นที่ว่างเพื่อเลื่อนเมนูขึ้นด้านบน -->
            <div class="flex-grow"></div>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <div class="flex items-center justify-between p-4 bg-white border-b border-gray-200 md:hidden fixed top-0 left-0 right-0 z-40">
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
                        <option value="week">สัปดาห์</option>
                        <option value="month" selected>เดือน</option>
                        <option value="year">ปี</option>
                        <option value="all">ทั้งหมด</option>
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
                            <span class="text-gray-600">ช้างอยู่บนถนน:</span>
                            <span class="text-xl font-bold text-orange-500"><?= number_format($counts['elephantsOnRoadCount']); ?> ตัว</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รถ-ช้างอยู่พื้นที่เดียวกัน:</span>
                            <span class="text-xl font-bold text-red-500"><?= number_format($counts['elephantsCarCount']); ?> ตัว</span>
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
                <!-- สถานะการดำเนินงาน -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">สถานะการดำเนินงาน</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ดำเนินการแล้ว:</span>
                            <span class="text-xl font-bold text-green-500"><?= number_format($counts['statusCountcompleted']); ?> ครั้ง</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รอดำเนินการ:</span>
                            <span class="text-xl font-bold text-yellow-500"><?= number_format($counts['statusCountpending']); ?> ครั้ง</span>
                        </div>
                    </div>
                </div>
                <!-- จำนวนกล้องเฝ้าระวัง -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">จำนวนกล้องเฝ้าระวัง</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ทั้งหมด:</span>
                            <span id="totalCameras" class="text-xl font-bold text-purple-500"><?= number_format($totalCameras); ?> ตัว</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ใช้งานอยู่:</span>
                            <span id="camOfflineCount" class="text-xl font-bold text-indigo-500"><?= number_format($counts['CamOffline']); ?> ตัว</span> 
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
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">การแจ้งเตือนช้างและรถ</h3>
                        <div class="flex-1">
                            <canvas id="visitorInsightsChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                    <!-- Chart 2: Online vs Offline Sales -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">Online vs Offline Sales</h3>
                        <div class="flex-1">
                            <canvas id="totalRevenueChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                    <!-- Chart 3: Customer Satisfaction -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">Customer Satisfaction</h3>
                        <div class="flex-1">
                            <canvas id="customerSatisfactionChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                    <!-- Chart 4: จำนวนผู้บาดเจ็บ/เสียชีวิต -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 h-96">
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">จำนวนผู้บาดเจ็บ/เสียชีวิต</h3>
                        <div class="flex-1">
                            <canvas id="targetVsRealityChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet.js -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <script>
        // ส่งข้อมูลจาก PHP ไปยัง JavaScript
        const chartData = <?= $monthlyDataJson; ?>;
        const injuryDeathData = <?= $incidentDetailsJson; ?>;
        const installPoints = <?= json_encode($installPoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        // const totalCameras = <?= $totalCameras; ?>; // จำนวนกล้องทั้งหมด (ตอนนี้ถูกกำหนดใน PHP)
        // let camOfflineCount = <?= number_format($counts['CamOffline']); ?>; // จำนวนกล้องที่ใช้งานอยู่ (ตอนนี้ถูกกำหนดใน PHP)
        let offlineTimer;

        // ฟังก์ชันสำหรับอัปเดตจำนวนกล้องที่ใช้งานอยู่ใน DOM
        function updateCamOfflineDisplay(count) {
            const camOfflineElement = document.getElementById('camOfflineCount');
            camOfflineElement.textContent = count.toLocaleString() + ' ตัว';
            if (count < totalCameras) {
                camOfflineElement.classList.remove('text-indigo-500');
                camOfflineElement.classList.add('text-red-500'); // เปลี่ยนสีเป็นแดง
            } else {
                camOfflineElement.classList.remove('text-red-500');
                camOfflineElement.classList.add('text-indigo-500'); // เปลี่ยนกลับเป็นสีน้ำเงิน
            }
        }

        // ฟังก์ชันสำหรับตั้งค่าเวลาหมดอายุ
        function startOfflineTimer() {
            // เคลียร์เวลาเดิมถ้ามี
            if (offlineTimer) {
                clearTimeout(offlineTimer);
            }
            // ตั้งเวลาใหม่
            offlineTimer = setTimeout(() => {
                // ตั้งค่าจำนวนกล้องที่ใช้งานอยู่เป็นจำนวนกล้องทั้งหมด - camOfflineCount
                const newCount = totalCameras - camOfflineCount;
                updateCamOfflineDisplay(newCount);
            }, 60000); // 1 นาที = 60000 มิลลิวินาที
        }

        // รีเซ็ต Timer เมื่อมีการอัปเดตข้อมูล
        function resetOfflineTimer() {
            startOfflineTimer();
        }

        // เริ่มต้น Timer เมื่อโหลดหน้า
        window.onload = function() {
            startOfflineTimer();
        }

        // Mobile sidebar toggle
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

        // ตัวอย่างกราฟที่ 1 (visitorInsightsChart)
        const ctxVisitor = document.getElementById('visitorInsightsChart').getContext('2d');
        const labelsVisitor = chartData.map(item => item.month);
        const elephantData = chartData.map(item => item.elephant_count);
        const elephantCarData = chartData.map(item => item.elephant_car_count);

        let visitorChart = new Chart(ctxVisitor, {
            type: 'line',
            data: {
                labels: labelsVisitor,
                datasets: [
                    {
                        label: 'ช้างอยู่บนถนน',
                        data: elephantData,
                        borderColor: 'orange',
                        backgroundColor: 'rgba(255, 165, 0, 0.2)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'รถและช้างอยู่ในพื้นที่เดียวกัน',
                        data: elephantCarData,
                        borderColor: 'red',
                        backgroundColor: 'rgba(239,68,68, 0.2)',
                        fill: true,
                        tension: 0.4
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

        // ตัวอย่างกราฟที่ 2 (totalRevenueChart)
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
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // ตัวอย่างกราฟที่ 3 (customerSatisfactionChart)
        const ctxSatisfaction = document.getElementById('customerSatisfactionChart').getContext('2d');
        new Chart(ctxSatisfaction, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [
                    {
                        label: 'Last Month',
                        data: [3004, 3500, 3300, 3100, 3200, 3400, 3500, 3700, 3800, 3900, 4000, 4200],
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'This Month',
                        data: [3500, 3800, 3700, 3600, 3900, 4000, 4200, 4300, 4400, 4500, 4600, 4800],
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        fill: true,
                        tension: 0.4
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

        // ตัวอย่างกราฟที่ 4 (targetVsRealityChart) - จำนวนผู้บาดเจ็บ/เสียชีวิต
        const ctxTargetReality = document.getElementById('targetVsRealityChart').getContext('2d');
        const labelsTargetReality = injuryDeathData.map(item => item.date);
        const fatalities = injuryDeathData.map(item => item.total_fatalities);
        const injuries = injuryDeathData.map(item => item.total_injuries);

        let targetVsRealityChart = new Chart(ctxTargetReality, {
            type: 'bar',
            data: {
                labels: labelsTargetReality,
                datasets: [
                    {
                        label: 'จำนวนผู้เสียชีวิต',
                        data: fatalities,
                        backgroundColor: 'rgba(255, 0, 0, 0.6)', // สีแดงจัด
                        borderColor: 'rgba(255, 0, 0, 1)', // สีแดงจัด
                        borderWidth: 1
                    },
                    {
                        label: 'จำนวนผู้บาดเจ็บ',
                        data: injuries,
                        backgroundColor: 'rgba(255, 140, 0, 0.6)', // สีส้มเข้ม
                        borderColor: 'rgba(255, 140, 0, 1)', // สีส้มเข้ม
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
                        position: 'top',
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });

        // สร้างแผนที่ Leaflet.js
        var map = L.map('map').setView([13.736717, 100.523186], 6); // พิกัดเริ่มต้นประเทศไทย

        // เพิ่ม Tile Layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Custom Icon สำหรับทุกจุด
        var singleIcon = L.icon({
            iconUrl: 'icons/IconLocation.png', // URL ไอคอนจุดเดียว
            iconSize: [30, 30], // ขนาดไอคอน [กว้าง, สูง]
            iconAnchor: [15, 30], // จุดยึดไอคอน [กลางด้านล่าง]
            popupAnchor: [0, -30] // จุดยึด popup [กลางด้านบน]
        });

        // เพิ่ม Marker บนแผนที่โดยใช้ไอคอนเดียว
        installPoints.forEach(function(point) {
            var lat = parseFloat(point.lat_cam);
            var lng = parseFloat(point.long_cam);
            var name = point.cam || 'Unknown Camera'; // ใช้ชื่อกล้องหรือแสดง "Unknown Camera"

            if (!isNaN(lat) && !isNaN(lng)) {
                L.marker([lat, lng], { icon: singleIcon }) // ใช้ไอคอนเดียวกัน
                 .bindPopup(`<strong>${name}</strong><br>Lat: ${lat}<br>Lng: ${lng}`) // Popup เมื่อคลิก
                 .addTo(map);
            }
        });

        // ฟังก์ชันในการดึงข้อมูลใหม่และอัปเดตกราฟ
        function fetchChartData(timePeriod, chartType) {
            fetch(`?action=fetch_data&group_by=${timePeriod}&chart=${chartType}`)
                .then(response => response.json())
                .then(data => {
                    updateChart(data, chartType);
                    // รีเซ็ต Timer เมื่อมีการอัปเดตข้อมูล
                    resetOfflineTimer();
                })
                .catch(error => console.error('Error fetching chart data:', error));
        }

        // ฟังก์ชันในการอัปเดตกราฟ
        function updateChart(data, chartType) {
            switch(chartType) {
                case 'elephant_count':
                    const labels = data.map(item => item.period);
                    const counts = data.map(item => item.count);
                    
                    // อัปเดต visitorInsightsChart
                    visitorChart.data.labels = labels;
                    visitorChart.data.datasets[0].data = counts;
                    visitorChart.update();
                    break;
                // เพิ่ม case สำหรับ chart อื่นๆ ตามต้องการ
                default:
                    console.warn('Unknown chart type:', chartType);
            }
        }

        // ตัวอย่างการใช้งานกับ dropdown
        document.getElementById('timePeriod').addEventListener('change', function() {
            var timePeriod = this.value;  // รับค่าจาก dropdown
            fetchChartData(timePeriod, 'elephant_count');  // เรียกฟังก์ชันดึงข้อมูลใหม่
        });

        // ฟังก์ชันสำหรับอัปเดตกล้องที่ใช้งานอยู่
        function updateCamOfflineDisplay(count) {
            const camOfflineElement = document.getElementById('camOfflineCount');
            camOfflineElement.textContent = count.toLocaleString() + ' ตัว';
            if (count < totalCameras) {
                camOfflineElement.classList.remove('text-indigo-500');
                camOfflineElement.classList.add('text-red-500'); // เปลี่ยนสีเป็นแดง
            } else {
                camOfflineElement.classList.remove('text-red-500');
                camOfflineElement.classList.add('text-indigo-500'); // เปลี่ยนกลับเป็นสีน้ำเงิน
            }
        }

        // ฟังก์ชันสำหรับตั้งค่าเวลาหมดอายุ
        function startOfflineTimer() {
            // เคลียร์เวลาเดิมถ้ามี
            if (offlineTimer) {
                clearTimeout(offlineTimer);
            }
            // ตั้งเวลาใหม่
            offlineTimer = setTimeout(() => {
                // ตั้งค่าจำนวนกล้องที่ใช้งานอยู่เป็นจำนวนกล้องทั้งหมด - camOfflineCount
                const newCount = totalCameras - camOfflineCount;
                updateCamOfflineDisplay(newCount);
            }, 60000); // 1 นาที = 60000 มิลลิวินาที
        }

        // รีเซ็ต Timer เมื่อมีการอัปเดตข้อมูล
        function resetOfflineTimer() {
            startOfflineTimer();
        }

        // เริ่มต้น Timer เมื่อโหลดหน้า
        window.onload = function() {
            startOfflineTimer();
        }

        // ปรับขนาดแผนที่เมื่อเปลี่ยนขนาดหน้าจอ
        window.addEventListener('resize', function() {
            map.invalidateSize();
        });
    </script>
</body>
</html>

<?php
ob_end_flush();
?>
