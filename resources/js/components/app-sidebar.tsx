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
import { Briefcase, Car, CreditCard, FileSpreadsheet, Inbox, LayoutGrid, ListChecks, Settings, Upload } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { nav } = usePage<SharedData>().props;
    const url = usePage().url;

    const main = [
        { title: 'Overview', url: '/dashboard', icon: LayoutGrid },
        { title: 'Inbox', url: '/inbox', icon: Inbox, badge: nav?.inbox },
        { title: 'Tax years', url: '/years', icon: ListChecks },
        { title: 'Transactions', url: '/transactions', icon: FileSpreadsheet },
        { title: 'Import', url: '/imports', icon: Upload },
    ];
    const work = [
        { title: 'Jobs', url: '/jobs', icon: Briefcase },
        { title: 'Mileage', url: '/mileage', icon: Car },
        { title: 'Accounts', url: '/accounts', icon: CreditCard },
        { title: 'Settings', url: '/settings/ledger', icon: Settings },
    ];

    const isActive = (target: string) => url === target || url.startsWith(target + '/') || url.startsWith(target + '?');

    const group = (label: string, items: typeof main) => (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={isActive(item.url)} tooltip={item.title}>
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
