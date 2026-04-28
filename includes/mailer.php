<?php

require_once __DIR__ . '/../includes/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

function sendVerificationEmail($email, $firstName, $token) {

    $verifyUrl = SITE_URL . '/verify.php?token=' . $token;

    $subject = 'Verify Your DocuGo Account';

    $body = '
    <div style="font-family:Arial;background:#f0f4f8;padding:20px;">
        <div style="max-width:520px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;">
            
            <div style="background:#1a56db;padding:20px;color:white;">
                <h2 style="margin:0;">DocuGo</h2>
            </div>

            <div style="padding:20px;">
                <h3>Hi ' . htmlspecialchars($firstName) . ' 👋</h3>

                <p>Please verify your email to activate your account.</p>

                <div style="text-align:center;margin:20px 0;">
                    <a href="' . $verifyUrl . '"
                       style="background:#1a56db;color:#fff;padding:12px 20px;
                       text-decoration:none;border-radius:6px;">
                       Verify Account
                    </a>
                </div>

                <p style="font-size:12px;color:#666;">
                    Or copy link: <br>
                    <a href="' . $verifyUrl . '">' . $verifyUrl . '</a>
                </p>
            </div>
        </div>
    </div>';

    return sendWithPHPMailer($email, $firstName, $subject, $body);
}

function sendWithPHPMailer($toEmail, $toName, $subject, $body) {

    $mail = new PHPMailer(true);

    try {
        // ======================
        // SMTP CONFIG (GMAIL)
        // ======================
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        // 🔴 CHANGE THIS
        $mail->Username   = MAIL_USERNAME;

        // 🔴 USE GMAIL APP PASSWORD
        $mail->Password   = MAIL_PASSWORD;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        return $mail->send();

    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function sendWithMail($toEmail, $subject, $body) {

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";

    return mail($toEmail, $subject, $body, $headers);
}