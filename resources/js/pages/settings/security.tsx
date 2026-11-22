import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

interface Props {
    enabled: boolean;
    pending: { qr: string; secret: string } | null;
    recoveryCodes: string[] | null;
    recoveryRemaining: number;
}

export default function Security({ enabled, pending, recoveryCodes, recoveryRemaining }: Props) {
    const confirm = useForm({ code: '' });
    const password = useForm({ password: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Settings', href: '/settings/security' }]}>
            <Head title="Security" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Two-factor sign-in"
                        description="After your password, sign-in asks for a 6-digit code from an authenticator app on your phone."
                    />

                    {recoveryCodes && (
                        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:bg-amber-950/40">
                            <p className="font-medium">Save these recovery codes now</p>
                            <p className="text-muted-foreground text-sm">Each one works once if you lose your phone. They won't be shown again.</p>
                            <ul className="mt-3 grid grid-cols-2 gap-1 font-mono text-sm">
                                {recoveryCodes.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {enabled ? (
                        <div className="space-y-4">
                            <p className="flex items-center gap-2 text-sm font-medium text-emerald-700">
                                <ShieldCheck className="size-4" /> On · {recoveryRemaining} recovery codes left
                            </p>
                            <div className="max-w-sm space-y-2">
                                <Label htmlFor="password">Your password, to change these settings</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={password.data.password}
                                    onChange={(e) => password.setData('password', e.target.value)}
                                />
                                <InputError message={password.errors.password} />
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        password.post('/settings/security/recovery-codes', {
                                            preserveScroll: true,
                                            onSuccess: () => password.reset(),
                                        })
                                    }
                                >
                                    New recovery codes
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() => password.delete('/settings/security', { preserveScroll: true, onSuccess: () => password.reset() })}
                                >
                                    Turn off
                                </Button>
                            </div>
                        </div>
                    ) : pending ? (
                        <div className="space-y-4">
                            <p className="text-sm">
                                Scan this with an authenticator app (for example Google Authenticator, 1Password or Apple Passwords), then enter the
                                code it shows.
                            </p>
                            <div className="w-fit rounded-lg bg-white p-3" dangerouslySetInnerHTML={{ __html: pending.qr }} />
                            <p className="text-muted-foreground text-xs">
                                Can't scan? Enter this key: <span className="font-mono">{pending.secret}</span>
                            </p>
                            <form
                                className="flex max-w-sm items-start gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    confirm.post('/settings/security/confirm', { preserveScroll: true });
                                }}
                            >
                                <div className="flex-1">
                                    <Input
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        placeholder="123456"
                                        value={confirm.data.code}
                                        onChange={(e) => confirm.setData('code', e.target.value)}
                                    />
                                    <InputError message={confirm.errors.code} />
                                </div>
                                <Button disabled={confirm.processing}>Turn on</Button>
                            </form>
                        </div>
                    ) : (
                        <Button onClick={() => router.post('/settings/security/enable', {}, { preserveScroll: true })}>
                            Set up two-factor sign-in
                        </Button>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
