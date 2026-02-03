<div class="sidebar" id="sidebar" style="background: linear-gradient(135deg, #1a3a8f, #1e40af); color: white;">
    <div class="sidebar-header text-center p-3">
        <div class="logo-container mb-3">
            <img src="./images/logo_pbsth.png" alt="Barangay Poblacion South Logo" class="sidebar-logo" style="max-width: 100px; height: auto;">
        </div>
        <h5 class="text-white mb-1 sidebar-title">Barangay <br>Poblacion South</h5>
        <p class="text-white-80 mb-0 sidebar-subtitle" style="font-size: 1rem;">
            Solano, Nueva Vizcaya<br>
            3709, Region 2, Philippines
        </p>
        <button class="btn btn-sm btn-light d-md-none position-absolute top-0 end-0 mt-2 me-2" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    <div class="sidebar-menu px-2">
        <ul class="list-unstyled">
            <li class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                <a href="dashboard.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-dark text-dark rounded' : '' ?>">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            <li class="<?= basename($_SERVER['PHP_SELF']) == 'ordinances.php' ? 'active' : '' ?>">
                <a href="ordinances.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'ordinances.php' ? 'bg-dark text-dark rounded' : '' ?>">
                    <i class="fas fa-file-alt me-2"></i> Ordinances
                </a>
            </li>
            <li class="<?= basename($_SERVER['PHP_SELF']) == 'resolutions.php' ? 'active' : '' ?>">
                <a href="resolutions.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'resolutions.php' ? 'bg-dark text-dark rounded' : '' ?>">
                    <i class="fas fa-upload me-2"></i> Resolutions
                </a>
            </li>
            <li class="<?= basename($_SERVER['PHP_SELF']) == 'minutes_of_meeting.php' ? 'active' : '' ?>">
                <a href="minutes_of_meeting.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'minutes_of_meeting.php' ? 'bg-dark text-dark rounded' : '' ?>">
                    <i class="fas fa-chart-bar me-2"></i> Meeting Minutes
                </a>
            </li>
            <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                <li class="<?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
                    <a href="users.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-dark text-dark rounded' : '' ?>">
                        <i class="fas fa-user me-2"></i> Users
                    </a>
                </li>
                <li class="<?= basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'active' : '' ?>">
                    <a href="activity_log.php" class="d-flex align-items-center py-2 px-3 text-white text-decoration-none <?= basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-dark text-dark rounded' : '' ?>">
                        <i class="fas fa-cog me-2"></i> Activity Logs
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
