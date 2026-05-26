import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

const gridColor = 'rgba(255,255,255,0.05)';
const tickColor = 'rgba(255,255,255,0.4)';

const COLORS = [
    'rgb(59,130,246)',
    'rgb(239,68,68)',
    'rgb(34,197,94)',
    'rgb(168,85,247)',
];

const canvas = document.getElementById('compareChart');
if (canvas && typeof compareDatasets !== 'undefined' && compareDatasets.length > 0) {
    const allDates = [...new Set(compareDatasets.flatMap(d => d.dates))].sort();

    const datasets = compareDatasets.map((d, i) => ({
        label: d.title,
        data: allDates.map(date => {
            const idx = d.dates.indexOf(date);
            return idx >= 0 ? d.views[idx] : 0;
        }),
        borderColor: COLORS[i % COLORS.length],
        backgroundColor: COLORS[i % COLORS.length].replace(')', ', 0.06)').replace('rgb', 'rgba'),
        borderWidth: 2,
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 6,
    }));

    new Chart(canvas, {
        type: 'line',
        data: { labels: allDates.map(d => d.slice(5).replace('-', '/')), datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    labels: { color: tickColor, boxWidth: 12, padding: 16 },
                },
                tooltip: {
                    backgroundColor: 'rgba(20,20,20,0.95)',
                    borderColor: 'rgba(60,60,60,0.8)',
                    borderWidth: 1,
                    titleColor: '#fff',
                    bodyColor: 'rgba(255,255,255,0.7)',
                    padding: 12,
                },
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: tickColor, maxTicksLimit: 10 } },
                y: { grid: { color: gridColor }, ticks: { color: tickColor }, beginAtZero: true },
            },
        },
    });
}
