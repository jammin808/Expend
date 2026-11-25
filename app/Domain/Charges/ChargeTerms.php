<?php

namespace App\Domain\Charges;

use App\Domain\Clients\ClientIds;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\WorkJob;
use Illuminate\Support\Collection;

/**
 * How a job's costs are charged, kind by kind: the job's own row wins; otherwise the paying client's usual (a client
 * merged into another counts as the one it went into); otherwise there's none. A job's row changes only its own kind
 * of cost, so arrangements mix: trains and taxis at cost, driving at 45p a mile, hotels not charged.
 *
 * Read only: only you write arrangements (ChargeTermsWriter). Never the end client's: the paying client is who you
 * invoice.
 */
final class ChargeTerms
{
    /**
     * Each kind of cost for this job: its charge (null with no arrangement), rate, currency, and where it came from.
     *
     * @return array<string, array{charge:?string, rate_minor:?int, currency:string, from:string}> by kind of cost
     */
    public static function forJob(WorkJob $job): array
    {
        return self::forJobs([$job])[(int) $job->id];
    }

    /**
     * Several jobs at once, with two queries.
     *
     * @param  iterable<WorkJob>  $jobs
     * @return array<int, array<string, array{charge:?string, rate_minor:?int, currency:string, from:string}>> by job id
     */
    public static function forJobs(iterable $jobs): array
    {
        $jobs = collect($jobs)->keyBy('id');
        if ($jobs->isEmpty()) {
            return [];
        }
        $clients = ClientIds::resolveMany($jobs->pluck('client_id'));
        $rows = collect();
        foreach ($jobs->keys()->chunk(500) as $chunk) {
            $rows = $rows->concat(ChargeTerm::query()->whereIn('work_job_id', $chunk->values()->all())->get());
        }
        $byClient = self::rowsOf(array_values(array_unique($clients)));
        $byJob = $rows->groupBy('work_job_id');
        $out = [];
        foreach ($jobs as $id => $job) {
            $client = $job->client_id === null ? null : ($clients[(int) $job->client_id] ?? null);
            $own = ($byJob[$id] ?? collect())->keyBy('class');
            $usual = $client === null ? collect() : ($byClient[$client] ?? collect());
            $terms = [];
            foreach (array_keys(CostClass::CLASSES) as $class) {
                $terms[$class] = isset($own[$class]) ? self::term($own[$class], 'job')
                    : (isset($usual[$class]) ? self::term($usual[$class], 'client') : self::none());
            }
            $out[(int) $id] = $terms;
        }

        return $out;
    }

    /**
     * A client's usual arrangement (merges resolved), kind by kind.
     *
     * @return array<string, array{charge:?string, rate_minor:?int, currency:string, from:string}>
     */
    public static function forClient(Client|int $client): array
    {
        $id = ClientIds::resolve($client instanceof Client ? (int) $client->id : $client);
        $usual = $id === null ? collect() : (self::rowsOf([$id])[$id] ?? collect());
        $terms = [];
        foreach (array_keys(CostClass::CLASSES) as $class) {
            $terms[$class] = isset($usual[$class]) ? self::term($usual[$class], 'client') : self::none();
        }

        return $terms;
    }

    /** Whether any kind of cost has an arrangement (the job's or the client's). */
    public static function any(array $terms): bool
    {
        return collect($terms)->contains(fn (array $t) => $t['from'] !== 'none');
    }

    /**
     * A job or client's own rows as they're stored, kind by kind (for the editor, and for a save tied to what it
     * showed).
     *
     * @return array<string, array{charge:string, rate_minor:?int, currency:string}>
     */
    public static function own(Client|WorkJob $owner): array
    {
        return ChargeTerm::query()->ownedBy($owner)->orderBy('class')->get()
            ->mapWithKeys(fn (ChargeTerm $t) => [$t->class => ['charge' => $t->charge, 'rate_minor' => $t->rate_minor, 'currency' => strtoupper($t->currency ?: 'GBP')]])
            ->sortKeys()->all();
    }

    /** @return array<int, Collection<string, ChargeTerm>> by client id, then kind of cost */
    private static function rowsOf(array $clientIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_filter($clientIds, fn ($id) => $id !== null)), 500) as $chunk) {
            foreach (ChargeTerm::query()->whereIn('client_id', $chunk)->whereNull('work_job_id')->get()->groupBy('client_id') as $client => $rows) {
                $out[(int) $client] = $rows->keyBy('class');
            }
        }

        return $out;
    }

    private static function term(ChargeTerm $t, string $from): array
    {
        return ['charge' => $t->charge, 'rate_minor' => $t->rate_minor, 'currency' => strtoupper($t->currency ?: 'GBP'), 'from' => $from];
    }

    private static function none(): array
    {
        return ['charge' => null, 'rate_minor' => null, 'currency' => 'GBP', 'from' => 'none'];
    }
}
