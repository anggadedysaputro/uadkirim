<?php

/**
 * Single-file PHP: Form + Debug mode + JSON mode + Excel upload (Box/Spout)
 * Features:
 * - Download Excel template (?download_template=1)
 * - Read XLSX/XLS/ODS/CSV via box/spout
 * - Fallback manual input (debug mode)
 * - Optional JSON source (karyawan.json)
 * - Send message via Wablas API
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
    if (strpos($number, '00') === 0) {
        // e.g. 0062... -> 62...
        $number = ltrim($number, '0');
    }
    if (substr($number, 0, 1) === '0') {
        return '62' . substr($number, 1);
    }
    if (substr($number, 0, 2) === '62') {
        return $number;
    }
    return $number; // biarkan apa adanya (misal sudah intl tanpa +)
}

function wablas($message, $phone = '')
{
    // TODO: Ganti token dengan punyamu sendiri. Simpan di env kalau bisa.
    $WABLAS_TOKEN = '3NCX72aOde4meolTukZte2PjwY1I8O7pYz3QS6AP56JlKBuMw5fIodG2jlZoTywZ.3EmBGgzH';

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
            'raw'    => null,
        ];
    }
    curl_close($curl);

    $json = json_decode($rs, true);
    return [
        'ok'    => is_array($json) ? true : false,
        'error' => null,
        'raw'   => $rs,
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
    $writer->addRow(WriterEntityFactory::createRowFromArray(['username', 'password', 'nama', 'nohp']));

    // Example rows (optional)
    $writer->addRow(WriterEntityFactory::createRowFromArray(['2251khyt', '2251khyt', 'ARI PERYANTO', '+6285643692200']));
    $writer->addRow(WriterEntityFactory::createRowFromArray(['1234abcd', 'abcd1234', 'BUDI SANTOSO', '081234567890']));

    $writer->close();
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
            $jsonData = file_get_contents($fileJSON);
            $rows = json_decode($jsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('JSON tidak valid: ' . json_last_error_msg());
            }
        } elseif ($mode === 'excel' && isset($_FILES['excel']) && $_FILES['excel']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['excel']['name'];
            $tmp  = $_FILES['excel']['tmp_name'];

            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

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
                    // Skip baris kosong total
                    if (empty(array_filter($cells, fn($v) => trim((string)$v) !== ''))) {
                        continue;
                    }
                    $rows[] = [
                        'username' => $cells[0] ?? '',
                        'password' => $cells[1] ?? '',
                        'nama'     => $cells[2] ?? '',
                        'nohp'     => $cells[3] ?? '',
                    ];
                }
            }
            $reader->close();
        } else {
            // debug/manual
            $rows = [[
                'username' => $_POST['username'] ?? '',
                'password' => $_POST['password'] ?? '',
                'nama'     => $_POST['nama'] ?? '',
                'nohp'     => $_POST['nohp'] ?? '',
            ]];
        }

        // Kirim pesan
        foreach ($rows as $entry) {
            $username = trim((string)($entry['username'] ?? ''));
            $password = trim((string)($entry['password'] ?? ''));
            $nama     = trim((string)($entry['nama'] ?? ''));
            $nohpRaw  = trim((string)($entry['nohp'] ?? ''));

            if ($username === '' && $password === '' && $nama === '' && $nohpRaw === '') {
                continue; // lewati baris kosong
            }

            $phone = formatIndoPhone($nohpRaw);

            $message =
                "Assalamualaikum w wb..Bapak/Ibu {$nama}" . PHP_EOL . PHP_EOL .
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
                "*Pengisian Paling Lambat, Kamis 4 September 2025 Pukul 16.00*" . PHP_EOL . PHP_EOL .
                "Jika ada pertanyaan silahkan kontak ke nomor ini. " . PHP_EOL .
                "Terima Kasih";

            $apiRes = wablas($message, $phone);
            $results[] = [
                'phone'   => $phone,
                'nama'    => $nama,
                'status'  => $apiRes['ok'] ? 'OK' : 'FAIL',
                'detail'  => $apiRes['ok'] ? ($apiRes['json']['message'] ?? 'sent') : $apiRes['error'],
            ];
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
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif;
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
        }

        .btn-secondary {
            background: #64748b;
            text-decoration: none;
            display: inline-block;
        }

        .muted {
            color: #64748b;
            font-size: 12px;
        }

        pre {
            background: #0b1020;
            color: #e5e7eb;
            padding: 12px;
            border-radius: 8px;
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #e5e7eb;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f8fafc;
        }

        .ok {
            color: #16a34a;
            font-weight: 600;
        }

        .fail {
            color: #dc2626;
            font-weight: 600;
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
            <span class="muted">&nbsp;Format didukung: XLSX, XLS, ODS, CSV (kolom: username, password, nama, nohp)</span>
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
                    <option value="json">Kirim (pakai karyawan.json)</option>
                    <option value="excel">Kirim (upload Excel)</option>
                </select>
            </div>

            <div id="debug-form" class="card" style="display:none;">
                <h3>Data Debug</h3>
                <div class="row">
                    <label>Username</label>
                    <input type="text" name="username" value="2251khyt" />
                </div>
                <div class="row">
                    <label>Password</label>
                    <input type="text" name="password" value="2251khyt" />
                </div>
                <div class="row">
                    <label>Nama</label>
                    <input type="text" name="nama" value="ARI PERYANTO" />
                </div>
                <div class="row">
                    <label>No HP</label>
                    <input type="text" name="nohp" value="+6285643692200" />
                </div>
            </div>

            <div id="excel-form" class="card" style="display:none;">
                <h3>Upload Excel</h3>
                <input type="file" name="excel" accept=".xlsx,.xls,.ods,.csv" />
                <div class="muted">Kolom wajib: <b>A=Username</b>, <b>B=Password</b>, <b>C=Nama</b>, <b>D=NoHP</b></div>
            </div>

            <button type="submit">Kirim Pesan</button>
        </form>
    </div>

    <?php if ($errorMsg): ?>
        <div class="card" style="border-color:#fecaca;">
            <h3 style="margin-top:0;color:#dc2626;">Error</h3>
            <pre><?= htmlspecialchars($errorMsg) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="card">
            <h3 style="margin-top:0;">Hasil Pengiriman</h3>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Nomor</th>
                        <th>Status</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['nama'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['phone'] ?? '-') ?></td>
                            <td class="<?= ($r['status'] === 'OK') ? 'ok' : 'fail' ?>"><?= $r['status'] ?></td>
                            <td><code><?= htmlspecialchars((string)($r['detail'] ?? '')) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</body>

</html>