import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

const gridColor = 'rgba(255,255,255,0.05)';
const tickColor = 'rgba(255,255,255,0.4)';

function baseOptions(suffix = '') {
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
                callbacks: suffix ? { label: ctx => `${ctx.parsed.y} ${suffix}` } : {},
            },
        },
        scales: {
            x: { grid: { color: gridColor }, ticks: { color: tickColor, maxTicksLimit: 10 } },
            y: {
                grid: { color: gridColor },
                ticks: { color: tickColor, callback: suffix ? (v => `${v}${suffix}`) : undefined },
                beginAtZero: true,
            },
        },
    };
}

function makeLine(id, color, labels, data, suffix = '') {
    const canvas = document.getElementById(id);
    if (!canvas || !data || data.length === 0) return;
    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data,
                borderColor: color,
                backgroundColor: color.replace('rgb(', 'rgba(').replace(')', ', 0.08)'),
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: color,
            }],
        },
        options: baseOptions(suffix),
    });
}

// Views chart
if (typeof chartData !== 'undefined') {
    makeLine('viewsChart',      'rgb(59,130,246)',  chartData.labels, chartData.views);
    makeLine('ctrChart',        'rgb(234,179,8)',   chartData.labels, chartData.ctr, '%');
    makeLine('watchTimeChart',  'rgb(249,115,22)',  chartData.labels, chartData.watchTime, 'h');
    makeLine('subscribersChart','rgb(168,85,247)',  chartData.labels, chartData.subscribers);
}

// Retention curve
if (typeof retentionData !== 'undefined' && retentionData.labels.length > 0) {
    const canvas = document.getElementById('retentionChart');
    if (canvas) {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: retentionData.labels,
                datasets: [{
                    data: retentionData.values,
                    borderColor: 'rgb(34,197,94)',
                    backgroundColor: 'rgba(34,197,94,0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                }],
            },
            options: {
                ...baseOptions('%'),
                plugins: {
                    ...baseOptions('%').plugins,
                    annotation: {},
                },
                scales: {
                    ...baseOptions('%').scales,
                    y: { ...baseOptions('%').scales.y, min: 0, max: 100 },
                    x: {
                        ...baseOptions('%').scales.x,
                        ticks: {
                            color: tickColor,
                            maxTicksLimit: 10,
                            callback: (v, i) => {
                                const s = retentionData.labels[i];
                                if (s >= 3600) return `${Math.floor(s/3600)}h`;
                                if (s >= 60)   return `${Math.floor(s/60)}m`;
                                return `${s}s`;
                            },
                        },
                    },
                },
            },
        });
    }
}
