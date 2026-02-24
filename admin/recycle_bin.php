<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . '/includes/auth.php');
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/logger.php');

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

if (!isAdmin()) {
    $_SESSION['error_message'] = 'You do not have permission to access Recycle Bin.';
    header('Location: dashboard.php');
    exit();
}

$valid_limits = [10, 25, 50, 100];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function recycleBindParams($stmt, $types, array &$values) {
    $bind = [$types];
    foreach ($values as $idx => &$value) {
        $bind[] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 10;
if (!in_array($table_limit, $valid_limits, true)) {
    $table_limit = 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $return_page = max(1, intval($_POST['return_page'] ?? 1));
    $return_limit = intval($_POST['return_limit'] ?? 10);
    if (!in_array($return_limit, $valid_limits, true)) {
        $return_limit = 10;
    }

    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['error'] = "CSRF token validation failed.";
        header("Location: recycle_bin.php?page={$return_page}&table_limit={$return_limit}");
        exit();
    }

    $recycle_id = intval($_POST['recycle_id'] ?? 0);
    if ($recycle_id <= 0) {
        $_SESSION['error'] = "Invalid recycle entry.";
        header("Location: recycle_bin.php?page={$return_page}&table_limit={$return_limit}");
        exit();
    }

    $allowed_tables = ['resolutions', 'ordinances', 'minutes_of_meeting'];

    try {
        $conn->begin_transaction();

        $entryStmt = $conn->prepare("SELECT id, original_table, original_id, data, restored_at FROM recycle_bin WHERE id = ? FOR UPDATE");
        if (!$entryStmt) {
            throw new Exception("Failed to prepare recycle lookup.");
        }
        $entryStmt->bind_param("i", $recycle_id);
        $entryStmt->execute();
        $entry = $entryStmt->get_result()->fetch_assoc();
        $entryStmt->close();

        if (!$entry) {
            throw new Exception("Recycle entry not found.");
        }
        if (!empty($entry['restored_at'])) {
            throw new Exception("This record has already been restored.");
        }
        if (!in_array($entry['original_table'], $allowed_tables, true)) {
            throw new Exception("Restore is not supported for this table.");
        }

        $record = json_decode((string)$entry['data'], true);
        if (!is_array($record) || empty($record)) {
            throw new Exception("Archived data is invalid or empty.");
        }

        $restoreId = isset($record['id']) ? (int)$record['id'] : 0;
        if ($restoreId <= 0) {
            $restoreId = (int)($entry['original_id'] ?? 0);
        }
        if ($restoreId > 0) {
            $record['id'] = $restoreId;
            $idCheckSql = "SELECT id FROM `{$entry['original_table']}` WHERE id = ? LIMIT 1";
            $idCheckStmt = $conn->prepare($idCheckSql);
            if (!$idCheckStmt) {
                throw new Exception("Failed to prepare restore ID check.");
            }
            $idCheckStmt->bind_param("i", $restoreId);
            $idCheckStmt->execute();
            $existingId = $idCheckStmt->get_result()->fetch_assoc();
            $idCheckStmt->close();
            if ($existingId) {
                throw new Exception("Cannot restore record because original ID already exists.");
            }
        } else {
            unset($record['id']);
        }

        $columns = [];
        $values = [];
        $types = '';
        foreach ($record as $column => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$column)) {
                continue;
            }
            $columns[] = "`{$column}`";
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $values[] = $value;
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        if (empty($columns)) {
            throw new Exception("No restorable columns found.");
        }

        $restoreSql = "INSERT INTO `{$entry['original_table']}` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", array_fill(0, count($columns), '?')) . ")";
        $restoreStmt = $conn->prepare($restoreSql);
        if (!$restoreStmt) {
            throw new Exception("Failed to prepare restore statement.");
        }
        if (!recycleBindParams($restoreStmt, $types, $values)) {
            $restoreStmt->close();
            throw new Exception("Failed to bind restore parameters.");
        }
        if (!$restoreStmt->execute()) {
            $error = $restoreStmt->error;
            $restoreStmt->close();
            throw new Exception("Restore insert failed: " . $error);
        }
        $restoreStmt->close();

        $updateStmt = $conn->prepare("UPDATE recycle_bin SET restored_at = CURRENT_TIMESTAMP WHERE id = ? AND restored_at IS NULL");
        if (!$updateStmt) {
            throw new Exception("Failed to prepare recycle update.");
        }
        $updateStmt->bind_param("i", $recycle_id);
        if (!$updateStmt->execute()) {
            $error = $updateStmt->error;
            $updateStmt->close();
            throw new Exception("Failed to update recycle status: " . $error);
        }
        $updateStmt->close();

        $conn->commit();
        $_SESSION['success'] = "Record restored successfully.";
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Recycle restore failed: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }

    header("Location: recycle_bin.php?page={$return_page}&table_limit={$return_limit}");
    exit();
}

$offset = ($page - 1) * $table_limit;

$total_entries = 0;
$countResult = $conn->query("SELECT COUNT(*) AS total FROM recycle_bin");
if ($countResult) {
    $row = $countResult->fetch_assoc();
    $total_entries = (int)($row['total'] ?? 0);
}

$total_pages = max(1, (int)ceil($total_entries / $table_limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $table_limit;
}

$entries = [];
$stmt = $conn->prepare("SELECT id, original_table, original_id, data, deleted_by, deleted_at, restored_at FROM recycle_bin ORDER BY deleted_at DESC LIMIT ? OFFSET ?");
if ($stmt) {
    $stmt->bind_param("ii", $table_limit, $offset);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function recycleDataPreview($jsonData) {
    if ($jsonData === null || $jsonData === '') {
        return 'No data';
    }

    $normalized = (string)$jsonData;
    $decoded = json_decode($normalized, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $normalized = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (strlen($normalized) > 120) {
        return substr($normalized, 0, 117) . '...';
    }

    return $normalized;
}

$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recycle Bin - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --primary-blue: #4361ee;
            --secondary-blue: #3a0ca3;
            --light-blue: #e8f0fe;
            --accent-orange: #ff6d00;
            --dark-gray: #2b2d42;
            --medium-gray: #8d99ae;
            --light-gray: #f8f9fa;
            --white: #ffffff;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            color: var(--dark-gray);
            min-height: 100vh;
            padding-top: 70px;
        }
        .management-container {
            margin-left: 250px;
            padding: 20px;
            margin-top: 0;
            transition: all 0.3s;
            margin-bottom: 60px;
            position: relative;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: sticky;
            top: 70px;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            padding: 15px 0;
            border-bottom: 2px solid var(--light-blue);
            flex-wrap: wrap;
            gap: 15px;
            z-index: 100;
        }
        .page-title {
            font-family: 'Montserrat', sans-serif;
            color: var(--secondary-blue);
            font-weight: 700;
            margin: 0;
            position: relative;
        }
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -17px;
            width: 60px;
            height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
        }
        .table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
        }
        .table-container {
            background-color: var(--white);
            border-radius: 16px;
            box-shadow: var(--box-shadow);
            padding: 0;
            margin-bottom: 0;
            overflow: hidden;
            position: relative;
            z-index: 0;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            max-height: calc(100vh - 360px);
            overflow-y: auto;
            display: block;
        }
        .table {
            margin-bottom: 0;
            min-width: 1000px;
        }
        .table th {
            background-color: var(--primary-blue);
            color: var(--white);
            font-weight: 600;
            border: none;
            padding: 12px 15px;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .table td {
            vertical-align: middle;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 8px;
            word-break: break-word;
        }
        .table tr:hover td {
            background-color: rgba(67, 97, 238, 0.05);
        }
        .data-preview {
            max-width: 360px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            cursor: help;
        }
        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        .shape {
            position: absolute;
            opacity: 0.1;
            transition: all 10s linear;
        }
        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary-blue);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            top: 10%;
            left: 10%;
            animation: float 15s infinite ease-in-out;
        }
        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--accent-orange);
            border-radius: 50%;
            bottom: 15%;
            right: 10%;
            animation: float 12s infinite ease-in-out reverse;
        }
        .shape-3 {
            width: 180px;
            height: 180px;
            background: var(--secondary-blue);
            border-radius: 50% 20% 50% 30%;
            top: 50%;
            right: 20%;
            animation: float 18s infinite ease-in-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }
        .pagination-container {
            position: sticky;
            bottom: 0;
            background: var(--white);
            padding: 15px 20px;
            border-top: 2px solid var(--light-blue);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            border-radius: 0 0 16px 16px;
            margin-bottom: 50px;
        }
        .pagination-info {
            font-weight: 600;
            color: var(--secondary-blue);
            background-color: rgba(67, 97, 238, 0.1);
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid var(--light-blue);
        }
        .page-link {
            border: 1px solid var(--light-blue);
            color: var(--primary-blue);
            font-weight: 500;
        }
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            border-color: var(--primary-blue);
        }
        /* Sidebar Base */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: linear-gradient(135deg, #1a3a8f, #1e40af);
            color: white;
            z-index: 1000;
            transition: all 0.3s;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 20px;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        .logo-container {
            display: flex;
            justify-content: center;
        }
        .sidebar-logo {
            width: 150px;
            height: 150px;
            object-fit: contain;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.15);
            padding: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s;
        }
        .sidebar-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .sidebar-subtitle {
            font-size: 0.85rem;
            opacity: 0.8;
        }
        .sidebar-menu {
            padding: 15px;
        }
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu ul li {
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
        }
        .sidebar-menu ul li a {
            display: block;
            padding: 10px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }
        .sidebar-menu ul li a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
            margin-right: 10px;
            transition: all 0.3s;
        }
        .sidebar-menu ul li:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        .sidebar-menu ul li:hover a {
            color: #fff;
            font-weight: 600;
        }
        .sidebar-menu ul li:hover a i {
            transform: scale(1.1);
        }
        .sidebar-menu ul li.active {
            background-color: rgba(255, 255, 255, 0.9);
        }
        .sidebar-menu ul li.active a {
            color: #1a3a8f;
            font-weight: 700;
        }
        #sidebarToggle {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        #sidebarToggle:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
            color: #fff;
        }
        @media (max-width: 992px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .sidebar.active {
                width: 250px;
            }
            .management-container {
                margin-left: 0;
                padding: 15px;
                margin-bottom: 60px;
            }
            .page-header {
                top: 60px;
            }
            .table-responsive {
                max-height: calc(100vh - 320px);
            }
            .pagination-container {
                padding: 10px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <div class="management-container">
        <div class="container-fluid">
            <div class="page-header">
                <h1 class="page-title">Recycle Bin</h1>
                <form method="GET" class="d-flex align-items-center gap-2">
                    <label for="table_limit" class="form-label mb-0">Rows:</label>
                    <select id="table_limit" name="table_limit" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($valid_limits as $limit): ?>
                            <option value="<?php echo $limit; ?>" <?php echo $table_limit === $limit ? 'selected' : ''; ?>>
                                <?php echo $limit; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="page" value="1">
                </form>
            </div>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-trash-restore-alt me-2"></i>
                    Showing <?php echo count($entries); ?> of <?php echo $total_entries; ?> recycled records
                </div>
            </div>

            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 7%;">ID</th>
                                <th style="width: 15%;">Original Table</th>
                                <th style="width: 8%;">Original ID</th>
                                <th style="width: 15%;">Deleted By</th>
                                <th style="width: 15%;">Deleted At</th>
                                <th style="width: 15%;">Restored At</th>
                                <th style="width: 18%;">Data</th>
                                <th style="width: 10%;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">Recycle bin is empty.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo (int)$entry['id']; ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars((string)$entry['original_table']); ?></span></td>
                                        <td><?php echo (int)$entry['original_id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)($entry['deleted_by'] ?? 'N/A')); ?></td>
                                        <td><?php echo !empty($entry['deleted_at']) ? date('M d, Y h:i:s A', strtotime($entry['deleted_at'])) : 'N/A'; ?></td>
                                        <td>
                                            <?php if (!empty($entry['restored_at'])): ?>
                                                <span class="badge bg-success mb-1">Restored</span><br>
                                                <small><?php echo date('M d, Y h:i:s A', strtotime($entry['restored_at'])); ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Restored</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-start">
                                            <span class="data-preview" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars((string)$entry['data']); ?>">
                                                <?php echo htmlspecialchars(recycleDataPreview($entry['data'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (empty($entry['restored_at'])): ?>
                                                <form method="POST" action="" onsubmit="return confirm('Restore this record?');" class="d-inline">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="recycle_id" value="<?php echo (int)$entry['id']; ?>">
                                                    <input type="hidden" name="return_page" value="<?php echo (int)$page; ?>">
                                                    <input type="hidden" name="return_limit" value="<?php echo (int)$table_limit; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-undo me-1"></i>Restore
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-success">Restored</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination-container d-flex justify-content-between align-items-center">
                    <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $p; ?>&table_limit=<?php echo $table_limit; ?>">
                                    <?php echo $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
