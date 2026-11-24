<?php

namespace App\Domain\Ai;

use App\Domain\Documents\AiConfig;
use App\Domain\Documents\Grounding;
use App\Domain\Tax\TaxLaw;
use App\Models\AssistantLog;
use App\Models\Setting;
use RuntimeException;

/**
 * The chat assistant: a bookkeeper and tax adviser for a UK sole trader. It answers from the app's records through
 * AssistantTools, from a library of checked tax law (TaxLaw), and, for anything newer, from official sites only
 * (legislation, GOV.UK and HMRC manuals, published judgments) through Claude's web search and fetch.
 *
 * The app then checks the answer: every money figure against what the tools returned, every link against the
 * library and the pages actually read, and every case name against the checked library. Anything that can't be
 * matched is listed under the answer rather than silently trusted.
 */
final class Assistant
{
    private const MAX_ROUNDS = 12;

    /** The only sites the assistant may search or read: the law itself, HMRC's guidance and the courts. */
    public const OFFICIAL_DOMAINS = [
        'legislation.gov.uk', 'www.gov.uk', 'caselaw.nationalarchives.gov.uk', 'bailii.org', 'supremecourt.uk',
        'financeandtax.decisions.tribunals.gov.uk', 'assets.publishing.service.gov.uk', 'judiciary.uk',
    ];

    /** Server-side web tools, limited to official sources (null when switched off in Settings › AI). */
    public static function webTools(): array
    {
        if (Setting::get('ai_web_research') === false) {
            return [];
        }

        return [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'allowedDomains' => self::OFFICIAL_DOMAINS, 'maxUses' => 5],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'allowedDomains' => self::OFFICIAL_DOMAINS, 'maxUses' => 5, 'maxContentTokens' => 40_000],
        ];
    }

    public function __construct(private AiClient $ai, private AssistantTools $tools) {}

    /**
     * @param  list<array{question:string, answer:string}>  $history
     * @return array{answer:string, unmatched:list<string>, tools:list<array>, log_id:int}
     */
    public function ask(string $question, string $channel = 'app', array $history = []): array
    {
        if (! AiConfig::ready()) {
            throw new RuntimeException('Add your Claude API key in Settings › AI to use the assistant.');
        }

        $messages = [];
        foreach (array_slice($history, -6) as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['question']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $calls = [];
        $toolOutputs = [];
        $sources = [];
        $in = $out = $searches = 0;
        $model = AiConfig::model();
        $tools = [...AssistantTools::definitions(), ...self::webTools()];
        $webUnavailable = false;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $result = $this->ai->send([
                'maxTokens' => 16000,
                'system' => $this->system($channel),
                'tools' => $tools,
                'messages' => $messages,
                'outputConfig' => ['effort' => AiConfig::effort()],
            ]);
            $in += $result->inputTokens;
            $out += $result->outputTokens;
            $searches += $result->webSearches;
            $model = $result->model;
            foreach ($result->sources as $source) {
                $sources[$source['url']] ??= $source;
            }
            foreach ($result->blocks as $b) {
                if ($b['type'] === 'server_tool_use') {
                    $calls[] = ['tool' => $b['name'], 'input' => $b['input'] ?? []];
                }
            }
            if ($result->webUnavailable) {
                // The account has web search off: stop offering it for the rest of this question.
                $webUnavailable = true;
                $tools = AssistantTools::definitions();
            }

            if ($result->stopReason === 'pause_turn') {
                // A long web search paused on the server: send the conversation back as it is and it carries on.
                $messages[] = ['role' => 'assistant', 'content' => $result->assistantContent];

                continue;
            }
            if ($result->stopReason !== 'tool_use') {
                break;
            }

            $results = [];
            foreach ($result->toolUses() as $use) {
                try {
                    $output = $this->tools->run($use['name'], $use['input']);
                } catch (\Throwable $e) {
                    $output = ['error' => $e->getMessage()];
                }
                $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $toolOutputs[] = $json;
                $calls[] = ['tool' => $use['name'], 'input' => $use['input']];
                $results[] = ['type' => 'tool_result', 'toolUseID' => $use['id'], 'content' => mb_substr($json, 0, 60_000)];
            }
            $messages[] = ['role' => 'assistant', 'content' => $result->assistantContent];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $sources = array_values(array_filter($sources, fn ($src) => self::isOfficial($src['url'])));
        $answer = $result->text() ?: 'I couldn’t finish that. Try asking in a narrower way.';
        $answer .= self::sourcesFooter($answer, $result->blocks, $sources);
        $known = [...$toolOutputs, ...array_map(fn ($src) => $src['url'], $sources)];
        $unmatched = [
            ...self::unmatchedFigures($answer, $toolOutputs),
            ...self::unmatchedLinks($answer, $known),
            ...self::unknownCases($answer, $sources),
        ];
        if ($webUnavailable) {
            $unmatched[] = 'web search is switched off for this API key, so this answer uses the built-in law library only';
        }

        $log = AssistantLog::query()->create([
            'channel' => $channel, 'question' => $question, 'answer' => $answer, 'tool_calls' => $calls,
            'unmatched_figures' => $unmatched, 'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out,
        ]);

        return ['answer' => $answer, 'unmatched' => $unmatched, 'tools' => $calls, 'log_id' => $log->id, 'sources' => $sources, 'web_searches' => $searches];
    }

    public static function isOfficial(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::OFFICIAL_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain) || ($domain === 'www.gov.uk' && $host === 'gov.uk')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pages the answer cited (through web citations) but didn't link in its text, listed under it so they can be opened.
     *
     * @param  list<array>  $blocks
     * @param  list<array{url:string,title:string}>  $sources
     */
    public static function sourcesFooter(string $answer, array $blocks, array $sources): string
    {
        $cited = [];
        foreach ($blocks as $b) {
            foreach ($b['citations'] ?? [] as $url) {
                $cited[$url] = true;
            }
        }
        $lines = [];
        foreach ($sources as $src) {
            if (isset($cited[$src['url']]) && ! str_contains($answer, $src['url'])) {
                $lines[] = '- '.($src['title'] !== '' ? $src['title'].': ' : '').$src['url'];
            }
        }

        return $lines ? "\n\nSources:\n".implode("\n", array_slice($lines, 0, 8)) : '';
    }

    /**
     * Court cases named in the answer that aren't in the checked library and weren't among the official pages read,
     * or that the library marks as not checked against the judgment.
     *
     * @param  list<array{url:string,title:string}>  $sources
     * @return list<string>
     */
    public static function unknownCases(string $answer, array $sources): array
    {
        $titles = mb_strtolower(implode(' | ', array_map(fn ($s) => $s['title'].' '.$s['url'], $sources)));
        $out = [];
        foreach (TaxLaw::casesNamedIn($answer) as $mention) {
            $known = TaxLaw::matchCase($mention['first'], $mention['second']);
            if ($known === true) {
                continue;
            }
            if ($known === false) {
                $out[] = 'case '.$mention['text'].' (not checked against the judgment: don’t rely on it)';

                continue;
            }
            $seen = array_filter([...$mention['first'], ...$mention['second']], fn ($w) => $w !== 'hmrc' && mb_strlen($w) > 2 && str_contains($titles, $w));
            if (! $seen) {
                $out[] = 'case '.$mention['text'].' (not in the checked library or the official pages read)';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Web links in the answer that no tool returned: a cited source has to be a real one from the tax guide or records.
     *
     * @param  list<string>  $toolOutputs
     * @return list<string>
     */
    public static function unmatchedLinks(string $answer, array $toolOutputs): array
    {
        preg_match_all('#https?://[^\s)\]>"\'<]+#', $answer, $m);
        $known = implode(' ', $toolOutputs);
        $out = [];
        foreach (array_unique($m[0]) as $url) {
            $clean = rtrim($url, '.,;:');
            if (! str_contains($known, $clean) && ! str_contains($known, str_replace('/', '\/', $clean))) {
                $out[] = 'link '.$clean;
            }
        }

        return $out;
    }

    /**
     * Money figures in the answer that don't appear anywhere in the data the tools returned.
     * Figures in sentences that call themselves an estimate, projection or forecast are allowed through.
     *
     * @param  list<string>  $toolOutputs
     * @return list<string>
     */
    public static function unmatchedFigures(string $answer, array $toolOutputs): array
    {
        $known = [];
        foreach ($toolOutputs as $json) {
            foreach (Grounding::amountsIn(preg_replace('/"pence":-?\d+/', '', $json)) as $amount) {
                $known[$amount] = true;
            }
            preg_match_all('/"pence":(-?\d+)/', $json, $m);
            foreach ($m[1] as $pence) {
                $known[abs((int) $pence)] = true;
            }
        }

        $unmatched = [];
        foreach (preg_split('/(?<=[.!?\n])\s+/', $answer) as $sentence) {
            $labelled = (bool) preg_match('/\b(estimate|estimated|estimates|projection|projected|forecast)\b/i', $sentence);
            preg_match_all('/[£$€]\s?\d[\d,]*(?:\.\d{1,2})?/u', $sentence, $m);
            foreach ($m[0] as $figure) {
                $minor = Grounding::amountsIn($figure)[0] ?? null;
                if ($minor !== null && ! isset($known[$minor]) && ! $labelled) {
                    $unmatched[] = $figure;
                }
            }
        }

        return array_values(array_unique($unmatched));
    }

    private function system(string $channel): string
    {
        $today = now('Europe/London')->format('l j F Y');
        $name = Setting::get('business_name') ?: 'the owner';
        $style = $channel === 'telegram'
            ? 'Reply in plain text for a phone message: short, no tables, under 1,500 characters.'
            : 'Use short paragraphs or bullet lists. Use a small table only when comparing figures.';
        $boundary = TaxLaw::planningBoundary();
        $allowed = implode("\n", array_map(fn ($l) => '  - '.$l, $boundary['allowed'] ?? []));
        $refuse = implode("\n", array_map(fn ($l) => '  - '.$l, $boundary['refuse'] ?? []));
        $researched = TaxLaw::researchedOn() ?? 'recently';
        $web = self::webTools()
            ? '- For anything newer than the library or not in it (a rate, a recent case, a Budget change), use web_search and web_fetch. They only reach legislation.gov.uk, GOV.UK and HMRC manuals, and the courts’ published judgments. Search queries must be about the law only: never put names, clients, amounts or other personal details in a query. Prefer the legislation and the judgment itself to commentary.'
            : '- Web search is off, so for anything not in the library say it needs checking on GOV.UK or with an accountant.';

        return <<<TXT
You are the assistant inside Expense Ledger, the private bookkeeping app of {$name}, a UK self-employed freelancer working as an events and production technician. Today is {$today}.

You act as their accountant and tax adviser: you know UK tax law for sole traders as it stands today (Acts, regulations, HMRC manuals and the leading cases) and you use it to keep them compliant and to make sure they pay no more tax than the law requires. You also help with anything about their finances, jobs, dates and business: answering questions, finding details in documents, spotting what needs doing, and making suggestions.

How you work:
- Use the tools to look things up. Everything you state about their records must come from a tool result in this conversation. Never guess or fill in missing details. If something isn't in the records, say so plainly and suggest how to add it.
- Don't do arithmetic yourself; use the totals the tools return. Quote money exactly as the tools give it.
- Mention where facts come from in brackets, like (job 12), (document 40) or (transaction 381), so the owner can check.
- Keep facts and advice apart. Put recommendations under a heading or sentence starting "Suggestion:" and label any estimate or projection as such.

The law:
- For any rule, use tax_law (a library of cases, legislation, HMRC guidance and coming changes, each checked against an official source on {$researched}) and tax_guide. State the rule in plain English, then the authority: the section of the Act, the HMRC manual page or the case, with its link. Cite a case only if tax_law returns it without do_not_cite, and say what it decided, not more. Never name a case, section or manual page you haven't seen in a tool result or an official page this conversation.
- Items marked not_law_yet are announcements or proposals: say so, and don't treat them as law.
{$web}
- When the law is unclear, or the cases go both ways, say so and give both sides. Say when an accountant or tax adviser should check something (anything near the line below, large sums, disputes with HMRC, status questions, structures).
- tax_year_overview gives the figures; tax_savings gives the legal ways to pay less tax checked against their records, with an estimate of each saving; penalties gives fines and interest. Figures marked ESTIMATE are estimates: HMRC's own calculation is the authority.
- Use explain_payment for "what is this payment / can I claim it".

Reducing tax: only ever lawfully, as Parliament intended. You may help with:
{$allowed}
You must refuse, briefly and without lecturing, and say why it's unlawful or caught by the anti-avoidance rules:
{$refuse}

Other rules:
- You can propose a decision for a transaction with propose_decision; it only becomes a suggestion in the Inbox. Never claim to have confirmed, filed, paid or sent anything.
- You are not a regulated adviser or a solicitor, and HMRC or a tribunal has the final word. Say this once when you give tax advice, not in every message.
- Use British English and £. {$style}
TXT;
    }
}
