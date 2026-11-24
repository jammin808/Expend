<?php

namespace App\Domain\Matching;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Recognises money moving between your own accounts: your own name as the payee or payer, a top-up, a savings pot,
 * a currency exchange, a card bill, or another account of yours named on the line. Money that only moves between
 * your accounts is never spending or income, so it is kept out of every total, and only the purchases themselves
 * count.
 */
final class OwnAccounts
{
    private const TITLES = ['MR', 'MRS', 'MS', 'MISS', 'MX', 'DR'];

    /** Lines that only ever move your own money: top-ups, savings pots and spaces, round-ups, exchanges. */
    public const OWN_PATTERN = '/\bTOP[\s-]?UP\b|\bTOPUP\b|\bSAVINGS?\s+(POT|SPACE|GOAL|VAULT)\b|\b(TO|FROM)\s+(POT|SPACE|VAULT)\b|\bROUND[\s-]?UPS?\b|\bINTERNAL TRANSFER\b|\bOWN ACCOUNT\b|\bBETWEEN (MY )?ACCOUNTS\b|\bEXCHANGED? (TO|FROM) [A-Z]{3}\b|\b(TO|FROM) (POCKET )?[A-Z]{3} [A-Z ]{0,30}?(SAVINGS|POCKET|VAULT)\b|\bTO POCKET [A-Z]{3}\b|\bPOCKET WITHDRAWAL\b|\bMONEY ADDED FROM (YOUR )?(SAVINGS|VAULT|POCKET)\b/i';

    /** A card statement's "payment received": you paying the card bill from a bank account. */
    public const CARD_PAYMENT = '/PAYMENT RECEIVED|THANK YOU|\bDIRECT DEBIT PAYMENT\b|\bPAYMENT\s*-\s*THANK/i';

    private const PROVIDER_WORDS = [
        'amex' => ['AMERICAN EXPRESS', 'AMEX'],
        'capital_one' => ['CAPITAL ONE'],
        'starling' => ['STARLING'],
        'revolut' => ['REVOLUT'],
        'monzo' => ['MONZO'],
        'wise' => ['WISE PAYMENTS', 'TRANSFERWISE'],
        'paypal' => ['PAYPAL TRANSFER', 'PAYPAL BALANCE'],
        'barclaycard' => ['BARCLAYCARD'],
        'hsbc' => ['HSBC'],
        'nationwide' => ['NATIONWIDE'],
        'santander' => ['SANTANDER'],
        'natwest' => ['NATWEST'],
        'lloyds' => ['LLOYDS'],
        'halifax' => ['HALIFAX'],
        'tide' => ['TIDE'],
    ];

    /** @var list<array{given:list<string>, surname:string}>|null */
    private static ?array $names = null;

    /** @var list<string>|null */
    private static ?array $otherAccounts = null;

    /** @var Collection<int, Account>|null */
    private static ?Collection $accounts = null;

    public static function flush(): void
    {
        self::$names = self::$otherAccounts = null;
        self::$accounts = null;
    }

    /** @return list<string> the names you go by, as you entered them (or your login name). */
    public static function names(): array
    {
        $saved = array_values(array_filter(array_map('trim', (array) Setting::get('own_names'))));

        return $saved ?: array_values(array_filter([User::query()->orderBy('id')->value('name')]));
    }

    /** @return list<string> other accounts of yours that aren't imported (savings elsewhere, PayPal, a partner's joint account...). */
    public static function otherAccounts(): array
    {
        return self::$otherAccounts ??= array_values(array_filter(array_map(fn ($w) => self::normalise((string) $w), (array) Setting::get('own_other_accounts')), fn ($w) => mb_strlen($w) >= 3));
    }

    /** Does this text name you (not a relative with the same surname)? "A SMITH", "MR ALEX J SMITH", "SMITH A". */
    public static function isYou(?string $text): bool
    {
        $tokens = explode(' ', self::normalise((string) $text));
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== '' && ! in_array($t, self::TITLES, true)));
        if (! $tokens || count($tokens) > 5) {
            return false;
        }
        foreach (self::parsedNames() as $n) {
            $i = array_search($n['surname'], $tokens, true);
            if ($i === false) {
                continue;
            }
            $others = array_values(array_diff_key($tokens, [$i => true]));
            if (! $others) {
                continue; // a surname alone could be anyone in the family
            }
            $first = $n['given'][0] ?? null;
            $matchesFirst = false;
            $ok = true;
            foreach ($others as $t) {
                if ($first && ($t === $first || $t === $first[0])) {
                    $matchesFirst = true;
                } elseif (! (mb_strlen($t) === 1 || in_array($t, $n['given'], true))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok && $matchesFirst) {
                return true;
            }
        }

        return false;
    }

    /** Does this text contain your name anywhere (for a reference like "TO A SMITH SAVINGS")? */
    public static function mentionsYou(?string $text): bool
    {
        $text = ' '.self::normalise((string) $text).' ';
        foreach (self::parsedNames() as $n) {
            $first = $n['given'][0] ?? null;
            if (! $first) {
                continue;
            }
            foreach (["{$first} {$n['surname']}", "{$first[0]} {$n['surname']}", "{$n['surname']} {$first}", "{$n['surname']} {$first[0]}"] as $variant) {
                if (str_contains($text, " {$variant} ")) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Words that identify this account on another account's statement line. @return list<string> */
    public static function accountWords(Account $account): array
    {
        $name = self::normalise($account->name);
        $generic = in_array($name, ['CURRENT ACCOUNT', 'CURRENT', 'MAIN', 'SAVINGS', 'BUSINESS', 'PERSONAL', 'CARD', 'CREDIT CARD', 'ACCOUNT'], true);

        return array_values(array_unique(array_filter([
            $generic || mb_strlen($name) < 4 ? null : $name,
            ...(self::PROVIDER_WORDS[$account->provider] ?? ($account->provider && ! in_array($account->provider, ['other', 'generic'], true) ? [strtoupper(str_replace('_', ' ', $account->provider))] : [])),
        ])));
    }

    /** Does the line name this account: its name, its bank, or its last four digits ("*1234", "ending 1234")? */
    public static function names_account(string $text, Account $account): bool
    {
        $text = ' '.self::normalise($text).' ';
        foreach (self::accountWords($account) as $word) {
            if (str_contains($text, " {$word} ") || str_contains($text, " {$word}")) {
                return true;
            }
        }
        if ($account->last4 && preg_match('/(\*|X{2,}|ENDING|ACCOUNT|A\/C|CARD)\s*'.preg_quote($account->last4, '/').'\b/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Why this line looks like your own money moving, judged from this line alone (the other side may not be
     * imported). Null when it doesn't.
     */
    public static function reason(BankTransaction $tx): ?string
    {
        $tx->loadMissing('account');
        $text = $tx->description.' '.$tx->counterparty;
        $norm = ' '.self::normalise($text).' ';

        if (self::isYou($tx->counterparty) || (! $tx->counterparty && self::isYou($tx->description))) {
            return $tx->amount_pence < 0 ? 'Paid to you (your own name), so it’s money moving to another account of yours' : 'Paid in by you (your own name), so it’s money from another account of yours';
        }
        if (preg_match(self::OWN_PATTERN, $text)) {
            return 'A top-up, savings pot, exchange or move between accounts, so it’s your own money moving';
        }
        if ($tx->account?->isCard() && $tx->amount_pence > 0 && preg_match(self::CARD_PAYMENT, $text)) {
            return 'A payment towards this card’s bill, from one of your accounts';
        }
        foreach (self::accounts() as $other) {
            if ($other->id !== $tx->account_id && self::names_account($text, $other)) {
                return ($tx->amount_pence < 0 ? 'Paid to ' : 'Paid in from ').'your '.$other->name.' account';
            }
        }
        foreach (self::otherAccounts() as $word) {
            if (str_contains($norm, " {$word} ")) {
                return 'Names '.mb_convert_case(mb_strtolower($word), MB_CASE_TITLE).', which you listed as an account of yours';
            }
        }

        return null;
    }

    /** Transactions that count as money moving between your accounts. */
    public static function scopeTransfers(Builder $q): Builder
    {
        return $q->where(fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer'))
            ->orWhere(fn ($q) => $q->whereNotNull('transfer_pair_id')->whereDoesntHave('allocations')));
    }

    /** Everything else: real spending and income. */
    public static function scopeNotTransfers(Builder $q): Builder
    {
        return $q->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer'))
            ->where(fn ($q) => $q->whereNull('transfer_pair_id')->orWhereHas('allocations'));
    }

    public static function isTransfer(BankTransaction $tx): bool
    {
        $tx->loadMissing('allocations');

        return $tx->allocations->contains('kind', 'transfer') || ($tx->transfer_pair_id && $tx->allocations->isEmpty());
    }

    /** @return Collection<int, Account> */
    public static function accounts(): Collection
    {
        return self::$accounts ??= Account::query()->get();
    }

    public static function normalise(string $text): string
    {
        $text = mb_strtoupper($text);
        $text = preg_replace('/[^\p{L}\p{N}\*\/]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /** @return list<array{given:list<string>, surname:string}> */
    private static function parsedNames(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }
        $out = [];
        foreach (self::names() as $name) {
            $tokens = array_values(array_filter(explode(' ', self::normalise($name)), fn ($t) => $t !== '' && ! in_array($t, self::TITLES, true)));
            if (count($tokens) < 2) {
                continue;
            }
            $surname = array_pop($tokens);
            $out[] = ['given' => $tokens, 'surname' => $surname];
        }

        return self::$names = $out;
    }
}
