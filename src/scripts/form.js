/* global blockFinderAjax */
document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('block-finder-form');

	form.addEventListener('submit', async e => {
		e.preventDefault();

		const postTypeSelector = document.getElementById('post-type-selector');
		const blockSelector = document.getElementById('block-finder-selector');
		const postType = postTypeSelector.value;
		const block = blockSelector.value;
		const resultsContainer = document.getElementById('block-finder-results');
		const submitButton = form.querySelector('button[type="submit"]');

		// Show loading indicator
		submitButton.disabled = true;
		submitButton.textContent = 'Finding blocks...';
		resultsContainer.innerHTML = '<p id="block-results-loading">Loading results...</p>';

		if (postType === '' || block === '') {
			resultsContainer.innerHTML =
				'<p>Please select both a post type and a block to find.</p>';
			return;
		}

		const data = new URLSearchParams();
		data.append('action', 'find_blocks');
		data.append('post_type', postType);
		data.append('block', block);
		data.append('nonce', blockFinderAjax.nonce);

		try {
			const response = await fetch(blockFinderAjax.ajax_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: data.toString(),
			});

			if (response.ok) {
				const responseText = await response.text();
				resultsContainer.innerHTML = responseText;
			} else {
				const errorData = await response.json();
				resultsContainer.innerHTML = `<p>An error occurred: ${errorData.message}</p>`;
			}
		} catch (error) {
			resultsContainer.innerHTML = `<p>An error occurred: ${error.message}</p>`;
		} finally {
			submitButton.disabled = false;
			submitButton.textContent = 'Find Block';
		}
	});
});
