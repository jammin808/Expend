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
        usage: { usd: number; calls: number; web_searches?: number };
        suggestions: boolean;
        extraction: string;
        web_research: boolean;
    };
    business: { name: string | null; address: string | null; email: string | null; bank: string | null; prefix: string; terms: number };
    email: { host: string | null; port: number; username: string | null; password_set: boolean; allowed: string };
    telegram: { token_set: boolean; paired: boolean; pairing_code: string | null; webhook: string };
    starling: {
        connected: boolean;
        accounts: { uid: string; name: string; account_id: number; synced_to: string | null }[];
        last: { at: string; results: { account: string; result: string; ok: boolean }[] } | null;
        app_accounts: { id: number; name: string }[];
    };
    mail: {
        method: string;
        chosen: string | null;
        smtp_host: string;
        smtp_port: number;
        smtp_username: string | null;
        smtp_password_set: boolean;
        from_address: string | null;
        default_from: string;
        to: string;
    };
    gmail: {
        configured: boolean;
        connected: boolean;
        account: string | null;
        client_id: string | null;
        redirect_uri: string;
        last_result: { ok: boolean; message: string; at: string } | null;
        searches: { key: string; label: string; query: string; default: string }[];
        can_label: boolean;
        label: string;
        label_on: boolean;
        label_status: { at: string; labelled_jobs: number; missing: string[] } | null;
        labelled: number;
    };
}

export default function Integrations({ ai, business, email, telegram, gmail, mail: out, starling }: Props) {
    const starlingForm = useForm({ token: '' });
    const aiForm = useForm({
        api_key: '',
        model: ai.model,
        effort: ai.effort,
        cap_usd: String(ai.cap_usd ?? 40),
        suggestions: ai.suggestions,
        extraction: ai.extraction ?? 'on_demand',
        web_research: ai.web_research,
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
    const send = useForm({
        method: out.method === 'log' ? 'server' : out.method,
        smtp_host: out.smtp_host ?? 'smtp.hostinger.com',
        smtp_port: String(out.smtp_port ?? 465),
        smtp_username: out.smtp_username ?? '',
        smtp_password: '',
        from_address: out.from_address ?? '',
    });
    const gm = useForm({
        client_id: gmail.client_id ?? '',
        client_secret: '',
        queries: Object.fromEntries(gmail.searches.map((q) => [q.key, q.query])) as Record<string, string>,
        label_invoiced: gmail.label_on,
    });

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
                            <label className="flex items-start gap-2 text-sm sm:col-span-2">
                                <Checkbox
                                    className="mt-0.5"
                                    checked={aiForm.data.web_research}
                                    onCheckedChange={(v) => aiForm.setData('web_research', !!v)}
                                />
                                <span>
                                    Let the assistant check the law online
                                    <span className="text-muted-foreground block text-xs">
                                        Only legislation.gov.uk, GOV.UK and HMRC manuals, and published court and tribunal judgments. The app tells
                                        the assistant to search only for the law, never your details. About 1¢ per search.
                                    </span>
                                </span>
                            </label>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            This month: {ai.usage.calls} requests{ai.usage.web_searches ? `, ${ai.usage.web_searches} law searches` : ''}, about $
                            {Number(ai.usage.usd).toFixed(2)} (estimate from published prices).
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
                        id="sending"
                        className="space-y-4"
                        onSubmit={(e) => (
                            e.preventDefault(),
                            send.put('/settings/integrations/mail', { preserveScroll: true, onSuccess: () => send.setData('smtp_password', '') })
                        )}
                    >
                        <HeadingSmall
                            title="Sending email"
                            description="Used for password reset links and test emails. The app doesn't send anything else."
                        />
                        <div className="space-y-2 text-sm">
                            {[
                                ['server', 'From this web server', 'No setup. Some providers put these in spam.'],
                                ['smtp', 'From your mailbox (recommended)', 'Sent and signed by your email provider, so it arrives reliably.'],
                                ['off', 'Off', 'Nothing is sent, so password reset won’t work.'],
                            ].map(([value, label, hint]) => (
                                <label key={value} className="flex items-start gap-2">
                                    <input
                                        type="radio"
                                        name="mail-method"
                                        className="mt-1"
                                        checked={send.data.method === value}
                                        onChange={() => send.setData('method', value)}
                                    />
                                    <span>
                                        {label} <span className="text-muted-foreground">· {hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {send.data.method === 'smtp' && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    placeholder="Server, e.g. smtp.hostinger.com"
                                    value={send.data.smtp_host}
                                    onChange={(e) => send.setData('smtp_host', e.target.value)}
                                />
                                <Input
                                    placeholder="Port"
                                    inputMode="numeric"
                                    value={send.data.smtp_port}
                                    onChange={(e) => send.setData('smtp_port', e.target.value)}
                                />
                                <Input
                                    placeholder="Mailbox address"
                                    autoComplete="off"
                                    value={send.data.smtp_username}
                                    onChange={(e) => send.setData('smtp_username', e.target.value)}
                                />
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    placeholder={out.smtp_password_set ? 'Password saved (encrypted)' : 'Mailbox password'}
                                    value={send.data.smtp_password}
                                    onChange={(e) => send.setData('smtp_password', e.target.value)}
                                />
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>From address</Label>
                            <Input
                                placeholder={out.default_from}
                                value={send.data.from_address}
                                onChange={(e) => send.setData('from_address', e.target.value)}
                            />
                        </div>
                        {Object.values(send.errors).map((e) => (
                            <p key={e} className="text-sm text-rose-600">
                                {e}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button disabled={send.processing}>Save</Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post('/settings/integrations/mail/test', {}, { preserveScroll: true })}
                            >
                                Send a test email to {out.to}
                            </Button>
                        </div>
                    </form>

                    <section id="starling" className="space-y-4">
                        <HeadingSmall
                            title="Starling: import statements automatically"
                            description="Every morning the app downloads Starling’s statement for the days since the last import and imports it like a file you upload: the running balance is checked (anything that doesn’t add up is held for you) and overlapping days never duplicate. It uses your own read-only token: it can’t move money or change anything."
                        />
                        {starling.connected ? (
                            <div className="space-y-3 text-sm">
                                <ul className="divide-y rounded-lg border">
                                    {starling.accounts.map((a) => (
                                        <li key={a.uid} className="flex flex-wrap items-center justify-between gap-2 p-2">
                                            <span>
                                                Starling “{a.name}”{a.synced_to ? `, imported up to ${a.synced_to}` : ', not imported yet'}
                                            </span>
                                            <NativeSelect
                                                className="h-8 w-56"
                                                value={a.account_id}
                                                aria-label={`Account for Starling ${a.name}`}
                                                onChange={(e) =>
                                                    router.patch(
                                                        '/settings/starling',
                                                        { uid: a.uid, account_id: Number(e.target.value) },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {starling.app_accounts.map((acc) => (
                                                    <option key={acc.id} value={acc.id}>
                                                        into {acc.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </li>
                                    ))}
                                </ul>
                                {starling.last && (
                                    <div className="text-muted-foreground text-xs">
                                        Last import {new Date(starling.last.at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}:
                                        {starling.last.results.map((r) => (
                                            <p key={r.account} className={r.ok ? '' : 'text-rose-600'}>
                                                {r.account}: {r.result}
                                            </p>
                                        ))}
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button onClick={() => router.post('/settings/starling/sync', {}, { preserveScroll: true })}>Import now</Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            const from = window.prompt('Import from which date? (YYYY-MM-DD, for catching up on earlier months)', '');
                                            if (from) router.post('/settings/starling/sync', { from }, { preserveScroll: true });
                                        }}
                                    >
                                        Import from a date…
                                    </Button>
                                    <Button variant="ghost" onClick={() => router.delete('/settings/starling', { preserveScroll: true })}>
                                        Disconnect
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <form
                                className="space-y-2 text-sm"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    starlingForm.post('/settings/starling', { preserveScroll: true, onSuccess: () => starlingForm.reset() });
                                }}
                            >
                                <ol className="text-muted-foreground list-decimal space-y-1 pl-5">
                                    <li>
                                        Sign in at developer.starlingbank.com with your Starling details, open <strong>Personal Access</strong> and
                                        create a token for your business account.
                                    </li>
                                    <li>
                                        Tick only the read permissions for accounts, account lists and CSV statements (nothing that pays or changes).
                                    </li>
                                    <li>Paste the token here. It’s stored encrypted.</li>
                                </ol>
                                <div className="flex gap-2">
                                    <Input
                                        type="password"
                                        autoComplete="off"
                                        value={starlingForm.data.token}
                                        onChange={(e) => starlingForm.setData('token', e.target.value)}
                                        placeholder="Starling personal access token"
                                        aria-label="Starling personal access token"
                                    />
                                    <Button disabled={starlingForm.processing || starlingForm.data.token.length < 20}>Connect</Button>
                                </div>
                            </form>
                        )}
                    </section>

                    <section id="gmail" className="space-y-4">
                        <HeadingSmall
                            title="Gmail"
                            description="Lets the app search your Gmail for hotel bookings, travel receipts and POs, using narrow searches you can edit, and put a green “✓ Invoiced” label on a PO email once you’ve invoiced the job. It only ever reads, and adds or removes that one label: it never sends, moves or deletes anything. Every email it fetches is listed under Stays & travel."
                        />
                        {gmail.last_result && (
                            <p className={gmail.last_result.ok ? 'text-sm text-emerald-700' : 'text-sm text-rose-600'}>{gmail.last_result.message}</p>
                        )}
                        {gmail.connected ? (
                            <div className="space-y-2 text-sm">
                                <div className="flex flex-wrap items-center gap-3">
                                    <p className="font-medium text-emerald-700">Connected as {gmail.account}.</p>
                                    <Button variant="ghost" onClick={() => router.delete('/settings/gmail', { preserveScroll: true })}>
                                        Disconnect
                                    </Button>
                                </div>
                                {gmail.can_label ? (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className="inline-flex items-center rounded bg-[#16a766] px-1.5 py-0.5 text-xs font-medium text-white">
                                            {gmail.label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {gmail.labelled} email{gmail.labelled === 1 ? '' : 's'} labelled
                                            {gmail.label_status ? `, last checked ${gmail.label_status.at.slice(0, 16).replace('T', ' ')}` : ''}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => router.post('/settings/gmail/labels', {}, { preserveScroll: true })}
                                            disabled={!gmail.label_on}
                                        >
                                            Update labels now
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-amber-700 dark:text-amber-400">
                                        This connection can only read. To get the {gmail.label} label in Gmail, press Connect Gmail again and allow
                                        the app to change your email (it only adds or removes that label).
                                    </p>
                                )}
                                {!!gmail.label_status?.missing.length && (
                                    <p className="text-muted-foreground text-xs">
                                        Invoiced, but the PO email wasn’t found in Gmail: {gmail.label_status.missing.join(', ')}. Link the PO email
                                        to the job under Documents, or add the PO number on the job.
                                    </p>
                                )}
                            </div>
                        ) : (
                            <ol className="text-muted-foreground list-decimal space-y-1.5 pl-5 text-sm">
                                <li>
                                    Go to console.cloud.google.com and create a project (for example "Expense Ledger"). Under APIs & Services ›
                                    Library, enable the <strong>Gmail API</strong>.
                                </li>
                                <li>
                                    Under Google Auth Platform, set up the consent screen: External, your own email as the contact. Under{' '}
                                    <strong>Audience</strong>, leave it in <strong>Testing</strong> and add the Gmail address you'll connect as a{' '}
                                    <strong>test user</strong>. Don't publish it: Google blocks Gmail access for published apps that haven't been
                                    through its paid security review. In Testing, Google ends the access after 7 days; the app tells you on Telegram,
                                    and reconnecting is one click here.
                                </li>
                                <li>
                                    Under Clients, create an OAuth client of type <strong>Web application</strong> with this authorised redirect URI:
                                    <code className="bg-muted mt-1 block rounded px-2 py-1 font-mono text-xs break-all">{gmail.redirect_uri}</code>
                                </li>
                                <li>Paste the client ID and secret below, save, then press Connect Gmail.</li>
                                <li>
                                    Sign in with that same Gmail address. Google says the app isn't verified (it's your own private app): choose
                                    Continue. Allow it to view and change your email: the app uses that only to read, and to add or remove its{' '}
                                    {gmail.label} label. “Access blocked” means the address you signed in with isn't on the test-user list.
                                </li>
                            </ol>
                        )}
                        <form
                            className="space-y-3"
                            onSubmit={(e) => (
                                e.preventDefault(),
                                gm.put('/settings/integrations/gmail', { preserveScroll: true, onSuccess: () => gm.setData('client_secret', '') })
                            )}
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    placeholder="Client ID (…apps.googleusercontent.com)"
                                    value={gm.data.client_id}
                                    onChange={(e) => gm.setData('client_id', e.target.value)}
                                />
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    placeholder={gmail.configured ? 'Client secret saved (encrypted)' : 'Client secret'}
                                    value={gm.data.client_secret}
                                    onChange={(e) => gm.setData('client_secret', e.target.value)}
                                />
                            </div>
                            {Object.values(gm.errors).map((e) => (
                                <p key={e} className="text-sm text-rose-600">
                                    {e}
                                </p>
                            ))}
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={gm.data.label_invoiced} onCheckedChange={(v) => gm.setData('label_invoiced', !!v)} />
                                Put a green {gmail.label} label on the PO email in Gmail once the job is invoiced (taken off if the invoice is voided)
                            </label>
                            <details className="text-sm">
                                <summary className="text-muted-foreground cursor-pointer">Edit the Gmail searches</summary>
                                <div className="mt-2 space-y-2">
                                    {gmail.searches.map((q) => (
                                        <div key={q.key} className="space-y-1">
                                            <Label>{q.label}</Label>
                                            <Textarea
                                                rows={3}
                                                className="font-mono text-xs"
                                                value={gm.data.queries[q.key]}
                                                onChange={(e) => gm.setData('queries', { ...gm.data.queries, [q.key]: e.target.value })}
                                            />
                                        </div>
                                    ))}
                                    <p className="text-muted-foreground text-xs">
                                        Gmail search syntax. The tax year's dates are added automatically. Clear a box to go back to the default.
                                    </p>
                                </div>
                            </details>
                            <div className="flex gap-2">
                                <Button disabled={gm.processing}>Save</Button>
                                {gmail.configured && (!gmail.connected || !gmail.can_label) && (
                                    <Button type="button" variant="outline" onClick={() => router.post('/settings/gmail/connect')}>
                                        Connect Gmail
                                    </Button>
                                )}
                            </div>
                        </form>
                    </section>

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
                        <details className="rounded-lg border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">Forward from Gmail automatically (no weekly reconnecting)</summary>
                            <div className="text-muted-foreground mt-2 space-y-2">
                                <p>
                                    Google ends the app’s Gmail connection every 7 days while your Google app is in Testing. Gmail filters that
                                    forward job paperwork to this mailbox keep things flowing without that: set them up once.
                                </p>
                                <ol className="list-decimal space-y-1 pl-5">
                                    <li>Save the mailbox above first, so the app is reading it.</li>
                                    <li>
                                        In Gmail on a computer: Settings (cog) › See all settings › <strong>Forwarding and POP/IMAP</strong> › Add a
                                        forwarding address › enter this mailbox’s address.
                                    </li>
                                    <li>
                                        Gmail emails a confirmation code to the mailbox. It arrives in <strong>Documents</strong>, held for approval
                                        (it’s from Google, not you): open it to see the code, enter the code in Gmail, then delete it. Don’t turn on
                                        forwarding of all mail.
                                    </li>
                                    <li>
                                        Settings › <strong>Filters and Blocked Addresses</strong> › Create a new filter. Paste one of the searches
                                        below into “Has the words”, press Create filter, tick “Forward it to” this mailbox, and Create filter. Repeat
                                        for each.
                                    </li>
                                </ol>
                                {gmail.searches.map((s) => (
                                    <div key={s.key} className="space-y-1">
                                        <p className="text-foreground font-medium">{s.label}</p>
                                        <div className="flex items-start gap-2">
                                            <code className="bg-muted block max-h-24 flex-1 overflow-auto rounded p-2 text-xs break-all">
                                                {s.query}
                                            </code>
                                            <Button type="button" size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(s.query)}>
                                                Copy
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                                <p>
                                    Add your Gmail address to “Your sending addresses” above, so forwarded mail is taken in straight away. Forwarding
                                    only applies to new mail; for older mail, use the Gmail searches in Stays & travel.
                                </p>
                            </div>
                        </details>
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
