import ProfileDetail from '@/features/app/profile/profile-detail'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_authenticated/app/profile/')({
  component: RouteComponent,
})

function RouteComponent() {
  return <ProfileDetail />
}
