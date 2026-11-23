import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    token: string;
    email: string;
}

type ResetPasswordForm = {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
};

/**
 * One new-password box with show/hide, and no automatic focus: phones' password managers otherwise
 * pop up over the box on every tap. The confirmation the server checks is filled in from the same box;
 * showing the password is how you check it.
 */
export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, transform, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, password_confirmation: d.password }));
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Reset password" description={`Choose a new password for ${email}`}>
            <Head title="Reset password" />

            <form onSubmit={submit}>
                {/* Tells password managers which account this is for, without a box to tap. */}
                <input type="email" name="email" autoComplete="username" value={data.email} readOnly hidden />
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="password">New password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="At least 8 characters"
                        />
                        <p className="text-muted-foreground text-xs">
                            If your phone's password pop-up gets in the way, tap the eye first, then type.
                        </p>
                        <InputError message={errors.password} />
                        <InputError message={errors.email} />
                    </div>

                    <Button type="submit" className="mt-2 w-full" disabled={processing || data.password.length === 0}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Save new password
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
