(function ($) {
    "use strict";

    // Spinner
    const spinner = function () {
        setTimeout(function () {
            if ($('#spinner').length > 0) {
                $('#spinner').removeClass('show');
            }
        }, 1);
    };
    spinner();


    // Back to top button
    $(globalThis).scroll(function () {
        if ($(this).scrollTop() > 300) {
            $('.back-to-top').fadeIn('slow');
        } else {
            $('.back-to-top').fadeOut('slow');
        }
    });
    $('.back-to-top').click(function () {
        $('html, body').animate({ scrollTop: 0 }, 1500, 'easeInOutExpo');
        return false;
    });


    // Sidebar Toggler - Use toggleSidebar for floating sidebar
    $('.sidebar-toggler').click(function (e) {
        e.preventDefault();
        if (typeof globalThis.toggleSidebar === 'function') {
            globalThis.toggleSidebar(e);
        } else if (typeof toggleSidebar === 'function') {
            toggleSidebar(e);
        } else {
            // Fallback to old behavior if function not available
            $('.sidebar, .content').toggleClass("open");
        }
        return false;
    });


    // Progress Bar
    if ($.fn.waypoint) {
        $('.pg-bar').waypoint(function () {
            $('.progress .progress-bar').each(function () {
                $(this).css("width", $(this).attr("aria-valuenow") + '%');
            });
        }, { offset: '80%' });
    }








    // Chart Global Color
    if (typeof Chart !== 'undefined') {
        Chart.defaults.color = "#6C7293";
        Chart.defaults.borderColor = "#000000";
        globalThis.chartInstances = {};


        // Worldwide Sales Chart
        const canvas1 = document.getElementById("worldwide-sales");
        if (canvas1) {
            const ctx1 = canvas1.getContext("2d");
            globalThis.chartInstances.worldwideSales = new Chart(ctx1, {
                type: "bar",
                data: {
                    labels: ["2016", "2017", "2018", "2019", "2020", "2021", "2022"],
                    datasets: [{
                        label: "USA",
                        data: [15, 30, 55, 65, 60, 80, 95],
                        backgroundColor: "rgba(235, 22, 22, .7)"
                    },
                    {
                        label: "UK",
                        data: [8, 35, 40, 60, 70, 55, 75],
                        backgroundColor: "rgba(235, 22, 22, .5)"
                    },
                    {
                        label: "AU",
                        data: [12, 25, 45, 55, 65, 70, 60],
                        backgroundColor: "rgba(235, 22, 22, .3)"
                    }
                    ]
                },
                options: {
                    responsive: true
                }
            });
        }


        // Salse & Revenue Chart
        const canvas2 = document.getElementById("salse-revenue");
        if (canvas2) {
            const ctx2 = canvas2.getContext("2d");
            globalThis.chartInstances.salesRevenue = new Chart(ctx2, {
                type: "line",
                data: {
                    labels: ["2016", "2017", "2018", "2019", "2020", "2021", "2022"],
                    datasets: [{
                        label: "Salse",
                        data: [15, 30, 55, 45, 70, 65, 85],
                        backgroundColor: "rgba(235, 22, 22, .7)",
                        fill: true
                    },
                    {
                        label: "Revenue",
                        data: [99, 135, 170, 130, 190, 180, 270],
                        backgroundColor: "rgba(235, 22, 22, .5)",
                        fill: true
                    }
                    ]
                },
                options: {
                    responsive: true
                }
            });
        }



        // Single Line Chart
        const canvas3 = document.getElementById("line-chart");
        if (canvas3) {
            const ctx3 = canvas3.getContext("2d");
            globalThis.chartInstances.lineChart = new Chart(ctx3, {
                type: "line",
                data: {
                    labels: [50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150],
                    datasets: [{
                        label: "Salse",
                        fill: false,
                        backgroundColor: "rgba(235, 22, 22, .7)",
                        data: [7, 8, 8, 9, 9, 9, 10, 11, 14, 14, 15]
                    }]
                },
                options: {
                    responsive: true
                }
            });
        }


        // Single Bar Chart
        const canvas4 = document.getElementById("bar-chart");
        if (canvas4) {
            const ctx4 = canvas4.getContext("2d");
            globalThis.chartInstances.barChart = new Chart(ctx4, {
                type: "bar",
                data: {
                    labels: ["Italy", "France", "Spain", "USA", "Argentina"],
                    datasets: [{
                        backgroundColor: [
                            "rgba(235, 22, 22, .7)",
                            "rgba(235, 22, 22, .6)",
                            "rgba(235, 22, 22, .5)",
                            "rgba(235, 22, 22, .4)",
                            "rgba(235, 22, 22, .3)"
                        ],
                        data: [55, 49, 44, 24, 15]
                    }]
                },
                options: {
                    responsive: true
                }
            });
        }


        // Pie Chart
        const canvas5 = document.getElementById("pie-chart");
        if (canvas5) {
            const ctx5 = canvas5.getContext("2d");
            globalThis.chartInstances.pieChart = new Chart(ctx5, {
                type: "pie",
                data: {
                    labels: ["Italy", "France", "Spain", "USA", "Argentina"],
                    datasets: [{
                        backgroundColor: [
                            "rgba(235, 22, 22, .7)",
                            "rgba(235, 22, 22, .6)",
                            "rgba(235, 22, 22, .5)",
                            "rgba(235, 22, 22, .4)",
                            "rgba(235, 22, 22, .3)"
                        ],
                        data: [55, 49, 44, 24, 15]
                    }]
                },
                options: {
                    responsive: true
                }
            });
        }


        // Doughnut Chart
        const canvas6 = document.getElementById("doughnut-chart");
        if (canvas6) {
            const ctx6 = canvas6.getContext("2d");
            globalThis.chartInstances.doughnutChart = new Chart(ctx6, {
                type: "doughnut",
                data: {
                    labels: ["Italy", "France", "Spain", "USA", "Argentina"],
                    datasets: [{
                        backgroundColor: [
                            "rgba(235, 22, 22, .7)",
                            "rgba(235, 22, 22, .6)",
                            "rgba(235, 22, 22, .5)",
                            "rgba(235, 22, 22, .4)",
                            "rgba(235, 22, 22, .3)"
                        ],
                        data: [55, 49, 44, 24, 15]
                    }]
                },
                options: {
                    responsive: true
                }
            });
        }
    }

})(jQuery); // End of jQuery IIFE

// Toggle floating sidebar
function toggleSidebar(e) {
    if (e) e.preventDefault();

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const content = document.querySelector('.content');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');

    if (content) {
        content.classList.toggle('sidebar-active');
    }
}

// Attach toggle event to all togglers
document.addEventListener('DOMContentLoaded', function () {
    const togglers = document.querySelectorAll('.sidebar-toggler');
    togglers.forEach(btn => {
        btn.addEventListener('click', toggleSidebar);
    });

    // Fix nested dropdown issue - prevent main menu from closing when nested dropdown is clicked
    const nestedDropdowns = document.querySelectorAll('.dropdown-menu .dropdown-toggle');
    nestedDropdowns.forEach(dropdown => {
        dropdown.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();

            // Close all other nested dropdowns first
            const allNestedMenus = document.querySelectorAll('.dropdown-menu .dropdown-menu.show');
            allNestedMenus.forEach(menu => {
                if (menu !== this.nextElementSibling) {
                    menu.classList.remove('show');
                }
            });

            // Toggle the nested dropdown
            const nestedMenu = this.nextElementSibling;
            if (nestedMenu) {
                nestedMenu.classList.toggle('show');

                // Handle dropleft positioning
                if (this.closest('.dropleft')) {
                    const rect = this.getBoundingClientRect();
                    const menuRect = nestedMenu.getBoundingClientRect();

                    // Position to the left of the parent
                    nestedMenu.style.left = 'auto';
                    nestedMenu.style.right = '100%';
                    nestedMenu.style.top = '0';
                    nestedMenu.style.marginRight = '0.5rem';

                    // Adjust if menu goes off screen
                    if (rect.left - menuRect.width < 10) {
                        nestedMenu.style.right = 'auto';
                        nestedMenu.style.left = '100%';
                        nestedMenu.style.marginRight = '0';
                        nestedMenu.style.marginLeft = '0.5rem';
                    }
                }
            }
        });
    });

    // Close nested dropdowns when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown-menu .dropdown-toggle') &&
            !e.target.closest('.dropdown-menu .dropdown-menu')) {
            const nestedMenus = document.querySelectorAll('.dropdown-menu .dropdown-menu.show');
            nestedMenus.forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
});

// Close sidebar when clicking outside
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar?.classList.contains('open')) {
            toggleSidebar();
        }
    }
});
