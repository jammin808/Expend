import { PickerGroup, PickerOption, PickerTrigger, usePicker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson, problemText } from '@/lib/api';
import { matchClients } from '@/lib/income';
import { type ClientRef } from '@/types';
import { Plus } from 'lucide-react';
import { useId, useMemo, useState } from 'react';

/** What POST /clients answers: the client added, or the one you have with that name already. */
interface Added {
    id: number;
    name: string;
    existing: boolean;
    message?: string;
}

/**
 * Pick a client: type to narrow the list, or add one with "New client…". A name you have already asks first: use the
 * one you have, or "It's a different client". Nothing is picked until you tap a row.
 */
export function ClientSelect({
    clients,
    value,
    name,
    onChange,
    id,
    allowNone = false,
    noneLabel = 'No client',
    placeholder = 'Pick the client',
    disabled,
    className,
}: {
    clients: ClientRef[];
    value: number | null | undefined;
    /** The picked client's name, when it isn't in the list (one added here, or worked out by the server). */
    name?: string | null;
    onChange: (client: ClientRef | null) => void;
    id?: string;
    allowNone?: boolean;
    noneLabel?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [added, setAdded] = useState<ClientRef[]>([]);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [same, setSame] = useState<{ have: ClientRef; typed: string } | null>(null);

    const all = useMemo(() => {
        const ids = new Set(clients.map((c) => c.id));
        return [...clients, ...added.filter((c) => !ids.has(c.id))];
    }, [clients, added]);
    const shown = useMemo(() => matchClients(all, query).slice(0, 60), [all, query]);
    const typed = query.trim();
    // The rows, in order: "No client" (when allowed), the matches, then "New client…".
    const rows: ({ type: 'none' } | { type: 'client'; client: ClientRef } | { type: 'new' })[] = [
        ...(allowNone ? [{ type: 'none' as const }] : []),
        ...shown.map((client) => ({ type: 'client' as const, client })),
        { type: 'new' as const },
    ];

    const picker = usePicker(rows.length, (i) => choose(rows[i]));
    const current = all.find((c) => c.id === value)?.name ?? (value ? name : null);

    const close = () => {
        picker.setOpen(false);
        setQuery('');
        setSame(null);
        setProblem(null);
    };

    const pick = (client: ClientRef | null) => {
        onChange(client);
        close();
    };

    const create = async (text: string, different = false) => {
        setBusy(true);
        setProblem(null);
        try {
            const answer = await postJson<Added>('/clients', { name: text, different });
            if (answer.existing && !different) {
                setSame({ have: { id: answer.id, name: answer.name }, typed: text });
                return;
            }
            const client = { id: answer.id, name: answer.name };
            setAdded((a) => [...a.filter((c) => c.id !== client.id), client]);
            pick(client);
        } catch (e) {
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    function choose(row: (typeof rows)[number] | undefined) {
        if (!row) return;
        if (row.type === 'none') return pick(null);
        if (row.type === 'client') return pick(row.client);
        if (typed === '') return setProblem('Type the new client’s name first.');
        if (!busy) void create(typed); // one press adds one client
    }

    if (!picker.open) {
        return (
            <PickerTrigger
                id={id}
                open={false}
                disabled={disabled}
                placeholder={placeholder}
                className={className}
                onOpen={() => {
                    picker.setActive(0);
                    picker.setOpen(true);
                }}
            >
                {current ?? undefined}
            </PickerTrigger>
        );
    }

    return (
        <div ref={picker.box} className="bg-background space-y-2 rounded-md border p-2 shadow-sm">
            <Input
                id={id}
                autoFocus
                role="combobox"
                aria-expanded
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={`${listId}-${picker.active}`}
                className="h-11"
                placeholder="Type a name"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setSame(null);
                    setProblem(null);
                    picker.setActive(0);
                }}
                onKeyDown={picker.onKeyDown}
            />
            {same ? (
                <div className="space-y-2 rounded-md bg-amber-50 p-3 text-sm dark:bg-amber-950/40" role="group" aria-label="A client with that name">
                    <p>You already have {same.have.name}.</p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button className="h-auto min-h-11 py-2 text-left whitespace-normal" onClick={() => pick(same.have)}>
                            Use {same.have.name}
                        </Button>
                        <Button className="h-11" variant="outline" disabled={busy} onClick={() => void create(same.typed, true)}>
                            It’s a different client
                        </Button>
                    </div>
                </div>
            ) : (
                <ul id={listId} role="listbox" aria-label="Clients" className="max-h-64 overflow-y-auto overscroll-contain rounded-md border">
                    {shown.length === 0 && typed !== '' && <PickerGroup>No client called that yet</PickerGroup>}
                    {rows.map((row, i) => {
                        const key = `${listId}-${i}`;
                        const common = { id: key, active: picker.active === i, onHover: () => picker.setActive(i), onPick: () => choose(row) };
                        if (row.type === 'none')
                            return (
                                <PickerOption key="none" {...common} selected={!value}>
                                    <span className="text-muted-foreground">{noneLabel}</span>
                                </PickerOption>
                            );
                        if (row.type === 'client')
                            return (
                                <PickerOption key={row.client.id} {...common} selected={row.client.id === value}>
                                    {row.client.name}
                                </PickerOption>
                            );
                        return (
                            <PickerOption key="new" {...common} className="border-t font-medium">
                                <span className="flex items-center gap-2">
                                    <Plus className="size-4 shrink-0" />
                                    {busy ? 'Adding…' : typed ? `New client: “${typed}”` : 'New client…'}
                                </span>
                            </PickerOption>
                        );
                    })}
                </ul>
            )}
            {problem && (
                <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                    {problem}
                </p>
            )}
            <div className="flex justify-end">
                <Button variant="ghost" className="h-11" onClick={close}>
                    Close
                </Button>
            </div>
        </div>
    );
}
