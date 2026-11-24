import type { Role } from "./role";

export interface User {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  login_id: string;
  email: string;
  status: string;
  roles: Role[];
  company_id?: number;
  company_code?: string;
  employee_code?: string | null;
  employee_status: string;
  user_profile: UserProfile;
  lang_code: string;
  need_password_change?: boolean;
}

export interface Employee extends User { }

export interface UserProfile {
  gender: string;
  department: string | null;
  birthday: string;
  phone_number: string | null;
  position: string | null;
}
