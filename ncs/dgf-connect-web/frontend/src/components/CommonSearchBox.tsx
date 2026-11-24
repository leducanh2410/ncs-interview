import { ReactNode, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSearch, LuX } from 'react-icons/lu';
import { useRouter } from 'next/router';
import { Button, Checkbox, ComboboxItem, Flex, MultiSelect, Paper, Select, SimpleGrid, TextInput } from '@mantine/core';
import { DatePickerInput, MonthPickerInput } from '@mantine/dates';
import { UseFormReturnType, useForm } from '@mantine/form';
import i18n from 'i18next';
import { ParsedUrlQuery } from 'querystring';
import { FormTransformConfig, ParamType } from '@/types/form.d';
import FormHelper from '@/helpers/form.helper';
import DSelectRemote from '@/components/DSelectRemote';
import { renderUserItem } from '@/components/DUserSelectRemote';

type SearchBoxElementType = 'input-text' | 'input-number' | 'select' | 'select-remote' | 'date' | 'date-range';

type SearchBoxElement = {
    type: SearchBoxElementType;
    label: string;
    id: string;
    name: string;
    defaultValues?: ParamType[];
    placeholders?: string[];
    searchEmpty?: {
        name: string;
        label: string;
    };
    visibleWhen?: (values: Record<string, ParamType>) => boolean;
};

type SearchBoxInputElement = SearchBoxElement & {
    type: 'input-text' | 'input-number';
};

type SearchBoxSelectElement = SearchBoxElement & {
    type: 'select';
    options: ComboboxItem[];
    isClearable?: boolean;
    isSearchable?: boolean;
    isMulti?: boolean;
    showAll?: boolean;
    allValue?: string;
};

type SearchBoxSelectRemoteElement<T> = SearchBoxElement & {
    type: 'select-remote';
    uri: string;
    extraParams?: ParsedUrlQuery;
    renderItem: (item: T) => ComboboxItem;
    isClearable?: boolean;
};

type SearchBoxDateElement = SearchBoxElement & {
    type: 'date';
    isMonth?: boolean;
    dateFormat?: string;
};

type SearchBoxDateRangeElement = Omit<SearchBoxDateElement, 'type'> & {
    type: 'date-range';
};

type Props<T> = {
    initialValues: Record<string, ParamType>;
    elements: (
        | SearchBoxInputElement
        | SearchBoxSelectElement
        | SearchBoxSelectRemoteElement<T>
        | SearchBoxDateElement
        | SearchBoxDateRangeElement
    )[];
    onSubmit?: (query: ParsedUrlQuery, values: Record<string, ParamType>) => void;
    config?: FormTransformConfig;
};

const displayFormat = () => i18n.t('FORMAT_DATE');
const displayFormatMonth = () => i18n.t('FORMAT_MONTH');

const itemBuilder: <T>(
    element:
        | SearchBoxInputElement
        | SearchBoxSelectElement
        | SearchBoxSelectRemoteElement<T>
        | SearchBoxDateElement
        | SearchBoxDateRangeElement,
    form: UseFormReturnType<any>,
) => ReactNode = (element, form) => {
    const isSearchEmpty = element.searchEmpty && form.values[element.searchEmpty.name];

    if (element.type === 'input-text' || element.type === 'input-number') {
        return <TextInput label={element.label} {...form.getInputProps(element.name)} disabled={isSearchEmpty} />;
    }
    if (element.type === 'select') {
        const Component = element.isMulti ? MultiSelect : Select;
        let isClearable = element.isClearable ?? true;
        let options = element.options;
        if (element.showAll && typeof element.allValue !== 'undefined') {
            options = [{ value: element.allValue, label: i18n.t('All') }, ...options];
        }
        return (
            <Component
                clearable={isClearable}
                searchable={element.isSearchable}
                label={element.label}
                data={options}
                {...form.getInputProps(element.name)}
                disabled={isSearchEmpty}
            />
        );
    }
    if (element.type === 'select-remote') {
        return (
            <DSelectRemote
                uri={element.uri}
                extraParams={element.extraParams}
                renderItem={element.renderItem}
                clearable={element.isClearable ?? true}
                label={element.label}
                {...form.getInputProps(element.name)}
                disabled={isSearchEmpty}
            />
        );
    }
    if (element.type === 'date') {
        if (element.isMonth) {
            return (
                <MonthPickerInput
                    label={element.label}
                    valueFormat={displayFormatMonth()}
                    rightSection={<LuCalendar size={16} />}
                    {...form.getInputProps(element.name)}
                    disabled={isSearchEmpty}
                />
            );
        }
        return (
            <DatePickerInput
                label={element.label}
                valueFormat={displayFormat()}
                rightSection={<LuCalendar size={16} />}
                {...form.getInputProps(element.name)}
                disabled={isSearchEmpty}
            />
        );
    }
    if (element.type === 'date-range') {
        return (
            <DatePickerInput
                key={element.id}
                label={element.label}
                valueFormat={displayFormat()}
                type="range"
                numberOfColumns={2}
                allowSingleDateInRange
                rightSection={<LuCalendar size={16} />}
                {...form.getInputProps(element.name)}
            />
        );
    }
};

const CommonSearchBox = <T,>({ initialValues, elements, onSubmit, config = {} }: Props<T>) => {
    const { t } = useTranslation();
    const { push, query: routerQuery } = useRouter();
    const form = useForm<Record<string, ParamType>>({ initialValues });
    const formInitRef = useRef<boolean>(false);

    for (const element of elements) {
        const elementConfig = config[element.name] ?? {};
        if (element.type === 'date' || element.type === 'date-range') {
            if (element.isMonth) {
                elementConfig.isMonth = true;
            } else {
                elementConfig.isDate = true;
            }
        } else if (element.type === 'select' && element.isMulti) {
            elementConfig.isArray = true;
        }
        if (Object.keys(elementConfig).length > 0) {
            config[element.name] = elementConfig;
        }
        if (element.searchEmpty) {
            config[element.searchEmpty.name] = { isBoolean: true };
        }
    }

    useEffect(() => {
        if (!formInitRef.current && Object.keys(routerQuery).length > 0) {
            form.setValues(FormHelper.queryToForm(routerQuery, config));
        }
        formInitRef.current = true;
    }, [routerQuery, config]);

    const isElementVisible = (element: (typeof elements)[number], values: Record<string, ParamType>) =>
        element.visibleWhen ? element.visibleWhen(values) : true;

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        const query = FormHelper.toParams(values, config);
        for (const element of elements) {
            if (!isElementVisible(element, values)) {
                delete query[element.name];
                if (element.searchEmpty) {
                    delete query[element.searchEmpty.name];
                }
                continue;
            }
            if (element.searchEmpty && query[element.searchEmpty.name]) {
                delete query[element.name];
            }
        }
        if (onSubmit) {
            onSubmit(query, values);
        } else {
            await push({
                query: {
                    page_size: routerQuery.page_size,
                    ...query,
                },
            });
        }
    };

    return (
        <Paper p="md" radius="md">
            <form onSubmit={form.onSubmit(onFormSubmit)}>
                <SimpleGrid cols={{ base: 1, sm: 2, md: 3, lg: 4 }} spacing="sm">
                    {elements.map((element) => {
                        if (!isElementVisible(element, form.values)) {
                            return null;
                        }
                        const component = itemBuilder(element, form);
                        return (
                            <Flex direction="column" gap={4} key={element.id}>
                                {component}
                                {element.searchEmpty && (
                                    <Checkbox
                                        label={element.searchEmpty.label}
                                        {...form.getInputProps(element.searchEmpty.name, { type: 'checkbox' })}
                                    />
                                )}
                            </Flex>
                        );
                    })}
                </SimpleGrid>
                <Flex gap="sm" mt="sm">
                    <Button type="submit" leftSection={<LuSearch size={16} />} w={120}>
                        {t('Search')}
                    </Button>
                    <Button
                        variant="default"
                        leftSection={<LuX size={16} />}
                        w={120}
                        onClick={async () => {
                            form.reset();
                            await onFormSubmit({});
                        }}
                    >
                        {t('Reset')}
                    </Button>
                </Flex>
            </form>
        </Paper>
    );
};

export default CommonSearchBox;

export { renderUserItem as userRenderItem };
