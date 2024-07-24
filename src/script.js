/* eslint-disable no-undef */
document.addEventListener('DOMContentLoaded', function () {
	const form = document.getElementById('block-finder-form');

	form.addEventListener('submit', function (e) {
		e.preventDefault();

		const blockSelector = document.getElementById('block-finder-selector');
		const block = blockSelector.value;
		const resultsContainer = document.getElementById(
			'block-finder-results'
		);

		const xhr = new XMLHttpRequest();
		xhr.open('POST', blockFinderAjax.ajax_url, true);
		xhr.setRequestHeader(
			'Content-Type',
			'application/x-www-form-urlencoded; charset=UTF-8'
		);

		xhr.onload = function () {
			if (xhr.status >= 200 && xhr.status < 400) {
				resultsContainer.innerHTML = xhr.responseText;
			} else {
				resultsContainer.innerHTML =
					'<p>An error occurred while processing the request.</p>';
			}
		};

		xhr.onerror = function () {
			resultsContainer.innerHTML =
				'<p>An error occurred while processing the request.</p>';
		};

		xhr.send('action=find_blocks&block=' + encodeURIComponent(block));
	});
});
