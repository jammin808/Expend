import { useCallback, useEffect, useRef, useState } from 'react';
import { getJson, problemText } from './api';
import { type MonthData } from './types';

/** A month as the page can use it, whatever an older server left out. */
export function normaliseMonth(data: Partial<MonthData> & { month: string }): MonthData {
    return { ...data, month: data.month, events: data.events ?? [], job_days: data.job_days ?? {}, locked_years: data.locked_years ?? [] };
}

/**
 * Months of the calendar fetched as they're shown (GET /calendar/month) and kept while the page is open. The page's
 * own month, when it has one, is given as it came with the page.
 */
export function useMonths(seed?: MonthData | null) {
    const [months, setMonths] = useState<Record<string, MonthData>>(() => (seed ? { [seed.month]: normaliseMonth(seed) } : {}));
    const [failed, setFailed] = useState<Record<string, string>>({});
    const asked = useRef(new Set<string>());

    // The page moved to another month (its props changed): that month is known now, fresh from the server.
    useEffect(() => {
        if (seed) setMonths((m) => ({ ...m, [seed.month]: normaliseMonth(seed) }));
    }, [seed]);

    const load = useCallback((month: string) => {
        asked.current.add(month);
        setFailed((f) => {
            const { [month]: _gone, ...rest } = f;
            void _gone;
            return rest;
        });
        getJson<MonthData>('/calendar/month', { m: month })
            .then((data) => setMonths((m) => ({ ...m, [month]: normaliseMonth({ ...data, month }) })))
            .catch((e) => {
                asked.current.delete(month);
                setFailed((f) => ({ ...f, [month]: problemText(e) }));
            });
    }, []);

    /** Fetches any of these months not known or asked for yet. */
    const ensure = useCallback(
        (list: string[]) => {
            for (const m of list) if (!months[m] && !asked.current.has(m) && !failed[m]) load(m);
        },
        [months, failed, load],
    );

    /** Forgets the fetched months (after a save changes what's on them); the page's own month is kept. */
    const forget = useCallback(() => {
        asked.current.clear();
        setFailed({});
        setMonths(seed ? { [seed.month]: normaliseMonth(seed) } : {});
    }, [seed]);

    return { months, failed, ensure, retry: load, forget };
}
