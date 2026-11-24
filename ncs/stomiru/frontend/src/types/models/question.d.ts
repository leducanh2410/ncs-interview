export interface QuestionCategory {
  id: number;
  name: string;
  topic: string;
  title: string;
}

export interface Question {
  id: number;
  question_category_id: number;
  content: string;
  options: Option[];
  is_answered?: boolean;
  selected_option_id?: number | null;
}

export interface Option {
  id: number;
  content: string;
}
