<?php
namespace Backup;

use Aws\Ses\SesClient;
use Aws\Sns\SnsClient;
use Twilio\Rest\Client as TwilioClient;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailExc;

/**
 * Notifier
 * --------
 * Dispatches success / failure alerts via the user-configured drivers.
 * Supported drivers (all optional):
 *
 *   • php_mail          – native mail()
 *   • smtp              – PHPMailer
 *   • sendgrid          – REST (curl)
 *   • mailgun           – REST (curl)
 *   • ses               – AWS SDK
 *   • sns               – AWS SDK
 *   • slack             – Incoming webhook
 *   • twilio            – Twilio SDK
 *   • africastalking    – REST (curl)
 *   • webhook (pack 9)  – handled separately in Router
 */
class Notifier
{
    /* --------------------------------------------------------
     *  Low-level log helper (wraps global log_driver)
     * -------------------------------------------------------- */
    public static function log(string $driver, int $code, string $msg = ''): void
    {
        log_driver($driver, $code, $msg);
    }

    /* --------------------------------------------------------
     *  Send alert
     * -------------------------------------------------------- */
    public static function alert(string $subject, string $body, bool $success): void
    {
        $N = Bootstrap::cfg('notifications');

        $sendable = $success ? ($N['on_success'] ?? true)
            : ($N['on_failure'] ?? true);
        if (!$sendable)
            return;

        $drivers = $success ? ($N['success_drivers'] ?? $N['drivers'])
            : ($N['failure_drivers'] ?? $N['drivers']);

        foreach ($drivers as $d)
            switch ($d) {

                /* =====================================================
                 *  1) php_mail  (built-in)
                 * ===================================================== */
                case 'php_mail':
                    $ok = @mail($N['to_email'], $subject, $body);
                    self::log('php_mail', $ok ? 200 : 500, $ok ? 'OK' : 'fail');
                    break;

                /* =====================================================
                 *  2) SMTP  (PHPMailer)
                 * ===================================================== */
                case 'smtp':
                    if (!class_exists(PHPMailer::class)) {
                        self::log('smtp', 500, 'PHPMailer missing');
                        break;
                    }
                    try {
                        $s = $N['smtp'];
                        $m = new PHPMailer(true);
                        $m->isSMTP();
                        $m->Host = $s['host'];
                        $m->Port = $s['port'];
                        $m->SMTPAuth = true;
                        $m->Username = $s['username'];
                        $m->Password = $s['password'];
                        $m->SMTPSecure = $s['encrypt'] ?: PHPMailer::ENCRYPTION_STARTTLS;
                        $m->setFrom($s['from']['address'], $s['from']['name']);
                        $m->addAddress($N['to_email']);
                        $m->Subject = $subject;
                        $m->Body = $body;
                        $m->send();
                        self::log('smtp', 200, 'sent');
                    } catch (MailExc $e) {
                        self::log('smtp', 500, $e->getMessage());
                    }
                    break;

                /* =====================================================
                 *  3) SendGrid  (curl JSON)
                 * ===================================================== */
                case 'sendgrid':
                    [$c, $r] = curl_post_json(
                        'https://api.sendgrid.com/v3/mail/send',
                        [
                            'personalizations' => [
                                [
                                    'to' => [['email' => $N['to_email']]],
                                    'subject' => $subject
                                ]
                            ],
                            'from' => $N['sendgrid']['from'],
                            'content' => [['type' => 'text/plain', 'value' => $body]]
                        ],
                        ['Authorization: Bearer ' . $N['sendgrid']['api_key']]
                    );
                    self::log('sendgrid', $c, $r);
                    break;

                /* =====================================================
                 *  4) Mailgun  (curl form-data helper)
                 * ===================================================== */
                case 'mailgun':
                    [$c, $r] = curl_mailgun(
                        $N['mailgun'],
                        $subject,
                        $body,
                        $N['to_email']
                    );
                    self::log('mailgun', $c, $r);
                    break;

                /* =====================================================
                 *  5) AWS SES  (SDK)
                 * ===================================================== */
                case 'ses':
                    $s = $N['ses'];
                    $ses = new SesClient([
                        'version' => '2010-12-01',
                        'region' => $s['region'],
                        'credentials' => [
                            'key' => $s['access_key'],
                            'secret' => $s['secret_key']
                        ]
                    ]);
                    try {
                        $ses->sendEmail([
                            'Destination' => ['ToAddresses' => [$N['to_email']]],
                            'Message' => [
                                'Subject' => ['Data' => $subject],
                                'Body' => ['Text' => ['Data' => $body]],
                            ],
                            'Source' => $s['from']['address']
                        ]);
                        self::log('ses', 200, 'ok');
                    } catch (\Throwable $e) {
                        self::log('ses', 500, $e->getMessage());
                    }
                    break;

                /* =====================================================
                 *  6) AWS SNS  (SDK)
                 * ===================================================== */
                case 'sns':
                    $s = $N['sns'];
                    $sns = new SnsClient([
                        'version' => '2010-03-31',
                        'region' => $s['region'],
                        'credentials' => [
                            'key' => $s['access_key'],
                            'secret' => $s['secret_key']
                        ]
                    ]);
                    try {
                        $sns->publish([
                            'PhoneNumber' => $N['to_phone'],
                            'Message' => "$subject – $body",
                            'MessageAttributes' => [
                                'AWS.SNS.SMS.SenderID' => [
                                    'DataType' => 'String',
                                    'StringValue' => $s['sender_id']
                                ]
                            ]
                        ]);
                        self::log('sns', 200, 'ok');
                    } catch (\Throwable $e) {
                        self::log('sns', 500, $e->getMessage());
                    }
                    break;

                /* =====================================================
                 *  7) Slack incoming webhook
                 * ===================================================== */
                case 'slack':
                    [$c, $r] = curl_post_json(
                        $N['slack']['webhook'],
                        ['text' => "*{$subject}*\n```{$body}```"]
                    );
                    self::log('slack', $c, $r);
                    break;

                /* =====================================================
                 *  8) Twilio SMS  (SDK)
                 * ===================================================== */
                case 'twilio':
                    $t = $N['twilio'];
                    try {
                        $tw = new TwilioClient($t['sid'], $t['token']);
                        $tw->messages->create(
                            $N['to_phone'],
                            ['from' => $t['from'], 'body' => "$subject – $body"]
                        );
                        self::log('twilio', 200, 'ok');
                    } catch (\Throwable $e) {
                        self::log('twilio', 500, $e->getMessage());
                    }
                    break;

                /* =====================================================
                 *  9) Africa's Talking SMS
                 * ===================================================== */
                case 'africastalking':
                    $at = $N['africastalking'];
                    $ch = curl_init('https://api.africastalking.com/version1/messaging');
                    curl_setopt_array($ch, [
                        CURLOPT_POST => 1,
                        CURLOPT_RETURNTRANSFER => 1,
                        CURLOPT_HTTPHEADER => [
                            'apiKey: ' . $at['api_key'],
                            'Content-Type: application/x-www-form-urlencoded'
                        ],
                        CURLOPT_POSTFIELDS => http_build_query([
                            'username' => $at['username'],
                            'to' => $N['to_phone'],
                            'message' => "$subject – $body",
                            'from' => $at['from']
                        ])
                    ]);
                    $resp = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    self::log('africastalking', $code, $resp);
                    break;
            }
    }
}
