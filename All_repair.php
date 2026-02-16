<?php
// ดึงข้อมูลจาก Google Sheets
$csvUrl = "https://docs.google.com/spreadsheets/d/e/2PACX-1vTSjf_ppDd3pJ3KrHeP99nI0J-l8jne8GyawbZfj42M5DP8xdh4dg7ifxeW4iirvQbIM99DhNDXaDYA/pub?gid=1095863488&single=true&output=csv";

$rows = [];
if (($handle = fopen($csvUrl, "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $rows[] = $data;
    }
    fclose($handle);
}

// แยก Header
$headers = array_shift($rows);

// เรียงข้อมูลตามวันที่ล่าสุดขึ้นมาก่อน (column 0)
usort($rows, function($a, $b) {
    $dateA = strtotime($a[0] ?? '');
    $dateB = strtotime($b[0] ?? '');
    $dateA = $dateA === false ? 0 : $dateA;
    $dateB = $dateB === false ? 0 : $dateB;
    return $dateB <=> $dateA; // ล่าสุดก่อน
});

// หากจำนวนแถวมีข้อมูล ให้สร้าง summary
$totalRows = count($rows);
$statsMonth = array_count_values(array_map(fn($r) => $r[0] ?? '', $rows));
$statsDept = array_count_values(array_map(fn($r) => $r[1] ?? '', $rows));
$statsType = array_count_values(array_map(fn($r) => $r[2] ?? '', $rows));

// ข้อมูลสำหรับกราฟ - แสดง top 8 items จาก column ที่ 2 (ชนิดการซ่อม)
$chartData = array_slice($statsType, 0, 8, true);
$chartLabels = array_keys($chartData);
$chartValues = array_values($chartData);

// จัดกลุ่มข้อมูลตามหน่วยงาน (column 1)
$groupedByDept = [];
foreach ($rows as $row) {
    $dept = $row[1] ?? 'ไม่ระบุ';
    if (!isset($groupedByDept[$dept])) {
        $groupedByDept[$dept] = [];
    }
    $groupedByDept[$dept][] = $row;
}
ksort($groupedByDept); // เรียงตัวอักษรตามหน่วยงาน
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ระบบประวัติการซ่อม - Google Sheets</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root{--bg:#f3f6f9;--card:#fff;--border:#e6eef3;--primary:#0f172a;--accent:#1e40af;--muted:#6b7280;--danger:#b91c1c;}
        html,body{height:100%;}
        body{font-family:'Sarabun',system-ui,-apple-system,'Segoe UI',Roboto,Arial;background:var(--bg);color:var(--primary);margin:0;padding:16px;-webkit-font-smoothing:antialiased;}
        .container{width:100%;max-width:100%;margin:0 auto;}
        .header{background:var(--card);border:1px solid var(--border);border-radius:0;padding:28px;margin-bottom:20px;box-shadow:0 2px 8px rgba(16,24,40,0.06);}
        .header h1{margin:0;font-size:22px;color:var(--accent);font-weight:700;}
        .header p{margin:4px 0 0;color:var(--muted);font-size:13px;}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px;}
        .stat-card{background:var(--card);border:1px solid var(--border);border-radius:0;padding:16px;box-shadow:0 2px 4px rgba(0,0,0,0.04);}
        .stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;}
        .stat-value{font-size:28px;font-weight:700;color:var(--primary);margin-top:6px;}
        .chart-card,.table-card{background:var(--card);border:1px solid var(--border);border-radius:0;padding:20px;box-shadow:0 2px 8px rgba(16,24,40,0.06);}
        .chart-card{margin-bottom:20px;}
        .chart-card h3{margin:0 0 16px;font-size:16px;color:var(--primary);}
        .table-card h3{margin:0 0 16px;font-size:16px;color:var(--primary);}
        .table-wrap{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;font-size:14px;}
        thead th{background:#fbfdff;padding:12px 14px;text-align:left;color:var(--muted);text-transform:uppercase;letter-spacing:0.02em;border-bottom:1px solid var(--border);font-weight:600;}
        tbody td{padding:12px 14px;border-bottom:1px solid var(--border);}
        tbody tr:nth-child(odd){background:#fff;}
        tbody tr:nth-child(even){background:#fafbfc;}
        tbody tr:hover{background:#f0f4f8;}
        .badge{display:inline-block;padding:4px 8px;border-radius:0;background:#eef2ff;color:#1e40af;font-weight:600;font-size:12px;}
        .btn-back{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:0;border:1px solid var(--border);background:transparent;color:var(--primary);text-decoration:none;font-weight:600;cursor:pointer;transition:all 0.2s;}
        .btn-back:hover{background:var(--accent);color:#fff;border-color:var(--accent);}
        @media print{body{padding:0;}.header{margin-bottom:10px;}.btn-back{display:none;}table{font-size:12px;}th,td{padding:8px;}}
    </style>
</head>
<body>

<div class="container">
    <!-- Header with Back Button -->
    <div class="header" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <h1>📋 รายละเอียดข้อมูลการซ่อมทั้งหมด</h1>
            <p>Dashboard จาก Google Sheets — อัปเดตแบบ Real-time</p>
        </div>
        <a href="index.php" class="btn-back">← กลับไป</a>
    </div>

    <!-- Main Table -->
    <div class="table-card">
        <h3>รายละเอียดข้อมูลทั้งหมด</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>วัน/เดือน/ปี</th>
                        <th>หน่วยงาน</th>
                        <th>ชนิดการซ่อม</th>
                        <th>สาเหตุ</th>
                        <?php foreach (array_slice($headers, 4) as $h): ?>
                            <th><?php echo htmlspecialchars($h); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $rowNum = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><span class="badge"><?php echo $rowNum++; ?></span></td>
                        <td><?php echo htmlspecialchars($row[0] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row[1] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row[2] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row[3] ?? ''); ?></td>
                        <?php foreach (array_slice($row, 4) as $cell): ?>
                            <td><?php echo htmlspecialchars($cell ?? ''); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p style="margin-top:20px;color:var(--muted);font-size:12px;text-align:center;">
        Generated: <?php echo date('Y-m-d H:i:s'); ?> | 
        <a href="javascript:window.print()" style="color:var(--accent);text-decoration:none;">พิมพ์</a>
    </p>
</div>

<script>
// Chart.js สำหรับแสดงสถิติชนิดการซ่อม
const ctx = document.getElementById('repairChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chartLabels); ?>,
        datasets: [{
            label: 'จำนวนครั้งการซ่อม',
            data: <?php echo json_encode($chartValues); ?>,
            backgroundColor: 'rgba(30, 64, 175, 0.6)',
            borderColor: 'rgba(30, 64, 175, 1)',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
</script>

</body>
</html>