<?php
// ============================================================
//  占いAPI 中継（プロキシ）＋日本語翻訳  ★先生がロリポップに設置する動く土台
// ============================================================
//  今回の星座占いAPIは、ブラウザから直接 fetch すると CORS でブロックされる
//  （API側が Access-Control-Allow-Origin を返さないため）。
//  そこで、このPHPをサーバーに置いて「間に1枚かます」ことで回避する。
//  サーバー同士の通信に CORS は無いので、このPHPが代わりにAPIを叩き、
//  結果に CORS 許可ヘッダーを付けてブラウザへ返す。
//  さらに、占いの本文（英語）を Google 翻訳で日本語にしてから返す。
//
//  設置方法（先生）:
//    1. この horoscope フォルダごと（中の index.php）をロリポップのWeb公開
//       フォルダにアップロードする → エンドポイントは
//       https://trident-web.kikirara.jp/horoscope/ （ディレクトリの index.php）
//    2. ブラウザで
//       https://trident-web.kikirara.jp/horoscope/?sign=Aries&day=TODAY
//       を開き、horoscope が日本語の JSON が返ればOK
//    3. その URL は README と index.html の課題コメントに記載済み
//
//  ※ 学生はこのファイルを触らない（起動も不要・常時稼働）。
// ============================================================

// どのオリジン（Live Server など）からでも読めるように CORS を許可
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// URL を GET してレスポンス本文を返す（cURL 優先・file_get_contents フォールバック）
function httpGet(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 308リダイレクト対策
        curl_setopt($ch, CURLOPT_USERAGENT, 'jsQuiz-proxy');
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res !== false && $code === 200) {
            return $res;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $res = @file_get_contents($url);
        if ($res !== false) {
            return $res;
        }
    }
    return null;
}

// 英語テキストを日本語へ翻訳（Google 翻訳の gtx エンドポイント・APIキー不要）
// 失敗したら null を返す（呼び出し側で英語のままにフォールバックする）
function translateToJa(string $text): ?string {
    $url = 'https://translate.googleapis.com/translate_a/single'
         . '?client=gtx&sl=en&tl=ja&dt=t&q=' . rawurlencode($text);
    $json = httpGet($url);
    if ($json === null) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        return null;
    }
    // 長文は複数セグメントに分割されて返るので結合する
    $out = '';
    foreach ($data[0] as $seg) {
        if (isset($seg[0])) {
            $out .= $seg[0];
        }
    }
    return $out !== '' ? $out : null;
}

// --- 入力（英字のみ許可してサニタイズ）---
$sign = isset($_GET['sign']) ? preg_replace('/[^A-Za-z]/', '', $_GET['sign']) : 'Aries';
$day  = isset($_GET['day'])  ? preg_replace('/[^A-Za-z]/', '', $_GET['day'])  : 'TODAY';

// --- ① 占いAPIから英語の運勢を取得 ---
$apiUrl = 'https://freehoroscopeapi.com/api/v1/get-horoscope/daily?sign='
        . urlencode($sign) . '&day=' . urlencode($day);
$body = httpGet($apiUrl);

if ($body === null) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream_failed']);
    exit;
}

$data = json_decode($body, true);

// --- ② 本文を日本語に翻訳して差し替え ---
if (isset($data['data']['horoscope'])) {
    $en = $data['data']['horoscope'];
    $ja = translateToJa($en);
    if ($ja !== null) {
        $data['data']['horoscope_en'] = $en; // 念のため英語も残す
        $data['data']['horoscope']    = $ja; // 学生が読む horoscope を日本語に
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
} else {
    // 想定外の形ならそのまま返す
    echo $body;
}
