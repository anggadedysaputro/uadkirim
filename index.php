<?php

/**
 * Single-file PHP: Form + Debug mode + JSON mode + Excel upload (Box/Spout)
 * Features:
 * - Download Excel template (?download_template=1)
 * - Read XLSX/ODS/CSV via box/spout
 * - Fallback manual input (debug mode)
 * - Optional JSON source (karyawan.json)
 * - Send message via Wablas API
 * - Save sent results (Excel mode) into sent.json
 * - View sent log (?view_sent=1)
 *
 * Requirements:
 *   composer require box/spout
 */

require __DIR__ . '/vendor/autoload.php';

use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;

// ==================== Helpers ==================== //
function formatIndoPhone($number)
{
    $number = preg_replace('/[^0-9]/', '', (string)$number);
    if ($number === '') return $number;
    if (substr($number, 0, 1) === '0') {
        return '62' . substr($number, 1);
    }
    if (substr($number, 0, 2) === '62') {
        return $number;
    }
    return $number;
}

function wablas($message, $phone = '')
{
    $WABLAS_TOKEN = '3NCX72aOde4meolTukZte2PjwY1I8O7pYz3QS6AP56JlKBuMw5fIodG2jlZoTywZ.3EmBGgzH'; // ganti dengan tokenmu

    $curl = curl_init();
    $payload = [
        'phone'   => $phone,
        'message' => $message,
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://pati.wablas.com/api/send-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $WABLAS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $rs = curl_exec($curl);
    if ($rs === false) {
        $err = curl_error($curl);
        curl_close($curl);
        return [
            'ok'     => false,
            'error'  => 'cURL Error: ' . $err,
        ];
    }
    curl_close($curl);

    $json = json_decode($rs, true);
    return [
        'ok'    => is_array($json),
        'error' => is_array($json) ? null : $rs,
        'json'  => $json,
    ];
}

function sanitize_link($link)
{
    $link = trim((string)$link);
    if (!preg_match('/^https?:\/\//i', $link)) {
        $link = 'https://' . ltrim($link, '/');
    }
    return $link;
}

// ==================== Download Template ==================== //
if (isset($_GET['download_template'])) {
    $filename = 'template_karyawan.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $writer = WriterEntityFactory::createXLSXWriter();
    $writer->openToBrowser($filename);

    // Header
    $writer->addRow(WriterEntityFactory::createRowFromArray(['username', 'password', 'nama', 'nohp', 'batch']));
    $writer->addRow(WriterEntityFactory::createRowFromArray(['2251khyt', '2251khyt', 'ARI PERYANTO', '6285643692200', 'kloter1']));
    $writer->addRow(WriterEntityFactory::createRowFromArray(['1234abcd', 'abcd1234', 'BUDI SANTOSO', '081234567890', 'kloter1']));

    $writer->close();
    exit;
}

// ==================== View Sent Log ==================== //
if (isset($_GET['view_sent'])) {
    $logFile = __DIR__ . '/sent.json';
    $sent = [];
    if (is_file($logFile)) {
        $sent = json_decode(file_get_contents($logFile), true) ?: [];
    }
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>Log Pesan Terkirim</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 20px;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            th,
            td {
                border: 1px solid #ddd;
                padding: 8px;
            }

            th {
                background: #f1f5f9;
            }

            .ok {
                color: green;
                font-weight: bold;
            }

            .fail {
                color: red;
                font-weight: bold;
            }
        </style>
    </head>

    <body>
        <h2>Log Pesan Terkirim</h2>
        <?php if (empty($sent)): ?>
            <p><em>Belum ada pesan terkirim.</em></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Nomor</th>
                        <th>Status</th>
                        <th>Detail</th>
                        <th>Batch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sent as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['nama'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['phone'] ?? '-') ?></td>
                            <td class="<?= strtolower($r['status']) === 'ok' ? 'ok' : 'fail' ?>"><?= $r['status'] ?></td>
                            <td><?= htmlspecialchars($r['detail'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['batch'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p><a href="./">⬅️ Kembali ke Form</a></p>
    </body>

    </html>
<?php
    exit;
}

// ==================== Process Submit ==================== //
$results = [];
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mode = $_POST['kirim'] ?? 'debug';
        $link = sanitize_link($_POST['link'] ?? '');
        $rows = [];

        if ($mode === 'json') {
            $fileJSON = __DIR__ . '/karyawan.json';
            if (!is_file($fileJSON)) {
                throw new RuntimeException('File karyawan.json tidak ditemukan.');
            }
            $rows = json_decode(file_get_contents($fileJSON), true) ?: [];
        } elseif ($mode === 'excel' && isset($_FILES['excel']) && $_FILES['excel']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['excel']['name'];
            $tmp  = $_FILES['excel']['tmp_name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

            switch ($ext) {
                case 'xlsx':
                    $reader = ReaderEntityFactory::createXLSXReader();
                    break;
                case 'ods':
                    $reader = ReaderEntityFactory::createODSReader();
                    break;
                case 'csv':
                    $reader = ReaderEntityFactory::createCSVReader();
                    break;
                default:
                    throw new RuntimeException("Format file tidak didukung: $ext");
            }

            $reader->open($tmp);
            foreach ($reader->getSheetIterator() as $sheet) {
                $rowIndex = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowIndex++;
                    if ($rowIndex === 1) continue; // skip header
                    $cells = $row->toArray();
                    if (empty(array_filter($cells, fn($v) => trim((string)$v) !== ''))) continue;
                    $rows[] = [
                        'username' => $cells[0] ?? '',
                        'password' => $cells[1] ?? '',
                        'nama'     => $cells[2] ?? '',
                        'nohp'     => $cells[3] ?? '',
                        'batch'    => $cells[4] ?? '',
                        'terlambat' => trim($_POST['terlambat_excel'] ?? 'Kamis 4 September 2025 Pukul 16.00')
                    ];
                }
            }
            $reader->close();
        } else {
            $rows = [[
                'username' => $_POST['username'] ?? '',
                'password' => $_POST['password'] ?? '',
                'nama'     => $_POST['nama'] ?? '',
                'nohp'     => $_POST['nohp'] ?? '',
                'batch'     => $_POST['batch'] ?? '',
                'terlambat' => trim($_POST['terlambat_debug'] ?? 'Kamis 4 September 2025 Pukul 16.00'),
            ]];
        }

        // Kirim pesan
        foreach ($rows as $entry) {
            $username = trim((string)($entry['username'] ?? ''));
            $password = trim((string)($entry['password'] ?? ''));
            $nama     = trim((string)($entry['nama'] ?? ''));
            $nohpRaw  = trim((string)($entry['nohp'] ?? ''));

            if ($username === '' && $password === '' && $nama === '' && $nohpRaw === '') {
                continue;
            }

            $phone = formatIndoPhone($nohpRaw);
            // Template pesan
            $message = "Assalamualaikum w wb..Bapak/Ibu {$nama}" . PHP_EOL . PHP_EOL .
                "Sehubungan dengan adanya kebutuhan untuk memperbarui data diri, bersama ini kami sampaikan agar dapat melakukan update data diri melalui tautan pada link:" . PHP_EOL .
                $link . PHP_EOL . PHP_EOL .
                "dengan menggunakan " . PHP_EOL .
                "👤 Username: {$username}" . PHP_EOL .
                "🔑 Password: {$password}" . PHP_EOL . PHP_EOL .
                "Kami mohon agar pengisian dilakukan dengan benar, lengkap, dan sesuai dengan kondisi terkini, sehingga data yang tercatat dapat lebih valid dan akurat." . PHP_EOL .
                "Atas perhatian dan kerjasamanya, kami ucapkan terima kasih." . PHP_EOL . PHP_EOL .
                "Terlampir surat permohonan update data" . PHP_EOL .
                "https://s.uad.id/SuratPermohonanUpdateDataDiri" . PHP_EOL . PHP_EOL .
                "Tutorial update data diri" . PHP_EOL .
                "https://s.uad.id/TutorialUpdateDataDiri" . PHP_EOL . PHP_EOL .
                "*Pengisian Paling Lambat, " . $entry['terlambat'] . "*" . PHP_EOL . PHP_EOL .
                "Jika ada pertanyaan silahkan kontak ke nomor ini. " . PHP_EOL .
                "Terima Kasih";

            $apiRes = wablas($message, $phone);
            $results[] = [
                'phone'   => $phone,
                'nama'    => $nama,
                'status'  => $apiRes['ok'] ? 'OK' : 'FAIL',
                'detail'  => $apiRes['ok'] ? ($apiRes['json']['message'] ?? 'sent') : $apiRes['error'],
                'batch' => $entry['batch']
            ];
        }

        // Selalu simpan log, apapun mode-nya
        if (!empty($results)) {
            $logFile = __DIR__ . '/sent.json';
            $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (false === file_put_contents($logFile, $json)) {
                die("⚠️ Gagal menulis ke $logFile, cek permission folder.");
            }
        }
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <title>Form Kirim Pesan WA Massal</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 24px;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .row {
            margin-bottom: 12px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
        }

        input[type=text],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 8px;
        }

        button,
        .btn {
            padding: 10px 14px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-secondary {
            background: #64748b;
        }

        .ok {
            color: green;
            font-weight: bold;
        }

        .fail {
            color: red;
            font-weight: bold;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        th {
            background: #f8fafc;
        }
    </style>
    <script>
        function toggleForm() {
            const mode = document.getElementById('kirim').value;
            document.getElementById('debug-form').style.display = (mode === 'debug') ? 'block' : 'none';
            document.getElementById('excel-form').style.display = (mode === 'excel') ? 'block' : 'none';
        }
        window.addEventListener('DOMContentLoaded', toggleForm);
    </script>
</head>

<body>
    <div class="card">
        <h2>Kirim Pesan WA Massal</h2>
        <div class="row">
            <a class="btn btn-secondary" href="?download_template=1">📥 Unduh Template Excel</a>
            <a class="btn btn-secondary" href="?view_sent=1" target="_blank">📖 Lihat Log Terkirim</a>
        </div>
        <form method="post" enctype="multipart/form-data">
            <div class="row">
                <label>Link Update Data</label>
                <input type="text" name="link" value="https://s.id/updatedatappguad" required />
            </div>
            <div class="row">
                <label>Mode Kirim?</label>
                <select name="kirim" id="kirim" onchange="toggleForm()">
                    <option value="debug">Debug (isi manual)</option>
                    <!-- <option value="json">Kirim (pakai karyawan.json)</option> -->
                    <option value="excel">Kirim (upload Excel)</option>
                </select>
            </div>

            <div id="debug-form" class="card" style="display:none;">
                <h3>Data Debug</h3>
                <label>Username</label><input type="text" name="username" value="2251khyt" />
                <label>Password</label><input type="text" name="password" value="2251khyt" />
                <label>Nama</label><input type="text" name="nama" value="ARI PERYANTO" />
                <label>No HP</label><input type="text" name="nohp" value="6283867679277" />
                <label>Pengisian paling lambat</label><input type="text" name="terlambat_debug" value="Kamis 4 September 2025 Pukul 16.00" />
                <label>Batch</label><input type="text" name="batch" value="kloter 1" />
            </div>

            <div id="excel-form" class="card" style="display:none;">
                <label>Pengisian paling lambat</label><input type="text" name="terlambat_excel" value="Kamis 4 September 2025 Pukul 16.00" />
                <h3>Upload Excel</h3>
                <input type="file" name="excel" accept=".xlsx,.ods,.csv" />
            </div>

            <button type="submit">Kirim Pesan</button>
        </form>
    </div>

    <?php if ($errorMsg): ?>
        <div class="card" style="border-color:#fecaca; color:#dc2626;">
            <h3>Error</h3>
            <p><?= htmlspecialchars($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="card">
            <h3>Hasil Pengiriman</h3>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Nomor</th>
                        <th>Status</th>
                        <th>Detail</th>
                        <th>Batch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['nama']) ?></td>
                            <td><?= htmlspecialchars($r['phone']) ?></td>
                            <td class="<?= strtolower($r['status']) === 'ok' ? 'ok' : 'fail' ?>"><?= $r['status'] ?></td>
                            <td><?= htmlspecialchars($r['detail']) ?></td>
                            <td><?= htmlspecialchars($r['batch']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</body>

</html>