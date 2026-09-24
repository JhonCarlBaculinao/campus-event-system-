<?php
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

function rmc_event_photo_web_path($path) {
    $path = ltrim((string) $path, '/');
    if ($path === '') return '';
    if (strpos($path, 'assets/uploads/events/') === 0) return $path;
    if (strpos($path, 'img/') === 0) return $path;
    return 'img/' . basename($path);
}

function rmc_event_photo_file_path($path) {
    $web = rmc_event_photo_web_path($path);
    if ($web === '') return '';
    return __DIR__ . '/' . $web;
}

// Confirm this event belongs to the logged-in organizer
$owner_check = $pdo->prepare("SELECT * FROM events WHERE event_id=? AND organizer_id=? LIMIT 1");
$owner_check->execute([$event_id, $organizer_id]);
$owned_event = $owner_check->fetch(PDO::FETCH_ASSOC);
if (!$owned_event) {
    http_response_code(403);
    die("Access denied. You can only manage photos for your own events.");
}
$event = $owned_event;

$error = '';
$success = '';

// ---- CSRF guard for all POST actions (upload / delete) ----
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
}

// ---- Upload new photo(s) ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['photos'])) {
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $max_size = 5 * 1024 * 1024;
    $uploaded_count = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($_FILES['photos']['tmp_name'] as $i => $tmp_name) {
        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ($_FILES['photos']['size'][$i] > $max_size) {
            continue;
        }

        $detected_mime = $finfo->file($tmp_name);
        if (!in_array($detected_mime, $allowed_mimes, true)) {
            continue;
        }

        if (@getimagesize($tmp_name) === false) {
            continue;
        }

        $file_ext = $mime_to_ext[$detected_mime];
        $new_filename = bin2hex(random_bytes(16)) . '.' . $file_ext;
        $upload_path = 'img/' . $new_filename;
        if (move_uploaded_file($tmp_name, $upload_path)) {
            $insert_photo = $pdo->prepare("INSERT INTO event_photos (event_id, image_path) VALUES (?, ?)");
            $insert_photo->execute([$event_id, $new_filename]);
            $uploaded_count++;
        }
    }

    if ($uploaded_count > 0) {
        $success = sprintf(t('photos_uploaded_msg'), $uploaded_count);
    } else {
        $error = t('no_valid_photos');
    }
}

// ---- Delete a photo ----
if (isset($_POST['delete_photo_id'])) {
    $photo_stmt = $pdo->prepare("SELECT * FROM event_photos WHERE photo_id=? AND event_id=?");
    $photo_stmt->execute([$_POST['delete_photo_id'], $event_id]);
    $photo = $photo_stmt->fetch(PDO::FETCH_ASSOC);

    if ($photo) {
        $file_path = rmc_event_photo_file_path($photo['image_path']);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        $delete_stmt = $pdo->prepare("DELETE FROM event_photos WHERE photo_id=?");
        $delete_stmt->execute([$_POST['delete_photo_id']]);
        $success = t('photo_deleted_msg');
    }
}

// ---- Bulk delete photos ----
if (isset($_POST['bulk_delete_ids']) && is_array($_POST['bulk_delete_ids'])) {
    $ids = array_filter(array_map('intval', $_POST['bulk_delete_ids']), fn($id) => $id > 0);
    $deleted = 0;
    $failed = 0;
    foreach ($ids as $pid) {
        $photo_stmt = $pdo->prepare("SELECT * FROM event_photos WHERE photo_id=? AND event_id=?");
        $photo_stmt->execute([$pid, $event_id]);
        $photo = $photo_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$photo) { $failed++; continue; }
        $file_path = rmc_event_photo_file_path($photo['image_path']);
        if (!empty($photo['image_path']) && $file_path !== '' && is_file($file_path)) {
            unlink($file_path);
        }
        $delete_stmt = $pdo->prepare("DELETE FROM event_photos WHERE photo_id=?");
        $delete_stmt->execute([$pid]);
        $deleted++;
    }
    $success = sprintf(t('photos_deleted_msg'), $deleted);
    if ($failed > 0) { $success .= ' ' . sprintf(t('photos_not_found_msg'), $failed); }
}

$photos = $pdo->prepare("SELECT * FROM event_photos WHERE event_id=? ORDER BY uploaded_at DESC");
$photos->execute([$event_id]);


/* =========================================================
   UNREAD COUNT + RECENT NOTIFICATIONS (shared header)
   ========================================================= */

$unread_stmt = $pdo->prepare("SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ? AND is_read = false");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = (int)$unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 5");
$recent_notifications->execute([$_SESSION['user_id']]);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role = 'organizer';
$role_label = 'Event Organizer';

$page_title  = t('title_manage_photos');
$active_page = '';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     MANAGE PHOTOS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-images text-2xl"></i>

    </div>

    <div class="min-w-0 flex-1">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight break-words">
            <?= t('manage_event_photos'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base break-words">
            <?= htmlspecialchars($event['title']); ?>
        </p>

    </div>

    <a
            href="dashboard.php"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 font-semibold text-sm shrink-0 self-start sm:self-auto"
    >

        <i class="fa-solid fa-arrow-left"></i>

        <?= t('back'); ?>

    </a>

</div>


<!-- =========================================================
     ALERTS
     ========================================================= -->

<?php if ($error): ?>

    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6 animate-up">

        <i class="fa-solid fa-circle-exclamation mr-2"></i>

        <?= htmlspecialchars($error); ?>

    </div>

<?php endif; ?>

<?php if ($success): ?>

    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6 animate-up">

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($success); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     UPLOAD FORM — Drag & Drop
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-1">

    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">

        <h2 class="text-xl font-bold text-slate-900 flex items-center gap-3">

            <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                <i class="fa-solid fa-cloud-arrow-up"></i>

            </span>

            <?= t('upload_photos_btn'); ?>

        </h2>

        <button
            type="button"
            id="chooseFilesBtn"
            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition"
        >

            <i class="fa-solid fa-plus"></i>

            <?= t('upload_photos_btn'); ?>

        </button>

    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">

        <?= csrf_field(); ?>

        <div
            id="dropZone"
            class="border-2 border-dashed border-slate-300 rounded-2xl p-10 text-center cursor-pointer transition-all duration-300 hover:border-rmc-400 hover:bg-rmc-50/30"
        >

            <div class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-400 flex items-center justify-center mb-4 transition-colors duration-300 group-hover:bg-rmc-100">

                <i class="fa-solid fa-cloud-arrow-up text-3xl" id="dropIcon"></i>

            </div>

            <p class="text-slate-700 font-semibold text-lg mb-1" id="dropText">
                <?= t('upload_photos_btn'); ?>
            </p>

            <p class="text-slate-400 text-sm" id="dropHint">
                <?= t('photos_multiple_hint'); ?>
            </p>

            <p class="text-xs text-slate-400 mt-3">
                JPG, PNG, WebP &middot; Max 5 MB each
            </p>

            <input
                type="file"
                name="photos[]"
                accept="image/jpeg,image/png,image/webp"
                multiple
                required
                id="fileInput"
                class="hidden"
            >

        </div>

        <div id="filePreview" class="hidden mt-4 space-y-2"></div>

        <button
            type="submit"
            id="uploadBtn"
            class="hidden mt-4 bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
        >

            <i class="fa-solid fa-cloud-arrow-up"></i>

            <span id="uploadBtnText"><?= t('upload'); ?></span>

        </button>

    </form>

</div>


<!-- =========================================================
     UPLOADED PHOTOS
     ========================================================= -->

<?php $photo_count = $photos->rowCount(); ?>

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-2">

    <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-3">

        <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

            <i class="fa-solid fa-image"></i>

        </span>

        <?= t('uploaded_photos'); ?>

        <?php if ($photo_count > 0): ?>
            <span class="ml-auto bg-rmc-100 text-rmc-800 text-sm font-bold px-3 py-1 rounded-full">
                <?= $photo_count; ?>
            </span>
        <?php endif; ?>

    </h2>

    <?php if ($photo_count === 0): ?>

        <div class="text-center py-14 px-4 text-slate-400">

            <div class="w-14 h-14 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                <i class="fa-regular fa-image text-2xl"></i>

            </div>

            <p class="text-slate-500">
                <?= t('no_photos_uploaded'); ?>
            </p>

        </div>

    <?php else: ?>

        <form method="POST" id="bulkDeletePhotoForm">
            <?= csrf_field(); ?>

            <div class="flex items-center gap-3 mb-5 flex-wrap">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 cursor-pointer">
                    <input type="checkbox" id="selectAllPhotos" class="bulk-select-all w-4 h-4 rounded border-slate-300 text-rmc-800 focus:ring-rmc-500 cursor-pointer" aria-label="<?= t('select_all'); ?>" data-target="photo-row-cb">
                    <?= t('select_all'); ?>
                </label>

                <div id="bulkDeleteBar" class="hidden flex-1 flex items-center gap-3 ml-auto">
                    <span class="text-sm font-semibold text-red-700"><span id="bulkDeleteCount">0</span> <?= t('selected_count'); ?></span>
                    <button type="button" onclick="openConfirmModal({bulkForm: document.getElementById('bulkDeletePhotoForm'), title: <?= htmlspecialchars(json_encode(t('delete_photo')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('bulk_delete_photos_confirm') ?: 'Delete selected photos? This cannot be undone.'), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(t('selected_photos')), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('photo')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('delete_photo')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-trash'});"
                        class="inline-flex items-center gap-2 bg-red-700 hover:bg-red-800 text-white px-4 py-2 rounded-xl text-sm font-semibold transition">
                        <i class="fa-solid fa-trash"></i>
                        <?= t('delete_photo'); ?>
                    </button>
                </div>
            </div>

        </form>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5">

            <?php $photo_idx = 0; while ($photo = $photos->fetch(PDO::FETCH_ASSOC)): ?>

                <div class="photo-card relative group rounded-2xl overflow-hidden border border-slate-200 bg-slate-50 transition-all duration-300 hover:shadow-xl hover:shadow-slate-200/60 hover:-translate-y-1 hover:border-rmc-200 cursor-pointer"
                     data-idx="<?= $photo_idx; ?>"
                     data-src="<?= htmlspecialchars(rmc_event_photo_web_path($photo['image_path'])); ?>"
                     data-date="<?= htmlspecialchars($photo['uploaded_at']); ?>"
                     onclick="if(!event.target.closest('label') && !event.target.closest('form')) openLightbox(<?= $photo_idx; ?>);"
                >

                    <label class="absolute top-2 left-2 z-10">
                        <input type="checkbox" name="bulk_delete_ids[]" value="<?= $photo['photo_id']; ?>" form="bulkDeletePhotoForm" class="bulk-cb photo-row-cb w-4 h-4 rounded border-slate-300 text-rmc-800 focus:ring-rmc-500 cursor-pointer bg-white/90" aria-label="<?= t('select_photo'); ?>">
                    </label>

                    <div class="relative overflow-hidden">
                        <img
                            src="<?= htmlspecialchars(rmc_event_photo_web_path($photo['image_path'])); ?>"
                            class="w-full h-36 sm:h-44 object-cover transition-transform duration-500 group-hover:scale-110"
                            loading="lazy"
                        >

                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end p-3">
                            <div class="flex items-center gap-1.5 text-white text-xs font-medium">
                                <i class="fa-solid fa-expand"></i>
                                <span>View</span>
                            </div>
                        </div>
                    </div>

                    <div class="px-3 py-2.5 border-t border-slate-100">
                        <p class="text-[11px] text-slate-400 truncate flex items-center gap-1">
                            <i class="fa-regular fa-clock"></i>
                            <?= date('M d, Y g:i A', strtotime($photo['uploaded_at'])); ?>
                        </p>
                    </div>

                    <form
                        method="POST"
                        class="absolute top-2 right-2 z-10 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                    >

                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="delete_photo_id"
                            value="<?= $photo['photo_id']; ?>"
                        >

                        <button
                            type="button"
                            class="bg-red-600 hover:bg-red-700 text-white w-9 h-9 rounded-full text-sm flex items-center justify-center shadow-lg transition"
                            onclick="event.stopPropagation(); openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('delete_photo')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('confirm_delete')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(t('photo')), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('photo')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('delete_photo')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-trash'});"
                            aria-label="<?= t('delete_photo'); ?>"
                        >

                            <i class="fa-solid fa-trash"></i>

                        </button>

                    </form>

                </div>

            <?php $photo_idx++; endwhile; ?>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     LIGHTBOX MODAL
     ========================================================= -->

<div id="lightboxModal" class="hidden fixed inset-0 z-[90] bg-black/95 backdrop-blur-sm flex items-center justify-center" role="dialog" aria-modal="true" aria-label="Photo viewer">

    <button
        type="button"
        onclick="closeLightbox();"
        class="absolute top-4 right-4 z-[95] text-white/70 hover:text-white w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition"
        aria-label="Close"
    >
        <i class="fa-solid fa-xmark text-xl"></i>
    </button>

    <button
        type="button"
        id="lbPrev"
        onclick="navigateLightbox(-1);"
        class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 z-[95] text-white/70 hover:text-white w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition"
        aria-label="Previous"
    >
        <i class="fa-solid fa-chevron-left"></i>
    </button>

    <button
        type="button"
        id="lbNext"
        onclick="navigateLightbox(1);"
        class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 z-[95] text-white/70 hover:text-white w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition"
        aria-label="Next"
    >
        <i class="fa-solid fa-chevron-right"></i>
    </button>

    <div class="flex flex-col items-center max-w-[90vw] max-h-[90vh]">
        <img
            id="lbImage"
            src=""
            class="max-h-[80vh] max-w-[90vw] object-contain rounded-lg transition-opacity duration-300"
            alt="Photo"
        >
        <div class="mt-3 text-center">
            <p id="lbCounter" class="text-white/60 text-sm font-medium"></p>
            <p id="lbDate" class="text-white/40 text-xs mt-1"></p>
        </div>
    </div>

</div>


<!-- CONFIRMATION MODAL -->
<div id="confirmModal" class="hidden fixed inset-0 z-[80] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4" onclick="if (event.target === this) closeConfirmModal();" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
    <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl overflow-hidden" id="confirmModalInner">
        <div id="confirmModalHeader" class="bg-red-700 text-white px-6 py-5 flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shrink-0">
                <i id="confirmModalIcon" class="fa-solid fa-trash text-lg"></i>
            </div>
            <div class="min-w-0">
                <h3 id="confirmModalTitle" class="font-bold text-lg leading-snug"></h3>
            </div>
        </div>
        <div class="p-6">
            <p id="confirmModalMessage" class="text-sm text-slate-600 leading-relaxed"></p>
            <div id="confirmModalItemBox" class="mt-4 bg-red-50 border border-red-200 rounded-2xl px-4 py-3">
                <p id="confirmModalItemLabel" class="text-[10px] font-bold uppercase tracking-wide text-red-500 mb-1"></p>
                <p id="confirmModalItemName" class="font-bold text-slate-900 break-words">—</p>
            </div>
        </div>
        <div class="px-6 pb-6 flex flex-col-reverse sm:flex-row gap-3">
            <button type="button" onclick="closeConfirmModal();" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-5 py-3 rounded-xl transition"><?= htmlspecialchars(t('cancel')); ?></button>
            <button type="button" id="confirmModalConfirmBtn" class="flex-1 bg-red-700 hover:bg-red-800 text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2">
                <i id="confirmModalBtnIcon" class="fa-solid fa-trash"></i>
                <span id="confirmModalBtnText"></span>
            </button>
        </div>
    </div>
</div>

<script>
var CONFIRM_PENDING_FORM = null;
var COLOR_MAP = {
    red: { header: 'bg-red-700', itemBg: 'bg-red-50', itemBorder: 'border-red-200', itemLabel: 'text-red-500', btn: 'bg-red-700 hover:bg-red-800' },
    emerald: { header: 'bg-emerald-700', itemBg: 'bg-emerald-50', itemBorder: 'border-emerald-200', itemLabel: 'text-emerald-500', btn: 'bg-emerald-700 hover:bg-emerald-800' },
    slate: { header: 'bg-slate-700', itemBg: 'bg-slate-50', itemBorder: 'border-slate-200', itemLabel: 'text-slate-500', btn: 'bg-slate-700 hover:bg-slate-800' }
};
function openConfirmModal(opts) {
    CONFIRM_PENDING_FORM = opts.form || opts.bulkForm || null;
    var c = COLOR_MAP[opts.color] || COLOR_MAP.red;
    document.getElementById('confirmModalHeader').className = c.header + ' text-white px-6 py-5 flex items-center gap-3';
    document.getElementById('confirmModalIcon').className = (opts.icon || 'fa-solid fa-trash') + ' text-lg';
    document.getElementById('confirmModalTitle').textContent = opts.title || '';
    document.getElementById('confirmModalMessage').textContent = opts.message || '';
    var itemBox = document.getElementById('confirmModalItemBox');
    itemBox.className = 'mt-4 ' + c.itemBg + ' border ' + c.itemBorder + ' rounded-2xl px-4 py-3';
    document.getElementById('confirmModalItemLabel').className = 'text-[10px] font-bold uppercase tracking-wide ' + c.itemLabel + ' mb-1';
    document.getElementById('confirmModalItemLabel').textContent = opts.itemLabel || '';
    document.getElementById('confirmModalItemName').textContent = opts.itemName || '—';
    var btn = document.getElementById('confirmModalConfirmBtn');
    btn.className = 'flex-1 ' + c.btn + ' text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2';
    document.getElementById('confirmModalBtnIcon').className = (opts.icon || 'fa-solid fa-trash');
    document.getElementById('confirmModalBtnText').textContent = opts.actionText || '';
    document.getElementById('confirmModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeConfirmModal() {
    document.getElementById('confirmModal').classList.add('hidden');
    document.body.style.overflow = '';
    CONFIRM_PENDING_FORM = null;
}
document.getElementById('confirmModalConfirmBtn').addEventListener('click', function() {
    if (CONFIRM_PENDING_FORM) { CONFIRM_PENDING_FORM.submit(); }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeConfirmModal(); }
});

/* ===== Bulk Select — Photos ===== */
(function() {
    var selectAll = document.getElementById('selectAllPhotos');
    var bar = document.getElementById('bulkDeleteBar');
    var counter = document.getElementById('bulkDeleteCount');
    var cbs = document.querySelectorAll('.photo-row-cb');

    if (!selectAll || cbs.length === 0) return;

    function sync() {
        var checked = document.querySelectorAll('.photo-row-cb:checked').length;
        counter.textContent = checked;
        if (bar) bar.classList.toggle('hidden', checked === 0);
        selectAll.checked = checked > 0 && checked === cbs.length;
        selectAll.indeterminate = checked > 0 && checked < cbs.length;
    }

    selectAll.addEventListener('change', function() {
        cbs.forEach(function(cb) { cb.checked = selectAll.checked; });
        sync();
    });

    cbs.forEach(function(cb) { cb.addEventListener('change', sync); });
    sync();
})();

/* ===== Drag & Drop Upload Zone ===== */
(function() {
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('fileInput');
    var filePreview = document.getElementById('filePreview');
    var uploadBtn = document.getElementById('uploadBtn');
    var uploadBtnText = document.getElementById('uploadBtnText');
    var dropIcon = document.getElementById('dropIcon');
    var dropText = document.getElementById('dropText');
    var chooseFilesBtn = document.getElementById('chooseFilesBtn');
    if (!dropZone) return;

    dropZone.addEventListener('click', function() { fileInput.click(); });

    if (chooseFilesBtn) {
        chooseFilesBtn.addEventListener('click', function() { fileInput.click(); });
    }

    dropZone.addEventListener('dragenter', function(e) { e.preventDefault(); dropZone.classList.add('border-rmc-500', 'bg-rmc-50/50'); dropIcon.classList.add('text-rmc-600'); dropText.classList.add('text-rmc-700'); });
    dropZone.addEventListener('dragover', function(e) { e.preventDefault(); });
    dropZone.addEventListener('dragleave', function(e) { e.preventDefault(); dropZone.classList.remove('border-rmc-500', 'bg-rmc-50/50'); dropIcon.classList.remove('text-rmc-600'); dropText.classList.remove('text-rmc-700'); });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('border-rmc-500', 'bg-rmc-50/50');
        dropIcon.classList.remove('text-rmc-600');
        dropText.classList.remove('text-rmc-700');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            showPreviews(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', function() {
        if (fileInput.files.length) showPreviews(fileInput.files);
    });

    function showPreviews(files) {
        filePreview.innerHTML = '';
        filePreview.classList.remove('hidden');
        uploadBtn.classList.remove('hidden');
        var count = files.length;
        uploadBtnText.textContent = 'Upload ' + count + ' Photo' + (count > 1 ? 's' : '');
        Array.from(files).forEach(function(f, i) {
            if (!f.type.match(/^image\/(jpeg|png|webp)$/)) return;
            var row = document.createElement('div');
            row.className = 'flex items-center gap-3 bg-slate-50 rounded-xl px-3 py-2 border border-slate-200';
            var thumb = document.createElement('div');
            thumb.className = 'w-12 h-12 rounded-lg overflow-hidden bg-slate-200 shrink-0';
            var img = document.createElement('img');
            img.className = 'w-full h-full object-cover';
            img.src = URL.createObjectURL(f);
            thumb.appendChild(img);
            var info = document.createElement('div');
            info.className = 'min-w-0 flex-1';
            info.innerHTML = '<p class="text-sm font-medium text-slate-700 truncate">' + f.name + '</p><p class="text-xs text-slate-400">' + formatBytes(f.size) + '</p>';
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'text-slate-400 hover:text-red-500 transition shrink-0';
            remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            remove.onclick = function() {
                row.remove();
                count--;
                if (count === 0) { filePreview.classList.add('hidden'); uploadBtn.classList.add('hidden'); fileInput.value = ''; uploadBtnText.textContent = 'Upload'; }
                else { uploadBtnText.textContent = 'Upload ' + count + ' Photo' + (count > 1 ? 's' : ''); }
            };
            row.appendChild(thumb);
            row.appendChild(info);
            row.appendChild(remove);
            filePreview.appendChild(row);
        });
    }

    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
        return (b/1048576).toFixed(1) + ' MB';
    }
})();

/* ===== Lightbox ===== */
var lightboxPhotos = [];
var lightboxIndex = 0;

(function() {
    var cards = document.querySelectorAll('.photo-card');
    cards.forEach(function(card) {
        lightboxPhotos.push({ src: card.dataset.src, date: card.dataset.date });
    });
})();

function openLightbox(idx) {
    lightboxIndex = idx;
    var modal = document.getElementById('lightboxModal');
    var img = document.getElementById('lbImage');
    img.style.opacity = '0';
    img.src = lightboxPhotos[idx].src;
    img.onload = function() { img.style.opacity = '1'; };
    document.getElementById('lbCounter').textContent = (idx + 1) + ' / ' + lightboxPhotos.length;
    document.getElementById('lbDate').textContent = lightboxPhotos[idx].date;
    document.getElementById('lbPrev').classList.toggle('hidden', lightboxPhotos.length <= 1);
    document.getElementById('lbNext').classList.toggle('hidden', lightboxPhotos.length <= 1);
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('lightboxModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function navigateLightbox(dir) {
    lightboxIndex += dir;
    if (lightboxIndex < 0) lightboxIndex = lightboxPhotos.length - 1;
    if (lightboxIndex >= lightboxPhotos.length) lightboxIndex = 0;
    openLightbox(lightboxIndex);
}

document.addEventListener('keydown', function(e) {
    var lb = document.getElementById('lightboxModal');
    if (lb.classList.contains('hidden')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') navigateLightbox(-1);
    if (e.key === 'ArrowRight') navigateLightbox(1);
});
</script>


<?php include 'partials/footer.php'; ?>