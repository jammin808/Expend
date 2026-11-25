import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { QuickAddButton } from '@/components/quick-add-button';
import { OldIconNote, QuickPill } from '@/components/quick/quick-pill';
import { Toaster } from '@/components/toaster';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <OldIconNote />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Entries still on this phone: "2 waiting · 1 needs you", to the waiting list. */}
                <div className="flex justify-end px-4 pt-2 empty:hidden md:px-6">
                    <QuickPill />
                </div>
                {children}
                {/* Room under the page so the + button never covers its last row on a phone. */}
                <div className="h-20 shrink-0 md:hidden" aria-hidden="true" />
            </AppContent>
            <QuickAddButton />
            <Toaster />
        </AppShell>
    );
}
