import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

const defaults = {
    borderWidth: 2,
    fill: true,
    tension: 0.4,
    pointRadius: 3,
    pointHoverRadius: 6,
};

const gridColor = 'rgba(255,255,255,0.05)';
const tickColor = 'rgba(255,255,255,0.4)';

function makeOptions(suffix = '') {
    return {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(20,20,20,0.95)',
                borderColor: 'rgba(60,60,60,0.8)',
                borderWidth: 1,
                titleColor: '#fff',
                bodyColor: 'rgba(255,255,255,0.7)',
                padding: 12,
                callbacks: suffix ? {
                    label: ctx => `${ctx.parsed.y} ${suffix}`,
                } : {},
            },
        },
        scales: {
            x: {
                grid: { color: gridColor },
                ticks: { color: tickColor, maxTicksLimit: 8 },
            },
            y: {
                grid: { color: gridColor },
                ticks: {
                    color: tickColor,
                    callback: suffix ? (v => `${v} ${suffix}`) : undefined,
                },
                beginAtZero: true,
            },
        },
    };
}

function initChart(id, color, yKey, suffix = '') {
    const canvas = document.getElementById(id);
    if (!canvas || !chartData[yKey] || chartData[yKey].length === 0) return null;

    return new Chart(canvas, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                ...defaults,
                data: chartData[yKey],
                borderColor: color,
                backgroundColor: color.replace(')', ', 0.08)').replace('rgb', 'rgba'),
                pointBackgroundColor: color,
            }],
        },
        options: makeOptions(suffix),
    });
}

const viewsChart = initChart('viewsChart',       'rgb(59,130,246)',  'views');
const subChart   = initChart('subscribersChart', 'rgb(168,85,247)', 'subscribers');
const wtChart    = initChart('watchTimeChart',   'rgb(249,115,22)', 'watchTime', 'h');

// Period selector
document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const data = await fetch(`/api/chart-data?days=${btn.dataset.days}`).then(r => r.json());

        [
            [viewsChart, 'views'],
            [subChart,   'subscribers'],
            [wtChart,    'watchTime'],
        ].forEach(([chart, key]) => {
            if (!chart) return;
            chart.data.labels = data.labels;
            chart.data.datasets[0].data = data[key];
            chart.update();
        });
    });
});
