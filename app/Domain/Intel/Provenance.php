<?php

namespace App\Domain\Intel;

use App\Models\Allocation;
use App\Models\LinkProvenance;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Why an automatic writer proposed what it did (docs/intelligence-cycle.md 4): the registered method (Methods) and
 * its version and basis, the method's own score, the evidence it relied on as refs (tx:381, inv:12, rule:4), the
 * runner-ups it weighed ({ref, score}), and the cycle run. Stored with the allocation it wrote, or, for a link that
 * isn't an allocation, in link_provenance with the link.
 */
final class Provenance
{
    /** @var list<string> */
    public readonly array $evidence;

    /** @var list<array{ref:string, score:?int}> */
    public readonly array $alternatives;

    public readonly int $version;

    public readonly string $basis;

    public readonly ?int $runId;

    /**
     * @param  iterable<?string>  $evidence  refs; invalid ones and repeats are dropped, at most 20 kept
     * @param  iterable<array{ref:?string, score?:?int}>  $alternatives  the runner-ups, best first; at most 5 kept
     */
    public function __construct(
        public readonly string $method,
        iterable $evidence = [],
        public readonly ?int $score = null,
        iterable $alternatives = [],
        ?int $version = null,
        ?string $basis = null,
        ?int $runId = null,
    ) {
        if (! Methods::has($method)) {
            throw new InvalidArgumentException("Unknown method “{$method}”: register it in App\\Domain\\Intel\\Methods.");
        }
        $basis ??= Methods::basis($method);
        if (! isset(Methods::BASES[$basis])) {
            throw new InvalidArgumentException("Unknown basis “{$basis}”.");
        }
        $this->version = $version ?? Methods::version($method);
        $this->basis = $basis;
        $this->runId = $runId ?? AuditContext::runId();
        $this->evidence = Ref::list($evidence);
        $alts = [];
        foreach ($alternatives as $alt) {
            $ref = $alt['ref'] ?? null;
            if ($ref !== null && Ref::valid($ref) && ! in_array($ref, array_column($alts, 'ref'), true) && count($alts) < 5) {
                $alts[] = ['ref' => $ref, 'score' => isset($alt['score']) ? (int) $alt['score'] : null];
            }
        }
        $this->alternatives = $alts;
    }

    /** The columns an allocation stores it in. */
    public function columns(): array
    {
        return [
            'method' => $this->method, 'method_version' => $this->version, 'basis' => $this->basis, 'score' => self::fitScore($this->score),
            'evidence' => $this->evidence ?: null, 'alternatives' => $this->alternatives ?: null, 'run_id' => $this->runId,
        ];
    }

    /** As stored with an allocation, or null for a line with no method stored (yours, or written before methods were). */
    public static function fromAllocation(Allocation $a): ?self
    {
        if ($a->method === null || ! Methods::has($a->method)) {
            return null;
        }

        return new self($a->method, $a->evidence ?? [], $a->score, $a->alternatives ?? [], $a->method_version, $a->basis, $a->run_id);
    }

    /**
     * The provenance of lines being written again with a small change (the job taken off, the wording updated): the
     * one they carry, or, for lines from before methods were stored, the method their maker and reason show.
     *
     * @param  Collection<int, Allocation>  $allocations
     */
    public static function keep(Collection $allocations): ?self
    {
        $first = $allocations->first();
        if (! $first instanceof Allocation) {
            return null;
        }
        if ($kept = self::fromAllocation($first)) {
            return $kept;
        }
        $method = Methods::of($first)['method'];

        return $method ? new self($method) : null;
    }

    /**
     * Write (or replace) the why of a link that isn't an allocation. Call it in the same transaction as the link: it
     * describes the link as $objectRef says it is, and counts only while the link still says so.
     */
    public function recordLink(string $link, string $subjectRef, ?string $objectRef): LinkProvenance
    {
        if (! in_array($link, LinkProvenance::LINKS, true)) {
            throw new InvalidArgumentException("Unknown link “{$link}”.");
        }
        if (! Ref::valid($subjectRef) || ($objectRef !== null && ! Ref::valid($objectRef))) {
            throw new InvalidArgumentException('A link’s subject and object are refs (cal:…, job:14).');
        }

        return LinkProvenance::query()->updateOrCreate(['link' => $link, 'subject_ref' => $subjectRef], [
            'object_ref' => $objectRef, 'method' => $this->method, 'method_version' => $this->version, 'basis' => $this->basis,
            'score' => self::fitScore($this->score), 'evidence' => $this->evidence ?: null, 'alternatives' => $this->alternatives ?: null,
            'run_id' => $this->runId, 'correlation' => AuditContext::correlation(),
        ]);
    }

    /** A score kept within the column (smallint). */
    private static function fitScore(?int $score): ?int
    {
        return $score === null ? null : max(-32768, min(32767, $score));
    }
}
