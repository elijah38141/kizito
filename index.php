<?php
declare(strict_types=1);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'dashboard';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; font-src 'self' https://fonts.gstatic.com; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

function setting(string $key, string $fallback = ''): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        return (string) ($stmt->fetchColumn() ?: $fallback);
    } catch (Throwable) {
        return $fallback;
    }
}

function ensure_app_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = ['users' => [], 'loans' => []];
    foreach (db()->query('SHOW COLUMNS FROM users')->fetchAll() as $column) {
        $columns['users'][$column['Field']] = true;
    }
    foreach (db()->query('SHOW COLUMNS FROM loans')->fetchAll() as $column) {
        $columns['loans'][$column['Field']] = true;
    }

    if (!isset($columns['users']['photo_path'])) {
        db()->exec('ALTER TABLE users ADD photo_path VARCHAR(255) NULL AFTER status');
    }
    if (!isset($columns['loans']['book_type'])) {
        db()->exec("ALTER TABLE loans ADD book_type VARCHAR(80) NOT NULL DEFAULT 'General' AFTER member_id");
    }
    if (!isset($columns['loans']['borrower_type'])) {
        db()->exec("ALTER TABLE loans ADD borrower_type ENUM('teacher', 'student') NOT NULL DEFAULT 'student' AFTER book_type");
    }
    if (!isset($columns['loans']['borrower_name'])) {
        db()->exec('ALTER TABLE loans ADD borrower_name VARCHAR(160) NULL AFTER borrower_type');
    }
    if (!isset($columns['loans']['student_class'])) {
        db()->exec('ALTER TABLE loans ADD student_class VARCHAR(20) NULL AFTER borrower_name');
    }
    if (!isset($columns['loans']['student_stream'])) {
        db()->exec('ALTER TABLE loans ADD student_stream VARCHAR(60) NULL AFTER student_class');
    }
    if (!isset($columns['loans']['borrowing_period'])) {
        db()->exec('ALTER TABLE loans ADD borrowing_period INT NOT NULL DEFAULT 14 AFTER due_date');
    }
    if (!isset($columns['loans']['notes'])) {
        db()->exec('ALTER TABLE loans ADD notes TEXT NULL AFTER borrowing_period');
    }
    if (!isset($columns['loans']['activity_status'])) {
        db()->exec("ALTER TABLE loans ADD activity_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER status");
    }
    db()->exec("DELETE FROM settings WHERE setting_key IN ('two_factor_enabled', 'mail_from', 'mail_from_name', 'mail_reply_to')");

    db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(160) NOT NULL,
        ip_address VARCHAR(64) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY login_attempt_key (email, ip_address)
    )");

    foreach ([
        'school_name' => setting('library_name', APP_NAME),
        'school_logo' => '',
        'school_motto' => '',
        'student_streams_s1_s4' => 'A, B',
    ] as $key => $value) {
        db()->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }

    $done = true;
}

function upload_file(string $field, string $prefix): string
{
    if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return '';
    }

    $type = mime_content_type($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$type])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
    }

    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$type];
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new RuntimeException('Unable to save uploaded file.');
    }

    return 'assets/uploads/' . $filename;
}

function calculate_fine(string $dueDate, ?string $returnDate = null): float
{
    $return = new DateTimeImmutable($returnDate ?: date('Y-m-d'));
    $due = new DateTimeImmutable($dueDate);
    if ($return <= $due) {
        return 0;
    }

    return (float) $due->diff($return)->days * (float) setting('daily_fine_rate', (string) DAILY_FINE_RATE);
}

function update_overdue_loans(): void
{
    ensure_app_schema();
    db()->exec("UPDATE loans SET status = 'overdue' WHERE status = 'borrowed' AND due_date < CURDATE()");
}

function issue_book_from_post(string $redirectTo = '?action=issue'): void
{
    $bookId = (int) post('book_id');
    $borrowerType = in_array(post('borrower_type'), ['teacher', 'student'], true) ? post('borrower_type') : 'student';
    $borrowerName = post('borrower_name');
    $studentClass = $borrowerType === 'student' ? post('student_class') : '';
    $studentStream = $borrowerType === 'student' ? post('student_stream') : '';
    $bookType = post('book_type') ?: 'General';
    $issueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('issue_date')) ? post('issue_date') : date('Y-m-d');
    $borrowingPeriod = max(1, (int) post('borrowing_period', setting('loan_days', '14')));
    $notes = post('notes');

    if ($borrowerName === '') {
        flash('Borrower name is required.', 'error');
        redirect($redirectTo);
    }
    if ($borrowerType === 'student' && ($studentClass === '' || $studentStream === '')) {
        flash('Student class and stream are required.', 'error');
        redirect($redirectTo);
    }

    $dueDate = (new DateTimeImmutable($issueDate))->modify('+' . $borrowingPeriod . ' days')->format('Y-m-d');
    $memberNo = strtoupper(substr($borrowerType, 0, 3)) . '-' . substr(sha1(strtolower($borrowerType . ':' . $borrowerName)), 0, 10);

    db()->beginTransaction();
    $book = db()->prepare('SELECT * FROM books WHERE id = ? FOR UPDATE');
    $book->execute([$bookId]);
    $book = $book->fetch();
    if (!$book || (int) $book['available_copies'] < 1) {
        db()->rollBack();
        flash('Selected book is not available.', 'error');
        redirect($redirectTo);
    }

    db()->prepare("INSERT INTO members (member_no, name, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', id = LAST_INSERT_ID(id)")
        ->execute([$memberNo, $borrowerName]);
    $memberId = (int) db()->lastInsertId();

    $stmt = db()->prepare("INSERT INTO loans (book_id, member_id, issued_by, issue_date, due_date, book_type, borrower_type, borrower_name, student_class, student_stream, borrowing_period, notes, activity_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->execute([$bookId, $memberId, current_user()['id'], $issueDate, $dueDate, $bookType, $borrowerType, $borrowerName, $studentClass ?: null, $studentStream ?: null, $borrowingPeriod, $notes]);
    db()->prepare('UPDATE books SET available_copies = available_copies - 1 WHERE id = ?')->execute([$bookId]);
    db()->commit();

    flash('Book issued successfully.');
    redirect($redirectTo);
}

function borrower_label(array $loan): string
{
    return (string) ($loan['borrower_name'] ?: $loan['member_name'] ?: '');
}

function days_left(string $dueDate): int
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $due = new DateTimeImmutable($dueDate);
    $days = (int) $today->diff($due)->format('%r%a');
    return $days;
}

function count_query(string $sql): int
{
    return (int) db()->query($sql)->fetchColumn();
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function login_lock_message(string $email): string
{
    ensure_app_schema();
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE email = ? AND ip_address = ? AND locked_until > NOW()');
    $stmt->execute([$email, client_ip()]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil) {
        return '';
    }

    return 'Too many failed login attempts. Try again after ' . (new DateTimeImmutable((string) $lockedUntil))->format('H:i') . '.';
}

function record_failed_login(string $email): void
{
    ensure_app_schema();
    db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ? AND locked_until IS NOT NULL AND locked_until <= NOW()')
        ->execute([$email, client_ip()]);
    db()->prepare("INSERT INTO login_attempts (email, ip_address, attempts, locked_until) VALUES (?, ?, 1, NULL)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1, locked_until = IF(attempts + 1 >= 3, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)")
        ->execute([$email, client_ip()]);
}

function clear_failed_logins(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')->execute([$email, client_ip()]);
}

function complete_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
}

function nav_item(string $href, string $label, string $icon, ?int $badge = null): array
{
    return compact('href', 'label', 'icon', 'badge');
}

function save_book_from_post(): void
{
    $copies = max(1, (int) post('total_copies', '1'));
    $isbn = post('isbn');

    if ($isbn !== '') {
        $existing = db()->prepare('SELECT id FROM books WHERE isbn = ?');
        $existing->execute([$isbn]);
        $bookId = (int) $existing->fetchColumn();
        if ($bookId > 0) {
            db()->prepare('UPDATE books SET total_copies = total_copies + ?, available_copies = available_copies + ?, title = ?, author = ?, category = ?, publisher = ?, publication_year = ?, shelf_location = ? WHERE id = ?')
                ->execute([$copies, $copies, post('title'), post('author'), post('category'), post('publisher'), post('publication_year') ?: null, post('shelf_location'), $bookId]);
            flash('Book copies added to the catalog.');
            redirect('?action=books');
        }
    }

    $stmt = db()->prepare('INSERT INTO books (isbn, title, author, category, publisher, publication_year, shelf_location, total_copies, available_copies) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$isbn ?: null, post('title'), post('author'), post('category'), post('publisher'), post('publication_year') ?: null, post('shelf_location'), $copies, $copies]);
    flash('Book added to the catalog.');
    redirect('?action=books');
}

function app_nav_items(array $user): array
{
    update_overdue_loans();

    if ($user['role'] === 'admin') {
        return [
            nav_item('dashboard', 'Dashboard', 'dashboard'),
            nav_item('books', 'Book Catalog', 'menu_book', count_query('SELECT COUNT(*) FROM books')),
            nav_item('librarians', 'Users', 'badge'),
            nav_item('reports', 'Reports', 'analytics'),
            nav_item('settings', 'Settings', 'settings'),
            nav_item('backup', 'Backup', 'database'),
        ];
    }

    return [
        nav_item('dashboard', 'Dashboard', 'dashboard'),
        nav_item('books', 'Book Catalog', 'menu_book', count_query('SELECT COUNT(*) FROM books')),
        nav_item('issue', 'Issue Books', 'assignment_add', count_query("SELECT COUNT(*) FROM loans WHERE status = 'borrowed'")),
        nav_item('returns', 'Returns', 'assignment_return', count_query("SELECT COUNT(*) FROM loans WHERE status = 'returned' AND return_date = CURDATE()")),
        nav_item('overdue', 'Overdue', 'warning', count_query("SELECT COUNT(*) FROM loans WHERE status = 'overdue'")),
    ];
}

function setting_list(string $key, array $fallback): array
{
    $raw = setting($key, implode(', ', $fallback));
    $items = array_filter(array_map('trim', explode(',', $raw)), fn($item) => $item !== '');
    return $items ?: $fallback;
}

function student_classes(): array
{
    return ['Senior 1', 'Senior 2', 'Senior 3', 'Senior 4', 'Senior 5', 'Senior 6'];
}

function streams_for_class(string $class): array
{
    if (in_array($class, ['Senior 5', 'Senior 6'], true)) {
        return ['Arts', 'Sciences'];
    }

    return setting_list('student_streams_s1_s4', ['A', 'B']);
}

function all_student_stream_options(): array
{
    $options = [];
    foreach (student_classes() as $class) {
        $options[$class] = streams_for_class($class);
    }
    return $options;
}

function render_app_nav(array $items, string $activeAction): void
{
    $activeLabel = 'Menu';
    foreach ($items as $item) {
        if ($item['href'] === $activeAction || ($activeAction === 'search' && $item['href'] === 'books')) {
            $activeLabel = $item['label'];
            break;
        }
    }
    ?>
    <div class="app-nav-wrap mt-8">
        <button type="button" class="mobile-menu-button" data-nav-toggle aria-expanded="false" aria-controls="appNav">
            <span class="material-symbols-outlined text-[20px]">menu</span>
            <span><?= e($activeLabel) ?></span>
        </button>
        <nav id="appNav" class="app-nav flex gap-7 overflow-x-auto border-b border-zinc-200 text-sm font-semibold text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            <?php foreach ($items as $item): ?>
                <?php $active = $item['href'] === $activeAction || ($activeAction === 'search' && $item['href'] === 'books'); ?>
                <a href="?action=<?= e($item['href']) ?>" class="flex items-center gap-2 border-b-2 px-1 pb-4 <?= $active ? 'border-cyan-500 text-cyan-600 dark:text-cyan-300' : 'border-transparent hover:text-zinc-950 dark:hover:text-white' ?>">
                    <span class="material-symbols-outlined text-[20px]"><?= e($item['icon']) ?></span>
                    <span><?= e($item['label']) ?></span>
                    <?php if ($item['badge'] !== null): ?>
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"><?= e((string) $item['badge']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php
}

function page_header(string $title): void
{
    global $action;
    $user = current_user();
    $libraryName = setting('school_name', setting('library_name', APP_NAME));
    $schoolLogo = setting('school_logo', '');
    $schoolMotto = setting('school_motto', '');
    $flash = flash();
    if ($user) {
        update_overdue_loans();
    }
    $overdueCount = $user ? count_query("SELECT COUNT(*) FROM loans WHERE status = 'overdue'") : 0;
    $notificationHref = $user && $user['role'] === 'librarian' ? '?action=overdue' : '?action=dashboard';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e($libraryName) ?></title>
        <script>
            const storedTheme = localStorage.getItem('theme');
            const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = storedTheme || preferredTheme;
        </script>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&family=Playfair+Display:wght@400;500;600;700;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-100">
    <?php if ($user): ?>
    <div class="top-page-controls">
            <a class="notification-button" href="<?= e($notificationHref) ?>" aria-label="<?= e((string) $overdueCount) ?> overdue records" title="Overdue records">
                <span class="material-symbols-outlined text-[20px]">notifications</span>
                <?php if ($overdueCount > 0): ?>
                    <span class="notification-badge"><?= e((string) $overdueCount) ?></span>
                <?php endif; ?>
            </a>
        <button type="button" id="themeToggle" class="theme-toggle icon-only-button" aria-label="Toggle full page theme" title="Toggle theme">
            <span id="themeIcon" class="material-symbols-outlined text-[20px]">dark_mode</span>
        </button>
    </div>
    <?php endif; ?>
    <div class="min-h-screen">
        <main>
            <?php if ($user): ?>
                <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="w-full px-5 py-4 sm:px-8">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="app-brand flex items-center gap-4">
                                <?php if ($schoolLogo !== ''): ?>
                                    <img src="<?= e($schoolLogo) ?>" alt="<?= e($libraryName) ?> logo" class="site-logo rounded-md border">
                                <?php else: ?>
                                    <div class="grid size-12 place-items-center rounded-md bg-lime-300 text-sm font-black leading-none text-zinc-950">LIB</div>
                                <?php endif; ?>
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-wide"><?= e($libraryName) ?></p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400"><?= e($schoolMotto !== '' ? $schoolMotto : ucfirst($user['role']) . ' workspace') ?></p>
                                </div>
                            </div>
                            <div class="header-user-actions ml-auto flex items-center gap-3">
                                <div class="flex items-center gap-2 rounded-full bg-cyan-600 px-2 py-1 text-white">
                                    <?php if (!empty($user['photo_path'])): ?>
                                        <img src="<?= e($user['photo_path']) ?>" alt="<?= e($user['name']) ?>" class="user-avatar">
                                    <?php else: ?>
                                        <span class="grid size-8 place-items-center rounded-full bg-cyan-500 font-bold"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                                    <?php endif; ?>
                                    <span class="hidden max-w-36 truncate pr-2 text-sm font-semibold sm:inline"><?= e($user['name']) ?></span>
                                </div>
                                <a class="icon-button rounded-full border border-zinc-200 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800" href="?action=logout">
                                    <span class="material-symbols-outlined text-[18px]">logout</span>
                                    Logout
                                </a>
                            </div>
                        </div>
                        <div class="mt-5">
                            <h1 class="text-3xl font-bold tracking-tight">Library Management</h1>
                            <p class="mt-1 text-zinc-500 dark:text-zinc-400">Manage book catalog, issue and return books, track overdue items</p>
                        </div>
                        <?php render_app_nav(app_nav_items($user), $action); ?>
                    </div>
                </header>
            <?php endif; ?>
            <section class="<?= $user ? 'w-full px-5 py-7 sm:px-8' : '' ?>">
                <?php if ($flash): ?>
                    <div class="mb-5 rounded-md border px-4 py-3 <?= $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>
    <?php
}

function page_footer(): void
{
    ?>
            </section>
        </main>
    </div>
    <script>
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        function syncThemeButton() {
            const isDark = document.documentElement.dataset.theme === 'dark';
            if (themeIcon) themeIcon.textContent = isDark ? 'light_mode' : 'dark_mode';
        }
        syncThemeButton();
        themeToggle?.addEventListener('click', () => {
            const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = nextTheme;
            localStorage.setItem('theme', nextTheme);
            syncThemeButton();
        });
        document.querySelectorAll('[data-nav-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const nav = document.getElementById(button.getAttribute('aria-controls'));
                if (!nav) return;
                const isOpen = nav.classList.toggle('is-open');
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                button.querySelector('.material-symbols-outlined').textContent = isOpen ? 'close' : 'menu';
            });
        });
        document.querySelectorAll('[data-open-issue]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById('issueDialog');
                if (!dialog) return;
                dialog.querySelector('[name="book_id"]').value = button.dataset.bookId || '';
                dialog.querySelector('[data-book-title]').textContent = button.dataset.bookTitle || '';
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', 'open');
            });
        });
        document.querySelectorAll('[data-open-dialog]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById(button.dataset.openDialog);
                if (!dialog) return;
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', 'open');
            });
        });
        document.querySelectorAll('[data-close-dialog]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = button.closest('dialog');
                if (!dialog) return;
                if (typeof dialog.close === 'function') dialog.close();
                else dialog.removeAttribute('open');
            });
        });
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                if (!input) return;
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.querySelector('.material-symbols-outlined').textContent = isPassword ? 'visibility_off' : 'visibility';
                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
        const streamOptions = <?= json_encode(all_student_stream_options(), JSON_UNESCAPED_SLASHES) ?>;
        function syncStudentFields(form) {
            const checkedType = form.querySelector('[name="borrower_type"]:checked');
            const studentPanel = form.querySelector('[data-student-fields]');
            const classSelect = form.querySelector('[name="student_class"]');
            const streamSelect = form.querySelector('[name="student_stream"]');
            const isStudent = !checkedType || checkedType.value === 'student';
            if (studentPanel) studentPanel.hidden = !isStudent;
            if (classSelect) classSelect.required = isStudent;
            if (streamSelect) streamSelect.required = isStudent;
            if (!isStudent || !classSelect || !streamSelect) return;
            const selectedStream = streamSelect.value;
            const streams = streamOptions[classSelect.value] || [];
            streamSelect.replaceChildren(...streams.map((stream) => {
                const option = document.createElement('option');
                option.value = stream;
                option.textContent = stream;
                return option;
            }));
            if (streams.includes(selectedStream)) {
                streamSelect.value = selectedStream;
            }
        }
        document.querySelectorAll('form').forEach((form) => {
            if (!form.querySelector('[data-student-fields]')) return;
            syncStudentFields(form);
            form.querySelectorAll('[name="borrower_type"], [name="student_class"]').forEach((input) => {
                input.addEventListener('change', () => syncStudentFields(form));
            });
        });
    </script>
    </body>
    </html>
    <?php
}

function card(string $label, string $value, string $tone = 'zinc', string $caption = '', string $icon = 'analytics'): void
{
    $classes = [
        'zinc' => 'text-zinc-600 dark:text-zinc-300',
        'emerald' => 'text-emerald-600 dark:text-emerald-300',
        'amber' => 'text-amber-600 dark:text-amber-300',
        'sky' => 'text-cyan-600 dark:text-cyan-300',
    ][$tone] ?? 'text-zinc-600 dark:text-zinc-300';
    ?>
    <div class="relative overflow-hidden rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"><?= e($label) ?></p>
        <p class="mt-3 text-4xl font-bold"><?= e($value) ?></p>
        <?php if ($caption !== ''): ?>
            <p class="mt-3 flex items-center gap-1.5 text-sm font-medium <?= $classes ?>">
                <?= e($caption) ?>
                <span class="material-symbols-outlined text-[18px]"><?= e($icon) ?></span>
            </p>
        <?php endif; ?>
        <div class="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-cyan-500 via-emerald-500 to-lime-400"></div>
    </div>
    <?php
}

function login_page(): void
{
    if (current_user()) {
        redirect('?action=dashboard');
    }

    $libraryName = setting('school_name', setting('library_name', APP_NAME));
    $schoolLogo = setting('school_logo', '');
    $schoolMotto = setting('school_motto', '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = post('email');
        $lockedMessage = login_lock_message($email);
        if ($lockedMessage !== '') {
            flash($lockedMessage, 'error');
            redirect('?action=login');
        }

        $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify(post('password'), $user['password_hash'])) {
            clear_failed_logins($email);
            $loginUser = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'photo_path' => $user['photo_path'] ?? '',
            ];
            complete_login($loginUser);
            redirect('?action=dashboard');
        }

        if ($email !== '') {
            record_failed_login($email);
        }
        flash('Invalid email or password.', 'error');
        redirect('?action=login');
    }

    page_header('Login');
    ?>
    <div class="grid min-h-screen place-items-center bg-zinc-950 px-4">
        <form method="post" class="w-full max-w-md rounded-md bg-white p-8 shadow-xl">
            <div class="brand-stack mb-6">
                <?php if ($schoolLogo !== ''): ?>
                    <img src="<?= e($schoolLogo) ?>" alt="<?= e($libraryName) ?> logo" class="site-logo rounded-md border">
                <?php else: ?>
                    <div class="grid size-12 place-items-center rounded-md bg-lime-300 text-sm font-black leading-none text-zinc-950">LIB</div>
                <?php endif; ?>
                <p class="text-sm font-bold uppercase tracking-wide text-zinc-900"><?= e($libraryName) ?></p>
                <p class="text-xs text-zinc-500"><?= e($schoolMotto !== '' ? $schoolMotto : 'Faith motto') ?></p>
            </div>
            <div class="flex items-center justify-end">
                <button type="button" id="themeToggle" class="theme-toggle inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-medium" aria-label="Toggle theme">
                    <span id="themeIcon" class="material-symbols-outlined text-[18px]">dark_mode</span>
                    Theme
                </button>
            </div>
            <label class="mt-6 block text-sm font-medium">Email</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">mail</span>
                <input name="email" type="email" required autocomplete="username" class="w-full rounded-md border px-3 py-2 focus:border-zinc-900 focus:outline-none">
            </div>
            <label class="mt-4 block text-sm font-medium">Password</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">lock</span>
                <input id="loginPassword" name="password" type="password" required autocomplete="current-password" class="has-password-toggle w-full rounded-md border px-3 py-2 focus:border-zinc-900 focus:outline-none">
                <button type="button" class="password-toggle" data-password-toggle="loginPassword" aria-label="Show password">
                    <span class="material-symbols-outlined text-[20px]">visibility</span>
                </button>
            </div>
            <button class="icon-button mt-6 w-full rounded-md primary-button px-4 py-2.5 font-medium text-white hover:bg-zinc-800">
                <span class="material-symbols-outlined text-[20px]">login</span>
                Login
            </button>
        </form>
    </div>
    <?php
    page_footer();
}

function dashboard(): void
{
    require_login();
    update_overdue_loans();

    $stats = [
        'Total Books' => db()->query('SELECT COUNT(*) FROM books')->fetchColumn(),
        'Total Copies' => db()->query('SELECT COALESCE(SUM(total_copies), 0) FROM books')->fetchColumn(),
        'Available Copies' => db()->query('SELECT COALESCE(SUM(available_copies), 0) FROM books')->fetchColumn(),
        'Active Borrowing' => db()->query("SELECT COUNT(*) FROM loans WHERE status IN ('borrowed', 'overdue')")->fetchColumn(),
    ];

    $borrowedCopies = max(0, (int) $stats['Total Copies'] - (int) $stats['Available Copies']);
    $overdueCount = count_query("SELECT COUNT(*) FROM loans WHERE status = 'overdue'");
    $returnedToday = count_query("SELECT COUNT(*) FROM loans WHERE status = 'returned' AND return_date = CURDATE()");
    $categories = db()->query('SELECT category, COUNT(*) AS titles, COALESCE(SUM(total_copies), 0) AS copies FROM books GROUP BY category ORDER BY titles DESC, category LIMIT 8')->fetchAll();
    $statusRows = db()->query("SELECT status, COUNT(*) AS total FROM loans GROUP BY status")->fetchAll();
    $statusCounts = ['borrowed' => 0, 'overdue' => 0, 'returned' => 0];
    foreach ($statusRows as $row) {
        $statusCounts[$row['status']] = (int) $row['total'];
    }
    $loans = db()->query("SELECT loans.*, books.title, members.name AS member_name FROM loans JOIN books ON books.id = loans.book_id JOIN members ON members.id = loans.member_id ORDER BY loans.id DESC LIMIT 8")->fetchAll();

    page_header('Dashboard');
    ?>
    <p class="mb-6 text-zinc-600 dark:text-zinc-300">Welcome! Here is an overview of your library activities and statistics.</p>
    <div class="grid gap-4 md:grid-cols-4">
        <?php card('Total Books', (string) $stats['Total Books'], 'sky', 'Active book titles', 'menu_book'); ?>
        <?php card('Total Copies', (string) $stats['Total Copies'], 'emerald', 'Physical copies in library', 'inventory_2'); ?>
        <?php card('Available Copies', (string) $stats['Available Copies'], 'amber', $borrowedCopies . ' currently borrowed', 'check_circle'); ?>
        <?php card('Active Borrowing', (string) $stats['Active Borrowing'], 'zinc', $overdueCount . ' overdue', 'sync'); ?>
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="min-h-[320px] rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                <h2 class="font-bold">Books by Category</h2>
            </div>
            <div class="space-y-4 p-5">
                <?php foreach ($categories as $category): ?>
                    <?php $percent = (int) $stats['Total Books'] > 0 ? min(100, round(((int) $category['titles'] / (int) $stats['Total Books']) * 100)) : 0; ?>
                    <div>
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium"><?= e($category['category']) ?></span>
                            <span class="text-zinc-500 dark:text-zinc-400"><?= e((string) $category['titles']) ?> titles, <?= e((string) $category['copies']) ?> copies</span>
                        </div>
                        <div class="mt-2 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2 rounded-full bg-cyan-500" style="width: <?= e((string) $percent) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$categories): ?>
                    <div class="grid min-h-56 place-items-center text-sm text-zinc-500 dark:text-zinc-400">No categories yet.</div>
                <?php endif; ?>
            </div>
        </section>
        <section class="min-h-[320px] rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                <h2 class="font-bold">Borrowing Status</h2>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-3">
                <div class="rounded-md border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Borrowed</p>
                    <p class="mt-2 text-3xl font-bold"><?= e((string) $statusCounts['borrowed']) ?></p>
                </div>
                <div class="rounded-md border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Overdue</p>
                    <p class="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-300"><?= e((string) $statusCounts['overdue']) ?></p>
                </div>
                <div class="rounded-md border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Returned</p>
                    <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-300"><?= e((string) $statusCounts['returned']) ?></p>
                </div>
            </div>
            <div class="px-5 pb-5">
                <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-950">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Returned today</span>
                        <span class="font-semibold"><?= e((string) $returnedToday) ?></span>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Books currently out</span>
                        <span class="font-semibold"><?= e((string) $borrowedCopies) ?></span>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
            <h2 class="font-bold">Recent Borrowing Activity</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr><th class="px-4 py-3">Book</th><th class="px-4 py-3">Member</th><th class="px-4 py-3">Due</th><th class="px-4 py-3">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <?php foreach ($loans as $loan): ?>
                    <tr><td class="px-4 py-3"><?= e($loan['title']) ?></td><td class="px-4 py-3"><?= e($loan['member_name']) ?></td><td class="px-4 py-3"><?= e($loan['due_date']) ?></td><td class="px-4 py-3"><?= e($loan['status']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$loans): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No borrowing activity yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    page_footer();
}

function librarians(): void
{
    require_role('admin');
    ensure_app_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('form_type') === 'edit_user') {
            $userId = (int) post('user_id');
            $role = in_array(post('role'), ['admin', 'librarian'], true) ? post('role') : 'librarian';
            $status = in_array(post('status'), ['active', 'inactive'], true) ? post('status') : 'active';
            $photoPath = upload_file('photo', 'user');
            $password = post('password');

            if ($password !== '' && $photoPath !== '') {
                db()->prepare('UPDATE users SET name = ?, email = ?, password_hash = ?, role = ?, status = ?, photo_path = ? WHERE id = ?')
                    ->execute([post('name'), post('email'), password_hash($password, PASSWORD_DEFAULT), $role, $status, $photoPath, $userId]);
            } elseif ($password !== '') {
                db()->prepare('UPDATE users SET name = ?, email = ?, password_hash = ?, role = ?, status = ? WHERE id = ?')
                    ->execute([post('name'), post('email'), password_hash($password, PASSWORD_DEFAULT), $role, $status, $userId]);
            } elseif ($photoPath !== '') {
                db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ?, photo_path = ? WHERE id = ?')
                    ->execute([post('name'), post('email'), $role, $status, $photoPath, $userId]);
            } else {
                db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?')
                    ->execute([post('name'), post('email'), $role, $status, $userId]);
            }

            if ($userId === (int) current_user()['id']) {
                $_SESSION['user']['role'] = $role;
                $_SESSION['user']['name'] = post('name');
                $_SESSION['user']['email'] = post('email');
                if ($photoPath !== '') {
                    $_SESSION['user']['photo_path'] = $photoPath;
                }
            }
            flash('User updated.');
        } elseif (post('form_type') === 'delete_user') {
            $userId = (int) post('user_id');
            if ($userId === (int) current_user()['id']) {
                flash('You cannot delete your own account while logged in.', 'error');
            } elseif (count_query('SELECT COUNT(*) FROM loans WHERE issued_by = ' . $userId . ' OR returned_by = ' . $userId) > 0) {
                flash('This user has borrowing history and cannot be deleted.', 'error');
            } else {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                flash('User deleted.');
            }
        } elseif (post('form_type') === 'photo') {
            $userId = (int) post('user_id');
            $photoPath = upload_file('photo', 'user');
            if ($photoPath === '') {
                flash('Choose a photo before saving.', 'error');
            } else {
                db()->prepare('UPDATE users SET photo_path = ? WHERE id = ?')->execute([$photoPath, $userId]);
                if ($userId === (int) current_user()['id']) {
                    $_SESSION['user']['photo_path'] = $photoPath;
                }
                flash('User photo updated.');
            }
        } else {
            $password = post('password') ?: 'library123';
            $role = in_array(post('role'), ['admin', 'librarian'], true) ? post('role') : 'librarian';
            $photoPath = upload_file('photo', 'user');
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, status, photo_path) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([post('name'), post('email'), password_hash($password, PASSWORD_DEFAULT), $role, post('status', 'active'), $photoPath ?: null]);
            flash('User account created.');
        }
        redirect('?action=librarians');
    }

    $users = db()->query('SELECT * FROM users ORDER BY role, name')->fetchAll();
    page_header('Manage Users');
    ?>
    <div class="grid gap-6 lg:grid-cols-[360px_1fr]">
        <form method="post" enctype="multipart/form-data" class="spacious-form rounded-md border bg-white p-5">
            <h2 class="font-semibold">Add User</h2>
            <input name="name" required placeholder="Full name" class="mt-4 w-full rounded-md border px-3 py-2">
            <div class="input-with-icon mt-3">
                <span class="material-symbols-outlined text-[20px]">mail</span>
                <input name="email" type="email" required placeholder="Email" class="w-full rounded-md border px-3 py-2">
            </div>
            <div class="input-with-icon mt-3">
                <span class="material-symbols-outlined text-[20px]">lock</span>
                <input id="newUserPassword" name="password" type="password" placeholder="Password" class="has-password-toggle w-full rounded-md border px-3 py-2">
                <button type="button" class="password-toggle" data-password-toggle="newUserPassword" aria-label="Show password">
                    <span class="material-symbols-outlined text-[20px]">visibility</span>
                </button>
            </div>
            <div class="input-with-icon mt-3">
                <span class="material-symbols-outlined text-[20px]">admin_panel_settings</span>
                <select name="role" class="w-full rounded-md border px-3 py-2"><option value="librarian">librarian</option><option value="admin">admin</option></select>
            </div>
            <select name="status" class="mt-3 w-full rounded-md border px-3 py-2"><option>active</option><option>inactive</option></select>
            <label class="mt-4 block text-sm font-medium">Photo</label>
            <input name="photo" type="file" accept="image/*" class="mt-2 w-full rounded-md border px-3 py-2">
            <button class="icon-button mt-4 rounded-md primary-button px-4 py-2 text-white">
                <span class="material-symbols-outlined text-[18px]">save</span>
                Save User
            </button>
        </form>
        <?php table_users($users); ?>
    </div>
    <?php
    page_footer();
}

function table_users(array $users): void
{
    ?>
    <div class="overflow-x-auto rounded-md border bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-500"><tr><th class="px-4 py-3">Photo</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Actions</th></tr></thead>
            <tbody class="divide-y">
            <?php foreach ($users as $user): ?>
                <tr>
                    <td class="px-4 py-3">
                        <?php if (!empty($user['photo_path'])): ?>
                            <img src="<?= e($user['photo_path']) ?>" alt="<?= e($user['name']) ?>" class="user-photo">
                        <?php else: ?>
                            <span class="grid size-8 place-items-center rounded-full bg-cyan-500 font-bold text-white"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><?= e($user['name']) ?></td>
                    <td class="px-4 py-3"><?= e($user['email']) ?></td>
                    <td class="px-4 py-3"><?= e($user['role']) ?></td>
                    <td class="px-4 py-3"><?= e($user['status']) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="icon-button rounded-md border px-3 py-2" data-open-dialog="viewUser<?= e((string) $user['id']) ?>">
                                <span class="material-symbols-outlined text-[18px]">visibility</span>
                                View
                            </button>
                            <button type="button" class="icon-button rounded-md primary-button px-3 py-2 text-white" data-open-dialog="editUser<?= e((string) $user['id']) ?>">
                                <span class="material-symbols-outlined text-[18px]">edit</span>
                                Edit
                            </button>
                            <form method="post" onsubmit="return confirm('Delete this user?');">
                                <input type="hidden" name="form_type" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>">
                                <button class="icon-button rounded-md bg-red-600 px-3 py-2 text-white">
                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                    Delete
                                </button>
                            </form>
                        </div>
                        <dialog id="viewUser<?= e((string) $user['id']) ?>" class="user-dialog rounded-md border bg-white p-0">
                            <div class="p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <h2 class="font-semibold">User Details</h2>
                                    <button type="button" class="rounded-full border px-3 py-1 text-sm" data-close-dialog>Close</button>
                                </div>
                                <div class="mt-4 flex items-center gap-3">
                                    <?php if (!empty($user['photo_path'])): ?>
                                        <img src="<?= e($user['photo_path']) ?>" alt="<?= e($user['name']) ?>" class="user-photo">
                                    <?php else: ?>
                                        <span class="grid size-8 place-items-center rounded-full bg-cyan-500 font-bold text-white"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-semibold"><?= e($user['name']) ?></p>
                                        <p class="text-sm text-zinc-500"><?= e($user['email']) ?></p>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-3 text-sm">
                                    <p><span class="font-semibold">Role:</span> <?= e($user['role']) ?></p>
                                    <p><span class="font-semibold">Status:</span> <?= e($user['status']) ?></p>
                                    <p><span class="font-semibold">Created:</span> <?= e($user['created_at'] ?? '') ?></p>
                                </div>
                            </div>
                        </dialog>
                        <dialog id="editUser<?= e((string) $user['id']) ?>" class="user-dialog rounded-md border bg-white p-0">
                            <form method="post" enctype="multipart/form-data" class="spacious-form p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <h2 class="font-semibold">Edit User</h2>
                                    <button type="button" class="rounded-full border px-3 py-1 text-sm" data-close-dialog>Cancel</button>
                                </div>
                                <input type="hidden" name="form_type" value="edit_user">
                                <input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>">
                                <label class="mt-4 block text-sm font-medium">Full Name</label>
                                <input name="name" required value="<?= e($user['name']) ?>" class="mt-2 w-full rounded-md border px-3 py-2">
                                <label class="mt-4 block text-sm font-medium">Email</label>
                                <div class="input-with-icon mt-2">
                                    <span class="material-symbols-outlined text-[20px]">mail</span>
                                    <input name="email" type="email" required value="<?= e($user['email']) ?>" class="w-full rounded-md border px-3 py-2">
                                </div>
                                <label class="mt-4 block text-sm font-medium">New Password</label>
                                <div class="input-with-icon mt-2">
                                    <span class="material-symbols-outlined text-[20px]">lock</span>
                                    <input id="editPassword<?= e((string) $user['id']) ?>" name="password" type="password" placeholder="Leave blank to keep current" class="has-password-toggle w-full rounded-md border px-3 py-2">
                                    <button type="button" class="password-toggle" data-password-toggle="editPassword<?= e((string) $user['id']) ?>" aria-label="Show password">
                                        <span class="material-symbols-outlined text-[20px]">visibility</span>
                                    </button>
                                </div>
                                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                    <div class="input-with-icon">
                                        <span class="material-symbols-outlined text-[20px]">admin_panel_settings</span>
                                        <select name="role" class="w-full rounded-md border px-3 py-2">
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                            <option value="librarian" <?= $user['role'] === 'librarian' ? 'selected' : '' ?>>librarian</option>
                                        </select>
                                    </div>
                                    <select name="status" class="rounded-md border px-3 py-2">
                                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>active</option>
                                        <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option>
                                    </select>
                                    <input name="photo" type="file" accept="image/*" class="rounded-md border px-3 py-2">
                                </div>
                                <div class="mt-5 flex justify-end">
                                    <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
                                        <span class="material-symbols-outlined text-[18px]">save</span>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        </dialog>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function books(): void
{
    require_login();
    ensure_app_schema();
    $user = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['role'], ['admin', 'librarian'], true)) {
        save_book_from_post();
    }

    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = db()->prepare('SELECT * FROM books WHERE title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ? ORDER BY title');
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $like, $like]);
        $books = $stmt->fetchAll();
    } else {
        $books = db()->query('SELECT * FROM books ORDER BY title')->fetchAll();
    }

    page_header($user['role'] === 'admin' ? 'Manage Books' : 'Book Catalog');
    ?>
    <form class="mb-5 flex max-w-2xl gap-3">
        <input type="hidden" name="action" value="books">
        <input name="q" value="<?= e($q) ?>" placeholder="Search book catalog" class="flex-1 rounded-md border px-3 py-2">
        <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
            <span class="material-symbols-outlined text-[18px]">search</span>
            Search
        </button>
    </form>
    <?php
    if (in_array($user['role'], ['admin', 'librarian'], true)) {
        book_form_and_table($books, $user['role']);
    } else {
        books_table($books);
        issue_dialog();
    }
    page_footer();
}

function book_form_and_table(array $books, string $role): void
{
    $heading = $role === 'librarian' ? 'Add Book Brought' : 'Add Book';
    ?>
    <div class="grid gap-6 lg:grid-cols-[380px_1fr]">
        <form method="post" class="rounded-md border bg-white p-5">
            <h2 class="font-semibold"><?= e($heading) ?></h2>
            <input name="title" required placeholder="Title" class="mt-4 w-full rounded-md border px-3 py-2">
            <input name="author" required placeholder="Author" class="mt-3 w-full rounded-md border px-3 py-2">
            <input name="category" required placeholder="Category" class="mt-3 w-full rounded-md border px-3 py-2">
            <input name="isbn" placeholder="ISBN" class="mt-3 w-full rounded-md border px-3 py-2">
            <input name="publisher" placeholder="Publisher" class="mt-3 w-full rounded-md border px-3 py-2">
            <div class="mt-3 grid grid-cols-3 gap-3">
                <select name="publication_year" class="rounded-md border px-3 py-2">
                    <option value="">Year</option>
                    <?php for ($year = 1900; $year <= 3030; $year++): ?>
                        <option value="<?= e((string) $year) ?>"><?= e((string) $year) ?></option>
                    <?php endfor; ?>
                </select>
                <input name="shelf_location" placeholder="Shelf" class="rounded-md border px-3 py-2">
                <input name="total_copies" type="number" min="1" value="1" class="rounded-md border px-3 py-2">
            </div>
            <button class="mt-4 rounded-md primary-button px-4 py-2 text-white">Save Book</button>
        </form>
        <?php books_table($books); ?>
    </div>
    <?php
}

function books_table(array $books): void
{
    $user = current_user();
    $canIssue = $user && $user['role'] === 'librarian';
    ?>
    <div class="overflow-x-auto rounded-md border bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-500"><tr><th class="px-4 py-3">Title</th><th class="px-4 py-3">Author</th><th class="px-4 py-3">Category</th><th class="px-4 py-3">Available</th><?php if ($canIssue): ?><th class="px-4 py-3">Action</th><?php endif; ?></tr></thead>
            <tbody class="divide-y">
            <?php foreach ($books as $book): ?>
                <tr>
                    <td class="px-4 py-3 font-medium"><?= e($book['title']) ?></td>
                    <td class="px-4 py-3"><?= e($book['author']) ?></td>
                    <td class="px-4 py-3"><?= e($book['category']) ?></td>
                    <td class="px-4 py-3"><?= e((string) $book['available_copies']) ?> / <?= e((string) $book['total_copies']) ?></td>
                    <?php if ($canIssue): ?>
                        <td class="px-4 py-3">
                            <?php if ((int) $book['available_copies'] > 0): ?>
                                <button type="button" class="icon-button rounded-md primary-button px-3 py-1.5 text-white" data-open-issue data-book-id="<?= e((string) $book['id']) ?>" data-book-title="<?= e($book['title']) ?>">
                                    <span class="material-symbols-outlined text-[18px]">assignment_add</span>
                                    Issue
                                </button>
                            <?php else: ?>
                                <span class="text-zinc-500">Unavailable</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$books): ?>
                <tr><td colspan="<?= $canIssue ? '5' : '4' ?>" class="px-4 py-8 text-center text-zinc-500">No books found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function issue_dialog(?array $books = null): void
{
    $books ??= db()->query('SELECT * FROM books WHERE available_copies > 0 ORDER BY title')->fetchAll();
    ?>
    <dialog id="issueDialog" class="issue-dialog rounded-md border bg-white p-0">
        <form method="post" action="?action=issue" class="spacious-form p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Issue Book</h2>
                <button type="button" class="rounded-full border px-3 py-1 text-sm" data-close-dialog>Cancel</button>
            </div>
            <p class="mt-2 text-sm text-zinc-500" data-book-title></p>
            <?php issue_fields($books); ?>
            <div class="mt-5 flex justify-end">
                <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
                    <span class="material-symbols-outlined text-[18px]">assignment_add</span>
                    Issue
                </button>
            </div>
        </form>
    </dialog>
    <?php
}

function issue_fields(array $books, bool $showBookSelect = false): void
{
    ?>
    <?php if ($showBookSelect): ?>
        <div>
            <label class="block text-sm font-medium">Book</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">menu_book</span>
                <select name="book_id" required class="w-full rounded-md border px-3 py-2">
                    <?php foreach ($books as $book): ?><option value="<?= e((string) $book['id']) ?>"><?= e($book['title']) ?> (<?= e((string) $book['available_copies']) ?> available)</option><?php endforeach; ?>
                </select>
            </div>
        </div>
    <?php else: ?>
        <input type="hidden" name="book_id" value="">
    <?php endif; ?>
    <div class="field-stack mt-4">
        <div>
            <label class="block text-sm font-medium">Book Type</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">category</span>
                <input name="book_type" required placeholder="Book type" class="w-full rounded-md border px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium">Borrower Type</label>
            <div class="mt-2 flex gap-4 text-sm">
                <label class="inline-flex items-center gap-2"><input type="radio" name="borrower_type" value="student" checked> Student</label>
                <label class="inline-flex items-center gap-2"><input type="radio" name="borrower_type" value="teacher"> Teacher</label>
            </div>
        </div>
        <div data-student-fields>
            <label class="block text-sm font-medium">Student Class</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">school</span>
                <select name="student_class" required class="w-full rounded-md border px-3 py-2">
                    <?php foreach (student_classes() as $class): ?>
                        <option value="<?= e($class) ?>"><?= e($class) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="mt-3 block text-sm font-medium">Stream</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">account_tree</span>
                <select name="student_stream" required class="w-full rounded-md border px-3 py-2">
                    <?php foreach (streams_for_class('Senior 1') as $stream): ?>
                        <option value="<?= e($stream) ?>"><?= e($stream) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium">Borrower Name</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">person</span>
                <input name="borrower_name" required placeholder="Borrower name" class="w-full rounded-md border px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium">Date of Borrow</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">calendar_month</span>
                <input name="issue_date" type="date" value="<?= e(date('Y-m-d')) ?>" required class="w-full rounded-md border px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium">Borrowing Period</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">schedule</span>
                <input name="borrowing_period" type="number" min="1" value="<?= e(setting('loan_days', '14')) ?>" required class="w-full rounded-md border px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium">Notes</label>
            <div class="input-with-icon mt-2">
                <span class="material-symbols-outlined text-[20px]">notes</span>
                <input name="notes" placeholder="Notes" class="w-full rounded-md border px-3 py-2">
            </div>
        </div>
    </div>
    <?php
}

function members(): void
{
    require_role('librarian');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $memberNo = post('member_no') ?: 'MEM-' . date('YmdHis');
        $stmt = db()->prepare('INSERT INTO members (member_no, name, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$memberNo, post('name'), post('email'), post('phone'), post('address'), post('status', 'active')]);
        flash('Member registered.');
        redirect('?action=members');
    }

    $members = db()->query('SELECT * FROM members ORDER BY created_at DESC')->fetchAll();
    page_header('Register Members');
    ?>
    <div class="grid gap-6 lg:grid-cols-[360px_1fr]">
        <form method="post" class="rounded-md border bg-white p-5">
            <h2 class="font-semibold">New Member</h2>
            <input name="member_no" placeholder="Member number" class="mt-4 w-full rounded-md border px-3 py-2">
            <input name="name" required placeholder="Full name" class="mt-3 w-full rounded-md border px-3 py-2">
            <input name="email" type="email" placeholder="Email" class="mt-3 w-full rounded-md border px-3 py-2">
            <input name="phone" placeholder="Phone" class="mt-3 w-full rounded-md border px-3 py-2">
            <textarea name="address" placeholder="Address" class="mt-3 w-full rounded-md border px-3 py-2"></textarea>
            <select name="status" class="mt-3 w-full rounded-md border px-3 py-2"><option>active</option><option>blocked</option></select>
            <button class="mt-4 rounded-md primary-button px-4 py-2 text-white">Register</button>
        </form>
        <div class="overflow-x-auto rounded-md border bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500"><tr><th class="px-4 py-3">Member No</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y">
                <?php foreach ($members as $member): ?>
                    <tr><td class="px-4 py-3"><?= e($member['member_no']) ?></td><td class="px-4 py-3"><?= e($member['name']) ?></td><td class="px-4 py-3"><?= e($member['phone']) ?></td><td class="px-4 py-3"><?= e($member['status']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    page_footer();
}

function issue_book(): void
{
    require_role('librarian');
    ensure_app_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        issue_book_from_post('?action=issue');
    }

    $books = db()->query('SELECT * FROM books WHERE available_copies > 0 ORDER BY title')->fetchAll();
    $borrowerType = $_GET['borrower_type'] ?? '';
    $activityStatus = $_GET['activity_status'] ?? '';
    $where = [];
    $params = [];
    if (in_array($borrowerType, ['teacher', 'student'], true)) {
        $where[] = 'loans.borrower_type = ?';
        $params[] = $borrowerType;
    }
    if (in_array($activityStatus, ['active', 'inactive'], true)) {
        $where[] = 'loans.activity_status = ?';
        $params[] = $activityStatus;
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT loans.*, books.title, members.name AS member_name FROM loans JOIN books ON books.id = loans.book_id JOIN members ON members.id = loans.member_id $sqlWhere ORDER BY loans.issue_date DESC, loans.id DESC");
    $stmt->execute($params);
    $loans = $stmt->fetchAll();

    page_header('Issue Books');
    ?>
    <div class="grid gap-6 lg:grid-cols-[460px_1fr]">
        <form method="post" class="spacious-form rounded-md border bg-white p-5">
            <h2 class="font-semibold">Borrowing Form</h2>
            <?php issue_fields($books, true); ?>
            <div class="mt-5 flex justify-end">
                <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
                    <span class="material-symbols-outlined text-[18px]">assignment_add</span>
                    Issue
                </button>
            </div>
        </form>
        <div>
            <form class="mb-5 grid gap-3 rounded-md border bg-white p-4 sm:grid-cols-3">
                <input type="hidden" name="action" value="issue">
                <div class="input-with-icon">
                    <span class="material-symbols-outlined text-[20px]">groups</span>
                    <select name="borrower_type" class="w-full rounded-md border px-3 py-2">
                        <option value="">Borrow type</option>
                        <option value="teacher" <?= $borrowerType === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                        <option value="student" <?= $borrowerType === 'student' ? 'selected' : '' ?>>Student</option>
                    </select>
                </div>
                <div class="input-with-icon">
                    <span class="material-symbols-outlined text-[20px]">task_alt</span>
                    <select name="activity_status" class="w-full rounded-md border px-3 py-2">
                        <option value="">Status</option>
                        <option value="active" <?= $activityStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $activityStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
                    <span class="material-symbols-outlined text-[18px]">search</span>
                    Search
                </button>
            </form>
            <?php issue_loans_table($loans); ?>
        </div>
    </div>
    <?php
    page_footer();
}

function issue_loans_table(array $loans): void
{
    ?>
    <div class="overflow-x-auto rounded-md border bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-500">
            <tr><th class="px-4 py-3">Borrower</th><th class="px-4 py-3">Book</th><th class="px-4 py-3">Borrowed</th><th class="px-4 py-3">Due Date</th><th class="px-4 py-3">Days Left</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y">
            <?php foreach ($loans as $loan): ?>
                <?php $left = days_left($loan['due_date']); ?>
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium"><?= e(borrower_label($loan)) ?></div>
                        <div class="text-xs text-zinc-500"><?= e(ucfirst($loan['borrower_type'] ?? 'student')) ?> · <?= e($loan['book_type'] ?? 'General') ?></div>
                        <?php if (($loan['borrower_type'] ?? '') === 'student' && !empty($loan['student_class'])): ?>
                            <div class="text-xs text-zinc-500"><?= e($loan['student_class']) ?> · <?= e($loan['student_stream'] ?? '') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><?= e($loan['title']) ?></td>
                    <td class="px-4 py-3"><?= e($loan['issue_date']) ?></td>
                    <td class="px-4 py-3"><?= e($loan['due_date']) ?></td>
                    <td class="px-4 py-3"><?= e($left >= 0 ? (string) $left : abs($left) . ' overdue') ?></td>
                    <td class="px-4 py-3"><?= e(ucfirst($loan['activity_status'] ?? 'active')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$loans): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No borrowing records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function returns(): void
{
    require_role('librarian');
    if (isset($_POST['loan_id'])) {
        $loanId = (int) post('loan_id');
        $stmt = db()->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if ($loan && $loan['status'] !== 'returned') {
            $fine = calculate_fine($loan['due_date']);
            db()->beginTransaction();
            db()->prepare("UPDATE loans SET status = 'returned', activity_status = 'inactive', return_date = CURDATE(), returned_by = ?, fine_amount = ? WHERE id = ?")->execute([current_user()['id'], $fine, $loanId]);
            db()->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = ?')->execute([$loan['book_id']]);
            if ($fine > 0) {
                db()->prepare('INSERT INTO fines (loan_id, member_id, amount) VALUES (?, ?, ?)')->execute([$loanId, $loan['member_id'], $fine]);
            }
            db()->commit();
            flash('Book return completed. Fine: ' . number_format($fine, 2));
        }
        redirect('?action=returns');
    }

    update_overdue_loans();
    $loans = db()->query("SELECT loans.*, books.title, members.name AS member_name FROM loans JOIN books ON books.id = loans.book_id JOIN members ON members.id = loans.member_id WHERE loans.status IN ('borrowed', 'overdue') ORDER BY loans.due_date")->fetchAll();
    page_header('Receive Returned Books');
    loans_table($loans, true);
    page_footer();
}

function overdue_loans(): array
{
    update_overdue_loans();
    return db()->query("SELECT loans.*, books.title, members.name AS member_name, members.phone AS member_phone, members.email AS member_email FROM loans JOIN books ON books.id = loans.book_id JOIN members ON members.id = loans.member_id WHERE loans.status = 'overdue' ORDER BY loans.due_date")->fetchAll();
}

function csv_value(?string $value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

function export_overdue(): void
{
    require_role('librarian');
    $loans = overdue_loans();
    $filename = 'overdue_members_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Member', 'Phone', 'Email', 'Borrower Type', 'Class', 'Stream', 'Book', 'Issued', 'Due', 'Days Overdue', 'Fine Today', 'Notes']);
    foreach ($loans as $loan) {
        $left = days_left($loan['due_date']);
        fputcsv($output, [
            csv_value(borrower_label($loan)),
            csv_value($loan['member_phone'] ?? ''),
            csv_value($loan['member_email'] ?? ''),
            csv_value(ucfirst($loan['borrower_type'] ?? 'student')),
            csv_value($loan['student_class'] ?? ''),
            csv_value($loan['student_stream'] ?? ''),
            csv_value($loan['title']),
            csv_value($loan['issue_date']),
            csv_value($loan['due_date']),
            (string) max(0, abs($left)),
            number_format(calculate_fine($loan['due_date']), 2, '.', ''),
            csv_value($loan['notes'] ?? ''),
        ]);
    }
    fclose($output);
    exit;
}

function overdue(): void
{
    require_role('librarian');
    $loans = overdue_loans();
    page_header('Overdue Books');
    ?>
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
        <div class="flex items-center gap-2 font-semibold">
            <span class="material-symbols-outlined text-[20px]">warning</span>
            <span><?= e((string) count($loans)) ?> overdue borrowing record(s)</span>
        </div>
        <a class="icon-button mt-3 rounded-md primary-button px-4 py-2 text-sm text-white" href="?action=export_overdue">
            <span class="material-symbols-outlined text-[18px]">download</span>
            Export Excel
        </a>
    </div>
    <?php loans_table($loans, true); ?>
    <?php
    page_footer();
}

function loans_table(array $loans, bool $withReturn = false): void
{
    ?>
    <div class="overflow-x-auto rounded-md border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400"><tr><th class="px-4 py-3">Book</th><th class="px-4 py-3">Member</th><th class="px-4 py-3">Class</th><th class="px-4 py-3">Stream</th><th class="px-4 py-3">Issued</th><th class="px-4 py-3">Due</th><th class="px-4 py-3">Fine Today</th><th class="px-4 py-3">Action</th></tr></thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <?php foreach ($loans as $loan): ?>
                <tr>
                    <td class="px-4 py-3"><?= e($loan['title']) ?></td>
                    <td class="px-4 py-3"><?= e($loan['member_name']) ?></td>
                    <td class="px-4 py-3"><?= e($loan['student_class'] ?? '') ?></td>
                    <td class="px-4 py-3"><?= e($loan['student_stream'] ?? '') ?></td>
                    <td class="px-4 py-3"><?= e($loan['issue_date']) ?></td>
                    <td class="px-4 py-3"><?= e($loan['due_date']) ?></td>
                    <td class="px-4 py-3"><?= number_format(calculate_fine($loan['due_date']), 2) ?></td>
                    <td class="px-4 py-3">
                        <?php if ($withReturn): ?>
                            <form method="post"><input type="hidden" name="loan_id" value="<?= e((string) $loan['id']) ?>"><button class="rounded-md bg-emerald-700 px-3 py-1.5 text-white">Return</button></form>
                        <?php else: ?>
                            <?= e($loan['status']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$loans): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function fines(): void
{
    require_role('librarian');
    if (isset($_POST['fine_id'])) {
        db()->prepare("UPDATE fines SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([(int) post('fine_id')]);
        flash('Fine marked as paid.');
        redirect('?action=fines');
    }

    $fines = db()->query("SELECT fines.*, members.name AS member_name, books.title FROM fines JOIN members ON members.id = fines.member_id JOIN loans ON loans.id = fines.loan_id JOIN books ON books.id = loans.book_id ORDER BY fines.created_at DESC")->fetchAll();
    page_header('Fine Management');
    ?>
    <div class="overflow-x-auto rounded-md border bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-500"><tr><th class="px-4 py-3">Member</th><th class="px-4 py-3">Book</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Action</th></tr></thead>
            <tbody class="divide-y">
            <?php foreach ($fines as $fine): ?>
                <tr><td class="px-4 py-3"><?= e($fine['member_name']) ?></td><td class="px-4 py-3"><?= e($fine['title']) ?></td><td class="px-4 py-3"><?= number_format((float) $fine['amount'], 2) ?></td><td class="px-4 py-3"><?= e($fine['status']) ?></td><td class="px-4 py-3"><?php if ($fine['status'] === 'unpaid'): ?><form method="post"><input type="hidden" name="fine_id" value="<?= e((string) $fine['id']) ?>"><button class="rounded-md primary-button px-3 py-1.5 text-white">Mark Paid</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    page_footer();
}

function search_books(): void
{
    require_login();
    $q = trim($_GET['q'] ?? '');
    $books = [];
    if ($q !== '') {
        $stmt = db()->prepare('SELECT * FROM books WHERE title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ? ORDER BY title');
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $like, $like]);
        $books = $stmt->fetchAll();
    }

    page_header('Search Books');
    ?>
    <form class="mb-5 flex max-w-2xl gap-3">
        <input type="hidden" name="action" value="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search by title, author, ISBN, or category" class="flex-1 rounded-md border px-3 py-2">
        <button class="icon-button rounded-md primary-button px-4 py-2 text-white">
            <span class="material-symbols-outlined text-[18px]">search</span>
            Search
        </button>
    </form>
    <?php books_table($books); ?>
    <?php
    page_footer();
}

function reports(bool $dailyOnly = false): void
{
    require_login();
    $date = $_GET['date'] ?? date('Y-m-d');
    $where = $dailyOnly ? 'WHERE loans.issue_date = ? OR loans.return_date = ?' : '';
    $stmt = db()->prepare("SELECT loans.*, books.title, members.name AS member_name FROM loans JOIN books ON books.id = loans.book_id JOIN members ON members.id = loans.member_id $where ORDER BY loans.created_at DESC");
    $dailyOnly ? $stmt->execute([$date, $date]) : $stmt->execute();
    $loans = $stmt->fetchAll();
    $fineTotal = db()->query("SELECT COALESCE(SUM(amount), 0) FROM fines")->fetchColumn();

    page_header($dailyOnly ? 'Daily Reports' : 'Reports');
    if ($dailyOnly): ?>
        <form class="mb-5 flex max-w-md gap-3"><input type="hidden" name="action" value="daily_report"><input name="date" type="date" value="<?= e($date) ?>" class="rounded-md border px-3 py-2"><button class="rounded-md primary-button px-4 py-2 text-white">View</button></form>
    <?php endif; ?>
    <div class="mb-5 grid gap-4 md:grid-cols-3">
        <?php card('Report Loans', (string) count($loans), 'sky'); ?>
        <?php card('Total Fine Records', (string) db()->query('SELECT COUNT(*) FROM fines')->fetchColumn(), 'amber'); ?>
        <?php card('All Fines Amount', number_format((float) $fineTotal, 2), 'emerald'); ?>
    </div>
    <?php loans_table($loans); ?>
    <?php
    page_footer();
}

function settings(): void
{
    require_role('admin');
    ensure_app_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $logoPath = upload_file('school_logo_file', 'school_logo');
        foreach (['school_name', 'school_motto', 'loan_days', 'daily_fine_rate', 'student_streams_s1_s4'] as $key) {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, post($key)]);
        }
        db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute(['library_name', post('school_name')]);
        if ($logoPath !== '') {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute(['school_logo', $logoPath]);
        }
        flash('Settings updated.');
        redirect('?action=settings');
    }

    page_header('System Settings');
    ?>
    <form method="post" enctype="multipart/form-data" class="max-w-xl rounded-md border bg-white p-5">
        <label class="block text-sm font-medium">School Name</label>
        <input name="school_name" value="<?= e(setting('school_name', setting('library_name', APP_NAME))) ?>" class="mt-2 w-full rounded-md border px-3 py-2">
        <label class="mt-4 block text-sm font-medium">School Motto</label>
        <input name="school_motto" value="<?= e(setting('school_motto', '')) ?>" class="mt-2 w-full rounded-md border px-3 py-2">
        <label class="mt-4 block text-sm font-medium">School Logo</label>
        <?php if (setting('school_logo', '') !== ''): ?>
            <img src="<?= e(setting('school_logo', '')) ?>" alt="School logo" class="mt-2 site-logo rounded-md border">
        <?php endif; ?>
        <input name="school_logo_file" type="file" accept="image/*" class="mt-2 w-full rounded-md border px-3 py-2">
        <label class="mt-4 block text-sm font-medium">Loan Days</label>
        <input name="loan_days" type="number" value="<?= e(setting('loan_days', '14')) ?>" class="mt-2 w-full rounded-md border px-3 py-2">
        <label class="mt-4 block text-sm font-medium">Daily Fine Rate</label>
        <input name="daily_fine_rate" type="number" value="<?= e(setting('daily_fine_rate', (string) DAILY_FINE_RATE)) ?>" class="mt-2 w-full rounded-md border px-3 py-2">
        <label class="mt-4 block text-sm font-medium">Senior 1 to Senior 4 Streams</label>
        <input name="student_streams_s1_s4" value="<?= e(setting('student_streams_s1_s4', 'A, B')) ?>" placeholder="A, B, C" class="mt-2 w-full rounded-md border px-3 py-2">
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Separate streams with commas. Senior 5 and Senior 6 use Arts and Sciences.</p>
        <button class="mt-5 rounded-md primary-button px-4 py-2 text-white">Save Settings</button>
    </form>
    <?php
    page_footer();
}

function backup(): void
{
    require_role('admin');
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    if (isset($_POST['backup'])) {
        $file = $backupDir . '/library_backup_' . date('Ymd_His') . '.sql';
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $sql = '';
        foreach ($tables as $table) {
            $create = db()->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
            $sql .= "\n\nDROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n";
            $rows = db()->query('SELECT * FROM `' . $table . '`')->fetchAll();
            foreach ($rows as $row) {
                $columns = array_map(fn($col) => "`$col`", array_keys($row));
                $values = array_map(fn($value) => $value === null ? 'NULL' : db()->quote((string) $value), array_values($row));
                $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            }
        }
        file_put_contents($file, $sql);
        flash('Backup created: ' . basename($file));
        redirect('?action=backup');
    }

    if (isset($_POST['restore']) && isset($_FILES['sql_file']) && is_uploaded_file($_FILES['sql_file']['tmp_name'])) {
        $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
        db()->exec($sql);
        flash('Database restored.');
        redirect('?action=backup');
    }

    $files = glob($backupDir . '/*.sql') ?: [];
    rsort($files);
    page_header('Backup Database');
    ?>
    <div class="grid gap-6 lg:grid-cols-2">
        <form method="post" class="rounded-md border bg-white p-5">
            <h2 class="font-semibold">Create Backup</h2>
            <button name="backup" value="1" class="mt-4 rounded-md primary-button px-4 py-2 text-white">Download-ready Backup</button>
        </form>
        <form method="post" enctype="multipart/form-data" class="rounded-md border bg-white p-5">
            <h2 class="font-semibold">Restore Backup</h2>
            <input type="file" name="sql_file" accept=".sql" required class="mt-4 w-full rounded-md border px-3 py-2">
            <button name="restore" value="1" class="mt-4 rounded-md bg-amber-700 px-4 py-2 text-white">Restore SQL</button>
        </form>
    </div>
    <div class="mt-6 rounded-md border bg-white p-5">
        <h2 class="font-semibold">Existing Backups</h2>
        <ul class="mt-3 divide-y text-sm">
            <?php foreach ($files as $file): ?><li class="py-2"><?= e(basename($file)) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php
    page_footer();
}

try {
    match ($action) {
        'login' => login_page(),
        'logout' => (session_destroy() || true) ? redirect('?action=login') : null,
        'dashboard' => dashboard(),
        'librarians' => librarians(),
        'books' => books(),
        'members' => members(),
        'issue' => issue_book(),
        'returns' => returns(),
        'overdue' => overdue(),
        'export_overdue' => export_overdue(),
        'fines' => fines(),
        'search' => search_books(),
        'reports' => reports(false),
        'daily_report' => reports(true),
        'settings' => settings(),
        'backup' => backup(),
        default => redirect('?action=dashboard'),
    };
} catch (Throwable $error) {
    http_response_code(500);
    page_header('Application Error');
    ?>
    <div class="rounded-md border border-red-200 bg-red-50 p-5 text-red-900">
        <h1 class="font-semibold">Something went wrong</h1>
        <p class="mt-2 text-sm"><?= e($error->getMessage()) ?></p>
    </div>
    <?php
    page_footer();
}
