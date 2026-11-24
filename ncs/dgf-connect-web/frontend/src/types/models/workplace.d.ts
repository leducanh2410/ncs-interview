export interface Workplace {
    id: number;
    name: string;
    address: string;
    coordinate: string;
    latitude: string;
    longitude: string;
    salary_per_hour: number | null;
    min_rest_hours: number | null;
    overtime_after_hours: number | null;
    is_test: boolean;
    is_yamato: boolean;
    is_self_register_disabled: boolean;
}

export interface WorkplaceSimple {
    id: number;
    name: string;
    is_test?: boolean;
}
