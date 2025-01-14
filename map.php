<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>ระบบแผนที่และนำทางอัจฉริยะ</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3B82F6;
            --primary-dark: #2563EB;
            --success-color: #10B981;
            --bg-light: #F3F4F6;
            --bg-dark: #1F2937;
            --text-light: #374151;
            --text-dark: #F9FAFB;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Prompt', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            padding: 20px;
            transition: all 0.3s ease;
        }

        body.dark-mode {
            background-color: var(--bg-dark);
            color: var(--text-dark);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
            transition: all 0.3s ease;
        }

        .dark-mode .container {
            background: #111827;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 0 10px;
        }

        h1 {
            color: var(--text-light);
            font-size: 2em;
            font-weight: 600;
        }

        .dark-mode h1 {
            color: var(--text-dark);
        }

        .controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 10px;
            background: var(--bg-light);
            border-radius: 15px;
            flex-wrap: wrap;
        }

        .dark-mode .controls {
            background: #374151;
        }

        .modern-button {
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .modern-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .theme-toggle {
            padding: 8px 16px;
            border-radius: 12px;
            background: var(--bg-light);
            border: 2px solid var(--primary-color);
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dark-mode .theme-toggle {
            background: #374151;
            color: var(--text-dark);
            border-color: var(--primary-dark);
        }

        #mapid {
            height: 600px;
            width: 100%;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            z-index: 1;
        }

        .dark-mode #mapid {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .info-panel {
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .dark-mode .info-panel {
            background: rgba(17, 24, 39, 0.9);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.95);
            padding: 20px 40px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .dark-mode .loading {
            background: rgba(17, 24, 39, 0.95);
            color: var(--text-dark);
        }

        .loading::after {
            content: '';
            display: block;
            width: 30px;
            height: 30px;
            margin: 10px auto;
            border: 3px solid var(--primary-color);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .dark-mode .stat-card {
            background: #374151;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        /* Custom Leaflet Controls */
        .leaflet-control-geocoder {
            border-radius: 12px !important;
            overflow: hidden;
        }

        .leaflet-control-geocoder-form input {
            padding: 8px 12px !important;
            border: none !important;
            font-family: 'Prompt', sans-serif !important;
        }

        .dark-mode .leaflet-control-geocoder,
        .dark-mode .leaflet-control-geocoder-form input {
            background: #374151 !important;
            color: var(--text-dark) !important;
        }

        /* Custom Popup Style */
        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            padding: 5px;
        }

        .dark-mode .leaflet-popup-content-wrapper {
            background: #374151;
            color: var(--text-dark);
        }

        .popup-content {
            padding: 10px;
        }

        .popup-content h3 {
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .dark-mode .popup-content h3 {
            color: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ระบบแผนที่และนำทางอัจฉริยะ</h1>
            <button onclick="toggleTheme()" class="theme-toggle" id="themeToggle">
                <span id="themeIcon">🌙</span>
            </button>
        </div>
        
        <div class="controls">
            <button class="modern-button" onclick="getLocation()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
                </svg>
                ค้นหาตำแหน่งของคุณ
            </button>
            <button class="modern-button" onclick="showNearbyPlaces()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zM2.04 4.326c.325 1.329 2.532 2.54 3.717 3.19.48.263.793.434.743.484-.08.08-.162.158-.242.234-.416.396-.787.749-.758 1.266.035.634.618.824 1.214 1.017.577.188 1.168.38 1.286.983.082.417-.075.988-.22 1.52-.215.782-.406 1.48.22 1.48 1.5-.5 3.798-3.186 4-5 .138-1.243-2-2-3.5-2.5-.478-.16-.755.081-.99.284-.172.15-.322.279-.51.216-.445-.148-2.5-2-1.5-2.5.78-.39.952-.171 1.227.182.078.099.163.208.273.318.609.304.662-.132.723-.633.039-.322.081-.671.277-.867.434-.434 1.265-.791 2.028-1.12.712-.306 1.365-.587 1.579-.88A7 7 0 1 1 2.04 4.327z"/>
                </svg>
                สถานที่ใกล้เคียง
            </button>
        </div>
        
        <div id="mapid"></div>
        <div class="loading">กำลังโหลด...</div>
        
        <div class="stats">
            <div class="stat-card">
                <h3>ระยะทางรวม</h3>
                <p id="totalDistance">- กม.</p>
            </div>
            <div class="stat-card">
                <h3>เวลาเดินทาง</h3>
                <p id="travelTime">- นาที</p>
            </div>
            <div class="stat-card">
                <h3>จุดสนใจใกล้เคียง</h3>
                <p id="nearbyCount">- แห่ง</p>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.js"></script>
    <script>
        let isDarkMode = false;
        const darkTileLayer = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
        const lightTileLayer = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
        let currentTileLayer;

        function toggleTheme() {
            isDarkMode = !isDarkMode;
            document.body.classList.toggle('dark-mode');
            document.getElementById('themeIcon').textContent = isDarkMode ? '☀️' : '🌙';
            
            // Switch map tile layer
            mymap.removeLayer(currentTileLayer);
            currentTileLayer = L.tileLayer(isDarkMode ? darkTileLayer : lightTileLayer, {
                maxZoom: 19,
                attribution: isDarkMode ? '© CartoDB, OpenStreetMap' : '© OpenStreetMap'
            }).addTo(mymap);
        }

        var mymap = L.map('mapid').setView([14.597093, 101.377258], 13);
        currentTileLayer = L.tileLayer(lightTileLayer, {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(mymap);

        var currentLocationMarker = null;
        var lastMarker = null;
        var currentRoute = null;

        // Modern camera icon
        var cameraIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/128/45/45010.png',
            iconSize: [36, 36],
            iconAnchor: [18, 36],
            popupAnchor: [0, -36],
            className: 'camera-icon'
        });

        // Modern location icon
        var currentLocationIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/1828/1828884.png',
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18],
            className: 'location-icon'
        });

        var camera1Marker = L.marker([14.22282, 101.40557], { icon: cameraIcon }).addTo(mymap);
        camera1Marker.bindPopup(`
            <div class="popup-content">
                <h3>กล้อง CCTV #1</h3>
                <p>ตำแหน่ง: 14.22282, 101.40557</p>
                <p>สถานะ: ออนไลน์</p>
			<button class="modern-button" onclick="viewCameraFeed(1)">
                    ดูภาพจากกล้อง
                </button>
            </div>
        `).openPopup();

        var geocoder = L.Control.Geocoder.nominatim({
            geocodingQueryParams: {
                countrycodes: 'th',
                'accept-language': 'th'
            }
        });
        
        var searchControl = new L.Control.Geocoder({
            geocoder: geocoder,
            position: 'topleft',
            placeholder: 'ค้นหาสถานที่...',
            defaultMarkGeocode: false
        }).on('markgeocode', function(e) {
            if (lastMarker) {
                mymap.removeLayer(lastMarker);
            }
            const coords = e.geocode.center;
            lastMarker = L.marker(coords).addTo(mymap);
            mymap.fitBounds(e.geocode.bbox);
            updateStats(coords);
        }).addTo(mymap);

        function showLoading() {
            document.querySelector('.loading').style.display = 'block';
        }

        function hideLoading() {
            document.querySelector('.loading').style.display = 'none';
        }

        function getLocation() {
            showLoading();
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    showPosition,
                    showError,
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            } else {
                hideLoading();
                alert("เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง");
            }
        }

        function showPosition(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            if (currentLocationMarker) {
                mymap.removeLayer(currentLocationMarker);
            }

            currentLocationMarker = L.marker([lat, lng], { 
                icon: currentLocationIcon,
                bounceOnAdd: true
            }).addTo(mymap);
            
            currentLocationMarker.bindPopup(`
                <div class="popup-content">
                    <h3>ตำแหน่งของคุณ</h3>
                    <p>ละติจูด: ${lat.toFixed(5)}</p>
                    <p>ลองจิจูด: ${lng.toFixed(5)}</p>
                </div>
            `).openPopup();

            mymap.setView([lat, lng], 15);
            hideLoading();
            updateStats([lat, lng]);
            findNearbyPlaces([lat, lng]);
        }

        function showError(error) {
            hideLoading();
            let message = "";
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    message = "ผู้ใช้ปฏิเสธการขอตำแหน่ง";
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = "ไม่สามารถรับข้อมูลตำแหน่งได้";
                    break;
                case error.TIMEOUT:
                    message = "หมดเวลารอการตอบกลับ";
                    break;
                case error.UNKNOWN_ERROR:
                    message = "เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ";
                    break;
            }
            showNotification(message, 'error');
        }

        function createRoute(end) {
            if (currentLocationMarker) {
                var start = currentLocationMarker.getLatLng();
                
                if (currentRoute) {
                    mymap.removeControl(currentRoute);
                }

                currentRoute = L.Routing.control({
                    waypoints: [
                        L.latLng(start.lat, start.lng),
                        L.latLng(end[0], end[1])
                    ],
                    routeWhileDragging: true,
                    lineOptions: {
                        styles: [{ 
                            color: isDarkMode ? '#60A5FA' : '#3B82F6',
                            opacity: 0.8,
                            weight: 6
                        }]
                    },
                    createMarker: function() { return null; },
                    router: L.Routing.osrmv1({
                        language: 'th',
                        profile: 'car'
                    })
                }).addTo(mymap);

                currentRoute.on('routesfound', function(e) {
                    const routes = e.routes;
                    const summary = routes[0].summary;
                    updateRouteStats(summary);
                });
            } else {
                showNotification("กรุณาระบุตำแหน่งปัจจุบันก่อน", 'warning');
            }
        }

        function updateRouteStats(summary) {
            document.getElementById('totalDistance').textContent = 
                (summary.totalDistance / 1000).toFixed(2) + ' กม.';
            document.getElementById('travelTime').textContent = 
                Math.round(summary.totalTime / 60) + ' นาที';
        }

        function findNearbyPlaces(coords) {
            // Simulate finding nearby places
            const nearby = Math.floor(Math.random() * 10) + 5;
            document.getElementById('nearbyCount').textContent = nearby + ' แห่ง';
        }

        function showNearbyPlaces() {
            if (!currentLocationMarker) {
                showNotification("กรุณาระบุตำแหน่งปัจจุบันก่อน", 'warning');
                return;
            }
            // Add logic for showing nearby places
            showNotification("กำลังค้นหาสถานที่ใกล้เคียง...", 'info');
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        mymap.on('click', function(e) {
            var lat = e.latlng.lat;
            var lng = e.latlng.lng;

            if (lastMarker) {
                mymap.removeLayer(lastMarker);
            }

            lastMarker = L.marker([lat, lng]).addTo(mymap)
                .bindPopup(`
                    <div class="popup-content">
                        <h3>สถานที่ที่เลือก</h3>
                        <p>ละติจูด: ${lat.toFixed(5)}</p>
                        <p>ลองจิจูด: ${lng.toFixed(5)}</p>
                        <button onclick='createRoute([${lat}, ${lng}])' class='modern-button'>
                            นำทางไปที่นี่
                        </button>
                    </div>
                `).openPopup();
            
            updateStats([lat, lng]);
        });

        // เพิ่ม Style สำหรับ Notification
        const style = document.createElement('style');
        style.textContent = `
            .notification {
                position: fixed;
                bottom: 20px;
                right: 20px;
                padding: 15px 25px;
                border-radius: 12px;
                color: white;
                font-weight: 500;
                animation: slideIn 0.3s ease-out;
                z-index: 1000;
            }

            .notification.info {
                background: var(--primary-color);
            }

            .notification.warning {
                background: #F59E0B;
            }

            .notification.error {
                background: #EF4444;
            }

            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>