import { useEffect, useMemo, useRef, useState } from 'react';
import { ComboboxItem, Loader, Select, SelectProps } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { ParsedUrlQuery } from 'querystring';
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
 * Remote searchable select component with debounced API calls
 * @template T - The type of items returned by the API
 */
type DSelectRemoteProps<T> = Omit<SelectProps, 'data' | 'searchable' | 'searchValue' | 'onSearchChange' | 'filter'> & {
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
};

const DSelectRemote = <T,>({
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
    rightSection,
    disabled,
    ...others
}: DSelectRemoteProps<T>) => {
    const [searchQuery, setSearchQuery] = useState('');
    const [searchQueryDebounced] = useDebouncedValue(searchQuery, 300);
    const [cachedOptions, setCachedOptions] = useState<ComboboxItem[]>(initialOptions || []);
    const [searchOptions, setSearchOptions] = useState<ComboboxItem[]>([]);
    const [internalValue, setInternalValue] = useState<string | null>(defaultValue || null);
    const [isSearching, setIsSearching] = useState(false);
    const [isResolvingValue, setIsResolvingValue] = useState(false);
    const isLoading = isSearching || isResolvingValue;
    const [dropdownOpened, setDropdownOpened] = useState(false);
    const skipSearchRef = useRef<string | null>(null);
    const renderItemRef = useRef(renderItem);
    const onErrorRef = useRef(onError);
    const onItemsLoadedRef = useRef(onItemsLoaded);
    const isControlled = value !== undefined;
    const selectedValue = isControlled ? (value ?? null) : internalValue;
    const normalizedExtraParams = extraParams || {};
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
        setCachedOptions((current) => mergeOptions(current, initialOptions || []));
    }, [initialOptions]);

    useEffect(() => {
        if (isControlled && !selectedValue) {
            setSearchQuery('');
        }
    }, [isControlled, selectedValue]);

    useEffect(() => {
        if (skipSearchRef.current !== null) {
            const skipped = skipSearchRef.current;
            skipSearchRef.current = null;
            if (skipped === searchQueryDebounced) {
                setIsSearching(false);
                return;
            }
        }
        if (searchQueryDebounced.length < minSearchLength) {
            setSearchOptions([]);
            setIsSearching(false);
            return;
        }

        const controller = new AbortController();
        setIsSearching(true);

        const fetchOptions = async () => {
            try {
                const params = { ...normalizedExtraParams, q: searchQueryDebounced };
                const response = await ApiHelper.get(uri, { abortSignal: controller.signal, params });
                const items = response.data as T[];
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
    }, [searchQueryDebounced, extraParamsKey, uri, minSearchLength]);

    const cachedOptionMap = useMemo(() => {
        return new Map(cachedOptions.map((option) => [option.value, option]));
    }, [cachedOptions]);

    const optionMap = useMemo(() => {
        return new Map([...cachedOptions, ...searchOptions].map((option) => [option.value, option]));
    }, [cachedOptions, searchOptions]);

    useEffect(() => {
        if (!selectedValue) {
            return;
        }

        const selectedOption = optionMap.get(selectedValue);
        if (!selectedOption) {
            return;
        }

        setCachedOptions((current) => mergeOptions(current, [selectedOption]));
    }, [selectedValue, optionMap]);

    const missingValue = useMemo(() => {
        if (!selectedValue || cachedOptionMap.has(selectedValue)) {
            return null;
        }

        return selectedValue;
    }, [cachedOptionMap, selectedValue]);

    useEffect(() => {
        if (!missingValue) {
            setIsResolvingValue(false);
            return;
        }

        const controller = new AbortController();
        setIsResolvingValue(true);

        const fetchSelectedOption = async () => {
            try {
                const params = { key: missingValue };
                const response = await ApiHelper.get(uri, { abortSignal: controller.signal, params });
                const items = response.data as T[];
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

        fetchSelectedOption().then((fetchedOptions) => {
            if (controller.signal.aborted) {
                return;
            }
            setCachedOptions((current) => mergeOptions(current, fetchedOptions));
            setIsResolvingValue(false);
        });

        return () => {
            controller.abort();
        };
    }, [missingValue, uri]);

    const selectOptions = useMemo(() => {
        const selectedOption = selectedValue
            ? optionMap.get(selectedValue) || { value: selectedValue, label: selectedValue }
            : null;
        const remainingOptions = searchOptions.filter((option) => option.value !== selectedValue);

        return selectedOption ? [selectedOption, ...remainingOptions] : remainingOptions;
    }, [optionMap, searchOptions, selectedValue]);

    return (
        <Select
            searchable
            data={selectOptions}
            searchValue={searchQuery}
            onSearchChange={(value) => {
                setSearchQuery(value);
                if (selectedValue) {
                    const label = optionMap.get(selectedValue)?.label || selectedValue;
                    if (value === label) {
                        skipSearchRef.current = value;
                        return;
                    }
                }
            }}
            filter={({ options }) => options}
            dropdownOpened={dropdownOpened}
            onDropdownOpen={() => setDropdownOpened(true)}
            onDropdownClose={() => setDropdownOpened(false)}
            value={selectedValue}
            onChange={(value, option) => {
                if (!isControlled) {
                    setInternalValue(value);
                }
                setDropdownOpened(false);
                onChange?.(value, option);
            }}
            disabled={disabled}
            rightSection={isLoading ? <Loader size="xs" /> : rightSection}
            {...others}
        />
    );
};

export default DSelectRemote;
