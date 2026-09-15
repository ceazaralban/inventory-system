// Global sidebar functionality
class SidebarManager {
    constructor() {
        this.sidebarToggle = document.getElementById('sidebarToggle');
        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('mainContent');
        this.sidebarOverlay = document.getElementById('sidebarOverlay');
        
        this.init();
    }
    
    init() {
        if (this.sidebarToggle && this.sidebar && this.mainContent) {
            this.bindEvents();
            this.handleResize(); // Initial check
            window.addEventListener('resize', () => this.handleResize());
        }
    }
    
    bindEvents() {
        // Hamburger button
        this.sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleSidebar();
        });
        
        // Overlay click (mobile)
        if (this.sidebarOverlay) {
            this.sidebarOverlay.addEventListener('click', () => {
                this.closeSidebar();
            });
        }
        
        // Close sidebar when clicking outside (mobile)
        this.mainContent.addEventListener('click', () => {
            this.closeSidebar();
        });
        
        // Don't close when clicking inside sidebar
        this.sidebar.addEventListener('click', (e) => {
            e.stopPropagation();
        });
        
        // Close with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeSidebar();
            }
        });
    }
    
    toggleSidebar() {
        this.sidebar.classList.toggle('collapsed');
        this.mainContent.classList.toggle('expanded');
        this.updateIcon();
    }
    
    closeSidebar() {
        if (window.innerWidth <= 768 && !this.sidebar.classList.contains('collapsed')) {
            this.sidebar.classList.add('collapsed');
            this.mainContent.classList.add('expanded');
            this.updateIcon();
        }
    }
    
    updateIcon() {
        const icon = this.sidebarToggle.querySelector('i');
        if (this.sidebar.classList.contains('collapsed')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-chevron-right');
        } else {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-bars');
        }
    }
    
    handleResize() {
        if (window.innerWidth <= 768) {
            this.sidebar.classList.add('collapsed');
            this.mainContent.classList.add('expanded');
        } else {
            this.sidebar.classList.remove('collapsed');
            this.mainContent.classList.remove('expanded');
        }
        this.updateIcon();
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    new SidebarManager();
});