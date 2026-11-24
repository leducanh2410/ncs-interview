export interface Log {
  id: number;
  company_id: number;
  actor_id: number | null;
  actor_name: string;
  actor_role: string[];
  category: string;
  target_id: string | null;
  action: string;
  created_at: string;
  updated_at: string;
}
