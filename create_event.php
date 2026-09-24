<?php

include 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require 'send_email.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    http_response_code(403);
    die("Access denied. Organizers only.");
}

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];
$role = 'organizer';
$role_label = 'Event Organizer';

$submitted_events_stmt = $pdo->prepare("SELECT event_id, title, event_date, venue, status FROM events WHERE organizer_id = ? ORDER BY event_date DESC, event_id DESC");
$submitted_events_stmt->execute([$_SESSION['user_id']]);
$submitted_events = $submitted_events_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $venue = trim($_POST['venue'] ?? '');
    $registration_limit = (int)($_POST['registration_limit'] ?? 0);

    $organizer_id = $_SESSION['user_id'];

    /* =========================================
       VALIDATE INPUT
       ========================================= */

    $errors = [];

    if (empty($title)) {
        $errors[] = 'Title is required';
    }
    if (empty($description)) {
        $errors[] = 'Description is required';
    }
    if (empty($category)) {
        $errors[] = 'Category is required';
    }
    if (empty($event_date)) {
        $errors[] = 'Event date is required';
    }
    if (empty($start_time)) {
        $errors[] = 'Start time is required';
    }
    if (empty($end_time)) {
        $errors[] = 'End time is required';
    }
    if (empty($venue)) {
        $errors[] = 'Venue is required';
    }
    if ($registration_limit < 1) {
        $errors[] = 'Registration limit must be at least 1';
    }

    /* =========================================
       VALIDATE + HANDLE PHOTO UPLOAD
       ========================================= */

    $image_path = null;
    $has_uploaded_file = isset($_FILES['event_photo']) && $_FILES['event_photo']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($has_uploaded_file) {

        $file = $_FILES['event_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {

            $errors[] = 'There was a problem uploading the photo. Please try again.';

        } else {

            $allowed_types = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $max_size_bytes = 5 * 1024 * 1024; // 5MB

            if (!isset($allowed_types[$detected_mime])) {

                $errors[] = 'Photo must be a JPG, PNG, or WEBP image.';

            } elseif ($file['size'] > $max_size_bytes) {

                $errors[] = 'Photo must be smaller than 5MB.';

            } else {

                $upload_dir = __DIR__ . '/img/';

                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                    $errors[] = 'Failed to prepare the image upload directory.';
                }

                $extension = $allowed_types[$detected_mime];
                $unique_filename = 'event_' . bin2hex(random_bytes(16)) . '.' . $extension;
                $destination = $upload_dir . $unique_filename;

                if (empty($errors) && move_uploaded_file($file['tmp_name'], $destination)) {
                    // Store only the filename; all event images live under img/.
                    $image_path = $unique_filename;
                } else {
                    $errors[] = 'Failed to save the uploaded photo. Please try again.';
                }
            }
        }
    }

    if (empty($errors)) {

        /* =========================================
           CREATE EVENT
           (uses poster_image, the real column name
           in the events table)
           ========================================= */

        $stmt = $pdo->prepare("INSERT INTO events (title, description, category, event_date, start_time, end_time, venue, registration_limit, poster_image, organizer_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$title, $description, $category, $event_date, $start_time, $end_time, $venue, $registration_limit, $image_path, $organizer_id]);

        $event_id = $pdo->lastInsertId();

        /* =========================================
           ALSO SAVE PHOTO TO event_photos
           (gallery table, separate from poster_image)
           ========================================= */

        if ($image_path !== null) {
            $pdo->prepare("INSERT INTO event_photos (event_id, image_path, uploaded_at) VALUES (?, ?, NOW())")
                ->execute([$event_id, $image_path]);
        }

        /* =========================================
           NOTIFY ORGANIZER
           ========================================= */

        $organizer_message = 'Your event "' . $title . '" has been submitted for approval.';

        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)")->execute([$organizer_id, $organizer_message, 'event_submission']);

        /* Organizer Gmail */

        $org_query = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $org_query->execute([$organizer_id]);
        $org = $org_query->fetch(PDO::FETCH_ASSOC);

        if (!empty($org['email'])) {
            send_notification_email(
                $org['email'],
                'Event Submission',
                '<h2>Event Submission</h2>' .
                '<p>Hello <strong>' . htmlspecialchars($first_name) . '</strong>!</p>' .
                '<p>Your event <strong>' . htmlspecialchars($title) . '</strong> has been submitted successfully and is waiting for administrator approval.</p>'
            );
        }

        /* =========================================
           NOTIFY ALL ADMINS
           ========================================= */

        $admins_query = $pdo->prepare("SELECT user_id, email, full_name FROM users WHERE role = 'admin'");
        $admins_query->execute();
        $admins = $admins_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)")->execute([$admin['user_id'], 'Event "' . $title . '" has been submitted by ' . $full_name . ' and is waiting for approval.', 'event_pending']);

            if (!empty($admin['email'])) {
                send_notification_email(
                    $admin['email'],
                    'New Event Submission',
                    '<h2>New Event Submission</h2>' .
                    '<p>A new event <strong>' . htmlspecialchars($title) . '</strong> has been submitted by <strong>' . htmlspecialchars($full_name) . '</strong> and is waiting for approval.</p>' .
                    '<p><strong>Event:</strong> ' . htmlspecialchars($title) . '</p>' .
                    '<p><strong>Date:</strong> ' . htmlspecialchars($event_date) . '</p>' .
                    '<p><strong>Venue:</strong> ' . htmlspecialchars($venue) . '</p>' .
                    '<p>Please log in to the Campus Event Management System to review and approve.</p>'
                );
            }
        }

        $success = 'Event submitted successfully and is waiting for approval';

        /* Clear submitted values so the form resets after a successful save */
        $_POST = [];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Event System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.2.0/remixicon.min.css">
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css">
</head>
<body>

<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<?php include 'partials/header.php'; ?>

<!-- CREATE EVENT HERO -->
<div class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up">
    <div class="flex items-center gap-4 sm:gap-5">
        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0">
            <i class="fa-solid fa-calendar-plus text-2xl"></i>
        </div>
        <div>
            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Create Event
            </h2>
            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                Submit a new event for administrator approval
            </p>
        </div>
    </div>
</div>


<!-- SUCCESS / ERROR MESSAGES -->
<?php if (isset($success)): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-8 animate-up">
        <i class="fa-solid fa-circle-check mr-2"></i>
        <?php echo $success; ?>
    </div>
<?php endif; ?>

<?php if (isset($errors) && is_array($errors) && !empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-8 animate-up">
        <i class="fa-solid fa-circle-exclamation mr-2"></i>
        <ul class="text-left">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


<!-- CREATE EVENT FORM -->
<div class="bg-white rounded-[26px] border border-slate-200 p-8 sm:p-10 animate-up">
    <h2 class="text-2xl font-bold text-slate-900 mb-6">Create New Event</h2>

    <form method="POST" action="create_event.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <input type="hidden" name="create_event" value="1">

        <div class="grid md:grid-cols-2 gap-6 mb-6">
            <div>
                <label class="font-semibold text-slate-700">Event Title</label>
                <input type="text" name="title" required
                    value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
            </div>

            <div>
                <label class="font-semibold text-slate-700">Description</label>
                <textarea name="description" rows="5" required
                    class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition resize-none"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>
        </div>

        <div class="mb-6">
            <label class="font-semibold text-slate-700">Category</label>
            <input type="text" name="category" required
                value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
        </div>

        <div class="grid md:grid-cols-3 gap-5 mb-6">
            <div>
                <label class="font-semibold text-slate-700">Event Date</label>
                <input type="date" name="event_date" required
                    value="<?php echo isset($_POST['event_date']) ? htmlspecialchars($_POST['event_date']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
            </div>

            <div>
                <label class="font-semibold text-slate-700">Start Time</label>
                <input type="time" name="start_time" required
                    value="<?php echo isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
            </div>

            <div>
                <label class="font-semibold text-slate-700">End Time</label>
                <input type="time" name="end_time" required
                    value="<?php echo isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
            </div>
        </div>

        <div class="mb-6">
            <label class="font-semibold text-slate-700">Venue</label>
            <input type="text" name="venue" required
                value="<?php echo isset($_POST['venue']) ? htmlspecialchars($_POST['venue']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
        </div>

        <div class="mb-6">
            <label class="font-semibold text-slate-700">Registration Limit</label>
            <input type="number" name="registration_limit" min="1" required
                value="<?php echo isset($_POST['registration_limit']) ? htmlspecialchars($_POST['registration_limit']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
            <p class="text-sm text-slate-500 mt-2">Maximum number of registrants</p>
        </div>

        <div class="mb-8">
            <label class="font-semibold text-slate-700">Event Photo</label>
            <p class="text-sm text-slate-500 mt-1 mb-3">JPG, PNG, or WEBP — max 5MB. Optional, but recommended.</p>

            <label for="event_photo_input" class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 rounded-xl px-6 py-10 bg-slate-50 hover:bg-rmc-50 hover:border-rmc-300 transition cursor-pointer text-center">
                <i class="fa-solid fa-cloud-arrow-up text-2xl text-rmc-800"></i>
                <span class="font-semibold text-slate-700" id="event_photo_label_text">
                    Click to upload a photo
                </span>
                <span class="text-xs text-slate-400">or drag and drop</span>
            </label>

            <input
                type="file"
                name="event_photo"
                id="event_photo_input"
                accept="image/jpeg,image/png,image/webp"
                class="hidden"
                onchange="document.getElementById('event_photo_label_text').textContent = this.files.length ? this.files[0].name : 'Click to upload a photo';"
            >
        </div>

        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-8 py-3.5 rounded-xl font-semibold transition">
            <i class="fa-solid fa-paper-plane"></i>
            Submit Event for Approval
        </button>
    </form>
</div>


<!-- EVENTS TABLE -->
<div class="mt-8">
    <h2 class="text-2xl font-bold text-slate-900 mb-4">Submitted Events</h2>

    <div class="grid md:grid-cols-2 gap-6 mb-6">
        <div>
            <label class="font-semibold text-slate-700">Search</label>
            <input type="text" id="submittedEventSearch" placeholder="Search events..."
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-72 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition">
        </div>

        <div class="flex items-end gap-2">
        <select id="submittedEventStatus" class="border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition sm:w-80">
            <option value="all">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
        </select>
        <button type="button" id="clearSubmittedEventFilters" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold">Clear</button>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-rmc-950 text-white">
                <tr>
                    <th class="px-6 py-4 text-center">Title</th>
                    <th class="px-6 py-4 text-left">Date</th>
                    <th class="px-6 py-4 text-left">Venue</th>
                    <th class="px-6 py-4 text-center">Status</th>
                    <th class="px-6 py-4 text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="submittedEventsBody">
                <?php if (empty($submitted_events)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <p>No events found</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submitted_events as $submitted_event): ?>
                        <tr class="submitted-event-row border-b border-slate-100" data-search="<?= htmlspecialchars(strtolower($submitted_event['title'] . ' ' . $submitted_event['event_date'] . ' ' . $submitted_event['venue']), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?= htmlspecialchars(strtolower($submitted_event['status']), ENT_QUOTES, 'UTF-8'); ?>">
                            <td class="px-6 py-4 font-semibold"><?= htmlspecialchars($submitted_event['title']); ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($submitted_event['event_date']); ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($submitted_event['venue']); ?></td>
                            <td class="px-6 py-4 text-center"><?= htmlspecialchars(ucfirst($submitted_event['status'])); ?></td>
                            <td class="px-6 py-4 text-center">
                                <a href="edit_event.php?id=<?= (int) $submitted_event['event_id']; ?>" class="font-semibold text-rmc-800 hover:text-rmc-950">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="submittedEventsNoMatch" hidden>
                        <td colspan="5" class="px-6 py-16 text-center"><p>No events match the selected filters</p></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var search = document.getElementById('submittedEventSearch');
    var status = document.getElementById('submittedEventStatus');
    var clear = document.getElementById('clearSubmittedEventFilters');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.submitted-event-row'));
    var noMatch = document.getElementById('submittedEventsNoMatch');
    if (!search || !status || !clear) return;

    function applySubmittedEventFilters() {
        var query = search.value.trim().toLowerCase();
        var selectedStatus = status.value.toLowerCase();
        var visible = 0;

        rows.forEach(function (row) {
            var matchesSearch = !query || row.dataset.search.indexOf(query) !== -1;
            var matchesStatus = selectedStatus === 'all' || row.dataset.status === selectedStatus;
            row.hidden = !(matchesSearch && matchesStatus);
            if (!row.hidden) visible++;
        });

        if (noMatch) noMatch.hidden = visible > 0;
    }

    search.addEventListener('input', applySubmittedEventFilters);
    status.addEventListener('change', applySubmittedEventFilters);
    clear.addEventListener('click', function () {
        search.value = '';
        status.value = 'all';
        applySubmittedEventFilters();
    });
})();
</script>


<!-- FOOTER -->
<?php include 'partials/footer.php'; ?>

</body>
</html>