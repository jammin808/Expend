import HeadingSmall from '@/components/heading-small';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm } from '@inertiajs/react';

interface Props {
    ai: {
        key_set: boolean;
        key_from_env: boolean;
        model: string;
        models: Record<string, string>;
        effort: string;
        cap_usd: number;
        usage: { usd: number; calls: number };
        suggestions: boolean;
        extraction: string;
    };
    business: { name: string | null; address: string | null; email: string | null; bank: string | null; prefix: string; terms: number };
    email: { host: string | null; port: number; username: string | null; password_set: boolean; allowed: string };
    telegram: { token_set: boolean; paired: boolean; pairing_code: string | null; webhook: string };
}

export default function Integrations({ ai, business, email, telegram }: Props) {
    const aiForm = useForm({
        api_key: '',
        model: ai.model,
        effort: ai.effort,
        cap_usd: String(ai.cap_usd ?? 40),
        suggestions: ai.suggestions,
        extraction: ai.extraction ?? 'on_demand',
    });
    const biz = useForm({
        name: business.name ?? '',
        address: business.address ?? '',
        email: business.email ?? '',
        bank: business.bank ?? '',
        prefix: business.prefix ?? 'INV-',
        terms: String(business.terms ?? 30),
    });
    const mail = useForm({
        host: email.host ?? '',
        port: String(email.port ?? 993),
        username: email.username ?? '',
        password: '',
        allowed: email.allowed ?? '',
        test: false as boolean,
    });
    const tg = useForm({ token: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Settings', href: '/settings/integrations' }]}>
            <Head title="Integrations" />
            <SettingsLayout>
                <div className="space-y-10">
                    <form
                        className="space-y-4"
                        onSubmit={(e) => (
                            e.preventDefault(),
                            aiForm.put('/settings/integrations/ai', { preserveScroll: true, onSuccess: () => aiForm.setData('api_key', '') })
                        )}
                    >
                        <HeadingSmall
                            title="AI (Claude)"
                            description="Reads documents, answers questions and suggests decisions. It never confirms anything; every figure is checked against your records."
                        />
                        <div className="space-y-1.5">
                            <Label>Claude API key</Label>
                            <Input
                                type="password"
                                autoComplete="off"
                                placeholder={
                                    ai.key_from_env
                                        ? 'Set on the server (ANTHROPIC_API_KEY)'
                                        : ai.key_set
                                          ? 'Saved (encrypted). Paste a new key to replace it.'
                                          : 'sk-ant-…'
                                }
                                value={aiForm.data.api_key}
                                onChange={(e) => aiForm.setData('api_key', e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">
                                From console.anthropic.com › API keys. Stored encrypted; never shown again.
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label>Model</Label>
                                <NativeSelect value={aiForm.data.model} onChange={(e) => aiForm.setData('model', e.target.value)}>
                                    {Object.entries(ai.models).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Effort</Label>
                                <NativeSelect value={aiForm.data.effort} onChange={(e) => aiForm.setData('effort', e.target.value)}>
                                    <option value="low">Low (fastest, cheapest)</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High (most careful)</option>
                                </NativeSelect>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Monthly spending cap (US$)</Label>
                                <Input inputMode="decimal" value={aiForm.data.cap_usd} onChange={(e) => aiForm.setData('cap_usd', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Reading documents</Label>
                                <NativeSelect value={aiForm.data.extraction} onChange={(e) => aiForm.setData('extraction', e.target.value)}>
                                    <option value="off">Off</option>
                                    <option value="on_demand">When I press “Read with AI”</option>
                                    <option value="on">Automatically when they arrive</option>
                                </NativeSelect>
                            </div>
                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                <Checkbox checked={aiForm.data.suggestions} onCheckedChange={(v) => aiForm.setData('suggestions', !!v)} /> Allow AI
                                suggestions for transactions
                            </label>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            This month: {ai.usage.calls} requests, about ${Number(ai.usage.usd).toFixed(2)} (estimate from published prices).
                        </p>
                        <Button disabled={aiForm.processing}>Save AI settings</Button>
                    </form>

                    <form
                        className="space-y-4"
                        onSubmit={(e) => (e.preventDefault(), biz.put('/settings/integrations/business', { preserveScroll: true }))}
                    >
                        <HeadingSmall title="Your business (for invoices)" description="Printed on every invoice." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input
                                placeholder="Business or trading name"
                                value={biz.data.name}
                                onChange={(e) => biz.setData('name', e.target.value)}
                            />
                            <Input placeholder="Email" value={biz.data.email} onChange={(e) => biz.setData('email', e.target.value)} />
                            <Textarea
                                className="sm:col-span-2"
                                placeholder="Address"
                                value={biz.data.address}
                                onChange={(e) => biz.setData('address', e.target.value)}
                            />
                            <Textarea
                                className="sm:col-span-2"
                                placeholder="Payment details (account name, sort code, account number). Stored encrypted."
                                value={biz.data.bank}
                                onChange={(e) => biz.setData('bank', e.target.value)}
                            />
                            <div className="space-y-1.5">
                                <Label>Invoice number prefix</Label>
                                <Input value={biz.data.prefix} onChange={(e) => biz.setData('prefix', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Default payment terms (days)</Label>
                                <Input inputMode="numeric" value={biz.data.terms} onChange={(e) => biz.setData('terms', e.target.value)} />
                            </div>
                        </div>
                        <Button disabled={biz.processing}>Save business details</Button>
                    </form>

                    <form
                        className="space-y-4"
                        onSubmit={(e) => (
                            e.preventDefault(),
                            mail.put('/settings/integrations/email', { preserveScroll: true, onSuccess: () => mail.setData('password', '') })
                        )}
                    >
                        <HeadingSmall
                            title="Forward by email"
                            description="Use a mailbox just for this, not your everyday one: every new message in it is read and filed. Forward POs, invites, production packs and bookings to it. A Gmail filter can forward them automatically (Gmail first emails a confirmation code to the mailbox; open its webmail to see it)."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input
                                placeholder="IMAP server, e.g. imap.hostinger.com"
                                value={mail.data.host}
                                onChange={(e) => mail.setData('host', e.target.value)}
                            />
                            <Input placeholder="Port" value={mail.data.port} onChange={(e) => mail.setData('port', e.target.value)} />
                            <Input
                                placeholder="Mailbox address"
                                value={mail.data.username}
                                onChange={(e) => mail.setData('username', e.target.value)}
                            />
                            <Input
                                type="password"
                                autoComplete="off"
                                placeholder={email.password_set ? 'Password saved (encrypted)' : 'Mailbox password'}
                                value={mail.data.password}
                                onChange={(e) => mail.setData('password', e.target.value)}
                            />
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label>Your sending addresses (comma separated)</Label>
                                <Input
                                    placeholder="you@gmail.com, you@your-domain"
                                    value={mail.data.allowed}
                                    onChange={(e) => mail.setData('allowed', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Mail you forward from these, including Gmail’s automatic forwarding, is taken in. Anything else, or anything that
                                    fails the sender checks, waits for your approval in Documents.
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button disabled={mail.processing}>Save</Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.put('/settings/integrations/email', { ...mail.data, test: true }, { preserveScroll: true })}
                            >
                                Save and check now
                            </Button>
                        </div>
                    </form>

                    <div className="space-y-4">
                        <HeadingSmall title="Telegram" description="Send files and photos to your own bot, or ask it questions on the go." />
                        {telegram.token_set ? (
                            <div className="space-y-3 text-sm">
                                {telegram.paired ? (
                                    <p className="font-medium text-emerald-700">Connected and paired with your Telegram account.</p>
                                ) : (
                                    <p>
                                        Bot connected. In Telegram, open your bot and send:{' '}
                                        <span className="bg-muted rounded px-2 py-1 font-mono">/start {telegram.pairing_code}</span>
                                    </p>
                                )}
                                <Button variant="ghost" onClick={() => router.delete('/settings/integrations/telegram', { preserveScroll: true })}>
                                    Disconnect
                                </Button>
                            </div>
                        ) : (
                            <form
                                className="space-y-3"
                                onSubmit={(e) => (
                                    e.preventDefault(),
                                    tg.post('/settings/integrations/telegram', { preserveScroll: true, onSuccess: () => tg.reset() })
                                )}
                            >
                                <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                                    <li>In Telegram, message @BotFather, send /newbot and follow the steps.</li>
                                    <li>Paste the token it gives you here. The site must be live on HTTPS.</li>
                                    <li>Send your bot the pairing code shown next. Only that chat will be accepted.</li>
                                </ol>
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    placeholder="Bot token, e.g. 123456:ABC…"
                                    value={tg.data.token}
                                    onChange={(e) => tg.setData('token', e.target.value)}
                                />
                                <Button disabled={tg.processing || !tg.data.token}>Connect bot</Button>
                            </form>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
