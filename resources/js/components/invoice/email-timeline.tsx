import { day, money } from '@/lib/format';
import { when } from '@/lib/invoice';
import { cn } from '@/lib/utils';
import { type InvoiceDetail, type IssuedView } from '@/types/invoices';

interface Step {
    at: string;
    text: string;
    tone?: 'good' | 'warn' | 'bad';
}

/**
 * What happened to an issued invoice, in order: issued, in your Gmail drafts, sent (to whom), a reminder ready or sent,
 * and each payment (linked, or waiting in the Inbox).
 */
export function EmailTimeline({ invoice, issued }: { invoice: InvoiceDetail; issued: IssuedView }) {
    const steps: Step[] = [];
    if (invoice.issue_date) steps.push({ at: invoice.issue_date, text: `Issued as ${invoice.number}` });
    const email = issued.email;
    if (email && email.route === 'gmail') {
        if (email.drafted_at) steps.push({ at: email.drafted_at, text: 'Put in your Gmail drafts' });
        if (email.status === 'pending') steps.push({ at: email.drafted_at ?? invoice.issue_date ?? '', text: 'Putting it in Gmail…', tone: 'warn' });
        if (email.status === 'failed')
            steps.push({
                at: email.drafted_at ?? invoice.issue_date ?? '',
                text: `Not in your Gmail drafts${email.error ? `: ${email.error}` : ''}`,
                tone: 'bad',
            });
        if (email.status === 'deleted')
            steps.push({ at: email.drafted_at ?? invoice.issue_date ?? '', text: 'Its Gmail draft was deleted without being sent', tone: 'bad' });
    }
    if (invoice.sent_at) {
        const byHand = email?.route === 'by_hand';
        steps.push({
            at: invoice.sent_at,
            text: byHand ? 'You sent it yourself' : `Sent${invoice.sent_to ? ` to ${invoice.sent_to}` : ''}`,
            tone: 'good',
        });
    }
    const reminder = issued.reminder;
    if (reminder?.drafted_at && reminder.status !== 'sent') {
        steps.push({
            at: reminder.drafted_at,
            text: reminder.status === 'drafted' ? 'A reminder is ready in your Gmail drafts' : 'A reminder was taken out of your Gmail drafts',
            tone: reminder.status === 'drafted' ? 'warn' : undefined,
        });
    }
    if (reminder?.sent_at) steps.push({ at: reminder.sent_at, text: `Reminder sent${reminder.to ? ` to ${reminder.to}` : ''}`, tone: 'good' });
    else if (invoice.reminded_at)
        steps.push({
            at: invoice.reminded_at,
            text: invoice.reminder_count > 1 ? `${invoice.reminder_count} reminders sent (the last one)` : 'Reminder sent',
            tone: 'good',
        });
    for (const p of issued.payments) {
        steps.push({
            at: p.date ?? '',
            text: `${money(p.amount_pence)} from ${p.payer ?? 'the client'}${p.linked ? '' : ': waiting in the Inbox'}`,
            tone: p.linked ? 'good' : 'warn',
        });
    }
    steps.sort((a, b) => a.at.localeCompare(b.at));

    if (steps.length === 0) return null;
    return (
        <section aria-label="What happened" className="space-y-2">
            <h2 className="text-base font-semibold">What happened</h2>
            <ol className="relative space-y-3 border-l pl-4">
                {steps.map((s, i) => (
                    <li key={i} className="relative">
                        <span
                            className={cn(
                                'border-background absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2',
                                s.tone === 'good'
                                    ? 'bg-emerald-500'
                                    : s.tone === 'warn'
                                      ? 'bg-amber-500'
                                      : s.tone === 'bad'
                                        ? 'bg-rose-500'
                                        : 'bg-muted-foreground',
                            )}
                        />
                        <p className="text-sm break-words">{s.text}</p>
                        <p className="text-muted-foreground text-xs">{s.at.length > 10 ? when(s.at) : day(s.at)}</p>
                    </li>
                ))}
            </ol>
        </section>
    );
}
