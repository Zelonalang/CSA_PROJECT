<?php

declare(strict_types=1);
session_start();

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$employeeFile = $storageDir . DIRECTORY_SEPARATOR . 'employees.json';
$taskFile = $storageDir . DIRECTORY_SEPARATOR . 'tasks.json';

function ensureStorage(string $dir, array $files): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($files as $file) {
        if (!file_exists($file)) {
            file_put_contents($file, '[]', LOCK_EX);
        }
    }
}

function readJsonRows(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
}

function writeJsonRows(string $file, array $rows): bool
{
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

function employeeNameMap(array $employees): array
{
    $map = [];
    foreach ($employees as $employee) {
        $id = (string)($employee['id'] ?? '');
        if ($id !== '') {
            $map[$id] = (string)($employee['employee_name'] ?? '');
        }
    }

    return $map;
}

function sanitizeCleanerIds($rawCleanerIds, array $validIds): array
{
    if (!is_array($rawCleanerIds)) {
        $rawCleanerIds = $rawCleanerIds !== null && $rawCleanerIds !== '' ? [(string)$rawCleanerIds] : [];
    }

    $cleanerIds = [];
    foreach ($rawCleanerIds as $id) {
        $id = trim((string)$id);
        if ($id !== '' && in_array($id, $validIds, true)) {
            $cleanerIds[] = $id;
        }
    }

    return array_values(array_unique($cleanerIds));
}

function normalizeRole(?string $role): string
{
    $role = strtolower(trim((string)$role));
    return $role === 'admin' ? 'admin' : 'cleaner';
}

function employeeRole(array $employee): string
{
    return normalizeRole((string)($employee['role'] ?? 'cleaner'));
}

function taskStatusFromCleanerIds(array $cleanerIds): string
{
    return count($cleanerIds) > 0 ? 'Assigned' : 'Open';
}

function manilaNow(): string
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
    return $dt->format('Y-m-d h:i A');
}

function currentUser(): ?array
{
    return isset($_SESSION['auth']) && is_array($_SESSION['auth']) ? $_SESSION['auth'] : null;
}

function isAuthorized(array $allowedRoles): bool
{
    $user = currentUser();
    if ($user === null) {
        return false;
    }

    $role = (string)($user['role'] ?? '');
    return in_array($role, $allowedRoles, true);
}

function getActorLabel(array $employees): string
{
    $user = currentUser();
    if ($user === null) {
        return 'System';
    }

    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return 'Admin';
    }

    $employeeId = (string)($user['employee_id'] ?? '');
    foreach ($employees as $employee) {
        if ((string)($employee['id'] ?? '') === $employeeId) {
            return (string)($employee['employee_name'] ?? 'Cleaner');
        }
    }

    return 'Cleaner';
}

ensureStorage($storageDir, [$employeeFile, $taskFile]);

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'login') {
        $role = trim((string)($_POST['role'] ?? ''));
        $employees = readJsonRows($employeeFile);

        if ($role === 'admin') {
            $_SESSION['auth'] = [
                'role' => 'admin',
                'name' => 'Administrator',
                'employee_id' => '',
            ];
            echo json_encode(['success' => true, 'redirect' => 'index.php?view=admin']);
            exit;
        }

        if ($role === 'cleaner') {
            $employeeId = trim((string)($_POST['employee_id'] ?? ''));
            if ($employeeId === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Cleaner is required.']);
                exit;
            }

            foreach ($employees as $employee) {
                if ((string)($employee['id'] ?? '') === $employeeId) {
                    if (strtolower((string)($employee['status'] ?? '')) !== 'active') {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'Only active cleaners can log in.']);
                        exit;
                    }

                    if (employeeRole($employee) !== 'cleaner') {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'Selected employee is not assigned as Cleaner role.']);
                        exit;
                    }

                    $_SESSION['auth'] = [
                        'role' => 'cleaner',
                        'name' => (string)($employee['employee_name'] ?? 'Cleaner'),
                        'employee_id' => $employeeId,
                    ];
                    echo json_encode(['success' => true, 'redirect' => 'index.php?view=cleaners']);
                    exit;
                }
            }

            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Cleaner not found.']);
            exit;
        }

        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid role.']);
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['success' => true, 'redirect' => 'index.php']);
        exit;
    }

    if (!isAuthorized(['admin', 'cleaner'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please log in first.']);
        exit;
    }

    if ($action === 'session_info') {
        echo json_encode(['success' => true, 'user' => currentUser()]);
        exit;
    }

    if ($action === 'employee_list') {
        $employees = readJsonRows($employeeFile);
        foreach ($employees as &$employee) {
            $employee['role'] = employeeRole($employee);
        }
        unset($employee);

        echo json_encode(['data' => $employees]);
        exit;
    }

    if (($action === 'employee_add' || $action === 'employee_update' || $action === 'task_add' || $action === 'task_update') && !isAuthorized(['admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admin can perform this action.']);
        exit;
    }

    if ($action === 'employee_add') {
        $name = trim((string)($_POST['employee_name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        $roleValue = normalizeRole((string)($_POST['role'] ?? 'cleaner'));

        if ($name === '' || $location === '' || $status === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'All employee fields are required.']);
            exit;
        }

        $employees = readJsonRows($employeeFile);
        $employees[] = [
            'id' => uniqid('emp_', true),
            'employee_name' => $name,
            'location' => $location,
            'status' => $status,
            'role' => $roleValue,
        ];

        if (!writeJsonRows($employeeFile, $employees)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to save employee data.']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'employee_update') {
        $id = trim((string)($_POST['id'] ?? ''));
        $name = trim((string)($_POST['employee_name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        $roleValue = normalizeRole((string)($_POST['role'] ?? 'cleaner'));

        if ($id === '' || $name === '' || $location === '' || $status === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'All employee fields are required.']);
            exit;
        }

        $employees = readJsonRows($employeeFile);
        $updated = false;

        foreach ($employees as &$employee) {
            if ((string)($employee['id'] ?? '') === $id) {
                $employee['employee_name'] = $name;
                $employee['location'] = $location;
                $employee['status'] = $status;
                $employee['role'] = $roleValue;
                $updated = true;
                break;
            }
        }
        unset($employee);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
            exit;
        }

        if (!writeJsonRows($employeeFile, $employees)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update employee data.']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_list' || $action === 'task_list_open') {
        $employees = readJsonRows($employeeFile);
        $nameMap = employeeNameMap($employees);
        $tasks = readJsonRows($taskFile);
        $rows = [];

        foreach ($tasks as $task) {
            $cleanerIds = isset($task['cleaner_ids']) && is_array($task['cleaner_ids']) ? $task['cleaner_ids'] : [];
            $cleanerNames = [];

            foreach ($cleanerIds as $id) {
                if (isset($nameMap[$id])) {
                    $cleanerNames[] = $nameMap[$id];
                }
            }

            $status = taskStatusFromCleanerIds($cleanerIds);
            if ($action === 'task_list_open' && $status !== 'Open') {
                continue;
            }

            $task['cleaner_ids'] = $cleanerIds;
            $task['cleaners'] = count($cleanerNames) > 0 ? implode(', ', $cleanerNames) : 'Unassigned';
            $task['status'] = $status;
            $task['history_count'] = isset($task['history']) && is_array($task['history']) ? count($task['history']) : 0;
            $rows[] = $task;
        }

        echo json_encode(['data' => $rows]);
        exit;
    }

    if ($action === 'task_add') {
        $taskNo = trim((string)($_POST['task_no'] ?? ''));
        $taskName = trim((string)($_POST['task_name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));

        if ($taskNo === '' || $taskName === '' || $location === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Task no., task name, and location are required.']);
            exit;
        }

        $employees = readJsonRows($employeeFile);
        $validIds = array_map(static fn($employee): string => (string)($employee['id'] ?? ''), $employees);
        $cleanerIds = sanitizeCleanerIds($_POST['cleaners'] ?? [], $validIds);

        $tasks = readJsonRows($taskFile);
        $tasks[] = [
            'id' => uniqid('task_', true),
            'task_no' => $taskNo,
            'task_name' => $taskName,
            'location' => $location,
            'created_at' => manilaNow(),
            'cleaner_ids' => $cleanerIds,
            'history' => [
                [
                    'event' => 'Created',
                    'by' => getActorLabel($employees),
                    'at' => manilaNow(),
                ],
            ],
        ];

        if (!writeJsonRows($taskFile, $tasks)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to save task data.']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_update') {
        $id = trim((string)($_POST['id'] ?? ''));
        $taskNo = trim((string)($_POST['task_no'] ?? ''));
        $taskName = trim((string)($_POST['task_name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));

        if ($id === '' || $taskNo === '' || $taskName === '' || $location === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Task fields are required.']);
            exit;
        }

        $employees = readJsonRows($employeeFile);
        $validIds = array_map(static fn($employee): string => (string)($employee['id'] ?? ''), $employees);
        $cleanerIds = sanitizeCleanerIds($_POST['cleaners'] ?? [], $validIds);

        $tasks = readJsonRows($taskFile);
        $updated = false;

        foreach ($tasks as &$task) {
            if ((string)($task['id'] ?? '') === $id) {
                $task['task_no'] = $taskNo;
                $task['task_name'] = $taskName;
                $task['location'] = $location;
                $task['cleaner_ids'] = $cleanerIds;

                if (!isset($task['history']) || !is_array($task['history'])) {
                    $task['history'] = [];
                }

                $task['history'][] = [
                    'event' => 'Updated',
                    'by' => getActorLabel($employees),
                    'at' => manilaNow(),
                ];

                $updated = true;
                break;
            }
        }
        unset($task);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task record not found.']);
            exit;
        }

        if (!writeJsonRows($taskFile, $tasks)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update task data.']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_grab') {
        $taskId = trim((string)($_POST['task_id'] ?? ''));
        $cleanerId = trim((string)($_POST['cleaner_id'] ?? ''));

        if ($taskId === '' || $cleanerId === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Task and cleaner are required.']);
            exit;
        }

        $user = currentUser();
        if (!isAuthorized(['admin', 'cleaner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized action.']);
            exit;
        }

        if (($user['role'] ?? '') === 'cleaner' && (string)($user['employee_id'] ?? '') !== $cleanerId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Cleaner can only grab task for own account.']);
            exit;
        }

        $employees = readJsonRows($employeeFile);
        $cleanerName = '';
        $cleanerActive = false;

        foreach ($employees as $employee) {
            if ((string)($employee['id'] ?? '') === $cleanerId) {
                $cleanerName = (string)($employee['employee_name'] ?? 'Cleaner');
                $cleanerActive = strtolower((string)($employee['status'] ?? '')) === 'active';
                break;
            }
        }

        if ($cleanerName === '') {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Cleaner not found.']);
            exit;
        }

        if (!$cleanerActive) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Inactive cleaner cannot grab tasks.']);
            exit;
        }

        $tasks = readJsonRows($taskFile);
        $updated = false;

        foreach ($tasks as &$task) {
            if ((string)($task['id'] ?? '') !== $taskId) {
                continue;
            }

            $currentCleanerIds = isset($task['cleaner_ids']) && is_array($task['cleaner_ids']) ? $task['cleaner_ids'] : [];
            if (count($currentCleanerIds) > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Task is already assigned.']);
                exit;
            }

            $task['cleaner_ids'] = [$cleanerId];

            if (!isset($task['history']) || !is_array($task['history'])) {
                $task['history'] = [];
            }

            $task['history'][] = [
                'event' => 'Grabbed',
                'by' => $cleanerName,
                'at' => manilaNow(),
            ];

            $updated = true;
            break;
        }
        unset($task);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task record not found.']);
            exit;
        }

        if (!writeJsonRows($taskFile, $tasks)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update task data.']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$user = currentUser();
$isLoggedIn = $user !== null;
$role = $isLoggedIn ? (string)($user['role'] ?? '') : '';
$viewParam = (string)($_GET['view'] ?? 'admin');
$requestedView = in_array($viewParam, ['admin', 'cleaners', 'settings'], true) ? $viewParam : 'admin';
$view = $role === 'cleaner' ? 'cleaners' : $requestedView;

$employeesForLogin = readJsonRows($employeeFile);
$activeEmployeesForLogin = array_values(array_filter($employeesForLogin, static function ($employee): bool {
    return strtolower((string)($employee['status'] ?? '')) === 'active'
        && employeeRole($employee) === 'cleaner';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aphrodite Task Board</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg: #edf2f7;
            --sidebar: #16324f;
            --sidebar-accent: #2f5d89;
            --panel-bg: #ffffff;
            --primary: #0f4c81;
            --open: #ce6a17;
            --assigned: #0c7c59;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(155deg, #f8fbff 0%, var(--bg) 100%);
        }

        .login-wrap {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
        }

        .login-card {
            width: min(480px, 96vw);
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(22, 50, 79, 0.16);
            border: 1px solid #dfe7f0;
            padding: 24px;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .side-menu {
            width: 250px;
            background: linear-gradient(180deg, var(--sidebar) 0%, #11263c 100%);
            color: #fff;
            transition: width 0.25s ease;
            overflow: hidden;
            position: sticky;
            top: 0;
            height: 100vh;
        }

        .side-menu.collapsed {
            width: 78px;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }

        .brand-name {
            font-weight: 700;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .side-menu.collapsed .brand-name,
        .side-menu.collapsed .menu-text,
        .side-menu.collapsed .user-name {
            display: none;
        }

        .menu-list {
            list-style: none;
            margin: 0;
            padding: 14px 10px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #fff;
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .menu-link:hover,
        .menu-link.active {
            background: var(--sidebar-accent);
            color: #fff;
        }

        .menu-footer {
            padding: 10px 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            position: absolute;
            bottom: 0;
            width: 100%;
        }

        .user-name {
            display: block;
            font-size: 0.85rem;
            opacity: 0.85;
            margin-bottom: 10px;
        }

        .main-content {
            flex: 1;
            padding: 24px;
        }

        .page-title {
            font-weight: 700;
            margin: 0;
        }

        .split-layout {
            display: grid;
            grid-template-columns: 7fr 3fr;
            gap: 16px;
            margin-top: 16px;
        }

        .panel-card {
            background: var(--panel-bg);
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(15, 76, 129, 0.1);
            border: 1px solid #dfe7f0;
        }

        .panel-card .card-body {
            padding: 20px;
        }

        .section-title {
            font-weight: 700;
            margin-bottom: 2px;
        }

        .section-caption {
            color: #5e6977;
            font-size: 0.92rem;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: #0b3d68;
            border-color: #0b3d68;
        }

        .status-pill {
            display: inline-block;
            padding: 4px 9px;
            font-size: 12px;
            border-radius: 999px;
            font-weight: 600;
        }

        .status-open {
            background: #fde8d8;
            color: var(--open);
        }

        .status-assigned {
            background: #d8f3ea;
            color: var(--assigned);
        }

        .action-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .history-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 999px;
            background: #e9eff6;
            color: #345;
        }

        @media (max-width: 1100px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 14px;
            }

            .side-menu {
                width: 78px;
            }

            .side-menu .brand-name,
            .side-menu .menu-text,
            .side-menu .user-name {
                display: none;
            }
        }
    </style>
</head>
<body>
<?php if (!$isLoggedIn): ?>
    <div class="login-wrap">
        <div class="login-card">
            <h3 class="mb-1">Aphrodite Login</h3>
            <p class="text-muted">Sign in as Admin or Cleaner (from active cleaner users).</p>
            <form id="loginForm">
                <div class="mb-3">
                    <label class="form-label" for="loginRole">Role</label>
                    <select class="form-select" id="loginRole" name="role" required>
                        <option value="">Select role</option>
                        <option value="admin">Admin</option>
                        <option value="cleaner">Cleaner</option>
                    </select>
                </div>
                <div class="mb-3 d-none" id="cleanerSelectWrap">
                    <label class="form-label" for="loginCleaner">Cleaner (active only)</label>
                    <select class="form-select" id="loginCleaner" name="employee_id">
                        <option value="">Select cleaner</option>
                        <?php foreach ($activeEmployeesForLogin as $employee): ?>
                            <option value="<?= htmlspecialchars((string)$employee['id']) ?>">
                                <?= htmlspecialchars((string)$employee['employee_name']) ?> - <?= htmlspecialchars((string)$employee['location']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="layout">
        <aside class="side-menu" id="sideMenu">
            <div class="brand">
                <span class="brand-name">Aphrodite</span>
                <button type="button" class="btn btn-sm btn-outline-light" id="btnToggleSidebar" title="Toggle menu">
                    <i class="bi bi-list"></i>
                </button>
            </div>
            <ul class="menu-list">
                <?php if ($role === 'admin'): ?>
                    <li>
                        <a class="menu-link <?= $view === 'admin' ? 'active' : '' ?>" href="index.php?view=admin">
                            <i class="bi bi-speedometer2"></i>
                            <span class="menu-text">Admin</span>
                        </a>
                    </li>
                    <li>
                        <a class="menu-link <?= $view === 'settings' ? 'active' : '' ?>" href="index.php?view=settings">
                            <i class="bi bi-gear"></i>
                            <span class="menu-text">Settings</span>
                        </a>
                    </li>
                <?php endif; ?>
                <li>
                    <a class="menu-link <?= $view === 'cleaners' ? 'active' : '' ?>" href="index.php?view=cleaners">
                        <i class="bi bi-people"></i>
                        <span class="menu-text">Cleaners</span>
                    </a>
                </li>
            </ul>
            <div class="menu-footer">
                <span class="user-name"><?= htmlspecialchars((string)($user['name'] ?? 'User')) ?></span>
                <button type="button" class="btn btn-sm btn-outline-light w-100" id="btnLogout">Logout</button>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($view === 'admin'): ?>
                <h2 class="page-title">Admin Page</h2>
                <p class="section-caption mb-0">Manage tasks, assignment, status, and employee records.</p>

                <div class="split-layout">
                    <div class="panel-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h3 class="section-title">Task Container</h3>
                                    <p class="section-caption mb-0">70% area for task management.</p>
                                </div>
                                <button type="button" class="btn btn-primary" id="btnAddTask">Add Task</button>
                            </div>
                            <div class="table-responsive">
                                <table id="taskTableAdmin" class="table table-striped align-middle w-100">
                                    <thead>
                                    <tr>
                                        <th>Task No.</th>
                                        <th>Task Name</th>
                                        <th>Location</th>
                                        <th>Date &amp; Time Created (Manila)</th>
                                        <th>Cleaners</th>
                                        <th>Status</th>
                                        <th>History</th>
                                        <th style="width: 90px;">Action</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h3 class="section-title">Employee Container</h3>
                                    <p class="section-caption mb-0">30% right-side employee list.</p>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" id="btnAddEmployee">Add</button>
                            </div>
                            <div class="table-responsive">
                                <table id="employeeTableAdmin" class="table table-sm table-striped align-middle w-100">
                                    <thead>
                                    <tr>
                                        <th>Employee Name</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Role</th>
                                        <th style="width: 70px;">Action</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($view === 'settings' && $role === 'admin'): ?>
                <h2 class="page-title">Settings</h2>
                <p class="section-caption mb-0">Edit users and assign roles using existing employee records. Data is saved in JSON.</p>

                <div class="panel-card mt-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h3 class="section-title">Users and Roles</h3>
                                <p class="section-caption mb-0">Add user accounts and set role as Admin or Cleaner.</p>
                            </div>
                            <button type="button" class="btn btn-primary" id="btnSettingsAddUser">Add User</button>
                        </div>
                        <div class="table-responsive">
                            <table id="settingsUserTable" class="table table-striped align-middle w-100">
                                <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Role</th>
                                    <th style="width: 80px;">Action</th>
                                </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <h2 class="page-title">Cleaners Page</h2>
                <p class="section-caption mb-0">Grab open tasks. Inactive cleaners are blocked automatically.</p>

                <div class="split-layout">
                    <div class="panel-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h3 class="section-title">Open Tasks</h3>
                                    <p class="section-caption mb-0">Grab available tasks and auto-assign to your account.</p>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table id="taskTableCleaner" class="table table-striped align-middle w-100">
                                    <thead>
                                    <tr>
                                        <th>Task No.</th>
                                        <th>Task Name</th>
                                        <th>Location</th>
                                        <th>Date &amp; Time Created (Manila)</th>
                                        <th>Status</th>
                                        <th>History</th>
                                        <th style="width: 100px;">Action</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <div class="card-body">
                            <h3 class="section-title">My Account</h3>
                            <p class="section-caption"><?= htmlspecialchars((string)($user['name'] ?? 'Cleaner')) ?></p>
                            <div class="table-responsive">
                                <table id="employeeTableCleaner" class="table table-sm table-striped align-middle w-100">
                                    <thead>
                                    <tr>
                                        <th>Employee Name</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($role === 'admin'): ?>
        <div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="taskModalLabel">Add Task</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="taskForm">
                        <div class="modal-body">
                            <input type="hidden" id="taskId" name="id">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="taskNo" class="form-label">Task No.</label>
                                    <input type="text" class="form-control" id="taskNo" name="task_no" required>
                                </div>
                                <div class="col-md-8">
                                    <label for="taskName" class="form-label">Task Name</label>
                                    <input type="text" class="form-control" id="taskName" name="task_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="taskLocation" class="form-label">Location</label>
                                    <input type="text" class="form-control" id="taskLocation" name="location" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="taskCleaners" class="form-label">Assign Employee/Cleaners</label>
                                    <select class="form-select" id="taskCleaners" name="cleaners[]" multiple size="5"></select>
                                    <div class="form-text">Hold Ctrl key to select multiple cleaners.</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="btnSaveTask">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="employeeModalLabel">Add Employee</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="employeeForm">
                        <div class="modal-body">
                            <input type="hidden" id="employeeId" name="id">
                            <div class="mb-3">
                                <label for="employeeName" class="form-label">Employee Name</label>
                                <input type="text" class="form-control" id="employeeName" name="employee_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="employeeLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="employeeLocation" name="location" required>
                            </div>
                            <div class="mb-0">
                                <label for="employeeStatus" class="form-label">Status</label>
                                <select class="form-select" id="employeeStatus" name="status" required>
                                    <option value="">Select status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="On Leave">On Leave</option>
                                </select>
                            </div>
                            <div class="mt-3">
                                <label for="employeeRole" class="form-label">Role</label>
                                <select class="form-select" id="employeeRole" name="role" required>
                                    <option value="cleaner">Cleaner</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="btnSaveEmployee">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>
<script>
    const endpoint = 'index.php';
    const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    const role = '<?= htmlspecialchars($role, ENT_QUOTES) ?>';
    const view = '<?= htmlspecialchars($view, ENT_QUOTES) ?>';
    const cleanerSelfId = '<?= $isLoggedIn ? htmlspecialchars((string)($user['employee_id'] ?? ''), ENT_QUOTES) : '' ?>';

    function renderStatusPill(status) {
        const cls = status === 'Assigned' ? 'status-assigned' : 'status-open';
        return '<span class="status-pill ' + cls + '">' + status + '</span>';
    }

    async function doLogout() {
        const body = new FormData();
        body.append('action', 'logout');
        const response = await fetch(endpoint, { method: 'POST', body: body });
        const result = await response.json();
        if (response.ok && result.success) {
            window.location.href = result.redirect || 'index.php';
        }
    }

    if (!isLoggedIn) {
        const loginForm = document.getElementById('loginForm');
        const loginRole = document.getElementById('loginRole');
        const cleanerSelectWrap = document.getElementById('cleanerSelectWrap');
        const loginCleaner = document.getElementById('loginCleaner');

        loginRole.addEventListener('change', function () {
            const isCleaner = loginRole.value === 'cleaner';
            cleanerSelectWrap.classList.toggle('d-none', !isCleaner);
            loginCleaner.required = isCleaner;
        });

        loginForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const formData = new FormData(loginForm);
            formData.append('action', 'login');

            try {
                const response = await fetch(endpoint, { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Login failed.');
                }

                window.location.href = result.redirect || 'index.php';
            } catch (error) {
                alert(error.message);
            }
        });
    }

    if (isLoggedIn) {
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        if (btnToggleSidebar) {
            btnToggleSidebar.addEventListener('click', function () {
                document.getElementById('sideMenu').classList.toggle('collapsed');
            });
        }

        const btnLogout = document.getElementById('btnLogout');
        if (btnLogout) {
            btnLogout.addEventListener('click', function () {
                doLogout().catch(function () {
                    alert('Unable to logout now.');
                });
            });
        }
    }

    if (isLoggedIn && role === 'admin' && (view === 'admin' || view === 'settings')) {
        const employeeModal = new bootstrap.Modal(document.getElementById('employeeModal'));
        const employeeForm = document.getElementById('employeeForm');
        const employeeIdField = document.getElementById('employeeId');
        const employeeNameField = document.getElementById('employeeName');
        const employeeLocationField = document.getElementById('employeeLocation');
        const employeeStatusField = document.getElementById('employeeStatus');
        const employeeRoleField = document.getElementById('employeeRole');
        const employeeModalLabel = document.getElementById('employeeModalLabel');
        const btnSaveEmployee = document.getElementById('btnSaveEmployee');

        let employeeTable = null;
        let taskTable = null;
        let settingsUserTable = null;

        function resetEmployeeForm() {
            employeeForm.reset();
            employeeIdField.value = '';
            employeeStatusField.value = 'Active';
            employeeRoleField.value = 'cleaner';
            employeeModalLabel.textContent = 'Add Employee';
            btnSaveEmployee.textContent = 'Save';
        }

        function openEmployeeEdit(row) {
            employeeIdField.value = row.id;
            employeeNameField.value = row.employee_name || '';
            employeeLocationField.value = row.location || '';
            employeeStatusField.value = row.status || '';
            employeeRoleField.value = row.role || 'cleaner';
            employeeModalLabel.textContent = 'Update Employee';
            btnSaveEmployee.textContent = 'Update';
            employeeModal.show();
        }

        if (view === 'admin') {
            const taskModal = new bootstrap.Modal(document.getElementById('taskModal'));
            const taskForm = document.getElementById('taskForm');
            const taskIdField = document.getElementById('taskId');
            const taskNoField = document.getElementById('taskNo');
            const taskNameField = document.getElementById('taskName');
            const taskLocationField = document.getElementById('taskLocation');
            const taskCleanersField = document.getElementById('taskCleaners');
            const taskModalLabel = document.getElementById('taskModalLabel');
            const btnSaveTask = document.getElementById('btnSaveTask');

            taskTable = $('#taskTableAdmin').DataTable({
                ajax: endpoint + '?action=task_list',
                columns: [
                    { data: 'task_no' },
                    { data: 'task_name' },
                    { data: 'location' },
                    { data: 'created_at' },
                    { data: 'cleaners' },
                    {
                        data: 'status',
                        render: function (data) {
                            return renderStatusPill(data || 'Open');
                        }
                    },
                    {
                        data: 'history_count',
                        render: function (data) {
                            return '<span class="history-badge">' + (data || 0) + ' logs</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: function () {
                            return '<button type="button" class="btn btn-outline-primary action-icon btn-task-edit" title="Update Task"><i class="bi bi-pencil-square"></i></button>';
                        }
                    }
                ]
            });

            employeeTable = $('#employeeTableAdmin').DataTable({
                ajax: endpoint + '?action=employee_list',
                paging: true,
                columns: [
                    { data: 'employee_name' },
                    { data: 'location' },
                    {
                        data: 'status',
                        render: function (data) {
                            return '<span class="status-pill status-assigned">' + data + '</span>';
                        }
                    },
                    {
                        data: 'role',
                        render: function (data) {
                            return '<span class="history-badge text-uppercase">' + (data || 'cleaner') + '</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: function () {
                            return '<button type="button" class="btn btn-outline-primary action-icon btn-employee-edit" title="Update Employee"><i class="bi bi-pencil-square"></i></button>';
                        }
                    }
                ]
            });

            function fillCleanerOptions(selectedIds = []) {
                const employees = employeeTable.rows().data().toArray().filter(function (employee) {
                    return (employee.role || 'cleaner') === 'cleaner';
                });

                taskCleanersField.innerHTML = '';

                if (employees.length === 0) {
                    const option = document.createElement('option');
                    option.disabled = true;
                    option.textContent = 'No cleaner-role users yet';
                    taskCleanersField.appendChild(option);
                    return;
                }

                employees.forEach(function (employee) {
                    const option = document.createElement('option');
                    option.value = employee.id;
                    option.textContent = employee.employee_name + ' (' + employee.location + ')';
                    if (selectedIds.includes(employee.id)) {
                        option.selected = true;
                    }
                    taskCleanersField.appendChild(option);
                });
            }

            function resetTaskForm() {
                taskForm.reset();
                taskIdField.value = '';
                taskModalLabel.textContent = 'Add Task';
                btnSaveTask.textContent = 'Save';
                fillCleanerOptions([]);
            }

            document.getElementById('btnAddTask').addEventListener('click', function () {
                resetTaskForm();
                taskModal.show();
            });

            document.getElementById('btnAddEmployee').addEventListener('click', function () {
                resetEmployeeForm();
                employeeModal.show();
            });

            document.querySelector('#taskTableAdmin').addEventListener('click', function (event) {
                const btn = event.target.closest('.btn-task-edit');
                if (!btn) {
                    return;
                }

                const clickedTr = btn.closest('tr');
                if (!clickedTr) {
                    return;
                }

                let row = taskTable.row(clickedTr).data();
                if (!row && clickedTr.previousElementSibling) {
                    row = taskTable.row(clickedTr.previousElementSibling).data();
                }

                if (!row) {
                    return;
                }

                taskIdField.value = row.id;
                taskNoField.value = row.task_no || '';
                taskNameField.value = row.task_name || '';
                taskLocationField.value = row.location || '';
                fillCleanerOptions(Array.isArray(row.cleaner_ids) ? row.cleaner_ids : []);
                taskModalLabel.textContent = 'Update Task';
                btnSaveTask.textContent = 'Update';
                taskModal.show();
            });

            document.querySelector('#employeeTableAdmin').addEventListener('click', function (event) {
                const btn = event.target.closest('.btn-employee-edit');
                if (!btn) {
                    return;
                }

                const clickedTr = btn.closest('tr');
                if (!clickedTr) {
                    return;
                }

                let row = employeeTable.row(clickedTr).data();
                if (!row && clickedTr.previousElementSibling) {
                    row = employeeTable.row(clickedTr.previousElementSibling).data();
                }

                if (!row) {
                    return;
                }

                openEmployeeEdit(row);
            });

            employeeTable.on('draw', function () {
                if (!taskIdField.value) {
                    fillCleanerOptions([]);
                }
            });

            taskForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                const formData = new FormData(taskForm);
                formData.append('action', taskIdField.value ? 'task_update' : 'task_add');
                btnSaveTask.disabled = true;

                try {
                    const response = await fetch(endpoint, { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Task request failed.');
                    }

                    taskModal.hide();
                    taskTable.ajax.reload(null, false);
                } catch (error) {
                    alert(error.message);
                } finally {
                    btnSaveTask.disabled = false;
                }
            });
        }

        if (view === 'settings') {
            settingsUserTable = $('#settingsUserTable').DataTable({
                ajax: endpoint + '?action=employee_list',
                columns: [
                    { data: 'employee_name' },
                    { data: 'location' },
                    {
                        data: 'status',
                        render: function (data) {
                            return '<span class="status-pill status-assigned">' + data + '</span>';
                        }
                    },
                    {
                        data: 'role',
                        render: function (data) {
                            return '<span class="history-badge text-uppercase">' + (data || 'cleaner') + '</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: function () {
                            return '<button type="button" class="btn btn-outline-primary action-icon btn-settings-user-edit" title="Edit User"><i class="bi bi-pencil-square"></i></button>';
                        }
                    }
                ]
            });

            document.getElementById('btnSettingsAddUser').addEventListener('click', function () {
                resetEmployeeForm();
                employeeModal.show();
            });

            document.querySelector('#settingsUserTable').addEventListener('click', function (event) {
                const btn = event.target.closest('.btn-settings-user-edit');
                if (!btn) {
                    return;
                }

                const clickedTr = btn.closest('tr');
                if (!clickedTr) {
                    return;
                }

                let row = settingsUserTable.row(clickedTr).data();
                if (!row && clickedTr.previousElementSibling) {
                    row = settingsUserTable.row(clickedTr.previousElementSibling).data();
                }

                if (!row) {
                    return;
                }

                openEmployeeEdit(row);
            });
        }

        employeeForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const formData = new FormData(employeeForm);
            formData.append('action', employeeIdField.value ? 'employee_update' : 'employee_add');
            btnSaveEmployee.disabled = true;

            try {
                const response = await fetch(endpoint, { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Employee request failed.');
                }

                employeeModal.hide();
                if (employeeTable) {
                    employeeTable.ajax.reload(null, false);
                }
                if (settingsUserTable) {
                    settingsUserTable.ajax.reload(null, false);
                }
                if (taskTable) {
                    taskTable.ajax.reload(null, false);
                }
            } catch (error) {
                alert(error.message);
            } finally {
                btnSaveEmployee.disabled = false;
            }
        });
    }

    if (isLoggedIn && view === 'cleaners') {
        const employeeTableCleaner = $('#employeeTableCleaner').DataTable({
            ajax: endpoint + '?action=employee_list',
            paging: true,
            columns: [
                { data: 'employee_name' },
                { data: 'location' },
                {
                    data: 'status',
                    render: function (data) {
                        return '<span class="status-pill status-assigned">' + data + '</span>';
                    }
                }
            ]
        });

        const taskTableCleaner = $('#taskTableCleaner').DataTable({
            ajax: endpoint + '?action=task_list_open',
            columns: [
                { data: 'task_no' },
                { data: 'task_name' },
                { data: 'location' },
                { data: 'created_at' },
                {
                    data: 'status',
                    render: function (data) {
                        return renderStatusPill(data || 'Open');
                    }
                },
                {
                    data: 'history_count',
                    render: function (data) {
                        return '<span class="history-badge">' + (data || 0) + ' logs</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function () {
                        return '<button type="button" class="btn btn-sm btn-primary btn-grab-task">Grab</button>';
                    }
                }
            ]
        });

        document.querySelector('#taskTableCleaner').addEventListener('click', async function (event) {
            const btn = event.target.closest('.btn-grab-task');
            if (!btn) {
                return;
            }

            if (!cleanerSelfId) {
                alert('Cleaner account is not linked.');
                return;
            }

            const clickedTr = btn.closest('tr');
            if (!clickedTr) {
                return;
            }

            let row = taskTableCleaner.row(clickedTr).data();
            if (!row && clickedTr.previousElementSibling) {
                row = taskTableCleaner.row(clickedTr.previousElementSibling).data();
            }

            if (!row) {
                return;
            }

            btn.disabled = true;
            try {
                const body = new FormData();
                body.append('action', 'task_grab');
                body.append('task_id', row.id);
                body.append('cleaner_id', cleanerSelfId);

                const response = await fetch(endpoint, { method: 'POST', body: body });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to grab task.');
                }

                taskTableCleaner.ajax.reload(null, false);
                employeeTableCleaner.ajax.reload(null, false);
            } catch (error) {
                alert(error.message);
            } finally {
                btn.disabled = false;
            }
        });
    }
</script>
</body>
</html>
