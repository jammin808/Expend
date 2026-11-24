import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Briefcase,
    Car,
    CreditCard,
    FileSpreadsheet,
    Inbox,
    LayoutGrid,
    ListChecks,
    ListTodo,
    Network,
    ReceiptText,
    Settings,
    Sparkles,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { nav } = usePage<SharedData>().props;
    const url = usePage().url;

    // A short menu: Enquiries & offers, Documents and Stays & travel live under Jobs, Import under Accounts.
    const main = [
        { title: 'Overview', url: '/dashboard', icon: LayoutGrid, also: [] as string[] },
        { title: 'To do', url: '/todo', icon: ListTodo, badge: nav?.todo, also: [] },
        { title: 'Inbox', url: '/inbox', icon: Inbox, badge: nav?.inbox, also: [] },
        { title: 'Assistant', url: '/assistant', icon: Sparkles, also: [] },
        { title: 'Tax years', url: '/years', icon: ListChecks, also: [] },
        { title: 'Transactions', url: '/transactions', icon: FileSpreadsheet, also: [] },
    ];
    const work = [
        { title: 'Jobs', url: '/jobs', icon: Briefcase, also: ['/documents', '/travel', '/opportunities'] },
        { title: 'Connections', url: '/connections', icon: Network, also: [] },
        { title: 'Invoices', url: '/invoices', icon: ReceiptText, also: [] },
        { title: 'Mileage', url: '/mileage', icon: Car, also: [] },
        { title: 'Accounts', url: '/accounts', icon: CreditCard, also: ['/imports'] },
        { title: 'Settings', url: '/settings/ledger', icon: Settings, also: ['/settings'] },
    ];

    const matches = (target: string) => url === target || url.startsWith(target + '/') || url.startsWith(target + '?');
    const isActive = (item: { url: string; also: string[] }) => matches(item.url) || item.also.some(matches);

    const group = (label: string, items: typeof main) => (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={isActive(item)} tooltip={item.title}>
                            <Link href={item.url} prefetch>
                                <item.icon />
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {'badge' in item && item.badge ? <SidebarMenuBadge>{item.badge > 999 ? '999+' : item.badge}</SidebarMenuBadge> : null}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                {group('Books', main)}
                {group('Work', work)}
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
