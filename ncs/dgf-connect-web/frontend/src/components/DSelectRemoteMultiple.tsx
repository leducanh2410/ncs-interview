import { useEffect, useMemo, useRef, useState } from 'react';
import { ComboboxItem, Loader, MultiSelect, MultiSelectProps } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api.d';
import ApiHelper from '@/helpers/api.helper';

const mergeOptions = (current: ComboboxItem[], incoming: ComboboxItem[]) => {
    if (incoming.length === 0) {
        return current;
    }

    const optionMap = new Map(current.map((option) => [option.value, option]));
    let hasChanged = false;

    for (const option of incoming) {
        const existing = optionMap.get(option.value);
        if (!existing || existing.label !== option.label) {
            optionMap.set(option.value, option);
            hasChanged = true;
        }
    }

    return hasChanged ? Array.from(optionMap.values()) : current;
};

/**
 * Remote searchable multi-select component with debounced API calls
 * @template T - The type of items returned by the API
 */
type DSelectRemoteMultipleProps<T> = Omit<
    MultiSelectProps,
    'data' | 'searchable' | 'searchValue' | 'onSearchChange' | 'filter'
> & {
    /** API endpoint for fetching options */
    uri: string;
    /**
     * Extra query parameters for the search request sent while the user types.
     * They are NOT sent with the id lookup that resolves the label of an
     * already-selected value, so a value that no longer matches them still
     * shows its name rather than a raw id.
     */
    extraParams?: ParsedUrlQuery;
    /** Function to transform API items to ComboboxItem format */
    renderItem: (item: T) => ComboboxItem;
    /** Initial options to display before search */
    initialOptions?: ComboboxItem[];
    /** Minimum search length before triggering API call (default: 3) */
    minSearchLength?: number;
    /** Callback for API errors */
    onError?: (error: Error) => void;
    /** Callback when API items are loaded */
    onItemsLoaded?: (items: T[]) => void;
    /** Extract items array from the API response. Defaults to treating response.data as T[]. */
    extractItems?: (response: ApiResponse<unknown>) => T[];
    /** Query parameter name for the search string (default: "q") */
    searchParam?: string;
    /**
     * Blocks typing a new query (and with it any remote lookup) while leaving
     * the control interactive, so already-selected chips can still be
     * removed. Use this - not `disabled` - when the search has no valid scope
     * yet: `disabled` would also freeze the current selection, trapping the
     * user with entries they can no longer clear.
     */
    searchDisabled?: boolean;
};

const DSelectRemoteMultiple = <T,>({
    uri,
    extraParams,
    renderItem,
    initialOptions,
    value,
    onChange,
    defaultValue,
    minSearchLength = 3,
    onError,
    onItemsLoaded,
    extractItems,
    searchParam = 'q',
    rightSection,
    disabled,
    searchDisabled,
    ...others
}: DSelectRemoteMultipleProps<T>) => {
    const [searchQuery, setSearchQuery] = useState('');
    const [searchQueryDebounced] = useDebouncedValue(searchQuery, 300);
    const [cachedOptions, setCachedOptions] = useState<ComboboxItem[]>(initialOptions || []);
    const [searchOptions, setSearchOptions] = useState<ComboboxItem[]>([]);
    const [internalValue, setInternalValue] = useState<string[]>(defaultValue || []);
    const [isSearching, setIsSearching] = useState(false);
    const [isResolvingValues, setIsResolvingValues] = useState(false);
    const isLoading = isSearching || isResolvingValues;
    const [dropdownOpened, setDropdownOpened] = useState(false);
    const skipSearchRef = useRef<string | null>(null);
    const renderItemRef = useRef(renderItem);
    const onErrorRef = useRef(onError);
    const onItemsLoadedRef = useRef(onItemsLoaded);
    const extractItemsRef = useRef(extractItems);
    const isControlled = value !== undefined;
    const normalizedExtraParams = extraParams || {};
    const selectedValuesKey = JSON.stringify(isControlled ? (value ?? []) : internalValue);
    const selectedValues = useMemo<string[]>(() => {
        return isControlled ? [...(value ?? [])] : [...internalValue];
    }, [isControlled, selectedValuesKey]);
    const extraParamsKey = JSON.stringify(normalizedExtraParams);

    useEffect(() => {
        renderItemRef.current = renderItem;
    }, [renderItem]);

    useEffect(() => {
        onErrorRef.current = onError;
    }, [onError]);

    useEffect(() => {
        onItemsLoadedRef.current = onItemsLoaded;
    }, [onItemsLoaded]);

    useEffect(() => {
        extractItemsRef.current = extractItems;
    }, [extractItems]);

    useEffect(() => {
        setCachedOptions((current) => mergeOptions(current, initialOptions || []));
    }, [initialOptions]);

    useEffect(() => {
        if (isControlled && selectedValues.length === 0) {
            setSearchQuery('');
        }
    }, [isControlled, selectedValues.length]);

    // Drop whatever was half-typed when the search closes, so re-enabling it
    // doesn't resurrect a query written against the previous scope.
    useEffect(() => {
        if (searchDisabled) {
            setSearchQuery('');
        }
    }, [searchDisabled]);

    // Search effect - triggers when user types
    useEffect(() => {
        if (skipSearchRef.current !== null) {
            const skipped = skipSearchRef.current;
            skipSearchRef.current = null;
            if (skipped === searchQueryDebounced) {
                setIsSearching(false);
                return;
            }
        }
        if (searchDisabled || searchQueryDebounced.length < minSearchLength) {
            setSearchOptions([]);
            setIsSearching(false);
            return;
        }

        const controller = new AbortController();
        setIsSearching(true);

        const fetchOptions = async () => {
            try {
                const params = { ...normalizedExtraParams, [searchParam]: searchQueryDebounced };
                const response = await ApiHelper.get(uri, { abortSignal: controller.signal, params });
                const items = extractItemsRef.current ? extractItemsRef.current(response) : (response.data as T[]);
                onItemsLoadedRef.current?.(items);
                return items.map(renderItemRef.current);
            } catch (e) {
                if (!controller.signal.aborted) {
                    onErrorRef.current?.(e as Error);
                    console.error(e);
                }
                return [];
            }
        };

        fetchOptions().then((fetchedOptions) => {
            if (controller.signal.aborted) {
                return;
            }
            setSearchOptions(fetchedOptions);
            setIsSearching(false);
        });

        return () => {
            controller.abort();
        };
    }, [searchQueryDebounced, extraParamsKey, uri, minSearchLength, searchDisabled]);

    const cachedOptionMap = useMemo(() => {
        return new Map(cachedOptions.map((option) => [option.value, option]));
    }, [cachedOptions]);

    const optionMap = useMemo(() => {
        return new Map([...cachedOptions, ...searchOptions].map((option) => [option.value, option]));
    }, [cachedOptions, searchOptions]);

    useEffect(() => {
        const selectedOptionsFromCurrentView: ComboboxItem[] = [];
        for (const selectedValue of selectedValues) {
            const option = optionMap.get(selectedValue);
            if (option) selectedOptionsFromCurrentView.push(option);
        }

        if (selectedOptionsFromCurrentView.length === 0) {
            return;
        }

        setCachedOptions((current) => mergeOptions(current, selectedOptionsFromCurrentView));
    }, [selectedValuesKey, optionMap]);

    const missingValues = useMemo(() => {
        return selectedValues.filter((selectedValue) => !cachedOptionMap.has(selectedValue));
    }, [cachedOptionMap, selectedValues]);

    const missingValuesKey = JSON.stringify(missingValues);

    useEffect(() => {
        if (missingValues.length === 0) {
            setIsResolvingValues(false);
            return;
        }

        const controller = new AbortController();
        setIsResolvingValues(true);

        const fetchSelectedOptions = async () => {
            try {
                const params = { key: missingValues };
                const response = await ApiHelper.get(uri, { abortSignal: controller.signal, params });
                const items = extractItemsRef.current ? extractItemsRef.current(response) : (response.data as T[]);
                onItemsLoadedRef.current?.(items);
                return items.map(renderItemRef.current);
            } catch (e) {
                if (!controller.signal.aborted) {
                    onErrorRef.current?.(e as Error);
                    console.error(e);
                }
                return [];
            }
        };

        fetchSelectedOptions().then((fetchedOptions) => {
            if (controller.signal.aborted) {
                return;
            }
            setCachedOptions((current) => mergeOptions(current, fetchedOptions));
            setIsResolvingValues(false);
        });

        return () => {
            controller.abort();
        };
    }, [selectedValuesKey, missingValuesKey, uri]);

    const selectOptions = useMemo(() => {
        const selectedOptions = selectedValues.map((selectedValue) => {
            return optionMap.get(selectedValue) || { value: selectedValue, label: selectedValue };
        });
        const remainingOptions = searchOptions.filter((option) => !selectedValues.includes(option.value));

        return [...selectedOptions, ...remainingOptions];
    }, [optionMap, searchOptions, selectedValues]);

    return (
        <MultiSelect
            searchable={!searchDisabled}
            data={selectOptions}
            searchValue={searchQuery}
            onSearchChange={(value) => {
                setSearchQuery(value);
                if (selectedValues.length > 0) {
                    const labels = selectedValues.map(
                        (selectedValue) => optionMap.get(selectedValue)?.label || selectedValue,
                    );
                    if (labels.includes(value)) {
                        skipSearchRef.current = value;
                        return;
                    }
                }
            }}
            filter={({ options }) => options}
            dropdownOpened={dropdownOpened}
            onDropdownOpen={() => setDropdownOpened(true)}
            onDropdownClose={() => setDropdownOpened(false)}
            value={selectedValues}
            onChange={(value) => {
                if (!isControlled) {
                    setInternalValue(value);
                }
                setDropdownOpened(false);
                onChange?.(value);
            }}
            disabled={disabled}
            rightSection={isLoading ? <Loader size="xs" /> : rightSection}
            {...others}
        />
    );
};

export default DSelectRemoteMultiple;
