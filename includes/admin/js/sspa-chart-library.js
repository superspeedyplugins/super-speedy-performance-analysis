(function () {
	'use strict';
	var echartsPromise = null;
	function loadECharts() {
		if (window.SSPAECharts) {
			return Promise.resolve(window.SSPAECharts);
		}
		if (echartsPromise) {
			return echartsPromise;
		}
		echartsPromise = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = sspa_admin.history_chart_asset;
			script.async = true;
			script.onload = function () {
				if (window.SSPAECharts) {
					resolve(window.SSPAECharts);
				} else {
					reject(new Error(wp.i18n.__("ECharts did not initialise.", "super-speedy-performance-analysis")));
				}
			};
			script.onerror = function () {
				reject(new Error(wp.i18n.__("The local chart library could not be loaded.", "super-speedy-performance-analysis")));
			};
			document.head.appendChild(script);
		});
		return echartsPromise;
	}

	window.SSPAChartLibrary = {load: loadECharts};
})();
