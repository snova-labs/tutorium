import { AppNav, type NavItem } from "@/components/app/app-nav";
import { Button } from "@/components/ui/button";
import { getMe } from "@/lib/me";
import { can } from "@/lib/permissions";

export default async function AppLayout({ children }: LayoutProps<"/">) {
  const me = await getMe();

  const items: NavItem[] = [
    { href: "/", label: "Home" },
    ...(can(me, "learners.view") ? [{ href: "/learners", label: "Learners" }] : []),
    { href: "/batches", label: "Batches" },
    ...(can(me, "reports.view_archive") ? [{ href: "/reports", label: "Reports" }] : []),
    ...(can(me, "billing.manage") ? [{ href: "/billing", label: "Billing" }] : []),
  ];

  return (
    <div className="flex min-h-full flex-1 flex-col">
      <header className="border-b bg-card">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
          <span className="grid size-7 shrink-0 place-items-center rounded-md bg-primary text-xs font-bold text-primary-foreground">
            T
          </span>
          <span className="hidden truncate text-sm font-semibold sm:block">{me.tenant.name}</span>
          <AppNav items={items} />
          <div className="ml-auto flex items-center gap-3">
            <span className="hidden text-xs text-muted-foreground md:block">{me.name}</span>
            <form action="/sign-out" method="post">
              <Button type="submit" variant="ghost" size="sm">
                Sign out
              </Button>
            </form>
          </div>
        </div>
      </header>
      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">{children}</main>
    </div>
  );
}
