<?php

session_start();

include 'db_connect.php';
require 'lang.php';
require 'csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    http_response_code(403);
    die("Access denied. Organizers only.");
}

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$event_id = $_GET['id'] ?? null;
$organizer_id = $_SESSION['user_id'];

// Make sure this event actually belongs to the logged-in organizer
$owner_check = pg_query_params(
    $conn,
    "SELECT event_id
     FROM events
     WHERE event_id=$1
     AND organizer_id=$2",
    array($event_id, $organizer_id)
);

if (pg_num_rows($owner_check) === 0) {
    http_response_code(403);
    die("Access denied. You can only edit your own events.");
}

// How many students are already registered - the limit can't go below this
$current_regs = pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM registrations
         WHERE event_id=$1",
        array($event_id)
    ),
    0,
    0
);

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF protection
    csrf_verify();

    $title = $_POST['title'];
    $description = $_POST['description'];
    $category = $_POST['category'];
    $event_date = $_POST['event_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $venue = $_POST['venue'];
    $registration_limit = (int) $_POST['registration_limit'];

    if ($registration_limit < $current_regs) {

        $error = sprintf(t('limit_below_current'), $current_regs);

    } elseif ($end_time <= $start_time) {

        $error = t('end_after_start');

    } elseif ($event_date < date('Y-m-d')) {

        $error = t('date_not_past');

    } else {

        /* Handle poster upload */
        $poster_image = $event['poster_image'];

        if (isset($_FILES['poster_image']) && $_FILES['poster_image']['error'] === UPLOAD_ERR_OK) {

            $file_tmp  = $_FILES['poster_image']['tmp_name'];
            $file_size = $_FILES['poster_image']['size'];
            $file_type = $_FILES['poster_image']['type'];

            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024;

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($file_tmp);

            if (!in_array($detected, $allowed_types)) {
                $error = 'Invalid file type. Please upload a JPG, PNG, or WebP image.';
            } elseif ($file_size > $max_size) {
                $error = 'File too large. Maximum size is 5 MB.';
            } else {

                $ext = match($detected) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    default      => 'png'
                };

                $new_filename = 'poster_' . bin2hex(random_bytes(16)) . '.' . $ext;
                $upload_dir = 'img/';
                $dest = $upload_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $dest)) {

                    // Delete old poster if exists
                    if (!empty($event['poster_image'])) {
                        $old_path = $upload_dir . $event['poster_image'];
                        if (file_exists($old_path)) {
                            @unlink($old_path);
                        }
                    }

                    $poster_image = $new_filename;

                } else {
                    $error = 'Failed to save uploaded image.';
                }
            }
        }

        if (empty($error)) {
            pg_query_params(
                $conn,

                "UPDATE events
                 SET title=$1,
                     description=$2,
                     category=$3,
                     event_date=$4,
                     start_time=$5,
                     end_time=$6,
                     venue=$7,
                     registration_limit=$8,
                     poster_image=$9
                 WHERE event_id=$10
                 AND organizer_id=$11",

                array(
                    $title,
                    $description,
                    $category,
                    $event_date,
                    $start_time,
                    $end_time,
                    $venue,
                    $registration_limit,
                    $poster_image,
                    $event_id,
                    $organizer_id
                )
            );

            header("Location: dashboard.php");
            exit();
        }
    }
}

$result = pg_query_params(
    $conn,
    "SELECT *
     FROM events
     WHERE event_id=$1",
    array($event_id)
);

$event = pg_fetch_assoc($result);


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
        array($_SESSION['user_id'])
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
    array($_SESSION['user_id'])
);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = 'Event Organizer';

$page_title  = t('title_edit_event');
$active_page = '';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     EDIT EVENT HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-pen-to-square text-2xl"></i>

    </div>

    <div class="min-w-0">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            <?= t('update_event_info'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base">
            <?= t('edit_event_hero_desc'); ?>
        </p>

    </div>

</div>


<!-- =========================================================
     EDIT EVENT FORM
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 max-w-5xl mx-auto animate-up delay-1">

    <?php if ($error): ?>

        <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6">

            <i class="fa-solid fa-circle-exclamation mr-2"></i>

            <?= htmlspecialchars($error); ?>

        </div>

    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-6">

        <?= csrf_field(); ?>

        <div>

            <label class="font-semibold text-slate-700">
                <?= t('event_title'); ?>
            </label>

            <input
                type="text"
                name="title"
                value="<?= htmlspecialchars($event['title']); ?>"
                required
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('description'); ?>
            </label>

            <textarea
                name="description"
                rows="5"
                required
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            ><?= htmlspecialchars($event['description']); ?></textarea>

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('category'); ?>
            </label>

            <input
                type="text"
                name="category"
                value="<?= htmlspecialchars($event['category']); ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                Event Poster
            </label>

            <?php if (!empty($event['poster_image']) && file_exists('img/' . $event['poster_image'])): ?>

                <div class="mt-3 mb-3">
                    <p class="text-sm text-slate-500 mb-2">Current poster:</p>
                    <img
                        src="img/<?= htmlspecialchars($event['poster_image']); ?>"
                        alt="Current poster"
                        class="w-48 h-auto rounded-xl border border-slate-200 shadow-sm"
                    >
                </div>

            <?php endif; ?>

            <input
                type="file"
                name="poster_image"
                accept="image/jpeg,image/png,image/webp"
                class="w-full mt-2 text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-rmc-100 file:text-rmc-800 hover:file:bg-rmc-200 file:cursor-pointer transition"
            >

            <p class="text-xs text-slate-400 mt-2">
                Recommended: 1200 x 630 px &bull; Max: 5 MB &bull; JPG, PNG, WebP
            </p>

        </div>


        <div class="grid md:grid-cols-3 gap-5">

            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('event_date'); ?>
                </label>

                <input
                    type="date"
                    name="event_date"
                    value="<?= htmlspecialchars($event['event_date']); ?>"
                    required
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('start_time'); ?>
                </label>

                <input
                    type="time"
                    name="start_time"
                    value="<?= htmlspecialchars($event['start_time']); ?>"
                    required
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('end_time'); ?>
                </label>

                <input
                    type="time"
                    name="end_time"
                    value="<?= htmlspecialchars($event['end_time']); ?>"
                    required
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('venue'); ?>
            </label>

            <input
                type="text"
                name="venue"
                value="<?= htmlspecialchars($event['venue']); ?>"
                required
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('registration_limit'); ?>
            </label>

            <input
                type="number"
                name="registration_limit"
                value="<?= htmlspecialchars($event['registration_limit']); ?>"
                min="<?= $current_regs; ?>"
                required
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <p class="text-sm text-slate-500 mt-2">

                <?= $current_regs; ?>
                <?= $current_regs == 1 ? t('student_singular') : t('students'); ?>
                <?= t('currently_registered'); ?>

                <?= t('limit_not_below'); ?>

            </p>

        </div>


        <div class="flex flex-col sm:flex-row justify-end gap-4 pt-4">

            <a
                href="dashboard.php"
                class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-white border border-rmc-200 text-rmc-800 hover:bg-rmc-50 font-semibold transition"
            >

                <i class="fa-solid fa-arrow-left"></i>

                <?= t('back'); ?>

            </a>

            <button
                type="submit"
                class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition"
            >

                <i class="fa-solid fa-floppy-disk"></i>

                <?= t('save_changes'); ?>

            </button>

        </div>

    </form>

</div>


<?php include 'partials/footer.php'; ?>
