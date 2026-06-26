<?php
// sidebar.php
// Get current script path for active link detection
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* Sidebar slide transition */
    .sidebar {
        transition: transform 0.3s ease-in-out;
    }

    /* Active link styling */
    .nav-link.active {
        background-color: #f0f0f0;
        color: #1e1e1e;
    }

    .nav-link {
        color: #6e6e6e;
    }

    .nav-link:hover {
        background-color: #f5f5f4;
        color: #1e1e1e;
    }

    /* Enable vertical scrolling for sidebar */
    .sidebar {
        overflow-y: auto;
        max-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    /* Make navigation section scrollable */
    .sidebar nav {
        flex: 1 1 auto;
        overflow-y: auto;
        padding-bottom: 20px;
    }

    /* Keep user info fixed at bottom */
    .user-info-container {
        position: sticky;
        bottom: 0;
        background: white;
        border-top: 1px solid #e5e5e5;
        margin-top: auto;
        width: 100%;
    }

    /* Custom scrollbar styling */
    .sidebar::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }

    .sidebar::-webkit-scrollbar-thumb:hover {
        background: #a1a1a1;
    }

    /* Shared component styles */
    .stat-box {
        background: white;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 20px;
    }
    .stat-box .stat-label {
        font-size: 0.75rem;
        color: #9e9e9e;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }
    .stat-box .stat-value {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1e1e1e;
    }
    .panel {
        background: white;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        overflow: hidden;
    }
    .panel-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e5e5;
    }
    .panel-body {
        padding: 20px;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid #e5e5e5;
        background: white;
        color: #1e1e1e;
    }
    .btn:hover { background: #f5f5f4; border-color: #cfcfcd; }
    .btn-primary {
        background: #1e1e1e;
        color: white;
        border-color: #1e1e1e;
    }
    .btn-primary:hover { background: #2d2d2d; }
    .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.8125rem; }
    .status-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .status-picked {
        background: #e8f5e9;
        color: #2e7d32;
    }
    .status-pending {
        background: #fff8e1;
        color: #f57f17;
    }
</style>

    <!-- Sidebar -->
    <style>
    :root { --sidebar-width: 240px; }
    .sidebar {
        width: 240px; transition: width 0.25s ease, transform 0.3s ease-in-out;
    }
    .sidebar-toggle-btn {
        width: 28px; height: 28px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid #e5e5e5; background: #fff; color: #9e9e9e;
        border-radius: 6px; cursor: pointer;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .sidebar-toggle-btn:hover { background: #f5f5f4; color: #1e1e1e; }
    html[data-sidebar-collapsed="true"] { --sidebar-width: 60px; }
    html[data-sidebar-collapsed="true"] .sidebar {
        width: 60px; transform: translateX(0) !important;
    }
    html[data-sidebar-collapsed="true"] .sidebar-text { display: none; }
    html[data-sidebar-collapsed="true"] .sidebar .nav-link { justify-content: center; gap: 0; padding-left: 0; padding-right: 0; }
    html[data-sidebar-collapsed="true"] .sidebar .user-info-container { justify-content: center; }
    html[data-sidebar-collapsed="true"] .sidebar nav { padding-left: 8px; padding-right: 8px; }
    html[data-sidebar-collapsed="true"] .sidebar .logo-section { justify-content: center; gap: 0; padding: 6px 0; }
    html[data-sidebar-collapsed="true"] .sidebar .logo-section img { display: none; }
    html[data-sidebar-collapsed="true"] .sidebar .logo-section .sidebar-toggle-btn { width: 16px; height: 16px; font-size: 10px; border: none; box-shadow: none; background: transparent; color: #6e6e6e; }
    .lg\:ml-\[var\(--sidebar-width\)\] { transition: margin-left 0.25s ease; }
</style>
<script>if(localStorage.getItem('sidebarCollapsed')==='true'){if(window.innerWidth>=1024)document.documentElement.setAttribute('data-sidebar-collapsed','true');else localStorage.setItem('sidebarCollapsed','false');}</script>

<div id="sidebar" class="sidebar w-60 lg:w-60 bg-white border-r border-[#e5e5e5] min-h-screen fixed left-0 top-0 overflow-y-auto transform -translate-x-full lg:translate-x-0 transition-all duration-300 z-40">
        <!-- Logo (always visible) -->
        <div class="px-4 py-5 flex items-center gap-2 sticky top-0 bg-white z-10 logo-section">
            <img src="images/logo.png" alt="Mail Room" class="w-6 h-6 shrink-0">
            <span class="text-[#1e1e1e] text-base font-medium sidebar-text">LIBRARY</span>
            <button id="sidebarToggle" class="sidebar-toggle-btn ml-auto" title="Toggle sidebar" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
        </div>

        <!-- Navigation (scrollable area) -->
        <nav class="px-3 pb-4">
            <!-- Dashboard -->
            <div class="text-xs text-[#9e9e9e] px-3 pt-2 pb-1 sidebar-text">MAIN</div>
            <a href="index.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-home w-4 text-current"></i>
                <span class="sidebar-text">Dashboard</span>
            </a>

            <!-- Newspapers Section -->
            <div class="text-xs text-[#9e9e9e] px-3 pt-5 pb-1 sidebar-text">NEWSPAPERS</div>
            <a href="newspaper_categories.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'newspaper_categories.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-tags w-4"></i>
                <span class="sidebar-text">Newspaper Subscription</span>
            </a>
            <a href="recipients.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'recipients.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-user w-4"></i>
                <span class="sidebar-text">Newspaper Recipients</span>
            </a>
            <a href="list.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'list.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-newspaper w-4"></i>
                <span class="sidebar-text">Newspaper List</span>
            </a>
            <a href="newspaper_distribution.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'newspaper_distribution.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-share-from-square w-4"></i>
                <span class="sidebar-text">Newspaper Distribution</span>
            </a>
            <a href="distribution_history.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'available.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock w-4"></i>
                <span class="sidebar-text">Newspaper History</span>
            </a>


            <!-- Documents Section -->
            <div class="text-xs text-[#9e9e9e] px-3 pt-5 pb-1 sidebar-text">DOCUMENTS</div>
            <a href="documents.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'documents.php' ? 'active' : ''; ?>">
                <i class="fa-regular fa-file-lines w-4"></i>
                <span class="sidebar-text">All Documents</span>
            </a>
            <a href="document_type.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'document_type.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-tags w-4"></i>
                <span class="sidebar-text">Document Types</span>
            </a>
            <a href="distribution.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'distribution.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-share-from-square w-4"></i>
                <span class="sidebar-text">Document Distribution</span>
            </a>
            <a href="documents_distribution_history.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'documents_distribution_history.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock w-4"></i>
                <span class="sidebar-text">Document History</span>
            </a>

            <!-- Parcels Section -->
            <div class="text-xs text-[#9e9e9e] px-3 pt-5 pb-1 sidebar-text">PARCELS</div>
            <a href="parcels.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'parcels.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-box w-4"></i>
                <span class="sidebar-text">Parcel Management</span>
            </a>

            <!-- Settings -->
            <div class="text-xs text-[#9e9e9e] px-3 pt-5 pb-1 sidebar-text">SYSTEM</div>
            <a href="settings.php"
                class="nav-link flex items-center gap-3 px-3 py-2 text-sm rounded-md mb-1 <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-gear w-4"></i>
                <span class="sidebar-text">Settings</span>
            </a>
        </nav>

        <!-- User info (fixed at bottom) -->
        <div class="user-info-container p-4 border-t border-[#e5e5e5] flex items-center gap-3 bg-white">
            <div class="w-8 h-8 rounded-md bg-[#f0f0f0] flex items-center justify-center text-sm text-[#1e1e1e] font-medium">
                <img src="./images/logo.png" alt="" srcset="">
            </div>
            <div class="sidebar-text">
                <div class="text-sm text-[#1e1e1e] font-medium">Library Staff</div>
                <div class="text-xs text-[#9e9e9e]">Administrator</div>
            </div>
        </div>
    </div>

    <!-- Overlay for mobile -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 hidden z-30 lg:hidden"></div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('-translate-x-full');
                if (overlay) overlay.classList.add('hidden');
            }
        });

        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (window.innerWidth < 1024) {
                    sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebar();
                } else {
                    const collapsed = document.documentElement.getAttribute('data-sidebar-collapsed') === 'true';
                    if (collapsed) {
                        document.documentElement.removeAttribute('data-sidebar-collapsed');
                        localStorage.setItem('sidebarCollapsed', 'false');
                    } else {
                        document.documentElement.setAttribute('data-sidebar-collapsed', 'true');
                        localStorage.setItem('sidebarCollapsed', 'true');
                    }
                }
            });
        }

        // Highlight current page in navigation
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-link');

            navLinks.forEach(link => {
                const href = link.getAttribute('href').split('?')[0].split('/').pop();
                if (href === currentPath) {
                    link.classList.add('active');
                } else if (currentPath === 'parcels.php' && href === 'parcels.php') {
                    // Special handling for parcels.php with tabs
                    const urlParams = new URLSearchParams(window.location.search);
                    const tab = urlParams.get('tab');
                    const linkTab = link.getAttribute('href').split('tab=')[1];

                    if ((!tab && !linkTab) || (tab === linkTab)) {
                        link.classList.add('active');
                    }
                }
            });
        });
    </script>