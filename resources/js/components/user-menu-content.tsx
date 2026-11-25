import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { clearOnSignOut, store, waitingOnPhone } from '@/lib/quick';
import { type User } from '@/types';
import { Link, router } from '@inertiajs/react';
import { LogOut, Settings } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

/**
 * Log out, after asking about expenses still on this phone: send them now, delete them, or keep them to send after
 * the next sign-in. Either way the day's data kept for the quick add screen (your jobs and accounts) and its cached
 * copy are cleared, so the screen doesn't show them once you're signed out.
 */
async function logOut(): Promise<void> {
    const waiting = await waitingOnPhone().catch(() => 0);
    let deleteWaiting = false;
    if (waiting > 0) {
        const these = waiting === 1 ? '1 expense is' : `${waiting} expenses are`;
        if (
            window.confirm(
                `${these} still on this phone, not yet in Expend. Send now before you log out?\n\nOK sends. Cancel lets you delete or keep them.`,
            )
        ) {
            await store()
                ?.flush({ reset: true, from: 'logout' })
                .catch(() => null);
        } else {
            deleteWaiting = window.confirm(
                `Delete ${waiting === 1 ? 'it' : 'them'} from this phone?\n\nOK deletes. Cancel keeps ${waiting === 1 ? 'it' : 'them'} to send after you next log in.`,
            );
        }
    }
    await clearOnSignOut(deleteWaiting).catch(() => {});
    router.post(route('logout'));
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href={route('profile.edit')} as="button" prefetch onClick={cleanup}>
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onSelect={() => {
                    cleanup();
                    void logOut();
                }}
            >
                <LogOut className="mr-2" />
                Log out
            </DropdownMenuItem>
        </>
    );
}
