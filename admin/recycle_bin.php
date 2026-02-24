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

$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 10;
$valid_limits = [10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits, true)) {
    $table_limit = 10;
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body { background: #f5f7fa; font-family: 'Poppins', sans-serif; padding-top: 40px; }
        .dashboard-container { margin-left: 250px; padding: 20px; margin-top: 0; margin-bottom: 60px; }
        .section-card { border: 0; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        .section-header { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: #fff; border-radius: 14px 14px 0 0; }
        .table thead th { background-color: #1a3a8f; color: #fff; vertical-align: middle; }
        .data-preview { max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (max-width: 992px) {
            .dashboard-container { margin-left: 0; padding: 15px; }
        }
    </style>
</head>
<body>
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <div class="dashboard-container">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0 text-primary"><i class="fas fa-recycle me-2"></i>Recycle Bin</h1>
                <form method="GET" class="d-flex align-items-center gap-2">
                    <label for="table_limit" class="mb-0 small text-muted">Rows</label>
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

            <div class="card section-card">
                <div class="card-header section-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-trash-restore-alt me-2"></i>Deleted Records</span>
                    <span class="badge bg-light text-dark"><?php echo $total_entries; ?> total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Original Table</th>
                                    <th>Original ID</th>
                                    <th>Deleted By</th>
                                    <th>Deleted At</th>
                                    <th>Restored At</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($entries)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">Recycle bin is empty.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($entries as $entry): ?>
                                        <tr>
                                            <td><?php echo (int)$entry['id']; ?></td>
                                            <td><?php echo htmlspecialchars((string)$entry['original_table']); ?></td>
                                            <td><?php echo (int)$entry['original_id']; ?></td>
                                            <td><?php echo htmlspecialchars((string)($entry['deleted_by'] ?? 'N/A')); ?></td>
                                            <td><?php echo !empty($entry['deleted_at']) ? date('M d, Y h:i:s A', strtotime($entry['deleted_at'])) : 'N/A'; ?></td>
                                            <td>
                                                <?php echo !empty($entry['restored_at']) ? date('M d, Y h:i:s A', strtotime($entry['restored_at'])) : '<span class="badge bg-secondary">Not Restored</span>'; ?>
                                            </td>
                                            <td class="data-preview" title="<?php echo htmlspecialchars((string)$entry['data']); ?>">
                                                <?php echo htmlspecialchars(recycleDataPreview($entry['data'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination pagination-sm justify-content-end">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $p; ?>&table_limit=<?php echo $table_limit; ?>">
                                    <?php echo $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
