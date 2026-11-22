import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    return (
        <AuthLayout
            title="One more step"
            description={recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        >
            <Head title="Two-factor sign-in" />
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/two-factor-challenge', { onFinish: () => form.reset() });
                }}
            >
                <div className="grid gap-2">
                    <Label htmlFor="code">{recovery ? 'Recovery code' : 'Code'}</Label>
                    {recovery ? (
                        <Input
                            id="code"
                            autoFocus
                            autoComplete="off"
                            value={form.data.recovery_code}
                            onChange={(e) => form.setData('recovery_code', e.target.value)}
                        />
                    ) : (
                        <Input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                    )}
                    <InputError message={form.errors.code} />
                </div>
                <Button disabled={form.processing}>Sign in</Button>
                <button type="button" className="text-muted-foreground text-sm underline" onClick={() => setRecovery(!recovery)}>
                    {recovery ? 'Use a code from my app' : 'Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
