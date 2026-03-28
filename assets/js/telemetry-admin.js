/**
 * Telemetry Admin Dashboard — Chart.js charts and AJAX data loading.
 *
 * Depends on: Chart.js (loaded via wp_enqueue_script from CDN or bundled).
 * Data is passed via wp_localize_script as `wuTelemetry`.
 *
 * @package WP_Update_Server_Plugin
 */

/* global Chart, wuTelemetry */

(function () {
	'use strict';

	// Colour palette — consistent across all charts.
	var COLORS = {
		blue:       'rgba(34, 113, 177, 0.85)',
		blueBorder: 'rgba(34, 113, 177, 1)',
		green:      'rgba(0, 163, 42, 0.85)',
		greenBorder:'rgba(0, 163, 42, 1)',
		purple:     'rgba(124, 58, 237, 0.85)',
		purpleBorder:'rgba(124, 58, 237, 1)',
		stripe:     'rgba(99, 91, 255, 0.85)',
		stripeBorder:'rgba(99, 91, 255, 1)',
		paypal:     'rgba(0, 48, 135, 0.85)',
		paypalBorder:'rgba(0, 48, 135, 1)',
		orange:     'rgba(219, 166, 23, 0.85)',
		orangeBorder:'rgba(219, 166, 23, 1)',
		grid:       'rgba(0, 0, 0, 0.06)',
		text:       '#646970',
	};

	// Shared Chart.js defaults.
	var CHART_DEFAULTS = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: {
				display: false,
			},
			tooltip: {
				mode: 'index',
				intersect: false,
			},
		},
		scales: {
			x: {
				grid: { color: COLORS.grid },
				ticks: { color: COLORS.text, font: { size: 11 } },
			},
			y: {
				grid: { color: COLORS.grid },
				ticks: { color: COLORS.text, font: { size: 11 } },
				beginAtZero: true,
			},
		},
	};

	/**
	 * Build a horizontal bar chart for distribution data.
	 *
	 * @param {string} canvasId  Canvas element ID.
	 * @param {Array}  labels    Array of label strings.
	 * @param {Array}  values    Array of numeric values.
	 * @param {string} color     Bar fill colour.
	 * @param {string} border    Bar border colour.
	 */
	function buildBarChart(canvasId, labels, values, color, border) {
		var canvas = document.getElementById(canvasId);
		if (!canvas) {
			return;
		}

		new Chart(canvas, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					data: values,
					backgroundColor: color || COLORS.blue,
					borderColor: border || COLORS.blueBorder,
					borderWidth: 1,
					borderRadius: 3,
				}],
			},
			options: Object.assign({}, CHART_DEFAULTS, {
				indexAxis: 'y',
				plugins: Object.assign({}, CHART_DEFAULTS.plugins, {
					tooltip: {
						callbacks: {
							label: function (ctx) {
								return ' ' + ctx.parsed.x.toLocaleString() + ' sites';
							},
						},
					},
				}),
				scales: {
					x: Object.assign({}, CHART_DEFAULTS.scales.x, {
						ticks: Object.assign({}, CHART_DEFAULTS.scales.x.ticks, {
							callback: function (val) {
								return val.toLocaleString();
							},
						}),
					}),
					y: Object.assign({}, CHART_DEFAULTS.scales.y, {
						grid: { display: false },
					}),
				},
			}),
		});
	}

	/**
	 * Build a line chart for trend data.
	 *
	 * @param {string} canvasId  Canvas element ID.
	 * @param {Array}  labels    Date labels.
	 * @param {Array}  datasets  Array of dataset objects {label, data, color, border}.
	 */
	function buildLineChart(canvasId, labels, datasets) {
		var canvas = document.getElementById(canvasId);
		if (!canvas) {
			return;
		}

		var chartDatasets = datasets.map(function (ds) {
			return {
				label: ds.label,
				data: ds.data,
				backgroundColor: ds.color,
				borderColor: ds.border,
				borderWidth: 2,
				pointRadius: labels.length > 60 ? 0 : 3,
				tension: 0.3,
				fill: true,
			};
		});

		new Chart(canvas, {
			type: 'line',
			data: {
				labels: labels,
				datasets: chartDatasets,
			},
			options: Object.assign({}, CHART_DEFAULTS, {
				plugins: Object.assign({}, CHART_DEFAULTS.plugins, {
					legend: {
						display: datasets.length > 1,
						position: 'top',
					},
				}),
				scales: {
					x: Object.assign({}, CHART_DEFAULTS.scales.x, {
						ticks: Object.assign({}, CHART_DEFAULTS.scales.x.ticks, {
							maxTicksLimit: 12,
						}),
					}),
					y: Object.assign({}, CHART_DEFAULTS.scales.y, {
						ticks: Object.assign({}, CHART_DEFAULTS.scales.y.ticks, {
							callback: function (val) {
								return val.toLocaleString();
							},
						}),
					}),
				},
			}),
		});
	}

	/**
	 * Build a doughnut chart for gateway/addon usage.
	 *
	 * @param {string} canvasId Canvas element ID.
	 * @param {Array}  labels   Label strings.
	 * @param {Array}  values   Numeric values.
	 */
	function buildDoughnutChart(canvasId, labels, values) {
		var canvas = document.getElementById(canvasId);
		if (!canvas) {
			return;
		}

		var palette = [
			COLORS.blue, COLORS.green, COLORS.purple,
			COLORS.stripe, COLORS.orange, COLORS.paypal,
			'rgba(239, 68, 68, 0.85)',
			'rgba(245, 158, 11, 0.85)',
			'rgba(16, 185, 129, 0.85)',
			'rgba(59, 130, 246, 0.85)',
		];

		new Chart(canvas, {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [{
					data: values,
					backgroundColor: palette.slice(0, values.length),
					borderWidth: 2,
					borderColor: '#fff',
				}],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: true,
						position: 'right',
						labels: {
							font: { size: 11 },
							color: COLORS.text,
							boxWidth: 12,
						},
					},
					tooltip: {
						callbacks: {
							label: function (ctx) {
								var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
								var pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
								return ' ' + ctx.label + ': ' + ctx.parsed.toLocaleString() + ' (' + pct + '%)';
							},
						},
					},
				},
			},
		});
	}

	/**
	 * Initialise all charts from localised data.
	 */
	function init() {
		var d = wuTelemetry;

		// PHP version distribution.
		if (d.phpVersions && d.phpVersions.labels.length) {
			buildBarChart(
				'wu-chart-php',
				d.phpVersions.labels,
				d.phpVersions.values,
				COLORS.blue,
				COLORS.blueBorder
			);
		}

		// WP version distribution.
		if (d.wpVersions && d.wpVersions.labels.length) {
			buildBarChart(
				'wu-chart-wp',
				d.wpVersions.labels,
				d.wpVersions.values,
				COLORS.green,
				COLORS.greenBorder
			);
		}

		// Plugin version distribution.
		if (d.pluginVersions && d.pluginVersions.labels.length) {
			buildBarChart(
				'wu-chart-plugin',
				d.pluginVersions.labels,
				d.pluginVersions.values,
				COLORS.purple,
				COLORS.purpleBorder
			);
		}

		// Gateway usage doughnut.
		if (d.gateways && d.gateways.labels.length) {
			buildDoughnutChart(
				'wu-chart-gateways',
				d.gateways.labels,
				d.gateways.values
			);
		}

		// Addon usage doughnut.
		if (d.addons && d.addons.labels.length) {
			buildDoughnutChart(
				'wu-chart-addons',
				d.addons.labels,
				d.addons.values
			);
		}

		// Stripe daily trend.
		if (d.stripeTrend && d.stripeTrend.labels.length) {
			buildLineChart(
				'wu-chart-stripe-trend',
				d.stripeTrend.labels,
				[
					{
						label: 'Gross Volume',
						data: d.stripeTrend.grossVolume,
						color: 'rgba(99, 91, 255, 0.15)',
						border: COLORS.stripeBorder,
					},
					{
						label: 'Application Fees',
						data: d.stripeTrend.appFees,
						color: 'rgba(0, 163, 42, 0.15)',
						border: COLORS.greenBorder,
					},
				]
			);
		}

		// Subsite distribution.
		if (d.subsiteDist && d.subsiteDist.labels.length) {
			buildBarChart(
				'wu-chart-subsites',
				d.subsiteDist.labels,
				d.subsiteDist.values,
				COLORS.purple,
				COLORS.purpleBorder
			);
		}

		// Revenue distribution.
		if (d.revenueDist && d.revenueDist.labels.length) {
			buildBarChart(
				'wu-chart-revenue',
				d.revenueDist.labels,
				d.revenueDist.values,
				COLORS.green,
				COLORS.greenBorder
			);
		}

		// Hosting providers.
		if (d.hostingProviders && d.hostingProviders.labels.length) {
			buildBarChart(
				'wu-chart-hosting',
				d.hostingProviders.labels,
				d.hostingProviders.values,
				COLORS.orange,
				COLORS.orangeBorder
			);
		}
	}

	// Run after DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
