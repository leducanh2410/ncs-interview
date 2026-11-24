import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  stressCheckExamQueryOptions,
  useSubmitStressCheckExamMutation,
} from "../stress-check.api";
import { useSuspenseQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import type { IStressCheckExam } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import CommonHelper from "@/helpers/common.helper";
import BackButton from "@/components/custom/back-button";
import ArrowBackIcon from "@/components/icons/arrow-back-icon";
import ArrowForwardIcon from "@/components/icons/arrow-forward-icon";
import { Button } from "@/components/ui/button";
import { QuestionBlock } from "./question-block";
import { StopAlertModal } from "./stop-alert-modal";
import { useTranslation } from "@/hooks/use-translation";
import { Route } from "@/routes/_authenticated/app/stress-check/$stressCheckId.exam";

const StressCheckExam = () => {
  const { t, tVar } = useTranslation();
  const { stressCheckId = 0 } = Route.useParams();
  const navigate = useNavigate();
  const { data: stressCheckData } = useSuspenseQuery(
    stressCheckExamQueryOptions(Number(stressCheckId))
  );

  const submitExamMutation = useSubmitStressCheckExamMutation(
    Number(stressCheckId)
  );

  const examData = stressCheckData.data as IStressCheckExam;
  const questionCategories = examData["question_set"]["question_categories"];
  const questions = examData["question_set"]["questions"];

  // Initialize answers from current_exam_session.questions_answered
  const initialAnswers = useMemo(() => {
    const saved: Record<number, number> = {};
    const questionsAnswered = examData.current_exam_session?.questions_answered;
    if (questionsAnswered && Array.isArray(questionsAnswered)) {
      for (const item of questionsAnswered) {
        saved[item.question_id] = item.option_id;
      }
    }
    return saved;
  }, [examData.current_exam_session]);

  // Find the category of the first UNANSWERED question to resume from
  const initialCategoryIndex = useMemo(() => {
    if (Object.keys(initialAnswers).length === 0) return 0;

    for (let i = 0; i < questionCategories.length; i++) {
      const category = questionCategories[i];
      const categoryQuestions = questions.filter(
        (q) => q.question_category_id === category.id
      );
      const firstUnanswered = categoryQuestions.find(
        (q) => initialAnswers[q.id] === undefined
      );
      if (firstUnanswered) {
        return i;
      }
    }

    // All answered — stay on last category
    return questionCategories.length - 1;
  }, [initialAnswers, questions, questionCategories]);

  const [answers, setAnswers] =
    useState<Record<number, number>>(initialAnswers);
  const [currentCategoryIndex, setCurrentCategoryIndex] =
    useState(initialCategoryIndex);
  const [showStopAlert, setShowStopAlert] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  const firstUnansweredRef = useRef<HTMLDivElement>(null);

  const currentCategory =
    examData["question_set"]["question_categories"][currentCategoryIndex] ||
    null;
  const currentQuestions = useMemo(() => {
    if (!currentCategory) return [];
    return questions.filter(
      (question) => question.question_category_id === currentCategory.id
    );
  }, [currentCategory, questions]);

  // Auto-scroll to first unanswered question in the current category
  useEffect(() => {
    if (firstUnansweredRef.current) {
      setTimeout(() => {
        firstUnansweredRef.current?.scrollIntoView({
          behavior: "smooth",
          block: "center",
        });
      }, 100);
    }
  }, [currentCategoryIndex]);

  // Get previous/next category info
  const prevCategory =
    currentCategoryIndex > 0
      ? questionCategories[currentCategoryIndex - 1]
      : null;
  const nextCategory =
    currentCategoryIndex < questionCategories.length - 1
      ? questionCategories[currentCategoryIndex + 1]
      : null;
  const isLastCategory = currentCategoryIndex === questionCategories.length - 1;
  const isFirstCategory = currentCategoryIndex === 0;

  const buildAnswersPayload = useCallback(
    (answersData: Record<number, number>) =>
      Object.entries(answersData).map(([questionId, optionId]) => ({
        question_id: Number(questionId),
        option_id: optionId,
      })),
    []
  );

  // Save draft (is_submitted: false)
  const saveDraft = useCallback(async () => {
    if (Object.keys(answers).length === 0) return;
    await submitExamMutation.mutateAsync({
      answers: buildAnswersPayload(answers),
      is_submitted: false,
    });
  }, [answers, submitExamMutation, buildAnswersPayload]);

  // Save draft and navigate back to home
  const saveAndExit = useCallback(async () => {
    try {
      setIsSaving(true);
      await saveDraft();
    } catch (err) {
      console.error("Failed to save answers:", err);
    } finally {
      setIsSaving(false);
      setShowStopAlert(false);
      void navigate({ to: RouteConfig.APP_STRESS_CHECK.path });
    }
  }, [saveDraft, navigate]);

  // Final submit
  const submitAnswers = async () => {
    const allAnswered = answeredCount >= totalQuestions && totalQuestions > 0;

    await submitExamMutation.mutateAsync({
      answers: buildAnswersPayload(answers),
      is_submitted: allAnswered,
    });

    if (allAnswered) {
      void navigate({
        to: CommonHelper.replaceUrlParam(
          RouteConfig.APP_STRESS_CHECK_RESULT.path,
          { stressCheckId }
        ),
      });
    }
  };

  const handleSelectOption = useCallback(
    (questionId: number, optionId: number) => {
      setAnswers((prev) => ({
        ...prev,
        [questionId]: optionId,
      }));
    },
    []
  );

  const answeredCount = Object.keys(answers).length;
  const totalQuestions = questions?.length || 0;

  // Back button: show dialog if user has answered, otherwise go home
  const handleBack = () => {
    if (answeredCount > 0) {
      setShowStopAlert(true);
    } else {
      void navigate({ to: RouteConfig.APP_STRESS_CHECK.path });
    }
  };

  // Navigate to previous category
  const goToPrevCategory = () => {
    if (isFirstCategory) {
      handleBack();
    } else {
      setCurrentCategoryIndex((prev) => prev - 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  // Navigate to next category
  const goToNextCategory = () => {
    if (isLastCategory) {
      submitAnswers();
    } else {
      setCurrentCategoryIndex((prev) => prev + 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  // Get prev button text
  const getPrevButtonText = () => {
    if (isFirstCategory) {
      return t("stressCheckPage-staff.returnHome");
    }
    return prevCategory ? prevCategory.topic : "";
  };

  const getNextButtonText = () => {
    if (isLastCategory) {
      return t("stressCheckPage-staff.seeResult");
    }
    return nextCategory ? nextCategory.topic : "";
  };

  return (
    <>
      <div className="mt-4 max-w-[992px] px-4 w-full mx-auto flex flex-col gap-4 pb-25">
        <div className="py-10 pb-2">
          <BackButton
            onClick={handleBack}
            label={t("stressCheckPage-staff.returnHome")}
          />
        </div>
        <div className="px-6 flex flex-col gap-6">
          <div className="pt-10 pb-8 flex flex-col gap-4">
            <div className="font-bold text-[16px] leading-[19px] text-primary">
              {currentCategory.topic}
            </div>
            <div className="font-bold text-[24px] leading-[34px] whitespace-pre-wrap">
              {currentCategory.title}
            </div>
          </div>
          <div className="px-4 flex flex-col gap-6">
            {currentQuestions.map((question, index) => {
              const isUnanswered = !answers[question.id];
              const isFirstUnanswered =
                isUnanswered &&
                !currentQuestions.slice(0, index).some((q) => !answers[q.id]);

              return (
                <QuestionBlock
                  key={`q_${question.id}`}
                  ref={isFirstUnanswered ? firstUnansweredRef : null}
                  question={question}
                  no={index + 1}
                  selectedOptionId={answers[question.id] || null}
                  onSelect={(optionId) =>
                    handleSelectOption(question.id, optionId)
                  }
                />
              );
            })}
          </div>
        </div>
      </div>

      <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-[24px] shadow-[0px_-1px_8px_0px_rgba(0,0,0,0.25)] px-10 py-6 flex items-center justify-between z-50">
        <Button
          type="button"
          variant="outline"
          className="border-2 border-primary"
          onClick={goToPrevCategory}
        >
          <ArrowBackIcon data-icon="inline-start" svgClassName="size-[18px]" />
          <span>{getPrevButtonText()}</span>
        </Button>

        <div className="flex items-center gap-[2px] font-bold text-[18px] leading-[22px] absolute left-1/2 -translate-x-1/2">
          <span className="text-primary flex items-center">
            <span>
              {tVar(
                "stressCheckPage-staff.answeredQuestions",
                { name: `${answeredCount}` },
                {
                  name: (value) => (
                    <span className="text-[24px] leading-[29px]">{value}</span>
                  ),
                }
              )}
            </span>
          </span>
          <span className="text-[24px] leading-[29px] w-5 text-center">/</span>
          <span className="text-black flex items-center">
            <span>
              {tVar(
                "stressCheckPage-staff.totalQuestions",
                { name: `${totalQuestions}` },
                {
                  name: (value) => (
                    <span className="text-[24px] leading-[29px]">{value}</span>
                  ),
                }
              )}
            </span>
          </span>
        </div>

        <Button type="button" className="" onClick={goToNextCategory}>
          <span>{getNextButtonText()}</span>
          <ArrowForwardIcon data-icon="inline-end" svgClassName="size-[18px]" />
        </Button>
      </div>

      <StopAlertModal
        open={showStopAlert}
        setOpen={setShowStopAlert}
        onSaveAndExit={saveAndExit}
        isSaving={isSaving}
      />
    </>
  );
};

export default StressCheckExam;
