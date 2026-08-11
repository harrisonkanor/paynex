<?php
/**
 * payNex Mailer — sends emails via Elastic Email HTTP API (production)
 * or Mailpit SMTP (development), with file logging as fallback.
 *
 * PRODUCTION (ELASTICEMAIL_API_KEY set in config.php):
 *   - Sends via Elastic Email HTTP API (https://api.elasticemail.com/v2/email/send)
 *
 * DEVELOPMENT (ELASTICEMAIL_API_KEY not set):
 *   - Mailpit SMTP on 127.0.0.1:1025 (web UI at http://localhost:8025)
 *   - Emails logged to /tmp/paynex-mail.log
 */

/**
 * Send an email.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    mail_log_to_file($to, $subject, $body);

    // Try Elastic Email HTTP API (production)
    if (defined('ELASTICEMAIL_API_KEY') && ELASTICEMAIL_API_KEY !== '') {
        if (send_mail_http_api($to, $subject, $body)) {
            return true;
        }
        error_log('Mailer: Elastic Email API failed, trying SMTP fallback');
    }

    // Try SMTP (Mailpit for development)
    if (send_mail_smtp($to, $subject, $body)) {
        return true;
    }

    // Final fallback: PHP built-in mail()
    error_log('Mailer: All methods failed, falling back to mail()');
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n";
    $headers .= "X-Mailer: payNex\r\n";
    return @mail($to, $subject, $body, $headers);
}

/**
 * Send via Elastic Email HTTP API v2.
 */
function send_mail_http_api(string $to, string $subject, string $body): bool
{
    $url = 'https://api.elasticemail.com/v2/email/send';

    $postData = http_build_query([
        'apikey'            => ELASTICEMAIL_API_KEY,
        'from'              => MAIL_FROM_ADDRESS,
        'fromName'          => MAIL_FROM_NAME,
        'to'                => $to,
        'subject'           => $subject,
        'bodyHtml'          => $body,
        'isTransactional'   => 'true',
    ]);

    $ch = @curl_init();
    if (!$ch) {
        error_log('Mailer HTTP: cURL not available');
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Mailer HTTP: cURL error — ' . $error);
        return false;
    }

    $result = json_decode($response, true);

    // Elastic Email returns: {"success":true,"data":{"transactionid":"..."}}
    if (isset($result['success']) && $result['success'] === true) {
        error_log('Mailer HTTP: Email sent successfully to ' . $to);
        return true;
    }

    $errorMsg = $result['error'] ?? $result['message'] ?? 'Unknown error';
    error_log('Mailer HTTP: API error — ' . json_encode($errorMsg));
    return false;
}

/**
 * Send via SMTP (Mailpit on localhost for development).
 */
function send_mail_smtp(string $to, string $subject, string $body): bool
{
    $smtpHost = '127.0.0.1';
    $smtpPort = 1025;

    $message = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n"
             . "To: {$to}\r\n"
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "X-Mailer: payNex\r\n"
             . "\r\n"
             . $body;

    $errno  = 0;
    $errstr = '';
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);

    if (!$socket) {
        error_log('Mailer SMTP: Could not connect to ' . $smtpHost . ':' . $smtpPort . ' — ' . $errstr);
        return false;
    }

    $greeting = fgets($socket);

    fwrite($socket, "EHLO payNex\r\n");
    while (($line = fgets($socket)) && substr($line, 3, 1) !== ' ') {}

    fwrite($socket, "MAIL FROM:<" . MAIL_FROM_ADDRESS . ">\r\n");
    fgets($socket);

    fwrite($socket, "RCPT TO:<{$to}>\r\n");
    fgets($socket);

    fwrite($socket, "DATA\r\n");
    fgets($socket);

    fwrite($socket, $message . "\r\n.\r\n");
    fgets($socket);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

/**
 * Log email to file for debugging.
 */
function mail_log_to_file(string $to, string $subject, string $body): void
{
    $logFile = '/tmp/paynex-mail.log';
    $stamp   = date('Y-m-d H:i:s');
    $entry   = "=== Email sent at {$stamp} ===\n"
             . "To: {$to}\n"
             . "Subject: {$subject}\n"
             . "--- Body ---\n{$body}\n"
             . "=== End ===\n\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/* =============================================================
 * EMAIL TEMPLATES
 * =========================================================== */

function mail_welcome_with_otp(string $to, string $name, string $referralCode, string $referralLink, string $otpCode): void
{
    $subject = 'Welcome to ' . SITE_NAME . ' — Verify your email';
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Welcome to payNex, {$name}!</h2>
        <p>Thanks for signing up! Use the code below to verify your email address:</p>
        <div style='background:#f4f6f4; border-radius:12px; padding:20px; text-align:center; margin:20px 0;'>
          <span style='font-size:36px; font-weight:700; letter-spacing:8px; font-family:monospace; color:#0A1B29;'>
            {$otpCode}
          </span>
        </div>
        <p>Enter this code on the verification page to activate your account.</p>
        <p style='margin-top:24px;'>
          <a href='" . BASE_URL . "/verify_email.php'
             style='display:inline-block; background:#8AD24A; color:#0A1B29; padding:12px 28px; border-radius:999px; text-decoration:none; font-weight:600;'>
            Verify now →
          </a>
        </p>
        <hr style='border:none; border-top:1px solid #eee; margin:24px 0;'>
        <p>Here are your referral details — share them to earn bonuses:</p>
        <table style='width:100%; margin:12px 0; border-collapse:collapse;'>
          <tr>
            <td style='padding:12px; background:#f4f6f4; border-radius:8px; font-family:monospace; font-size:22px; letter-spacing:4px; text-align:center;'>
              {$referralCode}
            </td>
          </tr>
        </table>
        <p>Or share your personal link:</p>
        <p><a href='{$referralLink}' style='color:#2E8FD6;'>{$referralLink}</a></p>
        <p style='font-size:13px; color:#888;'>The verification code expires in 15 minutes.</p>
    ");
    send_mail($to, $subject, $body);
}

function mail_verification_otp(string $to, string $name, string $otpCode): void
{
    $subject = SITE_NAME . ' — Verify your email address';
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Verify your email, {$name}</h2>
        <p>Use the code below to verify your email address:</p>
        <div style='background:#f4f6f4; border-radius:12px; padding:20px; text-align:center; margin:20px 0;'>
          <span style='font-size:36px; font-weight:700; letter-spacing:8px; font-family:monospace; color:#0A1B29;'>
            {$otpCode}
          </span>
        </div>
        <p>Enter this code on the verification page to activate your account.</p>
        <p style='font-size:13px; color:#888;'>This code expires in 15 minutes.</p>
        <p style='margin-top:24px;'>
          <a href='" . BASE_URL . "/verify_email.php'
             style='background:#8AD24A; color:#0A1B29; padding:12px 28px; border-radius:999px; text-decoration:none; font-weight:600;'>
            Verify now →
          </a>
        </p>
    ");
    send_mail($to, $subject, $body);
}

function mail_password_reset(string $to, string $name, string $resetLink): void
{
    $subject = SITE_NAME . ' — Password reset request';
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Reset your password, {$name}</h2>
        <p>We received a request to reset your password. Click the button below to set a new password:</p>
        <p style='margin:24px 0; text-align:center;'>
          <a href='{$resetLink}'
             style='display:inline-block; background:#8AD24A; color:#0A1B29; padding:14px 32px; border-radius:999px; text-decoration:none; font-weight:600; font-size:16px;'>
            Reset password →
          </a>
        </p>
        <p style='font-size:13px; color:#888;'>This link expires in 60 minutes.</p>
        <p style='font-size:13px; color:#888;'>If you didn't request this, you can safely ignore this email.</p>
    ");
    send_mail($to, $subject, $body);
}

function mail_login_alert(string $to, string $name, string $ip): void
{
    $time = date('Y-m-d H:i:s T');
    $subject = SITE_NAME . ' — New login detected';
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>New login, {$name}</h2>
        <p>A login was just recorded on your payNex account.</p>
        <table style='width:100%; border-collapse:collapse; font-size:14px; margin:16px 0;'>
          <tr><td style='padding:8px 0; color:#666;'>Time</td><td><strong>{$time}</strong></td></tr>
          <tr><td style='padding:8px 0; color:#666;'>IP address</td><td><strong>{$ip}</strong></td></tr>
        </table>
        <p>If this wasn't you, <a href='" . BASE_URL . "/login.php' style='color:#E2685F;'>log in and change your password immediately</a>.</p>
    ");
    send_mail($to, $subject, $body);
}

function mail_withdrawal_requested(string $to, string $name, float $amount, string $method, string $account): void
{
    $subject = SITE_NAME . ' — Withdrawal request received';
    $amt = money($amount);
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Withdrawal request received</h2>
        <p>Hi {$name}, we received your withdrawal request. Here are the details:</p>
        <table style='width:100%; border-collapse:collapse; font-size:14px; margin:16px 0;'>
          <tr><td style='padding:8px 0; color:#666;'>Amount</td><td><strong>{$amt}</strong></td></tr>
          <tr><td style='padding:8px 0; color:#666;'>Method</td><td><strong>" . e($method) . "</strong></td></tr>
          <tr><td style='padding:8px 0; color:#666;'>Account</td><td><strong>" . e($account) . "</strong></td></tr>
        </table>
        <p>An admin will review and process it. Average processing time is ~2 hours.</p>
        <p><a href='" . BASE_URL . "/withdraw.php' style='color:#2E8FD6;'>View withdrawal history →</a></p>
    ");
    send_mail($to, $subject, $body);
}

function mail_withdrawal_paid(string $to, string $name, float $amount): void
{
    $subject = SITE_NAME . ' — Your withdrawal has been paid!';
    $amt = money($amount);
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Payment sent!</h2>
        <p>Hi {$name}, your withdrawal of <strong>{$amt}</strong> has been approved and sent.</p>
        <p>Please allow a few minutes for the transaction to appear on the blockchain.</p>
        <p><a href='" . BASE_URL . "/withdraw.php' style='color:#2E8FD6;'>View your withdrawal history →</a></p>
    ");
    send_mail($to, $subject, $body);
}

function mail_withdrawal_rejected(string $to, string $name, float $amount, string $note = ''): void
{
    $subject = SITE_NAME . ' — Withdrawal rejected';
    $amt = money($amount);
    $noteHtml = $note ? "<p>Reason: <em>" . e($note) . "</em></p>" : '';
    $body = email_layout($subject, "
        <h2 style='margin:0 0 12px;'>Withdrawal rejected</h2>
        <p>Hi {$name}, unfortunately your withdrawal of <strong>{$amt}</strong> could not be processed.</p>
        {$noteHtml}
        <p>The full amount has been <strong>refunded to your wallet</strong> automatically.</p>
        <p><a href='" . BASE_URL . "/withdraw.php' style='color:#2E8FD6;'>Request again →</a></p>
    ");
    send_mail($to, $subject, $body);
}

/* HTML email layout wrapper */
function email_layout(string $title, string $content): string
{
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0; padding:0; background:#F2F5F1; font-family:'Helvetica Neue',Arial,sans-serif; color:#0A1520;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F2F5F1; padding:40px 0;">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="background:#fff; border-radius:16px; overflow:hidden; border:1px solid rgba(10,21,32,0.10);">
        <tr>
          <td style="background:#0A1B29; padding:24px 32px;">
            <span style="font-family:Arial,sans-serif; font-weight:700; font-size:20px; color:#8AD24A; letter-spacing:-0.02em;">pay</span><span style="font-family:Arial,sans-serif; font-weight:700; font-size:20px; color:#fff; letter-spacing:-0.02em;">Nex</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            {$content}
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px; border-top:1px solid rgba(10,21,32,0.10); font-size:12px; color:#888;">
            © payNex. You're receiving this because you have an account at payNex.<br>
            <a href="" style="color:#2E8FD6;">Unsubscribe</a>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
