<?php

namespace App\Domain\Banks;

use App\Domain\Tax\TaxPot;
use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Your Starling Spaces (savings goals and spending spaces), read with your token so one can be chosen as the tax pot
 * and its balance shown against what's needed by 31 January (TaxPot). It only reads: nothing is moved or changed.
 * Starling's GET account/{accountUid}/spaces needs the token's space:read permission; a token without it is told so
 * (StarlingScopeMissing), and everything else carries on without the pot.
 */
final class StarlingSpaces
{
    /** What the token needs, in the words Starling's developer portal uses. */
    public const SCOPES = 'space:read and balance:read';

    /**
     * Every Space on the connected accounts, with its balance.
     *
     * @return list<array{key: string, account_uid: string, kind: string, uid: string, name: string, pence: int, currency: string}>
     *
     * @throws StarlingScopeMissing when the token can't read Spaces
     */
    public function all(): array
    {
        $token = (string) Setting::get('starling_token');
        if ($token === '') {
            throw new RuntimeException('Starling isn’t connected.');
        }
        $out = [];
        foreach (array_keys((array) Setting::get('starling_accounts')) as $accountUid) {
            $response = $this->get($token, 'account/'.rawurlencode((string) $accountUid).'/spaces');
            foreach ((array) $response->json('savingsGoals') as $g) {
                if (is_array($g) && ! empty($g['savingsGoalUid'])) {
                    $out[] = self::row((string) $accountUid, 'goal', (string) $g['savingsGoalUid'], (string) ($g['name'] ?? 'Savings goal'), $g['totalSaved'] ?? null);
                }
            }
            foreach ((array) $response->json('spendingSpaces') as $s) {
                if (is_array($s) && ! empty($s['spaceUid'])) {
                    $out[] = self::row((string) $accountUid, 'space', (string) $s['spaceUid'], (string) ($s['name'] ?? 'Space'), $s['balance'] ?? null);
                }
            }
        }

        return $out;
    }

    /**
     * Read the chosen pot's balance and keep it (settings tax_pot_balance) for the dashboard and the import message.
     * Never throws: a token without the permission, a Space that's gone or Starling not answering is kept as such.
     *
     * @return array|null what was kept, or null with no pot chosen
     */
    public function refresh(): ?array
    {
        $space = Setting::get(TaxPot::SPACE, null);
        if (! is_array($space) || empty($space['uid']) || ! Setting::get('starling_token')) {
            return null;
        }
        try {
            $found = collect($this->all())->first(fn ($s) => $s['uid'] === $space['uid'] && $s['account_uid'] === ($space['account_uid'] ?? $s['account_uid']));
            $kept = $found
                ? ['ok' => true, 'pence' => $found['pence'], 'currency' => $found['currency'], 'name' => $found['name'], 'at' => now()->toIso8601String()]
                : ['ok' => false, 'error' => 'missing', 'at' => now()->toIso8601String()];
        } catch (StarlingScopeMissing) {
            $kept = ['ok' => false, 'error' => 'scope', 'at' => now()->toIso8601String()];
        } catch (Throwable $e) {
            report($e);
            $kept = ['ok' => false, 'error' => 'failed', 'at' => now()->toIso8601String()];
        }
        Setting::put(TaxPot::BALANCE, $kept);

        return $kept;
    }

    private function get(string $token, string $path): Response
    {
        $response = Http::withToken($token)->acceptJson()->timeout(20)->get(StarlingSync::API.$path);
        if (in_array($response->status(), [401, 403], true)) {
            throw new StarlingScopeMissing;
        }
        if ($response->failed()) {
            throw new RuntimeException('Starling couldn’t list your Spaces (HTTP '.$response->status().').');
        }

        return $response;
    }

    /** @return array{key: string, account_uid: string, kind: string, uid: string, name: string, pence: int, currency: string} */
    private static function row(string $accountUid, string $kind, string $uid, string $name, mixed $amount): array
    {
        return [
            'key' => $accountUid.'|'.$kind.'|'.$uid, 'account_uid' => $accountUid, 'kind' => $kind, 'uid' => $uid, 'name' => mb_substr($name, 0, 80),
            'pence' => (int) (is_array($amount) ? ($amount['minorUnits'] ?? 0) : 0), 'currency' => (string) (is_array($amount) ? ($amount['currency'] ?? 'GBP') : 'GBP'),
        ];
    }
}
