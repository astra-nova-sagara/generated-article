<?php
set_time_limit(1200);

// === Konfigurasi API ===
$gemini_api_key = 'AIzaSyANttYPw4vHUB00GtBT7NPdjm-oxrJSkDQ';
$wp_url = rtrim('http://localhost/wordpress/wp-json/wp/v2', '/');
$wp_username = 'TR4AA.';
$wp_password = 'iF9K8PPRwpwvdVOaELfaaj9s';

// === Autentikasi WordPress ===
function getAuthHeader($username, $password) {
    $creds = base64_encode("$username:$password");
    return "Authorization: Basic $creds";
}

// === Generate Judul Artikel ===
function generateTitles($topik, $api_key) {
    $prompt = <<<PROMPT
Buatkan 20 judul artikel dengan topik "$topik". 
Judul dilarang keras diawali oleh topik.
Judul artikel harus mengandung copywriting, menarik minat pembaca, dan maksimal 12 kata.
Jangan gunakan kata 'tentu', 'tentunya', 'berikut', atau kalimat pembuka tidak relevan.
Langsung sebutkan judul dalam baris baru.
Tanpa tanda *, nomor, atau simbol lainnya.
PROMPT;

    $response = callGemini($prompt, $api_key);
    $lines = preg_split("/\r?\n/", trim($response));
    $titles = [];
    foreach ($lines as $line) {
        $clean = trim(preg_replace("/[\-*\d.]+/", '', $line));
        if (strlen($clean) > 5 && str_word_count($clean) <= 12) {
            $titles[] = $clean;
        }
        if (count($titles) == 20) break;
    }
    return $titles;
}

// === Generate Artikel dari Judul ===
function generateArticle($judul, $penulis, $api_key) {
    $prompt = <<<PROMPT
Anda adalah jurnalis profesional yang menulis artikel berita aktual dan faktual dengan gaya bahasa santai dan mudah dipahami pembaca Indonesia, sepanjang 700 kata.

Tuliskan artikel berdasarkan judul berikut: "$judul"

Gunakan struktur berikut:
- Paragraf pembuka (2 paragraf pertama)
- Sisipkan <p><strong>Baca juga:</strong></p> setelah paragraf ke-2
- Tiga subjudul menarik (gunakan tag <h2>) dengan gaya pertanyaan seperti People Also Ask Google
- Gunakan daftar (bullet atau angka jika perlu), setiap subjudul tetap satu baris
- Sebelum paragraf penutup terakhir, tambahkan <p><strong>Baca juga:</strong></p>
- Paragraf penutup
- Tambahkan di akhir: <p><strong>Penulis: $penulis</strong></p>

Gunakan tag HTML langsung (h1, h2, p, ul, ol, li). Markdown, tanda *, <html> ,atau simbol aneh dilarang keras!!
jangan membalas pesan!!!
jangan repeat judul yang ada dalam 1 artikel!!
PROMPT;

    $response = callGemini($prompt, $api_key);
    return cleanArticle($response, $judul);
}

// === Generate Tags Otomatis dari AI ===
function generateTagsFromAI($judul, $artikel, $api_key) {
    $prompt = <<<PROMPT
Judul: $judul

Artikel:
$artikel

berikan tags yang sesuai, pisahkan dengan tanda koma
PROMPT;

    $response = callGemini($prompt, $api_key);
    $tags_text = trim($response);
    $tags = array_map('trim', explode(',', $tags_text));
    return array_filter($tags);
}

// === Request ke Gemini API ===
function callGemini($prompt, $api_key) {
    $data = ["contents" => [["parts" => [["text" => $prompt]]]]];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=$api_key");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    return $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

// === Bersihkan Artikel ===
function cleanArticle($text, $judul) {
    $text = preg_replace("/<h1[^>]*>.*?<\/h1>/i", '', $text);
    $text = preg_replace("/^.*$judul.*$/im", '', $text);
    $text = str_replace("html", '', $text);
    $text = str_replace('`', '', $text);
    return trim($text);
}

// === Posting ke WordPress ===
function postToWordpress($title, $content, $tags, $wp_url, $username, $password) {
    $tag_ids = [];
    foreach ($tags as $tag) {
        $id = getOrCreateTag($tag, $wp_url, $username, $password);
        if ($id) $tag_ids[] = $id;
    }

    $data = [
        'title' => $title,
        'content' => $content,
        'status' => 'draft',
        'tags' => $tag_ids
    ];

    $ch = curl_init("$wp_url/posts");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        getAuthHeader($username, $password)
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// === Cek atau Buat Tag ===
function getOrCreateTag($tag, $wp_url, $username, $password) {
    $url = "$wp_url/tags?search=" . urlencode($tag);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [getAuthHeader($username, $password)]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (!empty($data)) return $data[0]['id'];

    $ch = curl_init("$wp_url/tags");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["name" => $tag]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        getAuthHeader($username, $password)
    ]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    return $data['id'] ?? null;
}

// Aktifkan auto flush output ke browser
ob_implicit_flush(true);
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Auto Artikel ➜ WordPress</title>
  <style>
    :root {
      --bg-color: #121212;
      --card-color: #1e1e1e;
      --text-color: #f0f0f0;
      --accent-color: #4fc3f7;
      --button-hover: #29b6f6;
    }
    body {
      font-family: 'Segoe UI', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-color);
      display: flex;
      justify-content: center;
      padding: 40px;
    }
    .container {
      background-color: var(--card-color);
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
      max-width: 700px;
      width: 100%;
    }
    h1 {
      font-size: 24px;
      margin-bottom: 24px;
      text-align: center;
      color: var(--accent-color);
    }
    label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      color: #ccc;
    }
    input[type="text"] {
      width: 100%;
      padding: 10px;
      border-radius: 6px;
      border: none;
      background-color: #2c2c2c;
      color: var(--text-color);
      margin-bottom: 16px;
    }
    button {
      background-color: var(--accent-color);
      color: var(--bg-color);
      border: none;
      padding: 12px;
      border-radius: 6px;
      font-size: 16px;
      font-weight: bold;
      width: 100%;
      cursor: pointer;
      transition: background-color 0.3s ease;
    }
    button:hover {
      background-color: var(--button-hover);
    }
    .log {
      margin-top: 24px;
      padding: 20px;
      background: #2a2a2a;
      border-radius: 10px;
      height: 350px;
      overflow-y: scroll;
      font-family: monospace;
      font-size: 14px;
    }
  </style>
</head>
<body>
<div class="container">
  <h1>📝 Generate 20 Artikel & Kirim ke WordPress</h1>
  <form method="POST">
    <label>Topik Utama:</label>
    <input type="text" name="topik" required placeholder="Misalnya: Teknologi AI">

    <label>Nama Penulis:</label>
    <input type="text" name="penulis" required placeholder="Contoh: Ahmad Fauzan Rasyidin">

    <button type="submit">🚀 Mulai Generate & Upload</button>
  </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div class='log'><strong>[Progress]</strong><br/>";
    $topik = $_POST['topik'];
    $penulis = $_POST['penulis'];

    $titles = generateTitles($topik, $gemini_api_key);
    foreach ($titles as $i => $judul) {
        echo "<br>🎯 <strong>[$i] $judul</strong><br/>";
        echo "⏳ Menulis artikel...<br/>";
        $artikel = generateArticle($judul, $penulis, $gemini_api_key);

        echo "🔖 Generate tags dari AI...<br/>";
        $tags = generateTagsFromAI($judul, $artikel, $gemini_api_key);

        echo "📤 Upload ke WordPress...<br/>";
        $res = postToWordpress($judul, $artikel, $tags, $wp_url, $wp_username, $wp_password);

        if (!empty($res['id'])) {
            echo "✅ <span style='color:lightgreen;'>Artikel berhasil disimpan sebagai draft (ID: {$res['id']})</span><br/>";
        } else {
            echo "❌ <span style='color:red;'>Gagal upload artikel</span><br/>";
        }

        echo str_repeat(' ', 1024); flush();
    }
    echo "<br><strong>🚀 Semua selesai!</strong></div>";
}
?>
</div>
</body>
</html>
