<?php
// sidebar.php - NutriChild Navigation Sidebar
$current_page = basename($_SERVER['PHP_SELF']);
$current_role = $_SESSION['role'] ?? '';
$is_bns = ($current_role === 'Barangay Nutrition Scholars');
$is_hw = ($current_role === 'Health Worker');
$is_staff = ($current_role === 'Staff');
$display_name = 'User';
if ($current_role === 'Barangay Nutrition Scholars') {
    $display_name = 'BNS';
} elseif ($current_role === 'Health Worker') {
    $display_name = 'HW';
} elseif ($current_role === 'Staff') {
    $display_name = 'Staff';
} elseif ($current_role !== '') {
    $display_name = $current_role;
}
?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/mobile-ui.css">

<div class="sb-mobile-bar">
    <button class="sb-mobile-toggle" id="sbMobileToggle" aria-label="Open navigation">
        <svg viewBox="0 0 24 24">
            <line x1="4" y1="6" x2="20" y2="6" />
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="18" x2="20" y2="18" />
        </svg>
    </button>
    <div class="sb-mobile-title">Child Nutrition</div>
</div>

<div id="sbMobileOverlay"></div>

<aside id="sidebar">
    <button id="sbArrow" title="Toggle sidebar">
        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
    </button>

    <button type="button" id="sbMobileClose" class="sb-mobile-close" aria-label="Close navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>

    <div class="sb-inner">
        <!-- Brand -->
        <div class="sb-brand">
            <div class="sb-logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6 2 3 7 3 12c0 5 3.5 9 9 10V12"/>
                    <path d="M12 12c3-3 7-4 9-3-1 4-4 7-9 10"/>
                </svg>
            </div>
            <div class="sb-brand-text">
                <div class="sb-brand-name">Child Nutrition</div>
                <div class="sb-brand-tag"><?= htmlspecialchars($display_name) ?></div>
            </div>
        </div>

        <!-- Nav -->
        <nav>
            <span class="sb-section">Overview</span>

            <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="9" rx="1.5"/>
                        <rect x="14" y="3" width="7" height="5" rx="1.5"/>
                        <rect x="14" y="12" width="7" height="9" rx="1.5"/>
                        <rect x="3" y="16" width="7" height="5" rx="1.5"/>
                    </svg>
                </span>
                <span class="sb-label">Dashboard</span>
                <span class="sb-mini">Dashboard</span>
            </a>

            <span class="sb-section">Children Profile</span>

            <?php if (!$is_staff): ?>
            <a href="add_profile.php" class="<?= $current_page === 'add_profile.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </span>
                <span class="sb-label">Add Children</span>
                <span class="sb-mini">Add Children</span>
            </a>
            <?php endif; ?>

            <a href="child_profiles.php" class="<?= in_array($current_page, ['child_profiles.php', 'view_child_profile.php'], true) ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="4" y="4" width="16" height="16" rx="3"/>
                        <circle cx="12" cy="10" r="3.5"/>
                        <path d="M6 18c0-2.5 3-4 6-4s6 1.5 6 4"/>
                    </svg>
                </span>
                <span class="sb-label">Child Profiles</span>
                <span class="sb-mini">Profiles</span>
            </a>
            <a href="archive_children.php" class="<?= $current_page === 'archive_children.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="4" rx="1"/>
                        <path d="M5 8h14v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8z"/>
                        <path d="M10 12h4"/>
                    </svg>
                </span>
                <span class="sb-label">Archive</span>
                <span class="sb-mini">Archive</span>
            </a>
            

              <?php if (!$is_bns && !$is_hw && !$is_staff): ?>
                 <span class="sb-section">Manage</span>
            <a href="barangays.php" class="<?= $current_page === 'barangays.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 21h18"/>
                        <path d="M5 21V7l7-4 7 4v14"/>
                        <path d="M9 21v-6h6v6"/>
                    </svg>
                </span>
                <span class="sb-label">Barangay</span>
                <span class="sb-mini">Barangay</span>
            </a>
            <a href="interventions.php" class="<?= in_array($current_page, ['interventions.php', 'view_interventions.php'], true) ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M12 7v10"/>
                        <path d="M7 12h10"/>
                    </svg>
                </span>
                <span class="sb-label">Interventions</span>
                <span class="sb-mini">Intervene</span>
            </a>

              <?php endif; ?>
                  <?php if (!$is_bns && !$is_hw && !$is_staff): ?>
            <a href="inventory.php" class="<?= in_array($current_page, ['inventory.php', 'inventory_items.php', 'expired_items.php', 'low_stock_items.php', 'expiring_soon_items.php'], true) ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="7" width="18" height="13" rx="2"/>
                        <path d="M16 3v4"/>
                        <path d="M8 3v4"/>
                    </svg>
                </span>
                <span class="sb-label">Inventory</span>
                <span class="sb-mini">Inventory</span>
            </a>
            <?php endif; ?>
            <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
                        <path d="M14 3v5h5"/>
                        <path d="M8 13h8"/>
                        <path d="M8 17h8"/>
                    </svg>
                </span>
                <span class="sb-label">Reports</span>
                <span class="sb-mini">Reports</span>
            </a>
            <a href="account_settings.php" class="<?= $current_page === 'account_settings.php' ? 'active' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4"/>
                        <path d="M12 8h.01"/>
                    </svg>
                </span>
                <span class="sb-label">Account Settings</span>
                <span class="sb-mini">Settings</span>
            </a>
    
            <div class="sb-spacer"></div>
        </nav>

        <!-- Logout -->
        <div class="sb-logout-divider"></div>
        <div class="sb-logout">
            <a href="logout.php">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </span>
                <span class="sb-label">Logout</span>
                <span class="sb-mini">Logout</span>
            </a>
        </div>
    </div>
</aside>

<script src="javascript/uppercase-all.js"></script>
<script src="javascript/logout-confirm.js"></script>
<script src="javascript/sidebar.js"></script>
<script src="javascript/session_monitor.js?v=3"></script>
