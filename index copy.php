<html>

<head>
    <script>
        function toggleDebugForm() {
            const kirimSelect = document.getElementById("kirim");
            const debugForm = document.getElementById("debug-form");
            if (kirimSelect.value === "debug") {
                debugForm.style.display = "block";
            } else {
                debugForm.style.display = "none";
            }
        }
    </script>
</head>

<body>
    <form method="get">
        <label>Link Update Data:</label><br>
        <input type="text" name="link" placeholder="https://s.id/updatedatappguad" value="https://s.id/updatedatappguad" required>
        <br><br>

        <label>Kirim?</label>
        <select name="kirim" id="kirim" onchange="toggleDebugForm()">
            <option value="debug">Debug (isi manual)</option>
            <option value="ok">Kirim (pakai karyawan.json)</option>
        </select>
        <br><br>

        <!-- Form debug manual -->
        <div id="debug-form" style="border:1px solid #ccc; padding:10px; margin-top:10px;">
            <h3>Data Debug</h3>
            <label>Username:</label><br>
            <input type="text" name="username" value="2251khyt"><br><br>

            <label>Password:</label><br>
            <input type="text" name="password" value="2251khyt"><br><br>

            <label>Nama:</label><br>
            <input type="text" name="nama" value="ARI PERYANTO"><br><br>

            <label>No HP:</label><br>
            <input type="text" name="nohp" value="+6285643692200"><br><br>
        </div>

        <button type="submit">Kirim Pesan</button>
    </form>
</body>

</html>

<?php
set_time_limit(0);
try {
    if (!isset($_GET['link'])) {
        exit; // belum ada input, form baru ditampilkan
    }

    $kirim = $_GET['kirim'] ?? 'debug';
    $link = $_GET['link'];

    // pastikan link ada "https://"
    if (!preg_match('/^https?:\/\//', $link)) {
        $link = "https://" . ltrim($link, '/');
    }

    if (strtolower($kirim) === "ok") {
        // mode kirim -> ambil dari file karyawan.json
        $fileJSON = "karyawan.json";
        $jsonData = file_get_contents($fileJSON);
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            die("Error parsing JSON: " . json_last_error_msg());
        }
    } else {
        // mode debug -> ambil dari form manual
        $data = [[
            "username" => $_GET['username'] ?? '',
            "password" => $_GET['password'] ?? '',
            "nama"     => $_GET['nama'] ?? '',
            "nohp"     => $_GET['nohp'] ?? '',
        ]];
    }

    echo "<pre>";
    foreach ($data as $key => $value) {
        $value['nohp'] = formatIndoPhone($value['nohp']);

        $message = "Assalamualaikum w wb..Bapak/Ibu {$value['nama']}" . PHP_EOL . PHP_EOL .
            "Sehubungan dengan adanya kebutuhan untuk memperbarui data diri, bersama ini kami sampaikan agar dapat melakukan update data diri melalui tautan pada link:" . PHP_EOL .
            $link . PHP_EOL . PHP_EOL .
            "dengan menggunakan " . PHP_EOL .
            "👤 Username: {$value['username']}" . PHP_EOL .
            "🔑 Password: {$value['password']}" . PHP_EOL . PHP_EOL .
            "Kami mohon agar pengisian dilakukan dengan benar, lengkap, dan sesuai dengan kondisi terkini, sehingga data yang tercatat dapat lebih valid dan akurat." . PHP_EOL .
            "Atas perhatian dan kerjasamanya, kami ucapkan terima kasih." . PHP_EOL . PHP_EOL .
            "Terlampir surat permohonan update data" . PHP_EOL .
            "https://s.uad.id/SuratPermohonanUpdateDataDiri" . PHP_EOL . PHP_EOL .
            "Tutorial update data diri" . PHP_EOL .
            "https://s.uad.id/TutorialUpdateDataDiri" . PHP_EOL . PHP_EOL .
            "*Pengisian Paling Lambat, Kamis 4 September 2025 Pukul 16.00*" . PHP_EOL . PHP_EOL .
            "Jika ada pertanyaan silahkan kontak ke nomor ini. " . PHP_EOL .
            "Terima Kasih";

        $res = wablas($message, $value['nohp']);
        echo "Kirim ke {$value['nohp']} → {$value['nama']}" . PHP_EOL;
    }
} catch (\Throwable $th) {
    echo "Error: " . $th->getMessage();
}

function formatIndoPhone($number)
{
    $number = preg_replace('/[^0-9]/', '', $number);
    if (substr($number, 0, 1) === "0") {
        return "62" . substr($number, 1);
    } elseif (substr($number, 0, 2) === "62") {
        return $number;
    }
    return $number;
}

function wablas($message, $phone = "")
{
    $curl = curl_init();
    $payload = [
        'phone' => $phone,
        'message' => $message,
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://pati.wablas.com/api/send-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: 3NCX72aOde4meolTukZte2PjwY1I8O7pYz3QS6AP56JlKBuMw5fIodG2jlZoTywZ.3EmBGgzH",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
    ]);

    $rs = curl_exec($curl);
    if (empty($rs)) {
        echo "cURL Error: " . curl_error($curl);
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    return $rs;
}
?>