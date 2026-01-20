/* global blockFinderAjax */
document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('block-finder-form');

	/**
	 * Converts a select element into an autocomplete dropdown.
	 *
	 * @param {HTMLElement} selectElement   The select element to convert.
	 * @param {string}      placeholderText Placeholder text for the search input.
	 */
	function makeAutocomplete(selectElement, placeholderText) {
		// Create wrapper
		const wrapper = document.createElement('div');
		wrapper.className = 'autocomplete-wrapper';
		wrapper.style.position = 'relative';

		selectElement.parentNode.insertBefore(wrapper, selectElement);

		// Hide the original select but keep it for form submission
		selectElement.style.display = 'none';
		wrapper.appendChild(selectElement);

		// Store original options (excluding placeholder)
		const originalOptions = Array.from(selectElement.options).filter(
			option => option.value !== ''
		);

		// Create autocomplete input
		const autocompleteInput = document.createElement('input');
		autocompleteInput.type = 'text';
		autocompleteInput.className = 'autocomplete-input';
		autocompleteInput.placeholder = placeholderText || 'Type to search...';
		autocompleteInput.setAttribute('autocomplete', 'off');
		wrapper.appendChild(autocompleteInput);

		// Create dropdown container
		const dropdown = document.createElement('ul');
		dropdown.className = 'autocomplete-dropdown';
		wrapper.appendChild(dropdown);

		let selectedIndex = -1;
		let filteredOptions = [];

		// Show filtered options
		function showFilteredOptions() {
			const searchTerm = autocompleteInput.value.toLowerCase().trim();
			dropdown.innerHTML = '';

			if (!searchTerm) {
				// Show all options when input is empty
				filteredOptions = originalOptions;
			} else {
				// Filter options based on search term
				filteredOptions = originalOptions.filter(option => {
					const text = option.textContent.toLowerCase();
					return text.includes(searchTerm);
				});
			}

			if (filteredOptions.length === 0 && searchTerm) {
				const noResults = document.createElement('li');
				noResults.className = 'autocomplete-item no-results';
				noResults.textContent = 'No results found';
				dropdown.appendChild(noResults);
				dropdown.classList.add('show');
				// Reset scroll position to top
				dropdown.scrollTop = 0;
				return;
			}

			filteredOptions.forEach((option, index) => {
				const item = document.createElement('li');
				item.className = 'autocomplete-item';
				item.textContent = option.textContent;
				item.dataset.value = option.value;
				item.dataset.index = index;

				item.addEventListener('click', () => {
					selectOption(option);
				});

				item.addEventListener('mouseenter', () => {
					selectedIndex = index;
					updateHighlight();
				});

				dropdown.appendChild(item);
			});

			if (filteredOptions.length > 0) {
				dropdown.classList.add('show');
				selectedIndex = -1;
				// Reset scroll position to top
				dropdown.scrollTop = 0;
			} else {
				dropdown.classList.remove('show');
			}
		}

		// Update highlight on hover/keyboard
		function updateHighlight() {
			const items = dropdown.querySelectorAll('.autocomplete-item:not(.no-results)');
			items.forEach((item, index) => {
				if (index === selectedIndex) {
					item.classList.add('highlighted');
				} else {
					item.classList.remove('highlighted');
				}
			});
			// Scroll highlighted item into view
			if (selectedIndex >= 0 && items[selectedIndex]) {
				items[selectedIndex].scrollIntoView({ block: 'nearest' });
			}
		}

		// Select an option
		function selectOption(option) {
			selectElement.value = option.value;
			autocompleteInput.value = option.textContent;
			dropdown.classList.remove('show');
			selectedIndex = -1;

			// Trigger change event on select for any listeners
			selectElement.dispatchEvent(new Event('change', { bubbles: true }));
		}

		// Input event - show filtered options
		autocompleteInput.addEventListener('input', () => {
			showFilteredOptions();
		});

		// Focus event - show all options
		autocompleteInput.addEventListener('focus', () => {
			showFilteredOptions();
		});

		// Keyboard navigation
		autocompleteInput.addEventListener('keydown', e => {
			const items = dropdown.querySelectorAll('.autocomplete-item:not(.no-results)');

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				if (selectedIndex < items.length - 1) {
					selectedIndex++;
					updateHighlight();
				}
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				if (selectedIndex > 0) {
					selectedIndex--;
					updateHighlight();
				} else if (selectedIndex === 0) {
					selectedIndex = -1;
					updateHighlight();
				}
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (selectedIndex >= 0 && items[selectedIndex]) {
					const option = filteredOptions[selectedIndex];
					selectOption(option);
				} else if (filteredOptions.length === 1) {
					selectOption(filteredOptions[0]);
				}
			} else if (e.key === 'Escape') {
				dropdown.classList.remove('show');
				selectedIndex = -1;
			}
		});

		// Close dropdown when clicking outside
		document.addEventListener('click', e => {
			if (!wrapper.contains(e.target)) {
				dropdown.classList.remove('show');
				selectedIndex = -1;
			}
		});

		// Initialize with selected value if any
		if (selectElement.value) {
			const selectedOption = selectElement.options[selectElement.selectedIndex];
			if (selectedOption && selectedOption.value) {
				autocompleteInput.value = selectedOption.textContent;
			}
		}

		// Clear functionality
		autocompleteInput.addEventListener('blur', () => {
			// Small delay to allow click events on dropdown items to fire first
			setTimeout(() => {
				if (!selectElement.value) {
					autocompleteInput.value = '';
				} else {
					// Restore selected value if input was cleared
					const selectedOption = selectElement.options[selectElement.selectedIndex];
					if (selectedOption && selectedOption.value && !autocompleteInput.value) {
						autocompleteInput.value = selectedOption.textContent;
					}
				}
				dropdown.classList.remove('show');
			}, 200);
		});
	}

	// Initialize autocomplete dropdowns
	const postTypeSelector = document.getElementById('post-type-selector');
	const blockSelector = document.getElementById('block-finder-selector');

	if (postTypeSelector) {
		makeAutocomplete(postTypeSelector, 'Search post types...');
	}
	if (blockSelector) {
		makeAutocomplete(blockSelector, 'Search blocks...');
	}

	/**
	 * Creates a loading skeleton HTML string.
	 *
	 * @return {string} The loading skeleton HTML.
	 */
	function createLoadingSkeleton() {
		const skeletonItems = Array(5)
			.fill('')
			.map(
				() =>
					'<li class="skeleton-item"><span class="skeleton-text"></span><span class="skeleton-actions"></span></li>'
			)
			.join('');

		return `
			<div class="block-finder-loading">
				<div class="skeleton-header"></div>
				<ul class="skeleton-list">${skeletonItems}</ul>
			</div>
		`;
	}

	/**
	 * Performs the block search AJAX request.
	 *
	 * @param {number} page The page number to fetch.
	 */
	async function performSearch(page = 1) {
		if (!postTypeSelector || !blockSelector) {
			return;
		}

		const postType = postTypeSelector.value;
		const block = blockSelector.value;
		const resultsContainer = document.getElementById('block-finder-results');

		if (postType === '' && block === '') {
			resultsContainer.innerHTML =
				'<div class="block-finder-empty-state"><p>Select a post type and block to search.</p></div>';
			return;
		}

		if (postType === '') {
			resultsContainer.innerHTML =
				'<div class="block-finder-empty-state"><p>Please select a post type.</p></div>';
			return;
		}

		if (block === '') {
			resultsContainer.innerHTML =
				'<div class="block-finder-empty-state"><p>Please select a block to find.</p></div>';
			return;
		}

		const submitButton = form.querySelector('button[type="submit"]');

		// Show loading skeleton.
		submitButton.disabled = true;
		submitButton.textContent = 'Searching...';
		resultsContainer.innerHTML = createLoadingSkeleton();

		const data = new URLSearchParams();
		data.append('action', 'find_blocks');
		data.append('post_type', postType);
		data.append('block', block);
		data.append('page', page.toString());
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

				// Attach event listeners.
				attachPaginationListeners();
				attachFilterListeners();
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
	}

	/**
	 * Attaches click event listeners to pagination buttons.
	 */
	function attachPaginationListeners() {
		const resultsContainer = document.getElementById('block-finder-results');
		const prevButton = resultsContainer.querySelector('.block-finder-prev');
		const nextButton = resultsContainer.querySelector('.block-finder-next');

		if (prevButton) {
			prevButton.addEventListener('click', () => {
				const page = parseInt(prevButton.dataset.page, 10);
				performSearch(page);
			});
		}

		if (nextButton) {
			nextButton.addEventListener('click', () => {
				const page = parseInt(nextButton.dataset.page, 10);
				performSearch(page);
			});
		}
	}

	/**
	 * Attaches click event listeners to filter buttons.
	 */
	function attachFilterListeners() {
		const resultsContainer = document.getElementById('block-finder-results');
		const filterButtons = resultsContainer.querySelectorAll('.block-finder-filter');

		if (filterButtons.length === 0) {
			return;
		}

		filterButtons.forEach(button => {
			button.addEventListener('click', () => {
				// Update active state.
				filterButtons.forEach(btn => btn.classList.remove('active'));
				button.classList.add('active');

				// Get filter type.
				const filterType = button.dataset.filter;

				// Filter results.
				filterResults(filterType);
			});
		});
	}

	/**
	 * Filters the results list based on the selected filter type.
	 *
	 * @param {string} filterType The filter type: 'all', 'root', or 'nested'.
	 */
	function filterResults(filterType) {
		const resultsContainer = document.getElementById('block-finder-results');
		const resultItems = resultsContainer.querySelectorAll('.block-finder-list li');

		resultItems.forEach(item => {
			const hasRoot = item.dataset.hasRoot === '1';
			const hasNested = item.dataset.hasNested === '1';
			const contextElement = item.querySelector('.block-finder-context');

			// Show/hide list items based on filter.
			if (filterType === 'all') {
				item.style.display = '';
				// Show parent indicator for all.
				if (contextElement) {
					contextElement.style.display = '';
				}
			} else if (filterType === 'root') {
				item.style.display = hasRoot ? '' : 'none';
				// Hide parent indicator when filtering to root.
				if (contextElement) {
					contextElement.style.display = 'none';
				}
			} else if (filterType === 'nested') {
				item.style.display = hasNested ? '' : 'none';
				// Show parent indicator for nested.
				if (contextElement) {
					contextElement.style.display = '';
				}
			}
		});
	}

	form.addEventListener('submit', async e => {
		e.preventDefault();
		performSearch(1);
	});
});
