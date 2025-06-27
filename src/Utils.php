<?php
namespace Backup;

/**
 * Utils – shared helper functions
 * -------------------------------
 * • curl_post_json, curl_mailgun
 * • Simple AWS SigV4 helpers (JSON + Query flavours)
 * • log_driver()  (writes notifier.log if debug on)
 *
 * All helpers live in the global namespace so legacy calls
 * from Notifier / Uploader work unchanged.
 */

/* ============================================================
 *  Logger  (central place so every module can call it)
 * ============================================================ */
if (!function_exists('log_driver')) {
    function log_driver(string $driver, int $code, string $resp = ''): void
    {
        $N = \Backup\Bootstrap::cfg('notifications');

        if (!($N['debug'] ?? false) && $driver !== 'webhook') {
            return;                     // keep logs lean unless debug=true
        }
        $line = '[' . gmdate('c') . "] $driver HTTP $code → "
            . substr($resp, 0, 200) . "\n";

        file_put_contents(__DIR__ . '/../notifier.log', $line, FILE_APPEND);
    }
}

/* ============================================================
 *  Basic JSON POST wrapper
 * ============================================================ */
if (!function_exists('curl_post_json')) {
    function curl_post_json(string $url, array $data, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $headers
            )
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $resp];
    }
}

/* ============================================================
 *  Mailgun helper (form-data POST)
 * ============================================================ */
if (!function_exists('curl_mailgun')) {
    function curl_mailgun(array $mg, string $subject, string $body, string $to): array
    {
        $ch = curl_init("https://api.mailgun.net/v3/{$mg['domain']}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERPWD => 'api:' . $mg['api_key'],
            CURLOPT_POSTFIELDS => [
                'from' => "{$mg['from']['name']} <{$mg['from']['address']}>",
                'to' => $to,
                'subject' => $subject,
                'text' => $body
            ]
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $resp];
    }
}

/* ============================================================
 *  Minimal AWS SigV4 signer (JSON body)
 * ============================================================ */
if (!function_exists('aws_sign')) {
    function aws_sign(string $k, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $k, true);
        $kRegn = hash_hmac('sha256', $region, $kDate, true);
        $kServ = hash_hmac('sha256', $service, $kRegn, true);
        return hash_hmac('sha256', 'aws4_request', $kServ, true);
    }
}

if (!function_exists('aws_sigv4_json')) {
    function aws_sigv4_json(
        array $creds,     // ['region','access_key','secret_key']
        string $host,
        string $service,
        string $target,
        string $json
    ): array {
        $t = time();
        $amz = gmdate('Ymd\THis\Z', $t);
        $date = gmdate('Ymd', $t);
        $scope = "$date/{$creds['region']}/$service/aws4_request";

        $kSigning = aws_sign(
            $creds['secret_key'],
            $date,
            $creds['region'],
            $service
        );

        $canonical = "POST\n/\n\n" .
            "content-type:application/x-amz-json-1.0\n" .
            "host:$host\n" .
            "x-amz-date:$amz\n" .
            "x-amz-target:$target\n\n" .
            "content-type;host;x-amz-date;x-amz-target\n" .
        hash('sha256', $json);

        $stringToSign = "AWS4-HMAC-SHA256\n$amz\n$scope\n" .
        hash('sha256', $canonical);

        $sig = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = "AWS4-HMAC-SHA256 Credential={$creds['access_key']}/$scope, "
            . "SignedHeaders=content-type;host;x-amz-date;x-amz-target, "
            . "Signature=$sig";

        [$code, $resp] = curl_post_json(
            "https://$host/",
            $json,
            [
                'Content-Type: application/x-amz-json-1.0',
                "X-Amz-Date: $amz",
                "X-Amz-Target: $target",
                "Authorization: $auth"
            ]
        );

        return [$code, $resp];
    }
}

/* ============================================================
 *  AWS SigV4 Query-string helper (for SNS)
 * ============================================================ */
if (!function_exists('aws_sigv4_query')) {
    function aws_sigv4_query(
        array $creds,  // ['region','access_key','secret_key']
        string $host,
        string $service,
        string $query
    ): array {
        $t = time();
        $amz = gmdate('Ymd\THis\Z', $t);
        $date = gmdate('Ymd', $t);
        $scope = "$date/{$creds['region']}/$service/aws4_request";

        $kSigning = aws_sign(
            $creds['secret_key'],
            $date,
            $creds['region'],
            $service
        );

        $qs = $query .
            '&X-Amz-Algorithm=AWS4-HMAC-SHA256' .
            '&X-Amz-Credential=' . rawurlencode($creds['access_key'] . "/$scope") .
            '&X-Amz-Date=' . $amz .
            '&X-Amz-SignedHeaders=host';

        $canonical = "GET\n/\n$qs\nhost:$host\n\nhost\n" . hash('sha256', '');

        $stringToSign = "AWS4-HMAC-SHA256\n$amz\n$scope\n" .
            hash('sha256', $canonical);

        $sig = hash_hmac('sha256', $stringToSign, $kSigning);

        $url = "https://$host/?$qs&X-Amz-Signature=$sig";

        $resp = file_get_contents($url);
        return [200, $resp !== false ? $resp : ''];
    }
}
