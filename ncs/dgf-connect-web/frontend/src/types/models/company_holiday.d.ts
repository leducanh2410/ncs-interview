export type CompanyHolidaySource = 'library' | 'manual';

export interface CompanyHoliday {
    id: number;
    date: string; // YYYY-MM-DD
    name: string;
    source: CompanyHolidaySource;
}

export interface CompanyHolidayCalendar {
    year: number;
    exclude_weekends: boolean;
    holidays: CompanyHoliday[];
}
