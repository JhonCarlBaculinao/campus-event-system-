<?php

/*
|--------------------------------------------------------------------------
| RMC EMAIL TEMPLATES  (single source of truth)
|--------------------------------------------------------------------------
| Reusable RMC-branded email templates. All emails sent through the
| system are wrapped in the maroon RMC Events header/footer by
| send_email.php unless they already carry the <!-- RMC_BRANDED --> marker.
|--------------------------------------------------------------------------
*/

define('RMC_EMAIL_MAROON', '#7a0c0c');

define('RMC_BRANDED_MARKER', '<!-- RMC_BRANDED -->');


/*
|--------------------------------------------------------------------------
| Branded wrapper (header + footer)
|--------------------------------------------------------------------------
*/

function rmc_email_wrapper($inner_html)
{
    return '
        <!DOCTYPE html>
        <html>
        <body style="margin:0;padding:0;background:#f5f5f4;">

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;">
                <tr>
                    <td align="center">

                        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,0.08);max-width:600px;width:100%;">

                            <tr>
                                <td style="background:' . RMC_EMAIL_MAROON . ';padding:26px 32px;">

                                    <div style="font-family:Arial,sans-serif;color:#ffffff;">
                                        <div style="font-size:22px;font-weight:bold;letter-spacing:0.5px;">
                                            RMC Events
                                        </div>
                                        <div style="font-size:13px;opacity:0.9;margin-top:2px;">
                                            Regis Marie College
                                        </div>
                                    </div>

                                </td>
                            </tr>

                            <tr>
                                <td style="padding:32px;font-family:Arial,sans-serif;line-height:1.6;color:#333;">

                                    ' . $inner_html . '

                                </td>
                            </tr>

                            <tr>
                                <td style="background:#fafafa;padding:20px 32px;border-top:1px solid #eeeeee;text-align:center;font-family:Arial,sans-serif;">

                                    <p style="font-size:12px;color:#888888;margin:0 0 4px 0;">
                                        Regis Marie College &middot; Campus Event Management System
                                    </p>

                                    <p style="font-size:11px;color:#aaaaaa;margin:0;">
                                        Please do not reply to this automated message.
                                    </p>

                                </td>
                            </tr>

                        </table>

                    </td>
                </tr>
            </table>

            ' . RMC_BRANDED_MARKER . '

        </body>
        </html>';
}


/*
|--------------------------------------------------------------------------
| Email verification (sign-up)
|--------------------------------------------------------------------------
*/

function build_verification_email_html($name, $code)
{
    $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safe_code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $inner = '
        <h2 style="color:' . RMC_EMAIL_MAROON . ';margin-top:0;">
            Verify Your Email
        </h2>

        <p>
            Hello <strong>' . $safe_name . '</strong>,
        </p>

        <p>
            Thank you for registering for the
            Regis Marie College Campus Event System.
        </p>

        <p>
            Please use the verification code below
            to verify your Gmail address:
        </p>

        <div style="background:#f1f5f9;border-radius:12px;padding:20px;text-align:center;margin:25px 0;">
            <span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:' . RMC_EMAIL_MAROON . ';">
                ' . $safe_code . '
            </span>
        </div>

        <p>
            This verification code will expire
            in <strong>10 minutes</strong>.
        </p>

        <p>
            If you did not request this registration,
            you can safely ignore this email.
        </p>';

    return rmc_email_wrapper($inner);
}


/*
|--------------------------------------------------------------------------
| Welcome (after successful verification)
|--------------------------------------------------------------------------
*/

function build_welcome_email_html($name)
{
    $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $inner = '
        <h2 style="color:' . RMC_EMAIL_MAROON . ';margin-top:0;">
            Welcome to Regis Marie College
        </h2>

        <p>
            Hello <strong>' . $safe_name . '</strong>,
        </p>

        <p>
            Your Gmail address has been successfully verified.
        </p>

        <p>
            Your Regis Marie College Campus Event System account
            has now been created.
        </p>

        <p>You can now log in and:</p>

        <ul>
            <li>Browse campus events</li>
            <li>Register for events</li>
            <li>View your event registrations</li>
            <li>Use your QR code for attendance</li>
            <li>Receive event notifications</li>
        </ul>

        <p>
            Thank you for joining the
            Regis Marie College Campus Event System.
        </p>';

    return rmc_email_wrapper($inner);
}


/*
|--------------------------------------------------------------------------
| Password reset (with button link)
|--------------------------------------------------------------------------
*/

function build_password_reset_email_html($name, $reset_url)
{
    $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safe_url  = htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8');

    $inner = '
        <h2 style="color:' . RMC_EMAIL_MAROON . ';margin-top:0;">
            Password Reset Request
        </h2>

        <p>
            Hello <strong>' . $safe_name . '</strong>,
        </p>

        <p>
            We received a request to reset the password
            for your Regis Marie College Event System account.
        </p>

        <p>Click the button below to create a new password:</p>

        <p style="margin:25px 0;">
            <a
                href="' . $safe_url . '"
                style="
                    display:inline-block;
                    padding:12px 22px;
                    background:' . RMC_EMAIL_MAROON . ';
                    color:#ffffff;
                    text-decoration:none;
                    border-radius:8px;
                    font-weight:bold;
                "
            >
                Reset My Password
            </a>
        </p>

        <p>
            This link will expire in <strong>30 minutes</strong>.
        </p>

        <p>
            If you did not request a password reset,
            you can safely ignore this email.
        </p>';

    return rmc_email_wrapper($inner);
}


/*
|--------------------------------------------------------------------------
| Generic notification email
|--------------------------------------------------------------------------
| $message may contain pre-escaped HTML. Use {FULL_NAME} / {MESSAGE}
| placeholders when this template is passed to notify_all_users()
| or notify_role() so it is personalized per recipient.
|--------------------------------------------------------------------------
*/

function build_notification_email_html($full_name, $message, $heading = null)
{
    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');

    $heading_html = ($heading !== null && $heading !== '')
        ? '<h2 style="color:' . RMC_EMAIL_MAROON . ';margin-top:0;">' .
            htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') .
          '</h2>'
        : '';

    $inner = $heading_html . '
        <p>Hello <strong>' . $safe_name . '</strong>,</p>

        <div>' . $message . '</div>';

    return rmc_email_wrapper($inner);
}

?>
