<?php

namespace Tests\Feature;

use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Tax\TaxLaw;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Finding case names in an answer and checking them against the law library: a checked case passes however it is
 * written, an unchecked one is marked, and an invented one is never passed off as checked.
 */
class TaxLawCasesTest extends TestCase
{
    private const CITABLE = 'in the library, citable';

    private const DO_NOT_CITE = 'in the library, do not cite';

    private const UNKNOWN = 'not in the library';

    private const NOT_A_CASE = 'not a case';

    /** @return array<string, array{0:string, 1:string, 2?:string}> text, what it is, and the name as reported */
    public static function mentions(): array
    {
        return [
            // Checked cases as an answer would name them.
            'plain name' => ['In Mallalieu v Drummond the Lords refused the cost of black clothes.', self::CITABLE, 'Mallalieu v Drummond'],
            'HMRC and a surname' => ['See HMRC v Healy on hotels near a job.', self::CITABLE, 'HMRC v Healy'],
            'first name and surname' => ['Tim Healy v HMRC was reheard in 2015.', self::CITABLE, 'Tim Healy v HMRC'],
            'name it is often cited by' => ['Powell v Jackman says a regular place of work is commuting.', self::CITABLE],
            'parties the other way round' => ['Jackman v Powell is the same case.', self::CITABLE],
            'short company name' => ['Barclays Mercantile v Mawson is the modern approach.', self::CITABLE],
            'company name in full' => ['HMRC v Atholl House Productions Ltd went to the Court of Appeal.', self::CITABLE],
            'company and others' => ['Kickabout Productions Ltd v HMRC is the companion case.', self::CITABLE],
            'company named after a person' => ['Christa Ackroyd Media Ltd v HMRC is about a presenter.', self::CITABLE],
            'company name with and in it' => ['Red White and Green Ltd v HMRC (Eamonn Holmes) lost.', self::CITABLE, 'Red White and Green Ltd v HMRC'],
            'company name with of in it' => ['Strong & Co of Romsey Ltd v Woodifield is about a falling chimney.', self::CITABLE],
            'the Duke' => ['IRC v Duke of Westminster is the old starting point.', self::CITABLE],
            'and others' => ['Stephen Hoey and others v HMRC lost on the loan charge.', self::CITABLE, 'Stephen Hoey v HMRC'],
            'two people' => ['Hill and McCracken v HMRC is recent.', self::CITABLE],
            'two cases joined by and' => ['Ready Mixed Concrete and Hall v Lorimer are not separate tests.', self::CITABLE, 'Hall v Lorimer'],
            'sentence opener' => ['Following Hall v Lorimer, the tribunal looked at the whole picture.', self::CITABLE, 'Hall v Lorimer'],
            'Despite' => ['Despite Hall v Lorimer, the tribunal found employment.', self::CITABLE, 'Hall v Lorimer'],
            'Whereas' => ['Whereas Hall v Lorimer looked at many clients, this was one.', self::CITABLE, 'Hall v Lorimer'],
            'Neither' => ['Neither Hall v Lorimer nor the guidance says otherwise.', self::CITABLE, 'Hall v Lorimer'],
            'Against' => ['Against Hall v Lorimer, HMRC argued control.', self::CITABLE, 'Hall v Lorimer'],
            'During' => ['During Hall v Lorimer, the court looked at risk.', self::CITABLE, 'Hall v Lorimer'],
            'Given' => ['Given Hall v Lorimer, the whole picture counts.', self::CITABLE, 'Hall v Lorimer'],
            'Through' => ['Through Hall v Lorimer, the test grew.', self::CITABLE, 'Hall v Lorimer'],
            'Today' => ['Today Hall v Lorimer is still cited.', self::CITABLE, 'Hall v Lorimer'],
            'Take' => ['Take Hall v Lorimer: a vision mixer with many clients.', self::CITABLE, 'Hall v Lorimer'],
            'Ultimately' => ['Ultimately Hall v Lorimer decides it.', self::CITABLE, 'Hall v Lorimer'],
            'Post' => ['Post PGMOL v HMRC, mutuality needs only a minimum.', self::CITABLE, 'PGMOL v HMRC'],
            'post- with a hyphen' => ['The post-Ramsay v IRC approach looks at the whole scheme.', self::CITABLE, 'Ramsay v IRC'],
            'a date before the name' => ['In September 2024 HMRC v PGMOL settled the point.', self::CITABLE, 'HMRC v PGMOL'],
            'a citation before the name' => ['[2024] UKSC 29 HMRC v PGMOL is the leading case.', self::CITABLE, 'HMRC v PGMOL'],
            'judge after the name' => ['In Mallalieu v Drummond Lord Brightman said so.', self::CITABLE, 'Mallalieu v Drummond'],

            // F2: formal styles of a checked case.
            'Inspector of Taxes' => ['Hall (HM Inspector of Taxes) v Lorimer looked at a freelance vision mixer.', self::CITABLE, 'Hall v Lorimer'],
            'HMIT' => ['Wannell v Rothwell (HMIT) is about losses.', self::CITABLE, 'Wannell v Rothwell'],
            'bold' => ['**Hall v Lorimer** is the leading case.', self::CITABLE, 'Hall v Lorimer'],
            'italic parties' => ['*Hall* v *Lorimer* is the leading case.', self::CITABLE, 'Hall v Lorimer'],
            'v with a dot' => ['Hall v. Lorimer is the leading case.', self::CITABLE, 'Hall v Lorimer'],
            'vs' => ['Hall vs Lorimer is the leading case.', self::CITABLE, 'Hall v Lorimer'],
            'Ltd with a dot' => ['Interfish Ltd. v HMRC is about sponsorship.', self::CITABLE, 'Interfish Ltd v HMRC'],

            // F3: short names, initials, possessives and aliases.
            'PGMOL' => ['In HMRC v PGMOL [2024] UKSC 29 the Supreme Court sent it back.', self::CITABLE, 'HMRC v PGMOL'],
            'PGMO' => ['PGMO v HMRC was reheard in 2026.', self::CITABLE],
            'curly possessive' => ['Hall v Lorimer’s approach still applies.', self::CITABLE, 'Hall v Lorimer'],
            'straight possessive' => ["HMRC v Healy's reasoning on hotels.", self::CITABLE, 'HMRC v Healy'],
            'Scottish Power' => ['See Scottish Power v HMRC on damages.', self::CITABLE, 'Scottish Power v HMRC'],
            'Eilbeck v Rawling' => ['Eilbeck v Rawling was heard with Ramsay.', self::CITABLE],
            'Ramsay v IRC' => ['Ramsay v IRC is about circular schemes.', self::CITABLE],
            'BMBF' => ['BMBF v Mawson applied it.', self::CITABLE],
            'Tower MCashback' => ['HMRC v Tower MCashback is about capital allowances.', self::CITABLE],
            'initials run together' => ['In WT Ramsay Ltd v IRC [1982] AC 300 the Lords looked at the whole scheme.', self::CITABLE, 'WT Ramsay Ltd v IRC'],
            'initials apart' => ['In W T Ramsay Ltd v IRC the Lords looked at the whole scheme.', self::CITABLE],
            'initials with an ampersand' => ['HMRC v S&L Barnes Ltd is about a football pundit.', self::CITABLE, 'HMRC v S&L Barnes Ltd'],
            'surname of a company named after a person' => ['In Ackroyd v HMRC [2019] UKUT 326 the presenter lost.', self::CITABLE, 'Ackroyd v HMRC'],
            'second of two appellants' => ['In Brimheath Developments Ltd v HMRC the burden of proof was on HMRC.', self::CITABLE, 'Brimheath Developments Ltd v HMRC'],
            'lower-case plc' => ['Lawson v Johnson Matthey plc is about a payment to be rid of a liability.', self::CITABLE],
            'a number in the name' => ['RFC 2012 plc (in liquidation) v Advocate General for Scotland is about trusts.', self::CITABLE, 'RFC 2012 Plc v Advocate General for Scotland'],
            'a former name' => ['The Rangers Football Club plc v Advocate General for Scotland is the same case.', self::CITABLE],

            // In the library but not checked against the judgment.
            'unchecked case' => ['R v Dimsey is about offshore income.', self::DO_NOT_CITE, 'R v Dimsey'],
            'unchecked case in italics' => ['*R v Dimsey* [2001] UKHL 46 is about offshore income.', self::DO_NOT_CITE, 'R v Dimsey'],

            // F1: invented cases that share a word with a checked one.
            'first name of a checked case' => ['David Jones v HMRC [2019] UKFTT 123 (TC) allowed the claim.', self::UNKNOWN, 'David Jones v HMRC'],
            'another first name' => ['Paul Smith v HMRC allowed the claim.', self::UNKNOWN],
            'Tim' => ['Tim Wilson v HMRC allowed the claim.', self::UNKNOWN],
            'Stephen' => ['Stephen Brown v HMRC allowed the claim.', self::UNKNOWN],
            'Sean' => ['Sean Murphy v HMRC allowed the claim.', self::UNKNOWN],
            'Mohammed' => ['Mohammed Khan v HMRC allowed the claim.', self::UNKNOWN],
            'Charlotte' => ['Charlotte Smith v HMRC allowed the claim.', self::UNKNOWN],
            'Marc' => ['Marc Thompson v HMRC allowed the claim.', self::UNKNOWN],
            'right surname, wrong first name' => ['John Healy v HMRC allowed the claim.', self::UNKNOWN],
            'one generic word' => ['Green v HMRC allowed the claim.', self::UNKNOWN],
            'one word of an alias' => ['Power v HMRC [2020] UKFTT 101 (TC) allowed the claim.', self::UNKNOWN, 'Power v HMRC'],
            'first word of an alias' => ['Scottish v HMRC allowed the claim.', self::UNKNOWN],
            'second word of an alias' => ['Mercantile v Mawson allowed the claim.', self::UNKNOWN],
            'company named after a person, wrong name' => ['Ackroyd Media Ltd v HMRC allowed the claim.', self::UNKNOWN],
            'one appellant, wrong name' => ['Brimheath v HMRC allowed the claim.', self::UNKNOWN],
            'media company' => ['Northern Light Media Ltd v HMRC allowed the claim.', self::UNKNOWN],
            'productions company' => ['Stage Lighting Productions Ltd v HMRC was outside IR35.', self::UNKNOWN],
            'another productions company' => ['Canal Street Productions Ltd v HMRC was outside IR35.', self::UNKNOWN],
            'shares two words' => ['Canal House Productions Ltd v HMRC was outside IR35.', self::UNKNOWN],

            // F2: invented cases in formal styles.
            'invented, Inspector of Taxes' => ['In Bloggs (Inspector of Taxes) v Fakeco Ltd the court held otherwise.', self::UNKNOWN, 'Bloggs v Fakeco Ltd'],
            'invented, HMIT' => ['In Smith (HMIT) v Jones Ltd the court held otherwise.', self::UNKNOWN, 'Smith v Jones Ltd'],
            'invented judicial review' => ['In R (on the application of Smith) v HMRC the court held otherwise.', self::UNKNOWN, 'Smith v HMRC'],
            'invented judicial review, short' => ['In R (Bloggs) v HMRC [2019] UKSC 1 the court held otherwise.', self::UNKNOWN, 'Bloggs v HMRC'],
            'invented, Ltd with a dot' => ['In Smith Ltd. v HMRC the court held otherwise.', self::UNKNOWN, 'Smith Ltd v HMRC'],
            'invented, vs' => ['In Jones vs HMRC the court held otherwise.', self::UNKNOWN, 'Jones v HMRC'],
            'invented, bold parties' => ['**Smith** v **Jones** settles it.', self::UNKNOWN, 'Smith v Jones'],
            'invented, bracket inside the name' => ['In Fakeco (South East) Ltd v HMRC the court held otherwise.', self::UNKNOWN, 'Fakeco Ltd v HMRC'],
            'invented, lower-case plc' => ['In Fakeco plc v HMRC [2019] UKFTT 1 (TC) the tribunal allowed it.', self::UNKNOWN, 'Fakeco Plc v HMRC'],
            'invented, plc with a dot' => ['In Fakeco plc. v HMRC the tribunal allowed it.', self::UNKNOWN, 'Fakeco Plc v HMRC'],
            'invented, a number in the name' => ['In Fakeco 2 Ltd v HMRC the tribunal allowed it.', self::UNKNOWN, 'Fakeco 2 Ltd v HMRC'],
            'invented, a year in the name' => ['In Fakeco 2019 Ltd v HMRC the tribunal held.', self::UNKNOWN, 'Fakeco 2019 Ltd v HMRC'],
            'invented, a number after LLP' => ['In Fakeco LLP 2 v HMRC the tribunal allowed it.', self::UNKNOWN, 'Fakeco LLP 2 v HMRC'],
            'invented, a name that starts like an opener' => ['Given Ltd v HMRC allowed the claim.', self::UNKNOWN, 'Given Ltd v HMRC'],
            // F4: a name with nothing around it is still checked.
            'invented, no marker' => ['Smith v Jones says you can claim it.', self::UNKNOWN, 'Smith v Jones'],
            // A company made of words that also name options, or anyone against HMRC, is a case, not a comparison.
            'invented, private' => ['Private Hire Ltd v HMRC [2020] UKFTT 101 (TC) allowed the claim.', self::UNKNOWN, 'Private Hire Ltd v HMRC'],
            'invented, electric van' => ['Electric Van Ltd v HMRC allowed the claim.', self::UNKNOWN],
            'invented, freelance' => ['Freelance Ltd v HMRC allowed the claim.', self::UNKNOWN],
            'invented, business' => ['Business Ltd v HMRC allowed the claim.', self::UNKNOWN],
            'invented, digital' => ['Digital v HMRC allowed the claim.', self::UNKNOWN],
            'invented, against a company' => ['Electric Van Ltd v Smith went the same way.', self::UNKNOWN],

            // Comparisons of options, not cases.
            'cash basis' => ['Cash Basis v Accruals is a choice, not a case.', self::NOT_A_CASE],
            'flat rate' => ['Flat Rate v Actual Costs for the van.', self::NOT_A_CASE],
            'fuel' => ['Electric v Petrol for a new van.', self::NOT_A_CASE],
            'structure' => ['Sole Trader v Limited Company is worth a look.', self::NOT_A_CASE],
            'structure, vs Ltd' => ['Sole Trader vs Ltd: which is better?', self::NOT_A_CASE],
            'lower case' => ['the old smith v jones idea', self::NOT_A_CASE],
            'vehicle finance' => ['PCP vs HP for the van.', self::NOT_A_CASE],
            'capital allowances' => ['AIA vs WDA: which suits the lighting desk?', self::NOT_A_CASE],
            'full expensing' => ['Full Expensing vs AIA for a new console.', self::NOT_A_CASE],
            'gift aid' => ['Gift Aid vs Pension Contributions.', self::NOT_A_CASE],
            'MTD' => ['Making Tax Digital vs Self Assessment.', self::NOT_A_CASE],
            'MTD, short' => ['MTD vs SA: what changes?', self::NOT_A_CASE],
            'day rate' => ['Day Rate vs Fixed Fee for a festival.', self::NOT_A_CASE],
            'updates' => ['Quarterly Updates vs Annual Return.', self::NOT_A_CASE],
            'hire' => ['Hire Purchase vs Contract Hire.', self::NOT_A_CASE],
            'plans' => ['Plan A vs Plan B.', self::NOT_A_CASE],
            'numbered options' => ['Option 1 vs Option 2.', self::NOT_A_CASE],
            'banks' => ['Starling vs Revolut for a business account.', self::NOT_A_CASE],
            'software' => ['Xero vs FreeAgent for invoices.', self::NOT_A_CASE],
        ];
    }

    #[DataProvider('mentions')]
    public function test_a_case_named_in_an_answer_is_found_and_checked(string $text, string $expected, ?string $name = null): void
    {
        $found = TaxLaw::casesNamedIn($text);
        if ($expected === self::NOT_A_CASE) {
            $this->assertSame([], $found);

            return;
        }
        $this->assertCount(1, $found, 'one case is named');
        $known = TaxLaw::matchCase($found[0]['first'], $found[0]['second']);
        $this->assertSame($expected, match ($known) {
            true => self::CITABLE, false => self::DO_NOT_CITE, null => self::UNKNOWN,
        }, $found[0]['text']);
        if ($name !== null) {
            $this->assertSame($name, $found[0]['text']);
        }
    }

    public function test_an_invented_case_is_flagged_and_a_checked_one_is_not(): void
    {
        $answer = 'In HMRC v PGMOL [2024] UKSC 29 the court looked at control. Hall v Lorimer’s approach still applies, '
            .'and David Jones v HMRC [2019] UKFTT 123 (TC) went the same way. See also Scottish Power v HMRC and Eilbeck v Rawling. '
            .'Despite WT Ramsay Ltd v IRC, Power v HMRC [2020] UKFTT 101 (TC) and Private Hire Ltd v HMRC allowed the claim. '
            .'For the van, PCP vs HP and AIA vs WDA are worth comparing.';

        $flags = Assistant::unknownCases($answer, []);

        $this->assertSame([
            'case David Jones v HMRC (not in the checked library or the official pages read)',
            'case Power v HMRC (not in the checked library or the official pages read)',
            'case Private Hire Ltd v HMRC (not in the checked library or the official pages read)',
        ], $flags);
    }

    public function test_every_case_answers_to_its_own_name_and_aliases(): void
    {
        foreach (TaxLaw::data()['cases'] as $case) {
            $citable = (bool) ($case['citable'] ?? false);
            foreach ([$case['name'], ...($case['aliases'] ?? [])] as $name) {
                // Every name must be found, or a case written that way would never be checked.
                $mentions = TaxLaw::casesNamedIn($name);
                $this->assertNotEmpty($mentions, "{$case['id']}: {$name} is found");
                foreach ($mentions as $mention) {
                    $this->assertSame($citable, TaxLaw::matchCase($mention['first'], $mention['second']), "{$case['id']}: {$mention['text']}");
                }
            }
            foreach ($case['aliases'] ?? [] as $alias) {
                $this->assertCount(1, TaxLaw::casesNamedIn($alias), "{$case['id']}: {$alias} reads as one case");
            }
        }
    }

    public function test_a_party_word_drops_a_possessive(): void
    {
        $this->assertSame(['lorimer'], TaxLaw::partyWords('Lorimer’s'));
        $this->assertSame(['healy'], TaxLaw::partyWords("Healy's"));
        $this->assertSame(['hmrc'], TaxLaw::partyWords('Commissioners for His Majesty’s Revenue and Customs'));
    }

    // ---- F9: a savings checklist id ----

    public function test_tax_law_with_a_savings_id_gives_the_idea_and_its_law(): void
    {
        $out = app(AssistantTools::class)->run('tax_law', ['id' => 'use-of-home']);
        $item = $out['item'];

        $this->assertSame('use-of-home', $item['id']);
        $this->assertSame('saving', $item['kind']);
        foreach (['title', 'conditions', 'data_needed', 'how_to_estimate', 'risks'] as $key) {
            $this->assertNotEmpty($item[$key] ?? null, $key);
        }
        // The checklist doesn't say whether this idea needs an accountant, so the answer isn't told "no".
        $this->assertArrayNotHasKey('needs_accountant', $item);
        $this->assertArrayNotHasKey('url', $item);
        $this->assertNotEmpty($item['legal_basis']);
        foreach ($item['legal_basis'] as $law) {
            $this->assertNotEmpty($law['id']);
            $this->assertNotEmpty($law['name']);
            $this->assertContains($law['url'], TaxLaw::citableUrls());
        }
        $this->assertSame(TaxLaw::researchedOn(), $out['researched_on']);
    }

    public function test_the_law_behind_a_saving_links_only_what_may_be_cited(): void
    {
        $item = TaxLaw::presentSaving(['id' => 'test-idea', 'title' => 'An idea', 'risks' => 'Ask an accountant first.',
            'legal_basis' => 'r-v-dimsey, hall-v-lorimer, no-such-item, ittoia-s57a']);

        $this->assertTrue($item['needs_accountant']);
        $this->assertSame(['r-v-dimsey', 'hall-v-lorimer', 'ittoia-s57a'], array_column($item['legal_basis'], 'id'));
        [$dimsey, $lorimer, $act] = $item['legal_basis'];
        $this->assertArrayNotHasKey('url', $dimsey);
        $this->assertArrayHasKey('do_not_cite', $dimsey);
        $this->assertArrayHasKey('url', $lorimer);
        $this->assertArrayHasKey('source_note', $lorimer);
        $this->assertArrayHasKey('url', $act);
        $this->assertArrayNotHasKey('conditions', $item);
    }

    public function test_every_savings_idea_has_its_law_in_the_library(): void
    {
        foreach (TaxLaw::savingsChecklist() as $idea) {
            $shown = TaxLaw::present(TaxLaw::find($idea['id']), 'saving');
            $this->assertSame($idea['title'], $shown['title']);
            $this->assertCount(count(preg_split('/\s*,\s*/', $idea['legal_basis'])), $shown['legal_basis'], $idea['id']);
            // Never a "no accountant needed": only a "yes" when the idea's own wording asks for one.
            $this->assertNotSame(false, $shown['needs_accountant'] ?? null, $idea['id']);
        }
    }

    public function test_an_item_without_a_link_has_no_url_key(): void
    {
        $this->assertArrayNotHasKey('url', TaxLaw::present(['id' => 'x', 'name' => 'A page'], 'guidance'));
        $this->assertArrayHasKey('url', TaxLaw::present(TaxLaw::find('hall-v-lorimer'), 'case'));
    }
}
