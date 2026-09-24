<?php


require 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require 'send_email.php';

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

$student_id = (int) $_SESSION['user_id'];

/* =========================================================
   HANDLE EVENT REGISTRATION
   ========================================================= */

$message = '';
$message_type = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register_event_id'])) {

    csrf_verify();

    $event_id = (int) $_POST['register_event_id'];

    /* ---------------------------------------------------------
       Check if event exists and is approved
       --------------------------------------------------------- */

    $event_check = $pdo->prepare("SELECT event_id, title, status, registration_limit, event_date, start_time, end_time, venue FROM events WHERE event_id = ?");
    $event_check->execute([$event_id]);
    $event = $event_check->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        $message = "The selected event could not be found.";
        $message_type = "error";
    }
    elseif ($event['status'] !== 'approved') {
        $message = "This event is no longer available for registration.";
        $message_type = "error";
    }
    else {

        /* -----------------------------------------------------
           Build the complete event date/time.
           ----------------------------------------------------- */

        $event_start = new DateTime(
            $event['event_date'] . ' ' . $event['start_time']
        );

        $event_end = new DateTime(
            $event['event_date'] . ' ' . $event['end_time']
        );

        $now = new DateTime();


        /* -----------------------------------------------------
           Do not allow registration for completed events
           ----------------------------------------------------- */

        if ($now >= $event_end) {
            $message = "Registration is closed because this event has already ended.";
            $message_type = "error";
        }
        elseif ($now >= $event_start && $now < $event_end) {
            $message = "Registration is closed because this event is currently happening.";
            $message_type = "error";
        }
        else {

            /* -------------------------------------------------
               Check existing registration
               ------------------------------------------------- */

            $check = $pdo->prepare("SELECT registration_id FROM registrations WHERE event_id = ? AND user_id = ? AND status = 'registered'");
            $check->execute([$event_id, $student_id]);


            if ($check->rowCount() > 0) {
                $message = "You already registered for this event.";
                $message_type = "warning";
            }
            else {

                /* ---------------------------------------------
                   Check registration capacity
                   --------------------------------------------- */

                $count_result = $pdo->prepare("SELECT COUNT(*) AS cnt FROM registrations WHERE event_id = ? AND status = 'registered'");
                $count_result->execute([$event_id]);
                $current_count = (int)($count_result->fetch(PDO::FETCH_NUM)[0]);


                $registration_limit = (int) $event['registration_limit'];


                if ($registration_limit > 0 && $current_count >= $registration_limit) {
                    $message = "Sorry, this event has reached its registration limit.";
                    $message_type = "error";
                }
                else {

                    /* -----------------------------------------
                       Register for event
                       ----------------------------------------- */

                    $qr_code = bin2hex(random_bytes(16));


                    $insert_result = $pdo->prepare("INSERT INTO registrations (event_id, user_id, qr_code, status) VALUES (?, ?, ?, 'registered')");
                    $insert_ok = $insert_result->execute([$event_id, $student_id, $qr_code]);


                    if ($insert_ok) {

                        $message = "Successfully registered! Your QR code has been generated.";
                        $message_type = "success";


                        /* -------------------------------------
                           Notification
                           ------------------------------------- */

                        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'registration')")
                            ->execute([$student_id, "You have successfully registered for \"" . $event['title'] . "\"."]);


                        /* -------------------------------------
                           Email confirmation
                           ------------------------------------- */

                        $user_info_result = $pdo->prepare("SELECT email, full_name, email_notifications FROM users WHERE user_id = ?");
                        $user_info_result->execute([$student_id]);
                        $user_info = $user_info_result->fetch(PDO::FETCH_ASSOC);


                        $wants_email = !empty($user_info['email']) && ($user_info['email_notifications'] == 1 || $user_info['email_notifications'] === true);


                        if (!empty($user_info['email']) && $wants_email) {
                            send_notification_email(
                                $user_info['email'],
                                'Event Registration Confirmed',
                                "<h2>Hi " . htmlspecialchars($user_info['full_name']) . "!</h2>" .
                                "<p>You have successfully registered for <strong>" . htmlspecialchars($event['title']) . "</strong>.</p>" .
                                "<p>Your QR code is now available on the <strong>My QR Codes</strong> page.</p>"
                            );
                        }
                    }
                    else {
                        $message = "Registration failed. Please try again.";
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

/* =========================================================
   SEARCH AND FILTER
   ========================================================= */

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';


/* =========================================================
   GET APPROVED EVENTS
   ========================================================= */

$sql = "
    SELECT
        e.event_id,
        e.title,
        e.category,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.poster_image,
        e.status AS event_status,
        e.registration_limit,

        COUNT(r.registration_id) AS registered_count,

        CASE
            WHEN EXISTS (
                SELECT 1 FROM registrations sr WHERE sr.event_id = e.event_id AND sr.user_id = ?
            )
            THEN TRUE
            ELSE FALSE
        END AS is_registered

    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id AND r.status = 'registered'

    WHERE e.status = 'approved'
";

$params = [$student_id];


/* Search */

if (!empty($search)) {
    $sql .= " AND e.title LIKE ?";
    $params[] = "%" . $search . "%";
}


/* Category */

if (!empty($category_filter)) {
    $sql .= " AND e.category = ?";
    $params[] = $category_filter;
}


$sql .= "
    GROUP BY e.event_id
    ORDER BY e.event_date ASC, e.start_time ASC
";


$events = $pdo->prepare($sql);
$events->execute($params);


/* =========================================================
   NOTIFICATION DATA (for the shared header bell)
   ========================================================= */

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$student_id]);
$unread_count = (int)$stmt->fetchColumn();


$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_notifications->execute([$student_id]);


/* =========================================================
   PAGE VARIABLES (for the shared partials)
   ========================================================= */

$role = 'student';
$full_name = $_SESSION['full_name'] ?? 'Student';
$first_name = explode(' ', trim($full_name))[0];


$page_title = 'Browse Events — RMC Events';
$active_page = 'events';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Event System - Browse Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.2.0/remixicon.min.css">
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css">
</head>
<body>

<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<?php include 'partials/header.php'; ?>


<!-- MESSAGE -->
<?php if ($message): ?>
    <div class="bg-<?= $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red') ?>-50 border border-<?= $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red') ?>-300 text-<?= $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red') ?>-700 rounded-2xl px-5 py-4 mb-6 flex items-center gap-3">
        <i class="fa-solid fa-<?= $message_type === 'success' ? 'circle-check' : ($message_type === 'warning' ? 'triangle-exclamation' : 'circle-exclamation'); ?> mr-2"></i>
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>


<!-- SEARCH AND FILTER -->
<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-5 sm:p-6 mb-6 lg:mb-8">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="relative">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search event..."
                class="w-full border border-slate-200 rounded-xl pl-11 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-rmc-800/20 focus:border-rmc-800 bg-white">
        </div>

        <div class="relative">
            <i class="fa-solid fa-folder absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" name="category" value="<?= htmlspecialchars($category_filter); ?>" placeholder="Category..."
                class="w-full border border-slate-200 rounded-xl pl-11 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-rmc-800/20 focus:border-rmc-800 bg-white">
        </div>

        <button type="submit" class="bg-rmc-800 hover:bg-rmc-900 text-white rounded-xl font-semibold py-3 transition flex items-center justify-center gap-2">
            <i class="fa-solid fa-magnifying-glass"></i> Search Events
        </button>

        <a href="events.php" class="flex items-center justify-center gap-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold py-3 hover:bg-rmc-50 transition">
            <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
        </a>

    </form>
</div>


<!-- EVENTS GRID -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 lg:gap-8">


<?php if ($events->rowCount() === 0): ?>

    <div class="md:col-span-2 xl:col-span-3 bg-white rounded-[26px] border border-rmc-200 shadow-sm p-10 text-center">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center">
            <i class="fa-solid fa-calendar-xmark text-2xl"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-900 mt-4">No events found</h3>
        <p class="text-slate-500 mt-2 max-w-md mx-auto">No approved campus events match your current search. Try clearing your filters.</p>
        <a href="events.php" class="inline-flex items-center gap-2 mt-5 bg-rmc-800 hover:bg-rmc-900 text-white rounded-xl px-5 py-3 font-semibold transition">
            <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
        </a>
    </div>

<?php else: ?>

    <?php while ($row = $events->fetch(PDO::FETCH_ASSOC)): ?>

    <?php
        /* -----------------------------------------------------
           COMPUTED DISPLAY FIELDS
           (these come from PHP, not the SQL row, since they
           depend on current time / derived math)
           ----------------------------------------------------- */

        $registration_limit = (int) $row['registration_limit'];
        $registered_count   = (int) $row['registered_count'];

        $row['registration_percentage'] = $registration_limit > 0
            ? min(100, (int) round(($registered_count / $registration_limit) * 100))
            : 0;

        $row['slots_left'] = $registration_limit > 0
            ? max(0, $registration_limit - $registered_count)
            : null;

        $row['progress_class'] = $row['registration_percentage'] >= 100
            ? 'bg-red-500'
            : ($row['registration_percentage'] >= 80 ? 'bg-yellow-500' : 'bg-green-500');

        /* Time-based status — separate from the approval status
           column (event_status), which is always 'approved' here
           since the query only fetches approved events. */

        $event_start_ts = strtotime($row['event_date'] . ' ' . $row['start_time']);
        $event_end_ts   = strtotime($row['event_date'] . ' ' . $row['end_time']);
        $now_ts         = time();

        if ($event_end_ts !== false && $now_ts >= $event_end_ts) {
            $row['time_status']        = 'completed';
            $row['event_status_class'] = 'bg-rmc-100 text-rmc-600 border border-rmc-200';
            $row['event_status_icon']  = 'fa-circle-check';
            $row['event_status_text']  = 'Completed';
        } elseif ($event_start_ts !== false && $now_ts >= $event_start_ts && $now_ts < $event_end_ts) {
            $row['time_status']        = 'live';
            $row['event_status_class'] = 'bg-rmc-500 text-white border border-rmc-600';
            $row['event_status_icon']  = 'fa-fire';
            $row['event_status_text']  = 'Happening Now';
        } else {
            $row['time_status']        = 'upcoming';
            $row['event_status_class'] = 'bg-rmc-50 text-rmc-800 border border-rmc-100';
            $row['event_status_icon']  = 'fa-calendar';
            $row['event_status_text']  = 'Upcoming';
        }
    ?>

    <!-- EVENT CARD -->
    <div class="bg-white rounded-[26px] overflow-hidden border border-slate-200 shadow-sm hover:-translate-y-1 hover:shadow-lg transition duration-300 flex flex-col">

        <!-- POSTER -->
        <?php if (!empty($row['poster_image'])): ?>
        <img src="img/<?= htmlspecialchars(basename($row['poster_image']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($row['title']); ?>" class="w-full h-48 object-cover">
        <?php else: ?>
        <div class="h-48 bg-rmc-50 flex items-center justify-center">
            <img src="img/logo.webp" alt="" class="h-24 w-24 object-contain">
        </div>
        <?php endif; ?>


        <div class="p-5 sm:p-6 flex flex-col flex-1">


            <!-- TOP BADGES -->
            <div class="flex items-center justify-between gap-2 mb-4 flex-wrap">
                <span class="bg-rmc-50 text-rmc-800 border border-rmc-100 text-xs px-3 py-1 rounded-full font-bold">
                    <i class="fa-solid fa-tag mr-1"></i> <?= htmlspecialchars($row['category']); ?>
                </span>


                <span class="<?= $row['event_status_class']; ?> text-xs px-3 py-1 rounded-full font-bold">
                    <i class="fa-solid <?= $row['event_status_icon']; ?> mr-1"></i>
                    <?= $row['event_status_text']; ?>
                </span>
            </div>


            <!-- TITLE -->
            <h3 class="text-xl font-bold text-slate-900 mb-4 leading-snug">
                <?= htmlspecialchars($row['title']); ?>
            </h3>


            <!-- EVENT DETAILS -->
            <div class="space-y-2 text-slate-600 text-sm">
                <p>
                    <i class="fa-solid fa-calendar text-rmc-800 w-5"></i> <?= htmlspecialchars($row['event_date']); ?>
                </p>


                <p>
                    <i class="fa-solid fa-clock text-rmc-800 w-5"></i> <?= htmlspecialchars($row['start_time']); ?> -
                    <?= htmlspecialchars($row['end_time']); ?>
                </p>


                <p>
                    <i class="fa-solid fa-location-dot text-rmc-800 w-5"></i> <?= htmlspecialchars($row['venue']); ?>
                </p>
            </div>


            <!-- REGISTRATION STATUS -->
            <div class="mt-5">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-bold text-slate-800">
                        <i class="fa-solid fa-users text-rmc-800 mr-1"></i> Registration
                    </span>
                    <span class="text-sm font-semibold text-slate-600">
                        <?= $row['registered_count']; ?> /
                        <?= $row['registration_limit'] > 0 ? $row['registration_limit'] : '∞'; ?>
                    </span>
                </div>


                <?php if ($row['registration_limit'] > 0): ?>
                <div class="progress-track">
                    <div class="progress-bar <?= $row['progress_class']; ?>" style="width: <?= $row['registration_percentage']; ?>%;"></div>
                </div>


                <div class="flex justify-between items-center mt-2">
                    <span class="text-xs font-semibold text-slate-500"><?= $row['registration_percentage']; ?>% filled</span>


                    <span class="text-xs font-semibold <?= $row['slots_left'] <= 0 ? 'text-red-600' : ($row['registration_percentage'] >= 80 ? 'text-yellow-600' : 'text-green-600'); ?>">
                        <?php if ($row['slots_left'] <= 0): ?>
                            No slots remaining
                        <?php else: ?>
                            <?= $row['slots_left']; ?> slot<?= $row['slots_left'] == 1 ? '' : 's'; ?> remaining
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>


            <!-- REGISTRATION BUTTON -->
            <form method="POST" class="mt-5">
                <?= csrf_field(); ?>


                <input type="hidden" name="register_event_id" value="<?= htmlspecialchars($row['event_id']); ?>">


                <?php if ($row['is_registered']): ?>
                <button type="button" disabled class="w-full py-3 rounded-xl bg-green-100 text-green-700 font-bold cursor-not-allowed">
                    <i class="fa-solid fa-circle-check mr-2"></i> Already Registered
                </button>


                <?php elseif ($row['time_status'] === 'completed'): ?>
                <button type="button" disabled class="w-full py-3 rounded-xl bg-slate-200 text-slate-500 font-bold cursor-not-allowed">
                    <i class="fa-solid fa-circle-check mr-2"></i> Event Completed
                </button>


                <?php elseif ($row['time_status'] === 'live'): ?>
                <button type="button" disabled class="w-full py-3 rounded-xl bg-red-100 text-red-600 font-bold cursor-not-allowed">
                    <i class="fa-solid fa-lock mr-2"></i> Registration Closed
                </button>


                <?php elseif ($row['registration_limit'] > 0 && $row['registered_count'] >= $row['registration_limit']): ?>
                <button type="button" disabled class="w-full py-3 rounded-xl bg-slate-300 text-slate-600 font-bold cursor-not-allowed">
                    <i class="fa-solid fa-ban mr-2"></i> Registration Closed
                </button>


                <?php else: ?>
                <button type="submit" class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition">
                    <i class="fa-solid fa-user-plus mr-2"></i> Register Now
                </button>
                <?php endif; ?>
            </form>


            <!-- PHOTOS -->
            <a href="gallery.php?id=<?= htmlspecialchars($row['event_id']); ?>" class="block text-center mt-3 py-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold text-sm hover:bg-rmc-50 transition">
                <i class="fa-solid fa-images mr-2"></i> View Photos
            </a>


            <!-- FEEDBACK -->
            <a href="feedback.php?id=<?= htmlspecialchars($row['event_id']); ?>" class="block text-center mt-2 py-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold text-sm hover:bg-rmc-50 transition">
                <i class="fa-solid fa-star mr-2"></i> Feedback
            </a>

        </div>

    </div>


    <?php endwhile; ?>


<?php endif; ?>


</div>


<!-- COUNTDOWN JAVASCRIPT -->
<script>
function updateEventCountdown(card) {
    const startString = card.dataset.start;
    const endString = card.dataset.end;

    if (!startString || !endString) {
        return;
    }


    const start = new Date(startString);
    const end = new Date(endString);


    const daysElement = card.querySelector('.count-days');
    const hoursElement = card.querySelector('.count-hours');
    const minutesElement = card.querySelector('.count-minutes');
    const secondsElement = card.querySelector('.count-seconds');
    const messageElement = card.querySelector('.countdown-message');
    const statusElement = card.querySelector('.countdown-status');


    function pad(number) {
        return String(number).padStart(2, '0');
    }


    const now = new Date();


    /* =====================================================
       COMPLETED
       ===================================================== */

    if (now >= end) {
        daysElement.textContent = '00';
        hoursElement.textContent = '00';
        minutesElement.textContent = '00';
        secondsElement.textContent = '00';


        statusElement.textContent = 'COMPLETED';


        statusElement.className = 'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-slate-200 text-slate-700';


        messageElement.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i> EVENT COMPLETED';


        return;
    }


    /* =====================================================
       HAPPENING NOW
       ===================================================== */

    if (now >= start && now < end) {
        daysElement.textContent = '00';
        hoursElement.textContent = '00';
        minutesElement.textContent = '00';
        secondsElement.textContent = '00';


        statusElement.textContent = 'LIVE';


        statusElement.className = 'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-red-100 text-red-700';


        messageElement.innerHTML = '<i class="fa-solid fa-fire mr-1"></i> HAPPENING NOW';


        return;
    }


    /* =====================================================
       UPCOMING
       ===================================================== */

    const difference = start.getTime() - now.getTime();


    const totalSeconds = Math.floor(difference / 1000);


    const days = Math.floor(totalSeconds / 86400);


    const hours = Math.floor((totalSeconds % 86400) / 3600);


    const minutes = Math.floor((totalSeconds % 3600) / 60);


    const seconds = totalSeconds % 60;


    daysElement.textContent = pad(days);


    hoursElement.textContent = pad(hours);


    minutesElement.textContent = pad(minutes);


    secondsElement.textContent = pad(seconds);


    statusElement.textContent = 'UPCOMING';


    statusElement.className = 'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-rmc-50 text-rmc-800 border border-rmc-100';


    if (days > 0) {
        messageElement.innerHTML = '<i class="fa-solid fa-hourglass-half mr-1"></i> EVENT STARTS SOON';
    }
    else {
        messageElement.innerHTML = '<i class="fa-solid fa-fire mr-1"></i> STARTING TODAY';
    }
}


function updateAllCountdowns() {
    document.querySelectorAll('.countdown-box').forEach(updateEventCountdown);
}


updateAllCountdowns();


/* Update every second. */

setInterval(updateAllCountdowns, 1000);
</script>


<?php include 'partials/footer.php'; ?>

</body>
</html>