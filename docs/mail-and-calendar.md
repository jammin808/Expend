# Mail and calendar: assessment and proposed order

**Owner's decision (24 November 2026):** "Expend can have as much access to emails and calendar as it needs to
perform to the best of its ability." Read as: full Gmail and Calendar access, plus Drive read-only for attachments.
Access is not autonomy. Sending anything to a client, changing the owner's own calendar entries and moving money
still need the owner's press, unless M9–M10 of docs/intelligence-cycle.md grant more. Expend may write freely only
to things it owns: its own Gmail labels, its own calendar and its own drafts (this needs plan law 4 amending).

**Owner's choices (24 November 2026):**

- Build all four batches in section 5, with one change: **Gmail is checked hourly, or when the owner presses
  "Check now"**, not every 2 minutes. The hourly check uses Gmail's change log (history.list) the same way.
- Invoices and chasers are prepared as **Gmail drafts in the booking conversation, from the connected Gmail
  account**. The owner always presses Send.
- **Plan law 4 is amended:** Expend may write freely to its own Gmail labels, its own Google calendar and its own
  drafts, audited. It still never sends to clients, edits the owner's own calendar entries or moves money without
  the owner's press.
- **Job mail is read automatically by AI as it arrives, within the monthly AI cap**: only mail that looks like work
  (client domains, PO words, attachments, known threads); the rest is skipped and not stored.

Where section 2 or 5 says "every 2 minutes", read "hourly, or on Check now".

**Batch 1 is built (24 November 2026).** Law 4 of docs/intelligence-cycle.md is amended as the owner chose (section
5.1 there). As built:

- **Wording.** The README and Settings › Integrations say what Gmail access allows: reading, and Expend's own labels;
  never sending, moving or deleting.
- **Calendar fixes.** Cancelled entries (STATUS:CANCELLED, or a cancelled occurrence) aren't stored, so they stop
  counting. A feed that doesn't answer, isn't a calendar, comes back cut short or comes back empty never wipes the
  stored entries, and says so in plain words. The hourly refresh and Sync now both queue the job finder (it keeps to
  "find jobs automatically"; Sync now says which).
- **Possible jobs** (`Jobs\CandidateText`, on the to-do list). One from your calendar reads "From your calendar: Thu
  3 Dec, 08:00–18:00, Acme Arena", opens that entry on the calendar page, and offers **Part of <job>** when a job you
  have is within 3 days with the same client or place (never one you said it isn't part of, nor any once you said it
  isn't a job). Part of is one press (to-do action `cand_merge`, bound to what was shown, Telegram too) or opens the
  entry with the job chosen. The next 14 days come first. One whose entry has left your calendar says "No longer in
  your calendar" and opens the job finder.
- **Searching again.** Changing a job's PO number, dates, client, venue or postcode, or its days, searches again for
  what belongs to it, Gmail included (once per job; `JobController::SEARCHED_BY`); so does adding a possible job to a
  job. The search never looks in Sent mail (`-in:sent`), and leaves out an email with a part you said isn't the job's.
- **Invoice Message-IDs** (step 1 of 2D). Each invoice keeps one Message-ID (`invoices.message_id`, migration
  `2026_12_17_000001_add_message_id_to_invoices.php`); the invoice goes with it each time it's sent, and each reminder
  points back to it (In-Reply-To, References), by either mail route.
- **"Finished yesterday"** (the first step of idea 1). `ledger:job-finished`, daily at 08:37 London: "Acme Awards
  finished yesterday. Invoice it?" on Telegram with a link to the job, once per job (`settings.job_finished_told`), not
  for a cancelled or invoiced job or one whose Invoice item you snoozed. No draft is prepared yet (batch 4). *Since 25
  November it asks "All done?" with [Done], which gets the draft ready: see "The money end of batch 4" below.*
- **Tax pot, phase 1** (idea 2). The dashboard shows what to put aside at the average rate of income tax and Class 4
  on the expected profit (about £210 of a £1,380 payment at a £36,000 profit), and what's needed by the next payment:
  31 January (that year's balance plus the first payment on account), or from February the 31 July payment first.
  Choose a Starling Space or savings goal as the pot in Settings › Integrations (`tax_pot_space`); its balance is read
  at each Starling import (`tax_pot_balance`, needing the token's space and balance scopes), and new business income
  is told on Telegram with about how much to put aside. Expend never moves money.

Left from batch 1, for the owner or later:

- The reconnect in "Personal" mode (section 4) is yours.
- **Threading, corrected (25 November 2026).** Every reminder threads on the invoice's own Message-ID, by either route:
  the mail route (`App\Mail\InvoiceEmail::headers()`) sets In-Reply-To and References to it, and a Gmail reminder is a
  reply to the sent invoice in the same conversation (below). What differs is the subject. A reminder sent by Expend's
  own mail route still says "Reminder: invoice …", and Gmail on the client's side groups messages only when subjects
  match, so it may show there on its own. A Gmail draft (invoice or reminder) in the booking conversation says
  "Re: <their subject>" (never "Re: Re:", `InvoiceEmails::re()`), so it stays in that conversation on both sides; a new
  email says "Invoice INV-0042 · Arena load-in · PO 4471" (`InvoiceEmails::subjectFor()`). Sending an invoice again
  reuses its Message-ID, and Gmail drops a second copy with the same Message-ID in a mailbox that already has it, so a
  resend to the same address may not show again.
- Tax pot: tax deducted at source isn't counted; payments towards the coming bill are counted only as the payments on
  account you record on the tax year; money in still to decide isn't counted (the figure says "at least"). Starling
  balance snapshots into `statement_balances` are M8.
- A cancelled entry already linked to a job, and the days it made, are left as they are: flagging "cancelled, but the
  job still has a hotel or costs" is M8's `calendar.job_changed`, with the entry history (`calendar_event_versions`).

**The money end of batch 4 is built (25 November 2026):** section D and ideas 1 and 4, with the owner's answers of 24
November (reminders only when you ask; Telegram's one-tap Send only when safe; both "Issue and
send" and "Issue and put in Gmail drafts"). docs/PLAN.md §6.13 has the screens. As built:

- **Done, the morning after (idea 1).** `ledger:job-finished` (08:37 London, `app/Console/Commands/JobFinishedNudges.php`)
  asks "Arena load-in: last day was yesterday. All done? Marking it done gets its invoice ready to check." with [Done]
  [Not yet] / [Cancelled] [Open the job] (to-do actions `job_done`, `job_not_yet`, `job_cancel`, bound to the job as
  shown). A job finishes at the end of its last day in London time (`Jobs\JobStage::finishAt`); only jobs that finished
  in the last 60 days are asked about (`JobStage::DONE_WINDOW_DAYS`). It's asked once per job and finish
  (`settings.job_finished_told`, keyed `{job}@{finish}`, so days added later ask again), and once more the morning a
  "Not finished yet" snooze ends (`{job}@{finish}#{day}`). Three or more jobs on one morning go as one message. A job
  already done reads "its invoice is ready to check", or with no draft and nothing billed "Draft its invoice?" [Draft it]
  (`job_invoice`). Done makes or reuses the draft (`Jobs\JobStatus::markDone`, then `InvoiceService::draftForJob`) and
  `Invoices\InvoiceDraftSync` fills it: the work, the job's travel and expenses (each one listed, ticked where the client
  usually pays it), mileage, and the client's late costs from earlier jobs. Nothing is issued or sent by it. Issuing takes
  your two presses on the check screen: **Issue…**, then one of the sheet's buttons.
- **The booking conversation (section D, step 2).** `Invoices\BookingThread::candidates` picks, in order: the job's PO
  email's conversation (`Gmail\GmailLabeller::poMessages`), the conversation its enquiry or offer came in
  (`opportunities.thread_key`), then other conversations linked to the job from the client's own domains, newest first.
  None: a new email. A client whose `invoice_route` is `new` always gets a new email; `none` ("they pay without an invoice
  from me") gets no invoice at all. The issue sheet's "Use another conversation" lists them all (`GET invoices/{id}/threads`).
- **The draft** (`Gmail\GmailDrafts`, written by `Invoices\InvoiceEmails::prepare`): from the connected Gmail, To one
  address only (or none, for you to add in Gmail), never Cc, never reply-all; In-Reply-To and References point to the
  conversation's last message, with its threadId and the "Re:" subject (Gmail needs all three to keep a draft in the
  conversation); the issued PDF attached; and an `X-Expend-Ref` header (`{invoice id}/invoice/{first 8 of pdf_sha256}`,
  `reminder{stage}` for a reminder). The email stands on its own: invoice and PO numbers, the job and dates, the amount
  and due date, and travel and expenses at cost by kind; no card digits, references or postcodes. Each draft and send is
  a row in `invoice_emails` (kind `invoice` or `reminder`; route `gmail`, `mailer` or `by_hand`; status `drafted`, `sent`,
  `deleted`, `failed`, `replaced` or `removed`), audited as `invoice.gmail_drafted` or `invoice.reminder_drafted`.
- **When Gmail is slow.** `prepare` runs in the issuing request and waits at most 10 seconds (`InvoiceEmails::TIMEOUT`);
  meanwhile the page says "Putting it in Gmail…". If Gmail doesn't answer, `App\Jobs\PrepareInvoiceEmail` tries again a
  minute later, then twice more ($tries 3, backoff 180 and 360 seconds: about ten minutes). A retry adopts the draft an
  earlier try made (by its `X-Expend-Ref`, or by its PDF in the conversation when your edit dropped the header), so there
  is never a second draft. A draft you changed in Gmail is never taken for Expend's own: no one-tap Send, never deleted.
  When every try fails the row is `failed` (`invoice.gmail_failed`), with [Try again] and [Email it from Expend].
- **Your press sends it.** In Gmail (Open in Gmail goes to the conversation); with **Send it now** in Expend, which reads
  the draft as it is in Gmail (`GET invoices/{id}/gmail/preview`) and sends exactly that version (`POST
  invoices/{id}/gmail/send`; edited since: "You've changed it in Gmail since"); or in one press from the issue sheet with
  **Issue and send to <address>** (`POST invoices/{id}/issue-and-send`, To, subject and amount shown first) or **Issue and
  put in Gmail drafts** (`POST invoices/{id}/gmail`). Telegram's **[Send it]** is offered only when the draft is exactly
  as Expend made it and goes to an address an earlier invoice to this client went to (`InvoiceEmails::oneTapProblem`,
  checked again live on the press by `sendOneTap`; to-do codes `inv_gmail_send` and `inv_chase_send`).
- **How a send is seen.** `App\Jobs\CheckInvoiceEmails` looks at Expend's open drafts: when the invoice page opens, 2, 10
  and 30 minutes after issuing or Open in Gmail (`CheckInvoiceEmails::LATER`, `POST invoices/{id}/gmail/check`), and
  hourly at :23 (`invoice-emails-check` in `routes/console.php`). A draft gone from Drafts with a sent message carrying
  the PDF in the conversation (or found by `in:sent filename:"INV-0042.pdf"`) is recorded as sent: `sent_at`, `sent_to`
  and, when the invoice has none, its Message-ID from that message (`invoice.sent`, by the system). A draft gone with no
  sent message is "deleted": "The Gmail draft for INV-0042 was deleted without being sent" [Put it back in Gmail] [I sent
  it another way] (`invoice.gmail_draft_deleted`).
- **Expend can't see Gmail.** While a check can't read Gmail (Google's 7-day cut-off in Testing mode, for example),
  `settings.invoice_emails_blind` keeps since when and why; Telegram says so once, and no "not sent" message goes until
  a check works again. The to-do item reads "did INV-0042 go?" with [Yes, I sent it].
- **Fallbacks.** With Gmail not connected or without `gmail.modify`, the issued view offers [Email it from Expend]
  (`InvoiceMailer`, the mail route as before), [Download the PDF] and [I sent it myself]. **I sent it myself** asks "When did
  it go?" (today by default; from the day it was issued to today; `POST invoices/{id}/sent-self` with `on`), and its terms,
  so when it's overdue, count from that day (`InvoiceEmails::markSentSelf`, audited `invoice.marked_sent` with `sent_on`).
  The to-do button `inv_sent_self` says today, with a "Pick a date" link to `/invoices/{id}#sent`.
- **One route at a time.** While a Gmail draft of the invoice or of its reminder is open, `InvoiceMailer` refuses to send
  that kind ("Invoice INV-0042 is in your Gmail drafts, ready to send: send it from there, or delete it there first."),
  the to-do list doesn't offer Email it, and old Email it and Send a reminder buttons are refused (their fingerprints
  carry the open `invoice_emails` row).
- **Reminders, only when you ask (idea 4, the owner's answer).** **Make a reminder** on the invoice page (`POST
  invoices/{id}/reminder`), the to-do list's and Telegram's `inv_chase` write it as Expend's own Gmail draft
  (`Invoices\ChaseLadder::make`): a reply to the invoice as it was sent, in the same conversation, to the address it went
  to, with the PDF again. Nothing scheduled ever writes one (the comments in `routes/console.php` say so).
  Its words go by days overdue, counted from the later of the due date and the day it was sent plus its terms
  (`ChaseLadder::STAGES`: from 3 days "not received yet", from 14 asks when it will be paid, from 30 asks for payment this
  week); part paid, it thanks them for what came in. No statutory interest is added. A reminder can only follow a sent
  invoice: all three paths refuse an unsent one ("Send the invoice first: a reminder can only follow it.",
  `ChaseLadder::NOT_SENT`). One reminder draft is open at a time: asked again at a later stage, Expend's earlier unedited
  draft is replaced; one you edited stays. Expend takes its own unedited reminder out again once the invoice is paid,
  closed or voided, or a payment that may be for it waits in the Inbox (`ChaseLadder::tidy`, run by `CheckInvoiceEmails`;
  `invoice.gmail_draft_removed`), and leaves one you edited, saying so. With no usable Gmail, `inv_chase` emails the
  reminder through `InvoiceMailer` instead (its label then reads "Send a reminder", on the invoice page too; with Gmail,
  "Make a reminder"). That email starts as the Gmail reminder does (`ChaseLadder::opening`): part paid, it thanks them
  for what came in and asks for what's still to come, never "I can't see a payment yet" for the whole amount; its sheet
  says what it will claim, and warns when a payment that may be for it is waiting in the Inbox.
  [They've paid] (`inv_paid_wait`, `POST invoices/{id}/paid-wait`) snoozes the overdue item for 7 days, takes Expend's
  own reminder draft out and points to Link a payment (the invoice page's `#payments`, with a waiting payment that may be
  for it picked); only your press links the payment.
- **Telegram, persistent but not spammy** (`ledger:invoice-nudges`, daily at 09:13 London, now every day rather than
  weekdays; `app/Console/Commands/InvoiceNudges.php`). It only tells you; every button is yours to press:
  1. "The invoice for Arena load-in is still a draft." [Check the invoice] [Remind me Monday]: 2 days after Done, then
     weekly while it stays a draft.
  2. "INV-0042 (Arena load-in) is issued but not sent.": day 1 and day 3 after issuing, then weekly until it's sent or
     voided, worded by where its email is, with [Send it] only when safe (above), [Open in Gmail] and [I sent it
     myself]; or [Email it] with no Gmail.
  3. "The Gmail draft for INV-0042 was deleted without being sent", once.
  4. Overdue: at 3, 14 and 30 days overdue, then weekly (`invoices.overdue_nudged_at`), with its number, the bill-to name
     and the amount; [Make a reminder] (or [Send it] when your reminder is in your drafts as Expend made it), [They've
     paid] and [Not yet]. No reminder button while a payment that may be for it waits in the Inbox, or while the
     invoice's own Gmail draft is open. Overdue counts only once an invoice was sent (`ChaseLadder::daysOverdue`).
  5. "Expend can't see your Gmail", once.
  6. A reminder Expend took out of your drafts, and one you edited that it left there, once each.

  Quiet rules: nothing between 21:00 and 08:00 London; messages 1 to 3 wait while today is one of your job days, for at
  most 2 days; at most one message per invoice a day. Late costs and draft changes go only in the Monday digest. What was
  told is kept in `settings.invoice_nudges` (`{key: {at, n}}`), not the audit log: a message isn't a change.

Not built from section D and ideas 1 and 4: the change check watching unpaid invoices' threads and remittance emails; the
mailto PO request as a draft; statutory interest (the decision below is still open); receipts attached to invoice
emails (the email says "Receipts are available if you need them.").

*For the owner, 24 November 2026. Code facts come from the repository at commit 3384b60. Google and legal facts were checked against official pages (section 6). "Unverified" marks anything nobody could confirm.*

## 1. Short answer

- **Do nearly all of it, some parts in a different shape.** Some exists already: Gmail is searched when a job is added (the README is out of date), and calendar entries already become "Possible jobs".
- **The one change that will help most:** a new Google Cloud project in "Personal" mode, connected once with the permissions in section 4. Your Google connection is in "Testing" mode, so Google cuts Expend off every 7 days, silently stopping every Gmail search and label. Personal mode is made for one-person apps: no cut-off, no "unverified app" warning, no Google review. Then Expend can check Gmail every two minutes and read job emails as they land. Today a PO in Gmail can wait a week for the Sunday rescan, and under the default AI setting it is never read, so it never counts as a PO.
- **Order (section 5):** reconnect and quick fixes; continuous mail; the calendar; then the money end and the timeline.
- **Your priority:** I agree with all three, but I'd swap the first two and add one thing before the third. Calendar "possible jobs" mostly exists, so finishing it is small. Continuous PO discovery is the biggest real gap. A "the job finished yesterday: invoice it?" message with a ready draft is worth more than the timeline, because unbilled work is where money is lost. Build the timeline after M4's job close-out check, so the page, the to-do list and the history agree.

## 2. Your suggestions, by theme

Effort: small = a day or less; medium = 2–4 days; large = a week or more. "M" numbers are milestones in docs/intelligence-cycle.md. Throughout, anything sent to a client, any change to your own entries and anything that moves money needs your press. Expend may write freely to its own labels, calendar and drafts.

### A. Continuous discovery of POs and job mail (M-1, M-2, B-2a, B-2b)

- **Today:** Gmail is only searched: weekly (Sunday 05:23), by the "Search Gmail" button, and once per job when it's made or "Search again" is pressed. The forwarding mailbox is checked every 5 minutes. Under the default AI setting ("on demand"), emails are only scanned by rules for work offers: PO attachments aren't read, so they don't count as POs or wake the job finder. Editing a job never searches again (fixed in batch 1), and conversations aren't fetched. The "not invoiced" to-do already stays until you bill.
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

- **Today:** invoices and one fixed reminder go by Hostinger mail or SMTP, each in a new thread. No Message-ID (an email's unique tag) is stored, so reminders don't even thread with the invoice (batch 1 stores one; see the top). Replies go to your business email: if that is the forwarding mailbox they show as "Held" to-dos, otherwise Expend never sees them. Nothing looks for remittance advice.
- **Verdict:** do it, in two steps.
- **How:** (1) Whatever the route, give each invoice email a fixed Message-ID and thread reminders onto it. (2) Expend writes the invoice or reminder as its own Gmail draft in the booking conversation, subject "Re: <their subject>" (Gmail splits threads when the subject changes), with the invoice and PO numbers in the first line. Telegram: "Invoice 43 is in your Gmail drafts, in the PO thread [Send it] [Open in Gmail]". To is the billing address, never reply-all; per client, choose the booking thread or a new email to accounts. The change check then watches unpaid invoices' threads and remittance emails; the bank stays the proof. The mailto PO request becomes a draft too. If Google is unreachable, fall back to today's route.
- **Needs from you:** decide whether invoices go from Gmail, and from which address (your Gmail, or your business address as a "Send mail as" alias).
- **Access:** gmail.modify already allows drafts and sending, so "never without you" is Expend's rule, not Google's; the plan's security notes, which assume read-only, need updating.
- **Your rules:** sending is always your press, even under M9–M10.
- **Risks, effort:** the booking contact may not be accounts. Threading on the client's side isn't guaranteed (Gmail groups automated mail only within a week), so each email must stand alone. Drafts can be forgotten, so the "not sent" to-do keeps burning. Step 1 small, now; step 2 medium, with M8.
- **As built (25 Nov 2026):** both steps, as described under "The money end of batch 4" at the top: drafts in the booking conversation from the connected Gmail, To the billing address only, per client `invoice_route` (`thread`, `new` or `none`); the mail route stays as the fallback, one route at a time. A draft's lines come from what's linked to the job (`InvoiceDraftSync`), each with its source. The change check on unpaid invoices' threads and remittance emails, and the mailto PO request as a draft, aren't built.

### E. The calendar as a source of possible jobs (C-1, B-1, B-5a, B-5b)

- **Today:** largely built. Hourly, the job finder scores unlinked calendar entries; 3 or more becomes a "Possible job". Links survive moves, and the job and calendar pages show each other. Gaps: only title and place are kept, though the feed carries organiser, guests, notes, attachments and status. With AI off, score-2 entries only turn amber on the calendar page and score-1 shows nothing. The to-do item opens the Connections map and doesn't say "from your calendar" (both done in batch 1). Telegram buttons aren't tied to what was shown. Entries moved over 7 days away drop off the job page. Nothing checks tomorrow. Two bugs, fixed in batch 1: cancelled entries are stored as live, and an empty feed wipes all entries until the next sync. Invitation files in emails are never read as calendar data.
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

1. **Close out the morning after a job.** *Today:* "Draft the invoice" exists, but a draft from a job copies only a whole-job fee, so a day-rate job gets an empty draft. Recharged hotels are only a reminder. *Idea:* the morning after, Expend prepares the draft (PO lines, or day rate × days, recharges as lines, each with its source), mileage and stays: "Arena wrapped. Invoice £1,380 ready [Check and issue] · 168 miles, about £92 [Add]". Issuing takes two presses. *Effort:* medium. *Value:* high. M4–M5. *Built (25 Nov 2026), in this shape:* the morning after asks "All done?" with [Done] (see the top); Done makes or reuses the draft, which lists the work (fee, day rate × days, hours, travel days, a cancellation fee, or the PO's lines), every travel cost and expense linked to the job (ticked where the client usually pays it), bookings with no payment yet (at the booking's price) and the mileage trips you recorded, each with its source. Trips not recorded yet aren't added: the check screen says "Mileage not recorded yet" with [Add them], which opens the Mileage page.
2. **Tax pot.** *Today:* no "set aside" figure; Starling sync reads no balances. *Idea:* "Arena paid £1,380: put about £210 aside (estimate)" (at a £36,000 profit, the average rate) and "Tax pot £3,100 of about £5,400 needed by 31 Jan", read from your chosen Starling Space; from February, the 31 July payment on account comes first. 31 January 2027 (68 days away) usually brings the 2025-26 balance plus the first 2026-27 payment on account: half of last year's income tax and Class 4, less tax taken at source. A "Move to Tax Space" button would move money, so it needs your press each time and M9; Starling's specification shows it needs no extra signing, but test with £1. *Effort:* small. *Value:* high.
3. **Today's one thing.** *Today:* a weekly digest of 8 items and a total, and fixed-time nudges with sound. *Idea:* one silent message a day at your usual active hour, skipped on show days. It picks one item by money, urgency and minutes, with [Tomorrow] [Next], and never shows the backlog total. Other non-urgent prompts ride in it. *Effort:* medium. *Value:* high. M5.
4. **A chase ladder in the PO thread.** *Today:* one reminder wording, repeated weekly; no late-payment interest. *Idea:* Gmail drafts at 3, 14 and 30 days late [Send it] [Not yet] [They've paid]. Statutory interest is shown, and added only if you tick it, for business clients without their own interest clause: 8% over the Bank Rate, fixed per debt (11.75% where interest starts in 2026; 12.00% for January–June 2027, provisional until 31 December), simple daily, plus a one-off £40, £70 or £100. Example: £1,350, 40 days late: £17.38 + £70. *Effort:* medium. *Value:* high. *Built (25 Nov 2026), on demand only (the owner's answer):* the reminder is a Gmail draft in the invoice's conversation, worded by days overdue from 3, 14 and 30 days, made only when you press **Make a reminder**; Telegram nudges at those stages with the button. [Not yet] and [They've paid] are there; statutory interest is not.
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

- [x] Invoices and chasers as Gmail drafts in the booking thread, from the connected Gmail (chosen 24 Nov; built 25 Nov). A business "Send mail as" alias is still your choice in Gmail.
- [x] Amend plan law 4 so Expend may write its own labels, calendar and drafts (done: section 5.1 of docs/intelligence-cycle.md).
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
