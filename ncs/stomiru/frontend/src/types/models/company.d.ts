export interface Company {
  id: number;
  company_name: string;
  company_code: string;
  building_name: string;
  address: string;
  postal_code: string;
  prefecture: string;
  contact_first_name: string;
  contact_last_name: string;
  contact_email: string;
  contact_phone: string;
  contract_start_date: string;
  contract_end_date?: string;
  status: string;
  max_employee_accounts: number;
  max_manager_accounts: number;
  note?: string;
}
