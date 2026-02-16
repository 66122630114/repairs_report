<?php
// 1. ตั้งค่า URL ของ Google Sheets (รูปแบบ CSV)
 $csvUrl = "https://docs.google.com/spreadsheets/d/e/2PACX-1vTSjf_ppDd3pJ3KrHeP99nI0J-l8jne8GyawbZfj42M5DP8xdh4dg7ifxeW4iirvQbIM99DhNDXaDYA/pub?gid=1095863488&single=true&output=csv";

// 2. ฟังก์ชันสำหรับดึงข้อมูล CSV
function getDataFromCsv($url) {
    $data = file_get_contents($url);
    // แยกข้อมูลทุกบรรทัด
    $rows = explode("\n", $data);
    $csvData = [];
    
    foreach ($rows as $row) {
        // แปลงข้อมูลแต่ละบรรทัดเป็น Array โดยจัดการเครื่องหมาย comma ในเนื้อหา
        $csvData[] = str_getcsv($row);
    }
    return $csvData;
}

// 3. ดึงข้อมูลมาเก็บไว้ในตัวแปร
 $allData = getDataFromCsv($csvUrl);

// ตัดหัวตาราง (Header) ออกมา
 $headers = array_shift($allData); 

// ตัวแปรสำหรับเก็บสถิติ
 $stats = [
    'total_records' => 0,
    'total_minutes' => 0,
    'by_device' => [],
    'by_type' => [],
    'recent_logs' => []
];

// 4. วนลูปประมวลผลข้อมูล
foreach ($allData as $row) {
    // ข้ามบรรทัดว่าง
    if (empty(array_filter($row))) continue;

    // สร้าง associative array ให้ง่ายต่อการเรียกใช้ (Map ชื่อคอลัมน์กับข้อมูล)
    // สมมติ Index ตามลำดับในรูป: วันที่, หัวข้อ, ประเภท, อุปกรณ์, เวลา
    // หมายเหตุ: หากคอลัมน์เปลี่ยน อาจต้องปรับ Index นี้ (0, 1, 2, 3, 4)
    $record = [
        'date' => isset($row[0]) ? $row[0] : '-',
        'topic' => isset($row[1]) ? $row[1] : '-',
        'type' => isset($row[2]) ? $row[2] : '-',
        'device' => isset($row[3]) ? $row[3] : '-',
        'duration_str' => isset($row[4]) ? $row[4] : '0 นาที'
    ];

    // แปลงเวลาเป็นนาที (ดึงตัวเลขออกมาจาก string เช่น "15 นาที")
    $minutes = 0;
    if (preg_match('/(\d+)/', $record['duration_str'], $matches)) {
        $minutes = intval($matches[1]);
    }

    // บันทึกข้อมูลเพื่อนำไปแสดง
    $stats['recent_logs'][] = $record;
    $stats['total_records']++;
    $stats['total_minutes'] += $minutes;

    // นับจำนวนตามอุปกรณ์
    $dev = $record['device'];
    if (!isset($stats['by_device'][$dev])) {
        $stats['by_device'][$dev] = 0;
    }
    $stats['by_device'][$dev]++;

    // นับจำนวนตามประเภท
    $typ = $record['type'];
    if (!isset($stats['by_type'][$typ])) {
        $stats['by_type'][$typ] = 0;
    }
    $stats['by_type'][$typ]++;
}

// เรียงลำดับข้อมูลตามวันที่ล่าสุดขึ้นมาก่อน
usort($stats['recent_logs'], function($a, $b) {
    // Parse d/m/Y format manually (เช่น "5/2/2026" หรือ "5/2/2026, 14:55:12")
    $parseDate = function($dateStr) {
        // แยกเฉพาะส่วนวันที่ (ตัดส่วนเวลาออก)
        $datePart = explode(',', $dateStr)[0];
        $parts = explode('/', trim($datePart));
        if (count($parts) == 3) {
            $d = intval($parts[0]);
            $m = intval($parts[1]);
            $y = intval($parts[2]);
            return mktime(0, 0, 0, $m, $d, $y);
        }
        return 0;
    };
    
    $tsA = $parseDate($a['date']);
    $tsB = $parseDate($b['date']);
    
    return $tsB <=> $tsA; // ล่าสุดก่อน
});

// ฟังก์ชันช่วยคิดเปอร์เซ็นต์สำหรับกราฟ
function calculatePercent($value, $total) {
    if ($total == 0) return 0;
    return round(($value / $total) * 100);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการใช้งานอุปกรณ์ (Device Usage Dashboard)</title>
    <!-- ใช้ Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #673ab7; /* สีม่วงตามรูปภาพ */
            --bg-color: #f4f6f9;
            --card-bg: #ffffff;
            --text-color: #333;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        header h1 { margin: 0; font-size: 24px; }
        header p { margin: 5px 0 0; opacity: 0.9; }

        /* Summary Cards */
        .summary-cards {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            flex: 1;
            min-width: 200px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 5px solid var(--primary-color);
        }

        .card h3 { margin: 0 0 10px; font-size: 16px; color: #666; }
        .card .number { font-size: 28px; font-weight: bold; color: var(--primary-color); }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        .chart-box {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .chart-box h3 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }

        /* Simple Bar Chart Styles */
        .bar-chart-row {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }
        .bar-label { width: 150px; font-size: 14px; text-align: right; padding-right: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-track { flex-grow: 1; background: #eee; height: 20px; border-radius: 10px; overflow: hidden; }
        .bar-fill { height: 100%; background: var(--primary-color); width: 0%; transition: width 1s ease-in-out; }
        .bar-value { width: 50px; padding-left: 10px; font-size: 14px; font-weight: bold; }

        /* Data Table */
        .table-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
        }

        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }

        /* Back Button */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border: 1px solid rgba(255,255,255,0.3);
            background: transparent;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.1); border-color: #fff; }
    </style>
</head>
<body>

<div class="container">
    <header style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <h1>รายงานการใช้งาน (Usage Report)</h1>
            <p>สรุปข้อมูลการใช้งานอุปกรณ์และการดำเนินการ</p>
        </div>
        <a href="index.php" class="btn-back">← กลับหน้าแดชบอร์ด</a>
    </header>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="card">
            <h3>รายการทั้งหมด</h3>
            <div class="number"><?php echo number_format($stats['total_records']); ?></div>
        </div>
        <div class="card">
            <h3>เวลารวมทั้งหมด (นาที)</h3>
            <div class="number"><?php echo number_format($stats['total_minutes']); ?></div>
        </div>
        <div class="card">
            <h3>จำนวนอุปกรณ์</h3>
            <div class="number"><?php echo count($stats['by_device']); ?></div>
        </div>
    </div>
</div>

</body>
</html>
<!-- Data Table -->
    <div class="table-container">
        <h3>รายการล่าสุด</h3>
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
                <?php foreach ($stats['recent_logs'] as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['date']); ?></td>
                    <td><?php echo htmlspecialchars($log['topic']); ?></td>
                    <td><?php echo htmlspecialchars($log['type']); ?></td>
                    <td><?php echo htmlspecialchars($log['device']); ?></td>
                    <td><?php echo htmlspecialchars($log['duration_str']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>