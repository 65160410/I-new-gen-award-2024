<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ฟังก์ชันช่วยเหลือสำหรับสร้างคำสั่ง GROUP BY ตามช่วงเวลา
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
            return "1"; // สำหรับกราฟที่ไม่ต้องการจัดกลุ่ม
        default:
            return "DATE($timeField)";
    }
}

// ฟังก์ชันสำหรับดึงข้อมูลตามประเภทกราฟและช่วงเวลา พร้อมจำกัดจำนวนข้อมูล
function getChartData($conn, $chartType, $groupBy) {
    try {
        // กำหนดจำนวนข้อมูลที่ต้องการแสดงตามช่วงเวลา
        switch ($groupBy) {
            case 'day':
                $limit = 7;
                break;
            case 'week':
                $limit = 4;
                break;
            case 'month':
                $limit = 12;
                break;
            case 'year':
                $limit = 5; // คุณสามารถปรับเปลี่ยนได้ตามต้องการ
                break;
            case 'all':
                $limit = 0; // ไม่มีการจำกัด
                break;
            default:
                $limit = 7;
        }

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
                if ($limit > 0) {
                    $query .= " LIMIT $limit";
                }
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
                if ($limit > 0) {
                    $query .= " LIMIT $limit";
                }
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
                if ($limit > 0) {
                    $query .= " LIMIT $limit";
                }
                break;
            case 'summary_events':
                // ดึงข้อมูลสรุปเหตุการณ์ทั้งหมดและเหตุการณ์ที่แก้ไข
                $query = "
                    SELECT 
                        COUNT(*) AS total_events,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS resolved_events
                    FROM detections
                ";
                break;
            case 'resolved_percentage_per_level':
                // ดึงข้อมูลเปอร์เซ็นต์การแก้ไขตามระดับเหตุการณ์
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
                // ดึงข้อมูลลำดับบริเวณที่เกิดเหตุสูงสุด 10 อันดับ
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

        if (in_array($chartType, ['summary_events', 'resolved_percentage_per_level'])) {
            $result = mysqli_query($conn, $query);
            if (!$result) {
                throw new Exception("Error in query: " . mysqli_error($conn));
            }

            $data = mysqli_fetch_assoc($result);
            mysqli_free_result($result);
        } else {
            $result = mysqli_query($conn, $query);
            if (!$result) {
                throw new Exception("Error in query: " . mysqli_error($conn));
            }

            $data = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }

            // กลับข้อมูลเป็นลำดับจากเก่าไปใหม่
            $data = array_reverse($data);
        }

        return $data;

    } catch (Exception $e) {
        error_log($e->getMessage());
        return [];
    }
}

// ตรวจสอบว่ามีการร้องขอข้อมูลผ่าน AJAX หรือไม่
if (isset($_GET['action']) && $_GET['action'] === 'fetch_data') {
    $chartType = $_GET['chart'] ?? '';
    $groupBy = $_GET['group_by'] ?? 'day';

    $data = getChartData($conn, $chartType, $groupBy);
    echo json_encode($data);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>กราฟความเสี่ยงและจำนวนช้าง</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <style>
        .sidebar {
            width: 16rem;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
        }
        .chart-container {
            position: relative;
            width: 100%;
            background-color: white;
            padding: 1rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            height: 400px;
            min-height: 300px;
            overflow: hidden;
        }
        canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .group-by-select {
            float: right;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100">
    <!-- Slide Bar -->
    <div class="sidebar bg-white border-r border-gray-200 fixed inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out z-50">
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

    <!-- Mobile menu button -->
    <div class="md:hidden flex items-center p-2 bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-40">
        <button id="menu-btn" class="text-gray-600 focus:outline-none">
            <i class="fas fa-bars fa-lg"></i>
        </button>
        <h2 class="ml-4 text-2xl font-semibold text-gray-700">กราฟความเสี่ยงและจำนวนช้าง</h2>
    </div>

    <!-- Main content -->
    <div class="flex-1 ml-0 md:ml-64 p-6 mt-16 md:mt-0 overflow-auto">
        <h1 class="text-3xl font-bold mb-6">กราฟความเสี่ยงและจำนวนช้าง</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Existing Charts -->
            <!-- Risk Level Chart -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">ระดับความเสี่ยงที่เจอช้างกับรถในเฟรมเดียวกัน</h2>
                    <div class="flex space-x-2">
                        <select class="group-by-select border rounded p-1" id="riskLevelGroupBy">
                            <option value="day">วัน</option>
                            <option value="week">สัปดาห์</option>
                            <option value="month">เดือน</option>
                            <option value="year">ปี</option>
                        </select>
                        <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="riskLevelModal">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <canvas id="riskLevelChart"></canvas>
            </div>

            <!-- Elephant Detection Chart -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">จำนวนช้างที่ถูกตรวจจับ</h2>
                    <div class="flex space-x-2">
                        <select class="group-by-select border rounded p-1" id="elephantCountGroupBy">
                            <option value="day">วัน</option>
                            <option value="week">สัปดาห์</option>
                            <option value="month">เดือน</option>
                            <option value="year">ปี</option>
                        </select>
                        <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="elephantCountModal">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <canvas id="elephantCountChart"></canvas>
            </div>

            <!-- Camera Locations Chart -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">สถานที่ติดตั้งกล้องแต่ละจุด</h2>
                    <div class="flex space-x-2">
                        <select class="group-by-select border rounded p-1" id="cameraLocationsGroupBy">
                            <option value="day">วัน</option>
                            <option value="week">สัปดาห์</option>
                            <option value="month">เดือน</option>
                            <option value="year">ปี</option>
                        </select>
                        <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="cameraLocationsModal">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <canvas id="cameraLocationsChart"></canvas>
            </div>

            <!-- สรุปกราฟจำนวนเหตุการณ์ทั้งหมดและจำนวนเหตุการณ์ที่แก้ไข พร้อมเปอร์เซ็นต์ -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">สรุปจำนวนเหตุการณ์และการแก้ไข</h2>
                    <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="summaryEventsModal">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>
                <canvas id="summaryEventsChart"></canvas>
            </div>

            <!-- กราฟจำนวนและเปอร์เซ็นต์ความเสี่ยงแต่ละระดับ -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">จำนวนและเปอร์เซ็นต์ความเสี่ยงแต่ละระดับ</h2>
                    <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="riskLevelPercentageModal">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>
                <canvas id="riskLevelPercentageChart"></canvas>
            </div>

            <!-- กราฟลำดับบริเวณที่เกิดเหตุสูงสุด 10 อันดับ พร้อมเปอร์เซ็นต์ -->
            <div class="chart-container relative">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">ลำดับบริเวณที่เกิดเหตุสูงสุด 10 อันดับ</h2>
                    <button class="expand-btn text-gray-600 hover:text-gray-800 focus:outline-none" data-modal="topAreasModal">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>
                <canvas id="topAreasChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Modal Template -->
    <div id="modalTemplate" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg overflow-hidden w-11/12 md:w-4/5 lg:w-3/5 xl:w-2/3 relative">
            <button class="close-modal absolute top-2 right-2 text-gray-600 hover:text-gray-800 focus:outline-none">
                <i class="fas fa-times fa-lg"></i>
            </button>
            <div class="p-4">
                <canvas class="expanded-chart"></canvas>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ฟังก์ชันสำหรับดึงข้อมูลจากเซิร์ฟเวอร์
            function fetchData(chartType, groupBy, callback) {
                let url = `ChartDashboard.php?action=fetch_data&chart=${chartType}`;
                if (groupBy && groupBy !== 'all') {
                    url += `&group_by=${groupBy}`;
                }
                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => callback(data))
                    .catch(error => console.error('Error fetching data:', error));
            }

            // ฟังก์ชันสำหรับแสดง Modal
            function showModal(chartId, title, renderFunction) {
                const modal = document.getElementById('modalTemplate').cloneNode(true);
                modal.id = '';
                modal.classList.remove('hidden');

                const expandedCanvas = modal.querySelector('.expanded-chart');
                const closeModalBtn = modal.querySelector('.close-modal');

                closeModalBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    modal.remove();
                });

                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.classList.add('hidden');
                        modal.remove();
                    }
                });

                document.body.appendChild(modal);

                // Render the chart inside the modal
                renderFunction(expandedCanvas);

                // Set title if needed (optional)
                // You can modify the modal template to include a title if desired
            }

            // 1. Summary Events Chart
            const summaryEventsCtx = document.getElementById('summaryEventsChart').getContext('2d');
            let summaryEventsChart;

            function renderSummaryEventsChart(canvas = summaryEventsCtx) {
                fetchData('summary_events', 'all', function(data) {
                    const totalEvents = parseInt(data.total_events);
                    const resolvedEvents = parseInt(data.resolved_events);
                    const resolvedPercentage = totalEvents > 0 ? ((resolvedEvents / totalEvents) * 100).toFixed(2) : 0;

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'doughnut',
                        data: {
                            labels: ['เหตุการณ์ทั้งหมด', 'เหตุการณ์ที่แก้ไขแล้ว'],
                            datasets: [{
                                data: [totalEvents, resolvedEvents],
                                backgroundColor: ['rgba(54, 162, 235, 0.6)', 'rgba(75, 192, 192, 0.6)'],
                                borderColor: ['rgba(54, 162, 235, 1)', 'rgba(75, 192, 192, 1)'],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed !== null) {
                                                label += context.parsed;
                                                if (context.label === 'เหตุการณ์ที่แก้ไขแล้ว') {
                                                    label += ` (${resolvedPercentage}%)`;
                                                }
                                            }
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });
                });
            }

            // 2. Risk Level Chart
            const riskLevelCtx = document.getElementById('riskLevelChart').getContext('2d');
            let riskLevelChart;

            function renderRiskLevelChart(groupBy, canvas = riskLevelCtx) {
                fetchData('risk_level', groupBy, function(data) {
                    const labels = [];
                    const hasRisk = [];
                    const noRisk = [];

                    data.forEach(item => {
                        const period = item.period;
                        const alert = item.alert;
                        const count = parseInt(item.count);

                        if (!labels.includes(period)) {
                            labels.push(period);
                            hasRisk.push(0);
                            noRisk.push(0);
                        }

                        const index = labels.indexOf(period);
                        if (alert == 1) {
                            hasRisk[index] += count;
                        } else {
                            noRisk[index] += count;
                        }
                    });

                    // เพื่อให้กราฟแสดงข้อมูลจากเก่าไปใหม่
                    labels.reverse();
                    hasRisk.reverse();
                    noRisk.reverse();

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'มีความเสี่ยง',
                                    data: hasRisk,
                                    backgroundColor: 'rgba(255, 192, 203, 0.6)', // สีชมพู
                                    borderColor: 'rgba(255, 192, 203, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'ไม่มีความเสี่ยง',
                                    data: noRisk,
                                    backgroundColor: 'rgba(54, 162, 235, 0.6)', // สีฟ้า
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: false // แสดงกราฟสองแท่งด้านข้างกัน
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                }
                            }
                        }
                    });
                });
            }

            // 3. Elephant Count Chart
            const elephantCountCtx = document.getElementById('elephantCountChart').getContext('2d');
            let elephantCountChart;

            function renderElephantCountChart(groupBy, canvas = elephantCountCtx) {
                fetchData('elephant_count', groupBy, function(data) {
                    const labels = data.map(item => item.period);
                    const counts = data.map(item => parseInt(item.count));

                    // เพื่อให้กราฟแสดงข้อมูลจากเก่าไปใหม่
                    labels.reverse();
                    counts.reverse();

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'จำนวนช้าง',
                                data: counts,
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                borderColor: 'rgba(54, 162, 235, 1)',
                                borderWidth: 1,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                }
                            }
                        }
                    });
                });
            }

            // 4. Camera Locations Chart
            const cameraLocationsCtx = document.getElementById('cameraLocationsChart').getContext('2d');
            let cameraLocationsChart;

            function renderCameraLocationsChart(groupBy, canvas = cameraLocationsCtx) {
                fetchData('camera_locations', groupBy, function(data) {
                    const locations = [...new Set(data.map(item => item.camera_location))];
                    const periods = [...new Set(data.map(item => item.period))];
                    periods.sort();

                    const datasets = locations.map((location, index) => {
                        const dataPoints = periods.map(period => {
                            const record = data.find(d => d.camera_location === location && d.period === period);
                            return record ? parseInt(record.count) : 0;
                        });

                        const colors = [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 205, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(201, 203, 207, 0.6)'
                        ];

                        return {
                            label: location,
                            data: dataPoints,
                            backgroundColor: colors[index % colors.length],
                            borderColor: colors[index % colors.length],
                            borderWidth: 1
                        };
                    });

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: periods,
                            datasets: datasets
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: true
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                }
                            }
                        }
                    });
                });
            }

            // 5. Risk Level Percentage Chart
            const riskLevelPercentageCtx = document.getElementById('riskLevelPercentageChart').getContext('2d');
            let riskLevelPercentageChart;

            function renderRiskLevelPercentageChart(canvas = riskLevelPercentageCtx) {
                fetchData('resolved_percentage_per_level', 'all', function(data) {
                    const labels = data.map(item => `ความเสี่ยงระดับ ${item.risk_level}`);
                    const counts = data.map(item => parseInt(item.total_events));
                    const resolvedCounts = data.map(item => parseInt(item.resolved_events));
                    const percentages = counts.map((count, index) => {
                        return count > 0 ? ((resolvedCounts[index] / count) * 100).toFixed(2) : 0;
                    });

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: resolvedCounts,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.6)',
                                    'rgba(54, 162, 235, 0.6)',
                                    'rgba(255, 206, 86, 0.6)',
                                    'rgba(75, 192, 192, 0.6)',
                                    'rgba(153, 102, 255, 0.6)'
                                ],
                                borderColor: [
                                    'rgba(255,99,132,1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(153, 102, 255, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed !== null) {
                                                label += `${context.parsed} (${percentages[context.dataIndex]}%)`;
                                            }
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });
                });
            }

            // 6. Top Areas Chart
            const topAreasCtx = document.getElementById('topAreasChart').getContext('2d');
            let topAreasChart;

            function renderTopAreasChart(canvas = topAreasCtx) {
                fetchData('top_areas', 'all', function(data) {
                    const labels = data.map(item => item.area);
                    const counts = data.map(item => parseInt(item.count));
                    const total = counts.reduce((a, b) => a + b, 0);
                    const percentages = counts.map(count => total > 0 ? ((count / total) * 100).toFixed(2) : 0);

                    if (canvas.chartInstance) {
                        canvas.chartInstance.destroy();
                    }

                    canvas.chartInstance = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'จำนวนเหตุการณ์',
                                data: counts,
                                backgroundColor: 'rgba(255, 159, 64, 0.6)',
                                borderColor: 'rgba(255, 159, 64, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false,
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = `${context.parsed.x} (${percentages[context.dataIndex]}%)`;
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });
                });
            }

            // เรียกใช้ฟังก์ชันกราฟครั้งแรก
            renderRiskLevelChart('day');
            renderElephantCountChart('day');
            renderCameraLocationsChart('day');
            renderSummaryEventsChart(); // สรุปเหตุการณ์
            renderRiskLevelPercentageChart(); // ความเสี่ยงแต่ละระดับ
            renderTopAreasChart(); // ลำดับบริเวณที่เกิดเหตุ

            // เพิ่ม event listeners สำหรับการเปลี่ยนแปลงการกรองข้อมูล
            document.getElementById('riskLevelGroupBy').addEventListener('change', function() {
                renderRiskLevelChart(this.value);
            });

            document.getElementById('elephantCountGroupBy').addEventListener('change', function() {
                renderElephantCountChart(this.value);
            });

            document.getElementById('cameraLocationsGroupBy').addEventListener('change', function() {
                renderCameraLocationsChart(this.value);
            });

            // ปุ่มขยายกราฟ
            const expandButtons = document.querySelectorAll('.expand-btn');
            expandButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal');
                    const chartId = this.parentElement.parentElement.querySelector('canvas').id;
                    const title = this.parentElement.parentElement.querySelector('h2').innerText;

                    showModal(chartId, title, function(expandedCanvas) {
                        switch (chartId) {
                            case 'riskLevelChart':
                                renderRiskLevelChart(document.getElementById('riskLevelGroupBy').value, expandedCanvas);
                                break;
                            case 'elephantCountChart':
                                renderElephantCountChart(document.getElementById('elephantCountGroupBy').value, expandedCanvas);
                                break;
                            case 'cameraLocationsChart':
                                renderCameraLocationsChart(document.getElementById('cameraLocationsGroupBy').value, expandedCanvas);
                                break;
                            case 'summaryEventsChart':
                                renderSummaryEventsChart(expandedCanvas);
                                break;
                            case 'riskLevelPercentageChart':
                                renderRiskLevelPercentageChart(expandedCanvas);
                                break;
                            case 'topAreasChart':
                                renderTopAreasChart(expandedCanvas);
                                break;
                            default:
                                console.error('Unknown chart ID:', chartId);
                        }
                    });
                });
            });

            // ฟังก์ชันสำหรับแสดง Modal
            function showModal(chartId, title, renderFunction) {
                const modal = document.createElement('div');
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
                modal.innerHTML = `
                    <div class="bg-white rounded-lg overflow-hidden w-11/12 md:w-4/5 lg:w-3/5 xl:w-2/3 relative">
                        <button class="close-modal absolute top-2 right-2 text-gray-600 hover:text-gray-800 focus:outline-none">
                            <i class="fas fa-times fa-lg"></i>
                        </button>
                        <div class="p-4">
                            <h2 class="text-2xl font-semibold mb-4">${title}</h2>
                            <canvas class="expanded-chart"></canvas>
                        </div>
                    </div>
                `;

                const expandedCanvas = modal.querySelector('.expanded-chart');
                const closeModalBtn = modal.querySelector('.close-modal');

                closeModalBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    modal.remove();
                });

                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.classList.add('hidden');
                        modal.remove();
                    }
                });

                document.body.appendChild(modal);

                // Render the chart inside the modal
                renderFunction(expandedCanvas);
            }

            // Error handling for chart loading
            window.addEventListener('error', function(e) {
                console.error('Chart Error:', e.error);
                // แสดงข้อความแจ้งเตือนให้ผู้ใช้ทราบถ้ามีข้อผิดพลาด
                if (e.target.tagName === 'CANVAS') {
                    const container = e.target.parentElement;
                    container.innerHTML += '<div class="text-red-500 mt-4">ไม่สามารถโหลดกราฟได้ กรุณารีเฟรชหน้าเว็บ</div>';
                }
            });
        });

        // Mobile sidebar toggle
        const menuBtn = document.getElementById('menu-btn');
        const sidebar = document.querySelector('.sidebar');

        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
