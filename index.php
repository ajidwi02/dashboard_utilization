<?php
// index.php - Versi Update (Auto Refresh Realtime 1 Menit)
require_once './config.php';

// --- 1. FILTER TANGGAL ---
// Default range 30 hari jika tidak ada input
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// --- 2. SQL QUERY ---
// Query ini akan dieksekusi ulang setiap kali halaman refresh otomatis
$sql = "SELECT 
            DATEOFSTART AS Tanggal,
            -- SHIFT PAGI
            SUM(CASE WHEN STR_TO_DATE(TIMEOFSTART, '%H:%i:%s') BETWEEN '07:00:00' AND '19:30:00' THEN CUTTIME ELSE 0 END) AS Cut_Pagi,
            SUM(CASE WHEN STR_TO_DATE(TIMEOFSTART, '%H:%i:%s') BETWEEN '07:00:00' AND '19:30:00' THEN JOBTIME ELSE 0 END) AS Job_Pagi,
            -- SHIFT MALAM
            SUM(CASE WHEN STR_TO_DATE(TIMEOFSTART, '%H:%i:%s') NOT BETWEEN '07:00:00' AND '19:30:00' THEN CUTTIME ELSE 0 END) AS Cut_Malam,
            SUM(CASE WHEN STR_TO_DATE(TIMEOFSTART, '%H:%i:%s') NOT BETWEEN '07:00:00' AND '19:30:00' THEN JOBTIME ELSE 0 END) AS Job_Malam
        FROM dlist 
        WHERE DATEOFSTART BETWEEN '$startDate' AND '$endDate'
        GROUP BY DATEOFSTART
        ORDER BY DATEOFSTART ASC";

$result = mysqli_query($conn, $sql);

// --- 3. PERSIAPAN DATA ---
$dataTanggal = [];
$dataAvailPagi = [];
$dataAvailMalam = [];
$dataJobPagi = [];
$dataJobMalam = [];
$tableData = [];

// Variabel Total Periode untuk Gauge
$totalCutPagi = 0; $totalJobPagi = 0;
$totalCutMalam = 0; $totalJobMalam = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Hitung Per Hari
        $availPagi = ($row['Job_Pagi'] > 0) ? ($row['Cut_Pagi'] / $row['Job_Pagi']) * 100 : 0;
        $availMalam = ($row['Job_Malam'] > 0) ? ($row['Cut_Malam'] / $row['Job_Malam']) * 100 : 0;

        // Array Chart Trend
        $dataTanggal[] = date('d M', strtotime($row['Tanggal']));
        $dataAvailPagi[] = round($availPagi, 2);
        $dataAvailMalam[] = round($availMalam, 2);
        $dataJobPagi[] = round($row['Job_Pagi'], 2);
        $dataJobMalam[] = round($row['Job_Malam'], 2);

        // Akumulasi Total Periode
        $totalCutPagi += $row['Cut_Pagi'];
        $totalJobPagi += $row['Job_Pagi'];
        $totalCutMalam += $row['Cut_Malam'];
        $totalJobMalam += $row['Job_Malam'];

        // Data Tabel
        $row['avail_pagi_calc'] = $availPagi;
        $row['avail_malam_calc'] = $availMalam;
        $tableData[] = $row;
    }
}

$tableDataDesc = array_reverse($tableData);

// Hitung Rata-rata Total Periode untuk Gauge Jarum
$gaugePagiVal = ($totalJobPagi > 0) ? ($totalCutPagi / $totalJobPagi) * 100 : 0;
$gaugeMalamVal = ($totalJobMalam > 0) ? ($totalCutMalam / $totalJobMalam) * 100 : 0;

$gaugePagiVal = round($gaugePagiVal, 2);
$gaugeMalamVal = round($gaugeMalamVal, 2);

// JSON Encode
$jsonDates = json_encode($dataTanggal);
$jsonAvailPagi = json_encode($dataAvailPagi);
$jsonAvailMalam = json_encode($dataAvailMalam);
$jsonJobPagi = json_encode($dataJobPagi);
$jsonJobMalam = json_encode($dataJobMalam);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard OEE - Realtime Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script> 
    <script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script> 
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card-header { background-color: #fff; border-bottom: 1px solid #eee; font-weight: 700; color: #333; padding: 15px 20px; border-radius: 12px 12px 0 0 !important; }
        .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd, #0a58ca); }
        .gauge-container { width: 100%; height: 270px; margin-top: 45px;}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-gradient-primary mb-4 shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="#"><i class="fas fa-tachometer-alt me-2"></i>Bullmer OEE Dashboard</a>
    
    <span class="navbar-text text-white small">
        <i class="fas fa-clock me-1"></i> Refresh in: <span id="countdown" class="fw-bold text-warning" style="font-size:1.1em;">60</span>s
    </span>
  </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted fw-bold">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted fw-bold">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fas fa-sync-alt me-2"></i>Update Data Manual</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="card h-80">
                <div class="card-header text-center text-primary"><i class="fas fa-sun me-2"></i>Availability Shift Pagi</div>
                <div class="card-body p-0">
                    <div id="gaugePagi" class="gauge-container"></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-80">
                <div class="card-header text-center text-warning"><i class="fas fa-moon me-2"></i>Availability Shift Malam</div>
                <div class="card-body p-0">
                    <div id="gaugeMalam" class="gauge-container"></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-80">
                <div class="card-header text-center text-primary"><i class="fas fa-sun me-2"></i>Performance Shift Pagi</div>
                <div class="card-body p-0">
                    <div id="gaugeMalam" class="gauge-container"></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-80">
                <div class="card-header text-center text-warning"><i class="fas fa-moon me-2"></i>Performance Shift Malam</div>
                <div class="card-body p-0">
                    <div id="gaugeMalam" class="gauge-container"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12 mb-3">
            <div class="card h-100">
                <div class="card-header">Trend Performa Harian</div>
                <div class="card-body">
                    <div id="trendChart"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-12 mb-3">
            <div class="card h-100">
                <div class="card-header">Total Beban Kerja (Job Time)</div>
                <div class="card-body">
                    <div id="barChart"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Detail Harian</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Tanggal</th>
                            <th class="text-center">Avail Pagi</th>
                            <th class="text-center">Avail Malam</th>
                            <th class="text-end">Job Pagi (m)</th>
                            <th class="text-end pe-4">Job Malam (m)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableDataDesc)): ?>
                            <tr><td colspan="5" class="text-center py-4">Tidak ada data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tableDataDesc as $row): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?php echo date('d M Y', strtotime($row['Tanggal'])); ?></td>
                                <td class="text-center fw-bold <?php echo ($row['avail_pagi_calc']>=50)?'text-success':'text-danger'; ?>">
                                    <?php echo number_format($row['avail_pagi_calc'], 1); ?>%
                                </td>
                                <td class="text-center fw-bold <?php echo ($row['avail_malam_calc']>=50)?'text-success':'text-danger'; ?>">
                                    <?php echo number_format($row['avail_malam_calc'], 1); ?>%
                                </td>
                                <td class="text-end"><?php echo number_format($row['Job_Pagi'], 0); ?></td>
                                <td class="text-end pe-4"><?php echo number_format($row['Job_Malam'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // --- 1. CONFIG GAUGE ECHARTS ---
    function initGauge(domId, value, titleColor) {
        var chartDom = document.getElementById(domId);
        var myChart = echarts.init(chartDom);
        var option;

        option = {
            series: [
                {
                    type: 'gauge',
                    startAngle: 180,
                    endAngle: 0,
                    min: 0,
                    max: 100,
                    splitNumber: 10,
                    radius: '90%',
                    itemStyle: { color: titleColor },
                    progress: { show: true, width: 18 },
                    pointer: { show: true, length: '60%', width: 6, itemStyle: { color: 'auto' } },
                    axisLine: {
                        lineStyle: {
                            width: 18,
                            color: [[0.4, '#FF6E76'], [0.7, '#FDDD60'], [1, '#7CFFB2']]
                        }
                    },
                    axisTick: { distance: -18, length: 8, lineStyle: { color: '#fff', width: 2 } },
                    splitLine: { distance: -18, length: 30, lineStyle: { color: '#fff', width: 4 } },
                    axisLabel: { color: 'auto', distance: 25, fontSize: 14 },
                    detail: {
                        valueAnimation: true,
                        formatter: '{value}%',
                        color: 'auto',
                        fontSize: 40,
                        fontWeight: 'bolder',
                        offsetCenter: [0, '30%']
                    },
                    data: [{ value: value }]
                }
            ]
        };
        option && myChart.setOption(option);
        window.addEventListener('resize', function() { myChart.resize(); });
    }

    // Inisialisasi Gauge
    initGauge('gaugePagi', <?php echo $gaugePagiVal; ?>, '#5470C6');
    initGauge('gaugeMalam', <?php echo $gaugeMalamVal; ?>, '#EE6666');


    // --- 2. CONFIG APEXCHARTS ---
    var optionsTrend = {
        series: [
            { name: "Shift Pagi", data: <?php echo $jsonAvailPagi; ?> },
            { name: "Shift Malam", data: <?php echo $jsonAvailMalam; ?> }
        ],
        chart: { height: 300, type: 'line', toolbar: { show: false } },
        colors: ['#0d6efd', '#ffc107'],
        stroke: { width: [3, 3], curve: 'smooth' },
        xaxis: { categories: <?php echo $jsonDates; ?> },
        yaxis: { max: 100 },
        legend: { position: 'top' }
    };
    new ApexCharts(document.querySelector("#trendChart"), optionsTrend).render();

    var optionsBar = {
        series: [
            { name: 'Job Pagi', data: <?php echo $jsonJobPagi; ?> },
            { name: 'Job Malam', data: <?php echo $jsonJobMalam; ?> }
        ],
        chart: { type: 'bar', height: 300, stacked: true, toolbar: { show: false } },
        colors: ['#0d6efd', '#ffc107'],
        xaxis: { categories: <?php echo $jsonDates; ?> },
        legend: { position: 'top' }
    };
    new ApexCharts(document.querySelector("#barChart"), optionsBar).render();

    // --- 3. FITUR AUTO REFRESH (REALTIME) ---
    var timeLeft = 60; // Waktu hitung mundur dalam detik
    var countdownElement = document.getElementById('countdown');

    var refreshTimer = setInterval(function() {
        timeLeft--;
        if (countdownElement) {
            countdownElement.innerText = timeLeft;
        }

        if (timeLeft <= 0) {
            clearInterval(refreshTimer);
            // Reload halaman untuk mengambil data database terbaru
            // window.location.reload() akan mempertahankan parameter filter tanggal di URL
            window.location.reload(); 
        }
    }, 1000); // Jalan setiap 1000ms (1 detik)

</script>

</body>
</html>