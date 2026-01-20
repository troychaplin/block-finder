/**
 * Block Finder Ajax global object interface.
 */
interface BlockFinderAjax {
	ajax_url: string;
	nonce: string;
}

declare const blockFinderAjax: BlockFinderAjax;

document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('block-finder-form') as HTMLFormElement | null;

	if (!form) {
		return;
	}

	/**
	 * Converts a select element into an autocomplete dropdown.
	 *
	 * @param selectElement   The select element to convert.
	 * @param placeholderText Placeholder text for the search input.
	 */
	function makeAutocomplete(selectElement: HTMLSelectElement, placeholderText: string): void {
		// Create wrapper
		const wrapper = document.createElement('div');
		wrapper.className = 'autocomplete-wrapper';
		wrapper.style.position = 'relative';

		selectElement.parentNode?.insertBefore(wrapper, selectElement);

		// Hide the original select but keep it for form submission
		selectElement.style.display = 'none';
		wrapper.appendChild(selectElement);

		// Store original options (excluding placeholder)
		const originalOptions: HTMLOptionElement[] = Array.from(selectElement.options).filter(
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
		let filteredOptions: HTMLOptionElement[] = [];

		// Show filtered options
		function showFilteredOptions(): void {
			const searchTerm = autocompleteInput.value.toLowerCase().trim();
			dropdown.innerHTML = '';

			if (!searchTerm) {
				// Show all options when input is empty
				filteredOptions = originalOptions;
			} else {
				// Filter options based on search term
				filteredOptions = originalOptions.filter(option => {
					const text = (option.textContent || '').toLowerCase();
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
				item.dataset.index = index.toString();

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
		function updateHighlight(): void {
			const items = dropdown.querySelectorAll<HTMLElement>(
				'.autocomplete-item:not(.no-results)'
			);
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
		function selectOption(option: HTMLOptionElement): void {
			selectElement.value = option.value;
			autocompleteInput.value = option.textContent || '';
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
		autocompleteInput.addEventListener('keydown', (e: KeyboardEvent) => {
			const items = dropdown.querySelectorAll<HTMLElement>(
				'.autocomplete-item:not(.no-results)'
			);

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
		document.addEventListener('click', (e: MouseEvent) => {
			if (!wrapper.contains(e.target as Node)) {
				dropdown.classList.remove('show');
				selectedIndex = -1;
			}
		});

		// Initialize with selected value if any
		if (selectElement.value) {
			const selectedOption = selectElement.options[selectElement.selectedIndex];
			if (selectedOption && selectedOption.value) {
				autocompleteInput.value = selectedOption.textContent || '';
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
						autocompleteInput.value = selectedOption.textContent || '';
					}
				}
				dropdown.classList.remove('show');
			}, 200);
		});
	}

	// Initialize autocomplete dropdowns
	const postTypeSelector = document.getElementById(
		'post-type-selector'
	) as HTMLSelectElement | null;
	const blockSelector = document.getElementById(
		'block-finder-selector'
	) as HTMLSelectElement | null;

	if (postTypeSelector) {
		makeAutocomplete(postTypeSelector, 'Search post types...');
	}
	if (blockSelector) {
		makeAutocomplete(blockSelector, 'Search blocks...');
	}

	/**
	 * Creates a loading skeleton HTML string.
	 *
	 * @return The loading skeleton HTML.
	 */
	function createLoadingSkeleton(): string {
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

	// Track current filter state.
	let currentFilter = 'all';

	/**
	 * Performs the block search AJAX request.
	 *
	 * @param page   The page number to fetch.
	 * @param filter The filter type: 'all', 'root', or 'nested'.
	 */
	async function performSearch(page: number = 1, filter: string = 'all'): Promise<void> {
		if (!postTypeSelector || !blockSelector) {
			return;
		}

		const postType = postTypeSelector.value;
		const block = blockSelector.value;
		const resultsContainer = document.getElementById('block-finder-results');

		if (!resultsContainer) {
			return;
		}

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

		const submitButton = form?.querySelector(
			'button[type="submit"]'
		) as HTMLButtonElement | null;

		if (!form || !submitButton) {
			return;
		}

		// Update current filter state.
		currentFilter = filter;

		// Show loading skeleton.
		submitButton.disabled = true;
		submitButton.textContent = 'Searching...';
		resultsContainer.innerHTML = createLoadingSkeleton();

		const data = new URLSearchParams();
		data.append('action', 'find_blocks');
		data.append('post_type', postType);
		data.append('block', block);
		data.append('page', page.toString());
		data.append('filter', filter);
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
			const errorMessage = error instanceof Error ? error.message : 'Unknown error';
			resultsContainer.innerHTML = `<p>An error occurred: ${errorMessage}</p>`;
		} finally {
			submitButton.disabled = false;
			submitButton.textContent = 'Find Block';
		}
	}

	/**
	 * Attaches click event listeners to pagination buttons.
	 */
	function attachPaginationListeners(): void {
		const resultsContainer = document.getElementById('block-finder-results');

		if (!resultsContainer) {
			return;
		}

		const prevButton = resultsContainer.querySelector(
			'.block-finder-prev'
		) as HTMLButtonElement | null;
		const nextButton = resultsContainer.querySelector(
			'.block-finder-next'
		) as HTMLButtonElement | null;

		if (prevButton) {
			prevButton.addEventListener('click', () => {
				const page = parseInt(prevButton.dataset.page || '1', 10);
				performSearch(page, currentFilter);
			});
		}

		if (nextButton) {
			nextButton.addEventListener('click', () => {
				const page = parseInt(nextButton.dataset.page || '1', 10);
				performSearch(page, currentFilter);
			});
		}
	}

	/**
	 * Attaches click event listeners to filter links.
	 */
	function attachFilterListeners(): void {
		const resultsContainer = document.getElementById('block-finder-results');

		if (!resultsContainer) {
			return;
		}

		const filterLinks = resultsContainer.querySelectorAll<HTMLAnchorElement>(
			'.block-finder-filter-link'
		);

		filterLinks.forEach(link => {
			link.addEventListener('click', e => {
				e.preventDefault();
				const filterType = link.dataset.filter || 'all';

				// Perform new search with filter (resets to page 1).
				performSearch(1, filterType);
			});
		});
	}

	form.addEventListener('submit', async (e: Event) => {
		// Reset filter when submitting a new search.
		currentFilter = 'all';
		e.preventDefault();
		performSearch(1);
	});
});
