import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { kindLabels } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

interface Rule {
    id: number;
    merchant_key: string;
    kind: string;
    category: string | null;
    business_pct: number;
    auto_confirm: boolean;
    /** A rule learned from your decisions applies only the way the money went in them. */
    direction?: 'out' | 'in' | null;
    applied: number;
}

const directionLabels = { out: 'payments out only', in: 'money in only' };

export default function Rules({ rules }: { rules: Rule[] }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Settings', href: '/settings/rules' }]}>
            <Head title="Rules" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Merchant rules"
                        description="A rule fills in what new payments from a merchant are. It never changes anything you’ve decided. A rule you save with “Suggest this next time” only suggests, for you to confirm, unless you tick Confirm on its own. A rule made with “Always do this” confirms on its own: untick it to check each payment yourself."
                    />
                    <ul className="divide-y rounded-xl border text-sm">
                        {rules.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                <span>
                                    <span className="font-medium">{r.merchant_key}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {kindLabels[r.kind]}
                                        {r.category ? ` · ${r.category}` : ''}
                                        {r.business_pct < 100 ? ` · ${r.business_pct}% business` : ''}
                                        {r.direction ? ` · ${directionLabels[r.direction]}` : ''}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3">
                                    <label className="flex items-center gap-2 text-xs">
                                        <Checkbox
                                            checked={r.auto_confirm}
                                            onCheckedChange={(v) =>
                                                router.patch(`/settings/rules/${r.id}`, { auto_confirm: !!v }, { preserveScroll: true })
                                            }
                                        />
                                        Confirm on its own
                                    </label>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.delete(`/settings/rules/${r.id}`, { preserveScroll: true })}
                                    >
                                        Remove
                                    </Button>
                                    {r.applied > 0 && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            title="Remove the rule and put what it decided back in the Inbox"
                                            onClick={() =>
                                                window.confirm(`Undo this rule? Its ${r.applied} decisions go back to the Inbox.`) &&
                                                router.post(`/rules/${r.id}/undo`, {}, { preserveScroll: true })
                                            }
                                        >
                                            Undo ({r.applied})
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                        {rules.length === 0 && (
                            <li className="text-muted-foreground p-4">
                                No rules yet. Tick “Suggest this next time” when you decide something, or tap “Always do this” when Expend offers it.
                            </li>
                        )}
                    </ul>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
