/*
 * Графики дашборда: рисует все <canvas data-chart="{...}"> страницы (виджет Chart).
 * Подключается отдельным файлом только на страницах с графиком.
 * Цвета берутся из CSS-переменных темы и пересчитываются при смене темы (класс .dark на <html>).
 */
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

/**
 * Значение CSS-переменной темы админки или запасное.
 */
function cssVar(name, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

/**
 * Цвет акцента с прозрачностью: color-mix понимают все браузеры, которые поддерживает админка.
 */
function mix(color, percent) {
    return `color-mix(in srgb, ${color} ${percent}%, transparent)`;
}

/**
 * Настройки графика по данным виджета и текущей теме.
 */
function options(config) {
    const primary = cssVar('--fastleo-primary', '#4f46e5');
    const line = cssVar('--fl-border', 'rgba(128, 128, 128, 0.15)');
    const muted = cssVar('--fl-text-3', '#78726a');
    const isBar = config.type === 'bar';
    const last = config.values.length - 1;
    const ticks = { color: muted, font: { size: 11 } };

    return {
        type: config.type,
        data: {
            labels: config.labels,
            datasets: [{
                label: config.label,
                data: config.values,
                borderColor: primary,
                // Столбцы — приглушённый акцент, последний (текущий) — полный; линия — с мягкой заливкой.
                backgroundColor: isBar ? config.values.map((value, index) => (index === last ? primary : mix(primary, 26))) : mix(primary, 14),
                borderRadius: isBar ? { topLeft: 5, topRight: 5 } : 0,
                borderSkipped: isBar ? 'bottom' : false,
                maxBarThickness: 28,
                fill: !isBar,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { ...ticks, maxTicksLimit: 8 }, grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { ...ticks, precision: 0 }, grid: { color: line }, border: { display: false } },
            },
        },
    };
}

const charts = [];

function draw(canvas) {
    const config = JSON.parse(canvas.dataset.chart);
    charts.push({ chart: new Chart(canvas, options(config)), config });
    canvas.dataset.ready = '';
}

document.querySelectorAll('canvas[data-chart]').forEach(draw);

// Смена темы: перерисовать графики новыми цветами.
new MutationObserver(() => {
    charts.forEach((item) => {
        const fresh = options(item.config);
        item.chart.data.datasets[0].borderColor = fresh.data.datasets[0].borderColor;
        item.chart.data.datasets[0].backgroundColor = fresh.data.datasets[0].backgroundColor;
        item.chart.options.scales = fresh.options.scales;
        item.chart.update('none');
    });
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
