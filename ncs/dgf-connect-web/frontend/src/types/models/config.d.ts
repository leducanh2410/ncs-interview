export interface Config {
    max_shift_per_day: string;
    max_hour_per_week: string;
    salary_per_hour: string;
    login_url: string | null;
}

export interface UserConfig extends Config {
    max_shift_per_day_start_date: string;
    max_shift_per_day_expiry_date: string;
    max_hour_per_week_start_date: string;
    max_hour_per_week_expiry_date: string;
}

export type UserCustomConfigKey = 'max_shift_per_day' | 'max_hour_per_week';

export interface UserCustomConfig {
    key: UserCustomConfigKey;
    value: number;
    default_value: number;
    start_date: string | null;
    expiry_date: string | null;
}
