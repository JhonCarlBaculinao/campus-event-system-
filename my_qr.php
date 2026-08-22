<?php

session_start();

require 'db_connect.php';
require 'lang.php';
require 'qr_token.php';

/*
|--------------------------------------------------------------------------
| ROLE-BASED ACCESS
|--------------------------------------------------------------------------
| This page is ONLY for students.
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    http_response_code(403);
    die("Access denied. Students only.");
}

$student_id = $_SESSION['user_id'];
$role = 'student';
$full_name = $_SESSION['full_name'] ?? 'Student';
$first_name = explode(' ', trim($full_name))[0];

/*
|--------------------------------------------------------------------------
| GET STUDENT REGISTRATIONS + ATTENDANCE STATUS
|--------------------------------------------------------------------------
*/

$query = pg_query_params(
    $conn,
    "
    SELECT
        r.registration_id,
        r.qr_code,
        r.status AS registration_status,

        e.event_id,
        e.title,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.status AS event_status,

        a.attendance_id,
        a.verified,
        a.checked_in_at

    FROM registrations r

    JOIN events e
        ON r.event_id = e.event_id

    LEFT JOIN attendance a
        ON a.registration_id = r.registration_id

    WHERE r.user_id = $1

    ORDER BY e.event_date ASC, e.start_time ASC
    ",
    array($student_id)
);

if (!$query) {

    error_log(
        "My QR query failed: " .
        pg_last_error($conn)
    );

    die("Unable to load your QR codes.");
}


/* =========================================================
   COLLECT ROWS (keeps display + modal logic separate)
   ========================================================= */

$qr_rows = array();

while ($row = pg_fetch_assoc($query)) {
    $qr_rows[] = $row;
}


/* =========================================================
   NOTIFICATION DATA (for the shared header bell)
   ========================================================= */

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($student_id)
    ),
    0,
    0
);

$recent_notifications = pg_query_params(
    $conn,
    "SELECT notification_id, type, message, is_read, created_at
     FROM notifications
     WHERE user_id = $1
     ORDER BY created_at DESC
     LIMIT 5",
    array($student_id)
);

/* =========================================================
   QR PAYLOADS (registration_id => qr_code for the modal)
   ========================================================= */

$qr_payloads = array();
$static_payloads = array();

foreach ($qr_rows as $row) {

    $qr_payloads[(string) $row['registration_id']] =
        qr_token_mint(
            (int) $row['registration_id'],
            (int) $row['event_id']
        );

    $static_payloads[(string) $row['registration_id']] =
        $row['qr_code'];
}

$page_title  = 'My QR Codes — RMC Events';
$active_page = 'my_qr';

?>

<?php include 'partials/head.php'; ?>

<script src="js/qrcode.min.js" onerror="this.onerror=null;var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';document.head.appendChild(s);"></script>

<script>
/* QR instance registry is declared before any card script runs so the
   card loop can register each renderer and the rotation poll can refresh
   the visible QR + fallback code in place. */
var QR_INSTANCES = {};
</script>

<style>

.qr-box canvas,
.qr-box img,
#qrModalCode canvas,
#qrModalCode img {
    max-width: 100%;
    height: auto;
}

</style>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     PAGE HEADING
     ========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 lg:mb-8">

    <div class="flex items-center gap-3">

        <div class="w-11 h-11 rounded-xl bg-rmc-800 text-white flex items-center justify-center shrink-0">
            <i class="fa-solid fa-qrcode"></i>
        </div>

        <div>

            <h1 class="text-xl sm:text-2xl font-bold text-slate-900">
                My QR Codes
            </h1>

            <p class="text-sm text-slate-500 mt-1">
                Your registration and attendance QR passes.
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     HERO BANNER
     ========================================================= -->

<section class="bg-gradient-to-br from-rmc-50 via-white to-rmc-100 border border-rmc-100 rounded-[26px] shadow-sm p-6 sm:p-8 mb-6 lg:mb-8 animate-up">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">

        <div class="flex items-center gap-4">

            <div class="w-14 h-14 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shrink-0">
                <i class="fa-solid fa-qrcode text-2xl"></i>
            </div>

            <div>

                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">
                    Event Attendance QR Codes
                </h2>

                <p class="text-sm text-slate-600 mt-1">
                    Present your QR code to the organizer when entering the event.
                </p>

            </div>

        </div>


        <div class="flex items-center gap-3 bg-white border border-rmc-100 rounded-2xl px-4 py-3 shadow-sm shrink-0">

            <i class="fa-solid fa-shield-halved text-rmc-800 text-lg"></i>

            <div>

                <p class="font-semibold text-slate-800 text-sm">
                    Secure Attendance
                </p>

                <p class="text-xs text-slate-500">
                    QR verification
                </p>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     NO REGISTERED EVENTS
     ========================================================= -->

<?php if (count($qr_rows) === 0): ?>

    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm p-12 sm:p-16 text-center animate-up">

        <div class="w-24 h-24 mx-auto rounded-full bg-rmc-50 flex items-center justify-center mb-6">
            <i class="fa-solid fa-qrcode text-5xl text-rmc-300"></i>
        </div>

        <h2 class="text-2xl font-bold text-slate-800">
            No Registered Events
        </h2>

        <p class="text-slate-500 mt-2 mb-6">
            Register for an event to generate your QR Code.
        </p>

        <a
            href="events.php"
            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-6 py-3 rounded-xl font-semibold transition"
        >

            <i class="fa-solid fa-calendar-days"></i>

            Browse Events

        </a>

    </div>

<?php else: ?>


<!-- =========================================================
     QR CARDS
     ========================================================= -->

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-6">

<?php foreach ($qr_rows as $row): ?>

<?php

/* =========================================================
   ATTENDANCE STATUS
   ========================================================= */

$has_attendance =
    !empty($row['attendance_id']) &&
    (
        $row['verified'] === 't' ||
        $row['verified'] === true ||
        $row['verified'] === '1' ||
        $row['verified'] === 1
    );


if ($has_attendance) {

    $attendance_badge =
        'bg-emerald-50 text-emerald-700 border-emerald-200';

    $attendance_icon =
        'fa-circle-check';

    $attendance_title =
        'Attendance Verified';

    $attendance_text =
        'PRESENT';

} else {

    $attendance_badge =
        'bg-amber-50 text-amber-700 border-amber-200';

    $attendance_icon =
        'fa-clock';

    $attendance_title =
        'Not Yet Checked In';

    $attendance_text =
        'Awaiting Check-In';

}


/* =========================================================
   DATE DISPLAY
   ========================================================= */

$formatted_date =
    date(
        'F d, Y',
        strtotime($row['event_date'])
    );


/* =========================================================
   CHECK-IN TIME
   ========================================================= */

$check_in_display = '';

if (!empty($row['checked_in_at'])) {

    $check_in_display =
        date(
            'F d, Y — h:i A',
            strtotime($row['checked_in_at'])
        );

}

$js_title = json_encode(
    $row['title'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$js_name = json_encode(
    $full_name,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$js_attendance_title = json_encode(
    $attendance_title,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

?>

    <div class="qr-card bg-white border border-slate-200 rounded-[26px] shadow-sm overflow-hidden flex flex-col animate-up">

        <!-- EVENT HEADER -->

        <div class="bg-rmc-50/70 border-b border-rmc-100 p-5">

            <div class="flex items-start justify-between gap-3">

                <div class="flex items-center gap-3 min-w-0">

                    <div class="w-11 h-11 rounded-xl bg-rmc-800 text-white flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>

                    <div class="min-w-0">

                        <h3 class="text-lg font-bold text-slate-900 leading-snug">
                            <?= htmlspecialchars($row['title']); ?>
                        </h3>

                    </div>

                </div>


                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold text-xs whitespace-nowrap shrink-0">

                    <i class="fa-solid fa-circle-check"></i>

                    Registered

                </span>

            </div>


            <div class="mt-4 space-y-1.5 text-sm text-slate-600">

                <p>
                    <i class="fa-solid fa-calendar text-rmc-800 w-5"></i>
                    <?= htmlspecialchars($formatted_date); ?>
                </p>

                <p>
                    <i class="fa-solid fa-clock text-rmc-800 w-5"></i>
                    <?= htmlspecialchars($row['start_time']); ?>
                    -
                    <?= htmlspecialchars($row['end_time']); ?>
                </p>

                <p>
                    <i class="fa-solid fa-location-dot text-red-500 w-5"></i>
                    <?= htmlspecialchars($row['venue']); ?>
                </p>

            </div>

        </div>


        <!-- BODY -->

        <div class="p-5 flex flex-col gap-4 flex-1">


            <!-- ATTENDANCE STATUS -->

            <div class="rounded-2xl border <?= $attendance_badge; ?> p-4">

                <div class="flex items-center gap-3">

                    <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center shadow-sm">
                        <i class="fa-solid <?= $attendance_icon; ?> text-lg"></i>
                    </div>

                    <div class="flex-1 min-w-0">

                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                            Attendance
                        </p>

                        <p class="font-bold text-slate-900">
                            <?= $attendance_title; ?>
                        </p>

                    </div>

                </div>


                <?php if ($has_attendance && !empty($check_in_display)): ?>

                    <p class="mt-3 pt-3 border-t border-slate-200 text-xs font-semibold text-slate-600">

                        <i class="fa-solid fa-clock mr-1"></i>

                        Checked in:
                        <?= htmlspecialchars($check_in_display); ?>

                    </p>

                <?php endif; ?>

            </div>


            <!-- QR CODE -->

            <div class="text-center">

                <p class="text-[10px] font-bold tracking-widest text-slate-400 mb-3">
                    PRESENT THIS QR CODE AT THE EVENT
                </p>

                <div class="qr-box inline-flex bg-white border-4 border-slate-100 rounded-2xl p-4 shadow-lg">
                    <div id="qrcode-<?= (int) $row['registration_id']; ?>"></div>
                </div>

                <div class="mt-4 bg-slate-50 border border-slate-200 rounded-2xl p-3 text-left">
                    <p class="text-[10px] font-bold tracking-widest text-slate-400 mb-2 text-center">
                        <?= htmlspecialchars(t('qr_fallback_code')); ?>
                    </p>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            readonly
                            id="fb-<?= (int) $row['registration_id']; ?>"
                            value="<?= htmlspecialchars($qr_payloads[(string) $row['registration_id']]); ?>"
                            class="w-full text-[11px] font-mono bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-700 select-all outline-none"
                        >
                        <button
                            type="button"
                            onclick="copyFallbackCode('fb-<?= (int) $row['registration_id']; ?>')"
                            class="shrink-0 bg-rmc-800 hover:bg-rmc-900 text-white text-xs font-bold px-3 py-2 rounded-lg transition"
                        >
                            <i class="fa-solid fa-copy mr-1"></i>
                            <?= htmlspecialchars(t('qr_copy')); ?>
                        </button>
                    </div>
                </div>

                <p class="text-[11px] text-slate-400 mt-3">
                    <i class="fa-solid fa-shield-halved mr-1"></i>
                    <?= htmlspecialchars(t('qr_code_refresh_hint')); ?>
                </p>

            </div>


            <!-- ACTIONS -->

            <div class="mt-auto space-y-2.5">

                <button
                    onclick="openQrModal(
                        <?= (int) $row['registration_id']; ?>,
                        <?= $js_title; ?>,
                        <?= $js_name; ?>,
                        <?= $js_attendance_title; ?>,
                        '<?= $attendance_badge; ?>'
                    )"
                    class="w-full inline-flex items-center justify-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-3 rounded-xl text-sm font-bold transition"
                >

                    <i class="fa-solid fa-expand"></i>

                    Show QR Full Screen

                </button>


                <button
                    onclick="printQRCode('qrcode-<?= (int) $row['registration_id']; ?>', <?= $js_title; ?>)"
                    class="w-full inline-flex items-center justify-center gap-2 bg-white border border-rmc-200 text-rmc-800 hover:bg-rmc-50 px-4 py-2.5 rounded-xl text-sm font-semibold transition"
                >

                    <i class="fa-solid fa-print"></i>

                    Print QR Code

                </button>

            </div>

        </div>

    </div>


    <script>

        QR_INSTANCES[<?= (int) $row['registration_id']; ?>] =
            new QRCode(
                document.getElementById("qrcode-<?= (int) $row['registration_id']; ?>"),
                {
                    text: <?= json_encode($qr_payloads[(string) $row['registration_id']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    width: 220,
                    height: 220,
                    correctLevel: QRCode.CorrectLevel.H
                }
            );

    </script>


<?php endforeach; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     FULLSCREEN QR MODAL
     ========================================================= -->

<div
    id="qrModal"
    class="hidden fixed inset-0 z-[70] bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
    onclick="if (event.target === this) closeQrModal();"
>

    <div class="bg-white rounded-3xl w-full max-w-sm sm:max-w-md shadow-2xl overflow-hidden">

        <div class="bg-rmc-800 text-white px-5 py-4 flex items-center justify-between gap-3">

            <div class="min-w-0">

                <p class="text-[10px] uppercase tracking-widest text-rmc-200 font-bold mb-1">
                    Event Pass
                </p>

                <h3 id="qrModalTitle" class="font-bold text-base sm:text-lg leading-snug"></h3>

            </div>

            <button
                onclick="closeQrModal()"
                class="w-9 h-9 shrink-0 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center transition"
                aria-label="Close"
            >

                <i class="fa-solid fa-xmark text-lg"></i>

            </button>

        </div>

        <div class="p-5 sm:p-6 text-center">

            <div id="qrModalCode" class="mx-auto w-full max-w-[320px] bg-white border-4 border-slate-100 rounded-2xl p-3 sm:p-4 shadow-sm flex items-center justify-center"></div>

            <p id="qrModalName" class="font-bold text-slate-900 mt-4 text-lg"></p>

            <p class="text-sm text-slate-500 mt-1">
                Present this code to the organizer
            </p>

            <span id="qrModalStatus" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border mt-5">

                <i class="fa-solid fa-id-badge"></i>

                <span id="qrModalStatusText"></span>

            </span>

            <div class="mt-5 bg-slate-50 border border-slate-200 rounded-2xl p-3 text-left">

                <p class="text-[10px] font-bold tracking-widest text-slate-400 mb-2 text-center">
                    <?= htmlspecialchars(t('qr_fallback_code')); ?>
                </p>

                <div class="flex items-center gap-2">

                    <input
                        type="text"
                        readonly
                        id="qrModalFallback"
                        class="w-full text-[11px] font-mono bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-700 select-all outline-none"
                    >

                    <button
                        type="button"
                        onclick="copyFallbackCode('qrModalFallback')"
                        class="shrink-0 bg-rmc-800 hover:bg-rmc-900 text-white text-xs font-bold px-3 py-2 rounded-lg transition"
                    >
                        <i class="fa-solid fa-copy mr-1"></i>
                        <?= htmlspecialchars(t('qr_copy')); ?>
                    </button>

                </div>

            </div>

        </div>

        <button
            onclick="closeQrModal()"
            class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 py-3.5 font-semibold transition border-t border-slate-200"
        >

            Close

        </button>

    </div>

</div>


<?php include 'partials/footer.php'; ?>


<style>

/* QR canvases are re-inverted via dark_mode.php alongside img/video.
   This block is intentionally empty — the global dark_mode.php handles
   canvas re-inversion so QR codes always render black-on-white regardless
   of dark/light mode. */

</style>


<script>

/* =========================================================
   QR MODAL
   ========================================================= */

var QR_DATA = <?= json_encode($qr_payloads); ?>;
var STATIC_CODES = <?= json_encode($static_payloads); ?>;
var QR_INSTANCES = window.QR_INSTANCES || {};
var QR_RIDS = <?= json_encode(array_map('intval', array_keys($qr_payloads))); ?>;
var QR_MODAL_CTX = null;
var QR_TTL = <?= (int) QR_TOKEN_TTL; ?>;
var QR_CSRF = <?= json_encode(csrf_token()); ?>;


function openQrModal(regId, title, name, statusText, statusClass) {

    const modal =
        document.getElementById('qrModal');

    const codeBox =
        document.getElementById('qrModalCode');

    document.getElementById('qrModalTitle').textContent =
        title;

    document.getElementById('qrModalName').textContent =
        name;

    const statusEl =
        document.getElementById('qrModalStatus');

    statusEl.className =
        'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border mt-5 ' +
        statusClass;

    document.getElementById('qrModalStatusText').textContent =
        statusText;

    codeBox.innerHTML = '';

    modal.classList.remove('hidden');

    document.body.style.overflow = 'hidden';

    QR_MODAL_CTX = {
        rid: regId,
        title: title,
        name: name,
        statusText: statusText,
        statusClass: statusClass
    };

    renderModalQr();
}


function renderModalQr() {

    const codeBox =
        document.getElementById('qrModalCode');

    if (!QR_MODAL_CTX) {
        return;
    }

    codeBox.innerHTML = '';

    new QRCode(
        codeBox,
        {
            text: QR_DATA[String(QR_MODAL_CTX.rid)] || '',
            width: 320,
            height: 320,
            correctLevel: QRCode.CorrectLevel.H
        }
    );

    const fbInput =
        document.getElementById('qrModalFallback');

    if (fbInput) {
        fbInput.value = QR_DATA[String(QR_MODAL_CTX.rid)] || '';
    }
}


function refreshQrTokens() {

    if (QR_RIDS.length === 0) {
        return;
    }

    const formData = new URLSearchParams();

    formData.append(
        'rids',
        QR_RIDS.join(',')
    );

    formData.append(
        'csrf_token',
        window.QR_CSRF || ''
    );

    fetch(
        'qr_token.php',
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        }
    )
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {

        if (!data || !data.tokens) {
            return;
        }

        var updated = false;

        Object.keys(data.tokens).forEach(function (rid) {

            QR_DATA[rid] = data.tokens[rid];

            if (QR_INSTANCES[rid]) {
                QR_INSTANCES[rid].makeCode(data.tokens[rid]);
                updated = true;
            }

            const fbInput =
                document.getElementById('fb-' + rid);

            if (fbInput) {
                fbInput.value = data.tokens[rid];
                updated = true;
            }

        });

        if (updated) {
            renderModalQr();
        }

    })
    .catch(function () {
        // Transient failure — retry on the next interval.
    });
}


setInterval(function () {
    refreshQrTokens();
}, Math.max(10, QR_TTL - 15) * 1000);


function closeQrModal() {

    const modal =
        document.getElementById('qrModal');

    modal.classList.add('hidden');

    document.body.style.overflow = '';
}


/* =========================================================
   COPY FALLBACK CODE
   ========================================================= */

function copyFallbackCode(inputId) {

    const input =
        document.getElementById(inputId);

    if (!input) {
        return;
    }

    input.focus();
    input.select();

    const copyText = function () {
        return new Promise(function (resolve) {

            if (navigator.clipboard && window.isSecureContext) {

                navigator.clipboard.writeText(input.value).then(resolve);

            } else {

                try {
                    document.execCommand('copy');
                } catch (e) {}
                resolve();
            }
        });
    };

    copyText().then(function () {

        const label = inputId === 'qrModalFallback'
            ? document.querySelector('#qrModal .fa-copy')
            : null;

        const button = inputId === 'qrModalFallback'
            ? input.closest('.flex')
            : null;

        const buttons = document.querySelectorAll('button');
        let btn = null;

        buttons.forEach(function (b) {
            if (b.getAttribute('onclick') === "copyFallbackCode('" + inputId + "')") {
                btn = b;
            }
        });

        if (btn) {

            const original = btn.innerHTML;

            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i>' +
                <?= json_encode(t('qr_copied'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

            btn.classList.add('bg-emerald-600');

            setTimeout(function () {
                btn.innerHTML = original;
                btn.classList.remove('bg-emerald-600');
            }, 1600);
        }
    });
}


document.addEventListener('keydown', function (event) {

    if (event.key === 'Escape') {

        closeQrModal();
    }
});


/* =========================================================
   PRINT FUNCTION
   ========================================================= */

function printQRCode(qrId, eventTitle) {

    const regId = String(qrId).replace('qrcode-', '');

    if (!STATIC_CODES[regId]) {
        return;
    }

    const printWindow =
        window.open('', '_blank', 'width=700,height=800');

    printWindow.document.write(`
        <!DOCTYPE html>

        <html>

        <head>

            <title>QR Code - ${escapeHtml(eventTitle)}</title>

            <style>

                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 50px;
                }

                h1 {
                    margin-bottom: 10px;
                }

                #print-qr {
                    display: inline-flex;
                    margin: 30px auto;
                }

                .footer {
                    color: #666;
                    font-size: 13px;
                }

            </style>

        </head>

        <body>

            <h1>${escapeHtml(eventTitle)}</h1>

            <p>Regis Marie College Event System</p>

            <div id="print-qr"></div>

            <p class="footer">
                Present this QR Code to the event organizer for attendance verification.
            </p>

            <script src="js/qrcode.min.js"><\/script>

            <script>
                new QRCode(
                    document.getElementById('print-qr'),
                    {
                        text: ${JSON.stringify(STATIC_CODES[regId])},
                        width: 300,
                        height: 300,
                        correctLevel: QRCode.CorrectLevel.H
                    }
                );
            <\/script>

        </body>

        </html>
    `);

    printWindow.document.close();

    printWindow.focus();

    setTimeout(function() {

        printWindow.print();

    }, 700);

}


function escapeHtml(text) {

    const div = document.createElement('div');

    div.textContent = text;

    return div.innerHTML;

}

</script>

</body>

</html>
