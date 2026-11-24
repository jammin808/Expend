import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';

interface Props {
    business: string | null;
    email: string | null;
    updated: string;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            <div className="text-muted-foreground space-y-3 text-sm leading-6">{children}</div>
        </section>
    );
}

export default function Privacy({ business, email, updated }: Props) {
    const owner = business?.trim() || 'its owner';

    return (
        <div className="bg-background text-foreground min-h-svh">
            <Head title="Privacy" />
            <main className="mx-auto max-w-2xl space-y-8 px-4 py-10 sm:px-6">
                <header className="space-y-4">
                    <Link href={route('home')} className="inline-flex items-center gap-2 font-medium">
                        <AppLogoIcon className="size-8 fill-current text-[var(--foreground)] dark:text-white" />
                        <span>Expend</span>
                    </Link>
                    <h1 className="text-2xl font-semibold">Privacy</h1>
                    <p className="text-muted-foreground text-sm">Last updated {updated}</p>
                    <p className="text-sm leading-6">
                        Expend is a private bookkeeping and tax app, run by {owner} for their own business. It has one user: the owner. This page
                        explains what Expend does with information from the owner&rsquo;s Google account, and with the other records it keeps.
                    </p>
                </header>

                <Section title="What Expend is for">
                    <p>
                        Expend keeps the owner&rsquo;s business records: bank lines, expenses, jobs, invoices, mileage and tax returns. It finds the
                        paperwork behind them, links it to the right job or expense, and reminds the owner of things that need doing.
                    </p>
                </Section>

                <Section title="Google information Expend uses">
                    <p>Only once the owner connects their Google account, and only for the features below.</p>
                    <ul className="list-disc space-y-2 pl-5">
                        <li>
                            <strong className="text-foreground">Gmail.</strong> Expend looks for work emails (purchase orders, bookings, call sheets),
                            receipts, and travel and hotel confirmations, and links them to jobs and expenses. For the emails it keeps, it stores the
                            sender, subject, date and message reference, and a copy of the attachments it needs. Emails that don&rsquo;t look like
                            work are skipped and not stored. Expend adds its own labels (such as &ldquo;✓ Invoiced&rdquo;) and can prepare drafts,
                            such as an invoice in the booking conversation. It never sends an email unless the owner presses Send.
                        </li>
                        <li>
                            <strong className="text-foreground">Google Calendar.</strong> Expend reads the owner&rsquo;s calendar entries to find work
                            days and match them to jobs, and keeps its own &ldquo;Expend&rdquo; calendar of invoice due dates and tax deadlines. It
                            changes the owner&rsquo;s own entries only when the owner presses a button to do so.
                        </li>
                        <li>
                            <strong className="text-foreground">Google Drive.</strong> Expend opens files attached to calendar entries (such as call
                            sheets) and can store its own encrypted backup copies. It doesn&rsquo;t browse other files.
                        </li>
                    </ul>
                </Section>

                <Section title="How it is used and shared">
                    <p>
                        Google information is used only to provide Expend&rsquo;s features to the owner. It is never sold, used for advertising, or
                        shared with anyone else, except as follows:
                    </p>
                    <ul className="list-disc space-y-2 pl-5">
                        <li>
                            <strong className="text-foreground">AI reading.</strong> To pull details such as amounts, dates and PO numbers out of a
                            document or email, Expend sends the text it needs to Anthropic&rsquo;s Claude API. Anthropic&rsquo;s commercial terms
                            don&rsquo;t allow it to train its models on this content.
                        </li>
                        <li>
                            <strong className="text-foreground">Telegram.</strong> Short reminders and summaries go to the owner&rsquo;s own Telegram
                            chat. They never include account numbers or whole emails.
                        </li>
                        <li>
                            <strong className="text-foreground">Hosting.</strong> Expend runs on a server provided by Hostinger, which stores its
                            database and files.
                        </li>
                        <li>
                            <strong className="text-foreground">Emails the owner sends.</strong> An invoice or reminder the owner chooses to send goes
                            to the people it is addressed to.
                        </li>
                    </ul>
                    <p>No one but the owner reads this information.</p>
                    <p>Expend does not use Google user data to develop, improve or train generalised AI or machine-learning models.</p>
                    <p>
                        Expend&rsquo;s use and transfer to any other app of information received from Google APIs will adhere to the{' '}
                        <a
                            href="https://developers.google.com/terms/api-services-user-data-policy"
                            className="text-foreground underline underline-offset-4"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            Google API Services User Data Policy
                        </a>
                        , including the Limited Use requirements.
                    </p>
                </Section>

                <Section title="Keeping it safe">
                    <p>
                        Expend is reached only over HTTPS and needs the owner&rsquo;s password to sign in, with two-factor sign-in available. Google
                        access tokens and other keys are stored encrypted. Every change to the records is written to an audit trail. Backups are
                        encrypted.
                    </p>
                </Section>

                <Section title="Keeping and deleting">
                    <p>
                        Business records are kept for as long as UK tax law requires: at least five years after the 31 January submission deadline for
                        the tax year they belong to.
                    </p>
                    <p>
                        The owner can disconnect Google at any time in Expend&rsquo;s settings. Expend then deletes its Google access tokens and stops
                        reading Gmail, Calendar and Drive. Access can also be removed from the Google account at{' '}
                        <a
                            href="https://myaccount.google.com/permissions"
                            className="text-foreground underline underline-offset-4"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            myaccount.google.com/permissions
                        </a>
                        . Stored emails and documents can be deleted in Expend.
                    </p>
                </Section>

                <Section title="Contact">
                    <p>
                        Questions about this page go to {owner}
                        {email ? (
                            <>
                                {' '}
                                at{' '}
                                <a href={`mailto:${email}`} className="text-foreground underline underline-offset-4">
                                    {email}
                                </a>
                            </>
                        ) : null}
                        .
                    </p>
                </Section>
            </main>
        </div>
    );
}
