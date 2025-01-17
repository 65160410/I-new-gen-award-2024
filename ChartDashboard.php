<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ตรวจสอบการเข้าสู่ระบบแอดมิน
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
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
 * ฟังก์ชันสำหรับดึงข้อมูลจากตาราง detections
 */
function getChartData($conn, $chartType, $groupBy) {
    try {
        $limit = match ($groupBy) {
            'day' => 7,
            'week' => 4,
            'month' => 12,
            'year' => 5,
            'all' => 0,
            default => 7,
        };

        switch ($chartType) {
            case 'risk_level':
                $groupByClause = getGroupByClause($groupBy, 'time');
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
                $groupByClause = getGroupByClause($groupBy, 'time');
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
                $groupByClause = getGroupByClause($groupBy, 'time');
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

        if ($stmt = mysqli_prepare($conn, $query)) {
            if (isset($limit) && $limit > 0) {
                $query .= " LIMIT ?";
                mysqli_stmt_bind_param($stmt, "i", $limit);
            }

            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
        } else {
            throw new Exception("Error in query preparation: " . mysqli_error($conn));
        }

        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }

        if (in_array($chartType, ['summary_events', 'resolved_percentage_per_level'])) {
            return $data[0] ?? [];
        }

        mysqli_free_result($result);
        return $data;

    } catch (Exception $e) {
        error_log($e->getMessage());
        return [];
    }
}

// ตรวจสอบการเรียกผ่าน AJAX
if (isset($_GET['action']) && $_GET['action'] === 'fetch_data') {
    $chartType = $_GET['chart'] ?? '';
    $groupBy = $_GET['group_by'] ?? 'day';

    $chartType = mysqli_real_escape_string($conn, $chartType);
    $groupBy = mysqli_real_escape_string($conn, $groupBy);

    $data = getChartData($conn, $chartType, $groupBy);
    echo json_encode($data);
    exit;
}

// ดึงข้อมูลสถิติต่างๆ
$elephantsOnRoadCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM detections WHERE elephant = '1' AND car_count = '0'"))['count'] ?? 0;
$elephantsCarCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM detections WHERE elephant = '1' AND car_count = '1'"))['count'] ?? 0;
$statusCountcompleted = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM detections WHERE status = 'completed'"))['count'] ?? 0;
$statusCountpending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM detections WHERE status = 'pending'"))['count'] ?? 0;

// ดึงข้อมูลการตรวจจับในแต่ละเดือน
$sql = "SELECT 
          DATE_FORMAT(time, '%Y-%m') AS month,
          SUM(elephant) AS elephant_count,
          SUM(elephant AND alert) AS elephant_car_count
        FROM detections
        GROUP BY DATE_FORMAT(time, '%Y-%m')
        ORDER BY DATE_FORMAT(time, '%Y-%m')";
$result = $conn->query($sql);

$data = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

// ส่งข้อมูลไปยัง JavaScript ในรูปแบบ JSON
$jsonData = json_encode($data);
echo "<script>var chartData = $jsonData;</script>";

// ดึงข้อมูลตำแหน่งติดตั้งกล้อง
$installPoints = [];
$sql = "SELECT lat_cam, long_cam, id_cam FROM detections"; 
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $installPoints[] = $row;
}

mysqli_close($conn);
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
        /* กำหนดความสูงคงที่ให้กับ Container ของกราฟ */
        .chart-container {
            height: 16rem; /* 64 * 0.25rem = 16rem */
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
            </header>

            <!-- Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
				
                <!-- การแจ้งเตือนเหตุการณ์ช้าง -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">การแจ้งเตือนเหตุการณ์ช้าง</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ช้างอยู่บนถนน:</span>
                            <span class="text-xl font-bold text-red-500"><?= number_format($elephantsOnRoadCount); ?> ตัว</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รถ-ช้างอยู่ในพื้นที่เดียวกัน:</span>
                            <span class="text-xl font-bold text-orange-500"><?= number_format($elephantsCarCount); ?> ตัว</span>
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
                            <span class="text-xl font-bold text-green-500"><?= number_format($statusCountcompleted); ?> เหตุการณ์</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รอดำเนินการ:</span>
                            <span class="text-xl font-bold text-yellow-500"><?= number_format($statusCountpending); ?> เหตุการณ์</span>
                        </div>
                    </div>
                </div>
                <!-- จำนวนกล้องเฝ้าระวัง -->
                <div class="flex flex-col p-6 bg-white rounded-lg shadow-md">
                    <h3 class="mb-4 text-lg font-semibold text-gray-700">จำนวนกล้องเฝ้าระวัง</h3>
                    <div class="flex flex-col space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ทั้งหมด:</span>
                            <span class="text-xl font-bold text-purple-500">8 ตัว</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">ใช้งานอยู่:</span>
                            <span class="text-xl font-bold text-indigo-500"><?= number_format($elephantsCarCount); ?> ตัว</span>
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
                    <!-- Chart 1: ช้างและรถ -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 chart-container">
                        <h3 class="mb-4 text-lg font-semibold text-gray-700">การแจ้งเตือนช้างและรถ</h3>
                        <canvas id="visitorInsightsChart"></canvas>
                    </div>
                    <!-- Chart 2: Online vs Offline Sales -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 chart-container">
                        <h3 class="mb-4 text-lg font-semibold text-gray-700">Online vs Offline Sales</h3>
                        <canvas id="totalRevenueChart"></canvas>
                    </div>
                    <!-- Chart 3: Customer Satisfaction -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 chart-container">
                        <h3 class="mb-4 text-lg font-semibold text-gray-700">Customer Satisfaction</h3>
                        <canvas id="customerSatisfactionChart"></canvas>
                    </div>
                    <!-- Chart 4: Target vs Reality -->
                    <div class="flex flex-col bg-white rounded-lg shadow-md p-4 chart-container">
                        <h3 class="mb-4 text-lg font-semibold text-gray-700">Target vs Reality</h3>
                        <canvas id="targetVsRealityChart"></canvas>
                    </div>


                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet.js -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <!-- Chart.js -->
    <!-- ลบการโหลด Chart.js ซ้ำ -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->

    <script>
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

        // ตรวจสอบว่า chartData ถูกต้อง
        console.log(chartData);

        // ตัวอย่างกราฟที่ 1 (visitorInsightsChart)
        const ctxVisitor = document.getElementById('visitorInsightsChart').getContext('2d');
        const labelsVisitor = chartData.map(item => item.month);
        const elephantData = chartData.map(item => item.elephant_count);
        const elephantCarData = chartData.map(item => item.elephant_car_count);

        new Chart(ctxVisitor, {
            type: 'line',
            data: {
				
                labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
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
                        backgroundColor: 'rgba(255, 0, 0, 0.2)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: true, // เปลี่ยนเป็น true เพื่อรักษาอัตราส่วน
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
                maintainAspectRatio: true, // เปลี่ยนเป็น true เพื่อรักษาอัตราส่วน
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
                maintainAspectRatio: true, // เปลี่ยนเป็น true เพื่อรักษาอัตราส่วน
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // ตัวอย่างกราฟที่ 4 (targetVsRealityChart)
        const ctxTargetReality = document.getElementById('targetVsRealityChart').getContext('2d');
        new Chart(ctxTargetReality, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
                datasets: [
                    {
                        label: 'Reality Sales',
                        data: [8823, 9100, 8800, 9500, 10000, 11200, 11800],
                        backgroundColor: 'rgba(255, 159, 64, 0.6)'
                    },
                    {
                        label: 'Target Sales',
                        data: [12122, 11500, 12500, 13000, 14000, 14200, 14500],
                        backgroundColor: 'rgba(75, 192, 192, 0.6)'
                    }
                ]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: true, // เปลี่ยนเป็น true เพื่อรักษาอัตราส่วน
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // สร้างแผนที่ Leaflet.js
        var map = L.map('map').setView([13.736717, 100.523186], 6); // พิกัดเริ่มต้นประเทศไทย

        // เพิ่ม Tile Layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // ตำแหน่งติดตั้งจาก PHP (แปลงเป็น JSON)
        var installPoints = <?= json_encode($installPoints); ?>;

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
			// ตรวจจับการเลือกช่วงเวลาใน dropdown
document.getElementById('timePeriod').addEventListener('change', function() {
    var timePeriod = this.value;  // รับค่าจาก dropdown
    fetchChartData(timePeriod);  // เรียกฟังก์ชันดึงข้อมูลใหม่
});

function fetchChartData(timePeriod) {
    // ส่งค่าไปยัง server เพื่อดึงข้อมูลใหม่
    fetch(`?action=fetch_data&group_by=${timePeriod}&chart=elephant_count`)
        .then(response => response.json())
        .then(data => {
            updateChart(data, timePeriod);  // อัปเดตกราฟตามข้อมูลที่ได้รับ
        })
        .catch(error => console.error('Error fetching chart data:', error));
}

function updateChart(data, timePeriod) {
    // อัปเดตข้อมูลกราฟที่แสดง
    const ctx = document.getElementById('visitorInsightsChart').getContext('2d');
    const labels = data.map(item => item.period);
    const elephantData = data.map(item => item.count);
    
    // อัปเดตกราฟ
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'ช้างอยู่บนถนน',
                    data: elephantData,
                    borderColor: 'orange',
                    backgroundColor: 'rgba(255, 165, 0, 0.2)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: { 
            responsive: true,
            maintainAspectRatio: true, 
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

        });
    </script>
</body>
</html>

<?php
ob_end_flush();
?>
