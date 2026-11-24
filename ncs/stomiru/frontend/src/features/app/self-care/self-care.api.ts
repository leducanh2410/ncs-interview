import { queryOptions, useMutation } from "@tanstack/react-query";
import type { ApiResponse } from "@/types/api";
import type { ExamResultData } from "@/types/models/stress-check";
import type { Question, QuestionCategory } from "@/types/models/question";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper from "@/helpers/api.helper";

// Raw API response types (with translations arrays from backend)
interface RawTranslation {
  content?: string;
  topic?: string;
  title?: string;
}

interface RawOption {
  id: number;
  translations?: RawTranslation[];
  content?: string;
}

interface RawQuestion {
  id: number;
  question_category_id: number;
  translations?: RawTranslation[];
  content?: string;
  options: RawOption[];
}

interface RawQuestionCategory {
  id: number;
  name?: string;
  translations?: RawTranslation[];
  topic?: string;
  title?: string;
}

interface RawSelfCareExamData {
  questions: RawQuestion[];
  question_categories: RawQuestionCategory[];
}

export interface SelfCareExamData {
  questions: Question[];
  question_categories: QuestionCategory[];
}

type GetSelfCareExamResponse = ApiResponse<RawSelfCareExamData>;

/** Flatten translations arrays into direct properties to match the format used by stress-check */
function transformExamData(
  raw: RawSelfCareExamData
): SelfCareExamData {
  const questions: Question[] = raw.questions.map((q) => {
    const t = q.translations?.[0];
    return {
      id: q.id,
      question_category_id: q.question_category_id,
      content: q.content ?? t?.content ?? "",
      options: q.options.map((o) => {
        const ot = o.translations?.[0];
        return {
          id: o.id,
          content: o.content ?? ot?.content ?? "",
        };
      }),
    };
  });

  const question_categories: QuestionCategory[] =
    raw.question_categories.map((c) => {
      const t = c.translations?.[0];
      return {
        id: c.id,
        name: c.name ?? "",
        topic: c.topic ?? t?.topic ?? "",
        title: c.title ?? t?.title ?? "",
      };
    });

  return { questions, question_categories };
}

export const selfCareExamQueryOptions = () =>
  queryOptions({
    queryKey: ["app/self-care-exam"],
    queryFn: (): Promise<GetSelfCareExamResponse> =>
      ApiHelper.get(ApiConfig.APP_SELF_CARE_EXAM),
    select: (response): ApiResponse<SelfCareExamData> => {
      if (!response.data) return response as unknown as ApiResponse<SelfCareExamData>;
      return {
        ...response,
        data: transformExamData(response.data),
      };
    },
  });

export const useSubmitSelfCareExamMutation = () => {
  return useMutation({
    mutationFn: async (
      data: Partial<Record<string, unknown>>
    ): Promise<ApiResponse<ExamResultData>> => {
      return ApiHelper.post(ApiConfig.APP_SELF_CARE_EXAM, data);
    },
  });
};
