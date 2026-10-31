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
 * Подпись оси: дата «2026-03-08» (так отдаёт Period::days()) → «08.03»; остальные подписи как есть.
 */
function shortDate(label) {
    return /^\d{4}-\d{2}-\d{2}$/.test(label) ? `${label.slice(8)}.${label.slice(5, 7)}` : label;
}

/**
 * Подпись в подсказке: дата «2026-03-08» → «08.03.2026».
 */
function fullDate(label) {
    return /^\d{4}-\d{2}-\d{2}$/.test(label) ? `${label.slice(8)}.${label.slice(5, 7)}.${label.slice(0, 4)}` : String(label);
}

/**
 * Настройки графика по данным виджета и текущей теме.
 */
function options(config) {
    const primary = cssVar('--fastleo-primary', '#4f46e5');
    const line = cssVar('--fl-border', 'rgba(128, 128, 128, 0.15)');
    const muted = cssVar('--fl-text-3', '#78726a');
    const isBar = config.type === 'bar';
    // Одна линия — как раньше (акцент и мягкая заливка); несколько — разные цвета без заливки и легенда.
    const series = config.datasets ?? [{ label: config.label, values: config.values }];
    const multi = series.length > 1;
    const palette = [primary, '#0ea5e9', '#16a34a', '#d97706', '#dc2626', '#9333ea', '#0d9488', '#64748b'];
    const ticks = { color: muted, font: { size: 11 } };

    return {
        type: config.type,
        data: {
            labels: config.labels.map(shortDate),
            datasets: series.map((item, index) => {
                const color = multi ? palette[index % palette.length] : primary;
                const last = item.values.length - 1;

                return {
                    label: item.label,
                    data: item.values,
                    borderColor: color,
                    // Столбцы — приглушённый акцент, последний (текущий) — полный; линия — с мягкой заливкой.
                    backgroundColor: multi ? (isBar ? color : mix(color, 14)) : isBar ? item.values.map((value, position) => (position === last ? color : mix(color, 26))) : mix(color, 14),
                    borderRadius: isBar ? { topLeft: 5, topRight: 5 } : 0,
                    borderSkipped: isBar ? 'bottom' : false,
                    maxBarThickness: 28,
                    fill: !isBar && !multi,
                    // Монотонная кривая: гладкая, но без выбросов между точками (счётчики по дням не «проседают» и не «взлетают»).
                    cubicInterpolationMode: 'monotone',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                };
            }),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: multi ? { mode: 'index', intersect: false } : undefined,
            plugins: {
                legend: { display: multi, position: 'bottom', labels: { color: muted, boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                tooltip: { callbacks: { title: (items) => fullDate(config.labels[items[0]?.dataIndex] ?? '') } },
            },
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
        fresh.data.datasets.forEach((dataset, index) => {
            item.chart.data.datasets[index].borderColor = dataset.borderColor;
            item.chart.data.datasets[index].backgroundColor = dataset.backgroundColor;
        });
        item.chart.options.scales = fresh.options.scales;
        item.chart.options.plugins.legend = fresh.options.plugins.legend;
        item.chart.update('none');
    });
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
