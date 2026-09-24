<?php
include 'db_connect.php';
require 'lang.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$event_id = $_GET['id'] ?? null;
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$event_stmt = $pdo->prepare("SELECT * FROM events WHERE event_id=?");
$event_stmt->execute([$event_id]);
$event = $event_stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Event not found.");
}

$photos = $pdo->prepare("SELECT * FROM event_photos WHERE event_id=? ORDER BY uploaded_at DESC");
$photos->execute([$event_id]);


/* =========================================================
   UNREAD COUNT + RECENT NOTIFICATIONS (shared header)
   ========================================================= */

$unread_stmt = $pdo->prepare("SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ?
           AND is_read = 0");
$unread_stmt->execute([$user_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_notifications->execute([$user_id]);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = ucfirst($role);

if ($role === 'admin') {
    $role_label = 'Administrator';
} elseif ($role === 'organizer') {
    $role_label = 'Event Organizer';
}

$page_title  = t('title_photos');
$active_page = '';
?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     GALLERY HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-start gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-images text-2xl"></i>

        </div>

        <div class="min-w-0">

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight break-words">
                <?= htmlspecialchars($event['title']); ?>
            </h2>

            <p class="text-slate-600 mt-2 text-sm sm:text-base flex flex-wrap items-center gap-x-4 gap-y-1">

                <span class="inline-flex items-center gap-2">
                    <i class="fa-solid fa-calendar text-rmc-800"></i>
                    <?= htmlspecialchars($event['event_date']); ?>
                </span>

                <span class="inline-flex items-center gap-2">
                    <i class="fa-solid fa-location-dot text-rmc-800"></i>
                    <?= htmlspecialchars($event['venue']); ?>
                </span>

            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     PHOTO GRID
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-5 sm:p-8 animate-up delay-1">

    <?php if ($photos->rowCount() === 0): ?>

        <div class="text-center py-16 px-4 text-slate-400">

            <div
                class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-5"
            >

                <i class="fa-regular fa-image text-3xl"></i>

            </div>

            <p class="text-slate-500">
                <?= t('no_photos_uploaded'); ?>
            </p>

        </div>

    <?php else: ?>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5">

            <?php while ($photo = $photos->fetch(PDO::FETCH_ASSOC)): ?>

                <a
                        href="<?= htmlspecialchars(rmc_gallery_image_path($photo['image_path'])); ?>"
                    target="_blank"
                    class="group rounded-2xl overflow-hidden border border-slate-200 bg-slate-50 block"
                >

                    <img
                        src="<?= htmlspecialchars(rmc_gallery_image_path($photo['image_path'])); ?>"
                        class="w-full h-36 sm:h-44 object-cover group-hover:scale-105 group-hover:opacity-80 transition duration-300"
                        loading="lazy"
                    >

                </a>

            <?php endwhile; ?>

        </div>

    <?php endif; ?>

</div>


<?php include 'partials/footer.php'; ?>