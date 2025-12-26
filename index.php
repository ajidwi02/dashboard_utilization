<?php
require_once 'config.php';

// Default: Take the last 30 days of data if there is no input.
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Take all raw data in the date range.
// Availability = (CUTTIME / JOBTIME) * 100
$sql = "SELECT 
            DATEOFSTART, 
            TIMEOFSTART, 
            MARKER, 
            CUTTIME, 
            JOBTIME,
            MACHINEID
        FROM dlist 
        WHERE DATEOFSTART BETWEEN '$startDate' AND '$endDate'
        ORDER BY DATEOFSTART DESC, TIMEOFSTART DESC";

$result = mysqli_query($conn, $sql);

// Daily grouping
$dailyData = [];
$totalCutTimePeriod = 0;
$totalJobTimePeriod = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $date = $row['DATEOFSTART'];
        
        // Initialize the array for that date if it does not already exist
        if (!isset($dailyData[$date])) {
            $dailyData[$date] = [
                'summary_cut' => 0,
                'summary_job' => 0,
                'details' => []
            ];
        }

        // Add to daily summary
        $dailyData[$date]['summary_cut'] += $row['CUTTIME'];
        $dailyData[$date]['summary_job'] += $row['JOBTIME'];

        // Add to total period (for Gauge Chart)
        $totalCutTimePeriod += $row['CUTTIME'];
        $totalJobTimePeriod += $row['JOBTIME'];

        // Calculate availability per job (for row details)
        $jobAvail = ($row['JOBTIME'] > 0) ? ($row['CUTTIME'] / $row['JOBTIME']) * 100 : 0;

        // Simpan detail job
        $dailyData[$date]['details'][] = [
            'time' => $row['TIMEOFSTART'],
            'marker' => $row['MARKER'],
            'cut' => $row['CUTTIME'],
            'job' => $row['JOBTIME'],
            'avail' => $jobAvail,
            'machine' => $row['MACHINEID']
        ];
    }
}

// Calculate Total Period Availability
$overallAvail = ($totalJobTimePeriod > 0) ? ($totalCutTimePeriod / $totalJobTimePeriod) * 100 : 0;
$overallAvail = round($overallAvail, 2);

// Prepare data for Chart (Sorted Ascending by Date for Graph)
$chartDates = [];
$chartAvail = [];
$chartCut = [];
$chartJob = [];

// Sort the dailyData array in ascending order for the chart (because of the DESC query).
$sortedDailyData = $dailyData;
ksort($sortedDailyData); 

foreach ($sortedDailyData as $date => $data) {
    $dayAvail = ($data['summary_job'] > 0) ? ($data['summary_cut'] / $data['summary_job']) * 100 : 0;
    
    $chartDates[] = $date;
    $chartAvail[] = round($dayAvail, 2);
    $chartCut[]   = round($data['summary_cut'], 2);
    $chartJob[]   = round($data['summary_job'], 2);
}

// Convert to JSON for JavaScript
$jsonDates = json_encode($chartDates);
$jsonAvail = json_encode($chartAvail);
$jsonCut   = json_encode($chartCut);
$jsonJob   = json_encode($chartJob);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard OEE - Availability</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <style>
        body { background-color: #f4f6f9; }
        .card { border: none; box-shadow: 0 0 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-header { background-color: #fff; border-bottom: 1px solid #eee; font-weight: bold; }
        .detail-row { background-color: #f9f9f9; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; }
        .expand-btn { cursor: pointer; color: #0d6efd; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="#"><i class="fas fa-chart-line me-2"></i>Bullmer IoT Dashboard</a>
  </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter Data</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-header">Total Availability</div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <div id="gaugeChart"></div>
                </div>
                <!-- <div class="card-footer text-center text-muted">
                    Rumus: (Σ CutTime / Σ JobTime) * 100
                </div> -->
            </div>
        </div>

        <div class="col-md-8 mb-3">
            <div class="card h-100">
                <div class="card-header">Trend Availability Harian (%)</div>
                <div class="card-body">
                    <div id="trendChart"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">Perbandingan Total Cut Time vs Job Time (Per Hari)</div>
                <div class="card-body">
                    <div id="barChart"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Detail Data Harian (Klik <i class="fas fa-plus-circle"></i> untuk expand)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">Detail</th>
                            <th>Tanggal</th>
                            <th class="text-end">Total Cut Time</th>
                            <th class="text-end">Total Job Time</th>
                            <th class="text-center">Availability (%)</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dailyData)): ?>
                            <tr><td colspan="6" class="text-center">Tidak ada data pada rentang tanggal ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dailyData as $date => $data): 
                                $dayAvail = ($data['summary_job'] > 0) ? ($data['summary_cut'] / $data['summary_job']) * 100 : 0;
                                $rowId = 'row_' . str_replace('-', '', $date);
                                
                                // Tentukan warna badge berdasarkan performa
                                $badgeClass = $dayAvail >= 50 ? 'bg-success' : ($dayAvail >= 30 ? 'bg-warning' : 'bg-danger');
                            ?>
                            <tr>
                                <td class="text-center">
                                    <i class="fas fa-plus-circle expand-btn" onclick="toggleRow('<?php echo $rowId; ?>', this)"></i>
                                </td>
                                <td class="fw-bold"><?php echo date('d F Y', strtotime($date)); ?></td>
                                <td class="text-end"><?php echo number_format($data['summary_cut'], 2); ?></td>
                                <td class="text-end"><?php echo number_format($data['summary_job'], 2); ?></td>
                                <td class="text-center fw-bold text-primary"><?php echo number_format($dayAvail, 2); ?>%</td>
                                <td class="text-center">
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo $dayAvail >= 50 ? 'Good' : 'Low'; ?>
                                    </span>
                                </td>
                            </tr>
                            
                            <tr id="<?php echo $rowId; ?>" style="display: none;" class="detail-row">
                                <td colspan="6">
                                    <div class="p-3">
                                        <h6><i class="fas fa-list me-2"></i>Daftar Job pada <?php echo $date; ?>:</h6>
                                        <table class="table table-sm table-striped bg-white">
                                            <thead>
                                                <tr>
                                                    <th>Jam Mulai</th>
                                                    <th>Machine ID</th>
                                                    <th>Nama Marker / File</th>
                                                    <th class="text-end">Cut Time</th>
                                                    <th class="text-end">Job Time</th>
                                                    <th class="text-center">Avail %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($data['details'] as $job): ?>
                                                <tr>
                                                    <td><?php echo $job['time']; ?></td>
                                                    <td><?php echo $job['machine']; ?></td>
                                                    <td><?php echo $job['marker']; ?></td>
                                                    <td class="text-end"><?php echo $job['cut']; ?></td>
                                                    <td class="text-end"><?php echo $job['job']; ?></td>
                                                    <td class="text-center"><?php echo number_format($job['avail'], 2); ?>%</td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
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
    // Fungsi Expand/Collapse Row
    function toggleRow(rowId, btnElement) {
        var row = document.getElementById(rowId);
        if (row.style.display === "none") {
            row.style.display = "table-row";
            btnElement.classList.remove("fa-plus-circle");
            btnElement.classList.add("fa-minus-circle");
            btnElement.style.color = "#dc3545"; // Merah saat open
        } else {
            row.style.display = "none";
            btnElement.classList.remove("fa-minus-circle");
            btnElement.classList.add("fa-plus-circle");
            btnElement.style.color = "#0d6efd"; // Biru saat close
        }
    }

    // --- CHART CONFIGURATION ---

    // 1. Gauge Chart (Overall Availability)
    var optionsGauge = {
        series: [<?php echo $overallAvail; ?>],
        chart: {
            height: 300,
            type: 'radialBar',
        },
        plotOptions: {
            radialBar: {
                startAngle: -135,
                endAngle: 135,
                hollow: {
                    margin: 15,
                    size: '60%',
                },
                dataLabels: {
                    showOn: 'always',
                    name: {
                        offsetY: -10,
                        show: true,
                        color: '#888',
                        fontSize: '13px'
                    },
                    value: {
                        color: '#111',
                        fontSize: '30px',
                        show: true,
                        formatter: function (val) {
                            return val + "%";
                        }
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'dark',
                type: 'horizontal',
                shadeIntensity: 0.5,
                gradientToColors: ['#ABE5A1'],
                inverseColors: true,
                opacityFrom: 1,
                opacityTo: 1,
                stops: [0, 100]
            }
        },
        stroke: {
            lineCap: 'round'
        },
        labels: ['Availability'],
        colors: ['#20E647']
    };
    var chartGauge = new ApexCharts(document.querySelector("#gaugeChart"), optionsGauge);
    chartGauge.render();

    // 2. Line/Area Chart (Trend Harian)
    var optionsTrend = {
        series: [{
            name: "Availability (%)",
            data: <?php echo $jsonAvail; ?>
        }],
        chart: {
            height: 300,
            type: 'area',
            zoom: { enabled: false }
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth' },
        xaxis: {
            categories: <?php echo $jsonDates; ?>
        },
        tooltip: {
            y: {
                formatter: function (val) {
                    return val + "%"
                }
            }
        },
        colors: ['#0d6efd']
    };
    var chartTrend = new ApexCharts(document.querySelector("#trendChart"), optionsTrend);
    chartTrend.render();

    // 3. Bar Chart (Total Cut vs Total Job)
    var optionsBar = {
        series: [{
            name: 'Total Cut Time',
            data: <?php echo $jsonCut; ?>
        }, {
            name: 'Total Job Time',
            data: <?php echo $jsonJob; ?>
        }],
        chart: {
            type: 'bar',
            height: 350
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
                endingShape: 'rounded'
            },
        },
        dataLabels: { enabled: false },
        stroke: {
            show: true,
            width: 2,
            colors: ['transparent']
        },
        xaxis: {
            categories: <?php echo $jsonDates; ?>,
        },
        fill: { opacity: 1 },
        colors: ['#198754', '#6c757d'], // Hijau (Cut), Abu (Job)
        tooltip: {
            y: {
                formatter: function (val) {
                    return val
                }
            }
        }
    };
    var chartBar = new ApexCharts(document.querySelector("#barChart"), optionsBar);
    chartBar.render();

</script>

</body>
</html>