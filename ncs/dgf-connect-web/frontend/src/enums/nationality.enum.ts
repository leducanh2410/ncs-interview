export type Nationality =
    | 'VIETNAM'
    | 'NEPAL'
    | 'SRI_LANKA'
    | 'MYANMAR'
    | 'INDONESIA'
    | 'CAMBODIA'
    | 'THAILAND'
    | 'PAKISTAN'
    | 'BANGLADESH'
    | 'MONGOLIA'
    | 'LAOS'
    | 'INDIA'
    | 'SINGAPORE'
    | 'KOREA'
    | 'CHINA'
    | 'EAST_TIMOR'
    | 'PHILIPPINES'
    | 'BHUTAN'
    | 'BRUNEI'
    | 'MALAYSIA'
    | 'MALDIVES'
    | 'OTHER';

export const NationalityEnum: { [key in Nationality]: { value: string; text: string } } = Object.freeze({
    VIETNAM: { value: 'vietnam', text: 'Vietnam' },
    NEPAL: { value: 'nepal', text: 'Nepal' },
    SRI_LANKA: { value: 'sri_lanka', text: 'Sri Lanka' },
    MYANMAR: { value: 'myanmar', text: 'Myanmar' },
    INDONESIA: { value: 'indonesia', text: 'Indonesia' },
    CAMBODIA: { value: 'cambodia', text: 'Cambodia' },
    THAILAND: { value: 'thailand', text: 'Thailand' },
    PAKISTAN: { value: 'pakistan', text: 'Pakistan' },
    BANGLADESH: { value: 'bangladesh', text: 'Bangladesh' },
    MONGOLIA: { value: 'mongolia', text: 'Mongolia' },
    LAOS: { value: 'laos', text: 'Laos' },
    INDIA: { value: 'india', text: 'India' },
    SINGAPORE: { value: 'singapore', text: 'Singapore' },
    KOREA: { value: 'korea', text: 'Korea' },
    CHINA: { value: 'china', text: 'China' },
    EAST_TIMOR: { value: 'east_timor', text: 'East Timor' },
    PHILIPPINES: { value: 'philippines', text: 'Philippines' },
    BHUTAN: { value: 'bhutan', text: 'Bhutan' },
    BRUNEI: { value: 'brunei', text: 'Brunei' },
    MALAYSIA: { value: 'malaysia', text: 'Malaysia' },
    MALDIVES: { value: 'maldives', text: 'Maldives' },
    OTHER: { value: 'other', text: 'Other' },
});
