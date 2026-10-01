/**
 * ROOTS login — minimal terminal enhancements (status line, optional matrix)
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function stampTermLogs() {
        const now = new Date();
        const ts =
            String(now.getHours()).padStart(2, '0') +
            ':' +
            String(now.getMinutes()).padStart(2, '0') +
            ':' +
            String(now.getSeconds()).padStart(2, '0');
        document.querySelectorAll('.term-log[data-ts=""]').forEach(function (el) {
            el.setAttribute('data-ts', ts);
        });
    }

    function initOptionalMatrix() {
        const canvas = document.getElementById('matrix-bg');
        if (!canvas || prefersReducedMotion()) {
            return;
        }

        canvas.removeAttribute('hidden');

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        let width = 0;
        let height = 0;
        let columns = 0;
        let drops = [];
        const chars = '01';
        const fontSize = 14;

        function resize() {
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = width;
            canvas.height = height;
            columns = Math.floor(width / fontSize);
            drops = new Array(columns).fill(1);
        }

        function draw() {
            ctx.fillStyle = 'rgba(6, 10, 8, 0.12)';
            ctx.fillRect(0, 0, width, height);
            ctx.fillStyle = 'rgba(0, 255, 65, 0.35)';
            ctx.font = fontSize + 'px JetBrains Mono, monospace';

            for (let i = 0; i < drops.length; i++) {
                const char = chars[Math.floor(Math.random() * chars.length)];
                const x = i * fontSize;
                const y = drops[i] * fontSize;
                ctx.fillText(char, x, y);

                if (y > height && Math.random() > 0.985) {
                    drops[i] = 0;
                }
                drops[i]++;
            }
        }

        let raf = 0;
        let last = 0;
        const interval = 80;

        function loop(ts) {
            if (ts - last >= interval) {
                draw();
                last = ts;
            }
            raf = requestAnimationFrame(loop);
        }

        resize();
        window.addEventListener('resize', resize, { passive: true });
        raf = requestAnimationFrame(loop);

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                cancelAnimationFrame(raf);
            } else {
                raf = requestAnimationFrame(loop);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        stampTermLogs();
        initOptionalMatrix();
    });
})();
