// Toggle sidebar collapse/expand
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const toggleIcon = document.getElementById('toggle-icon');
    const toggleText = document.querySelector('.toggle-sidebar .menu-text');
    
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
    
    if(sidebar.classList.contains('collapsed')) {
        toggleIcon.textContent = '▶';
        if(toggleText) toggleText.textContent = 'Expand';
        localStorage.setItem('sidebarCollapsed', 'true');
    } else {
        toggleIcon.textContent = '◀';
        if(toggleText) toggleText.textContent = 'Collapse';
        localStorage.setItem('sidebarCollapsed', 'false');
    }
}

// Load sidebar state from localStorage
document.addEventListener('DOMContentLoaded', function() {
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if(isCollapsed) {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        if(sidebar && mainContent) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            const toggleIcon = document.getElementById('toggle-icon');
            if(toggleIcon) toggleIcon.textContent = '▶';
        }
    }
});

// Search functionality
function searchNotices() {
    let input = document.getElementById('searchInput');
    let filter = input.value.toUpperCase();
    let cards = document.getElementsByClassName('notice-card');
    
    for(let i = 0; i < cards.length; i++) {
        let title = cards[i].getElementsByClassName('notice-title')[0];
        if(title) {
            let textValue = title.textContent || title.innerText;
            if(textValue.toUpperCase().indexOf(filter) > -1) {
                cards[i].style.display = "";
            } else {
                cards[i].style.display = "none";
            }
        }
    }
}

// Filter by category
function filterByCategory(category) {
    let cards = document.getElementsByClassName('notice-card');
    for(let i = 0; i < cards.length; i++) {
        let cardCategory = cards[i].getAttribute('data-category');
        if(category === 'all' || cardCategory === category) {
            cards[i].style.display = "";
        } else {
            cards[i].style.display = "none";
        }
    }
}