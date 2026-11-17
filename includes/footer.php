    <!-- Footer Copyright -->
    <footer class="app-footer text-center py-3 text-secondary border-top mt-auto" style="font-size: 13px;">
        <div class="container">
            <p class="mb-0 text-muted">&copy; <?= date('Y') ?> Sistem Informasi Absensi. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Leaflet JS for Map & Radius Visualization -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        // Modal stacking helper to prevent modal backdrop conflicts
        document.addEventListener('show.bs.modal', function (e) {
            if (e.target.parentElement !== document.body) {
                document.body.appendChild(e.target);
            }
        });

        // Theme Switcher & Persistence
        function updateThemeIcons(theme) {
            const moonIcons = document.querySelectorAll('.theme-icon-dark');
            const sunIcons  = document.querySelectorAll('.theme-icon-light');
            if (theme === 'dark') {
                moonIcons.forEach(el => el.classList.add('d-none'));
                sunIcons.forEach(el => el.classList.remove('d-none'));
            } else {
                moonIcons.forEach(el => el.classList.remove('d-none'));
                sunIcons.forEach(el => el.classList.add('d-none'));
            }
        }

        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('data-bs-theme', theme);
            try { localStorage.setItem('absensi_theme', theme); } catch(e) {}
            updateThemeIcons(theme);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            updateThemeIcons(currentTheme);

            document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const now = document.documentElement.getAttribute('data-theme') || 'light';
                    const target = (now === 'dark') ? 'light' : 'dark';
                    setTheme(target);
                });
            });
        });
    </script>
</body>
</html>
