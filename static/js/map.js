//
// สร้างแผนที่ และตั้งค่าให้เริ่มต้นที่อุทยานแห่งชาติเขาใหญ่
const map = L.map('map').setView([14.4332, 101.3728], 12); // พิกัดเขาใหญ่

// เพิ่มแผนที่พื้นฐาน (OpenStreetMap)
const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
});
osm.addTo(map);

// สร้างตัวแปรสำหรับ Routing Control
let routingControl = null;

// สร้าง custom icon สำหรับกล้อง
const cameraIcon = L.icon({
    iconUrl: 'https://cdn-icons-png.flaticon.com/128/45/45010.png',
    iconSize: [15, 15],
    iconAnchor: [16, 32],
    popupAnchor: [0, -32]
});

// ดึงข้อมูลตำแหน่งจาก API
fetch('/api/locations')
    .then(response => response.json())
    .then(locations => {
        const markerLayer = L.layerGroup().addTo(map);

        // เพิ่มมาร์กเกอร์สำหรับสถานที่ทั้งหมด
        locations.forEach(location => {
            // ใช้ไอคอนกล้องสำหรับทุกตำแหน่งที่ชื่อขึ้นต้นด้วย Cam
            const markerIcon = location.name.startsWith('Cam') ? cameraIcon : null;
            
            const marker = L.marker([location.lat, location.lng], {
                icon: markerIcon  // ใช้ custom icon ถ้ามี
            })
            .bindPopup(`
                <b>${location.name}</b><br>
                ละติจูด: ${location.lat.toFixed(5)}<br>
                ลองจิจูด: ${location.lng.toFixed(5)}<br>
                <button onclick="startNavigation(${location.lat}, ${location.lng})">
                    นำทางมาที่นี่
                </button>
            `)
            .addTo(markerLayer);
            marker.options.name = location.name;
        });

        // เพิ่มระบบค้นหา
        const searchControl = new L.Control.Search({
            layer: markerLayer,
            propertyName: 'name',
            marker: false,
            initial: false,
            moveToLocation: (latlng) => {
                map.setView(latlng, 14);
            }
        });
        map.addControl(searchControl);
    });

// ส่วนที่เหลือของโค้ดยังคงเหมือนเดิม...
function startNavigation(lat, lng) {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position => {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;

            if (routingControl) {
                map.removeControl(routingControl);
            }

            routingControl = L.Routing.control({
                waypoints: [
                    L.latLng(userLat, userLng),
                    L.latLng(lat, lng)
                ],
                router: L.Routing.osrmv1({
                    serviceUrl: 'https://router.project-osrm.org/route/v1'
                }),
                routeWhileDragging: true
            }).addTo(map);

            alert("เริ่มการนำทาง!");
        }, error => {
            alert("ไม่สามารถระบุตำแหน่งของคุณได้: " + error.message);
        });
    } else {
        alert("เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง");
    }
}

function locateUser() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            map.setView([lat, lng], 14);

            L.marker([lat, lng])
                .addTo(map)
                .bindPopup("ตำแหน่งปัจจุบันของคุณ")
                .openPopup();
        }, error => {
            alert("ไม่สามารถระบุตำแหน่งของคุณได้: " + error.message);
        });
    } else {
        alert("เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง");
    }
}

// ตัวแปรสำหรับเก็บมาร์กเกอร์ล่าสุด
let currentMarker = null;

// ฟังก์ชันดึงข้อมูลชื่อสถานที่
function getPlaceName(lat, lng, callback) {
    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;
    fetch(url)
        .then(response => response.json())
        .then(data => {
            const placeName = data.display_name || "ไม่ทราบชื่อสถานที่";
            callback(placeName);
        })
        .catch(error => {
            console.error("เกิดข้อผิดพลาดในการดึงข้อมูลชื่อสถานที่:", error);
            callback("ไม่สามารถดึงชื่อสถานที่ได้");
        });
}

map.on('click', function (e) {
    const { lat, lng } = e.latlng;

    if (currentMarker) {
        map.removeLayer(currentMarker);
    }

    getPlaceName(lat, lng, placeName => {
        currentMarker = L.marker([lat, lng])
            .addTo(map)
            .bindPopup(`
                ชื่อสถานที่: ${placeName} <br>
                ละติจูด: ${lat.toFixed(5)} <br>
                ลองจิจูด: ${lng.toFixed(5)} <br>
                <button onclick="startNavigation(${lat}, ${lng})">นำทางไปยังตำแหน่งนี้</button>
            `)
            .openPopup();
    });
});

// สร้างตัวแปรสำหรับ Geosearch Control
const geosearchControl = new GeoSearch.GeoSearchControl({
    provider: new GeoSearch.OpenStreetMapProvider(),
    style: 'button',
    autoComplete: true,
    autoCompleteDelay: 250,
    showMarker: false,
    updateMap: true,
    searchLabel: 'ค้นหาสถานที่...',
    retainZoomLevel: false,
    animateZoom: true,
});

map.addControl(geosearchControl);

let searchMarker = null;

map.on('geosearch/showlocation', function (event) {
    const { x: lng, y: lat } = event.location;

    if (searchMarker) {
        map.removeLayer(searchMarker);
    }

    getPlaceName(lat, lng, placeName => {
        searchMarker = L.marker([lat, lng])
            .addTo(map)
            .bindPopup(`
                <b>ชื่อสถานที่: ${placeName}</b><br>
                ละติจูด: ${lat.toFixed(5)}<br>
                ลองจิจูด: ${lng.toFixed(5)}<br>
                <button onclick="startNavigation(${lat}, ${lng})">นำทางไปยังตำแหน่งนี้</button>
            `)
            .openPopup();

        map.setView([lat, lng], 14);
    });
});

function resizeMap() {
    const mapElement = document.getElementById('map');
    mapElement.style.height = `${window.innerHeight - 120}px`;
}

window.addEventListener('load', resizeMap);
window.addEventListener('resize', resizeMap);
window.addEventListener('resize', () => {
    map.invalidateSize();
});