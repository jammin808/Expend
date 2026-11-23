<?php

namespace App\Domain\Travel;

use App\Domain\Ai\AiClient;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Grounding;
use App\Domain\Gmail\GmailScanner;
use App\Models\Document;
use App\Models\TravelItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Turns a booking or receipt email into stays and journeys.
 *
 * First choice is the structured booking data (schema.org markup) that booking sites embed for Gmail's
 * booking cards: exact, and no AI needed. Otherwise Claude reads the email, and every date, amount and
 * reference must come with a quote that's checked against the email's own text. Anything that can't be
 * checked is marked for you; nothing here decides anything.
 */
final class TravelReader
{
    private const KIND_BY_TYPE = [
        'LodgingReservation' => 'hotel', 'TrainReservation' => 'rail', 'BusReservation' => 'coach', 'FlightReservation' => 'flight',
        'RentalCarReservation' => 'car_hire', 'TaxiReservation' => 'taxi',
    ];

    private const PROVIDERS = [
        'booking.com' => 'Booking.com', 'hotels.com' => 'Hotels.com', 'premierinn.com' => 'Premier Inn', 'travelodge.co.uk' => 'Travelodge',
        'ihg.com' => 'IHG', 'expedia.com' => 'Expedia', 'airbnb.com' => 'Airbnb', 'uber.com' => 'Uber', 'bolt.eu' => 'Bolt',
        'free-now.com' => 'FREENOW', 'addisonlee.com' => 'Addison Lee', 'trainline.com' => 'Trainline', 'lner.co.uk' => 'LNER', 'gwr.com' => 'GWR',
        'avantiwestcoast.co.uk' => 'Avanti West Coast', 'nationalexpress.com' => 'National Express', 'easyjet.com' => 'easyJet', 'ba.com' => 'British Airways',
        'ryanair.com' => 'Ryanair', 'enterprise.co.uk' => 'Enterprise', 'enterprise.com' => 'Enterprise', 'hertz.co.uk' => 'Hertz', 'hertz.com' => 'Hertz',
        'avis.co.uk' => 'Avis', 'europcar.co.uk' => 'Europcar', 'sixt.co.uk' => 'Sixt', 'zipcar.com' => 'Zipcar', 'justpark.com' => 'JustPark',
        'ringgo.co.uk' => 'RingGo', 'paybyphone.co.uk' => 'PayByPhone', 'ncp.co.uk' => 'NCP',
    ];

    public function __construct(private AiClient $ai, private TravelMatcher $matcher) {}

    /**
     * @return list<TravelItem>|null the stays and journeys found (empty if it isn't a booking), or null if
     *                               it needs AI to read and AI is off
     */
    public function read(Document $document): ?array
    {
        $raw = (string) Storage::disk('local')->get($document->stored_path);
        $html = (string) (new MailMimeParser)->parse($raw, false)->getHtmlContent();
        $provider = self::providerFrom((string) $document->email_from);

        $found = self::fromStructuredData($html, $provider);
        if (! $found) {
            if (! self::looksLikeBooking((string) $document->text)) {
                return [];
            }
            if (! GmailScanner::canUseAi()) {
                return null;
            }
            $found = $this->fromAi($document, $provider);
        }

        $items = [];
        foreach ($found as $data) {
            $items[] = $this->save($data, $document);
        }

        return $items;
    }

    /** Cheap check before spending anything on AI: marketing emails from the same senders are skipped. */
    public static function looksLikeBooking(string $text): bool
    {
        return (bool) preg_match('/check[- ]?in|check[- ]?out|(booking|reservation|confirmation|order) (number|no\.?|reference|ref|code)|your\b.{0,40}\b(trip|journey|ride|ticket|e-?ticket|receipt|booking|reservation|rental|parking)|receipt|itinerary|\b(pick-?up|drop-?off)\b|\bfare\b|thanks for (riding|travelling|traveling|booking|choosing)|\btotal\b[^\n]{0,30}[£$€]\s?\d|[£$€]\s?\d[\d,]*\.\d\d[^\n]{0,15}\b(total|paid|charged)\b/iu', $text)
            && (bool) preg_match('/\d{1,2}(st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)|\b\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\b|\b20\d\d-\d\d-\d\d\b|(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2}\b/i', $text);
    }

    public static function providerFrom(string $from): string
    {
        $address = strtolower(preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from);
        $domain = substr(strrchr($address, '@') ?: '', 1);
        foreach (self::PROVIDERS as $suffix => $name) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $name;
            }
        }
        $display = trim(preg_replace('/<[^>]*>/', '', $from), " \"'");

        return mb_substr($display !== '' ? $display : ($domain ?: 'Unknown'), 0, 120);
    }

    /** @return list<array> */
    public static function fromStructuredData(string $html, string $provider): array
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        $nodes = [];
        foreach ($m[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true);
            if (is_array($data)) {
                $nodes = array_merge($nodes, self::flatten($data));
            }
        }

        $items = [];
        foreach ($nodes as $node) {
            $type = self::type($node);
            if (! isset(self::KIND_BY_TYPE[$type])) {
                continue;
            }
            $kind = self::KIND_BY_TYPE[$type];
            $for = (array) ($node['reservationFor'] ?? []);
            $currency = null;
            [$start, $end, $place, $destination, $postcode] = match ($kind) {
                'hotel' => [$node['checkinDate'] ?? $node['checkinTime'] ?? null, $node['checkoutDate'] ?? $node['checkoutTime'] ?? null,
                    self::join([$for['name'] ?? null, $for['address']['addressLocality'] ?? null]), null, $for['address']['postalCode'] ?? null],
                'rail', 'coach' => [$for['departureTime'] ?? null, null, $for['departureStation']['name'] ?? $for['departureBusStop']['name'] ?? null,
                    $for['arrivalStation']['name'] ?? $for['arrivalBusStop']['name'] ?? null, null],
                'flight' => [$for['departureTime'] ?? null, null, $for['departureAirport']['name'] ?? $for['departureAirport']['iataCode'] ?? null,
                    $for['arrivalAirport']['name'] ?? $for['arrivalAirport']['iataCode'] ?? null, null],
                'car_hire' => [$node['pickupTime'] ?? null, $node['dropoffTime'] ?? null, $node['pickupLocation']['name'] ?? null, null,
                    $node['pickupLocation']['address']['postalCode'] ?? null],
                default => [$node['pickupTime'] ?? null, null, $node['pickupLocation']['name'] ?? null, null, null],
            };
            $startsOn = self::isoDate($start);
            if (! $startsOn) {
                continue;
            }
            $price = $node['totalPrice'] ?? $node['price'] ?? null;
            if (is_array($price)) {
                $currency = $price['priceCurrency'] ?? $price['currency'] ?? null;
                $price = $price['price'] ?? $price['value'] ?? null;
            }
            $status = str_contains(strtolower((string) ($node['reservationStatus'] ?? '')), 'cancel') ? 'cancelled' : 'booked';
            $structured = ['quote' => null, 'verified' => true, 'note' => 'From the booking data in the email'];

            $items[] = [
                'kind' => $kind,
                'provider' => (string) ($node['provider']['name'] ?? $node['broker']['name'] ?? $for['airline']['name'] ?? $provider),
                'reference' => isset($node['reservationNumber']) ? (string) $node['reservationNumber'] : null,
                'status' => $status,
                'starts_on' => $startsOn,
                'ends_on' => self::isoDate($end),
                'start_time' => self::isoTime($start),
                'place' => $place, 'destination' => $destination, 'postcode' => self::postcode($postcode),
                'amount_minor' => self::minor($price),
                'currency' => self::currency($currency ?? $node['priceCurrency'] ?? null),
                'payment' => 'unknown',
                'booked_for' => $node['underName']['name'] ?? null,
                'booker' => null,
                'source' => 'structured_data',
                'evidence' => ['starts_on' => $structured, 'ends_on' => $structured, 'amount' => $structured, 'reference' => $structured, 'place' => $structured],
                'unverified_count' => 0,
            ];
        }

        return $items;
    }

    /** @return list<array> */
    private function fromAi(Document $document, string $provider): array
    {
        $text = mb_substr((string) $document->text, 0, 60_000);
        $result = $this->ai->send([
            'maxTokens' => 8000,
            'system' => self::system(),
            'messages' => [['role' => 'user', 'content' => "The email was sent by {$document->email_from} on ".($document->received_at?->format('Y-m-d') ?? 'an unknown date')
                .".\nList every hotel stay or journey it confirms, changes, cancels or gives a receipt for. If it's marketing or anything else, return an empty list.\n\n<email>\n{$text}\n</email>"]],
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ]);
        $data = json_decode($result->text(), true);

        $items = [];
        foreach ((array) ($data['items'] ?? []) as $row) {
            $startsOn = self::isoDate($row['starts_on'] ?? null);
            if (! $startsOn || ! isset(TravelItem::KINDS[$row['kind'] ?? ''])) {
                continue;
            }
            $quotes = (array) ($row['quotes'] ?? []);
            $amount = is_int($row['amount_minor'] ?? null) ? $row['amount_minor'] : null;
            $evidence = [
                'starts_on' => self::checkDate($text, $quotes['starts_on'] ?? null, $startsOn),
                'ends_on' => ($endsOn = self::isoDate($row['ends_on'] ?? null)) ? self::checkDate($text, $quotes['ends_on'] ?? null, $endsOn) : null,
                'amount' => $amount !== null ? Grounding::check($text, 'extracted', (string) ($quotes['amount'] ?? ''), $amount) + ['quote' => $quotes['amount'] ?? null] : null,
                'reference' => ($row['reference'] ?? null) ? self::checkContains($text, $quotes['reference'] ?? null, (string) $row['reference']) : null,
                'place' => ($row['place'] ?? null) ? self::checkContains($text, $quotes['place'] ?? null, null) : null,
                'payment' => ($row['payment'] ?? 'unknown') !== 'unknown' ? self::checkContains($text, $quotes['payment'] ?? null, null) : null,
            ];
            $evidence = array_filter($evidence);

            $items[] = [
                'kind' => $row['kind'],
                'provider' => mb_substr((string) (($row['provider'] ?? null) ?: $provider), 0, 120),
                'reference' => ($row['reference'] ?? null) && $evidence['reference']['verified'] ? mb_substr((string) $row['reference'], 0, 120) : null,
                'status' => ($row['status'] ?? '') === 'cancelled' ? 'cancelled' : 'booked',
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'start_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($row['start_time'] ?? '')) ? $row['start_time'] : null,
                'place' => self::clip($row['place'] ?? null), 'destination' => self::clip($row['destination'] ?? null),
                'postcode' => self::postcode($row['postcode'] ?? null),
                'amount_minor' => $amount,
                'currency' => self::currency($row['currency'] ?? null),
                // "Paid by someone else" is only believed when the email's own words say so.
                'payment' => in_array($row['payment'] ?? '', ['paid', 'pay_at_property', 'paid_by_other'], true) && ($evidence['payment']['verified'] ?? false) ? $row['payment'] : 'unknown',
                'booked_for' => self::clip($row['booked_for'] ?? null, 200),
                'booker' => self::clip($row['booker'] ?? null, 200),
                'source' => 'ai',
                'evidence' => $evidence,
                'unverified_count' => count(array_filter($evidence, fn ($e) => ! $e['verified'])),
            ];
        }

        return $items;
    }

    /** One record per booking: later emails (amendments, receipts, cancellations) update the same stay. */
    private function save(array $data, Document $document): TravelItem
    {
        $existing = $data['reference']
            ? TravelItem::query()->where('provider', $data['provider'])->where('reference', $data['reference'])->first()
            : TravelItem::query()->where('kind', $data['kind'])->where('provider', $data['provider'])->whereDate('starts_on', $data['starts_on'])
                ->where(fn ($q) => $q->where('amount_minor', $data['amount_minor'])->orWhereNull('amount_minor'))->first();

        if ($existing) {
            // A cancellation only changes the status. Once you've decided a stay, later emails don't
            // rewrite its details either; otherwise the newest email's details win.
            $existing->update($data['status'] === 'cancelled' || $existing->decided_at
                ? ['status' => $data['status'] === 'cancelled' ? 'cancelled' : $existing->status]
                : array_filter($data, fn ($v) => $v !== null) + ['document_id' => $document->id]);
            $item = $existing->fresh();
        } else {
            $item = TravelItem::query()->create($data + ['document_id' => $document->id]);
            AuditLog::record('travel.found', $item, null, ['kind' => $item->kind, 'provider' => $item->provider, 'starts_on' => $data['starts_on']]);
        }

        $this->matcher->suggest($item);

        return $item->fresh();
    }

    private static function system(): string
    {
        return <<<'TXT'
You read booking confirmations, tickets and receipts for a UK self-employed freelancer, to list their hotel stays and journeys. Accuracy matters more than completeness.

Rules:
- Only what the email states. Never infer or fill gaps; use null when it isn't stated.
- Each quote is copied exactly, character for character, from the email: the shortest passage containing the value.
- Dates as YYYY-MM-DD. For a hotel, starts_on is check-in and ends_on is check-out. For a journey, starts_on is the travel date.
- amount_minor is the total in minor units (pence or cents) in the email's currency, with currency as a 3-letter code.
- payment: "paid" if the email says it's paid or charged; "pay_at_property" if it says you pay at the hotel; "paid_by_other" only if it says a company or someone else paid or is billed; otherwise "unknown".
- booker: the company that made or pays for the booking, only if the email names one.
- Food delivery, promotions and account emails are not journeys: return an empty list.
TXT;
    }

    public static function schema(): array
    {
        $s = ['type' => ['string', 'null']];
        $object = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return $object(['items' => ['type' => 'array', 'items' => $object([
            'kind' => ['type' => 'string', 'enum' => array_keys(TravelItem::KINDS)],
            'provider' => $s, 'reference' => $s,
            'status' => ['type' => 'string', 'enum' => ['booked', 'amended', 'cancelled', 'receipt']],
            'starts_on' => $s, 'ends_on' => $s, 'start_time' => $s, 'place' => $s, 'destination' => $s, 'postcode' => $s,
            'amount_minor' => ['type' => ['integer', 'null']], 'currency' => $s,
            'payment' => ['type' => 'string', 'enum' => ['paid', 'pay_at_property', 'paid_by_other', 'unknown']],
            'booked_for' => $s, 'booker' => $s,
            'quotes' => $object(['starts_on' => $s, 'ends_on' => $s, 'amount' => $s, 'reference' => $s, 'place' => $s, 'payment' => $s]),
        ])]]);
    }

    private static function checkDate(string $text, ?string $quote, string $date): array
    {
        if (! $quote || ! Grounding::quoteFound($text, $quote)) {
            return ['quote' => $quote, 'verified' => false, 'note' => 'The quoted words weren’t found in the email.'];
        }

        return Grounding::dateInQuote($date, $quote)
            ? ['quote' => $quote, 'verified' => true, 'note' => null]
            : ['quote' => $quote, 'verified' => false, 'note' => 'The date isn’t in the quoted words.'];
    }

    private static function checkContains(string $text, ?string $quote, ?string $value): array
    {
        $ok = $quote && Grounding::quoteFound($text, $quote) && ($value === null || Grounding::quoteFound($quote, $value));

        return ['quote' => $quote, 'verified' => (bool) $ok, 'note' => $ok ? null : 'Couldn’t be checked against the email.'];
    }

    private static function flatten(array $data): array
    {
        if (array_is_list($data)) {
            return array_merge(...array_map(fn ($d) => is_array($d) ? self::flatten($d) : [], $data ?: [[]]));
        }
        $out = [$data];
        foreach (['@graph', 'subReservation'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $out = array_merge($out, self::flatten(array_is_list($data[$key]) ? $data[$key] : [$data[$key]]));
            }
        }

        return $out;
    }

    private static function type(array $node): string
    {
        $type = $node['@type'] ?? '';

        return (string) (is_array($type) ? ($type[0] ?? '') : preg_replace('#^https?://schema\.org/#', '', (string) $type));
    }

    private static function isoDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse(substr($value, 0, 10))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private static function isoTime(mixed $value): ?string
    {
        return is_string($value) && preg_match('/T(\d{2}:\d{2})/', $value, $m) ? $m[1] : null;
    }

    private static function minor(mixed $price): ?int
    {
        if (! is_numeric($price) && ! (is_string($price) && is_numeric(str_replace(',', '', $price)))) {
            return null;
        }

        return BigDecimal::of(str_replace(',', '', (string) $price))->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    private static function currency(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/', strtoupper($value)) ? strtoupper($value) : 'GBP';
    }

    private static function postcode(mixed $value): ?string
    {
        $pc = strtoupper(preg_replace('/\s+/', '', (string) $value));

        return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $pc) ? substr($pc, 0, -3).' '.substr($pc, -3) : null;
    }

    private static function join(array $parts): ?string
    {
        $parts = array_values(array_unique(array_filter(array_map(fn ($p) => is_string($p) ? trim($p) : null, $parts))));

        return $parts ? mb_substr(implode(', ', $parts), 0, 300) : null;
    }

    private static function clip(mixed $value, int $max = 300): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
