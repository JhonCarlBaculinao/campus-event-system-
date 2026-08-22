<?php

session_start();

include 'db_connect.php';
require 'lang.php';
require 'csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Get Event
|--------------------------------------------------------------------------
*/

$event_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($event_id <= 0) {
    die("Invalid event.");
}

$event_result = pg_query_params(
    $conn,
    "SELECT * FROM events WHERE event_id = $1",
    array($event_id)
);

$event = pg_fetch_assoc($event_result);

if (!$event) {
    die("Event not found.");
}

/*
|--------------------------------------------------------------------------
| Check Attendance
|--------------------------------------------------------------------------
| Only students who actually attended the event can submit feedback.
|--------------------------------------------------------------------------
*/

$attended = false;

if ($role === 'student') {

    $attend_check = pg_query_params(
        $conn,
        "SELECT a.attendance_id
         FROM attendance a
         JOIN registrations r
           ON a.registration_id = r.registration_id
         WHERE r.event_id = $1
           AND r.user_id = $2
         LIMIT 1",
        array($event_id, $user_id)
    );

    $attended = pg_num_rows($attend_check) > 0;
}

/*
|--------------------------------------------------------------------------
| Submit Feedback
|--------------------------------------------------------------------------
| Multiple submissions are allowed.
| Existing feedback is NOT updated anymore.
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['submit_feedback']) &&
    $role === 'student'
) {

    csrf_verify();

    if (!$attended) {

        $error = t('feedback_attended_only');

    } else {

        $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
        $comment = trim($_POST['comment'] ?? '');

        /*
        | Anonymous checkbox
        */
        $is_anonymous = isset($_POST['is_anonymous']) &&
                        $_POST['is_anonymous'] === '1';

        /*
        | Validate rating
        */
        if ($rating < 1 || $rating > 5) {

            $error = t('select_rating_msg');

        } else {

            /*
            | Insert a NEW feedback record.
            | We intentionally do NOT update previous feedback.
            */

            $insert_result = pg_query_params(
                $conn,
                "INSERT INTO feedback
                    (event_id, user_id, rating, comment, is_anonymous)
                 VALUES
                    ($1, $2, $3, $4, $5)",
                array(
                    $event_id,
                    $user_id,
                    $rating,
                    $comment,
                    $is_anonymous ? 'true' : 'false'
                )
            );

            if ($insert_result) {

                $success = t('feedback_submitted_msg');

            } else {

                $error = t('feedback_submit_error');
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Get All Feedback
|--------------------------------------------------------------------------
*/

$all_feedback = pg_query_params(
    $conn,
    "SELECT
        f.feedback_id,
        f.rating,
        f.comment,
        f.is_anonymous,
        f.created_at,
        u.full_name
     FROM feedback f
     JOIN users u
       ON f.user_id = u.user_id
     WHERE f.event_id = $1
     ORDER BY f.created_at DESC",
    array($event_id)
);

/*
|--------------------------------------------------------------------------
| Average Rating
|--------------------------------------------------------------------------
*/

$avg_result = pg_fetch_assoc(
    pg_query_params(
        $conn,
        "SELECT
            ROUND(AVG(rating)::numeric, 1) AS avg_rating,
            COUNT(*) AS total
         FROM feedback
         WHERE event_id = $1",
        array($event_id)
    )
);

$avg_rating = $avg_result['avg_rating'] ?? null;
$total_feedback = (int) ($avg_result['total'] ?? 0);


/* =========================================================
   UNREAD COUNT + RECENT NOTIFICATIONS (shared header)
   ========================================================= */

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($user_id)
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
    array($user_id)
);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = ucfirst($role);

if ($role === 'admin') {
    $role_label = 'Administrator';
} elseif ($role === 'organizer') {
    $role_label = 'Event Organizer';
}

$page_title  = t('title_feedback');
$active_page = '';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     FEEDBACK HERO — AVERAGE RATING
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex flex-col sm:flex-row sm:items-center gap-6">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-star text-2xl"></i>

        </div>

        <div class="min-w-0 flex-1">

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight break-words">
                <?= htmlspecialchars($event['title']); ?>
            </h2>

            <?php if ($total_feedback > 0): ?>

                <div class="flex items-center gap-3 flex-wrap mt-3">

                    <span class="text-3xl font-bold text-slate-900">
                        <?= htmlspecialchars($avg_rating); ?>
                    </span>

                    <span class="text-amber-500 text-xl">

                        <?php
                        $rounded_rating = round((float) $avg_rating);

                        for ($i = 1; $i <= 5; $i++):
                        ?>

                            <i class="fa-solid fa-star <?= $i <= $rounded_rating ? '' : 'opacity-30'; ?>"></i>

                        <?php endfor; ?>

                    </span>

                    <span class="text-sm font-semibold text-slate-600">
                        (<?= $total_feedback; ?>
                        <?= $total_feedback == 1 ? t('review') : t('reviews'); ?>)
                    </span>

                </div>

            <?php else: ?>

                <p class="text-slate-600 mt-1 text-sm">
                    <?= t('no_feedback_event'); ?>
                </p>

            <?php endif; ?>

        </div>

        <a
            href="events.php"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 font-semibold text-sm shrink-0 self-start sm:self-auto"
        >

            <i class="fa-solid fa-arrow-left"></i>

            <?= t('back'); ?>

        </a>

    </div>

</div>


<!-- =========================================================
     ALERTS
     ========================================================= -->

<?php if ($error): ?>

    <div
        class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6 animate-up"
    >

        <i class="fa-solid fa-circle-exclamation mr-2"></i>

        <?= htmlspecialchars($error); ?>

    </div>

<?php endif; ?>

<?php if ($success): ?>

    <div
        class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6 animate-up"
    >

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($success); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     STUDENT FEEDBACK FORM
     ========================================================= -->

<?php if ($role === 'student'): ?>

    <?php if ($attended): ?>

        <div
            class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-1"
        >

            <div class="mb-6">

                <h2 class="text-xl font-bold text-slate-900 flex items-center gap-3">

                    <span
                        class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
                    >

                        <i class="fa-solid fa-pen"></i>

                    </span>

                    <?= t('leave_your_feedback'); ?>

                </h2>

                <p class="text-sm text-slate-500 mt-2">
                    <?= t('feedback_multiple'); ?>
                </p>

            </div>


            <form method="POST" class="space-y-6">

                <?= csrf_field(); ?>


                <!-- Rating -->

                <div>

                    <label class="font-semibold text-slate-700 block mb-3">
                        <?= t('rating'); ?>
                    </label>

                    <div class="flex gap-3 text-3xl">

                        <?php for ($i = 1; $i <= 5; $i++): ?>

                            <label class="cursor-pointer">

                                <input
                                    type="radio"
                                    name="rating"
                                    value="<?= $i; ?>"
                                    class="hidden peer"
                                    required
                                >

                                <i class="fa-solid fa-star
                                    text-gray-300
                                    peer-checked:text-amber-400
                                    hover:text-amber-300
                                    transition">
                                </i>

                            </label>

                        <?php endfor; ?>

                    </div>

                </div>


                <!-- Comment -->

                <div>

                    <label class="font-semibold text-slate-700">
                        <?= t('comment_suggestion'); ?>
                    </label>

                    <textarea
                        name="comment"
                        rows="4"
                        maxlength="2000"
                        placeholder="<?= t('feedback_placeholder'); ?>"
                        class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                    ></textarea>

                </div>


                <!-- Anonymous Option -->

                <div class="bg-rmc-50/60 border border-rmc-200 rounded-2xl p-5">

                    <label class="flex items-start gap-3 cursor-pointer">

                        <input
                            type="checkbox"
                            name="is_anonymous"
                            value="1"
                            class="mt-1 w-5 h-5 rounded border-rmc-300 text-rmc-800 focus:ring-rmc-300"
                        >

                        <span>

                            <span class="font-semibold text-slate-800 block">
                                <?= t('anonymous'); ?>
                            </span>

                            <span class="text-sm text-slate-500">
                                <?= t('anonymous_desc'); ?>
                            </span>

                        </span>

                    </label>

                </div>


                <!-- Submit -->

                <button
                    type="submit"
                    name="submit_feedback"
                    value="1"
                    class="bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
                >

                    <i class="fa-solid fa-paper-plane"></i>

                    <?= t('submit_feedback'); ?>

                </button>

            </form>

        </div>

    <?php else: ?>

        <div
            class="bg-amber-50 border border-amber-200 text-amber-800 rounded-3xl px-6 py-5 mb-8 flex items-start gap-3 animate-up delay-1"
        >

            <i class="fa-solid fa-circle-info mt-1"></i>

            <span>
                <?= t('feedback_attended_hint'); ?>
            </span>

        </div>

    <?php endif; ?>

<?php endif; ?>


<!-- =========================================================
     ALL FEEDBACK
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-2">

    <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-3">

        <span
            class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
        >

            <i class="fa-solid fa-comments"></i>

        </span>

        <?= t('all_feedback'); ?>

    </h2>

    <?php if (pg_num_rows($all_feedback) === 0): ?>

        <div class="text-center py-12 text-slate-400">

            <div
                class="w-14 h-14 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4"
            >

                <i class="fa-regular fa-comment-dots text-2xl"></i>

            </div>

            <p class="text-slate-500">
                <?= t('no_feedback_yet'); ?>
            </p>

        </div>

    <?php else: ?>

        <div class="space-y-5">

            <?php while ($fb = pg_fetch_assoc($all_feedback)): ?>

                <div class="border border-slate-100 rounded-2xl p-5 bg-slate-50/50">

                    <div class="flex items-center justify-between mb-2 gap-4 flex-wrap">

                        <span class="font-semibold text-slate-800">

                            <?php if ($fb['is_anonymous'] === 't' || $fb['is_anonymous'] === true): ?>

                                <i class="fa-solid fa-user-secret mr-1 text-slate-500"></i>

                                <?= t('anonymous_name'); ?>

                            <?php else: ?>

                                <?= htmlspecialchars($fb['full_name']); ?>

                            <?php endif; ?>

                        </span>

                        <span class="text-amber-400 whitespace-nowrap">

                            <?php for ($i = 1; $i <= 5; $i++): ?>

                                <i class="fa-solid fa-star <?= $i <= (int) $fb['rating'] ? '' : 'text-slate-200'; ?>"></i>

                            <?php endfor; ?>

                        </span>

                    </div>

                    <?php if (!empty($fb['comment'])): ?>

                        <p class="text-slate-600 leading-relaxed">
                            <?= htmlspecialchars($fb['comment']); ?>
                        </p>

                    <?php endif; ?>

                    <p class="text-xs text-slate-400 mt-3">

                        <?= date(
                            "M j, Y g:i A",
                            strtotime($fb['created_at'])
                        ); ?>

                    </p>

                </div>

            <?php endwhile; ?>

        </div>

    <?php endif; ?>

</div>


<?php include 'partials/footer.php'; ?>
