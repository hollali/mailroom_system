<?php
// sidebar.php
// Rewritten with workflow-based navigation & modern design
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<script>document.documentElement.style.colorScheme='light';if(localStorage.getItem('mr_sidebar')==='collapsed'&&window.innerWidth>=1024)document.documentElement.setAttribute('data-sidebar-collapsed','true');</script>

<aside id="appSidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="images/logo.png" alt="Mailroom" style="width:26px;height:26px;border-radius:6px;">
        </div>
        <span class="sidebar-brand">Mailroom Ops</span>
        <button class="sidebar-toggle" data-sidebar-toggle title="Toggle sidebar" aria-label="Toggle sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <a href="index.php" class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span class="nav-text">Dashboard</span>
        </a>

        <div class="nav-group-label"><span>Newspapers</span></div>
        <a href="list.php" class="nav-link <?php echo $current_page == 'list.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-newspaper"></i>
            <span class="nav-text">Newspaper Management</span>
        </a>
        <a href="newspaper_distribution.php" class="nav-link <?php echo $current_page == 'newspaper_distribution.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-paper-plane"></i>
            <span class="nav-text">Newspaper Distribution</span>
        </a>
        <a href="distribution_history.php" class="nav-link <?php echo $current_page == 'distribution_history.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span class="nav-text">Newspaper History</span>
        </a>
        <a href="newspaper_categories.php" class="nav-link <?php echo $current_page == 'newspaper_categories.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-layer-group"></i>
            <span class="nav-text">Newspaper Categories</span>
        </a>
        <a href="recipients.php" class="nav-link <?php echo $current_page == 'recipients.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-user"></i>
            <span class="nav-text">Recipients</span>
        </a>

        <div class="nav-group-label"><span>Documents</span></div>
        <a href="documents.php" class="nav-link <?php echo $current_page == 'documents.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-file-lines"></i>
            <span class="nav-text">Documents</span>
        </a>
        <a href="distribution.php" class="nav-link <?php echo $current_page == 'distribution.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-paper-plane"></i>
            <span class="nav-text">Document Distribution</span>
        </a>
        <a href="documents_distribution_history.php" class="nav-link <?php echo $current_page == 'documents_distribution_history.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span class="nav-text">Document History</span>
        </a>
        <a href="document_type.php" class="nav-link <?php echo $current_page == 'document_type.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-tags"></i>
            <span class="nav-text">Document Types</span>
        </a>

        <div class="nav-group-label"><span>Parcels</span></div>
        <a href="parcels.php" class="nav-link <?php echo $current_page == 'parcels.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-box"></i>
            <span class="nav-text">Parcels</span>
        </a>

        <div class="nav-group-label"><span>System</span></div>
        <a href="settings.php" class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="nav-text">Settings</span>
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar"><i class="fa-solid fa-user"></i></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name">Library Staff</div>
            <div class="sidebar-user-role">Administrator</div>
        </div>
    </div>
</aside>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const currentPath = window.location.pathname.split('/').pop().split('?')[0];
    document.querySelectorAll('.nav-link').forEach(link => {
        const href = link.getAttribute('href').split('?')[0].split('/').pop();
        if (href === currentPath) {
            link.classList.add('active');
        }
    });
});
</script>