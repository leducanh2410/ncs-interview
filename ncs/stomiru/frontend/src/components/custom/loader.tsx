import { Spinner } from "@/components/ui/spinner";

function FullPageLoader() {
  return (
    <div className="bg-background flex min-h-svh items-center justify-center gap-1 p-6 md:p-10">
      <Spinner aria-hidden="true" className="size-5 text-primary" />
      <span className="text-sm">読み込み中...</span>
    </div>
  );
}

function LoaderCircle({ className }: { className?: string }) {
  return <Spinner className={className} />;
}

export { FullPageLoader, LoaderCircle };
