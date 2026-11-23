<?php

namespace App\Domain\Ai;

use App\Domain\Documents\AiConfig;
use App\Domain\Documents\Grounding;
use App\Models\AssistantLog;
use App\Models\Setting;
use RuntimeException;

/**
 * The chat assistant. It answers from the app's records only, through AssistantTools, and the app then
 * checks every money figure in the answer against what the tools returned. Figures that can't be matched
 * are listed under the answer rather than silently trusted.
 */
final class Assistant
{
    private const MAX_ROUNDS = 10;

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
        $in = $out = 0;
        $model = AiConfig::model();

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $result = $this->ai->send([
                'maxTokens' => 16000,
                'system' => $this->system($channel),
                'tools' => AssistantTools::definitions(),
                'messages' => $messages,
                'outputConfig' => ['effort' => AiConfig::effort()],
            ]);
            $in += $result->inputTokens;
            $out += $result->outputTokens;
            $model = $result->model;

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

        $answer = $result->text() ?: 'I couldn’t finish that. Try asking in a narrower way.';
        $unmatched = [...self::unmatchedFigures($answer, $toolOutputs), ...self::unmatchedLinks($answer, $toolOutputs)];

        $log = AssistantLog::query()->create([
            'channel' => $channel, 'question' => $question, 'answer' => $answer, 'tool_calls' => $calls,
            'unmatched_figures' => $unmatched, 'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out,
        ]);

        return ['answer' => $answer, 'unmatched' => $unmatched, 'tools' => $calls, 'log_id' => $log->id];
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

        return <<<TXT
You are the assistant inside Expense Ledger, the private bookkeeping app of {$name}, a UK self-employed freelancer working on events and productions. Today is {$today}.

You help with anything about their finances, jobs, dates and business: answering questions, finding details in documents, explaining their tax position, spotting what needs doing, and making suggestions and recommendations.

How you work:
- Use the tools to look things up. Everything you state as a fact must come from a tool result in this conversation. Never guess or fill in missing details. If something isn't in the records, say so plainly and suggest how to add it.
- Don't do arithmetic yourself; use the totals the tools return. Quote money exactly as the tools give it.
- Mention where facts come from in brackets, like (job 12), (document 40) or (transaction 381), so the owner can check.
- Keep facts and advice apart. Put recommendations under a heading or sentence starting "Suggestion:" and label any estimate or projection as such.
- Tax: figures marked ESTIMATE are estimates; HMRC's own calculation is the authority. For any tax rule (what's allowable, travel, mileage, use of home, capital allowances, payments on account, pensions, VAT, ways to reduce tax) use the tax_guide tool, say what it says in plain English, and cite its GOV.UK or HMRC links. Don't state a rule or rate the guide or tax_rules don't give you. Use explain_payment for "what is this payment / can I claim it". Say when an accountant should be asked (the guide marks those). Only suggest legal ways to reduce tax. You are not a regulated adviser.
- You can propose a decision for a transaction with propose_decision; it only becomes a suggestion in the Inbox. Never claim to have confirmed, filed, paid or sent anything.
- Use British English and £. {$style}
TXT;
    }
}
