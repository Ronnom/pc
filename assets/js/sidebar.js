/**
 * PC POS System - Sidebar Navigation
 * Modern sidebar functionality with improved UX
 */

class SidebarNavigation {
    constructor() {
        this.STORAGE_KEY = 'pc_pos_sidebar_state_v7';
        this.nav = null;
        this.sections = [];
        this.persisted = { openSection: null };
        this.init();
    }

    init() {
        this.nav = document.querySelector('.sidebar-menu');
        if (!this.nav) return;

        this.sections = Array.from(this.nav.querySelectorAll('.sidebar-section[data-section]'));
        this.loadPersistedState();
        this.setupSections();
        this.setupResizeHandler();
        this.setupKeyboardNavigation();
    }

    loadPersistedState() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            this.persisted = stored ? JSON.parse(stored) : { openSection: null };
        } catch (e) {
            this.persisted = { openSection: null };
        }
    }

    savePersistedState() {
        try {
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(this.persisted));
        } catch (e) {
            // Ignore localStorage errors
        }
    }

    setupSections() {
        const activeSection = this.nav.querySelector('.sidebar-section--active');
        const activeSectionId = activeSection ? activeSection.getAttribute('data-section') : null;
        const openSectionId = (typeof this.persisted.openSection === 'string' && this.persisted.openSection)
            ? this.persisted.openSection
            : activeSectionId;

        this.sections.forEach(sectionEl => {
            const id = sectionEl.getAttribute('data-section');
            const isExpanded = openSectionId === id;
            this.setExpanded(sectionEl, isExpanded);
            this.setupSectionToggle(sectionEl);
        });
    }

    setupSectionToggle(sectionEl) {
        const toggleBtn = sectionEl.querySelector('[data-section-toggle]');
        if (!toggleBtn) return;

        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleSection(sectionEl);
        });

        // Keyboard navigation
        toggleBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.toggleSection(sectionEl);
            }
        });
    }

    toggleSection(sectionEl) {
        const isExpanded = sectionEl.classList.contains('sidebar-section--open');

        if (isExpanded) {
            this.setExpanded(sectionEl, false);
            this.persisted.openSection = null;
        } else {
            // Close other sections
            this.sections.forEach(otherSection => {
                if (otherSection !== sectionEl) {
                    this.setExpanded(otherSection, false);
                }
            });

            this.setExpanded(sectionEl, true);
            this.persisted.openSection = sectionEl.getAttribute('data-section');
        }

        this.savePersistedState();
    }

    setExpanded(sectionEl, expanded) {
        const toggleBtn = sectionEl.querySelector('[data-section-toggle]');
        const childrenWrap = sectionEl.querySelector('.sidebar-children');
        const chevron = sectionEl.querySelector('.sidebar-item__chevron');

        if (!toggleBtn || !childrenWrap) return;

        // Update ARIA attributes
        toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        // Update classes
        sectionEl.classList.toggle('sidebar-section--open', expanded);
        childrenWrap.classList.toggle('sidebar-children--expanded', expanded);

        // Animate height
        if (expanded) {
            childrenWrap.style.maxHeight = childrenWrap.scrollHeight + 'px';
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        } else {
            childrenWrap.style.maxHeight = '0px';
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    }

    setupResizeHandler() {
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.sections.forEach(sectionEl => {
                    if (sectionEl.classList.contains('sidebar-section--open')) {
                        const childrenWrap = sectionEl.querySelector('.sidebar-children');
                        if (childrenWrap) {
                            childrenWrap.style.maxHeight = childrenWrap.scrollHeight + 'px';
                        }
                    }
                });
            }, 100);
        });
    }

    setupKeyboardNavigation() {
        // Focus management for accessibility
        this.nav.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                this.navigateWithArrowKeys(e.key === 'ArrowDown');
            }
        });
    }

    navigateWithArrowKeys(down = true) {
        const focusableElements = this.nav.querySelectorAll(
            'a.sidebar-item, button.sidebar-item--parent'
        );
        const focusedElement = document.activeElement;
        const currentIndex = Array.from(focusableElements).indexOf(focusedElement);

        if (currentIndex === -1) return;

        let nextIndex;
        if (down) {
            nextIndex = currentIndex + 1;
            if (nextIndex >= focusableElements.length) nextIndex = 0;
        } else {
            nextIndex = currentIndex - 1;
            if (nextIndex < 0) nextIndex = focusableElements.length - 1;
        }

        focusableElements[nextIndex].focus();
    }

    // Public method to programmatically toggle sections
    toggleSectionById(sectionId) {
        const sectionEl = this.nav.querySelector(`[data-section="${sectionId}"]`);
        if (sectionEl) {
            this.toggleSection(sectionEl);
        }
    }

    // Public method to expand a section
    expandSection(sectionId) {
        const sectionEl = this.nav.querySelector(`[data-section="${sectionId}"]`);
        if (sectionEl && !sectionEl.classList.contains('sidebar-section--open')) {
            this.toggleSection(sectionEl);
        }
    }

    // Public method to collapse all sections
    collapseAll() {
        this.sections.forEach(sectionEl => {
            this.setExpanded(sectionEl, false);
        });
        this.persisted.openSection = null;
        this.savePersistedState();
    }
}

// Mobile sidebar functionality
class MobileSidebar {
    constructor() {
        this.offcanvas = null;
        this.init();
    }

    init() {
        this.offcanvas = document.getElementById('mobileSidebar');
        if (!this.offcanvas) return;

        this.setupOffcanvasEvents();
    }

    setupOffcanvasEvents() {
        // Close mobile sidebar when clicking on links
        this.offcanvas.addEventListener('shown.bs.offcanvas', () => {
            const links = this.offcanvas.querySelectorAll('a.sidebar-item');
            links.forEach(link => {
                link.addEventListener('click', () => {
                    const bsOffcanvas = bootstrap.Offcanvas.getInstance(this.offcanvas);
                    if (bsOffcanvas) {
                        bsOffcanvas.hide();
                    }
                });
            });
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize sidebar navigation
    window.sidebarNav = new SidebarNavigation();

    // Initialize mobile sidebar
    new MobileSidebar();

    // Add smooth scrolling for sidebar
    const sidebarScroll = document.querySelector('.sidebar-scroll');
    if (sidebarScroll) {
        sidebarScroll.style.scrollBehavior = 'smooth';
    }
});

// Export for potential external use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { SidebarNavigation, MobileSidebar };
}