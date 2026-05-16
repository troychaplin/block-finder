import * as React from 'react';
import { createRoot, useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

interface SearchResponse {
	html: string;
	total: number;
}

interface ComboOption {
	value: string;
	label: string;
}

function BlockSelector({
	options,
	hiddenInput,
}: {
	options: ComboOption[];
	hiddenInput: HTMLInputElement;
}) {
	const [value, setValue] = useState<string | null>(null);

	return (
		<ComboboxControl
			label="Select a block you would like to search for"
			value={value}
			onChange={val => {
				setValue(val);
				hiddenInput.value = val ?? '';
			}}
			options={options}
		/>
	);
}

function PostTypeSelector({
	options,
	hiddenInput,
}: {
	options: ComboOption[];
	hiddenInput: HTMLInputElement;
}) {
	const [value, setValue] = useState<string>('all');

	return (
		<ComboboxControl
			label="Select a post type you wish to search in"
			value={value}
			onChange={val => {
				const next = val ?? 'all';
				setValue(next);
				hiddenInput.value = next;
			}}
			options={options}
		/>
	);
}

document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('block-finder-form') as HTMLFormElement | null;

	if (!form) {
		return;
	}

	// Mount block selector.
	const blockRoot = document.getElementById('block-finder-selector-root') as HTMLElement | null;
	const blockInput = document.getElementById('block-finder-selector') as HTMLInputElement | null;
	if (blockRoot && blockInput) {
		const blockOptions: ComboOption[] = JSON.parse(blockRoot.dataset.blocks ?? '[]');
		createRoot(blockRoot).render(
			<BlockSelector options={blockOptions} hiddenInput={blockInput} />
		);
	}

	// Mount post-type selector.
	const postTypeRoot = document.getElementById(
		'block-finder-post-type-root'
	) as HTMLElement | null;
	const postTypeInput = document.getElementById('post-type-selector') as HTMLInputElement | null;
	if (postTypeRoot && postTypeInput) {
		const postTypeOptions: ComboOption[] = JSON.parse(postTypeRoot.dataset.postTypes ?? '[]');
		createRoot(postTypeRoot).render(
			<PostTypeSelector options={postTypeOptions} hiddenInput={postTypeInput} />
		);
	}

	const blockSelector = document.getElementById(
		'block-finder-selector'
	) as HTMLInputElement | null;
	const postTypeSelector = document.getElementById(
		'post-type-selector'
	) as HTMLInputElement | null;

	// Conditional sections appear or hide based on which sources are checked.
	const sourceCheckboxes = Array.from(
		form.querySelectorAll<HTMLInputElement>('input[name="sources[]"]')
	);
	const conditionalSections = Array.from(
		form.querySelectorAll<HTMLElement>('[data-conditional-source]')
	);
	const formSubmitButton = form.querySelector<HTMLButtonElement>('button[type="submit"]');

	/**
	 * Show or hide each [data-conditional-source] section based on which sources are
	 * currently checked, and disable the submit button when no sources are selected.
	 */
	function updateConditionalSections(): void {
		const checked = sourceCheckboxes.filter(cb => cb.checked).map(cb => cb.value);

		for (const section of conditionalSections) {
			const required = (section.dataset.conditionalSource ?? '')
				.split(',')
				.map(s => s.trim())
				.filter(Boolean);
			const shouldShow = required.some(r => checked.includes(r));
			section.classList.toggle('block-finder-hidden', !shouldShow);
		}

		if (formSubmitButton) {
			formSubmitButton.disabled = checked.length === 0;
		}
	}

	for (const cb of sourceCheckboxes) {
		cb.addEventListener('change', updateConditionalSections);
	}

	updateConditionalSections();

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

		const checkedStatuses = Array.from(
			form.querySelectorAll<HTMLInputElement>('input[name="post_status[]"]:checked')
		).map(input => input.value);

		const checkedSources = Array.from(
			form.querySelectorAll<HTMLInputElement>('input[name="sources[]"]:checked')
		).map(input => input.value);

		try {
			const data = await apiFetch<SearchResponse>({
				path: addQueryArgs('/block-finder/v1/search', {
					block,
					post_type: postType,
					page,
					filter,
					post_status: checkedStatuses,
					sources: checkedSources,
				}),
				method: 'GET',
			});
			resultsContainer.innerHTML = data.html;

			// Attach event listeners.
			attachPaginationListeners();
			attachFilterListeners();
		} catch (error) {
			const message = (error as { message?: string })?.message ?? 'Unknown error';
			resultsContainer.innerHTML = `<p>An error occurred: ${message}</p>`;
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
