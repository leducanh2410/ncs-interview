import { useNavigate } from "@tanstack/react-router";
import { Button } from "@/components/ui/button";
import ResetPassword from "./reset-password";
import SetFirstPassword from "./set-first-password";
import { Route } from "@/routes/(auth)/setup-password";

const SetupPassword = () => {
  const navigate = useNavigate();
  const { user_id, signature, expires, type } = Route.useSearch();

  const isInvalidLink =
    !user_id || !signature || !expires || Date.now() / 1000 > parseInt(expires);

  if (isInvalidLink) {
    return (
      <div className="flex-1 flex items-center justify-center pb-25 h-screen">
        <div className="bg-[#f4f7fb] rounded-[20px] p-12 text-center">
          <h1 className="text-2xl font-bold text-black mb-4">無効なリンク</h1>
          <p className="text-sm text-gray-600 mb-6">
            {"このリンクは無効または期限切れです。"}
          </p>
          <Button onClick={() => navigate({ to: "/" })} className="w-[200px]">
            ログインページへ
          </Button>
        </div>
      </div>
    );
  }

  const isEmployee = type === "employee";

  return isEmployee ? <SetFirstPassword /> : <ResetPassword />;
};

export default SetupPassword;
