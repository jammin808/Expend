<?php

namespace App\Domain\Matching;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Recognises money moving between your own accounts: your own name as the payee or payer, a savings pot, a currency
 * exchange, a card bill, a top-up of another account of yours, or a move to or from another account of yours named
 * on the line. Money that only moves between your accounts is never spending or income, so it is kept out of every
 * total, and only the purchases themselves count.
 *
 * It errs on the side of leaving a line for you to decide: a merchant or client named on the line, a bank's name in a
 * shop's address, or "top up" on a phone or Oyster purchase is never enough on its own.
 */
final class OwnAccounts
{
    private const TITLES = ['MR', 'MRS', 'MS', 'MISS', 'MX', 'DR'];

    /** Lines that only ever move your own money: savings pots and spaces, round-ups, exchanges, moves between accounts. */
    public const OWN_PATTERN = '/\bSAVINGS?\s+(POT|SPACE|GOAL|VAULT)\b|\b(TO|FROM)\s+(POT|SPACE|VAULT)\b|\bROUND[\s-]?UPS?\b|\bINTERNAL TRANSFER\b|\bOWN ACCOUNT\b|\bBETWEEN (MY )?ACCOUNTS\b|\bEXCHANGED? (TO|FROM) [A-Z]{3}\b|\b(TO|FROM) (POCKET )?[A-Z]{3} [A-Z ]{0,30}?(SAVINGS|POCKET|VAULT)\b|\bTO POCKET [A-Z]{3}\b|\bPOCKET WITHDRAWAL\b|\bMONEY ADDED FROM (YOUR )?(SAVINGS|VAULT|POCKET)\b/i';

    /** "Top up": your own money only when it tops up an account of yours, never a phone, an Oyster card or a coffee card. */
    public const TOP_UP = '/\bTOP[\s-]?UP\b|\bTOPUP\b/i';

    /** Money added to this account from your own card ("Top-up by *4821", "Apple Pay top-up by *1234"). */
    private const TOP_UP_SELF = '/\bTOP ?UP (?:BY|FROM|VIA|WITH|USING) (?:(?:DEBIT|CREDIT) )?(?:CARD\b|\*|X{2,}|\d{4}\b|APPLE PAY|GOOGLE PAY|BANK)/';

    /** The card a top-up came from, as its last four digits ("Top-up by *4821", "Top up from card ending 4821"). */
    private const TOP_UP_CARD = '/\bTOP ?UP (?:BY|FROM|VIA|WITH|USING) (?:(?:DEBIT|CREDIT) )?(?:CARD )?(?:ENDING (?:IN )?)?(?:\* ?|X{2,})?(\d{4})\b/';

    /**
     * Merchants whose "top-up" is a purchase: phone credit (any "mobile" top-up), travel cards, coffee and game
     * cards, prepaid meters and charging.
     */
    private const MERCHANT_TOP_UP = '/\b(EE|BT|VODAFONE|O2|THREE|GIFFGAFF|LEBARA|LYCA|LYCAMOBILE|TESCO MOBILE|ID MOBILE|SMARTY|VOXI|SKY MOBILE|ASDA MOBILE|VIRGIN MOBILE|VIRGIN MEDIA|PLUSNET|TALKMOBILE|TALKTALK|ECOTALK|LIFE MOBILE|1P ?MOBILE|MOBILE|PHONE|SIM|AIRTIME|DING|OYSTER|TFL|DART ?CHARGE|DARTFORD|STAGECOACH|ARRIVA|TRAVEL ?CARD|STARBUCKS|COSTA|PRET|CAFFE NERO|STEAM|XBOX|PLAYSTATION|NINTENDO|GOOGLE PLAY|ITUNES|APP STORE|GIFT ?CARD|PAYPOINT|PREPAY|METER|GAS|ELECTRIC(?:ITY)?|UTILITA|POD POINT|ZAPMAP|UBER|BOLT|TRAINLINE)\b/';

    /** Banks and wallets you top up from or into: a top-up naming one is your own money moving. */
    private const TOP_UP_PROVIDERS = ['REVOLUT', 'MONZO', 'WISE', 'TRANSFERWISE', 'PAYPAL', 'CURVE', 'STARLING', 'CHASE', 'KROO', 'TIDE', 'MONESE'];

    /** Payment types the bank itself gives to moves within your own accounts. */
    private const OWN_TYPES = '/^(INTERNAL TRANSFER|SAVINGS GOAL|SAVINGS SPACE|SPACE TRANSFER|POT TRANSFER|ROUND ?UPS?|EXCHANGE)$/i';

    /** A card statement's "payment received": you paying the card bill from a bank account. */
    public const CARD_PAYMENT = '/PAYMENT RECEIVED|THANK YOU|\bDIRECT DEBIT PAYMENT\b|\bPAYMENT\s*-\s*THANK/i';

    /** The words before a payee's or payer's name on a line without a counterparty ("To ALEX SMITH", "Payment from …"). */
    private const LEAD_IN = '/^(?:(?:(?:FASTER PAYMENTS?|BANK TRANSFER|TRANSFER|PAYMENT|MONEY (?:ADDED|SENT|RECEIVED)|SENT|RECEIVED|INWARD PAYMENT|OUTWARD PAYMENT|STANDING ORDER|TFR|FPI|FPO|FPS|BGC|BACS|CHAPS) )?(?:TO|FROM)|(?:PAYMENT|TRANSFER|MONEY ADDED|SENT|PAID) BY|FPI|FPO|FPS|BGC|BANK GIRO CREDIT|BACS|CHAPS|TFR|BP|SO|STO) /';

    /** Bank codes and account words after a name ("A SMITH SAVINGS FT"). */
    private const TRAILING = '/ (?:FT|FPO|FPI|FPS|BGC|SO|STO|TFR|BP|BACS|CHAPS|DD|REF|SAVINGS|SAVER|CURRENT|ACCOUNT|ISA)$/';

    /** Company endings that don't change who a payee is ("REVOLUT LTD" is Revolut, "HALIFAX CREDIT CARD" is Halifax). */
    private const SUFFIXES = '/ (?:LTD|LIMITED|PLC|BANK|UK|CREDIT CARDS?|CARD|CARDS|SERVICES|EUROPE|GB)$/';

    /** Company endings only, for names whose "Bank" is part of who they are ("TESCO BANK" isn't Tesco). */
    private const COMPANY_ENDINGS = '/ (?:LTD|LIMITED|PLC|UK|GB)$/';

    /**
     * Card issuers and banks a card bill is paid to, besides the providers above. Named as the payee, they're a bank,
     * not somebody else; on money going out, a hint that it pays one of your cards.
     */
    private const CARD_ISSUERS = ['BARCLAYS', 'BARCLAYS BANK', 'BARCLAYCARD', 'MBNA', 'VIRGIN MONEY', 'TESCO BANK', 'SAINSBURYS BANK', 'M S BANK', 'MARKS SPENCER BANK',
        'JOHN LEWIS FINANCE', 'JOHN LEWIS PARTNERSHIP CARD', 'TSB', 'TSB BANK', 'RBS', 'ROYAL BANK OF SCOTLAND', 'BANK OF SCOTLAND', 'FIRST DIRECT', 'METRO BANK',
        'CO OPERATIVE BANK', 'COOPERATIVE BANK', 'ULSTER BANK', 'CLYDESDALE BANK', 'VANQUIS', 'VANQUIS BANK', 'NEWDAY', 'ZOPA', 'POST OFFICE MONEY', 'CAPITAL ONE',
        'AMERICAN EXPRESS', 'AMEX', 'HSBC', 'NATWEST', 'LLOYDS', 'LLOYDS BANK', 'HALIFAX', 'SANTANDER', 'NATIONWIDE'];

    /** A card bill in the line's own words ("HALIFAX CREDIT CARD", "CC PAYMENT"). Never "card payment", a debit card purchase. */
    private const CARD_BILL = '/\bCREDIT ?CARD\b|\bCARD (?:BILL|REPAYMENT|ACCOUNT PAYMENT)\b|\bCC (?:PAYMENT|BILL|REPAYMENT)\b/';

    /**
     * Money added to a wallet of yours (PayPal's "General Card Deposit", "Bank Deposit to PP Account"). Never a
     * client's "Deposit for the May show".
     */
    private const WALLET_FUNDING = '/\b(?:CARD|BANK) DEPOSIT\b|\bDEPOSIT (?:TO|INTO|FROM) (?:PP|PAYPAL|BANK|CARD|YOUR)\b|\bFUNDING\b|\bADD(?:ED)? (?:FUNDS|MONEY)\b|\bMONEY ADDED\b/';

    /** A payee named with one of these is a pot or account of yours, not a person ("To EUR Savings"). */
    private const ACCOUNT_LIKE = '/\b(SAVINGS|SAVER|POT|SPACE|VAULT|POCKET|ISA|GOAL)\b/';

    /** Wallets that purchases go through ("PAYPAL *THOMANN"): named only by a move in or out of them, never by a purchase. */
    private const WALLETS = ['PAYPAL', 'CURVE', 'CRV', 'KLARNA', 'CLEARPAY', 'LAYBUY', 'AMAZON PAY', 'GOOGLE PAY', 'APPLE PAY'];

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

    /** @var array{plain: array<string, true>, light: array<string, true>}|null */
    private static ?array $ownNames = null;

    public static function flush(): void
    {
        self::$names = self::$otherAccounts = self::$ownNames = null;
        self::$accounts = null;
    }

    /** @return list<string> the names you go by, as you entered them (or your login name). */
    public static function names(): array
    {
        $saved = array_values(array_filter(array_map('trim', (array) Setting::get('own_names'))));

        return $saved ?: array_values(array_filter([User::query()->orderBy('id')->value('name')]));
    }

    /** @return list<string> other accounts of yours that aren't imported (savings elsewhere, a partner's joint account...). */
    public static function otherAccounts(): array
    {
        return self::$otherAccounts ??= array_values(array_filter(array_map(fn ($w) => self::normalise((string) $w), (array) Setting::get('own_other_accounts')), fn ($w) => mb_strlen($w) >= 3));
    }

    /** Does this text name you (not a relative with the same surname)? "A SMITH", "MR ALEX J SMITH", "SMITH A". */
    public static function isYou(?string $text): bool
    {
        $tokens = explode(' ', self::trimCodes(self::normalise((string) $text)));
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

    /**
     * Who the money went to or came from: the counterparty, or the name after "To" or "Payment from" on a line
     * without one (Revolut, Amex and most custom statements have no counterparty column). Null when the line
     * doesn't say.
     */
    public static function party(BankTransaction $tx): ?string
    {
        if (trim((string) $tx->counterparty) !== '') {
            return self::trimCodes(self::normalise((string) $tx->counterparty));
        }
        $who = preg_replace(self::LEAD_IN, '', self::normalise((string) $tx->description), 1, $stripped);

        return $stripped && $who !== null && $who !== '' ? self::trimCodes($who) : null;
    }

    /** Paid to you or by you: your name as the counterparty, or as the payee on a line that has no counterparty. */
    public static function byOrToYou(BankTransaction $tx): bool
    {
        if (trim((string) $tx->counterparty) !== '') {
            return self::isYou($tx->counterparty);
        }
        $desc = self::normalise((string) $tx->description);

        return self::isYou(preg_replace(self::LEAD_IN, '', $desc, 1) ?? $desc);
    }

    /** The bank calls it a move within your own accounts (an internal transfer, a savings goal, an exchange). */
    public static function ownType(BankTransaction $tx): bool
    {
        return $tx->payment_type !== null && preg_match(self::OWN_TYPES, trim($tx->payment_type)) === 1;
    }

    /** "Top up" on the line, and not a phone, travel-card, coffee-card or meter top-up. */
    public static function topUpWording(BankTransaction $tx): bool
    {
        $norm = self::normalise($tx->description.' '.$tx->counterparty);

        return preg_match(self::TOP_UP, $norm) === 1 && ! preg_match(self::MERCHANT_TOP_UP, $norm);
    }

    /** Money added to this (bank or e-money) account from your own card: "Top-up by *4821". */
    public static function topUpBySelf(BankTransaction $tx): bool
    {
        $tx->loadMissing('account');

        return $tx->amount_pence > 0 && ! $tx->account?->isCard() && self::topUpWording($tx)
            && preg_match(self::TOP_UP_SELF, self::normalise($tx->description.' '.$tx->counterparty)) === 1;
    }

    /** The last four digits of the card a top-up of this account came from, when the line gives them. */
    public static function topUpCard(BankTransaction $tx): ?string
    {
        return self::topUpBySelf($tx) && preg_match(self::TOP_UP_CARD, self::normalise($tx->description.' '.$tx->counterparty), $m) ? $m[1] : null;
    }

    /**
     * The last four digits of the card this line was paid with, when they're known: the statement's own card
     * column, or the card account's number. (A bank account's "last 4" may be its account number, not its card's.)
     */
    public static function paidWithCard(BankTransaction $tx): ?string
    {
        $tx->loadMissing('account');
        $card = $tx->card_last4 ?: ($tx->account?->isCard() ? $tx->account->last4 : null);

        return $card !== null && preg_match('/^\d{4}$/', (string) $card) ? (string) $card : null;
    }

    /** The line reads as a savings pot, a round-up, an exchange or a move between your accounts. */
    public static function ownWording(BankTransaction $tx): bool
    {
        return self::ownType($tx) || preg_match(self::OWN_PATTERN, $tx->description.' '.$tx->counterparty) === 1;
    }

    /**
     * Somebody else paid it or was paid: the line names a payer or payee who isn't you, isn't a bank or wallet, and
     * isn't one of your accounts or pots.
     */
    public static function thirdParty(BankTransaction $tx): bool
    {
        $who = self::party($tx);
        if ($who === null || $who === '' || self::isYou($who) || self::ownType($tx)) {
            return false;
        }
        $fromDescription = trim((string) $tx->counterparty) === '';
        if ($fromDescription && (preg_match(self::OWN_PATTERN, (string) $tx->description) || preg_match(self::ACCOUNT_LIKE, $who))) {
            return false; // "To EUR Savings", "From Holiday pot": one of your own pots
        }
        if (! $fromDescription && preg_match('/\b(POT|SPACE|VAULT|POCKET|SAVINGS|SAVER|ISA|GOAL)$/', self::normalise((string) $tx->counterparty))) {
            return false; // a pot or space of yours as the payee ("Holiday pot")
        }
        if (self::isBankName($who) || self::walletOfYours($who)) {
            return false;
        }
        // One of your accounts as the payee: "REVOLUT LTD", and "HALIFAX CREDIT CARD" paid to your Halifax Credit
        // Card account (compared like with like). Never "TESCO" for a "Tesco Bank" account.
        $own = self::ownNames();

        return ! isset($own['plain'][self::corporate($who)]) && ! isset($own['light'][self::companyless($who)]);
    }

    /**
     * Your accounts' names and words and the accounts you listed: as they are, and without company endings.
     *
     * @return array{plain: array<string, true>, light: array<string, true>}
     */
    private static function ownNames(): array
    {
        if (self::$ownNames !== null) {
            return self::$ownNames;
        }
        $words = self::otherAccounts();
        foreach (self::accounts() as $account) {
            $words = [...$words, self::normalise($account->name), ...self::accountWords($account)];
        }
        $out = ['plain' => [], 'light' => []];
        foreach (array_filter($words, fn ($w) => $w !== '') as $word) {
            $out['plain'][$word] = true;
            $out['light'][self::companyless($word)] = true;
        }

        return self::$ownNames = $out;
    }

    /** A name without "Ltd", "PLC" and the like, but with "Bank" or "Card", which may be part of who it is. */
    private static function companyless(string $norm): string
    {
        do {
            $before = $norm;
            $norm = preg_replace(self::COMPANY_ENDINGS, '', $norm) ?? $norm;
        } while ($norm !== $before);

        return $norm;
    }

    /**
     * A wallet you import the statement of (PayPal), named by its purchase descriptor ("PAYPAL *THOMANN"): the money
     * went into your wallet, and the purchase itself is on the wallet's own statement. A wallet you only listed (not
     * imported) never counts: the purchase would be counted nowhere.
     */
    public static function walletOfYours(string $who, ?Account $wallet = null): bool
    {
        $who = self::normalise($who);
        foreach ($wallet ? [$wallet] : self::accounts() as $account) {
            $word = self::walletWord($account);
            if ($word !== null && preg_match('/^'.preg_quote($word, '/').' ?\*/u', $who)) {
                return true;
            }
        }

        return false;
    }

    /** The wallet an account is (PayPal, Curve...), by its provider or its name; null for a bank or card. */
    public static function walletWord(Account $account): ?string
    {
        if ($account->provider === 'paypal') {
            return 'PAYPAL';
        }
        $name = self::normalise($account->name);
        foreach (self::WALLETS as $w) {
            if ($name === $w || str_starts_with($name, $w.' ')) {
                return $w;
            }
        }

        return null;
    }

    /** Money added to a wallet account of yours ("General Card Deposit" on PayPal's statement), from nobody else. */
    public static function walletFunding(BankTransaction $tx): bool
    {
        $tx->loadMissing('account');

        return $tx->amount_pence > 0 && $tx->account && self::walletWord($tx->account) !== null && ! self::thirdParty($tx)
            && preg_match(self::WALLET_FUNDING, self::normalise($tx->description.' '.$tx->counterparty).' '.self::normalise((string) $tx->payment_type)) === 1;
    }

    /** A purchase through your wallet account $wallet, paid from this (bank or card) line: "PAYPAL *THOMANN". */
    public static function fundsWallet(BankTransaction $tx, Account $wallet): bool
    {
        if ($tx->amount_pence >= 0 || self::walletWord($wallet) === null) {
            return false;
        }
        foreach (array_filter([(string) $tx->counterparty, (string) $tx->description]) as $text) {
            if (self::walletOfYours($text, $wallet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Money going out that pays a card bill, by what the line says: paid to a bank or card issuer ("BARCLAYCARD",
     * "AMERICAN EXPRESS", "HALIFAX"), or a credit card or card bill in its own words. Only a hint: it counts as
     * evidence only next to a card's own "payment received".
     */
    public static function paysACard(BankTransaction $tx): bool
    {
        if ($tx->amount_pence >= 0 || self::vetoed($tx)) {
            return false;
        }
        $norm = self::normalise($tx->description.' '.$tx->counterparty);
        if (preg_match(self::CARD_BILL, $norm)) {
            return true;
        }
        $payee = self::party($tx) ?? self::trimCodes(self::normalise((string) $tx->description));

        return $payee !== '' && self::isCardIssuer($payee);
    }

    /**
     * Not your own money, whatever else the line says: somebody else is named as the payer or payee. Money going
     * out to a pot or space is still yours when the line says so; money coming in from a named payer never is (a
     * client's reference can say anything).
     */
    public static function vetoed(BankTransaction $tx): bool
    {
        return self::thirdParty($tx) && ! self::ownType($tx) && ! ($tx->amount_pence < 0 && preg_match(self::OWN_PATTERN, $tx->description.' '.$tx->counterparty));
    }

    /**
     * Plainly your own money moving, whatever the other matchers say: your own name as the payer or payee, or the
     * line (or the bank) calling it a pot, exchange or move between your accounts. Used to keep such a line from
     * being matched to an invoice as income.
     */
    public static function plainlyYours(BankTransaction $tx): bool
    {
        return self::byOrToYou($tx) || (self::ownWording($tx) && ! self::vetoed($tx));
    }

    /** Words that identify this account on another account's statement line. @return list<string> */
    public static function accountWords(Account $account): array
    {
        $name = self::normalise($account->name);
        $generic = in_array($name, ['CURRENT ACCOUNT', 'CURRENT', 'MAIN', 'SAVINGS', 'BUSINESS', 'PERSONAL', 'CARD', 'CREDIT CARD', 'ACCOUNT'], true);

        return array_values(array_unique(array_filter([
            $generic || mb_strlen($name) < 4 ? null : $name,
            ...self::providerWords($account),
        ])));
    }

    /**
     * Words that tell $account apart from the line's own account: a bank's name says nothing about which of your
     * accounts at that bank it is, and pockets of one Revolut account only tell each other apart by their own names.
     *
     * @return list<string>
     */
    private static function wordsFrom(Account $account, ?Account $from): array
    {
        $words = self::accountWords($account);
        if (! $from) {
            return $words;
        }
        $mine = self::accountWords($from);
        if (self::family($account, $from)) {
            $mine = [...$mine, ...self::providerWords($account)];
        }

        return array_values(array_diff($words, $mine));
    }

    /** Pockets of one account, or the account and its pockets. */
    public static function family(Account $a, Account $b): bool
    {
        return $a->id === $b->id || $a->parent_account_id === $b->id || $b->parent_account_id === $a->id
            || ($a->parent_account_id !== null && $a->parent_account_id === $b->parent_account_id);
    }

    /**
     * How a line names another account of yours: 'strict' when it reads as money moving to or from it (its last
     * four digits; "to", "from", "top-up" or "transfer" next to its name; its bank as the payee of money going out;
     * a name of its own of more than one word), 'loose' when the name is only somewhere on the line (a shop in
     * Halifax), null when it doesn't name it.
     *
     * With $besides, only what tells $other apart from that account counts: "AMERICAN EXPRESS" names both of two
     * Amex cards, so it names neither one rather than the other.
     */
    public static function naming(BankTransaction $tx, Account $other, ?Account $besides = null): ?string
    {
        $tx->loadMissing('account');
        if ($tx->account_id === $other->id || ($tx->account && self::family($tx->account, $other) && self::wordsFrom($other, $tx->account) === [])) {
            return null;
        }
        $text = self::normalise($tx->description.' '.$tx->counterparty);
        if (self::matchesLast4($text, $other) && ! ($besides && $besides->last4 === $other->last4)) {
            return 'strict';
        }
        $providers = self::providerWords($other);
        $words = self::wordsFrom($other, $tx->account);
        if ($besides) {
            $shared = [...self::accountWords($besides), ...self::providerWords($besides)];
            $words = array_values(array_filter($words, fn ($w) => ! in_array($w, $shared, true) && ! self::nameContains($besides, $w)));
        }
        $loose = false;
        foreach ($words as $word) {
            if (! self::hasWord($text, $word)) {
                continue;
            }
            $loose = true;
            $isProvider = in_array($word, $providers, true);
            if (self::strictly($tx, $text, $word, multiWordCounts: ! $isProvider || preg_match('/\b(TRANSFER|BALANCE)\b/', $word) === 1, payeeCounts: $tx->amount_pence < 0 || ! $isProvider)) {
                return 'strict';
            }
        }

        return $loose ? 'loose' : null;
    }

    /**
     * Why this line looks like your own money moving, judged from this line alone (the other side may not be
     * imported). Null when it doesn't, or when it's not sure: then the line is left for you to decide.
     */
    public static function reason(BankTransaction $tx): ?string
    {
        $tx->loadMissing('account');
        $text = $tx->description.' '.$tx->counterparty;
        $norm = self::normalise($text);
        $in = $tx->amount_pence > 0;

        if (self::byOrToYou($tx)) {
            return $in ? 'Paid in by you (your own name), so it’s money from another account of yours' : 'Paid to you (your own name), so it’s money moving to another account of yours';
        }
        if (self::vetoed($tx)) {
            return null; // somebody else paid it or was paid
        }
        if (self::ownWording($tx)) {
            return 'A savings pot, exchange or move between accounts, so it’s your own money moving';
        }
        if ($tx->account?->isCard() && $in && preg_match(self::CARD_PAYMENT, $text)) {
            return 'A payment towards this card’s bill, from one of your accounts';
        }
        $named = self::accounts()->filter(fn (Account $other) => $other->id !== $tx->account_id && self::naming($tx, $other) === 'strict')->values();
        if ($named->isNotEmpty()) {
            // Named by what only one of them is called, that one; named only by a bank both share (two Amex
            // cards), either of them.
            $apart = $named->filter(fn (Account $a) => $named->every(fn (Account $b) => $a->id === $b->id || self::naming($tx, $a, $b) === 'strict'));
            $names = ($apart->isNotEmpty() ? $apart : $named)->pluck('name')->all();
            $last = array_pop($names);

            return ($in ? 'Paid in from ' : 'Paid to ').'your '.($names ? implode(', ', $names).' or ' : '').$last.' account';
        }
        if (self::topUpWording($tx)) {
            if ($target = self::topUpTarget($tx, $norm)) {
                return 'A top-up '.($in ? 'from ' : 'of ').$target.', so it’s your own money moving';
            }
            if (self::topUpBySelf($tx)) {
                return 'Money added to this account from your own card, so it’s your own money moving';
            }
        }
        foreach (self::otherAccounts() as $word) {
            if (self::listedStrictly($tx, $norm, $word)) {
                return 'Moved '.($in ? 'from ' : 'to ').mb_convert_case(mb_strtolower($word), MB_CASE_TITLE).', which you listed as an account of yours';
            }
        }

        return null;
    }

    /** One of your accounts, a bank or wallet you top up, or an account you listed, named on a top-up line. */
    private static function topUpTarget(BankTransaction $tx, string $norm): ?string
    {
        foreach (self::accounts() as $other) {
            if ($other->id !== $tx->account_id && self::naming($tx, $other) !== null) {
                return 'your '.$other->name.' account';
            }
        }
        $own = $tx->account ? self::accountWords($tx->account) : [];
        foreach (self::TOP_UP_PROVIDERS as $word) {
            if (! in_array($word, $own, true) && self::hasWord($norm, $word)) {
                return 'your '.mb_convert_case(mb_strtolower($word), MB_CASE_TITLE).' account';
            }
        }
        foreach (self::otherAccounts() as $word) {
            if (self::hasWord($norm, $word) && ! preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').' ?\*/u', $norm)) {
                return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
            }
        }

        return null;
    }

    /** A listed account of yours that isn't imported, named as the other side of the money (never a purchase through it). */
    private static function listedStrictly(BankTransaction $tx, string $norm, string $word): bool
    {
        if (! self::hasWord($norm, $word)) {
            return false;
        }
        $wallet = self::isWallet($word);

        return self::strictly($tx, $norm, $word, multiWordCounts: ! $wallet, payeeCounts: ! $wallet, wallet: $wallet);
    }

    /** The word is named as where the money went or came from, not just somewhere on the line. */
    private static function strictly(BankTransaction $tx, string $norm, string $word, bool $multiWordCounts, bool $payeeCounts, ?bool $wallet = null): bool
    {
        $w = preg_quote($word, '/');
        $wallet ??= self::isWallet($word);
        $in = $tx->amount_pence > 0;
        // A purchase through it ("PAYPAL *THOMANN") never counts.
        $descriptor = preg_match('/(?<![\p{L}\p{N}])'.$w.' ?\*(?! ?TOP ?UP)/u', $norm) === 1;
        $after = $wallet ? 'TRANSFER|TFR|TOP ?UP|TOPUP|BALANCE|WITHDRAWAL' : 'TRANSFER|TFR|TOP ?UP|TOPUP|BILL|BALANCE|WITHDRAWAL|SAVINGS|SAVER|ISA|LOAD|CREDIT CARD|CARD BILL|CC';
        $before = 'TRANSFER TO|TRANSFER FROM|TFR TO|TFR FROM|TOP ?UP|TOPUP|WITHDRAWAL FROM|PAYMENT TO|PAYMENT FROM|MONEY ADDED FROM';
        if ($wallet) {
            $before = 'TRANSFER TO|TRANSFER FROM|TFR TO|TFR FROM|TOP ?UP|TOPUP|WITHDRAWAL FROM'.($in ? '|FROM' : '');
        } elseif (str_contains($word, ' ') || self::isBankName($word)) {
            $before .= '|TO|FROM|TFR'; // "To Halifax": a bank's or an account's own name. A one-word name of yours ("Work") only at the start
        }
        if (preg_match('/(?<![\p{L}\p{N}])'.$w.'[ *\/]*(?:'.$after.')(?![\p{L}\p{N}])/u', $norm)
            || preg_match('/(?<![\p{L}\p{N}])(?:'.$before.') (?:YOUR |MY )?'.$w.'(?![\p{L}\p{N}])/u', $norm)
            || (! $wallet && preg_match('/^(?:TO|FROM) (?:YOUR |MY )?'.$w.'(?![\p{L}\p{N}])/u', $norm))) {
            return true;
        }
        if ($descriptor) {
            return false;
        }
        if ($payeeCounts && ($who = self::party($tx)) !== null && (self::corporate($who) === $word || self::companyless($who) === self::companyless($word))) {
            return true;
        }

        return $multiWordCounts && str_contains($word, ' ');
    }

    private static function isWallet(string $word): bool
    {
        foreach (self::WALLETS as $w) {
            if ($word === $w || str_starts_with($word, $w.' ')) {
                return true;
            }
        }

        return false;
    }

    /** A bank's, card issuer's or wallet's own name ("REVOLUT LTD", "WISE PAYMENTS LIMITED", "AMERICAN EXPRESS", "MBNA"). */
    public static function isBankName(string $who): bool
    {
        $corp = self::corporate(self::normalise($who));
        foreach ([...array_merge(...array_values(self::PROVIDER_WORDS)), ...self::TOP_UP_PROVIDERS, ...self::WALLETS] as $word) {
            if ($corp === $word) {
                return true;
            }
        }

        return self::isCardIssuer($who);
    }

    /** A bank or card issuer a card bill is paid to ("BARCLAYCARD", "AMERICAN EXPRESS", "TESCO BANK"), never a wallet. */
    private static function isCardIssuer(string $who): bool
    {
        $norm = self::normalise($who);
        $plain = self::companyless($norm);
        $corp = self::corporate($norm);
        $words = [...self::CARD_ISSUERS, ...array_merge(...array_values(array_diff_key(self::PROVIDER_WORDS, ['paypal' => true, 'wise' => true])))];

        return in_array($plain, $words, true) || in_array($corp, $words, true);
    }

    /** @return list<string> */
    private static function providerWords(Account $account): array
    {
        return self::PROVIDER_WORDS[$account->provider] ?? ($account->provider && ! in_array($account->provider, ['other', 'generic'], true) ? [strtoupper(str_replace('_', ' ', $account->provider))] : []);
    }

    /** The account's own name has this word in it ("HALIFAX" in "Halifax Credit Card"). */
    private static function nameContains(Account $account, string $word): bool
    {
        return self::hasWord(self::normalise($account->name), $word);
    }

    private static function hasWord(string $normText, string $word): bool
    {
        return $word !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $normText) === 1;
    }

    private static function matchesLast4(string $normText, Account $account): bool
    {
        return $account->last4 && preg_match('/(\*|X{2,}|ENDING|ACCOUNT|A\/C|CARD)\s*'.preg_quote($account->last4, '/').'\b/i', $normText) === 1;
    }

    /** A name without its company ending. */
    private static function corporate(string $norm): string
    {
        do {
            $before = $norm;
            $norm = preg_replace(self::SUFFIXES, '', $norm) ?? $norm;
        } while ($norm !== $before);

        return $norm;
    }

    /** A name without bank codes and account words after it. */
    private static function trimCodes(string $norm): string
    {
        do {
            $before = $norm;
            $norm = preg_replace(self::TRAILING, '', $norm) ?? $norm;
        } while ($norm !== $before);

        return $norm;
    }

    /**
     * Rows that count as money moving between your accounts, for totals that add up whole rows (the Transactions
     * page, the assistant's searches): a line of the decision is a transfer, or they're paired and not decided yet.
     *
     * A split of a transfer and a cost (a currency exchange and its fee) is counted here whole, so its transfer is
     * never shown as spending; only its fee is missing from spending. Totals that need the fee too add up line by
     * line with transferPenceSql() and spendingPenceSql() over all rows instead.
     */
    public static function scopeTransfers(Builder $q): Builder
    {
        return $q->where(fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer'))
            ->orWhere(fn ($q) => $q->whereNotNull('transfer_pair_id')->whereDoesntHave('allocations')));
    }

    /** Everything else, for totals that add up whole rows: real spending and income (see scopeTransfers()). */
    public static function scopeNotTransfers(Builder $q): Builder
    {
        return $q->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer'))
            ->where(fn ($q) => $q->whereNull('transfer_pair_id')->orWhereHas('allocations'));
    }

    /**
     * Rows with a part that is spending or income: all of an undecided row that isn't paired, and the cost or income
     * in a split with a transfer (a hotel paid together with a top-up). For finding a payment (a stay's, a job's),
     * never for totals of whole rows.
     */
    public static function scopeWithSpendingLines(Builder $q): Builder
    {
        return $q->where(fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('kind', '!=', 'transfer'))
            ->orWhere(fn ($q) => $q->whereDoesntHave('allocations')->whereNull('transfer_pair_id')));
    }

    /** The same rule as scopeTransfers(), for a row in hand: some or all of it is money moving between your accounts. */
    public static function isTransfer(BankTransaction $tx): bool
    {
        $tx->loadMissing('allocations');

        return $tx->allocations->contains('kind', 'transfer') || ($tx->transfer_pair_id && $tx->allocations->isEmpty());
    }

    /**
     * SQL for the part of a row that is a transfer, for totals worked out line by line: a split of -£600 into a
     * -£500 transfer and a -£100 cost counts £500 as moved (and spendingPenceSql() £100 as spending).
     */
    public static function transferPenceSql(string $table = 'bank_transactions'): string
    {
        return "(CASE WHEN EXISTS (SELECT 1 FROM allocations a0 WHERE a0.bank_transaction_id = {$table}.id AND a0.voided_at IS NULL)"
            ." THEN COALESCE((SELECT SUM(a1.amount_pence) FROM allocations a1 WHERE a1.bank_transaction_id = {$table}.id AND a1.voided_at IS NULL AND a1.kind = 'transfer'), 0)"
            ." WHEN {$table}.transfer_pair_id IS NOT NULL THEN {$table}.amount_pence ELSE 0 END)";
    }

    /** SQL for the part of a row that is spending or income (all of it, less its transfer part): see transferPenceSql(). */
    public static function spendingPenceSql(string $table = 'bank_transactions'): string
    {
        return "({$table}.amount_pence - ".self::transferPenceSql($table).')';
    }

    /**
     * Spending, income and money moved between your own accounts for these rows, worked out line by line (see
     * transferPenceSql()): only the transfer part of a split counts as moved, and the rest as spending
     * or income.
     *
     * @return array{count:int, out_pence:int, in_pence:int, transfer_count:int, transfer_out_pence:int, transfer_in_pence:int}
     */
    public static function lineTotals(Builder $rows): array
    {
        $t = self::transferPenceSql();
        $r = $rows->toBase()->selectRaw("COUNT(*) as n, SUM(CASE WHEN amount_pence < 0 THEN amount_pence - {$t} ELSE 0 END) as out_pence,"
            ." SUM(CASE WHEN amount_pence > 0 THEN amount_pence - {$t} ELSE 0 END) as in_pence, SUM(CASE WHEN {$t} <> 0 THEN 1 ELSE 0 END) as moved_n,"
            ." SUM(CASE WHEN amount_pence < 0 THEN {$t} ELSE 0 END) as moved_out, SUM(CASE WHEN amount_pence > 0 THEN {$t} ELSE 0 END) as moved_in")->first();

        return ['count' => (int) ($r->n ?? 0), 'out_pence' => (int) ($r->out_pence ?? 0), 'in_pence' => (int) ($r->in_pence ?? 0),
            'transfer_count' => (int) ($r->moved_n ?? 0), 'transfer_out_pence' => (int) ($r->moved_out ?? 0), 'transfer_in_pence' => (int) ($r->moved_in ?? 0)];
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
