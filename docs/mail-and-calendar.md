# Mail and calendar: assessment and proposed order

**Owner's decision (24 November 2026):** "Expend can have as much access to emails and calendar as it needs to
perform to the best of its ability." Read as: full Gmail and Calendar access, plus Drive read-only for attachments.
Access is not autonomy. Sending anything to a client, changing the owner's own calendar entries and moving money
still need the owner's press, unless M9–M10 of docs/intelligence-cycle.md grant more. Expend may write freely only
to things it owns: its own Gmail labels, its own calendar and its own drafts (this needs plan law 4 amending).

Status: proposal. Which batches to build is the owner's choice.

*For the owner, 24 November 2026. Code facts come from the repository at commit 3384b60. Google and legal facts were checked against official pages (section 6). "Unverified" marks anything nobody could confirm.*

## 1. Short answer

- **Do nearly all of it, some parts in a different shape.** Some exists already: Gmail is searched when a job is added (the README is out of date), and calendar entries already become "Possible jobs".
- **The one change that will help most:** a new Google Cloud project in "Personal" mode, connected once with the permissions in section 4. Your Google connection is in "Testing" mode, so Google cuts Expend off every 7 days, silently stopping every Gmail search and label. Personal mode is made for one-person apps: no cut-off, no "unverified app" warning, no Google review. Then Expend can check Gmail every two minutes and read job emails as they land. Today a PO in Gmail can wait a week for the Sunday rescan, and under the default AI setting it is never read, so it never counts as a PO.
- **Order (section 5):** reconnect and quick fixes; continuous mail; the calendar; then the money end and the timeline.
- **Your priority:** I agree with all three, but I'd swap the first two and add one thing before the third. Calendar "possible jobs" mostly exists, so finishing it is small. Continuous PO discovery is the biggest real gap. A "the job finished yesterday: invoice it?" message with a ready draft is worth more than the timeline, because unbilled work is where money is lost. Build the timeline after M4's job close-out check, so the page, the to-do list and the history agree.

## 2. Your suggestions, by theme

Effort: small = a day or less; medium = 2–4 days; large = a week or more. "M" numbers are milestones in docs/intelligence-cycle.md. Throughout, anything sent to a client, any change to your own entries and anything that moves money needs your press. Expend may write freely to its own labels, calendar and drafts.

### A. Continuous discovery of POs and job mail (M-1, M-2, B-2a, B-2b)

- **Today:** Gmail is only searched: weekly (Sunday 05:23), by the "Search Gmail" button, and once per job when it's made or "Search again" is pressed. The forwarding mailbox is checked every 5 minutes. Under the default AI setting ("on demand"), emails are only scanned by rules for work offers: PO attachments aren't read, so they don't count as POs or wake the job finder. Editing a job never searches again, and conversations aren't fetched. The "not invoiced" to-do already stays until you bill.
- **Verdict:** do it differently: poll first, push later.
- **How:** (1) Every 2 minutes, ask Gmail's change log what is new. Read only sender and subject; keep emails that look like work (a client's domain, PO words, an attachment, a reply in a known thread) and skip the rest unstored. (2) Read kept job mail automatically, within the AI cap. (3) Search for a job's PO in passes: the exact PO number; "PO" or "purchase order" from the client's domains; then today's broad search, never your Sent mail. Re-run after edits, and on every check until a PO links or you press "No PO needed". (4) Fetch a linked email's conversation and offer the rest as possible finds. (5) Telegram: "PO 4471 found for Arena load-in [Draft the invoice]". (6) Gmail push through Pub/Sub (Google's relay service) is optional later: on Hostinger it saves about a minute, and it needs a paid billing account.
- **Needs from you and access:** the reconnect; gmail.modify, which you already granted.
- **Your rules:** only firm rules link (PO number, or date plus name); everything else is a proposal with "Not this", and rejections stick. Every kept email is logged with its reason, newly including the forwarding mailbox.
- **Risks, effort:** AI spend (capped); a PO linked to another job of the same client (never on AI alone). Medium, inside M8.

### B. Fallbacks: forwarding filters and label-only reading (B-2c, B-2d)

- **Today:** Settings lists forwarding steps and searches to copy; nothing measures what they catch. Limiting Expend to a label means editing a search by hand. Unticking Gmail on Google's screen gives no access, not read-only.
- **Verdict:** later, as a fallback for when the Google connection breaks.
- **How:** a filter advisor ("these 3 filters would have caught 41 of your 45 POs"), copied by you or created on your press. Label-only reading isn't needed: Google has no label-limited permission, so it was only ever Expend's promise.
- **Needs from you and access:** verify the forwarding mailbox in Gmail once (no app may do it for you); gmail.settings.basic to create filters.
- **Your rules:** suggestions only; each filter on your press, audited.
- **Risks, effort:** a broad filter forwards personal mail. Small to medium, after M8.

### C. Gmail labels mirroring Expend (M-3)

- **Today:** one label, ✓ Invoiced, added when an invoice is issued and removed if it's voided, all audited. If you remove it, it stays off. Nothing is read back. Plan law 4 allows only this label.
- **Verdict:** do it differently: three labels, not four.
- **How:** "Expend/Needs you" on the email behind an open to-do; "Expend/Not a PO", read back as your decision (confirmed the first time, undoable); "Expend/Paid". Skip "Read": Gmail shows a thread as labelled if any message is, so a new amendment in an old thread would look handled. Labels don't follow replies, so the 2-minute check labels new messages in tracked threads. That "/" nests labels is undocumented: test, else use flat names.
- **Needs from you and access:** amend law 4 and switch each label on; gmail.modify (held).
- **Your rules:** the labels are Expend's own; you confirm the read-back.
- **Risks, effort:** loops with push (Expend reacts only to new mail); stale labels while Expend is down. Medium, after A. Record each label as an action kind for M9.

### D. Invoices and chasers in the booking conversation (M-4, B-5c)

- **Today:** invoices and one fixed reminder go by Hostinger mail or SMTP, each in a new thread. No Message-ID (an email's unique tag) is stored, so reminders don't even thread with the invoice. Replies go to your business email: if that is the forwarding mailbox they show as "Held" to-dos, otherwise Expend never sees them. Nothing looks for remittance advice.
- **Verdict:** do it, in two steps.
- **How:** (1) Whatever the route, give each invoice email a fixed Message-ID and thread reminders onto it. (2) Expend writes the invoice or reminder as its own Gmail draft in the booking conversation, subject "Re: <their subject>" (Gmail splits threads when the subject changes), with the invoice and PO numbers in the first line. Telegram: "Invoice 43 is in your Gmail drafts, in the PO thread [Send it] [Open in Gmail]". To is the billing address, never reply-all; per client, choose the booking thread or a new email to accounts. The change check then watches unpaid invoices' threads and remittance emails; the bank stays the proof. The mailto PO request becomes a draft too. If Google is unreachable, fall back to today's route.
- **Needs from you:** decide whether invoices go from Gmail, and from which address (your Gmail, or your business address as a "Send mail as" alias).
- **Access:** gmail.modify already allows drafts and sending, so "never without you" is Expend's rule, not Google's; the plan's security notes, which assume read-only, need updating.
- **Your rules:** sending is always your press, even under M9–M10.
- **Risks, effort:** the booking contact may not be accounts. Threading on the client's side isn't guaranteed (Gmail groups automated mail only within a week), so each email must stand alone. Drafts can be forgotten, so the "not sent" to-do keeps burning. Step 1 small, now; step 2 medium, with M8.

### E. The calendar as a source of possible jobs (C-1, B-1, B-5a, B-5b)

- **Today:** largely built. Hourly, the job finder scores unlinked calendar entries; 3 or more becomes a "Possible job". Links survive moves, and the job and calendar pages show each other. Gaps: only title and place are kept, though the feed carries organiser, guests, notes, attachments and status. With AI off, score-2 entries only turn amber on the calendar page and score-1 shows nothing. The to-do item opens the Connections map and doesn't say "from your calendar". Telegram buttons aren't tied to what was shown. Entries moved over 7 days away drop off the job page. Nothing checks tomorrow. Two bugs: cancelled entries are stored as live, and an empty feed wipes all entries until the next sync. Invitation files in emails are never read as calendar data.
- **Verdict:** do it: finish it.
- **How:** (1) Fix the bugs. The item says "From your calendar: Tue 3 Dec, 08:00–18:00, <venue>", opens the entry and offers "Part of <job>"; the next 14 days come first. (2) Read through the Calendar API every 5 minutes, changes only: explicit cancellations, organiser and guest domains, your own reply, attachments, and Google's "from Gmail" hotel entries to skip. Keep each entry's history (M8); the feed stays as fallback. (3) Fetch call sheets attached to entries from Drive; unshared files become "forward it" prompts. (4) Read invitation files exactly, linking entry, booking thread and PO. (5) Each evening: "Tomorrow: Arena rig, 07:00, has no job [It's a job] [Not a job]" or "…has no PO [Ask for it]". (6) "A PO for 12 Mar arrived; your calendar has 'Acme rig' that day [Link them] [Not this]".
- **Access:** calendar.events, calendar.calendarlist.readonly, drive.readonly.
- **Your rules:** "Not a job" is remembered, and your links are never changed. Reads are logged with reasons. Guest addresses never go to AI or Telegram: guard both the job finder's prompt and the assistant's calendar tool.
- **Risks, effort:** false positives from notes; feed and API ids will likely match, but Google doesn't promise it. Fixes small, now; the reader medium, with M8; prompts in M5; one-tap buttons after M7, which checks an item hasn't changed since it was shown.

### F. Calendar write-back and sync (C-2, C-3, B-3)

- **Today:** no calendar writes. Moves show only as a nightly "failed check". Cancellations aren't detected, and job days don't follow moves. Holds live in Opportunities, which never look at the calendar. Invoice nudges start only after the due date.
- **Verdict:** do it differently: two one-way flows, never true two-way sync.
- **How:** (1) An "Expend" calendar that Expend writes freely: invoice due dates; tax dates with "missing it costs about £X (estimate)"; "Bill: Acme Awards" the day after a job until it's invoiced, then "✓ Billed"; each job coloured by status (pencilled, confirmed, invoiced, paid). Events carry a fixed id and a hidden tag, checked before inserting; amounts can be hidden; Expend never reads this calendar back as jobs. (2) Your calendars are read, and changes become findings: "Your calendar moved Acme Awards to 5–6 Dec; the job is 3–4 Dec [Move the job] [Keep]"; "Cancelled, hotel still booked [Mark cancelled]"; "You accepted Acme's hold [Mark confirmed]". Days you set never move, and a change must hold across two checks. (3) Edits to your own entries, such as accepting an invitation, are buttons only. Moving job days on its own is M9–M10.
- **Access:** calendar.app.created for its own calendar; calendar.events for reading and your pressed edits. With calendar.events, "never on its own" is Expend's rule. calendar.events.readonly would make Google enforce it, losing the pressed edits.
- **Why not a feed you subscribe to:** Google doesn't document how often it refreshes one (others report hours to a day; Outlook says 3–6 hours).
- **Your rules:** Expend writes only to what it owns, audited. Findings have Keep and Not relevant.
- **Risks, effort:** duplicates (Google doesn't promise to catch id clashes); bursts of writes. Medium each for the calendar and the findings. The findings are M8's calendar.job_changed. Law 4 needs amending.

### G. Per-job evidence timeline (B-4)

- **Today:** the job page has separate sections. The Connections map for a job is a graph of every job from 21 days before to 45 after, with flags.
- **Verdict:** do it, after M4.
- **How:** a "Timeline" tab, as one dated list: calendar entries, job days, emails and documents (date received and the date they mention), stays, journeys, bank lines, invoices and mileage. Each row says who linked it and opens the existing drawer or M6's "why" panel. "Expected but missing" rows come from M4's close-out finding, so every view agrees: no PO, no invoice 14 days after, no payment by the due date, a paid stay with no bank line. No new tables, no new access.
- **Your rules:** a view only; possible items carry Link and "Not this".
- **Risks, effort:** page weight; dates with different meanings (show both). Medium; M4 and M6.

## 3. My own ideas, best first

1. **Close out the morning after a job.** *Today:* "Draft the invoice" exists, but a draft from a job copies only a whole-job fee, so a day-rate job gets an empty draft. Recharged hotels are only a reminder. *Idea:* the morning after, Expend prepares the draft (PO lines, or day rate × days, recharges as lines, each with its source), mileage and stays: "Arena wrapped. Invoice £1,380 ready [Check and issue] · 168 miles, about £92 [Add]". Issuing takes two presses. *Effort:* medium. *Value:* high. M4–M5.
2. **Tax pot.** *Today:* no "set aside" figure; Starling sync reads no balances. *Idea:* "Arena paid £1,380: put about £330 aside (estimate)" and "Tax pot £3,100 of about £5,400 needed by 31 Jan", read from your chosen Starling Space. 31 January 2027 (68 days away) usually brings the 2025-26 balance plus the first 2026-27 payment on account: half of last year's income tax and Class 4, less tax taken at source. A "Move to Tax Space" button would move money, so it needs your press each time and M9; Starling's specification shows it needs no extra signing, but test with £1. *Effort:* small. *Value:* high.
3. **Today's one thing.** *Today:* a weekly digest of 8 items and a total, and fixed-time nudges with sound. *Idea:* one silent message a day at your usual active hour, skipped on show days. It picks one item by money, urgency and minutes, with [Tomorrow] [Next], and never shows the backlog total. Other non-urgent prompts ride in it. *Effort:* medium. *Value:* high. M5.
4. **A chase ladder in the PO thread.** *Today:* one reminder wording, repeated weekly; no late-payment interest. *Idea:* Gmail drafts at 3, 14 and 30 days late [Send it] [Not yet] [They've paid]. Statutory interest is shown, and added only if you tick it, for business clients without their own interest clause: 8% over the Bank Rate, fixed per debt (11.75% where interest starts in 2026; 12.00% for January–June 2027, provisional until 31 December), simple daily, plus a one-off £40, £70 or £100. Example: £1,350, 40 days late: £17.38 + £70. *Effort:* medium. *Value:* high.
5. **A deadline ladder with "Done" buttons.** *Today:* warnings 60 days before the return and 14 before MTD updates; nothing before 31 July. *Idea:* each HMRC date gets stages at 30, 7 and 2 days and the day after, each saying something new: "when will you do it? [Sat] [Sun]", the next step, the cost of missing it. [Filed today] and [Paid £X today] record your word; the bank line confirms it later. *Effort:* medium. *Value:* high.
6. **Missing receipts found in Gmail.** *Today:* a bank line's page shows nearby same-amount receipts, but there is no list of costs without receipts. *Idea:* nightly narrow searches (merchant, dates, amount) for business costs over £20. Keep only amount matches and propose links. Monthly: "Linked 11; 7 not found: send photos". *Effort:* medium. *Value:* high. M4.
7. **Records safe for six years.** *Today:* encrypted backups are kept 30 days on the same Hostinger account, copied off by hand, unchecked. Any document can be deleted. *Idea:* a keep-until date per year, with a reason needed to delete; backups copied to Drive (already encrypted; drive.file sees only Expend's own files); a warning when the copy is over 8 days old; a year pack on lock. *Effort:* medium. *Value:* high.
8. **Money owed and a 13-week look-ahead.** *Today:* the invoices page totals what is outstanding, with no ageing or forecast. *Idea:* owed by age, unbilled and booked work, then weekly money in (shifted by each client's usual lateness) and out (HMRC, regular costs): "Lowest point about w/c 26 Jan: −£600 to +£1,400". *Effort:* medium. *Value:* high.
9. **MTD pack.** *Today:* thresholds and quarters are tracked, with no totals or export. Expend can't file: HMRC has paused new production access. *Idea:* "are you in?", counting undecided income. Over £30,000 of turnover (not profit) in 2025-26 means MTD from 6 April 2027, first update 7 August 2027. Add a quarter-close checklist and an export for recognised bridging software. *Effort:* large. *Value:* high if in scope.
10. **Capture from the phone.** *Today:* Telegram takes photos and files; a typed note records nothing. *Idea:* "cash 12 parking", "84 miles Leeds", "hold 12–14 Dec Arena" become drafts with [Save]; statements sent to the bot become held imports. *Effort:* medium. *Value:* medium.
11. **Closing the payment loop.** *Today:* matching needs one bank line equal to one invoice. *Idea:* a payment reference on invoices; payment runs split when exactly one combination fits; remittance emails and accounts replies ("needs a PO", "paid on the 15th") sorted into to-dos. *Effort:* medium. *Value:* medium.
12. **Client cards and booking terms.** *Today:* a client is a name and notes. *Idea:* billing email, terms, "always needs a PO", business or individual, usual lateness (after 3 paid invoices), rate history; and, when you accept work, a draft confirming dates, rate and your terms. *Effort:* medium. *Value:* medium.

Smaller extras: a VAT watch on invoices rather than cash; weekly 10-minute year-end sweeps (per-year counts exist); a kit register, as selling claimed kit is taxable.

## 4. What you'd need to set up or decide

**Google (about 30 minutes, once):**

- [ ] Create a **new** Google Cloud project in **Personal** mode, signed in as the Gmail account Expend uses. An existing project can't be switched. A Workspace account's admin may need to allow Gmail and Drive.
- [ ] If Personal isn't offered: External, "In production", never Testing. You click past an "unverified app" warning once. Google documents the 7-day cut-off only for Testing.
- [ ] Enable the Gmail, Calendar and Drive APIs (and Pub/Sub only for push).
- [ ] Create a web OAuth client (the "Sign in with Google" key), paste its ID and secret into Settings, and reconnect.
- [ ] Grant in one go. Expend checks what came back and falls back to the calendar feed if calendar is unticked.

| Permission | For | Google's class |
|---|---|---|
| gmail.modify (held) | reading, labels, drafts, sending | restricted |
| gmail.settings.basic (optional) | filters, on your press | restricted |
| calendar.events | reading entries; your pressed edits | sensitive (Google's example; not formally listed) |
| calendar.app.created | Expend's own calendar | not published |
| calendar.calendarlist.readonly | which calendars to read | not published |
| drive.readonly | call sheets on entries | restricted |
| drive.file | backup copies | non-sensitive |

  "Restricted" normally means a Google review and a yearly paid security assessment. Personal-mode apps are exempt.
- [ ] Reliability: the connection still dies on a password change, if you revoke access, or after 6 months unused. Expend already alerts you on Telegram and the to-do list; a daily check makes that prompt. Keep the forwarding filters and calendar feed as fallbacks.
- [ ] **Push, only if wanted later:** a paid billing account (a free trial closes after 90 days); a topic in the same project (Google requires this); Publisher rights for gmail-api-push@system.gserviceaccount.com; a push subscription to Expend's /hooks/gmail with Google-signed tokens, never expiring, with gradual retries. Cost: about £0, plus pennies of storage after an outage longer than a day. Check Hostinger's firewall doesn't block Google.

**Other:** re-issue the Starling token with balance:read, space:read and savings-goal:read (a business account needs its own developer account and token).

**Decisions:**

- [ ] Invoices and chasers as Gmail drafts in the booking thread? From your Gmail, or a business alias?
- [ ] Amend plan law 4 so Expend may write its own labels, calendar and drafts.
- [ ] Which labels to switch on; whether amounts show on your calendar.
- [ ] Automatic AI reading of job mail, and the monthly AI cap.
- [ ] Statutory interest: opt in per invoice?
- [ ] Watch: Google's AI tools terms (from 1 February 2027) may cover apps that pass Workspace data to AI. Whether they cover Expend is unclear; re-check in January.

## 5. Proposed order of work

1. **Reconnect and quick wins (2–3 days).** Your reconnect. Correct the README and the "read-only" Settings text. Calendar bug fixes, and "Sync now" running the job finder. Possible-job polish. Re-search Gmail after job edits, excluding Sent. Invoice Message-IDs. "Acme finished yesterday: invoice it?". The tax pot, before January. Mostly small fixes next to M3–M4; another workflow is editing the to-do, Telegram, job finder and calendar code, so coordinate.
2. **Continuous mail (3–4 days, in M8).** The change check, automatic reading of job mail, PO passes, conversation fetch, the read log for every route, "PO found" messages, health alerts, and the labels.
3. **Calendar (3–4 days, M8 and M5).** The API reader, attachments, invitations, change findings, Expend's own calendar, and the "tomorrow" check.
4. **The money end (3–4 days, M4–M6; one-taps after M7).** Close-out drafts, invoice and chase drafts in the thread, the timeline, and "today's one thing".

Then the deadline ladder, receipts, records and the MTD pack (before April 2027 if in scope); push and the filter advisor last. Anything acting on its own (sending, moving money, moving job days) waits for your say under M9–M10.

**Unverified:** whether your private feed carries attachments (public Google feeds do); whether "/" nests Gmail labels; whether Expend's calendar is tied to the OAuth client or the project (don't swap clients until tested); whether feed and API ids always match; whether Google's AI tools terms cover Expend.

## 6. Sources

- Gmail: [push](https://developers.google.com/workspace/gmail/api/guides/push), [users.watch](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/watch), [history.list](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list), [scopes](https://developers.google.com/workspace/gmail/api/auth/scopes), [threads](https://developers.google.com/workspace/gmail/api/guides/threads), [labels](https://developers.google.com/workspace/gmail/api/guides/labels), [filters](https://developers.google.com/workspace/gmail/api/guides/filter_settings), [forwarding](https://developers.google.com/workspace/gmail/api/guides/forwarding_settings), [quotas](https://developers.google.com/workspace/gmail/api/guides/quotas), [conversations](https://support.google.com/mail/answer/5900)
- OAuth: [Personal mode](https://support.google.com/cloud/answer/15544987), [publishing status](https://support.google.com/cloud/answer/15549945), [unverified apps](https://support.google.com/cloud/answer/13464323), [OAuth 2.0](https://developers.google.com/identity/protocols/oauth2), [web server flow](https://developers.google.com/identity/protocols/oauth2/web-server), [restricted scopes](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification), [sensitive scopes](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification)
- Pub/Sub: [push](https://docs.cloud.google.com/pubsub/docs/push), [authentication](https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions), [pricing](https://cloud.google.com/pubsub/pricing), [free tier](https://docs.cloud.google.com/free/docs/free-cloud-features)
- Calendar: [scopes](https://developers.google.com/workspace/calendar/api/auth), [events](https://developers.google.com/workspace/calendar/api/v3/reference/events), [sync](https://developers.google.com/workspace/calendar/api/guides/sync), [extended properties](https://developers.google.com/workspace/calendar/api/guides/extended-properties), [calendars.insert](https://developers.google.com/workspace/calendar/api/v3/reference/calendars/insert), [subscribe](https://support.google.com/calendar/answer/37100), [Outlook](https://support.microsoft.com/en-us/office/import-or-subscribe-to-a-calendar-in-outlook-com-or-outlook-on-the-web-cff1429c-5af6-41ec-a5b4-74f2c278e98c)
- Drive: [scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth), [downloads](https://developers.google.com/workspace/drive/api/guides/manage-downloads). Workspace: [usage tiers](https://developers.google.com/workspace/guides/quota-usage-tiers), [AI tools terms](https://workspace.google.com/terms/ai-tools)
- UK: [Late Payment Act 1998](https://www.legislation.gov.uk/ukpga/1998/20), [SI 2002/1675](https://www.legislation.gov.uk/uksi/2002/1675/made), [Bank Rate](https://www.bankofengland.co.uk/boeapps/database/Bank-Rate.asp), [late payments guide](https://www.gov.uk/late-commercial-payments-interest-debt-recovery), [payments on account](https://www.gov.uk/understand-self-assessment-bill/payments-on-account), [MTD eligibility](https://www.gov.uk/guidance/check-if-youre-eligible-for-making-tax-digital-for-income-tax), [MTD penalties](https://www.gov.uk/guidance/penalties-for-making-tax-digital-for-income-tax), [HMRC MTD guide](https://developer.service.hmrc.gov.uk/guides/income-tax-mtd-end-to-end-service-guide/)
- Starling: [developer docs](https://developer.starlingbank.com/docs), [API specification](https://api.starlingbank.com/api/openapi.json)
