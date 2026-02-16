<?php
 $csvUrl = "https://docs.google.com/spreadsheets/d/e/2PACX-1vTSjf_ppDd3pJ3KrHeP99nI0J-l8jne8GyawbZfj42M5DP8xdh4dg7ifxeW4iirvQbIM99DhNDXaDYA/pub?gid=1095863488&single=true&output=csv";
function getDataFromCsv($url) {
    $data = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        
        $data = curl_exec($ch);
        
        if (curl_errno($ch)) {
            // ถ้า cURL ล้มเหลว จะลองวิธีที่ 2
            // echo "cURL Error: " . curl_error($ch); // ใช้สำหรับ Debug
            $data = false;
        }
        curl_close($ch);
    }

    // วิธีที่ 2: ใช้ file_get_contents (ถ้า cURL ไม่ทำงาน)
    if ($data === false || empty($data)) {
        if (ini_get('allow_url_fopen')) {
            $opts = [
                "http" => [
                    "method" => "GET",
                    "header" => "User-Agent: Mozilla/5.0\r\n" // ปลอมตัวเป็น Browser เผื่อ Google บล็อก bot
                ]
            ];
            $context = stream_context_create($opts);
            $data = @file_get_contents($url, false, $context);
        }
    }

    // ถ้าดึงไม่ได้จริงๆ
    if ($data === false || empty($data)) {
        return []; // คืนค่า Array ว่าง
    }

    // แปลง String เป็น Array
    $rows = explode("\n", $data);
    $csvData = [];
    foreach ($rows as $row) {
        if (!empty(trim($row))) {
            $csvData[] = str_getcsv($row);
        }
    }
    return $csvData;
}

 $allData = getDataFromCsv($csvUrl);
 $headers = array_shift($allData); // ตัดหัวตารางออก

// ตัวแปรสำหรับ Filter
 $filterDevice = isset($_GET['device']) ? $_GET['device'] : '';
 $filterType = isset($_GET['type']) ? $_GET['type'] : '';
 $searchKeyword = isset($_GET['search']) ? $_GET['search'] : '';

// เก็บค่า Unique สำหรับทำ Dropdown Filter
 $allDevices = [];
 $allTypes = [];

// ตัวแปรสำหรับสรุปผล
 $stats = [
    'total_records' => 0,
    'total_minutes' => 0,
    'by_device' => [],
    'by_type' => [],
    'filtered_logs' => []
];

// 3. ประมวลผลและกรองข้อมูล
foreach ($allData as $row) {
    // ตรวจสอบ Index ข้อมูล (0:วันที่, 1:หัวข้อ, 2:ประเภท, 3:อุปกรณ์, 4:เวลา)
    // ป้องกัน Error หากข้อมูลไม่ครบ
    $date = isset($row[0]) ? $row[0] : '-';
    $topic = isset($row[1]) ? $row[1] : '-';
    $type = isset($row[2]) ? $row[2] : '-';
    $device = isset($row[3]) ? $row[3] : '-';
    $duration_str = isset($row[4]) ? $row[4] : '0 นาที';

    // เก็บค่าลงใน Array สำหรับ Dropdown (ก่อนกรอง)
    if (!empty($device) && !in_array($device, $allDevices)) $allDevices[] = $device;
    if (!empty($type) && !in_array($type, $allTypes)) $allTypes[] = $type;

    // --- Logic การกรองข้อมูล ---
    $passFilter = true;

    if (!empty($filterDevice) && $device != $filterDevice) {
        $passFilter = false;
    }
    if (!empty($filterType) && $type != $filterType) {
        $passFilter = false;
    }
    if (!empty($searchKeyword)) {
        // ค้นหาในหัวข้อหรืออุปกรณ์
        if (stripos($topic, $searchKeyword) === false && stripos($device, $searchKeyword) === false) {
            $passFilter = false;
        }
    }

    // ถ้าผ่านการกรอง ค่อยนำไปคำนวณสถิติ
    if ($passFilter) {
        // แปลงเวลา
        $minutes = 0;
        if (preg_match('/(\d+)/', $duration_str, $matches)) {
            $minutes = intval($matches[1]);
        }

        $record = [
            'date' => $date,
            'topic' => $topic,
            'type' => $type,
            'device' => $device,
            'duration_str' => $duration_str,
            'minutes' => $minutes
        ];

        $stats['filtered_logs'][] = $record;
        $stats['total_records']++;
        $stats['total_minutes'] += $minutes;

        // นับสถิติกราฟ
        if (!isset($stats['by_device'][$device])) $stats['by_device'][$device] = 0;
        $stats['by_device'][$device]++;

        if (!isset($stats['by_type'][$type])) $stats['by_type'][$type] = 0;
        $stats['by_type'][$type]++;
    }
}

// คำนวณเฉลี่ย
 $avgTime = $stats['total_records'] > 0 ? round($stats['total_minutes'] / $stats['total_records'], 2) : 0;

// ฟังก์ชันคิดเปอร์เซ็นต์
function calculatePercent($value, $total) {
    if ($total == 0) return 0;
    return round(($value / $total) * 100);
}

// 4. ส่วน Export Excel (CSV)
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_export.csv"');
    
    $output = fopen('php://output', 'w');
    // เขียน Header (ถ้ามีภาษาไทย อาจต้องใช้ fwrite พิมพ์ BOM \xEF\xBB\xBF ก่อน เพื่อให้อ่านภาษาไทยได้ใน Excel)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, ['วันที่', 'หัวข้อ', 'ประเภท', 'อุปกรณ์', 'เวลา']);
    
    foreach ($stats['filtered_logs'] as $row) {
        fputcsv($output, [$row['date'], $row['topic'], $row['type'], $row['device'], $row['duration_str']]);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - รายงานการใช้งาน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #673ab7; --bg: #f4f6f9; --card: #fff; }
        body { font-family: 'Sarabun', sans-serif; background: var(--bg); color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }

        /* Header & Filter */
        header { background: var(--primary); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .header-title h1 { margin: 0; font-size: 24px; }
        .header-title p { margin: 5px 0 0; opacity: 0.9; font-size: 14px; }
        
        .btn { padding: 8px 15px; border-radius: 6px; text-decoration: none; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; font-size: 14px;}
        .btn-light { background: white; color: var(--primary); }
        .btn-light:hover { background: #f0f0f0; }
        .btn-export { background: #2e7d32; color: white; border: 1px solid #2e7d32; }
        .btn-export:hover { background: #1b5e20; }

        /* Filter Bar */
        .filter-bar { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: 13px; font-weight: 600; color: #555; }
        .form-control { padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; min-width: 150px; }
        
        /* Cards */
        .summary-cards { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .card { background: var(--card); padding: 20px; border-radius: 8px; flex: 1; min-width: 200px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 5px solid var(--primary); }
        .card h3 { margin: 0 0 10px; font-size: 16px; color: #666; }
        .card .number { font-size: 28px; font-weight: bold; color: var(--primary); }
        .card .sub-text { font-size: 12px; color: #888; margin-top: 5px; }

        /* Grid & Charts */
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .dashboard-grid { grid-template-columns: 1fr; } }
        .chart-box { background: var(--card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .chart-box h3 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 18px; }
        
        .bar-chart-row { margin-bottom: 12px; display: flex; align-items: center; }
        .bar-label { width: 150px; font-size: 13px; text-align: right; padding-right: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-track { flex-grow: 1; background: #eee; height: 20px; border-radius: 10px; overflow: hidden; }
        .bar-fill { height: 100%; background: var(--primary); width: 0%; transition: width 0.5s ease; }
        .bar-value { width: 40px; padding-left: 10px; font-size: 13px; font-weight: bold; }

        /* Table */
        .table-container { background: var(--card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px; }
        th { background: #eee; color: #333; font-weight: 600; }
        tr:hover { background: #fafafa; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; background: #eee; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="header-title">
            <h1>รายงานการใช้งานอุปกรณ์</h1>
            <p>ระบบวิเคราะห์และรายงานสรุปผล</p>
        </div>
        <div>
            <a href="Full_repair.php" class="btn btn-light">รายการทั้งหมด →</a>
        </div>
    </header>

    <!-- Filter Bar -->
    <form method="GET" action="" class="filter-bar">
        <div class="form-group">
            <label>ค้นหา (หัวข้อ/อุปกรณ์)</label>
            <input type="text" name="search" class="form-control" placeholder="พิมพ์คำค้นหา..." value="<?php echo htmlspecialchars($searchKeyword); ?>">
        </div>
        <div class="form-group">
            <label>อุปกรณ์</label>
            <select name="device" class="form-control">
                <option value="">-- ทั้งหมด --</option>
                <?php foreach ($allDevices as $dev): ?>
                    <option value="<?php echo htmlspecialchars($dev); ?>" <?php echo ($filterDevice == $dev) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dev); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>ประเภท</label>
            <select name="type" class="form-control">
                <option value="">-- ทั้งหมด --</option>
                <?php foreach ($allTypes as $typ): ?>
                    <option value="<?php echo htmlspecialchars($typ); ?>" <?php echo ($filterType == $typ) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($typ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-light" style="background:#e0e0e0; color:#333;">ตกลง</button>
            <a href="?" class="btn" style="color:red; text-decoration:underline; font-size:13px; padding-left:10px;">ล้างค่า</a>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="card">
            <h3>รายการที่พบ (ตามการค้นหา)</h3>
            <div class="number"><?php echo number_format($stats['total_records']); ?></div>
            <div class="sub-text">รายการ</div>
        </div>
        <div class="card">
            <h3>เวลารวม</h3>
            <div class="number"><?php echo number_format($stats['total_minutes']); ?></div>
            <div class="sub-text">นาที</div>
        </div>
        <div class="card" style="border-left-color: #ff9800;">
            <h3>เวลาเฉลี่ย</h3>
            <div class="number"><?php echo number_format($avgTime, 1); ?></div>
            <div class="sub-text">นาที / รายการ</div>
        </div>
        <div class="card" style="border-left-color: #4caf50;">
            <h3>ดาวน์โหลดข้อมูล</h3>
            <div style="margin-top: 10px;">
                <!-- ส่ง Parameter filter ปัจจุบันไปกับ Link Export ด้วย -->
                <a href="?export=csv&device=<?php echo $filterDevice;?>&type=<?php echo $filterType;?>&search=<?php echo $searchKeyword;?>" class="btn btn-export">
                    ⬇ Export Excel/CSV
                </a>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="dashboard-grid">
        <div class="chart-box">
            <h3>สถิติตามอุปกรณ์</h3>
            <?php 
            arsort($stats['by_device']);
            if (empty($stats['by_device'])) echo "<p style='color:#999;font-size:13px;'>ไม่มีข้อมูล</p>";
            foreach ($stats['by_device'] as $device => $count) : 
                $percent = calculatePercent($count, $stats['total_records']);
            ?>
            <div class="bar-chart-row">
                <div class="bar-label" title="<?php echo htmlspecialchars($device); ?>"><?php echo htmlspecialchars($device); ?></div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?php echo $percent; ?>%;"></div>
                </div>
                <div class="bar-value"><?php echo $count; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="chart-box">
            <h3>สถิติตามประเภท</h3>
            <?php 
            arsort($stats['by_type']);
            if (empty($stats['by_type'])) echo "<p style='color:#999;font-size:13px;'>ไม่มีข้อมูล</p>";
            foreach ($stats['by_type'] as $type => $count) : 
                $percent = calculatePercent($count, $stats['total_records']);
            ?>
            <div class="bar-chart-row">
                <div class="bar-label" title="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?php echo $percent; ?>%; background-color: #9c27b0;"></div>
                </div>
                <div class="bar-value"><?php echo $count; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Data Table -->
    <div class="table-container">
        <h3>รายการละเอียด (Top 50 รายการล่าสุด)</h3>
        <table>
            <thead>
                <tr>
                    <th>วัน/เดือน/ปี</th>
                    <th>วันที่แก้ไข</th>
                    <th>หน่วยงาน</th>
                    <th>ชนิดการซ่อม</th>
                    <th>สาเหตุ/อาการ</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // ฟังก์ชันแปลงวว/เดือน/ปี เป็นตัวเลขเพื่อเปรียบเทียบ
                $parseDate = function($dateStr) {
                    if (empty($dateStr)) return 0;
                    $parts = explode("/", trim($dateStr));
                    if (count($parts) != 3) return 0;
                    
                    $day = intval($parts[0]);
                    $month = intval($parts[1]);
                    $year = intval($parts[2]);
                    
                    // แปลง 259 เป็น 2025, 260 เป็น 2026 เป็นต้น (กรณี Buddhist calendar)
                    if ($year > 100 && $year < 200) {
                        $year += 1900;
                    }
                    
                    return mktime(0, 0, 0, $month, $day, $year);
                };
                
                // แสดงแค่ 50 รายการล่าสุดเพื่อไม่ให้หน้าเว็บช้า
                // เรียงลำดับตามวันที่ล่าสุดอยู่ด้านบน
                usort($stats['filtered_logs'], function($a, $b) use ($parseDate) {
                    $dateA = $parseDate($a['date']);
                    $dateB = $parseDate($b['date']);
                    return $dateB <=> $dateA; // ล่าสุดอยู่ด้านบน
                });
                
                $displayLogs = array_slice($stats['filtered_logs'], 0, 50); 
                
                if (empty($displayLogs)) {
                    echo "<tr><td colspan='5' style='text-align:center; padding:20px;'>ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td></tr>";
                }

                foreach ($displayLogs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['date']); ?></td>
                    <td><?php echo htmlspecialchars($log['topic']); ?></td>
                    <td><span class="badge"><?php echo htmlspecialchars($log['type']); ?></span></td>
                    <td><?php echo htmlspecialchars($log['device']); ?></td>
                    <td><?php echo htmlspecialchars($log['duration_str']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>