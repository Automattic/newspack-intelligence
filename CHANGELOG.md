# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- The graph legend's text is 12px, the substrate's `--np-text-2xs`, and a test fails on any smaller size in the page's stylesheet.
- The Search words box keeps focus when its results arrive, through the substrate's `DataViewsList`.
- The views are inset 16px 20px, the substrate's shared page inset, instead of 16px 24px, and the DataViews skin rules come from the substrate's shared sheet rather than this page's bundle.
- The client or company box lists every matching name as you type, an alias or a spelling with other punctuation included, on the stock combobox. A click, or the arrow keys and Enter, picks one and Search updates at once; typed text alone picks nothing, so an entity key can no longer be typed into Search. The box's × clears the filter. Two entities sharing a name are listed with their keys beside it, and a merge names the record by its own name. The merge dialog, the watch form, "Is this about…" and the layout preview pick through the same box; the layout preview previews a pick at once, with no Preview button, and Escape closes it once the list has closed.
- The Watches table's column headers are plain text. They offered "Move left" and "Move right", which moved nothing.
- The New watch form spaces its fields, and its Delivery heading is lettered like the labels around it, as the team view's tier heading is.
- The single-key shortcuts toggle stays at the end of its line when the shortcuts are off.
- "Show more" and "Why this ranks" sit a space apart.
- Review answers each row with a check and a cross joined in one pill again, in place of the **No** and **Yes, it is** buttons. The Y and N keys, and what each says to a screen reader, are unchanged.
- Search's filter chips sit on the search field's line, the field is wider, and the Layout switch ends the line clear of the sheet's edge, so its border is whole.
- A pill's × reads in the pill's colour; it painted black.
- "Show more" and "Why this ranks" share one line again, and a focused one rings itself alone; DataViews made each a full-width block.
- The team view dialog spaces its fields, as the other forms do.
- The tab bar sits flush under the header, as the Station's does, and the views below it keep the page's padding.
- The Briefing stands on the shared card, a step lighter than the page, so nothing inside it reads darker than the surface around it.
- Review pages Suggested matches 50 items at a time and Suggested names 50 names at a time, so every row in a queue can be reached; it showed only the first 50 links. `review` takes `--after`, as the MCP door's `intel_review` does: an opaque cursor the previous page answered as `next`, naming its last item or entity, so a page holds the rows after it in the queue's order and judging one page never skips a row on the next; a cursor the read did not mint for the queue is refused. `total` counts the whole queue on every page. For links, `limit` and `total` count items, each answered with at most five of its proposed entities, one row each, the first by entity key, each row's `item_links` counting every entity the item proposes, and an item row says how many of its matches wait beyond those it shows. A judgment leaves its row in place, greyed and showing its verdict, with Undo on the row while the judgment waits to be sent, until its write has gone and no page its queue still reaches lists it, a page the cursors have moved past no longer counting; a page or a queue shown in another's place sends every judgment still in the undo window at once, so Undo and `z` only take back rows on the page shown, and an undo says "Undone."; a row listed again after that is owed a judgment again, an item whose every match is judged takes no bulk answer, and a refused write or a send that throws returns its row to its answers with the refusal under it, a refusal that names no send returns only its own queue's rows sent and not yet landed, every refusal off the page shown is listed with its row named above the queues, and a refusal goes only once its row is judged again. The tabs show the server's `total`, the themes tab less the pairs set apart, and the Review badge, which only a curator's page reads, shows no number while Review is open and takes its count from a one-row read elsewhere. A move to a page Review cannot turn to leaves the checked run alone. `review links` reads a page, its count and its cursor in one statement; a topic tag beside a link the machine asserted no longer asks to be matched. Previous returns to the cursor a page came from, each queue keeps its page across a switch of tab, Review leaves a later page only once its read answers no rows and no next page, and a later page that cannot be read still offers Previous. While a page loads, the list keeps the page it shows, its number and its pager included, until the next lands, its "Updated" time with it, and Next pressed meanwhile turns nothing, so focus stays on it.

## [0.41.1] - 2026-11-11

### Fixed
- The Briefing's panel and its dividers take the skin's colours, so a dark skin no longer shows a light panel inside a dark page.
- The Ask button sits level with the question field instead of centring against the field and its label.
- An operator's Intelligence page no longer crashes with "node name collision: intelligence:fetch_now:tee". The header's fault notice rendered its fix a second time to build its spoken message, and the notice, the Details panel and a Briefing health block each drew the same fix under one name. The notice now speaks the fault's label, its state and its cause when the cause is plain text, and each place a fix is drawn sends it under its own scope. A cause holding markup, such as the `wp nodes activate` step, is no longer spoken.

## [0.41.0] - 2026-11-10

### Added

- **`copies <item>`**, the readable copies of an item's story, each with its source and day, and the item row's "and %d other copies" opens them. The MCP door offers it as `intel_copies`.

### Fixed

- A page's repeated reads (team views, watches, folders, the review queue, health) come from one live read, and a view never shows an old answer as fresh.
- Two layouts mounted on one page give their headings distinct ids; a trend chart with no subject says that none is picked.
- Excluding an item refreshes an open copies list and an open "How this was read", and a theme merge refreshes the trace. An item's copies count and its copies list read one rule, and `copies` reads the item's cluster once instead of once per readable item.
- **A churn item keeps its client.** A re-extraction that keeps a churn kind its check passed keeps the stored kind subject with it, and the Briefing files a churn report under the client it names, not the first client in the item.
- **The Briefing's category and otherwise counts span the week**, as its total does, not only the 50 items listed.
- **`add_link` follows the judgment ranks.** It is refused where a higher-ranked judgment rejects the link, and a confirmed mention can be added as subject. A link added under a merged key is written under its survivor.
- **"How this was read" opens for any item**, a key holding `/` included.
- **One write and one Undo per item list**, so `z` reaches a removed chip and Undo bars never stack. An answer's sources render again.
- **Composed pages:** block writes and the Ask box are inert while a layout is edited; a tab change or Back asks before discarding an edit, and Back closes a profile; a layout that no longer reads offers a curator **Restore default**; a block naming a team view reads through its filters wherever it stands; identical reads are sent once a page; jump links target headings by id; a lens holds no longer after the server stores it.
- Review keys are idle while Review is hidden; a merge cancelled mid-send still lands quietly; a cancelled drag disarms the Folders board; Search never repaints items after a refusal; the Excluded list is worded; an empty Mark-as-used day is refused; a watch says "saved" once the save lands; focus follows the spec in dialogs and emptied lists.
- A folder-board naming a collection that was never made is refused; a team view's week count reads no empty page; a checked list's remove matches its add.

### Changed

- **The page wears the substrate's standard header**, as the Nodes station and the event logger do: the `NEWSPACK::NODES` wordmark with "Intelligence" as its subtitle, beside the version and the host, in place of a page title of its own.
- **Requires newspack-nodes 2.152.0**, for the shared `Confirm` dialog, `CompositionEditor`'s `onDirty` and `status_text()`'s one-line scrub.
- Leaving a layout edit that holds no unsaved change, by a tab or Back, no longer asks to discard it.
- Theme groups page through the shared list's pager.

### Security

- **The Briefing's signals list only clients the caller may see.** A client only restricted items mention, carrying a churn note, no longer shows a reader its key, name or note.
- A layout naming a key whose merge chain is broken is refused without exposing the key.

## [0.40.0] - 2026-11-10

### Added

- **The Briefing, team views and profiles are block layouts on the substrate's composition layer.** A curator edits one with **Edit layout**, which loads the block editor for `tune` alone; seeds may carry `layouts`, written only to a page that has none. The Briefing is Ask, Since you last looked, Needs attention, Your watches and This week; a profile is `pulse`, `entity-facts`, `related` and `timeline` under a fixed header; a team view is a rail with each view's new count, a header with Watch, Edit and a menu, and the `trend-chart` and `item-list` blocks; the `health` and `folder-board` blocks place the health details and the folders board on any page.
- **The Ask box shows each step it takes**, what it searched and read, a marker per cited source with a preview, and which empty case an answer is; it takes the `ask` block's label, placeholder and examples.
- **Curators keep a churn-risk note on a client**, with an owner and a next step; Needs attention lists noted clients first, and readers see the churn notice without its buttons.
- **`add_link <item> <entity> [--role=subject|mention]`** links a readable item to an entity as the curator's confirmed link, even where no machine linked it; the resolver writes it on every pass until it is rejected. Item rows give curators an × on each entity chip (reject, with Undo) and an "Is this about…" picker.
- **An item row says why it ranks** behind "Why this ranks", counts its story's other copies in its meta line, and opens "How this was read" from its menu.
- **Review: check match or name rows into a run and answer them all with one Yes or No, under one Undo.**
- **The Folders tab is a board**: each folder a column of cards, opening on items not yet used; a card drags to another folder, or its menu moves it, marks it used or removes it, each with Undo.
- `search --tier` narrows within the reader's tiers and a lens's; `trends` answers `by` and `bucket`.

### Changed

- **Requires newspack-nodes 2.151.0.** Item lists draw through the substrate's shared `DataViewsList`, and DataViews loads from the substrate's one `newspack-nodes-dataviews` script instead of a copy in this bundle.
- **Lists run on DataViews** through `ItemList`: Search's Find items (Team view and Kind of news always shown, the rest behind Add filter, the client picked by name, Importance or Newest order, every filter in a sheet when narrow) and Review's queues. A team view now combines with other filters.
- **Review shows its three queues as counted tabs**, each suggestion with its reason and quoted evidence; Same as… confirms the merge in a dialog; a pair of themes offers Keep either one or Not the same; a curator's Review tab counts the suggested matches.
- Review and a watch's matches share one single-key implementation and one "Turn off single-key shortcuts" setting.
- **Collection status sits in the page header**; faults show in a notice under the tabs, one sentence for a reader and the fix plus Details for an operator; a source failing 10% of its requests or more reads "Failing". Watches is a table with confirm-to-stop and undoable seen marks, and its tab counts new matches.
- **A view filtering by role reads its role rows once**: Leadership's signals take 153 ms instead of 276, Support's 149 instead of 194. `Intel_Event::SOURCES` is the one list of item sources; each Source_Node records under its `SOURCE`, and a lens naming another source is refused.
- One `Graph_Query::saved_lens()` answers whether a lens was saved, for search, `remove_lens`, `save_watch` and the layout check.

### Fixed

- A run of review judgments, or several folder moves, refused together shows each row's own refusal.

### Security

- A layout that no longer reads no longer shows a restricted entity's key in the page source of a reader without `manage`.

## [0.39.4] - 2026-11-09

### Fixed

- **A feed item whose guid would pass the item-key cap records instead of being refused.** A key `feed:<guid>`, or a client's `feed:<domain>:<guid>`, that would run past `Graph_Curation::ITEM_KEY_BYTES` (512) as the stream writes it takes the guid's sha256 in the guid's place, in its lane, so one guid always keys alike and `Feed_Source_Node::lane_of()` still reads a client item's domain. Google News guids run past the cap, so each such story was logged `refused an item whose key runs …` and lost. A key within the cap is unchanged.
- **A source's failure logs as one line.** `Source_Node::fails()` and a Curl error's text turn each run of whitespace into one space, scrub invalid UTF-8, render controls terminal-safe and cut the text to 500 bytes on a character boundary, for every source. A feed parse error such as `CData section not finished` carried a line break and the feed's markup, so one failure wrote a second log line holding page HTML.

## [0.39.3] - 2026-11-09

### Fixed

- **An item's title follows the skin's ink.** It was drawn in `--np-text`, which no skin redefines, so on a dark skin a title sat near-black on a dark ground. It reads `var(--ink, var(--np-text))`, as the substrate's own text does.

## [0.39.2] - 2026-11-09

### Changed

- **Switching views on the Intelligence page adds a browser history entry, so Back and Forward step through them.** Each switch, from the tab bar or from a link inside a view, pushes `?view=<tab id>` through the substrate's `useQueryParamState` with its new `push` option, and Back or Forward reopens the view that entry names, which keeps the state it was left in. A link naming `?view=` opens that view; one naming a view the page does not offer, or `entity` with no entity open, opens the default: Watches for a `?watch=` link, else the Briefing. The default view is left out of the bar, so loading the page writes no history entry. `?watch=` links work as before. Building it needs the substrate's `src/` from this change.

## [0.39.1] - 2026-11-09

### Fixed

- **Review's single keys work from anywhere on the page.** While the Review view shows and the keys are on, `j` or `k` pressed outside every row, on the tab bar or the page body, focuses the view's first row, and `z` undoes the latest pending judgment from anywhere; `y`, `n` and `m` still act only on a focused row, and `j` and `k` still move within that row's list. A key typed in a field or inside a dialog, or with a modifier, is left alone, and with the keys off nothing is captured. One page listener, registered while the view is mounted, replaces each list's own handler.

## [0.39.0] - 2026-11-09

### Added

- **A Slack channel can skip bot posts.** `add_channel <id> --skip-bots`, on `Slack_Source` and `Context_A8C_Slack` alike, records none of the channel's bot posts, a message carrying a `bot_id` or of subtype `bot_message`, in its history or its threads; a bot's thread is still read, so the people replying under it record. A plain `add_channel` records bots as before, adding a channel again replaces its setting, `remove_channel` drops it, and `dump_config()` writes `--skip-bots` on the channel's add line. `Source_Node::checked_list()` takes the flag as an optional declaration, so another checked list can declare one.

## [0.38.1] - 2026-11-09

### Fixed

- **`wp newspack-intelligence ontology reextract` takes `--kind`.** Its synopsis spelled the flag `[--kind=<kind>[,<kind>]]`, a token WP-CLI's parser reads as unknown, so every run naming `--kind` was refused as `unknown --kind parameter`. It reads `[--kind=<kinds>]`, comma-separated as before, and a test holds every flag the plugin's commands declare to a shape WP-CLI parses.

## [0.38.0] - 2026-11-09

### Changed

- **Create and save is one command.** `create_collection` takes an optional `item` and saves it into the new folder as `collect` does, in one `collection_created` event the projector fans out into the folder and the item's placement, so the save no longer waits on the projector's flush; a refused item leaves no folder, and the new folder heads the dialog's Recent group.
- **Every key on screen is a reader's word.** The page vocabulary adds `kindWords`, `typeWords`, `stateWords`, `nameKindWords`, `predicateWords`, `sourceWords`, `signalWords`, `healthWords`, `tierWords`, `spanWords` and `axisWords` beside `roleWords`, `identifierWords`, `linkRoleWords`, `methodWords` and `markWords`, each localized, and the page data carries `operatorName`, the name the running topology's `LLM_Call` configures, '' for none. A churned item reads "Leaving <name>", else "Leaving", and the operator's product role "<name> product", else "Own product", in place of "Product of the organization". The tabs read Briefing, Search, Team views, Review, Folders and Watches. Item rows, theme groups, signals, trend charts and tables, a profile's meta line, details and facts, Review's queues ("Suggested matches", "Suggested names", "Similar themes", "Same as…"), Search's and a team view's filters ("Kind of news", "About", "Client or company", "Look back", "Include items that are", "Chart by", "Group by"), the Ask box's searched line, the watch list and form ("Watching", "<n> new", "Not listed here; email only", "Mark <n> as seen") and the health strip ("Matching items to clients", "Summaries", "Skipped", "AI requests waiting", "Running", "Up to date", "Healthy") show those words, never the stored key. A signal reads "Release jumped", "Published more than usual", "Published less than usual" or "Went quiet", its count "35, usually under 1". A profile shows its type, or Client or Former client for an organization holding one, its state only when it is not accepted, its item count only when it is not zero, and its ID and the IDs merged into it to a curator alone. A team view's form keys a new view by its name and picks kinds, roles and sources as tokens; a source filter takes only the six known sources. A key a word map does not word throws where it renders.
- **The extractor names whose churn an item reports.** The extraction prompt asks for `kind_subject`: for a `churned` or `churn_signal` item (`Intel_Event::CHURN_KINDS`), the name of the one client whose churn or churn signal it reports, the publication leaving where one merges into or moves to another, and null for any other kind. The extractor keeps it only for those two kinds and only when it names exactly one entity the item links; `extraction_recorded` carries that entity's key as `kind_subject`, which the projection writes on the item's `extractions` row, null otherwise. For those two kinds, a client's `status` counts and churn items, the Briefing summary's client counts and a theme row's `clients` read the kind subject, as its survivor, in place of subject links, so "Harbor Ledger merges into Prairie Signal", about both as subject, opens Harbor Ledger's churn alone. A churn item naming no kind subject claims no client, so until `ontology reextract --kind=churned,churn_signal` runs, every stored churn item claims none and every client's `churn_open` reads false. Other kinds still read subject links. `status` reads the items naming the client as a subject or a mention, and `last_item_ts` still dates the newest naming it as their subject.
- **Each extraction says why its item ranks.** The extraction prompt asks for `rank_reason`, one sentence saying why the item's importance is what it is. `extraction_recorded` carries it as one line, cut on a character boundary to `Intel_Event::RANK_REASON_BYTES` (512), the projection writes it on the item's `extractions` row, '' for an extraction carrying none, the stand-in among them, and every listed item answers `rank_reason`, null for an item never extracted.
- **A listed item answers its copies.** Every read answering items through `ITEM_COLUMNS` (`search`, a theme group, a timeline, watch matches and an Ask's cited items) answers `copies`, how many other items the item's cluster holds that the caller may read, each placed by the newest partition's `clusters` row, 0 for an item outside every cluster, so a restricted copy counts only for a holder of the tier.
- **Item rows lead with their subjects.** A row shows its first three subjects as pills, and every other entity it names sits behind "+N more" in the same row, a mention reading "<name>, mentioned", so a client an item only mentions never leads. Its meta line is cells joined by no mark: the source's initial and name, the day ("6 Nov", the year only when it is not this year, read in UTC) and the kind as a badge. Save stays the bare glyph, "Save to folder", and a new "More actions" menu holds "Open profile", for the subject its first pill shows, and "Copy link", whose outcome stands four seconds as a polite snackbar; on a narrow page Save moves into it. A title reads in Ink until it is hovered or focused, and a long name is cut short inside its pill, its accessible name whole.
- **Every entity chip is one pill.** The Briefing, Search, a team view, a profile and Review open an entity from the same full-round pill, the shared status badge's `is-pill is-info`, and Review's joined control rounds and squares it with its Confirm and Reject glyphs.
- **Signals read as a pulse line.** A signal is a sentence ("Offboarding: 6 last week, usually under 1."), then a 96 by 24 sparkline whose band is that signal's usual range, then the bucket still running, "11 so far this week", apart from the line; a quiet answer says "No unusual change yesterday / last week / last month." or that there is too little history, and draws no band. The line's accessible name says which signal it draws only when several signal.
- **Badges are sentence case.** The shared status badge no longer capitalizes or spaces its letters, so a badge reads as its words are written, as spec §5 sets for every label. Build against a newspack-nodes source carrying that badge.
- **A view's signals judge against history beyond its own days.** A team view's baseline buckets and its history read past its own days, so a 30-day weekly view, whose days cannot hold the nine weeks a signal is measured against, signals and is judged; that the Leadership view signals the offboarding rise it charts is to be verified on eve after the deploy. "Look back" still bounds what the view lists: a signal cites only stories dated inside it, while its count is the whole bucket's, and a view whose latest complete bucket lies wholly before its first day, as a day view looking back one day, signals nothing that bucket, though it stays judged. `trends` and which buckets of each series answer `in_window` stay bounded too. The read resolves stories only for the window's items and the copies sharing their clusters, and reads the history from the copies alone, in the one statement that counts the window, so a lens counting kinds over fewer days than the retention reads in one statement where it read in two.
- **Signals say whether a view changed, did not, or is too new to judge.** `signals` answers `judged`, true when any axis it carries signals for held the history its rule judges by, so a non-empty signals list is always judged, and names its `bucket` (`day`, `week` or `month`) and `baseline_buckets` (8). Each `trend` bucket carries `in_window`, false for a bucket the read's days never reach, and the bucket holding now answers apart as `partial: { bucket, count }`. Every signal carries its own series as `trend` and that series' usual range as `usual: { mean, low, high }`; for a spike or a burst it is the range the spike rule reads, so a count spikes only at or above `high`, which is at least 3, and a drop or a silence takes its client's weekly series and the range its rule reads, the same helper's with `low` at half the mean, so a drop counts at or below `low` and a normal week sits inside the band. A signal no longer carries `baseline`: it was always `usual.mean`, which every reader (the signals table, the digest's "against a mean of") now reads. The totals carry no range, because no rule judges a total. A signals card that lists no signal reads "No unusual change last week." or "Too little history to judge. A change is measured against the 8 weeks before.", named by the answer's bucket and count, never "Steady"; with a signal its sparkline draws that signal's series with its range behind it, its accessible name saying which signal when there are several, and it breaks at an out-of-window bucket rather than dropping to zero, reading it as "not read". A client's status line names its latest signal only when there is one. A source dates an item stamped after its retrieval at its retrieval, so a clock or a zone the producer got wrong no longer files it in a bucket no read counts; items already recorded keep their stamp until their date arrives. Build against a newspack-nodes source whose shared `Sparkline` carries `band` and draws a null value as a gap.
- **A tab or view returning paints its last answer at once, and refreshes behind.** The Intelligence page remembers each read's last answer in memory, at most 64, least recently used first out, and never in browser storage; a page load starts empty. A tab or view mounting again paints that answer at once and refreshes it behind, showing "Updated <when>" until the fresh answer lands. A refused refresh forgets the remembered answer and shows the error alone, so a reader whose grant was revoked never sees a stale restricted item. Every write names the reads it changes: a landed write forgets every remembered answer under them and every mounted read among them asks again, so an exclusion, a judgment, an ended client, a saved lens, a watch or a folder change never paints the answer from before it.
- **An answer still owed shows skeletons shaped like what it stands for.** An answer still owed shows skeletons shaped like what they stand for: item rows for a list, lines for a prose card (signals, an entity's details, related entities, claims, the health strip's slices), never "Loading…".
- **A failed read is one polite sentence with Try again, everywhere a read is shown.** A read that failed says "Intelligence could not be read just now. Try again." as a polite status with a Try again button, everywhere a read is shown, the lens and folder pickers, the entity field, the health strip and the Ask answer among them; a refused write stays an alert. Only an operator sees a failed read's own words, muted beneath; the page provides the operator flag once from `canManage`. A store that did not open says "The intelligence store did not open, so this was not read. An administrator can check the status above."
- **The page is titled "Intelligence"**, its one `h1` in the substrate's page title, and its view panel takes its role from the substrate's `useTabPanelProps()`, so the narrow "View" select leaves no `tabpanel` without a tablist. It needs a newspack-nodes source carrying `title` and `useTabPanelProps()`.
- **The Briefing's "Since you last looked" baseline holds for the whole browser session.** The page copies the reader's last look into the session once, so reloads and tab switches show changes since the same instant. A new last look is written when the reader leaves (`pagehide`, or the tab turning hidden) after the Briefing has been visible for 10 seconds across the session's pages, as the instant it was last seen. The lede is headed "Since Tuesday at 14:31", "Since yesterday at 09:05" or "Since 28 Oct" ("Nothing new since %s." when nothing arrived), and "This week" for a reader who never looked. A browser that refuses storage, or will not keep the session's copy, shows the Briefing without the summary. The last look moves to the `newspack-intelligence:last-look:<user>` key; the old `briefing:last-view` key is no longer read.
- **a ContextA8C token dir that will not list fails the pass naming it**, through `Source_Node`'s failing status and a rate-limited line, where it used to read as not signed in and publish idle.
- **Review's undo window sends every pending judgment on a tab switch and when the page is left, without a Router tick and in any hook order.** `useIntelWrite` declares its sends writes (`sendsOnRemoval`). `useDeferredWrite`'s `flush()` only runs each pending send: on a tab switch the write's Fetcher sends it as it is removed, and the flush runs in a layout cleanup, ahead of every graph teardown, so a component may call its write hooks before or after `useDeferredWrite`. On `pagehide` it then calls `sendQueuedWrites()`, which posts every queued write in the same task with `keepalive`, even on a background tab whose write Timer is stopped. Build against a newspack-nodes source carrying `sendsOnRemoval` and `sendQueuedWrites()`.
- **The operator is the deployment's to name.** No shipped prompt, copy, default or definition names who runs a deployment or the platform it runs on. `LLM_Call_Node` takes `set_operator <name>` and `set_tracks <tracks>`, which its topology row names as `operator` and `tracks`, and `Operator` holds both: the kind definitions, the clause an item a client publishes carries, the Ask answer's team line and each decision's last line, `Asked by: Newspack Intelligence, a plugin built on newspack-nodes, which tracks <tracks>.`, read them, the eval's requests included. With neither set the operator is `the organization`, translated where the page shows it, and it tracks its own work, the organizations it serves as clients, and their industry; a name set alone tracks its own work. `curl_request()`, `Prompts::messages()`, `Prompts::decision()`, `Prompts::extract()` and the Ask's two prompts take the `Operator`, and `Prompts::ASKED_BY` and `Ask_Prompts::TEAM` are templates read through it. Four phrases read differently for any operator: the publisher clause names "a client of <operator>", `churn_signal` leaves out "a client adopting more of <operator>'s products", `research` leaves out "engineering, audit, performance or planning work by <operator> or an organization it belongs to", and the Ask answers "for someone on <operator>'s team". The Intelligence page is localized the running topology's operator as `{ name, heading }`, which the Ask box's placeholder and the Briefing's count sentence and empty state name, and whose `heading` heads the Briefing's operator group, a configured name never recapitalized; and each role's and identifier kind's label as `roleWords` and `identifierWords`, which the Edit drawer's selects, an entity's role and identifier rows and Search's role picker show in place of the token. The client import's Settings section reads "Import Clients" and "Clients imported."
- **The model transport and the ContextA8C relay are the site's.** The shipped resolve topology sets no proxy url, model or feature, so a site's lists set `set_api_url`, `set_model`, `set_decision_model` and `set_feature` on `intel:llm`. Until they do, `intel:llm` answers each request at once as unlent, `{ unlent, unset }`, `unset` the setters the call's kind lacks: the extractor writes the heuristic stand-in and parks no retry, its status names the setters as `unset`, and the health strip says the AI proxy is not configured, naming them, where a fresh install parked five retries of every item for a call that could never be made. A ContextA8C source takes the MCP server's url as its `make_node` argument after the tier, `<tier> <mcp_url> <auth_dir> [<proxy>]`, in place of a constant, and the plugin ships no `newspack-intelligence-context-a8c`: a site running the workstation keeps that part in its user topology dir, as it keeps its lists, and uninstall skips a composition missing either (`Intelligence_Health::SITE_PARTS`).
- **Two stored vocabulary values are renamed (one-time migration).** The role `newspack_product` is `operator_product` (`Graph_Query::OPERATOR_PRODUCT`), and the identifier kind `atomic_site_id` is `site_id`. A client CSV is headed `Site ID`, any case, in its first column; a client found by neither its site id nor its domain is created as `organization:site-<id>`, named `Site <id>` when it has no domain. Stored events naming the old values no longer validate, so upgrading a site that holds them runs, under `wp nodes stop`, dndocker's `tools/migrate-intel-renames.sh <container> --dry-run`, then the same without `--dry-run`, then `wp newspack-intelligence ontology reproject`; on an Atomic site, over ssh, the same three steps run as `wp eval-file migrate-intel-renames.php dry-run`, then `wp eval-file migrate-intel-renames.php`, then the reproject. It reads every segment, reader dead letter and checkpoint the active topology declares, rewrites each renamed field where it lies, every line keeping its offset and length so no cursor moves and a line appended meanwhile survives, refuses a checkpoint holding an old value, backs each segment up first, validates every rewritten line, and names the backup to remove once the reproject is verified, since it holds restricted rows. A site's lists file using `set_operator`, `set_tracks` or the model transport lines, and its seed naming `operator_product` or `site_id`, need this release: an older plugin refuses them.
- An extraction records its kind check: when the decision model's review asks `fits_kind`, the `extraction_recorded` event carries `kind_check { kind, verdict, ts }` (`passed` at the kind's `Prompts::KIND_CHECKS` bar or above, `failed` below, `ts` the whole second it answered), projected onto the item's `extractions` row (`checked_kind`, `kind_verdict`, `checked_ts`), answered as `kind_check` beside each listed item's kind, null when it was never checked, and shown on the Intelligence page in the item's meta line ("launch passed its kind check on 23 Sep").
- A call's re-extraction keeps a stored kind that passed its check, with that check, whatever its own reply or review says; the extractor reads the stored check as it records the answer, and the importance floor applied is the kept kind's. A kind whose check failed, one never checked, or one that is not the kind its check passed is replaced as before.
- A review of an item whose stored kind passed its check asks the decision model neither `about_publisher` nor `fits_kind`, which only decide the kind the keep fixes; it asks the theme choice alone, and is not sent when no question remains. A check a concurrent extraction replaces while that review is in flight leaves the reply's kind recorded unchecked until the next re-extraction.
- A reply that does not parse publishes `the model's answer did not parse as an extraction`.
- **The intel-graph shape is version 12.** It adds the kept `excluded_items` table beside the kind-check columns version 8 added, an `item_time` column on `items` (an item's own date, else when it was retrieved), indexed as `items_item_time`, an `extractions_kind` index, the `kind_subject` and `rank_reason` columns on `extractions`, and, for `trace`, the recording `actor` on `items`, the extraction's `ts` on `extractions`, and the deduper's `actor` and `ts` on `clusters`. Upgrading requires `wp newspack-intelligence ontology reproject`, then `wp newspack-intelligence ontology reextract --since=<the retention's first day> --kind=churned,churn_signal` so stored churn items name their kind subject.
- **Model calls speak more than one wire protocol.** Each protocol is one row of `LLM_Call_Node::wires()`, carrying its path, the setters it needs, its headers, its body, its answer's reader and its per-prompt budgets. `set_protocol <ai-proxy|openai|anthropic>` chooses how chat calls are posted: the AI API Proxy's chat completions with its feature header, plain chat completions, or the Claude Messages API (`v1/messages`, the `anthropic-version` `set_api_version` names, else 2026-10-21; the system text as one cached block; each prompt's own `max_tokens` and effort, low but for the Ask's answer, medium, unless `set_effort` names one; never a temperature; refusal fallbacks unless `set_fallbacks none`; a `refusal` or `max_tokens` stop answered as an error). `set_decision_protocol <systemone|chat>` sends decisions to systemone, or asks each as one structured-output call through the chat protocol, read to the same decision. Both settings join the unset list, so a node without them answers every call as unlent: a deployment adds `set_protocol ai-proxy` and `set_decision_protocol systemone` to keep today's behaviour. `wp newspack-intelligence eval` asks through whatever protocol the topology's `LLM_Call` names. Model-call errors and the health strip name the model, not the proxy.
- **The substrate floor rises to 2.150.0**, the first newspack-nodes release with `Bootstrap::resolve_row_table()`, which `ontology reproject` calls, and the command rename. Release newspack-nodes first.
- **"Save to folder" is a bare glyph, and "Show more" a link.** Beside every item title, in a trend or signal's citations, Review's cited items and an entity's claims, Save is the archive icon alone, with no box, in the secondary text colour, turning the primary colour on hover and focus, centred on the line it shares, with "Save to folder" as its name and tooltip. An item's "Show more" and "Show less" are a link, with no border.
- **Ending a client never acts on one click.** The profile's churn notice names each report and the operator the client is leaving. Its End client..., the Edit drawer's client end and the health strip's Mark former client open one End client dialog naming the evidence, and "%s is now a former client." shows once the end lands. Not about this client rejects the report's link to the client after the undo window, with Undo.

### Added

- **Each team view answers its new items this week.** `lenses` answers `week_items` per lens: how many items it admits from the last 7 UTC days (`Graph_Query::NEW_DAYS`), today the last, as `search` through it reads them with those days in place of its own, under the caller's tiers narrowed to the lens's, so the team view rail can count them.
- **Review's queues and theme suggestions answer their totals.** `review` answers `total` beside its page of `links` or `entities`, how many rows the queue holds whatever the limit, and `theme_suggestions` answers `total`, every pair it scored, beside the ten it lists, each counted in the statement that reads the page.
- **An ask says which step it is on, and how many sentences it hides.** The Ask node writes `ask_planned { ask_key, owner, searches }` once its plan validates and `ask_read { ask_key, owner, found }` once its searches are read, each one line of the asks stream. Each is one `ask_steps` row, keyed by the ask and its stage, kept a week like its `asks` row, so a step never touches the outcome and the steps read the same in any order. `ask_result`'s `state` reads `asked`, `planned`, `read`, `answered`, `failed` or `withheld`, an ask owed its outcome reading the furthest step it reached with the searches its plan named and, once read, the items found; any of the three still owed past 900 seconds reads `failed`. `ask_result` also answers `hidden`, how many sentences it leaves out because the caller can no longer read an item they cite, as one expired or excluded since; an answer written from a restricted item the caller no longer reads is still withheld whole. The table is added at the current shape version, so it needs no reproject of its own.
- **How an item was read.** `trace <item>`, at read, answers one item's own decision record from the rows its events already wrote, in pipeline order, each step with its actor and its second: `recorded` (source and tier), each `linked` entity (role, method, confidence, its state, and the judgment that set it), `extracted`, `gated` or `heuristic` (kind, theme, rank reason), `checked` (the kind check's question, kind and verdict) and `clustered` (whether it is its cluster's canonical, and how many of the cluster's other items the caller may read). It reads under the caller's tiers and exclusions, refusing any other item as `no item: <key>`, and leaves out a linked entity the caller may not see. The MCP door offers `intel_trace` to a read session. The projection gains the columns it reads, so the shape moves to version 12.
- **An item can leave a folder.** `uncollect <collection> <item>`, at tune, writes one ontology event, `item_uncollected { collection_key, item_key, tiers }`, naming every tier of the item's copies in that folder the caller reads, as a move names them. The projection deletes those placements from that folder alone: a holder's restricted copy stays where a reader takes the item out, the item stays in every other folder, and its saved copies and marks stay. It refuses a folder never made, an item the caller reads no copy of there and an excluded item. The MCP door offers `intel_uncollect` to a tune session.
- **A client carries a churn-risk note, with an owner and a next step.** `note_churn <entity> [--text=] [--owner=] [--next_step=] [--day=]`, at tune, writes one ontology event, `churn_note_set { entity_key, text, owner, next_step, day }`, on the client's survivor, replacing its last note; each field is trimmed, the text and next step bounded at `Graph_Curation::NOTE_BYTES` (1,000), the owner at `NAME_BYTES` (256) and the day a Y-m-d date, each '' when unsaid, and a note with no text clears it. It refuses an entity the caller cannot see and one holding no open client role. The projection keeps one `churn_notes` row per client; a client's `status` answers `churn_note: { text, owner, next_step, day, by }`, the newest any of its keys carries, null for none, and the briefing's `signals` lists a noted client whatever it signals, every client row answering its `churn_note`. The MCP door offers `intel_note_churn` to a tune session. The table is added at version 11, so no reproject is needed.
- **`ontology reextract --kind=<kind>[,<kind>]`** re-extracts only the items any partition's extraction gives one of the kinds named, and refuses a kind that is not an item kind before it reads anything. Its count, its `--limit` check and its success line read the kinds, and its `--dry-run` now lists each item it counts of a tier the caller reads, `<item_key>: <title>`, the oldest first, says how many others it counts and does not list, and prints the command with `--kind`. The reresolver's `RERESOLVE` and `REEXTRACT` requests take a last `--kind=<kind>[,<kind>]` token, answer `kinds` beside the range, key the unit with it and, as for a source, publish no `last_range`; `Reresolver_Node::ask()` takes the kinds after the source and `count_dated()` takes them as its fourth argument.
- **Exclude an item from every view, and include it again.** A curator's `exclude_item <item> [--why=<text>]` and `include_item <item>` commands, at tune, each write one ontology event (`item_excluded`, `item_included`), offered in the Intelligence CI, the MCP door (`intel_exclude_item`, `intel_include_item`) and the CLI (`wp newspack-intelligence items exclude|include`). An excluded item drops out of every read — search, the Briefing, signals, trends, watches and their inboxes and instant emails, collections and their counts, the entity page, the Ask and the MCP door — and every curation command naming one (`confirm_link`, `reject_link`, `collect`, `mark_used`, `move_item`, `exclude_item`) refuses it as `<item> is excluded from every view`. `excluded [--limit]` (`wp newspack-intelligence items excluded`, `intel_excluded`) lists what is excluded with who, when and why, each at the tier its item reads at now. Every item row offers a reader holding tune "Exclude from every view", held five seconds behind an Undo; the Collections page lists the excluded items with "Include again".
- **Review's judgments wait five seconds, with Undo.** Confirming or rejecting a link, accepting or rejecting a proposed entity and merging a theme hide the row at once and send after five seconds; a snackbar offers Undo, and `z` in a queue does the same. Leaving the page or switching tabs sends every pending judgment at once, and a send that throws stops none of the others, every failure raised after the last. The key legend shows above the queues, a "Turn off single-key shortcuts" switch, remembered per browser, disables every single-key shortcut, and each judgment is announced politely to screen readers. The bundle needs the newspack-nodes release whose runtime exports `raise`.
- **The Ask box answers a question with cited items.** `ask <question>`, at `read`, appends an `ask_asked` to the new `intel:asks` stream, which keeps a week (`Ask_Node::KEEP_DAYS`, 7) and carries the restricted read gate, and answers `{ ask_key }`. The new `Ask` node, fed by its own `intel:asks:ask` Consumer, signs in as the asker as the watcher signs in an owner, asks the chat model through `intel:llm`, as new work, for one to three searches over `search`'s own filters (words, lens, entity, kinds, roles and days, each text at most 80 bytes, kinds and roles from the vocabularies Search's pickers offer, "our clients" being `roles: ["client"]`), refuses a plan naming anything else, runs each through the `search` command's declared args under the asker's tiers, and gives the second chat call at most 40 of the items found, numbered, each with what it is and its tier, fenced as untrusted, and told that the answer is for someone on the operator's team and that a ticket, chat message or internal post records the operator's own work. It records an `ask_answered` keeping at most 8 sentences, only those ending in citations of items it was given, only the items they cite, numbered again in citation order, the tiers of every item it was given and the searches it ran; nothing found answers with no second call, and a failed call, a refused plan or an asker who cannot be signed in records an `ask_failed` with the searches run. A question read, or a model call's reply landing, more than 900 seconds after it was asked is dropped and writes nothing. `ask_result <key>`, at `read`, answers the caller's own ask alone, refusing any other as `no ask: <key>`, withholds an answer whose items include a tier the caller no longer reads, hides a sentence citing an item gone since, reads an ask still owed past 900 seconds as failed, and names the searches and that wait. `Graph_Asks` holds both commands. The projector writes each ask into the new `asks` table, kept a week; it is added at the same schema version, so no reproject is needed on a site that never ran the Ask. The MCP door offers both as `intel_ask` and `intel_ask_result`, and the page's `AskBox` heads the Briefing and Search, keeps the ask across a switch between them, shows the searches it ran, and stops asking once the ask has waited 900 seconds. The decision model is never asked.
- **Every model call says which system it serves.** `Prompts::SELF` states that the request comes from Newspack Intelligence, a WordPress plugin built on the newspack-nodes substrate, and what it records, links and extracts; `Prompts::framed()` adds what that call does, and each chat prompt's system message leads with it. Each decision's state ends with one line, `Prompts::asked_by()`, after the item's fields, which keep its first lines. `useIntelWrite`'s `refresh` receives the landed answer.

- **Collections are a board of folders, each saved item carrying use marks (schema version 7).** `mark_used <collection> <item> <mark> <day>` records an `item_used` event on the copy of the item the caller reads, carrying that copy's tier, `mark` one of `newsletter`, `linkedin` and `site` (`Intel_Event::MARKS`) and `day` a Y-m-d date; marking an item so again replaces the day, and a mark is not taken back. `move_item <collection> <item> <into>` records an `item_moved` event naming the tier of every copy of the item in that folder the caller reads, `tiers`, a list of distinct tiers `Intel_Event::validate()` checks against `TIERS`; each copy moves with its own URL, title and marks, so a holder's move takes the reader's copy too and leaves neither in the old folder, a reader's move leaves the holder's restricted copy where it is, and a move into a folder already holding a copy of a tier it names leaves that placement's actor and time as they were, the projection writing each placement it enters with `ignore`, so no move leaves a copy the caller reads behind. A folder watch's inbox lists only the matches whose items the folder holds at a tier the caller reads, so an item moved out leaves the inbox and its unseen count. Both declare `tune` and refuse before writing, naming each folder by its name: a folder that was never made (`no collection: <key>`), an item not saved in the folder named, one saved only at a tier the caller does not read included (`<item> is not saved in <folder>`), a mark outside the three (`mark must be one of: newsletter, linkedin, site`), a day that is not a Y-m-d date (`not a Y-m-d date: <day>`) and a move into the folder the item is in (`<item> is already in <folder>`). Each tier's saved copy of an item is one row of the new kept table `saved_copies ( item_key, tier, url, title )`, and its place in a folder one `collected` row, now `( collection_key, item_key, tier, actor, ts )` keyed by the first three, so an item sits in every folder it was saved into, and a move fans out one delete of its placement in the folder it leaves and one `ignore` write in the folder it enters per tier it names, carrying no copy, so it stays inside one line; each mark is one row of the new kept table `item_marks ( item_key, tier, mark, day, actor, ts )`, keyed by the item, the tier of the copy it marks and the mark. A file at version 4, 5 or 6 is discarded when its Row_Table opens it, so the upgrade runs `wp newspack-intelligence ontology reproject`. The MCP door offers both as `intel_mark_used` and `intel_move_item`.
- **A folder lists its items unused first and filters by mark.** `collections <key>` answers each item with `saved_ts` and `marks: [ { mark, day } ]`, those carrying no mark first, then each run by save time, the latest first; a move counts as the save of each placement it adds, and a placement the folder already held keeps its own time. The Collections tab shows the folders as a board of cards; an open folder filters to every item, those not yet used, or those carrying one mark, shows each mark as a badge with its day, and offers a curator a control marking an item used on a day, today unless another is picked, and one moving it into another folder, each shown from the moment it is sent until a read reflects it.

- **The Briefing opens with "Since you last looked".** `briefing changes` answers a `summary`: from `--since=<UTC seconds>`, the window's first instant at the latest and by default, how many items arrived, how many open clients they are about, and each of `churned`, `churn_signal`, `incident`, `bug`, `launch`, `funding`, `award`, `feature_request` and `complaint` with any item, in that order, with its items and clients. The statement counting `total` counts it too. The page keeps when each reader last looked in the browser, under a key naming the plugin and the reader's user id, which the page now localizes as `userId`, and shows up to 5 lines, such as "7 clients started offboarding" or "3 bugs filed", each opening Search on that kind from the day of the last look. A first visit, or storage that will not be read, shows the Briefing alone, and an answer from a graph that would not open keeps the last look where it was. `briefing signals` refuses a `since` as `since applies to changes`.

- **`names` answers the entities a name prefix leads.** `names <prefix>`, at `read`, answers up to 10 entities (`Graph_Query::NAMES_LIMIT`) holding a name or alias whose norm, or a word of it, starts with the prefix's norm, and refuses a prefix whose norm holds fewer than 2 letters or digits, read off the index the resolver matches against, each once as its merge survivor and by the name every read shows it by: those whose whole name the prefix leads first, then by name. A rejected entity and a topic are not among them, and an entity the caller may not see is left out, so a proposal only restricted items cite answers a holder of the restricted tier alone. It judges every match's visibility in two statements, however many names match, and reads only the names the prefix leads, through a stored norm and its word starts, never loading the whole entity index. The MCP door offers it as `intel_names`.

- **The health strip lists dead client feeds.** A client domain whose host does not resolve (curl error 6) on three fetches running is dead: the feed source counts the run in the domain's row of its Table, beside its backoff, ending it on anything else the domain answers, so a timeout, a 5xx or a body breaks it, and every status it publishes carries `dead_clients`, each dead domain with the start of its run. `health sources` answers `dead_clients: [ { entity_key, name, domain, since } ]`, each the survivor of the entity holding the domain, by the name every read shows it by, listed only while it holds an open `client` role and the caller may see it. The strip shows each as a "Client feed" fault, "<name> (<domain>) has not resolved since <time>.", and offers a curator "Mark former client", which ends the client role and, only once that lands, asserts `former_client`, as the entity page's churn prompt does; both now end a client through one hook, `useEndClient`, which hides the client from the click, every dead domain of it on the strip and the prompt on the entity page, until a read no longer lists it, since the projector records the end only on its next flush, and moves focus to the next row or the strip, and to the entity's heading. A refused end shows the client again with the refusal; a refused assert keeps it hidden, because the end landed, and says on the strip and on the entity page, in a line that outlives the client's row and prompt, that the client is no longer a client but `former_client` was not recorded, and why.

- **The projection stores every name's norm and its word starts (schema version 4).** `entities` gains `norm`, indexed as `entities_norm`, beside `names.norm`, and a new kept table `name_starts ( start, norm )` holds, per norm, the norm read from each of its words on. `Entity_Index::led_by()` builds the index for one prefix from a range over `start`, through the same `built()` that `load()` builds the whole index with. A file at version 3 is discarded when its Row_Table opens it, so the upgrade runs `wp newspack-intelligence ontology reproject`.
- **`entity`'s `status` answers `churn_items`.** The five newest churn reports it rests on, each `{ item_key, title, url, source, day }`.

### Changed

- **Folders list the reader's five recent ones first.** `collections` answers every folder with `recent_ts`, the caller's own latest save or move into it of a copy of a tier the caller reads, an agent's under the caller's login among them, read from the projected `collected` and `item_moved` events, for the caller's `Graph_Query::RECENT_FOLDERS` (5) most recent folders, which list first, the latest first; every other folder follows by name, its `recent_ts` null, where the newest folder listed first. The "Save to folder" dialog, which replaces "Save to collection", offers those folders first under "Recent", the latest chosen, then every other folder.
- **A save never overwrites or reveals another tier's saved copy.** `collect` writes a copy of the item's own tier beside any copy another tier saved in the folder, where a reader saving an item a holder had saved at `restricted`, after the item's tier changed, replaced the holder's copy. A folder lists each item once, as the most restricted copy the caller reads, with that copy's marks alone; its count counts items, not copies. `collect` refuses only an item the caller reads a copy of in that folder, `<item> is already in <folder>`, so a reader is never told that a restricted copy exists. A watch on a folder matches an item when the first copy its owner reads was saved, so a reader's watch no longer matches an item at the moment a holder saved a restricted copy of it.
- **"Save to folder" sits on every item:** a proposed link's item and a proposed entity's cited items in Review, each claim's item on the Entity page, and the items each trend row and signal cites on the Entity, Lens and Briefing views among them.
- **Search picks kinds, roles and sources, and suggests the entity.** Kinds, roles and sources are multi-selects, kinds and roles taking only their own values, whether entered or typed with a comma, each sent as a repeated `--kind=`, `--role=` or `--source=` as before; topics stay comma-separated. The entity field suggests the entities `names` answers once 2 letters or digits are typed, each under its name, and shows a refusal `names` answers beneath it. The box keeps the text typed, so a name that leads another, such as "Quiet Harbor" on the way to "Quiet Harbor Weekly", can be typed past; the search, once run, sends the key of the name it holds, and a key typed or copied from a lens stays as given.
- **Signals always say something.** `signals` answers `trend` beside `signals`: the stories the about spikes count in each of the 8 latest complete buckets (`Graph_Query::SIGNAL_TREND`), the latest first and the bucket holding now left out, per week by kind for an entity named with no lens, else per the lens's bucket and by its axis over its filters and tiers, so `other` is muted on the kind axis and an untagged story is not counted on any topic or theme. Its totals ride the statement that counts the spikes, as one more arm over the same stories, so the trend costs no statement of its own. The Lens and Entity views draw it with the shared `Sparkline` in the Signals card, the latest bucket marked, beside each signal called out, or "Steady" where "No signal is seen through this lens." and "No signal is seen for this entity." stood; a client's status line reads "Steady" where it read "No signal". The bundle needs the substrate carrying `@newspack-nodes/shared/components/Sparkline`.
- **The Briefing's groups open collapsed.** Each client, operator, Industry and lens group shows its heading and a count of its items or signals; a click on the count opens it. A screen reader hears the toggle as the heading and the count, such as "Industry 3 items".
- **An idle source offers "Add credential".** A source idle for want of its Vault credential, Unthread's among them, links the Vault tab under "Add credential" where it read "Open the Vault".
- **A trend or signal read looks up URLs and titles only for the stories it cites,** at most 5 per bucket and value, where it read them for every story it counted. On eve's projection the two reads of every client's own output in `briefing signals` drop from about 0.37 and 0.34 seconds to 0.30 and 0.28.

### Fixed

- **A refusal naming several unreadable topologies chains every one of them.** `Worker_Route::running()` chained only the first, so an engine error behind a later one kept its file and line in the refusal a read caller received; it now chains `Worker_Should_Stop::combine()` of them all, and the substrate's `Core::caller_message()` holds the words back below manage.
- **The excluded list shows a reader only what its tier reads.** An item excluded at one tier that reads at another now is listed with the title and URL its row reads now, the copy captured at the exclusion only once the item's rows are gone, and its `why` only to a caller who reads the tier it was written at; an internal reader no longer sees a restricted copy's title, URL or reason.
- **Save to folder returns to the folder select after Create and save.** The new folder is chosen and held in the select until a read lists it, a reader's first folder included, and the name field clears. The dialog used to stay on the name field, where a second Enter re-sent `create_collection`, refused as taken or sent with a blank name.
- **A health row whose fix landed stays a fault until Refresh.** It shows the fix's answer and "Fix sent; Refresh to check." where its button was, so the same fix is not sent twice while health still answers the state it fixed; Refresh, or a read in another state, gives the row back with its button.
- **A source no longer re-records items an `ontology reproject` left out of the empty projection.** A pass, begun or resumed from what a stopped process left in the source's Table, waits until every projector reader has committed the end its stream held when the wait first saw it (`Projector_Node::ends()`, `short_of()`), logged as `waiting for the projector to read its streams to the end; opening no pass: <stream>`, and opens on the first Router tick after; a reader that appears while it waits is waited for too, and a stream recreated under its mark is judged by whether its reader has read it to its end. A TICK takes fresh ends and answers `fetching: 0` while it waits. The readers and their lags are read once a tick, however many sources wait, and a source with nothing left in its Table reads none. `reproject`'s success line says sources wait for the replay.
- **A client's churn, signals and counts read only the items that name it as their subject.** A client's churn report, its status counts, an entity's about signals and trend, a theme row's and a theme group's clients, and the Briefing summary's per-kind client counts no longer count a mention, so a mention never makes a client churn, spike or count under a kind or theme.


## [0.37.0] - 2026-11-08

### Added

- **Themes merge.** `merge_theme <from> <into>`, at `tune`, records a `theme_merged` event on the ontology stream, so it outlives the items it touches, and the projection keeps it in a new `theme_merges` table at the same schema version, which a restarted worker creates. Every read of an item's theme reads the survivor: a theme group, a trend row, a signal on the theme axis, the extraction's offer and the review's `theme_choice`. No item's row is rewritten. Reads and curation resolve a chain with one walker, `Entity_Index::walk()`, so both read a cycling chain alike, as the theme itself. The verb refuses a blank theme or one past 80 bytes, a merge into itself, one naming a theme that is not the theme of any item the caller may read, as written or as its survivor (`no theme: <theme>`), so a caller without the restricted tier reads one refusal whether a restricted item carries the theme or no item does, one that would cycle or run past 10 hops, naming the first theme it breaks that the caller can see and otherwise none, one into a theme that itself merges away, naming its survivor, or whose own chain does not resolve, and one by a holder of the restricted tier that would make its target the theme of an unrestricted item for the first time, judged by each item's newest theme. The MCP door offers it as `intel_merge_theme` to a session whose role covers `tune`.
- **`theme_suggestions` names themes worth merging.** It answers up to 10 pairs of themes from the last 30 days' readable stories whose stories share an entity they are about and whose names share a word that is not generic, the pair whose names share the rarest words first, each with both themes' story counts, the shared words and how many entities the stories share. A word more than 20 themes hold pairs none of them, and the read keeps only the best pairs as it scores them, so its work grows with the words rather than with the square of the themes. The Review tab lists them under "Themes", and a curator merges either theme into the other there.

- **`wp newspack-intelligence eval <file> [--check=<kind>] [--dry-run]` scores the review's checks against labelled items.** Each line of the JSONL file names an `item_key`, its `kind` and whether it `fits_kind`, and optionally `about_publisher` with the `publisher`. The command asks the decision model each label's questions over the stored item, through the `LLM_Call` node the topology configures, one call at a time, then prints each check's precision and recall at its bar and every miss. It never sends a restricted item, and says how many labels it skipped. `examples/newspack-intelligence-eval.jsonl` shows the shape. `LLM_Call`'s topology row now names its `api_url`, `decision_model` and `feature`.

### Changed

- **The entity page leads with status, and curation moves into an Edit drawer.** The record heads with its name, a client's status line, its type and counts; its signals and timeline follow, then a "Details" section of its names, identifiers, roles and topics. A curator's forms adding a name or an identifier, asserting a role and ending one sit behind an "Edit" toggle.
- **A churn report offers to end the client.** `entity`'s `status` adds `churn_open`, true when a readable item about the client dated after its client role began is extracted `churned`. A curator then reads "This client has a churn report." and an "End client?" button in the status line, which ends the `client` role and, once that lands, opens `former_client`; a refused end opens nothing. Nothing ends it automatically. A churn report on a restricted item names one only to a caller holding the restricted tier.
- **An item shows its client and subject chips, its mentions behind "+N more".** Each listed item's entity answers its link's `role` and whether it is an open `client`, an open client first, then a subject. An entity linked by no subject or mention never shows.
- **A lens shows its trends as one compact time chart above its groups.** The shared area chart draws the 5 values counting the most over the 8 newest buckets, a series each, and a screen reader reads every row as a hidden table. The trend table repeating the groups, its "Clients and items" column with it, is gone. A signal's bucket no longer wraps.
- **The theme offer adds the window's most-used themes.** After the themes of an item's 12 most similar neighbours, an `extract` prompt and its review offer the 12 themes the window uses most, counted by distinct stories among unrestricted items alone, the window and the generic themes left out being the neighbours' own, each with its newest story's summary as its example. It reads the theme merges in one statement, then the offer in one.

- **A client's own feed item is gated before its chat call.** An unrestricted item of a client's feed lane (`feed:<domain>:<guid>`) that an open client published and no call extracted before is first asked one `extract_gate` decision, `about_publisher` of the item alone. Below `Extractor_Node::ABOUT_PUBLISHER_MIN` (0.5) it spends no chat call: the heuristic stand-in, kind `other` and every score 0, is written as `machine:extract-gated@<version>`, which, like the heuristic stand-in, never replaces a call's extraction and does not count as one, so a re-resolution, `ontology reextract` and `extract: true` reach it again. At the bar or above the item is extracted and its review does not ask `about_publisher` again. A restricted item and a configured feed's item are never gated, and a lane item a call already extracted is extracted and reviewed as before. A gate that fails, its group lending nothing or its answer not reading, logs `extract gate failed: <why> for <item>`, publishes its text as `last_error` as a failed review does, and the item is extracted as any other is. `health extraction` reads a gated stand-in's actor as `heuristic`.
- **Template boilerplate links nothing.** A body line at least 8 items of one source carry, such as an offboarding checklist naming ten products in its steps, a "Generated with" footer on every pull request or a ticket template's headings, is left out of what the name, alias, acronym and NER steps read, so it no longer links those products or proposes them to every item. The title and the URL are always read, and the extractor still sees the whole body. The projection counts each item's distinct body lines in a new `boilerplate` table at the same schema version, which a restarted worker creates; it counts only items recorded after the deploy until `wp newspack-intelligence ontology reproject` replays the rest.
- **Extraction asks at temperature 0, down from 0.2,** so a re-run reads an item's kind the same way.
- **The review checks `release` too.** It asks whether the item reports a final software release, answering no for a pre-release, a release candidate, an alpha, a beta or a nightly build, at a bar of 0.5, so `plover-sdk 3.1.0-rc.2` writes `other`. The extraction prompt's `release` line says the same: a software release or version shipped for general use, a pre-release, release candidate, alpha, beta or nightly build being `other`, where it called every GitHub release page a release.
- **A client import holds a client a curator ended.** One holding neither `client` nor `former_client` open after a `client` row that held time is not reopened by a CSV row, nor through a new atomic id on its domain. `Client_Importer::import()` counts it in a new `held`, and `clients import` reports `<n> held by curation`.

### Fixed

- **A rejected entity shows on no item.** Each listed item's entities leave out one whose survivor is rejected, as `review links` already does, so a stored link to it no longer shows a chip.

## [0.36.12] - 2026-11-07

### Changed

- **The Briefing lists each item once.** An item about two or three clients sits under the first it names, so "the 50 most important" shows 50 entries.
- **A watch's inbox follows its target.** A match whose item has since left the lens or entity, as a re-extraction can move it, is neither listed nor counted.
- **Three kind checks read tighter.** `launch` leaves out a publication's own contest, event, quiz, club or column; `funding` keeps money for news and drops reader appeals; `feature_request` leaves out a vendor move or a data import, at a bar of 0.55.
- **A lens's groups rank by importance before clients.** Within a kind, the group holding the most important story comes first, so a grave bug no client is named on outranks a named client's styling fix.

## [0.36.11] - 2026-11-06

### Changed

- The substrate floor rises to 2.148.0, the substrate this release builds against.
- `tests/run-coverage.sh` runs under PCOV, through `scripts/lib/coverage-php.sh`. No runtime change.
- **The review checks six kinds, not one.** `launch`, `funding`, `research`, `feature_request`, `bug` and `incident` each ask the decision model whether the item fits, and a low answer writes `other`, so a launch checklist, a reader appeal, an internal audit, a one-site task, a planned change or a legal notice leaves the lenses. `Prompts::KIND_CHECKS` holds each question and its bar, set against the items the lenses showed.
- Stock topologies ship as `<name>.tsl.php`, so a web server runs their guard rather than serving them as text. Requires the substrate that resolves them.

## [0.36.10] - 2026-11-05

### Fixed

- **`departure` leaves client offboardings to `churned`.** It now reads a person leaving an organization or a role, not a client leaving Newspack, where "someone leaving" took offboarding tickets.
- **An explicit re-extraction reaches an item with no links.** The extractor skipped a resolution with no link and no candidate even when it carried `extract: true`, so an item whose links a sweep removed kept its stale kind through `ontology reextract`. Such a resolution is now never skipped; one without `extract: true` still is.

## [0.36.9] - 2026-11-05

### Added

- **Every listed item answers an `excerpt`,** the opening of its body as plain text, markup stripped, entities decoded and whitespace collapsed, cut on a word boundary to `Graph_Query::EXCERPT_CHARS` (800) with an ellipsis. `search`, theme groups, timelines and watch matches carry it, reading 4,096 characters of the body rather than all of it, and the MCP tools take it inside their fence. It is the one exception to an answer never carrying a body. `ItemRow` shows it behind a "Show more" toggle, collapsed at first, and offers none when the summary or title already holds it.
- **ContextA8C's Linear issues record whole.** `team-issues` cuts a description at 300 characters, so `Context_A8C_Linear_Node` asks `issue` for one that moved, keeping within the request cap, and an `issue` call that fails leaves the cut description, logged.
- **`item_grown` replaces a stored item's body with a longer one.** It projects as `set`, keeping the row's id and its FTS in step, and the resolver spends nothing on it. Each ContextA8C Linear pass walks the stored issues of the last 30 days still cut short, under the request cap across passes, so a re-extraction reads them whole. A plain repeat of `item_recorded` still changes nothing.
- **The `churned` kind:** a client that has left Newspack or is leaving, offboarding underway or done, the decision already made. `churn_signal` now means a hint that a client may leave, such as dissatisfaction with price or service, going quiet or weighing a competitor, and leaves out a client already offboarding. `entity`'s `status.kinds` counts `churned` apart, ahead of `churn_signal`, and the status line reads "2 churn reports, 4 churn signals, …".
- **A `launch` must have gone live.** `extract_review` also asks the noul question `went_live` of any reply of kind `launch`, in the same one request as `about_publisher` and `theme_choice`, so a launch no client published and offered no theme is now reviewed too. A `went_live` below `Extractor_Node::WENT_LIVE_MIN` (0.5) writes the kind as `other` before the importance floor applies, so a launch checklist or prep ticket leaves the `launch` kind while it keeps its theme. A missing or bad answer fails the review, which keeps the reply's kind and theme, and a restricted item is still never sent. `KIND_DEFINITIONS['launch']` is unchanged.

### Changed

- **Summaries run to two or three sentences.** `Prompts::SUMMARY_CHARS` rises to 600, and the prompt asks for what happened, who it affects and why it matters, read from the item alone, saying only what a thin item says.
- **An RSS item's body is its `content:encoded`, else its `description`,** so a feed's full text is what is searched, resolved and extracted.
- **`extract_review` states the item's source first,** `Source: <label>`, so a ticket is not read as news.
- **A canonical name wins over the name an entity was created with.** `Graph_Query::name_of()` shows the newest `canonical` name whenever one exists, where it did so only for an entity created under one of its own domains, so a machine's proposal shows the name a curator gave it. The feed still writes a title only while the name shown is one of the entity's own domains.

## [0.36.8] - 2026-11-05

### Fixed

- **More words stop carrying a fuzzy match.** `Resolver::GENERIC_NAME_WORDS` adds `journalism`, `local`, `online`, `community`, `public`, `nonprofit`, `center`, `institute`, `association`, `society`, `school`, `voice` and `company`, so two names sharing only these, such as two `<place> Journalism Project` sites or two `<place> Local` sites, no longer pair.

## [0.36.7] - 2026-11-05

### Fixed

- **`review links` leaves out a link whose entity was rejected.** A link to an entity whose survivor's state is `rejected`, read from the highest partition holding one, no longer lists, so a `ner_fuzzy` row a re-resolution keeps does not stand in the queue forever.
- **A name shaped like code is never proposed or fuzzy-matched.** `Resolver::recognized()` refuses, logged rate-limited, a name holding a `_`, a backtick, `::` or `()`, one lower-camelCase word (`apiFetch`), or a bare domain or file name (`DESIGN_SYSTEM.md`), unless an entity past proposal holds exactly that name. A word led by a capital passes whatever capitals it holds, so ProPublica and McClatchy are proposed.
- **A fuzzy match needs a distinctive shared word.** The NER step scores only names sharing a word outside `Resolver::GENERIC_NAME_WORDS`, so "Harbor Journal" no longer proposes a link to "Kestrel Journal", and a name holding no such word cannot fuzzy-match. An exact name still scores 1.0.

## [0.36.6] - 2026-11-05

### Fixed

- **A cooperative stop no longer splits a finished extraction.** `Asks_Model::finish_answer()` runs each finish inside `Event_Framework::uninterruptible()`, for the extractor and the resolver alike. A stop falling due while an answer finishes lets every write land and raises at the first check after it; before, the extraction row could land while its subjects, its deduper forward and its claims were lost, with nothing to replay them.
- **A failure to start a review no longer discards the paid reply.** When the themes read or the review request throws, the extractor logs it and records the parsed reply with its own kind and theme, where it parked and paid for another chat call, and at `MAX_ATTEMPTS` left the item with no extraction.
- **A review outage shows in `health extraction`.** A failed review publishes its text as `last_error` and sets `failing_since`, keeping `mode` and `last_ok_at`. An extraction no review follows leaves it standing, and a review that answers clears it. A recycled worker forgets the failure until the next one. The log line is `extract review failed: <why> for <item>`, rate-limited by the failure rather than the item.
- **A neighbour exactly `Prompts::THEME_DAYS` (30) days away is offered.** The window is inclusive on both sides, as "within 30 days" says.

### Changed

- **The theme read's work is bounded.** Each partition keeps its `Prompts::THEME_CANDIDATES` (36) best matches that hold a theme it may offer, so the themes and summaries are read for those keys alone. On eve's 16,578 items a title of common words falls from about 85 ms to about 9 ms a read.
- **`Entity_Index::keys_of()` answers an entity's keys,** the inverse of its merges, built once per index, so the extractor no longer rebuilds it per request; the feed and the roster read it too.
- **`ontology reextract` counts extraction calls.** The count, the `--limit` refusal, the success line and the dry run say "extraction calls", and the dry run adds that each can add one decision-model call, which `--limit` does not include.

## [0.36.5] - 2026-11-05

### Added

- **The decision model picks an extraction's theme from the themes offered.** A parsed extraction of an item that is not restricted, and that the projection offers themes, asks the `theme_choice` question: a `choice` among the offered themes, keyed `t1` to `tN`, each told by its theme and example, and `none`. An offered theme chosen with a `confidence` of `Extractor_Node::THEME_CHOICE_MIN` (0.6) or more is the theme written; a weaker choice, `none`, or a failed review keeps the reply's own theme.

### Changed

- **One `extract_review` decision asks every question an extraction is owed.** `about_publisher` and `theme_choice` go in one `systemone` request, the reply carried once and finished once, and `Prompts::questions()` reads which apply from the review's inputs. The decision prompt is renamed from `about_publisher` to `extract_review`. A request queued under the old name before a deploy answers `no prompt: about_publisher`, and its extraction keeps the reply's kind and theme. A failed review logs `extract review failed for <item>: <why>`.
- **A restricted item is never sent to the decision model.** Its data stays with the chat model alone, so it is asked neither question.
- **`launch` takes the preparation for a launch.** It is a site, product or publication going live or being prepared to go live, a client's or Newspack's, because excluding "one step toward a launch" read a client's launch checklist as a step and sent those tickets to `other`, while grouping by theme now folds the steps together.
- **`research`, `launch` and `other` leave internal work out.** `research` is a study, survey, report or analysis of the news industry, its audiences or its business, as published, and not Newspack's or Automattic's own engineering, audit, performance or planning work. `launch` is not an internal tool, process or deploy going live. `other` adds internal tooling, audits and engineering reports.

## [0.36.4] - 2026-11-05

### Added

- **A decision model judges whether a client's own article is about the client.** The 0.36.3 publisher clause still let the extraction model give a client's article about someone else a real kind. Now an extraction that parses for an item an open client published, of any kind but `other`, is first asked of the `about_publisher` decision. It posts `{ model, state, questions }` to `<api_url>/systemone` through `intel:llm`, under the same credential, frontier, window and backlog order as chat calls. A `noul` below `Extractor_Node::ABOUT_PUBLISHER_MIN` (0.5) writes the kind as `other`, before the importance floor applies; a `noul` at or above it keeps the kind. A decision that fails keeps the kind, logged, and parks no retry.
- **`set_decision_model <model>` on `intel:llm`'s config,** which `dump_config()` replays and the resolve topology sets to `decision-lux-9b`. A decision asked before it is set answers `<name>: set_api_url, set_decision_model and set_feature before a decision`.

### Changed

- **`Prompts::PROMPTS` maps each prompt to its kind, `chat` or `decision`,** and `Prompts::decision()` builds a decision prompt's body as `Prompts::messages()` builds a chat prompt's messages. A decision request carrying options is refused.

## [0.36.3] - 2026-11-05

### Changed

- **An extraction knows when a client published the item, and reads that client's articles about anyone else as `other`.** The resolver forwards `publisher`, the entity the item's `publisher` link names, beside the links, so a publisher the text also names is no longer lost. The extractor, as it asks, names that entity's survivor in `inputs.publisher` when it holds an open `client` role, and `Prompts::extract()` puts the name in the item's fence and adds the clause: an article a publication runs about anyone but itself is `other`, unless it is about the publication itself or the news industry. An item a non-client, a former client or no entity published carries neither, and its prompt is unchanged. A resolution whose `publisher` is not a string is dropped.
- **A lens's Value column is never narrower than its longest word.** `TrendTable` gives every Value cell, the signals tables' included, the class `newspack-intelligence__value`, which sets `overflow-wrap: normal` inside the lens over the lens's `overflow-wrap: anywhere`, so "release" and "incident" no longer break mid-word while a theme of several words still wraps between them.

## [0.36.2] - 2026-11-04

### Changed

- **Six kind definitions say what each kind is not.** `feature_request` is a client or user asking for a capability Newspack's product lacks, not a setup, styling, migration, onboarding, offboarding or configuration task for one site; `bug` is a defect in Newspack's shipped code, not a CRM record, one site's content or configuration, a launch snag list or internal tooling; `incident` leaves out a storage-quota warning, a legal notice and a delayed project; `launch` is a site, product or publication going live, a client's or Newspack's, and not one step toward a launch; `release` always takes a GitHub release page; and `other` names routine service and onboarding work, internal planning, team updates and kudos, and a publication's own journalism about anyone else. Each stays one line of `Intel_Event::KIND_DEFINITIONS`, and the kinds and their order are unchanged.
- **A feed title names its site through its words' initials too.** `Resolver::names_site()` also accepts a title whose words, read from the first, each spell themselves or their first letter and spell the domain's label exactly, only trailing words left over and the words used reading as a name: "Plover Tern Courier" names ptcourier.example, "Gull Ridge Sand Lake Post" gullridgeslp.example. The resolver's `domain_stem` link does not take initials, since an item title's leading initials can spell a short label by chance.
- **A tagline is not written as part of a client's name.** When the part of a feed title before its first ` | `, ` - `, ` – `, ` — ` or `: ` names the site, that part alone is written: "Wren Supper: Good Food For The Curious" writes "Wren Supper".
- **A Google News copy dedups against the publisher's own.** An RSS item carrying `<source>` whose title ends with ` - ` and that element's text records its title without the suffix, so the deduper's title norm matches the client's copy.
- **A theme names the event or initiative an item is part of, never a step within one.** The prompt asks for the level a reader tracks across clients ("community site launch"), never a step ("redirect rules for launch", "launch checklist"), a category of work or a kind.
- **An offered theme's example is its neighbour's extraction summary,** read from the newest partition in the same statement through the `extractions` key, falling back to the title when the summary is empty and cut to `Source_Node::TITLE_CHARS` (120), since a title can be a bare domain that says nothing.

## [0.36.1] - 2026-11-04

### Changed

- **A lens's theme trends table holds clients and items in one column,** headed "Clients and items", each row's client chips above its items, so the theme takes the width the Clients column held. A row with no clients shows its items alone; the kind and topic axes and the signals table keep their "Items" column.

## [0.36.0] - 2026-11-04

### Added

- **`wp newspack-intelligence ontology reresolve` and `ontology reextract` take `--source=<name>`,** so only the items of that source in the range are counted and sent. The flag is read trimmed, as `items purge` reads it; one given empty is refused rather than widened to every source, and so is one that no stored item is recorded under, in its own case, the refusal listing those sources sorted, read across every partition by `Items_CLI_Command::stored_sources()`; a node's name such as `news`, whose items record under `feed`, is refused. `Reresolver_Node::ask()` takes the source, '' for every one, which the `reresolve` verb passes, and refuses one of more than one word before anything is sent; it sends `RERESOLVE <since> <until> [<source>]` or `REEXTRACT …` alike, and the reresolver queues the unit as `<kind>|<from> <to> <source>`, paged and checkpointed as before. `Reresolver_Node::count_dated()` takes the source, so `reextract`'s `--limit` refusal, its dry run and its "no item" line count only that source's items, and every line names it. A range naming a source answers `{ since, until, source }` and publishes no `last_range`, since it covers only some of its days' items, so the health strip's dropped days stay shown.

### Changed

- **An extraction is offered the themes of the items most like it, not the most used ones.** The 60 themes most named over 30 days were offered to every item, so a theme used early was reused and offered more: "client styling implementation" took 98 unrelated items on eve, and "other" was the most common theme, at 232. `Prompts` now finds, as `intel:llm` sends the request, the `Prompts::THEME_NEIGHBOURS` (12) themed items whose title best matches the item's own title words by `bm25` over `items_fts`, under the `{title} :` column filter, an `OR` of its first sixteen words of at least two characters that hold a letter and are not stop words, each a quoted phrase, so bm25 weighs which are rare; a neighbour is another item, retrieved within `THEME_DAYS` (30) days either side of this item's own retrieval, so a re-extraction reads the neighbours of its own time, of an unrestricted tier, its theme read from the newest partition holding one, and one whose theme is a kind or generic is passed over. Their distinct themes are offered, each with its best match's title as an example, cut to `Source_Node::TITLE_CHARS` (120), as a JSON list of `{ theme, example }` still fenced as data in the user message, so the model can see that a theme does not fit this item. An item with no such neighbour is offered none, and an item naming no integer `retrieved_ts` is refused. The read is one statement, its matches materialized once and their themes read through the `themes` key, and nothing is cached: the process-wide theme cache and `Prompts::THEME_OFFER` are gone.
- **An item about more than `Graph_Query::MAX_ITEM_CLIENTS` (3) open clients names none of them for its theme group or its theme trend row,** as the briefing already files such an item once; the row's other items still name theirs, and the item still counts. One item naming dozens of clients had listed them all on its group and ranked it by them. The cap moves from `Intelligence_Briefing::MAX_CLIENT_GROUPS` to `Graph_Query::MAX_ITEM_CLIENTS`, which the briefing reads.
- **A theme trend row of one item shows its theme under Value and the item under Items,** as every other row does, where it showed the item's title in place of the theme and left Items empty.
- **`Prompts::extract()` takes the offered themes as a list of `{ theme, example }`,** where it took a list of theme names.
- **A theme that names a kind or says nothing is not written.** `Prompts::parse_extract()` leaves out a theme equal to an item kind, its underscore a space, or to one of `Prompts::GENERIC_THEMES`: `update`, `news`, `issue`, `bug report`, `general`, `misc`, `request` and `announcement`, `other` being a kind already.
- **The prompt asks for a specific theme.** A theme names the one problem or event the item is about ("site blocked from sending email", "bot account registrations"), never a category of work ("ui fixes", "client styling") and never a kind; two items share a theme only when they are about the same problem or event, an offered theme is reused only when its example is about the same problem or event as this item, and a new one is written when none is.

## [0.35.0] - 2026-11-04

### Added

- **`LLM_Call_Node` publishes its queue,** `{ queue: { new, backlog, in_flight }, at }` through `Publishes_Status`, under its name and worker partition: `new` and `backlog` the requests its frontier Table counts waiting under each priority (`Frontier::count()`), `in_flight` the calls the process has sent and not settled, `queue` null when a count is not answered, never a zero, and `at` the second it was written. It publishes on the Router's tick, at most every `QUEUE_PUBLISH_S` (5) seconds after a request, a take or a settle and every `STATUS_HEARTBEAT_S` (300) while the queue does not move, a clock stepped back counting as the interval passed; a change stays owed until a counted snapshot lands, a throw while counting is logged and tried at the next tick, and the snapshot lives three heartbeats.
- **The health strip shows the model-call queue.** `health extraction` answers `queue: { new, backlog, in_flight }` beside the extractor's mode, read from the topology's `LLM_Call` snapshot under the name its line gives it, for every reader: null with no snapshot, with counts the Table did not answer, or with one older than two heartbeats, never zero. A neutral "Model calls" row counts them while the queue holds a call, grouped in the page's language with a class or a part holding none left out ("5,735 calls waiting (5,735 backlog) · 4 in flight", "1 call waiting (1 new)"), and the strip's line counts them as work waiting, so it no longer says "Nothing waiting." while calls are queued.
- **Every extraction names a theme.** The `extract` prompt asks for `theme`, two to six words naming the problem or event the item is an instance of, with no client names, URLs or numbers, and offers for reuse the `Prompts::THEME_OFFER` (60) themes most named over the last `THEME_DAYS` (30) days by items outside the restricted tier, read from the projection once a minute as the topics are, when `intel:llm` builds the request it sends, so a request queued earlier offers the current themes. The offered themes ride after the item in a `<themes>` fence as data, never in the system message, and the reuse clause appears only when there are themes to offer. `Prompts::parse_extract()` reads a theme as inert words, lowercase letters, hyphens and apostrophes alone, its ends trimmed, cut on a character boundary to `Intel_Event::THEME_BYTES` (80), and leaves it out when that leaves nothing. `extraction_recorded` carries an optional `theme`, bounded like a proposal's `evidence` and refused when blank.
- **The `themes` table, `( item_key, theme, item_day )` keyed by `item_key`,** declared at schema version 3, so a restarted worker creates it and nothing is reprojected. An extraction naming a theme replaces its item's row, one naming none deletes it, the heuristic stand-in leaves it alone, and `item_purged` deletes it. It keeps 730 days by `item_day`, like every item-scoped table.
- **The `bug` kind,** a defect in Newspack's own product as Newspack files or fixes it, whoever reported it, told apart from `complaint`, a client's dissatisfaction. The prompt defines every kind in one line from `Intel_Event::KIND_DEFINITIONS`, beside the kinds, and `churn_signal` excludes a client adopting more Newspack and routine account or storage work. The lens lists, verb descriptions and the page's kind vocabulary carry `bug` through `Intel_Event::ITEM_KINDS`.
- **`search --group=theme` answers the items it reads as ranked theme groups,** `{ groups: [ { theme, kind, count, clients: [ { entity_key, name } ], newest_ts, items } ], total, available }`, through a lens or not; without `group`, `search` answers items exactly as before. Each item counts as its story, a syndicated copy once; a group is the stories sharing a theme, and a story none of whose copies names one is a group of its own, its theme ''. Where neither the call nor its lens bounds the days, it reads the last `Graph_Query::GROUP_DAYS` (30). `kind` is the most severe among every copy of the group's stories, a copy with no theme included, by the kind's place in the effective `kinds` list, most severe first, every kind alike when it is empty; `clients` are the open clients those copies are about by a subject or a mention, by name; `items` are its five newest stories, each as `search` answers an item, the most important first. Groups rank by severity, then clients, then newest story, then theme; `limit` and `offset` count groups, 20 to a page by default, `total` every group, and `sort` beside `group` is refused. A page costs one statement for its groups and stories and one for their entities.
- **`trends` counts by `theme`.** `Graph_Query::TREND_AXES` gains `theme`, so `save_lens` takes `--by=theme`; a theme row is `{ bucket, value, count, clients, items }`, its clients the open clients its stories are about, and an item with no theme counts under none. Each item's theme is read from the newest partition holding a row for it, so a stale row in another file makes no second value. A theme's signals follow the topic rule: its first surge may spike once a themed story precedes the window, and an unthemed story is no history.

### Changed

- **The substrate floor rises to newspack-nodes 2.147.0,** the first release whose `Frontier` answers `count()`, which `LLM_Call_Node` publishes its queue by.
- **`search` declares `limit` with no default,** answering 50 items, or 20 groups beside `group`, when the call names none; the `trends` and `save_lens` descriptions name their axes as a list.
- **`Prompts::extract()` takes the offered themes after the topic names.**
- **`Intel_Graph_Row_Schema::all()` leaves out an attached partition whose file lacks the table,** read from each file's `sqlite_master` once per connection, so a read of a table declared at the same version meets an empty table, not `no such table`, until each worker's Row_Table creates it.
- **A lens shows its items as ranked theme groups.** The Lens view asks `search --lens=<key> --group=theme` and renders each group it answers, in the server's order, as one row: its theme as a heading, its count, kind and newest day, and a chip per client it is about; "Show items" discloses its items as item rows. A group of one item is that item's row; a group or a theme trend row answering no `items` or `clients` shows none. Search with a lens picked sends no `--group` and still lists items. The pager counts groups ("3 of 41 groups"), and the "Ranked by importance / Show newest first" switch gives way to the fixed line "Ranked by severity, clients affected, then recency", so a lens's read sends no `--sort`. A lens counting trends by theme shows each trend row's clients as chips beside its count, and a row of one item as that item.

## [0.34.0] - 2026-11-04

### Added

- **`LLM_Call_Node` makes every model call without blocking the worker** (`make_node LLM_Call intel:llm ai-proxy`, with `set_api_url`, `set_model`, `set_feature` and `set_concurrency`). A caller sends a TM_STRUCT `{ prompt, item, inputs, options, carry, backlog }` keyed by its subject, `prompt` one of `Prompts::PROMPTS`, `options` only an integer `max_tokens` and a numeric `temperature`, and the carry holding what the caller needs back less the item and the inputs; the node queues it in the `intel:llm:frontier` sqlite Table it owns, member `<0|1>/<arrival>/<from>/<key>`, `<arrival>` the zero-padded microsecond it arrived, the item's body stored once, so a request with `backlog` false goes ahead of every backlog request queued before it, each priority in arrival order, and two requests for one item are two members, each answered, and when it sends one builds the messages through `Prompts::messages()` and POSTs `{ model, messages, ...options }` to `<api_url>/chat/completions` through its own Curl, which lends the Vault group's credential, sends `X-WPCOM-AI-Feature`, takes a 120-second `timeout` and a body of up to 1 MiB. At most `set_concurrency` calls run at once, refilled once half the window is free. Each answer goes TO the caller as a TM_STRUCT|TM_RESPONSE `{ content | error, item, inputs, carry }`; a group lending no credential on the api url's origin answers `{ unlent: <group>, item, inputs, carry }` at once. A stored request that will not send, an unknown prompt, an older row's shape, an unencodable body or Curl throwing, is settled and answered as an error and the next goes out; a take the Table cannot answer is logged and retried at the next tick, never thrown into the event loop, and a cooperative stop met while answering starts no new POST. A caller throwing on its answer is logged, never thrown into the event loop; a request in flight when the worker stops is sent again by the next one, and a clean stop sends none it answered again.

### Changed

- **The extractor and the resolver's NER step ask `intel:llm` and finish on its answer,** so a model call no longer holds the worker's event loop and stalls every source's fetch. Both take a third `make_node` argument, the model-call node (`make_node Extractor extractor intel:writer intel:later intel:llm`, likewise `Resolver`), refused when missing, and declared as a destination. The extractor names the `extract` prompt, its topic map, profile and audience as inputs, and carries the resolution less its item; the resolver names `extract_entities` and carries the record it was filled with less the item's fields, and resolves it again with the names in hand. The answer hands the item and the inputs back. Both callers send and read through the `Asks_Model` trait. A resolver answer whose resolution throws parks the record as the hold parks it, a re-resolution with its own `parked_at` and `fresh_after` and its `attempt` counted one on, so a record failing again and again still ages out a day after it first parked, and from `Extractor_Node::MAX_ATTEMPTS` parks nothing, logged. An extractor answer parks one retry per failure, after its write, never a second when the write then throws. A request `intel:llm` cannot read is refused TO its FROM with whatever of its item, inputs and carry it could read, so the caller parks it; only one naming no FROM is logged. Every line logging an answer's error or a message's FROM goes through `Core::terminal_safe()`. A re-extraction's requests (`extract: true`) are backlog, behind every new item. An unlent answer stands where "no client" stood: a heuristic extraction and no retry, or no NER step. An answer whose handling throws is logged and parks the item on `intel:later` (the extractor's retry, or the resolver's `{ ner: true }` re-resolution), never thrown into the event loop. `Resolver::resolve( $item, $ner )` takes `$ner` as `false` (none), `true` (owed; answers null when the step would run) or the names answered.
- **The extractor's status names the Vault group, `vault_group`, where it named `vault_id`,** on a heuristic extraction alone; the `health` slice `extraction` and its redaction below `manage` (`Intelligence_Health::OPERATOR_FIELDS`) read the new key, and the health strip's heuristic row reads "Vault group <group> holds no entry for the AI proxy."
- **The NER reply's parse is `Prompts::parse_entities()`,** beside `Prompts::parse_extract()`, with the same rules and log lines. `Prompts::messages( $prompt, $item, $inputs )` builds the messages of a prompt `Prompts::PROMPTS` names, and refuses any other.
- **`stale_timeout 600` stays in both compositions** until a measured run shows a lower value is safe, because a pass still holds work across ticks; no model call blocks a `fill()` any longer.
- **The substrate floor rises to newspack-nodes 2.146.0,** the first release whose `Curl_Node` takes a per-request `timeout` and `set_body_cap()`; an extraction prompt runs past the 64 KiB body cap and past the 30-second timeout an older Curl keeps.
- **Upgrade:** before upgrading, move the `AI-proxy` Vault entry into the `ai-proxy` group, with url `https://public-api.wordpress.com`, `auth_header` `Authorization` and `auth_scheme` `Bearer`, keeping its password. Until an entry of that group sits on that origin every extraction is heuristic and no NER step runs. A deployment that overrode `set_api_url`, `set_model` or `set_feature` on `resolver:config` or `extractor:config` sends them to `intel:llm:config` instead; those verbs no longer exist on either node, and `set_vault_id` no longer exists anywhere.
- **`Source_Node` and `LLM_Call_Node` share their Curl and Frontier half** through the `Owns_Curl_Frontier` trait, with a `configure_curl()` hook, `send_curl()` and `abandon()`, and settle only an answer under the FROM they minted, so a message from another node keyed as a request in flight settles nothing; `Source_Node::FRONTIER` and `owned_table()` come from it, unchanged.
- **The resolver prefers an accepted entity to a machine proposal for the same name.** When equal spans name several entities and no locality settles them, the one accepted or created entity among machine-`proposed` ones is asserted at the strength its hit earns, 1.0 for a name or 0.9 for a stem, and the proposals get no link. Two or more accepted entities still propose each at 0.5.
- **A name the stoplist rejects never links an entity of its type by a match.** A name a rejected entity of type T carries, found as a name, alias or acronym in a title or body, as a domain's stem in a title, or by the NER step's fuzzy match, no longer links an entity of type T, even an accepted one. `Entity_Index::names_in()` marks such a hit, and `Resolver::stemmed()` a stem hit by its squashed spelling or its surface, so "News Room" counts as the rejected Newsroom; a marked hit still claims its span, so "Kestrel" inside a stoplisted "Kestrel Mail" links nothing either. The fuzzy step neither scores such a name for that type (`Entity_Index::name_norms()`) nor links a winner the extracted name is stoplisted for, and a claim's subject or object never resolves to it. A rejected entity's localities and acronyms are not stoplisted, and an acronym hit never is, since it matches only in its own case. A stoplisted co-winner leaves an equal span or a fuzzy tie ambiguous, so the rest is proposed rather than asserted. A claim still resolves a stoplisted name to an entity the item asserts by an identifier, so a client's own site naming it keeps its claims. A client organization that took a generic site title the seed rejects as an organization, such as "Newsroom", is no longer linked to every item using the word. An entity of another type still links by the name, so a product created over a rejected organization of its name keeps linking. Proposals stay blocked by a rejected name whatever its type, and identifier links are unchanged, so the client's own site still links it as `publisher`.
- **`Entity_Index::holder()` answers who holds an identifier**: a live holder's survivor, else a rejected or unresolvable holder's key. `Graph_Client_Roster` and `Graph_Curation::add_identifier()` read a holder through it; `add_identifier` loads the index for it in place of its own `identifiers` query.
- **A feed item whose copy is already held records nothing.** Two items are one copy when they come from the same source, their canonical URL (`Url_Canon::of()`) names a host, and their raw ids, the guid past `feed:` and a client lane's domain, are equal; a configured feed's item is judged by its key alone, so a third party's copy of a client's post never stands in for the client's own. `Source_Node::record()`'s one projection read also finds the source's items at those canonical URLs, and its bounded seen set remembers each item once, by its copy or else its key, so a post sister feeds list under one guid records once, while a podcast's episodes sharing one link each record. Only a source whose `raw_id()` names one judges copies, the feeds alone, because a P2 comment shares its post's canonical URL; an item whose URL is empty or names no host is judged by key alone.
- **The briefing's "Last seven days" reads an item as about an entity only through a `subject` or `mention` link.** `Graph_Query::most_important()` reads through `Intel_Event::NAMING_ROLES`, so an item only tagged with a topic no longer qualifies; search and lenses still read `ABOUT_ROLES`.
- **Importance ties break by relevance.** `Graph_Query::MOST_IMPORTANT` orders by importance, then relevance, then time, then key, and both the briefing and the search's `importance` sort read it, so a churn signal outranks a newer bug report scored the same.
- **`add_importance_floor <kind> <min> [<relevance>]` lifts an extraction only when its relevance is at least `<relevance>`**, 0 when omitted. A relevance outside 0 to 10 (`Prompts::MAX_SCORE`, the scale extraction scores clamp to) is refused, naming it, and `dump_config()` replays it only when it is not 0. The heuristic stand-in scores relevance 0, so a floor naming a relevance leaves it.
- **The briefing groups Newspack's own work apart, and every group names its role.** In `briefing changes`, each group is `{ entity_key, name, role, items }`: a client's carries role `client`; an item about no open client but about an entity holding an open `newspack_product` role sits in one group of that role; every other item sits in one group of role null. The Briefing view heads each group by its role, a client's chip, "Newspack" or "Industry", in the answer's order. `Graph_Query::NEWSPACK_PRODUCT` names the role beside `Graph_Query::CLIENT`.
- **A story counts once in trends and signals.** An item the deduper placed in a cluster counts as the cluster's canonical, the cluster and its canonical each read from the newest partition holding a row, when the caller may read that canonical, else as itself; an item two partitions hold counts once. Every read dates the story by the earliest of its copies the filters admit and cites it by the canonical's key, URL and title. `trends`, both signal axes, `spikes()` and the signals' history check count this way, in one statement per axis, so seven syndicated copies of one story count one, and a story whose copy precedes the signal window is history rather than a count inside it.
- **`other` never signals on the about axis.** It is the kind that says nothing, so the about axis joins none of its extractions when it counts by kind (`Graph_Query::MUTE_KIND`), in `signals`, `spikes()` and `signals_of()`, and they neither spike nor count as history there; `trends` still counts them, and a publisher's `burst` of it, a `drop` and a `silent` are unchanged.
- **A client's status counts churn signals.** `Graph_Query::STATUS_KINDS` is `churn_signal`, `feature_request`, `complaint`, in that order, so `status.kinds` answers all three. The Entity view's status line counts churn signals first, as "3 churn signals, 2 feature requests, 5 complaints".
- **A claim answers names and its item's title.** Each `claims` row adds `subject_name`, through `Graph_Query::name_of()`; falling back to the key; `object_name`, the object's name, else its key, when `object` is a key the caller may see, read for every such key in one follow-up read, and null only when `object` is the item's spelling; and `title`, the cited item's. Every earlier field stays.
- **Trends list the newest bucket first**, then the highest count.
- **An item about many clients appears once in the briefing.** In `briefing changes`, an item about more than `Intelligence_Briefing::MAX_CLIENT_GROUPS` (3) open clients is filed once, in the `newspack_product` group when it is about a holder of that role, else in the group of role null, rather than under each client; its entities still name every client, so its chips are unchanged. An item about three clients still sits under each.
- **An about-axis spike by kind needs the kind's own history.** In `signals`, `spikes()` and `signals_of()`, for a lens or an entity alike, a kind spikes on the about axis only when that same kind was counted before the oldest baseline bucket, read across the whole retention whatever days a lens keeps, because a first appearance within the window is not a change: a client feed's two-week backfill, one item in the baseline and five in the latest week, no longer spikes, while a 60-day lens still spikes on a kind counted long before. Any other axis keeps the earlier rule, so a new topic surging through a topic lens spikes. `Graph_Query::tally()` reads that history per value only when the rule needs it, in the statement that counts the window, or in one more for a lens keeping fewer than every day. The publisher axis is unchanged: a kind new to an entity's own output still bursts, and `drop` and `silent` read as before.
- **A domain stem may lead with an article its domain spells, and a site title may hold lowercase connecting words.** The stem match in a title and `Resolver::names_site()`, the feed's test of whether a site title names its client, share one test, `Resolver::reads_as_name()`: each word capitalized, but a lowercase `de`, `in`, `of`, `and` or `the` between two that are. "The" no longer refuses a stem's span, so "The Citizen" reads as `thecitizen.example`'s stem, title-case sentences included, while "A" or "An" still refuses one, so "A Newsroom Guide to Elections" links nothing to `anewsroom.example`; "Pueblo de Cobre" names `pueblodecobre.example`. "News", "Kestrel Bayou News" for kestrelbay.example and "Lanternese Weekly" for lantern.example still name nothing.
- **An item row drops a proposal another entity of its name stands for.** When an item's entities render the same name, as a proposed product and an accepted client of one name, `ItemRow` leaves out each proposed one while another of that name is not proposed, and keeps only the first of several proposals of one name; two entities that are not proposed keep a chip each. A proposed product the item is about never hides the accepted client it mentions.

### Removed

- **`Proxy_LLM_Client`, the `LLM_Client` interface, the `LLM_Config` and `Vault_Secret` traits, `LLM_Entity_Extractor` and the `Entity_Extractor` interface,** with their seams `Proxy_LLM_Client::$http_post` and `$llm_factory`. Model calls go through `LLM_Call_Node` and the substrate's curl seams.

### Fixed
- **A name led by "the" no longer links ordinary prose.** `Entity_Index::norm()` drops a leading `the`, and names match in any case, so a name such as "The Echo" folded to "echo" and linked by `name`, at 1.0, any item whose prose said "leaves no echo". In an item's title or body, `Entity_Index::names_in()` now finds such a name only where its first word past the article is capitalized, the rest in any case, and a one-word name only right after `the` or `The`, read with markup and entities removed (`Entity_Index::plain()`), so `the <a href="…">Echo</a>` and `the&nbsp;Echo` keep their article: "The Center for Tidewater Integrity", "Grant To The Center For Tidewater Integrity", "THE CENTER FOR TIDEWATER INTEGRITY" and "The Kestrel's Trust", straight or curly apostrophe, link, and so does a name the model proposed in lowercase; "the center for tidewater integrity" does not; "the Echo", "The Echo" and "THE ECHO" link; "leaves no echo", "Leaves No Echo" and "Echo Amounts Found" do not. A claim's subject and object, which the model writes, still match in any case, so "the harbor gazette" resolves to The Harbor Gazette; `names_in()` takes an `$as_written` flag that says which. A name without an article still matches in any case.
- **A configured feed can no longer take a client's item key.** A configured guid that a plain hostname and `:` lead, as `harborgazette.example:cup-73`, keyed `feed:harborgazette.example:cup-73`, the client's own key, so a third party's feed answering first recorded its title and body under it. Such a guid is now keyed `feed::<guid>`.
- **A row's control sits apart from its text and centred on it:** the Entity view's "End client" beside its role and its Related chips, and the Lenses and Collections lists' buttons, each row laid out as `newspack-intelligence__actions`.
- **The Entity view leaves out a role row that held no time.** A closed row whose `from_ts` equals its `to_ts`, as a client import's same-day correction writes, no longer lists as "client 2026-11-01 – 2026-11-01" under "client since 2020-05-06". An open row always lists, including one a seed asserted from 0.
- **The Entity tab reads what the entity's heading shows once its record answers**, its name else its key, "Entity: Acme Quillsheet" in place of "Entity: organization:acme-quillsheet". Before the record answers, or once a later read refuses it, as after a merge, the tab reads its key. The view hands the name it already read to the page, paired with its key, so nothing is read twice and a name answered for one entity never labels the next.
- **Claims read by name and cite their item by title.** Each claim renders its subject as a chip opening the entity, then its predicate, then its object: a chip when `object_name` is set, else the item's spelling as text. A chip whose name is null shows the key. Each claim links its item's `title` to its `url`, so no item key shows.

## [0.33.3] - 2026-11-03

### Changed

- **The floor rises to newspack-nodes 2.145.0.** The debug overlay this plugin inlines keeps its sampler under the page global `__newspackNodesOverviewSamplerTimer`, which that release introduces; a page mixing this bundle with an older substrate's would run two samplers.

## [0.33.2] - 2026-11-03

### Changed

- **Curation and client-import refusals, MCP and verb descriptions, the doctor warning and source log lines say their negative outright**, as in `<key> is not cited by any item outside the restricted tier`. A test or caller matching the old wording must change with it.

## [0.33.1] - 2026-11-03

### Changed
- Builds against newspack-nodes 2.144.1.

## [0.33.0] - 2026-11-03

### Changed

- **The plugin ships `newspack-intelligence-deployment`, which includes the site's `newspack-intelligence-lists`.** The shipped deployment file is `include newspack-intelligence-lists` alone, so a site supplies `<base_dir>/topologies/newspack-intelligence-lists.tsl`, empty when it has no settings of its own, and the plugin ships no file of that name, which would win over the site's. Everything that keyed on the deployment file keys on the lists file: `Intelligence_Health::LISTS` replaces `DEPLOYMENT`, `missing_lists()` and `warn_missing_lists()`, the `wp nodes doctor` hook, replace `missing_deployment()` and `warn_missing_deployment()`, the `collection` slice answers `unconfigured` naming the lists file's path, which the health strip shows as a `no lists file` fault, saying an empty file will do, and uninstall skips a composition that will not read only because no lists file resolves.
- **The plugin ships `newspack-intelligence-workstation` and `newspack-intelligence-context-a8c`.** The workstation composes the core, the public collect, ContextA8C in place of the Vault-credentialed token half, Unthread and the deployment file, then sets the feed's concurrency to 6, because a workstation's Docker resolver fails lookups at sixteen. `newspack-intelligence-context-a8c` reads Slack, P2 and Linear through ContextA8C for a container that mounts the relay's token dir at `/mcp-auth` and reaches the server through a proxy at `host.docker.internal:8080`.
- **The `collection` slice offers every composition that can collect on this host.** While collection is off it answers `{ state: inactive, topologies }`, every catalog topology declaring an `Intel_Event_Writer` that no other such topology includes, sorted (`Worker_Route::candidates()`, which replaces `candidate()`), leaving out one declaring a source whose class's `Source_Node::runs_here()` answers false for its bound `make_node` line: a ContextA8C source answers false unless its `auth_dir` is a dir the worker's user can read and search, so a site without the relay's token dir is not offered `newspack-intelligence-workstation`. A source line that will not bind refuses the slice naming the composition, the node's type and name, and why. The health strip offers one Activate button per name, labelled with the name; with none it names `wp nodes activate`.
- **The seed lives in the site's Intelligence data dir, not a topology dir.** `Ontology_Seed::path()` answers `<dirname(base_dir)>/newspack-intelligence/newspack-intelligence-ontology-seed.json`, which `ontology seed` with no file and the `seed_ontology` verb read; no topology dir is searched. The data dir passes the substrate's `Config::ensure_path()`, as the runtime base does: it is created 0700 when absent, and one that is a symlink, another user's, or writable by group or others is refused, so nothing planted in a shared `/tmp` is read as the seed. With no file there both refuse with `Seed_Refused`, naming the path to a manager and "its Intelligence data dir" to anyone else, beside the example at `examples/newspack-intelligence-ontology-seed.json`. `ontology seed <file>` still reads the file given.
- **Upgrade:** write `newspack-intelligence-lists.tsl` into `<base_dir>/topologies/`, owned by the user the workers run as, holding the lines the site's own deployment file held, or empty; without it no composition loads. A `newspack-intelligence-deployment.tsl` in the user dir is shadowed by the shipped one and can be removed, as can a user-dir `newspack-intelligence-workstation.tsl` or `newspack-intelligence-context-a8c.tsl`. Move the seed from `<base_dir>/topologies/` to `<dirname(base_dir)>/newspack-intelligence/` (`wp eval 'echo \Newspack_Intelligence\Ontology_Seed::path();'`).

## [0.32.0] - 2026-11-03

### Changed

- **A deployment's deployment file and seed live in the user topology dir, `<base_dir>/topologies/`.** The plugin no longer ships `topologies/newspack-intelligence-deployment.tsl` or `topologies/newspack-intelligence-ontology-seed.json`: the substrate resolves every stock dir before the user dir, so a shipped file of either name always won over a deployment's own. The fictional seed moves to `examples/newspack-intelligence-ontology-seed.json`, which the release zip now carries, as the shape to start from. `newspack-intelligence.tsl` still includes `newspack-intelligence-deployment` last, so with none in any topology dir the composition does not read: the worker cannot load, `wp nodes doctor` fails worker liveness naming the include and opens with a warning naming `<base_dir>/topologies/newspack-intelligence-deployment.tsl` and that an empty file will do, and the `health` verb's `collection` slice answers `{ state: unconfigured, file }` while no running composition reads, which the health strip shows as a `no deployment file` fault naming the path; a reader below manage gets no `file`, and the strip tells it an administrator can create the deployment file. A running composition reads its worker's state whatever the topology dirs hold. With no seed in any dir, `ontology seed` and the `seed_ontology` verb refuse with `Seed_Refused` naming where it belongs and the example at `examples/newspack-intelligence-ontology-seed.json`, relative to the plugin, which the strip's "Seed the ontology" fix shows; the user-dir path is named only to a caller holding manage, so WP-CLI names it under `--user=` of a manager, and anyone else reads "the user topology dir". A seed file that does not parse, or holds what the graph has no word for, is refused naming the file by its name alone, not its absolute path. `ontology seed` now registers the user dir before it resolves, so a seed there is found under WP-CLI too. Uninstall skips the composition while it will not read only because no deployment file resolves, because its parts declare its tables, and fails on any other shipped topology that will not read. **Upgrade:** a deployment keeps both files in `<base_dir>/topologies/` (its `wp eval 'echo \Newspack_Nodes\Bootstrap::base_dir();'`), owned by the user the workers run as; an install with no settings of its own writes an empty `newspack-intelligence-deployment.tsl` there before it upgrades, or its worker stops loading. Remove any stock dir holding either file, and any mu-plugin that registered one for them: the substrate searches every stock dir before the user dir, so a stock dir's copy wins over the user dir's.
- **Intelligence's topology is found by what it holds, not by its name.** It is the one active topology declaring an `Intel_Event_Writer` node, which every composition does through `newspack-intelligence-streams`, so a deployment may compose its own topology from the shipped parts under another name and activate it in place of `newspack-intelligence`. `Worker_Route` routes `fetch_now`, `reresolve`, `reextract` and every append to that topology's live workers, `fetch_now` ticks and the `sources` slice list its `Source_Node`s, and the other `health` slices read its nodes; `Worker_Route::TOPOLOGY` and `Intelligence_CI_Node::COLLECT_TOPOLOGY` are gone. Two active topologies declaring the writer are refused naming both. With none, routing refuses (`No active topology declares an Intel_Event_Writer node`), and `health` answers `collection` `inactive`, offering to activate the one catalog topology declaring the writer that no other such topology includes, else naming none, when the strip names `wp nodes activate` in place of its Activate button; with none declaring it while an active `.tsl` will not read, both are refused naming that topology and why, but an active name no `.tsl` resolves, which declares nothing, reads as collection off. `newspack-intelligence` stays the shipped composition, and a deployment activating it changes nothing.
- **`newspack-intelligence-core` holds everything Intelligence runs but its collect:** the streams, the projection, resolution, the reresolver and its Consumer, and the watcher. `newspack-intelligence.tsl` is its frontmatter, `include newspack-intelligence-core`, `include newspack-intelligence-collect` and the deployment include last, so a deployment composing its own topology includes the core and its own collect and copies none of the core's lines. **Upgrade:** a deployment's own composition replaces its copy of the core's lines with `include newspack-intelligence-core`; node names, the offset and dead-letter dirs and the frontmatter stay as they were.
- The hooks now run the shared private-data gate, `scripts/lint-private-data.py`, because `scripts/pre-push.local` sets `PRIVATE_DATA_GATE=1`: `pre-commit` holds the staged diff's added lines and new paths, `commit-msg` the message, and `pre-push` each pushed commit's message, added lines and new paths, each ref's names and each annotated tag's message to the private pattern list.

## [0.31.0] - 2026-11-02

### Added
- **A `publisher` link role.** The resolver links the entity whose `domain` is the item URL's host as `publisher`, never `subject`, so a client's own story is not about the client unless its text names it: an item on a client's site naming the client links twice, `publisher` from its host and `subject` or `mention` from its text. A GitHub org, repo, LinkedIn company, X handle or Linear team the URL's path names stays the subject. A publisher spares the NER step as any link does, and the extractor is sent one link per entity, the one the item is about where the text also names it. `Intel_Event::LINK_ROLES` gains `publisher`, and `Intel_Event::ABOUT_ROLES` (`subject`, `mention`, `topic`) is the one spelling of what an item is about.
- **`entity` answers `published: { count }`,** the readable items its own site published, apart from `counts.items`, and **`timeline --published`** pages them; the Entity view lists the items about the entity first, then a collapsed "Published by <name> (N)" section, read only once opened.
- **Signals from what an entity publishes.** `signals --entity=<key>`, with no lens, answers beside the signals about the entity a `publisher` axis, read from its `publisher` links per week against its own baseline of `SIGNAL_BASELINE` weeks: `burst` for each item kind any entity's own output spikes by the rule the about signals apply, naming the kind; and, for an entity holding an open `client` role, `drop` when the latest complete week holds at most `Graph_Query::SIGNAL_DROP` (0.5) of a baseline mean of at least `SIGNAL_MIN`, or `silent` once, when that week and the one before it are empty and the week before them was not. Neither a drop nor a silence fires in a week when the output of every client fell by the same rule, which is collection failing rather than the client. Every signal answers `axis` (`about` or `publisher`) and `signal` (`spike`, `drop`, `silent` or `burst`) beside its bucket, `by`, `value`, count, baseline and cited items, `by` and `value` null for a drop or a silence. A lens's signals answer no publisher axis. The watcher's digest reads them for an entity watch through `spikes()`, and the Entity view shows the entity's signals, both axes, under its header.
- **A source's pass outlives its worker.** Every source but the ContextA8C ones owns a sqlite Table, `{name}:frontier`, which its schema declares under `owns` and builds where the worker's partition is bound, and keeps each pass's requests there through the substrate's `Frontier` until each is answered: the next worker's first tick sends what the last one left, its request in flight again included. A request carries a note its answer reads back (`queue()`'s fourth argument, `note()`), so Slack's pages carry the workspace and Unthread's messages pages their ticket and context line across a recycle. A send takes four times its room from the Table once half the room is free, so requests waiting on a busy host leave room for the others, and requests leave in about subject order. A clean stop's `shutdown_sweep()` removes the requests answered since the last take, so the next worker sends none of them again. The ContextA8C sources keep theirs in an in-process `Frontier`, since a pass's MCP session does not outlive it. Uninstall deletes every `{name}:frontier` Table's files with the graph's (`delete_graph_tables()` is `delete_tables()`).
- **A client whose `/feed/` does not answer with a feed is found on its home page.** A 4xx, a body that is no RSS or Atom feed, or a redirect off the client's lane whose items none link back sends the feed to `https://<domain>/`, its redirects followed, and the first `<link rel="alternate">` of type `application/rss+xml` or `application/atom+xml`, read with `WP_HTML_Tag_Processor` and resolved with `WP_Http::make_absolute_url()` against where the page landed, is fetched in the same pass and remembered for the domain. A url `Curl_Node::origin_of()` refuses, or on a host `Entity_Index::hostname_fault()` faults, is refused, logged once, and fails discovery. The lane takes the found feed's host, as `Entity_Index::identifier_value()` spells a domain, so `www.` drops, and its subdomains.
- **A client domain that keeps failing sits out whole passes.** A host that does not resolve, a 4xx of its home page, a found feed lost, or no usable feed advertised makes the domain sit out the next 1, 2, 4 … passes, up to `Feed_Source_Node::MAX_SKIP_PASSES` (48), kept as a `retry_at` on the interval's grid; a feed that answers resets it. A timeout, a 5xx and any other curl error back nothing off. Each domain's found feed and backoff live in one `domains` row of the feed's Table, holding the pass's clients alone: read once a pass, and written, only when changed, at the Router's next tick, as the pass ends and on a clean stop, so a recycle keeps it, and again until the Table takes it; a read the Table does not answer backs nothing off and overwrites nothing. A client request's note carries the feed its home page advertised, so a pass another worker resumes keeps its lane.
- **`set_concurrency <count>`** bounds the feeds a `Feed_Source` fetches at once, from 1 to `Curl_Node::MAX_IN_FLIGHT`, the default, refusing any other count as `Curl_Node::concurrency_of()` does.
- **`set_audience <audience>`,** an `Extractor_Node` verb, names who the extractor's system prompt frames its reading for, `You are an analyst reading one item for <audience>.`; with none set the prompt names no one. `dump_config()` replays it.
- **`add_importance_floor <kind> <min>` and `remove_importance_floor <kind>`,** `Extractor_Node` verbs, set and drop a kind's least importance, 0 to 9: after the call, and on the heuristic stand-in alike, the importance written and forwarded is the greater of the score and its kind's floor. A kind outside `Intel_Event::ITEM_KINDS`, a min outside 0 to 9 and removing a kind with no floor are refused; a second floor for a kind replaces the first, and `dump_config()` replays one add line per floor. The shipped topologies set none; a deployment sets its own in `newspack-intelligence-deployment`.
- **`search --sort=importance|time`.** `importance` reads the highest importance first, then the newest, an item no extraction scored last; `time` the newest first. With none named, `search` reads by time when it has words and no lens, else by importance, so a lens and a bare search rank by importance, and every answer says which as `sort`. The MCP door's `intel_search` takes it off the schema. The Search view and a lens's items say whether the page is ranked by importance or newest first and switch to the other, asking again from the first page.
- **`wp newspack-intelligence ontology reextract --since=<Y-m-d> [--until=<Y-m-d>] [--limit=<n>] [--dry-run]`** re-extracts every stored item a range of UTC days dates. Every live worker re-extracts the whole range, so it counts its calls as the items times the live workers and refuses calls above `--limit` (200 when omitted), naming both and that a failed call may retry up to 5 times; a range dating no item sends nothing, and `--dry-run` sends nothing and prints the calls and the command that runs them. It reuses re-resolution: `Reresolver_Node::ask()` sends `REEXTRACT <since> <until>` through `Worker_Route` to each live worker's reresolver, which pages the range as `RERESOLVE` does, each `item_reresolve` record carrying `extract: true`; the resolver forwards such a record whatever the item holds, with `extract` on the resolution, and a failed call keeps the earlier extraction and parks a retry still owed one. It refuses root like the other verbs.
- **`newspack-intelligence-deployment`, an empty topology the composed `newspack-intelligence` includes last,** is where a deployment supplies its lists, relevance profile and audience: it shadows the file from a stock dir it registers after this plugin's, with `cmd <node>:config` lines over the nodes the shipped files declare: `github add_repo`, `feed` and `news add_url`, `linear add_team`, `slack add_channel`, `p2` and `p2-restricted add_site`, and `extractor set_audience` and `add_profile`. Register that stock dir on `plugins_loaded` at a priority above 10, the one this plugin registers its own at, so it is searched first.
- **A tool is proposed as a product.** The NER prompt asks for `products` beside `orgs`, a tool, platform, vendor product or service being a product and never an organization, and the resolver proposes each product it cannot match as a `product` entity; a product an entity already carries links to it as an organization's name does. A name the extractor gives past `Graph_Curation::NAME_BYTES` (256 bytes) is left out, logged, so a proposal fits one line. An organization proposed for a tool before this release is corrected by `create_entity` of the `product:<slug>`, then `reject_entity` of the organization: a rejected name proposes nothing whatever type the model gives it.
- **A seed entity may be declared `"state": "rejected"`.** `Ontology_Seed` writes its creation and names, then an `entity_rejected`, so its names are never proposed, exactly as a name a curator rejected is never proposed again: a deployment's stoplist of its own teams, processes and generic words is the rejected entities its seed declares, and a curator extends it by rejecting a proposal. Any other `state` is refused, naming the field. The shipped example seed rejects one fictional name.
- **Every proposal quotes its evidence.** `link_proposed` and `entity_proposed` carry an optional `evidence`, the first sentence of the item's title or body, markup stripped, that spells the name the link or candidate was found by, cut on whole characters to `Intel_Event::EVIDENCE_BYTES` (512 written bytes) around the name; a link an identifier found quotes nothing, and a `link_asserted` carries none. A present `evidence` that is not a string within the cap is refused; a missing one, as on every proposal written before this release, reads as none. The projection's `links` rows hold it, and `review links` answers it per link and `review entities` per citing item, read only through an item the reader may read. The Review view quotes it under each row. A name the text breaks across a line, a tab or a double space still finds its sentence: the sentence and the name are both read by `Entity_Index::plain()`, and the sentences by `Entity_Index::sentences()`.
- **A client takes its name from its own site.** When a client's feed lands in its lane and every read still shows the client by one of its own domains, as the roster import names it, the feed writes the feed's RSS `<channel><title>` or Atom `<feed><title>`, whitespace collapsed, once, as a `name_added` of kind `canonical` through the path its items take (`Source_Node::write()`, as `source:<name>`). A title that is blank, past 256 bytes or the domain again is not written, nor one that does not name the site as the resolver's domain-stem match reads one (`Resolver::names_site()`): its letters and digits, lowercased, must spell the domain's registrable label, of at least 6 characters, on word boundaries, every word of it capitalized, so "Harbor Gazette" names harborgazette.example, and "News", "Kestrel Bayou News" for kestrelbay.example and "Lanternese Weekly" for lantern.example name nothing. A client already shown by a name gets none. The feed remembers each entity it named for its process, and the next process reads the name off the projection.
- **A client's record answers its status.** `entity` answers `status: { client_since, kinds: { feature_request, complaint }, last_item_ts }` for an entity holding an open `client` role, and `status: null` for any other: when its open client role began, how many readable items about it, over the whole retention, are feature requests and complaints, and when the newest of them is dated, what its own site published counting in none. The Entity view heads a client's page with it as one line, beside the latest signal its own `signals` read lists first.
- **A `briefing` read and a Briefing tab, the page's default.** `briefing <what>`, at `read`, answers one slice of the last seven UTC days a call (`Intelligence_Briefing::SLICES`): `changes`, the `Graph_Query::DEFAULT_LIMIT` (50) most important readable items about any entity, an about-link to it, so an item only its own site published never appears, grouped under each open client it is about, one about two under both and the rest in a group with no client, with `total` counting every such item; and `signals`, every client's signals on both axes and every lens's, as `signals` answers them, whose bucket closed inside those days. The page opens on it unless a link names a watch, each its own card: "Last seven days", the week's items under each client's chip and then under Industry, each row linking to its item and its entities; "Signals", each client's and lens's, the client opening its page and the lens its view; and "Your watches", the `watches` verb read directly, each row the one the Watches view lists, opening the watch. A card that answers nothing says so and offers the one control that acts on it: Search everything, Open lenses, or New watch. Nothing polls. The MCP door offers it as `intel_briefing`, as its rule offers every read but `health`.
- **`Graph_Query::signals_of()` reads any number of entities' signals in the same statements,** about and publisher axes alike: each statement's `live` reads the items linking any of their keys, with one grouped count per axis, one grouped look before the window when a subject asks, one `open_roles()`, and the every-client outage read once and only when a client fell. A lens's spikes run through the same pipeline under one subject, and a window's bucket starts are one recursive statement rather than one per bucket, so a week lens costs two statements. `signals --entity`, the watcher's digest and the briefing all read an entity through it, so the briefing reads twenty clients' signals in the statements two clients' take.
- **Keyboard review.** In either Review queue, with focus in its list and not in a field (the substrate's `isTypingTarget()`), `j` and `k` move between the judgments, `y` confirms or accepts, `n` rejects and `m` opens a merge with its field focused; a judgment moves focus to the row then in its place. Each key sits on its control as `aria-keyshortcuts`, and each list is named for its queue and described to a screen reader by a visually hidden sentence of its keys.

### Changed
- **The substrate floor rises to newspack-nodes 2.144.0,** for `Frontier`, `Table_Client::get()`, `Topology_Registry::resolve_file()`, `Curl_Node::concurrency_of()` and the `public_only` and `connect_timeout` request fields.
- **The Intelligence page opens on the Briefing,** not Search; Search is the second tab.
- **A site naming itself in its own body is not news about it.** The resolver drops a body-only `mention` of the item's own `publisher`, as a feed's "…appeared first on <site>" footer spells one, so once a client carries its site's name its own items do not count as about it again; a `subject` from the title stays, and a mention of anyone else stays. `ontology reresolve` retracts the stored ones.
- **Log lines quote untrusted names inertly.** The reresolver's skipped-items and failed-read lines, the extractor's two per-item lines and a source's refused-event line pass names, keys and error text through `Core::terminal_safe()`.
- **A client the roster named by its domain shows its canonical name.** `Graph_Query::name_of()` is the one place a name is chosen, for `entity`, the entities each listed item names, `related`, `review entities` and a record's topics: an entity created under one of its own domains, as the roster names a client, shows the newest `canonical` name added to it; every other entity keeps the name it was created with.
- **A client is a client from the day its site was `Created`.** The roster import dates a new client's `client` role from the start, UTC, of the earliest `Created` day among its snapshot rows, not the import day, or from the latest end of a `client` or `former_client` row that held time when that is later, so a return and a curator's `end_role` are never reopened. Once every row is reconciled, an open `client` row starting on any other day ends at its own start, holding no time, and the client's role is asserted once from the right day, so a re-import corrects a client an earlier import dated. A `Created` that is not a `Y-m-d` day, optionally followed by a time of day, refuses the whole import naming the row's atomic id. **Upgrade:** import the client CSV once more to correct the start of every client the earlier imports dated by the import day.
- **The heuristic stand-in's summary reads the item as the evidence does,** through `Entity_Index::sentences()`: entities are decoded (`&amp;` reads `&`), and a tag such as `<br>` leaves a space, so the words either side of it no longer run together.
- **A flat history never signals.** A spike's count must also pass its baseline's mean, so a latest bucket equal to a flat baseline, whose deviation is 0, no longer meets the mean-plus-two-deviations threshold by equality; one above it still signals. This holds for the about signals and for a publisher's bursts alike.
- **The digest names each signal's axis and kind:** `Signal (about, spike): kind complaint, 5 from 2026-10-12, against a mean of 1.0`, and `Signal (publisher, drop): 1 from …` for a signal counting every item the entity published.
- **`search` with no words, and every lens read, rank by importance** rather than newest first; a search with words and no lens still reads newest first. Pass `--sort=time` for the old order.
- **`Reresolver_Node::ask()` takes the range request** (`RERESOLVE` or `REEXTRACT`) as its second argument and refuses any other; `ontology reresolve` and the `reresolve` verb pass `RERESOLVE`.
- **The resolution the resolver forwards carries `extract`,** and the extractor drops one without it, as it drops one without `attempt`.
- **Every read meaning "about" leaves `publisher` links out:** `search`'s entity filter, a lens's or a call's role and entity filters, `timeline`, `related`, `trends`, the about signals, `review links` and `review entities`, an entity's `counts`, and the entities each listed item names; an entity's signals read its `publisher` links on a publisher axis of their own. Visibility and a claim's subject still rest on any link. **Watches follow:** an entity watch on a client, or a watch on a lens filtering `role=client`, no longer matches the client's own articles, so its inbox matches, its instant email and its digest's about signals leave them out, while an entity watch's digest carries the client's publisher signals.
- **A curator's judgment governs what an item is about, never who published it.** A `link_confirmed` or `link_rejected` names no role, so it judges an item's `subject`, `mention` or `topic` link to the entity and leaves its `publisher` link at the machine's state: rejecting that a client's story is about the client leaves the story counted as published by it, the entity visible through it and a claim the story makes about it answering. `confirm_link` and `reject_link` refuse an item the entity only published (`no link: <item> <entity>`). A confirmed host link stored as `subject` therefore stays beside the `publisher` link re-resolution asserts, still confirmed and still read as about the entity, until a curator rejects it.
- **A confirmed link the resolver now finds in another about-role is replaced by that role, keeping its confirmation:** a `subject` a curator confirmed whose name the item now carries only in its body is retracted and written as a `mention`, which the confirmation, keyed by item and entity, still judges. **Upgrade:** run `wp newspack-intelligence ontology reresolve --since=<first day kept>` once after the workers restart; each stored host link written as `subject` is retracted in that role and asserted as `publisher`, but one a curator confirmed, which stays beside it until a curator rejects it; no item is sent to extract again.
- **The seed ontology is a file a deployment supplies.** `wp newspack-intelligence ontology seed [<file>]` and the `seed_ontology` verb read `newspack-intelligence-ontology-seed.json`, found through the substrate's `Topology_Registry::resolve_file()`, the stock dir registered last first, so a deployment's dir shadows the fictional example this plugin ships in `topologies/` (three `.example` organizations, four topics and one `overview` lens); the command also takes a path. The file holds `entities`, type => slug => `{ name, names, identifiers, roles }`, and `lenses`, key => `{ name, description, filters }`. `load()` mints every event as it reads, so a file that does not parse, holds a field neither shape takes, names a type, kind or role `Intel_Event` does not hold, carries a blank name or a name or value past 256 bytes, gives a lens a filter `save_lens` would refuse (through `Graph_Curation::lens_filters()`, the half of its checks that reads no projection), spells a slug or lens key otherwise than its key function does, or mints an event past one line is refused with `Seed_Refused` naming the file and the field, before anything is written. What `present()` settles and the idempotence rules are unchanged; `Ontology_Seed::load( ?$path )` builds the seed, and `events( $present )` filters the events it minted. **Upgrade:** a deployment that seeded before ships its seed file in its stock dir first, or `ontology seed` writes the example's keys. The release needs a substrate carrying `resolve_file()`, and raises the floor to it.
- **The prompt verbs are the extractor's alone.** `set_audience`, `add_profile` and `remove_profile` move from `LLM_Config` to `Extractor_Node`'s schema, with the audience and profile state; `LLM_Config` keeps the four client verbs both LLM nodes use, and its `dump_config()` replays whatever setters and lists the class declares, so an extractor's dump still carries them. The resolver, which builds no extraction prompt, refuses all three.
- **The shipped topologies name no lists, profile or audience.** GitHub's repos, the feeds' and searches' urls, Linear's teams, Slack's channels, the P2 sites and the extractor's profile lines are gone from `topologies/`, so GitHub, Linear, Slack, both P2 nodes and `news` fetch nothing and `feed` reads the open clients' feeds alone until a deployment's `newspack-intelligence-deployment` names them. Every source keeps its node, tier, interval, Vault group and settings. A deployment upgrading writes its lists into that file before it restarts the worker.
- **`Prompts::extract()` takes the audience as its fourth argument,** and frames the prompt for no one when it is empty, rather than for a fixed audience.
- **Every source request is public-only,** configured feeds and the API sources included, so Curl refuses a private, loopback, link-local or reserved address and checks every redirect hop. A source answers the flag through `public_only()`; the ContextA8C sources answer false, since Curl refuses the flag beside their proxy.
- **A request Curl refuses as it is handed frees its host at once,** so the next request on that host goes out in the same send rather than waiting on a host nothing is fetching.
- **A replay naming another vault group sends again what was in flight,** which the rebuilt Curl sibling dropped.
- **The test suite reads WordPress's parsers from the install `WP_ABSPATH` names,** and refuses to start without it.
- **Each feed request bounds its connection at 10 seconds** (`Feed_Source_Node::CONNECT_TIMEOUT`, through Curl's `connect_timeout` request field) within Curl's 30 for the whole transfer.
- **A feed body that is no RSS or Atom feed is logged under its own url,** once, with libxml's first error or the root element found. **A client feed redirected off its lane, whose items none link back, counts as failed,** logged once under the feed and naming where it landed.
- **Slack asks for the channels' histories once `auth.test` answers or fails,** in transport too, so a pass opens on `auth.test` alone while no workspace url is held.
- **The health strip's waiting count comes from the later queue.** `health backlog` answers `held` as the `depth` the `intel:later` Delay publishes, every entry it holds, so the count includes the extractor's retries and is known from the queue's first sweep; `dropped` is the days the Delay published for the entries it aged out, still null once the reresolver's last range covers them; `since` is still the resolver's hold start when its reason matches. The Delay is the one the resolver's `make_node` line names as `later`.
- **The resolver publishes only its hold's `reason` and `since`.** Its held set, count gauge, dropped days and `Resolver_Node::QUEUE_MAX_AGE_S` are gone.
- **`health sources` reads a source's tier after its `set_tier` lines,** the last one the topology sends its config, else its `make_node` line's, so a source set restricted by command is left out for a reader without the restricted tier. `Source_Node::graph_facts()` answers it as the `tier` of the source's `Topology_Analyzer` row.
- **The strip shows every time in the browser's own zone:** last answered, failing since, the last extraction and a hold's start. The dropped days stay the UTC days the server names.
- **The strip's line names the sources no pass has reported,** "Collecting from 5 of 6 sources; feed has not reported yet.", counting one source in the singular and joining the names in the page's own language, and stays neutral; any other unknown row, a source in a state the strip does not recognise included, reads "The pipeline’s health is not fully known." With nothing held, the line counts the queue's entries as items waiting to resolve or retry, rather than claiming nothing waits.
- **The fetch fix reads "Fetch all sources now",** because it ticks every source.
- **The dropped row reads "Items parked from <first_day> to <last_day> were dropped before they resolved.",** true both of an entry the later queue aged out and of one it refused while full.
- **The reresolver keeps its `last_range` for a year,** a historical fact that outlives the Delay's dropped days, so a re-resolved range never returns as a Re-resolve offer; the resolver's hold keeps `Resolver_Node::STATUS_TTL_S`, 30 days.
- **`health backlog` derives its reason without loading the entity index,** through `Entity_Index::hold_cause_now()`, one `LIMIT 1` query using the same live-entity rule the index loads by.
- **An entity's state across partitions is the one the highest partition holding one writes,** for both the index's live entities and `hold_cause_now()`; before, the index counted an entity live when any partition's state was. `Intel_Graph_Row_Schema::all()` takes an opt-in that tags each row with its file's `partition_ordinal`, which only the state read uses.
- **`health sources` asks the Vault once per group and url,** however many sources share them.
- **`Reresolver_Node::ask()` validates the range before it sends,** so `ontology reresolve` mounts its graph and validates nothing itself.
- **The graph schema rises to version 3:** `links` gains `evidence`. **Upgrade:** run `wp newspack-intelligence ontology reproject`, which rebuilds the graph from the streams; nothing is dead-lettered, since a proposal written before this release projects with no evidence and its review row shows none.

### Removed
- **`newspack-intelligence-context-a8c`,** the workstation topology that read Slack, P2 and Linear through ContextA8C, leaves the plugin; a workstation ships its own beside its collect shadow. `Context_A8C_Slack`, `Context_A8C_P2` and `Context_A8C_Linear` stay.

## [0.30.0] - 2026-11-01

### Added
- **Each source publishes how its last pass went** to the shared cache, under its name and worker partition, for `Source_Node::read_status()` to read: `ok` with when it last answered and how many of its requests failed, `failing` with the last error, terminal-safe, a scheduled pass's throw included, and cut to 500 bytes, and since when, or, for the ContextA8C sources, `idle` with a reason opening `not signed in to ContextA8C` for a missing token, an expired one holding no refresh token, or a refused refresh. A source with nothing to ask, or whose every request succeeded, reads `ok`; one with no Vault entry still publishes nothing and logs nothing.
- **The extractor publishes how it extracts** to the shared cache, under its name and worker partition, for `Extractor_Node::read_status()` to read: `heuristic` with the Vault id it names when no client can be built, `call` with when a call last answered, and a failing call's error, terminal-safe and cut to 500 bytes, with since when, kept until a call answers or a `heuristic` publish, with no client, clears it. A reply that does not parse fails as `AI proxy answer did not parse as an extraction`.
- **The resolver publishes why it holds items** to the shared cache, under its name and worker partition, for 30 days, for `Resolver_Node::read_status()` to read: the `reason`, `no_live_entity` or `behind_ontology`, '' when nothing holds; `since`, when that reason's hold began, kept by a recycled worker and restarted when the reason changes; and `held`, how many distinct items it holds. The count is unknown for a hold's first two minutes, one circulation of the later queue, and in a recycled worker it is the count read back until that worker has held two minutes itself; after that it is refreshed once a minute, so a large hold costs one cache write a minute rather than one per item. A record that resolves ends the hold, publishing `held` 0. A reason whose hold lasted longer than the one-day later queue (`Resolver_Node::QUEUE_MAX_AGE_S`, 86,400 seconds, the `intel:later` Delay's own limit) adds its first and last UTC day to `dropped`, which widens to cover every such hold, so every day whose records the queue dropped can be re-resolved.
- **The reresolver publishes the last range it was asked to re-resolve,** `last_range { since, until }`, for 30 days, whenever a `RERESOLVE` it can read arrives; a refused one publishes nothing.
- **A `health <what>` read verb answers one slice of pipeline health a call,** for the health strip: `collection`, the worker's state as `wp nodes status` reports it, `inactive` while the topology is off; `sources`, each source the collect topology declares, in its order, but those of a tier the caller cannot read, `idle` with the url and Vault group it needs when its group lends no entry on that url's origin, whatever it last published, else its published pass, else `unknown`; `backlog`, why items wait to resolve, derived in the request as the resolver's hold derives it, so it survives a cache flush, beside the held count and its start, each null unless the resolver's published hold names the same reason, so a new hold after a clear reads its count as unknown rather than 0, and the dropped days the resolver published, null once a re-resolution's range covers them; and `extraction`, the extractor's published mode, else `unknown` beside `last_mode`, `heuristic` or `call`, as the newest extraction's actor names it, which never claims a missing Vault entry, else `unknown` alone. Each worker node's snapshot is read under the name the active `newspack-intelligence` topology gives its `Resolver`, `Extractor` and `Reresolver`, and one it does not declare reads as unknown. A fact no cache holds reads as unknown or null, never as healthy, and nothing throws without a shared cache. The MCP door leaves it out, as it leaves out `fetch_now`.
- **Two write verbs run the health strip's fixes, each sharing its implementation with a WP-CLI verb.** `seed_ontology`, at `tune`, runs `Ontology_Seed::seed()`, as `wp newspack-intelligence ontology seed` does, and answers `{ ok, events, created, present }`. `reresolve <since> <until>`, at `manage`, both days required, runs `Reresolver_Node::ask()`, as `ontology reresolve` does, and answers `{ ok, workers }`; a day that does not parse, a range running backwards, or no live worker is refused before anything is sent. The MCP door offers neither.
- **The Intelligence page learns whether its reader holds manage,** localized as `canManage`, the string `'1'` or `''`, beside `canTune`.
- **A health strip sits above the Intelligence page's tabs on every view.** Collapsed, it reads one line, "Collecting from 8 sources. Nothing waiting.", "Collecting from 8 sources. Catching up on the ontology." while the projector catches up, or "Collection is off." while the topology is inactive, which neither opens nor colours the strip, and leaves every other row listed but neutral. A fault opens it, faults first, its line naming the worst, and colours it as a warning banner: a source failing or idle, collection stale, down or held, items held while the ontology is empty, the projector behind the ontology for more than five minutes since the hold began, held items dropped after a day, and extraction heuristic or failing. An extractor that has not reported since the cache was cleared reads as unknown, naming whether the newest extraction used the heuristic or the AI proxy. Each row's state is a pill, and each fix shows to a reader holding its role: Seed the ontology at `tune`; Re-resolve over the dropped days, Fetch now, Restart for a stale worker and Activate for an inactive one at `manage`; a link to the Nodes station's Vault tab for a missing credential. Any other reader reads the step as text ("An administrator can seed the ontology."). A fix's answer, a refusal included, shows beside its button; Fetch now, which ticks every source, shows once, on the first failing source. A state the strip does not recognise reads as a neutral unknown row naming it, and while any row's health is unknown the line says so rather than claiming health. A reader's open or close holds until the set of faults changes. The strip reads its four `health` slices on load, again once any fix lands, and on Refresh; nothing polls. Times render as UTC day and minute.
- **`Source_Node::fails( $prefix, $detail )`** logs a failure, rate-limited under its prefix, and counts the answer being taken as failed with the same text.

### Changed
- **Answers that fetched nothing count as failed, not answered,** so a pass that fetched nothing no longer reads `ok`: a JSON body carrying an error, a feed body that is no RSS or Atom feed (an HTML page at `/feed/` included), a ContextA8C refresh naming no access token or `initialize` naming no session, a Slack `auth.test` naming no https url, and a redirect not followed. A request that only opens or closes a pass, as a ContextA8C session's or Slack's `auth.test`, counts only when it fails.
- **A seed refuses while the projector has not read the ontology stream to its end,** whoever wrote the changes it has not read: `Seed_Refused`, `Recent ontology changes are still being processed; try again in a minute.`, before it reads the projection or writes anything, so a seed never writes again keys already written; the command shows that refusal, and only it, through `WP_CLI::error()`.
- **`health` hides the operator's fields from a caller below manage:** each source row and the `extraction` row answer without `credential`, `vault_id` and `last_error`, so such a caller reads their state, times, counts and an idle ContextA8C source's `reason`, fixed text naming at most an HTTP status, and a source idle for want of a credential still reads `idle`. A manager reads every field. The verb stays at `read`.
- **`ontology reresolve` validates its range before it mounts the request graph.**
- **An index read behind the ontology stream holds as `behind_ontology`, even while it holds no live entity,** so right after a seed the resolver logs that the projector has not caught up, and the strip offers no second seed, rather than naming an empty ontology; a never-seeded index still holds as `no_live_entity`.
- **A pass that queues nothing publishes a quiet `ok`,** with both counts 0, as a Linear source naming no team does; a source whose Vault group lends it no credential still publishes nothing. A ContextA8C pass waiting on another node's refresh publishes nothing, leaving the last snapshot standing, and one whose token lock will not hold publishes `failing` with `could not lock the ContextA8C token: <path>`, keeping a standing `failing_since`.
- **Two log lines name the url:** a feed body that is no RSS or Atom feed logs `no RSS or Atom feed at <url>`, where one that would not parse logged `Feed parse failed`, and ContextA8C's `initialize` naming no session logs the MCP url rather than its subject.
- **`Source_Node::credential_url()` is static and public,** so a reader asks a source's credential url of its class with no instance.
- **A pass asks `Curl_Node::lends_credential( $group, $url )`, now static,** of the source's own vault group, rather than of its Curl sibling. The substrate floor is 2.143.0, the first release with `Publishes_Status` and the static `lends_credential()`.

## [0.29.1] - 2026-10-31

### Changed
- **Linear, Slack, P2 and Unthread sit idle until their Vault entry exists.** Each declares the url it fetches through `Source_Node::credential_url()`, and every pass first asks its Curl sibling's `lends_credential()` (substrate 2.142.0) whether the source's vault group holds an entry on that url's origin: `https://api.linear.app`, `https://slack.com`, `https://public-api.wordpress.com` and `https://api.unthread.io`. With no group or no such entry, a pass, scheduled or a `TICK`, sends nothing, answers `{ fetching: 0 }` and logs nothing; before, it fetched with no credential and logged each 401. Every pass asks again, so the pass after the entry is added fetches, with no restart. GitHub, whose public API answers without a token, and the ContextA8C nodes, which take their token from the relay's file, fetch as before.
- **The substrate floor is 2.142.0,** the first release with `Curl_Node::lends_credential()`.
- **Unthread is an include of its own, `newspack-intelligence-collect-unthread`, which `-collect-token` includes,** so a workstation reading Slack, P2 and Linear through ContextA8C, which cannot read Unthread, includes it beside them and runs every source the shipped topology runs. The node, its tier, interval, Vault group and target are unchanged.

## [0.29.0] - 2026-10-30

### Added
- **An Unthread source reads the support conversations that moved since the pass before last, every 15 minutes, at the restricted tier** (`make_node Unthread_Source unthread restricted unthread`). It was written against Unthread's published API reference (docs.unthread.io/docs/api-docs/api-reference) and has not yet called the API live. A pass POSTs `https://api.unthread.io/api/conversations/list` with `{ order: [updatedAt], descending: true, limit: 100 }`. The reference documents a `where: [{ field, operator, value }]` clause with `>` among its operators but names no field it accepts, so the source reads its edge client-side until a live call shows whether `updatedAt` filters. Each conversation whose `updatedAt` falls within two intervals (1,800 seconds at the topology's 900), or within `Unthread_Source_Node::WINDOW_SECONDS` (two days) while no interval is set, queues `conversations/<id>/messages/list` with `{ limit: 100 }`, as Slack reads only the threads that moved, so a pass does not spend its 60-request cap re-reading every conversation of the last two days. A page whose `cursors.hasNext` is true queues the next with `cursor: cursors.next` in its body; a conversations page does so only while its last conversation with an `updatedAt` that parses is inside the edge, a conversation whose `updatedAt` does not parse being skipped, and its next page is queued behind the first page of each of its conversations' messages, ahead of any later page of them. A message records as `unthread:<conversation id>:<ts>`, its `&amp;`, `&lt;` and `&gt;` decoded once as Slack's are, titled `#<friendlyId> <its first line cut to 120 characters>`, or `#<friendlyId>` when its text is empty, so the deduper folds neither a conversation's messages nor one macro reply sent in two tickets. Its body opens with the context line `[<status>] <customer>: <subject>` (the customer's name, else `Unthread`; the conversation's title, else its initial message's first line cut to 120 characters, else `#<friendlyId>`), then its poster's `user.name`, else `userId`, and its text. It is dated by its Slack-style `ts`, as Slack's messages are, so a message whose `timestamp` is missing or a number still records, and it carries an empty url, because the reference names no permalink. A message missing its `ts` or `text`, and a conversation whose id is not a uuid, is logged and skipped, and an answer with no `data` list logs Unthread's `message`, rate-limited, and records nothing. Until the `unthread` Vault entry exists (`https://api.unthread.io`, the group `unthread`, `auth_header X-Api-Key`, no scheme) the source sends no credential and logs Unthread's 401.
- **Unthread records one item per message, not one per conversation** as the design first said: the projection dedup records only keys the graph lacks, so a conversation recorded once would never gain its later messages.
- **Unthread reads at the restricted tier, every 900 seconds,** because support threads quote publishers' staff by name. `fetch_now` TICKs it with the other sources, read from the collect topology.
- **A local workstation reads Slack, P2 and Linear through ContextA8C,** Automattic's MCP server, as the person signed in to it on the host, through `newspack-intelligence-context-a8c`, which nothing shipped includes: a workstation shadows `newspack-intelligence-collect` with its own copy, including `-collect-public` and that file, from a stock dir it registers after this plugin's. `Context_A8C_Slack`, `Context_A8C_P2` and `Context_A8C_Linear` take the name, tier, interval and channels, sites or teams of the source each stands in for, and record each item as that source records it, through one mapping on the source where its fields need one (`Slack_Source_Node::fields()`, `P2_Source_Node::post_fields()` and `comment_fields()`; Linear's issue maps field for field), so the graph records the same keys whichever path fed it. Slack reads `messages` over two days, paged by `next_latest`, and the `thread` of each that moved, paged by `next_cursor`; P2 reads `posts-text` with comments over seven days, every page to `max_pages`; Linear reads each team's newest 30 issues from `team-issues`. A ContextA8C comment carries no url, and a Linear description arrives truncated.
- **`Context_A8C_Source_Node` owns the MCP call, and `Context_A8C_Token` the token.** The node's second `make_node` argument names the dir holding the relay's `wordpress-remote-*` token dirs (`/mcp-auth` in the ContextA8C topology), and its optional third the proxy every request goes through (`socks5h://host.docker.internal:8080`, the host's tunnel to the Automattic proxy, without which the server refuses every call). `Context_A8C_Token` holds each dir's token for the process, in no node's state: it reads the file obtained last, and a token within `EXPIRY_MARGIN` (600) seconds of expiring, or one a server answered 401, is refreshed by the one node that claims it, the others waiting for it to land and starting their pass on their next tick. The claim re-reads the file under an exclusive `flock` on a `.lock` file beside it and takes a token the relay refreshed meanwhile; the refresh's form POST of `grant_type`, `refresh_token`, `client_id` and `resource` to `https://public-api.wordpress.com/oauth2-1/token` is built as it goes out, so no queued request holds it; and the answer is written back under the same lock through a rename beside the file. A refresh refused with 400 or 401 logs `ContextA8C refresh refused (HTTP <code>): sign in to ContextA8C again on the host`, and the refused refresh token is never spent again: the next pass reads the file, waiting for the relay to sign in, and only a token obtained after the refused one, by `obtained_at`, clears the refusal and any stale mark the old one left, so a file still holding an older token, after a write-back that did not land, never spends its used refresh token again. A refresh that will never answer, its dispatch thrown on or its request dropped, releases its claim, so the next pass claims again. Every pass opens its own session, `initialize`, reading `Mcp-Session-Id` back through Curl's `response_headers`, then `notifications/initialized`, then the calls, each carrying `Mcp-Session-Id` and `MCP-Protocol-Version`, and closes it with a DELETE once nothing is left to send. A call answered 401 ends the pass and marks the token stale; one answered 400 or 404 ends the pass and drops the session, its remaining calls unsent. A JSON-RPC error, an `isError` result or a text that is not a JSON object is logged under the call, `<name>: ContextA8C answered an error for <provider>/<subtool>?<args>: <why>`. A missing token logs `no readable ContextA8C token: <path>`, and an expired one with no refresh token logs its file; either pass sends nothing.
- **A source may queue several requests to one url, and name what each adds.** `Source_Node::queue( $url, $body, $subject )` keys a request by its subject when one is given, and `request_options( $subject )`, read as the request goes out, adds its `headers`, `proxy`, `response_headers`, a `body` built then or a `method` to the TM_STRUCT Curl runs (substrate 2.141.0); `headers_received( $subject, $headers )` takes the response headers a request named, `failed( $subject, $error )` each TM_ERROR after it is logged, `abandoned( $subject )` each request that will never answer (dropped at the cap, by `end_pass()` or by the next pass, or thrown on as it went out), `pass_ended()` the moment nothing is queued or in flight, and `resumes()` whether a tick starts a pass off the grid, under the grid's own guard. A source whose Curl follows no redirect re-queues a redirected plain GET at its Location as before, but not a request carrying header lines of its own or a method other than GET, which the re-queue would carry past Curl's own redirect guard; it is logged and records nothing. `end_pass()` drops every request a pass has yet to send, and `send_closing()` sends the one a pass closes with at once, outside the request cap, so a capped pass still closes.

### Fixed
- **A P2 comment is titled by its own text, so the deduper no longer folds every comment on a post into one.** A comment's title was `Re: <post title>`, shared by every comment on the post, and the deduper clusters items whose title norms match. A comment is now titled `<author name>: <its first line>`, its text stripped to plain text with entities decoded and its first line cut to 120 characters; its body opens with `Re: <post title>` on a line of its own, then the author's name and the text. Item keys are unchanged, so a comment already recorded keeps its old title.

### Changed
- **The collect topology is two halves,** `newspack-intelligence-collect-public` (GitHub and the feeds) and `newspack-intelligence-collect-token` (Linear, Slack, both P2 nodes and Unthread), which `newspack-intelligence-collect` includes, so a local copy can swap the token half out. The shipped graph holds the same nodes; GitHub, the feed and `news` are declared before Linear, so `fetch_now` TICKs them first.
- **The substrate floor is 2.141.0,** the first release whose `Curl_Node` request sends header lines of its own, goes through a proxy and reads response headers.
- **Slack, P2 and Unthread cut a title by one rule.** `Source_Node::first_line()` cuts a text's first line to `Source_Node::TITLE_CHARS` (120), and `Source_Node::mrkdwn()` decodes `&amp;`, `&lt;` and `&gt;` in one pass; Slack, P2 and Unthread share them, and Slack's own copies are gone. Slack's titles and bodies are unchanged.

## [0.28.0] - 2026-10-30

### Added
- **A Slack source reads four Newspack channels every 15 minutes, at the internal tier** (`make_node Slack_Source slack internal slack`): #newspack-product, #newspack-engineering-public, #newspack-integrations and #newspack-support. A pass asks `auth.test` once a worker for the workspace's url, kept only when it is https and names a host and logged otherwise, then each channel's `conversations.history` over the last two days (`Slack_Source_Node::WINDOW_SECONDS`), 200 a page, following `response_metadata.next_cursor`, and `conversations.replies`, paged alike, for each thread whose `latest_reply` is newer than two intervals, or the two days while no interval is set, so a pass re-reads only the threads that moved since the pass before last. A message records as `slack:<channel>:<ts>`, its text's `&amp;`, `&lt;` and `&gt;` decoded in one pass and its `<@U…>` and `<#C…>` mentions kept as Slack wrote them, titled by its first line cut to 120 characters, its poster's name leading its text, its url the workspace's `archives/<channel>/p<ts>` permalink, empty while `auth.test` has not answered. Channel joins, leaves, topics and purposes record nothing, and a thread's parent records once. An answer Slack refuses with a 200 and `ok` false, `not_in_channel` or `not_authed` among them, logs Slack's error, rate-limited, and records nothing. `add_channel` refuses anything but a channel id (`C` or `G` and eight or more capitals and digits), naming the value. A reply to a thread whose parent is older than two days is not seen.
- **The poster is named from the message itself, with no `users.info` request:** the profile's real name, else the bot's name, else the username, else the user id. History carries `user_profile` for most posters, and a request per poster would hold each message until its name arrived.
- **A P2 source reads seven P2s every 30 minutes through the WordPress.com REST API.** `p2` (`make_node P2_Source p2 internal p2`) reads five sites at the internal tier, and `p2-restricted` (`make_node P2_Source p2-restricted restricted p2`) reads two more, holding material a deployment keeps back, at the restricted tier. A pass asks each site's posts and then its comments since seven days ago (`P2_Source_Node::WINDOW_SECONDS`), 100 a page. A posts page names its next by `meta.next_page`, sent back as `page_handle`; comments carry no handle, so a full page of them asks for the next by `page`. A post records as `p2:<site_ID>:<ID>`, a comment as `p2:<site_ID>:<post ID>:c<ID>`, the site's id taken from the comments answer, titled `Re: <post title>`; titles and content are stripped to text with entities decoded, the author's name leading the body. An answer carrying `error` logs its message, rate-limited, and records nothing, and an answer naming no `site_ID` logs that, rate-limited per site, and records none of what it lacks. `add_site` refuses anything but a `<name>.wordpress.com` host, naming the value.
- **`fetch_now` TICKs every source the collect topology declares,** read from it through `Topology_Analyzer::nodes_of_type()`, so `news`, `slack`, `p2` and `p2-restricted` join `github`, `linear` and `feed`, and a source added to that topology needs no other edit.
- **Both new sources fetch with no credential until their Vault entries exist**, and each refusal is logged: Slack's `not_authed`, and WordPress.com's 403 as a failed fetch. The `slack` entry needs `https://slack.com`, the group `slack`, `auth_header Authorization` and `auth_scheme Bearer`; the `p2` entry, which both P2 nodes share, needs `https://public-api.wordpress.com`, the group `p2` and the same header and scheme.
- **A `news` source reads one news-search feed for each of nine organizations that publish no usable feed.** It is a second `Feed_Source` (`make_node Feed_Source news public`), fetching hourly into `intel:writer`. Each query pairs the organization's quoted name with a journalism term, and ships because at least 7 of its first 10 items concern the organization. An organization whose generic name kept no query on topic is left out.
- **The feed source reads one more organization's feed.**
- **Linear's failures are loud.** An answer carrying `errors`, which Linear sends with a 200, logs its first error message, rate-limited, and records nothing, as one whose `data.issues.nodes` is not a list does.
- **`add_repo` refuses anything but one GitHub `owner/name` pair** (letters, digits, `_`, `.` and `-` on each side), naming the value.

### Changed
- **A source's failures are logged one line per request.** A failed fetch logs `a fetch failed for <url>: …`, an answer that throws `an answer failed for <url>: …`, and an API source's refused answer `<api> answered an error for <url>: …`, so each request that fails shows rather than only the first a minute. A 200 whose body is no JSON object or list is logged by GitHub, Linear, Slack and P2 alike, and GitHub logs a body that is not a list, which it dropped without a line. A Linear answer carrying `errors` beside data records nothing, where it recorded the data.
- **Every add verb refuses a value ending in a newline:** `add_repo`, `add_channel` and `add_site` anchor their patterns with `\z`.
- **No source blocks the worker.** `Source_Node` owns the chain `Feed_Source_Node` used: the `{name}:curl` sibling, the queue, the requests in flight and the answer path. A due pass queues the source's opening requests (`begin()`) and sends each to Curl as a TM_STRUCT request, its subject, the url it was queued under, in KEY and the url of its current hop in VALUE. Each answer goes to the source's `received( $url, $body )` under that subject, which returns the items to record and may queue the next requests. A TM_ERROR is logged, rate-limited, and ends only its own branch, and an answer that throws is logged naming its subject while the drain runs on. A pass ends when nothing is queued or in flight, and a due pass while requests are in flight starts nothing. A host has one request in flight at a time, so the `news` node's nine searches, which share one host, go out one after another. GitHub and Linear send one request at a time and at most `Source_Node::MAX_REQUESTS_PER_PASS` (60) a pass; the feeds keep up to 16 in flight, with no cap on a pass. Every source's `TICK` answers `{ fetching }`, the number of requests the pass opens on, 0 while one is in flight; GitHub's and Linear's answered `{ emitted, refused }`.
- **Credentials travel with the Curl sibling.** GitHub and Linear name their Vault group as a `make_node` argument after the tier (`make_node Linear_Source linear internal linear`), and build their Curl sibling under it, so each request carries the header line its origin's entry names (substrate 2.140.0's `auth_header` and `auth_scheme`). The `github` and `linear` entries need the API origin as their URL, that group, and `auth_header Authorization`, with `auth_scheme Bearer` for GitHub and none for Linear. Without them the sources fetch with no credential, and each refusal is logged: a status of 400 or more as a failed fetch, and an error Linear answers with a 200 as Linear's own.
- **GitHub fetches 30 a page** from each repo's releases, closed pull requests and issues, and watches two repos in place of five, three of them archived and one not on github.com. Item keys and their mapping are unchanged.
- **Linear sends its GraphQL query as a POST of `{ query, variables }`** through Curl; item keys and their mapping are unchanged.
- **A feed's redirect is queued behind the rest of its pass**, no longer at the head of it, under the feed it redirects, so a client's feed keeps its lane wherever it is sent and a configured feed redirected onto a client's `/feed/` keeps its own.
- **The feed source looks no name up before sending.** `Feed_Source_Node` no longer passes a feed URL or a redirect's Location to `wp_http_validate_url()`.
- **`items purge` purges when run, and takes `--dry-run` to count first.** `--yes` is gone, and nothing prompts. `--dry-run` writes nothing and prints the count per key prefix, the total, and the command that purges them, `--reason` carried when given; a purge's success line names the dry run to run once the projectors have read the purges, which answers `Nothing to purge` when none is left.
- **The substrate floor is 2.140.0,** the first release whose `Curl_Node` takes a TM_STRUCT `{ url, method, body }` request and whose Vault entries name their credential's header.

### Removed
- **`set_vault_id` on the GitHub and Linear sources,** with no alias; the Vault group on the `make_node` line replaces it. `Vault_Secret` stays for `LLM_Config`.
- **`Github_Source_Node::fetch()`, `Linear_Source_Node::fetch()`, and their `$http_get` and `$http_post` seams.** Tests stand in for libcurl through the substrate's `Event_Framework::$curl_dispatch` and `Curl_Node::$curl_result`, as the feed's do.

## [0.27.0] - 2026-10-29

### Added
- **Sources fetch on a schedule.** Once `set_interval <seconds>` sets an interval, a source fetches on the wall-clock grid, riding the Router's tick: at each multiple of the interval since the epoch, never at a worker's first tick, so a recycled worker waits for the next boundary. With none set, only `TICK` fetches. The collect topology fetches GitHub and Linear every 900 seconds and the feeds every 1,800. A scheduled fetch that throws is logged naming the source and tried again at the next boundary; it never stops the worker. `fetch_now` still TICKs every source at once.
- **Publisher feeds.** `set_client_feeds true`, which the collect topology turns on, adds `https://<domain>/feed/` for each domain of every entity holding an open `client` role, read from the projection each pass, so a churned client drops out on the next. A client feed records only items dated within 7 days (`Feed_Source_Node::CLIENT_FEED_DAYS`), never an undated one, which bounds the extraction calls publisher items cost. A configured feed records every item, as before.
- **`wp newspack-intelligence ontology seed` writes 21 more organizations,** each with its domains, the names and acronyms it is known by, and its role: seven partners hold `partner`, which a lens reads; industry bodies hold `industry_body`, and so do award bodies, since no role names one; two funders hold `funder`. Run `ontology seed` after deploying; it writes only the keys the projection lacks, and the reresolver then links the items already stored that name them.
- **The feed source reads fourteen more feeds,** from partners, industry bodies, award bodies and two newsletters' archives. Each was fetched and parsed by the feed source before it was added.

### Changed
- **The substrate floor is 2.139.0,** the first release whose `Curl_Node` sends a User-Agent; against an older substrate the feed source fetches without one, and some feeds refuse it.
- **Client feeds are fenced.** `add_identifier` refuses a `domain` that is not a plain hostname (`Entity_Index::hostname_fault()`: lowercase labels, at least two, an alphabetic top-level domain, no IP literal, port, path or userinfo), and a client feed skips a stored one that is not, logged. Every feed URL and every redirect it follows passes `wp_http_validate_url()`, which refuses private, loopback and reserved addresses, before it is sent: the `feed:curl` sibling follows no redirect, and the feed follows at most five itself. A client's items are keyed `feed:<domain>:<guid>`, an item linking off the client's own domain is dropped, and so is one dated more than a day ahead. The address check and the fetch resolve the host separately, so a host that answers the check with a public address and the fetch with a private one still passes, as it does for WordPress's own `wp_safe_remote_get()`.
- **Feeds no longer block the worker.** `Feed_Source_Node` fetches through its own `feed:curl` sibling, the substrate's `Curl_Node`, at most 16 feeds in flight, and records each body's items as it lands; a due fetch while feeds are in flight starts nothing, and a feed whose send throws leaves flight, so the next pass starts. The feed's `TICK` answers `{ fetching }`, the number of feeds the pass fetches, instead of `{ emitted, refused }`. Requests carry the substrate's `User-Agent: newspack-nodes/<version>`, which some feeds require, so the substrate floor rises to 2.139.0, the release carrying it. `Feed_Source_Node::$http_get` is removed.
- **A respawned source records only what the graph lacks.** Before emitting, a fetch reads, in one projection read, which of its unseen item keys the `items` table already holds, and remembers those without recording them. A worker no longer re-records every item its sources still list each time it recycles, about every ten minutes. If the projection will not open, the fetch records everything, logged.
- **One source seam.** The `Source` interface is removed: each source implements `Source_Node::tick()`, which a `TICK` request and the grid both call, and GitHub and Linear keep their public `fetch( $config )`. Sources no longer advertise a `FIRE` registration they never fired.

### Fixed
- **The Search, Lens and Watches forms line their fields up again.** Each field's label and input share a row with its neighbours', and a field's help hangs below its input instead of pushing the input up. The forms' buttons take a row of their own beneath the fields, and Search's "Edit as my own search" sits beside its Search button.

## [0.26.0] - 2026-10-29

### Added
- **`wp newspack-intelligence items purge --source=<name> --keep=<globs> [--reason=<text>] [--yes]` purges recorded items.** It selects every stored item of the source whose `item_key` matches none of the comma-separated keep globs, each matched against the whole key in its own case through `fnmatch()`. `--keep` is required, so no command purges a whole source; WP-CLI keeps only the last of a repeated flag, so a `--keep` given twice is refused, read off `$_SERVER['argv']`. Without `--yes` it writes nothing: it prints the count per key prefix, up to the first `-`, its control characters shown as codepoints, the total, and the command that purges them. With `--yes` it appends one `item_purged` per item, as `machine:purge@<version>`, through `Worker_Route` to every live worker's `intel:writer`, 500 at a time, each batch, the first included, only once every live worker has read its input through (`Worker_Route::read_through()`). Input left unread for 600 seconds stops the command, naming how many it sent; run it again to send the rest, because it selects only the items the projection still holds. Its success line names the dry run to rerun once the projectors have read the purges, to confirm nothing is left. `--reason` defaults to the selection and runs at most 512 bytes as the stream writes it. An empty `--source`, an empty glob or reason, and a root run are refused before anything is read. The Linear items recorded from other teams before 0.22.0 go with `items purge --source=linear --keep='linear:<KEY>-*,…'`, one glob per team the topology names. **Restart the workers after deploying this release and before purging:** a worker on an older release drops every `item_purged` as an unknown kind. **Purge once the resolver has caught up,** when `wp nodes status` lists the reader `newspack-intelligence.intel-items-resolve.p<N>` with nothing behind: an item still in its backlog is resolved after its purge, and the links, extraction and claims that writes outlive it, hidden from every read and out of a rerun's reach.
- **A new items-stream kind, `item_purged` (`item_key`, `reason`), deletes the rows the items stream has written about its item:** its `items` row, which the full-text index follows, and its `extractions`, `extracted_subjects`, `links`, `claims` and `clusters` rows, plus the `canonicals` row of any cluster it heads; no event elects that cluster another canonical. Rows another stream wrote about the item — judgments, collection entries, watch matches and seen marks — stay, and no read answers them, because every read joins the readable items; a collection still lists its saved copy. An entity a machine proposed from the item stays in the entity index and goes on matching other items. A replay applies the record, then the purge, and ends purged. A source recording the same key again records it again; the Linear source no longer fetches other teams, so it will not.

## [0.25.0] - 2026-10-29

### Added
- **A Watches view on the Intelligence page, and signals on the Lenses view.** Watches lists the caller's own watches from `watches`, each with what it follows and its unseen count, or "Inbox off" for a watch without the inbox ticked, whose matches view says the inbox is off in place of its matches; opening one lists its unseen matches from `watch_matches --key=<key>`, a page at a time, each with "Mark seen" (`mark_seen --key=<key> --item=<item>`), plus "Mark these seen", which names every match the page shows (`--item=` once each) and no other, and a marked match leaves the list as it is sent, until a read no longer lists it. A mark that lands returns to the first page, since marked matches leave the unseen list and a later page's offset would skip some. A link naming a watch, `admin.php?page=newspack-intelligence&watch=<key>` as the watcher's email writes it, opens the page on Watches with that watch open, and a key the reader's watches do not hold opens nothing, leaving the list. One form saves a new watch or edits the one opened (`save_watch`, carrying `--key` when it edits): its name, a choice of what it follows and that one target, a lens picked by name, an entity key, or a collection picked by name, so exactly one target is sent; and three checkboxes, "Email me a daily digest", "Email me each new match" and "List new matches on this page", each sent as `true` or `false`. A new watch starts with the last checked and the two emails unchecked. `remove_watch` removes the watch opened. A save or a removal shows from the moment it is sent, until a read lists the watch with the name, target kind and deliveries it sent — an edit by its own key, a new watch by those alone — so an entity target the server stores as its merge survivor still settles; a refusal puts the list back, and every reader sees the tab and its controls, since the verbs are at `read`. The Lenses view shows the opened lens's signals (`signals --lens=<key>`) above its items, each signal's bucket, value and count against its baseline, citing its items, and "Watch this" opens the watch form on that lens, named after it.
- **`signals [--lens=] [--entity=]`, a read, answers the latest complete bucket's spikes:** `{ signals: [ { bucket, by, value, count, baseline, items: [ { item_key, url, title } ] } ], available }`. Per value of the trend axis, a count is a signal when it is at least 3 (`Graph_Query::SIGNAL_MIN`) and at least the mean plus 2 (`SIGNAL_SIGMAS`) population standard deviations of the 8 (`SIGNAL_BASELINE`) buckets before it; `baseline` is that mean. It reads the rows `trends` answers for the same lens and entity, so the lens's filters, axis, bucket and tiers apply alike. It counts only the baseline buckets and the latest, not the whole two years, about seven times faster on a 4,000-item test graph; a history with no counted item before the oldest baseline bucket, within the lens's days, signals nothing.
- **Watches: five verbs at `read`, each answering or changing the caller's own watches alone.** `watches` lists them by name with each one's unseen count, 0 for a watch whose inbox is off; `save_watch <name> [--lens=] [--entity=] [--collection=] [--digest=] [--instant=] [--inbox=] [--key=]` saves one over exactly one target, `digest` and `instant` off and `inbox` on by default, under a minted key or the key of one of the caller's own watches, which it replaces; `remove_watch <key>` removes one; `watch_matches <key> [--limit=] [--offset=]` lists its unseen matches, latest matched first, each as `search` answers it plus `matched_ts`, and none for a watch whose inbox is off; and `mark_seen <key> [--item=…]` marks the named matches seen, or every unseen one when none is named, answering `{ ok, count }`. A reader keeps at most 25 watches (`Graph_Watches::MAX_PER_OWNER`): a new one past them is refused, naming the cap and asking the reader to remove one first, while an edit of one of their own is never refused by it; saves the projector has not recorded yet count from its next flush, so a burst inside one five-second window can pass the cap. A target the caller cannot see is refused (`no entity`, `no lens`, `no collection`), a key that is not one of the caller's own watches is refused as `no watch: <key>` whoever owns it, and a match of a tier the caller does not hold now is neither listed, counted nor marked. The MCP door offers all five, and `intel_signals`, with no door code; a read session gets them.
- **A watcher runs every watch every five minutes, as the watch's owner, and emails what it finds.** `make_node Watcher watcher intel:writer`, in the composed topology, rides the Router's tick. It takes the user signed in before the pass once; for each owner it drops the cached user, signs in inside a `try` and signs back in as that user in its `finally`, so each read answers the tiers the owner holds at that pass and a sign-in listener that throws leaves no one signed in as the owner; that owner is logged and the next runs, as is an owner who is gone or no longer reads. Each owner's pass is one projection read. It matches the items each watch's target gained since a day before the watch's high-water mark, a lens's or an entity's by retrieval and a collection's by saving, at most 200 a pass, the oldest first, so an item whose extraction or link lands up to a day late is still matched, and the watch's own matches keep anything from matching twice. An item that entered the target before the watch was saved is recorded quietly and emails nothing, and a watch's first pass marks it at its saving at least, so a new or re-saved watch floods no inbox and history that joins the target later floods nothing. A watch with instant email ticked is sent each new match; once a day, from 13:00 UTC, each owner with a digest watch gets one email of the matches since their last digest and each lens's or entity's signals since then. Email is plain text to the owner's own address: titles as one line of text, URLs only when http or https, source and day, and a link to the Intelligence page naming the watch; never an item's body. A failed send is logged and retried on the next pass from the recorded matches, never matched again; the high-water mark, the instant mark and each owner's digest are watches-stream events, so a respawned watcher repeats nothing. A pass waits while the projector has not recorded the watches stream's end and asks again on the Router's next tick. Every failing owner and watch is logged, each under its own line.
- **A third stream, `intel:watches`,** holds `watch_matched`, `watch_seen` and `watch_delivered` (modes `scan`, `instant` and `digest`) for two years, behind the restricted read gate like the other intel streams, written by the watcher and request-side appends and read by `intel:watches:consumer` into the projector. `watch_saved` and `watch_removed` ride the ontology stream, which keeps every segment. The projection gains `watches`, `watch_matches`, `watch_seen` and `watch_deliveries`, created when the Row_Table opens its file, so the schema version stays 2 and no reproject is needed; restart the workers after the deploy, and until then a watch read refuses `no such table`.

### Changed
- **`Intel_Event_Writer` takes a third partition, the watches stream's:** `make_node Intel_Event_Writer intel:writer intel:items intel:ontology intel:watches`. `Intel_Event::make()` refuses an event of any stream but the items stream past one 4,096-byte line.
- **Request-side appends go through `Stream_Writer`,** which writes the ontology and watches streams, each through a transient Partition built from that stream's declaration, and refuses an items-stream event before writing anything; `Ontology_Writer` is gone.

## [0.24.0] - 2026-10-29

### Added
- **Lenses: named, curated sets of read filters, stored as ontology events.** `save_lens <key> <name> [--description=] [--entity=] [--days=] [--by=] [--bucket=] [--kind=…] [--topic=…] [--role=…] [--source=…] [--tier=…]`, at `tune`, writes `lens_saved { lens_key, name, description, filters }`, replacing any lens under the key, which `Intel_Event::lens_key()` slugs and the answer carries; `remove_lens <key>` writes `lens_removed`. `filters` is always the whole `Graph_Query::unfiltered()` shape — `kinds`, `topics`, `roles`, `sources`, `entity`, `days`, `tiers`, `by`, `bucket`, a list empty and a scalar null when unset. Each kind, role and tier is checked against `Intel_Event`'s lists, `by` and `bucket` against the trend sets, and `days` runs 1 to 730; an entity or topic must be one the caller sees, stored as its survivor, a topic must be of type `topic` (`<key> is not a topic`), and one no item outside the restricted tier cites is refused, since every reader lists every lens. `remove_lens` and `--lens=` slug their key as `save_lens` does. A lens past one 4,096-byte ontology line is refused before it is written. The projector keeps a `lenses` table, created when the Row_Table opens its file, so the schema version stays 2 and no reproject is needed; a read before the workers restart after the deploy refuses `no such table`.
- **`lenses`, a read, lists every lens by name:** `{ lenses: [ { lens_key, name, description, filters } ], available }`. A lens's entity and topics list only when the caller may see them at the time of the read, so one a judgment has since left to restricted items alone is left out for a non-holder; reading through the lens, such a key stays in the filter and matches nothing that caller may read.
- **`search` and `trends` read through `--lens=<key>`.** Each filter the call names replaces the lens's for that field; `--since` or `--until` replaces the lens's days, the last that many UTC days ending today, whole. The lens's tiers intersect the caller's readable tiers, so a lens narrows what a holder reads and never widens what anyone else reads. A lens no one saved is refused as `no lens: <key>`.
- **The ontology seed writes three lenses** — Leadership, Support and Developers — and leaves alone a lens any `lens_saved` or `lens_removed` has named.
- **The MCP door offers `intel_lenses`, `intel_save_lens`, `intel_remove_lens` and the `lens` argument**, read off the schema; a repeatable argument is a JSON array.
- **The Intelligence page's vocabulary carries `tiers`, `trendAxes`, `buckets` and `lensLists`,** the last one `{ field, option, values }` per list filter, `values` null when free, from the one table `Graph_Query::LENS_LISTS` that the reads, `save_lens` and the verb schema also iterate.
- **A Lenses view on the Intelligence page.** It lists every lens from `lenses`; opening one shows its items a page at a time (`search --lens=<key>`) beside its trend rows (`trends --lens=<key>`), a table of bucket, value and count citing each row's newest items by title, linked when the URL is http or https. A curator saves a new lens or edits the one opened through one form naming every filter: kinds, topics, roles and sources entered comma-separated, tiers checked from the localized `tiers`, and the trend axis and bucket picked from `trendAxes` and `buckets`, each led by "Not set". Every list is sent as one repeated option per value (`--kind=a --kind=b`), and a blank field is left out so the lens does not set it; a save that lands closes the form and lists again. A curator removes the lens opened, which closes it and lists again. A refused save keeps the form and shows the refusal. The page holds the lens opened and its page, so the view returns as it was left.
- **Search opens a lens.** Its Lens select lists every lens. Picking one shows every filter the lens searches with, read-only — its lists, its entity, its rolling days and its tiers, an unset one as "Any" — and sends only `--lens=<key>`, the words and the page, so nothing the view shows can differ from what the server applies. "Edit as my own search" copies the lens's filters into Search's own fields, its days as `since`, the first of the last that many UTC days, and drops the lens; a search takes no tier, so the view says the lens's tiers are dropped. Picking "No lens" returns to Search's own filters as they were. A refused `lenses` read shows as the refusal.
- **A write shows from the moment it is sent, until a read reflects it.** The projector records a write up to 5 seconds after it lands, so the re-read that follows it answered the old rows: an edited lens kept its old filters, a new lens or collection was missing and a removed lens still opened. Lenses shows a saved lens in place of its old one, or after the rest, until a `lenses` read lists one with the same name, description and filters, and takes a removed lens out until a read no longer lists it; Collections shows a created collection, saving and unopened, until a read lists one by its name. A refusal undoes the hold whose send it echoes. Review's judged rows go through the same `useHeld` hook, which replaces its own.

### Changed
- **`search` and `trends` take `kind`, `topic`, `role` and `source` as repeatable arguments, each matching any of its values,** and `search` gains `role`: items linking an entity holding the role at the item's time. `trends` gains `kind`, `topic` and `source`. A single value is now one repeat (`--kind=award`), and the MCP door takes a JSON array. `search`'s declared args read `q`, `entity`, `lens`, `since`, `until`, `limit`, `offset`, then the four lists; `trends`'s `by`, `entity`, `lens`, `since`, `until`, `bucket`, then the lists.
- **`trends` rows and `related` entities cite `items: [ { item_key, url, title } ]` in place of `item_keys`,** so each citation links without a second read.
- **A role filter, and a topic trend, with no entity or topic read only the links of the readable items inside the call's days,** where it read every link: the same answer, about a third of the time for a ten-day window on a 3,000-item test graph.
- **`trends`'s `by` is optional:** with neither the call nor its lens naming one it counts `kind`, and `bucket` falls back the same way to `week`. Neither declares a default any more, since a bound default would mask the lens's.
- **Search's kind, topic and source filters each take several values, and Search gains Roles and Entity.** Each list is a comma-separated field sent as one repeated option per value, as `search` matches any of them, read from the localized `lensLists`; a closed list's field names the values it allows. The Kind select is gone.
- **`useIntelWrite` names its arguments through `commandArgs()`,** which repeats a list once per member and leaves out a blank value or an empty list, so the verb reads its own default for it.

## [0.23.0] - 2026-10-29

### Added
- **A raw read of an intel stream needs the restricted tier's capability.** `intel:items`, `intel:ontology`, `intel:reresolve` and the `intel:later` Delay each declare `set_read_capability newspack_intelligence_restricted` (substrate ADR-39), so each writes a `.read-capability` marker into its dir, every reader copies it into its cursor and dead letters, and the substrate's `raw-logs` verbs, its log catalog and an SSE subscription refuse a marked dir to a caller without the capability; a non-holder can no longer read restricted items whole off the log. A test writes through each stream and its readers and holds every marker to `Intel_Tiers::CAPABILITY`. `Ontology_Writer` binds the ontology stream's `set_read_capability` and `keep_everything` against the Partition's own declaration of each verb, by position or by name, applies both, so a request-side append that opens the directory leaves the marker too, and still refuses any other `intel:ontology:config` line, or a line its declaration refuses, by name.
- **The restricted tier has holders.** A user granted the capability `newspack_intelligence_restricted` (`Intel_Tiers::CAPABILITY`), a WordPress user capability rather than a role, reads restricted items beside public and internal ones: `Intel_Tiers::readable()` asks `current_user_can()` on each call and caches nothing, so every read, every curation verb's item check, `collect` and `collections` answer restricted rows to a holder alone, and a non-holder still gets `no item: <key>` for one. The capability adds a tier and no verb: each verb's own role gate runs first. An MCP session reads the tier while the user who minted it holds the capability, since the door becomes that user for each call, so a revoke takes effect on the session's next call; the session's scope neither widens nor narrows it. A role carrying the capability also reads the tier, and on a multisite a super admin passes every `current_user_can()` check and so reads it on every site, both without a grant.
- **`wp newspack-intelligence restricted grant <login>`, `revoke <login>` and `list`** grant, revoke and list the capability on the current site. A holder is a user's own grant on the site, so `list` names every user `revoke` takes and no role, then says that a role and a super admin read the tier without a grant. A grant or a revoke names the user it changed by the user's own login, looked up as WordPress does; an unknown login, on a multisite a user who is not a member of the current site, a grant to a user already granted it and a revoke from a user holding no grant are each refused by name and change nothing, so a grant never makes anyone a member of a site. Every verb refuses to run as root.
- **A "Restricted access" section on Settings → Newspack Intelligence** lists each user's own grant with a revoke for it, grants by login, and says who reads the tier without a grant. Each is an admin-post, `newspack_intelligence_grant_restricted` or `newspack_intelligence_revoke_restricted`, refused to a caller below MANAGE or whose nonce for its own action fails; a refused change dies naming why.
- **Each grant and revoke writes one stderr line, which reaches the PHP error log**, naming the operator and the user by its own login: `restricted access granted to <login> by <operator>` or `restricted access revoked from <login> by <operator>`. The operator is `user:<login>` for the Settings page and for `wp … --user=<login>`, else `wp-cli uid <uid>`.
- **Uninstall removes every user's own grant of the capability**, on every site of a multisite, whether or not the substrate is active.
- **Curation cannot declassify.** `accept_entity` refuses an entity no item outside the restricted tier cites, by a link or as the item it was proposed from, with `<key> is cited by no item outside the restricted tier; accepting it would show it to every reader`, because an accepted entity shows to every reader. `merge_entities` refuses a merge joining such an entity with one every reader sees, in either direction, with `merging <from> into <into> would show <key> to every reader; no item outside the restricted tier cites it`: merged away, its names and key show on the survivor; surviving, it gains the other's citations. Two such entities still merge. `tag_topic` refuses such a topic on an entity every reader sees, with `tagging <entity> with <topic> would show <topic> to every reader; no item outside the restricted tier cites it`, and an entity's record lists a topic it is tagged with only when the caller may see that topic, so a tag written earlier shows a restricted-only topic to a holder alone. A claim answers its object's key only to a caller who may see that entity, and anyone else the object as the item spelled it, which the extractor now keeps in `object_text` on every claim, keyed or not; a claim written before that, whose object carries a key and no spelling, is left out for a caller who cannot see the object. A claim's subject is linked on the claim's own readable item, so it shows to every caller who sees the claim. All three refuse before writing, and all three decide through `Graph_Query::visible()` over the read's own graph narrowed to the unrestricted tiers, so an accepted or created entity is unaffected. A holder still rejects such an entity, or creates a public one over its key on purpose with `create_entity`.

### Changed
- **The substrate floor is 2.138.0,** the first release carrying `set_read_capability` (ADR-39), which the streams topology declares and `Ontology_Writer` calls; against an older substrate a topology load would refuse the unknown verb.
- **The Settings page's three admin-posts share one gate, one form head and one return.** The client-CSV upload and the two restricted-access handlers open on `admit_settings_post()`, build their forms on `open_settings_form()` and return through `return_to_settings()`, all three in the plugin file beside the Settings page. Each still refuses a caller below MANAGE or whose nonce for its own action fails with `Not allowed.`, and the upload still returns with `clients_imported=1`.

## [0.22.0] - 2026-10-29

### Changed
- **The Linear source fetches only the teams its topology names.** It asked for the workspace's 30 most recently updated issues with no team filter, and the token sees the whole workspace, so it recorded other teams' issues and none of the deployment's. `linear:config` takes `add_team <KEY>` and `remove_team <KEY>`, declared as `github:config` declares `add_repo` and `remove_repo`, which round-trip through `dump_config()`; an identical repeat is a no-op, and `remove_team` refuses a key never added. The request filters `issues` by `team.key` through a `$teams` GraphQL variable, keeping the page of 30, and with no team registered the source makes no call. The collect topology names nine teams.
- **An item recorded again is re-resolved, not resolved afresh.** A fresh worker forgets which items its sources recorded and records their recent items again; datapoke's `intel:items` holds 435 `item_recorded` events for 217 items. Each repeat ran the NER step, an LLM call, then the extractor, a second call that replaced the stored extraction, and the resolution it sent ignored the curators' verdicts, so a rejected link reached the extractor and a confirmed one was not sent asserted. When the projection already holds the item, the resolver now runs what an `item_reresolve` naming it runs: the verdicts in force, and the item forwarded for extraction only when no call extraction is projected or it asserts a link the last extraction was not given. It skips the NER step only for an item resolved before, one the projection holds an extraction of from any actor or a non-topic link of, because the projector writes the `items` row within 5 seconds whether or not anything resolved it. So a first recording the projector flushed before the resolver reached it, and an item never linked because its NER step named nobody or failed, still run NER and are forwarded with `extracted: false`. A first recording the projection does not hold yet takes the same path from the event's item, NER included, with the verdicts the projection already holds of it, since a replay can project the ontology stream ahead of the items stream; an item read while the projection will not open takes it with no verdicts and retracts nothing, logged. A crash in the 30 seconds between the projector flushing an item's extraction and `intel:items:resolve` committing its cursor replays the item as a settled repeat, so the deduper does not place it in a cluster; that is the accepted cost.

## [0.21.0] - 2026-10-29

### Added
- **`intel:later` parks records to reprocess later.** The streams topology declares a substrate Delay, `intel:later`, which keeps each record a day at most, sweeps every minute, and sends each due record to `intel:reresolve` as an `item_reresolve`, so the resolver reads it one a poll like any other re-resolution. `make_node Resolver` and `make_node Extractor` each take it as a second argument, declared as a destination beside the writer.
- **A failed extraction call is retried with backoff.** When the extractor's call throws a `RuntimeException` or answers a reply that does not parse, it still writes the heuristic stand-in, or keeps the earlier extraction, and also parks the item on `intel:later` for a re-resolution one attempt on, due 300 seconds doubled per attempt and capped at an hour, for at most 5 attempts. The re-resolution forwards the item for extraction only while it is still owed one. A re-resolution whose NER step did not run and answer now sends the extractor the item's stored NER links as they were stored in any partition, unless a curator rejected one, so a retry reaches an item only NER linked, and costs no NER call; the link events it writes are still only those it found. With no client configured nothing is retried.

### Changed
- **The resolver holds items while its entity index is empty.** An index holding no accepted or proposed entity resolved every item against nothing: after the 0.20.x upgrade discarded the projection, a respawn re-recorded recent items, and 43 good extractions were replaced by ones given the wrong subjects. The resolver now resolves nothing then: a recorded item parks on `intel:later` as a re-resolution owed its NER step, and a re-resolution parks again unchanged, each a minute on, under a line naming `wp newspack-intelligence ontology seed`, or `ontology reproject` after an upgrade. It also holds while the projector's reader of the ontology stream had not committed the stream's end just before the entity index was read: each load of the index takes that reading first, so a flush landing between the two reads cannot pass a stale index, and a curation the projector has not flushed yet holds an item a minute at most. The resolver's own `entity_proposed` events leave that cursor short until the projector's next checkpoint, up to 30 seconds, so items following a proposal may park a minute; nothing is lost and no LLM call is spent. A record parked again keeps its first `parked_at`, so a day bounds the wait, and every record parks keyed by its item, so the Delay's age-out line names it; `wp newspack-intelligence ontology reresolve --since=<day>` then re-resolves it, without its NER step, after `ontology seed` on a fresh install, as the hold line says. A replay owed NER runs it, and parks again while the projection does not hold the item yet.
- **A resolution retracts the links it no longer finds.** A new items-stream kind, `link_retracted` (`item_key`, `entity_key`, `role`, `retrieved_ts`), deletes the one `links` row it keys. The resolver writes one for each stored non-topic link of the item, by raw entity key and role, that a resolution does not write again, so a link whose role changed goes from its old role. It reads only this worker's own partition's file, the one its projector deletes in. It keeps a link whose entity a curator confirmed, and a NER link unless the NER step ran and answered: `Resolver::resolve()` now reports whether it did, and the NER extractor answers none, logged, for a failed call or a reply whose `orgs` is not a list, so a failure, or a NER step skipped because an earlier step found a link, is told from an answer naming nobody.
- **The substrate floor is 2.137.0,** the first release carrying the Delay node `intel:later` is built from.

### Fixed
- **A source refuses an item whose key runs past 512 bytes** as the stream writes it, JSON-escaped (`Graph_Curation::ITEM_KEY_BYTES`), with a rate-limited line naming the source, the key's length and the key cut to 64 bytes, terminal-safe, so no parked or re-resolution record naming it outgrows its 4,096-byte line. A process refuses such a key once.

## [0.20.1] - 2026-10-29

### Fixed
- **The view tabs follow the WAI-ARIA tabs pattern.** The page renders the substrate's shared `TabBar`, so each tab names the panel it controls, the panel is labelled by the selected tab, and the arrow keys, Home and End move and select. A release builds against substrate 2.135.0 or later, the first to carry `TabBar`.
- **Next leaves a page that shows no rows.** The pager offered Next when a page answered no rows while more matched, and Next asked for the same page again.

## [0.20.0] - 2026-10-28

### Added
- **`wp newspack-intelligence ontology reproject` rebuilds the graph from the event streams.** It holds the fleet with `wp nodes stop`, deletes the `intel-graph` Row_Table's files and the cursor of every Consumer the projector checkpoints with, read from each active topology's TSL, then releases the hold with `wp nodes start`, which runs even when a delete fails. A fleet that does not stop has nothing deleted under it, and its hold is released. The projector then replays both streams from their oldest segment. The streams and every other reader's cursor stay, so a rebuild signals nothing and re-resolves nothing. **Run it after upgrading to this release:** the graph schema's version rises to 2, so the substrate discards the old projection when it opens it, and the graph stays empty until it runs. Run it right after deploying. The reresolver's new reader (below) replays the ontology once on its first boot, and its sweeps may re-resolve items while the graph rebuilds; that spends no call beyond the one-time re-extraction below, because each new extraction lands after the item's old one in the stream and settles it. Then run `wp newspack-intelligence ontology reresolve --since=<day>` to reach the items no signal names.

### Changed
- **The reresolver reads the ontology through its own Consumer.** `intel:ontology:reresolve` feeds it and carries its checkpoint, and `intel:ontology:consumer` feeds the projector alone, so `intel:ontology:tee` is gone. Resetting the projector's cursors therefore leaves the reresolver where it was. On its first boot the new reader has no checkpoint, so it replays the ontology stream into the reresolver once.
- **A link is settled when the item's last extraction was given it.** A call's extraction now also writes `subjects_extracted`, naming the entities it was given as asserted, which the projector keeps per item in `extracted_subjects`, replacing the earlier set. A re-resolution forwards an item a call has extracted when, with the curators' verdicts in force, it asserts a link whose entity, compared by survivor, that extraction was not given; a link a curator rejected is not sent, so it never counts. So an item whose links were asserted while an older release ran, which never extracted it again, now is; a ✓ the projection had not yet recorded when the item was extracted is still owed its claims; and a merge settles through the survivor, costing no call. A curator's ✓ needs no flag of its own any more, so `item_reresolve` records no longer carry `judged`, and the reresolver queues a confirmed link's item like any other. Older releases recorded no such set, so after the reproject every item a call extracted reads as given nothing, and the next signal that reaches it, or a `reresolve`, extracts it once more: one LLM call per item, once.

### Fixed
- **One click on Review's ✓ or ✕ takes.** A judgment is appended to the ontology log at once, but the projector writes it into the queue the page re-reads only on its next five-second flush, so the re-read after a click still listed the proposal and its ✓ looked dead; each further click recorded the same judgment again. A judgment now takes its link or entity out of the queue the moment it is sent, and the row stays out until a read no longer lists it. A refused judgment puts its row back beside the refusal, matched by the arguments the refusal echoes, and a refusal naming no send, such as a Router's NOT_AVAILABLE, puts back every judged row of that queue. A queue no longer locks its other rows while one judgment waits, so the next proposal can be judged at once.
- **A failed re-extraction is tried again.** An extractor whose call fails keeps the item's earlier extraction, which was not given the link that sent it, so the item's next re-resolution forwards it again; before, the link already counted as settled and the item was never retried.
- **A heuristic stand-in never replaces a call's extraction.** The extractor records the stand-in when no call answers and the projection shows no call's extraction yet, which a lagging projection could show wrongly; the stand-in then deleted the call's claims and topics. The projector now writes a stand-in only into an item no extraction holds, with an `ignore`, so the stream's order decides and a call's extraction, claims and topics stand.

## [0.19.0] - 2026-10-27

### Changed
- **The Review queues are one compact control per proposal.** A proposed link was a row per item and entity, the item's title repeated for each entity, a `mention · name · 1` line beneath it and a Confirm slab beside a red Reject slab; a proposed entity carried an Accept slab, a red Reject slab and an open Merge field. A link row is now one item, its title once, holding a pill per entity proposed for it: the entity's name, which opens it, joined to a ✓ that confirms and a ✕ that rejects. The pill's tooltip says in words what the entity is to the item, how it was found and how sure the finding was ("Mentioned, found by a similar name (61%)"). The words for each role and method come from the page's localized vocabulary, keyed by `Intel_Event::LINK_ROLES` and `Intel_Event::METHODS`. A proposed entity is the same pill, accepting or rejecting it, beside its type and key and a Merge… button that opens the merge field. Each glyph is named for screen readers ("Confirm Acme Gazette", "Reject Valley Times"), and every pill takes the accent of the shared secondary button rather than the danger red.

- **An item is extracted again once an entity it names is accepted.** Extraction asserts a claim only about a subject the item links by an asserted link, so an item extracted while its entity was still a proposal kept no claim about it, and a re-resolution after the entity was accepted or seeded wrote asserted links but never extracted the item again. A re-resolution now forwards an item already extracted when it asserts a link to an entity whose link to the item is not settled — neither asserted already, compared by the entity's survivor so a merge costs nothing, nor rejected by a curator — so the extractor runs once more and writes that subject's claims. An item whose settled links are unchanged is still never extracted twice, so the LLM cost of a signal stays bounded by the items whose asserted entities grew. A curator's ✓ on a proposed link does the same: the confirmation sends its item back through the resolver, which extracts it again with the confirmed link asserted, so the extractor keeps that subject's claims, and a link a curator rejected is left out of what the extractor is sent. Each `item_reresolve` record now names whether a confirmation sent it (`judged`), and the resolver drops one that does not. An extraction now replaces the item's claims and topic links outright, through newspack-nodes 2.134.0's `delete` row op, so a re-extraction yielding fewer leaves none of the earlier behind; the substrate floor rises to 2.134.0 for it. A re-extraction whose call fails keeps the earlier extraction rather than replacing it with the heuristic stand-in, and a link a curator has judged counts as settled, so accepting its entity afterwards spends no second call.

### Fixed
- **The curation controls show for a curator.** `wp_localize_script()` sends a top-level `true` as the string `'1'`, and the page tested for `true`, so no one ever saw Review's judgments, Collections' create form, the Entity editors or Save to collection. The page now sends the flag as `'1'` or `''` and reads `'1'`, and the PHP suite's `wp_localize_script()` double casts as WordPress does, so the test holds the value the browser receives. newspack-nodes now refuses a boolean extra outright, so a substrate carrying that refusal needs this release beside it.

## [0.18.0] - 2026-10-25

### Added
- **`Intel_Event`, the entity graph's event vocabulary.** One table names every event kind, its stream (`items` or `ontology`), its fields and the closed sets they are drawn from; `make()` fills the envelope and refuses an ontology event that does not pack into one 4,096-byte partition line, `validate()` refuses an unknown kind, a missing field, a value outside its closed set, an actor that is not `user:`, `agent:` or `machine:`, or a person or agent writing the items stream, and `from_message()` reads the event a TM_STRUCT message carries.
- **Two event streams and the writer that fills them.** `intel:items` keeps two years of items (16 MiB segments, both retention rules at 63,072,000 seconds, a hard cap of 8,192 segments) and takes item bodies past PIPE_BUF from its one writer, the worker; `intel:ontology` keeps every segment. `Intel_Event_Writer` takes the two partitions as `make_node` arguments and addresses each event to the one its kind's stream names, dropping a message that names no kind with a rate-limited line.
- **Sources record `item_recorded` events.** Each source records at the tier its `make_node` line names (`make_node Feed_Source feed public`; github `public`, linear `internal`), which a load without one refuses and the `set_tier` verb replaces, and stamps the plugin version as each event's `config_version`; an item's body is cut at 65,536 bytes on a character boundary. An item `make()` refuses is logged, skipped and retried on the next TICK, and the TICK's reply counts it as `refused` beside `emitted`. The sources live in `newspack-intelligence-collect.tsl` and write to `intel:writer`. The feed source adds five organizations' feeds.
- **The `intel-graph` projection.** `Projector`, fed by `intel:items:consumer` and `intel:ontology:consumer` in `newspack-intelligence-project.tsl`, replays both streams into the `intel-graph` Row_Table (`Intel_Graph_Row_Schema`, version 1). Items, extractions, links, claims, clusters and canonicals keep 730 days by their day column; entities, their states and merges, names, identifiers, roles, entity topics, judgments, collections, collected items and the ontology `events` keep every row; `events` is indexed by subject and kind, and `judgments` by entity. Every write is an `ignore` or a whole-row `set`, so a replayed event changes nothing and the two streams project the same rows in either order; an item is written once, and a machine's proposal never overwrites a person's entity. Both Consumers drain in batches. Rows leave in one batch every 5 seconds, at 2,000 buffered ops or 1 MiB of string values, at each checkpoint and on a clean stop; a failed batch stops the worker and keeps the buffer, so the cursor replays it, and an event that will not read dead-letters while the cursor moves on. `extract` joins the link methods, for the topic links an extraction makes.
- **Resolution links each recorded item to the entities it is about.** `Resolver` carries the retired Gate's `Publisher_Matcher` over to every entity type and identifier kind, in cost order: identifiers in the item's URL (its host by suffix walk, a GitHub org or repo, a LinkedIn company, an X handle, a Linear issue's team) at 1.0; names and aliases as whole words in the title and body at 1.0, an acronym only in its own case at 0.9; a domain's stem in the title at 0.9, when it runs at least six characters, aligns to word boundaries, capitalizes every word and is not led by an article, and never in a body; and, only when those find nothing, the organizations the NER extractor names, fuzzy-matched against every name — asserted at 0.85, proposed at 0.60, and below that a candidate entity, at most three an item, never a name a rejected entity carries. Name and domain-stem hits whose spans overlap in one text settle together: the longest span wins, so "The Times" inside "The Bayport Times" links only the latter and a name inside a stem's span drops, while equal spans naming several entities assert the one whose locality the text also names as a capitalized word, at 0.9, and otherwise propose each at 0.5. A locality is evidence only and never drops a name. An entity found in the URL or the title is the item's subject, one found only in the body a mention, and two names in one item are two links rather than a hold. A link to an entity that is only proposed is itself proposed, at the confidence its method earned. `Entity_Index` reads the accepted and proposed entities from the projection, a merged entity's names and identifiers answering its survivor; an entity whose merge chain cycles or runs past ten hops is left out, logged once naming each chain, and every other entity still loads; `Intel_Event::entity_key()` keys a candidate by its type and slugged name. `Resolver` (`newspack-intelligence-resolve.tsl`), fed by its own `intel:items:resolve` Consumer, writes a `link_asserted` or `link_proposed` per link and an `entity_proposed` per candidate to `intel:writer` as `machine:resolve@<version>`, and forwards each resolution to `extractor`. It hands the resolver one item a poll (`set_line_mode true`), so a collect burst never runs every item's NER and extraction calls inside one `fill()`. It resolves against no entities, logged, while no `intel-graph` table exists; an `item_recorded` it cannot read dead-letters. The NER client is built from the node's `LLM_Config` state for each recorded item, so a runtime `set_vault_id` or `set_api_url` reaches the next item, and `Resolver::resolve( $item, $ner )` takes it per call. The NER step skips the fuzzy scan for a name whose norm is indexed exactly, and otherwise scores only the names whose length can reach the proposing band.
- **Extraction records what each linked item says.** `Extractor` (`newspack-intelligence-resolve.tsl`), fed each resolution in process, skips an item with no link and no candidate; for any other it makes one LLM call through `Prompts::extract()`, which carries the item as JSON inside an `<item>` fence the system message names as untrusted, and writes an `extraction_recorded` — a summary of at most 280 characters, a kind, topic keys, relevance and importance from 0 to 10, sentiment from -1 to 1 — plus a `claim_asserted` for each of at most five claims whose subject the item links by an asserted link, as `machine:extract@<version>`; a subject linked only by a proposed link asserts no claim. A claim's subject and object resolve through every entity whose name spells the whole of it: the subject is the one the item asserts, the object the only one or the only one the item links, else it stays text, so two entities sharing a name never resolve by load order. The claim takes its subject link's confidence. `Prompts::parse_extract()` reads the reply leniently, reading an unknown kind as `other` and dropping topics outside those offered and predicates outside the closed set. With no client, a thrown call or a reply that does not parse, the item records a heuristic extraction — the first sentence of its title and of its body, kind `other`, no topics, scores 0 — as `machine:extract-heuristic@<version>`, so it is marked extracted rather than retried. Its relevance profile names who Newspack serves and what counts.
- **Dedup clusters repeated items and records each cluster's canonical by event.** The Extractor forwards each extracted item, heuristic ones included at their 0 scores, to `deduper` (`newspack-intelligence-resolve.tsl`), which finds an earlier copy with the same canonical URL — scheme, `www.`, `utm_*`, `fbclid`, `gclid`, `mc_cid`, `mc_eid`, the fragment and a trailing slash dropped, query keys sorted — or else the same title norm of at least 24 characters, within 14 days of the earlier copy, timing an undated item by its retrieval. Meeting a singleton founds a cluster keyed by the earlier item: a `cluster_joined` for both, then a `cluster_superseded` naming the higher-ranked (relevance plus importance, a tie keeping the earlier) as canonical; a later copy joins, and supersedes the canonical only when it outranks it, and joins alone while the cluster's canonical is not yet projected. An item already placed is never placed again: its own lookup entry, else its own `clusters` row, is found before any copy, and re-delivered it only supersedes its own cluster's canonical once it outranks it. A canonical re-delivered at a higher rank supersedes itself to record it; at a lower one the cluster passes to its best-ranked member, a tie keeping the earlier item. Every decision is a new event as `machine:dedup@<version>`. Lookup reads a 5,000-entry LRU first and the `intel-graph` projection on a miss, URL before title, so two copies arriving in one tick still meet; a cluster the LRU no longer holds is read again from the projection. `intel:items:resolve` checkpoints the LRU with its cursor — positional entries under a 16-hex hash of each spelling, no cluster key where the item is its own cluster, about 425 KB when full — so a respawned deduper resumes what it had decided. With no `intel-graph` table a miss finds nothing, logged.
- **`wp newspack-intelligence ontology seed` writes the starting ontology the projection lacks**: thirty topics with their display names and aliases, six partners, funders and industry bodies with their domains and roles, and the deployment's own products with their GitHub repositories and Linear team. Each entity is created once, and only for keys whose projected state is absent or a machine's pending proposal, so a seeded entity a curator has since accepted, rejected, merged or renamed is never reset while a machine's proposal of a seed key is replaced and a second run writes nothing; the command prints how many keys were new and how many present, and fails when the projection will not open rather than writing everything. Run as root, it refuses before it writes, as `wp newspack-intelligence ontology reresolve` does, because a root-owned segment or IPC file is one the workers cannot append to.
- **Stored items are re-resolved when the ontology grows.** `Reresolver` (`make_node Reresolver reresolver intel:reresolve`) takes the ontology stream through a new `intel:ontology:tee` in front of the projector, and checkpoints its queue with `intel:ontology:consumer`'s cursor, logging any entry the checkpoint cannot read. An `entity_created` or `name_added` queues the name, an `identifier_added` the URLs it spells (a domain, a GitHub org or repo, a LinkedIn company, an X handle on x.com or twitter.com, a Linear team's issues), an `entity_accepted` every name and identifier the projection holds for the entity, and a new locality the entity's own names; a topic queues nothing. Each Router tick appends `item_reresolve` records, each naming a stored item, to `intel:reresolve`, a Partition keeping a day of them, whose own Consumer `intel:reresolve:resolve` feeds the resolver one record a poll, so the tick never runs resolution or extraction itself and an item whose resolution throws dead-letters alone. A signal at least 30 seconds old goes first, appending its newest 500 hits at once, newest first, and counting the rest only when it reaches that cap, to log how many it skipped; only with none due does a requested range append its next 20. A name is one quoted FTS5 phrase over title and body; an identifier is a LIKE over the URL anchored to a whole host (ending at `/`, a `:` port, `?`, `#` or the end) or path segment, its wildcards escaped, so `ap.example` matches `ap.example:8443` but never `snap.example`, and the handle `abc` never matches `abcxyz`. A unit whose projection read fails for any reason but an absent table leaves the queue with one line naming it and the cause, so a respawn cannot crash-loop on it; a checkpoint holding no queue is dropped and logged. A queued unit checkpoints by its key, `<kind>|<value>`, with when it was queued and a range's cursor. A signal's record carries `fresh_after`, the tick its signal settled (its time plus 30 seconds), and a range's 0. The resolver reads each named item from the projection, drops one it no longer holds with a line, and runs the identifier, name and stem steps alone, because NER ran on first resolution; it writes the item's links, and forwards it to the Extractor only when the projection holds no extraction a call made, so an item is extracted by a call once, one that linked nothing before is extracted now, and one a proxy outage left with only a heuristic extraction is extracted again. An acronym — a `name_added` of kind `acronym`, or an accepted entity's acronym — is searched in its own case too, through a case-sensitive `GLOB` on the title or body beside the case-folding FTS phrase, so `OWL` never sweeps every "owl".
- **`wp newspack-intelligence ontology reresolve --since=<Y-m-d> [--until=<Y-m-d>]` re-resolves every item dated in a range of UTC days**, an undated item by its retrieval, through to today when `--until` is omitted. It refuses a date that does not parse or a range that runs backwards before sending anything, sends `RERESOLVE <since> <until>` to each live worker's `reresolver` and reports how many it reached. The reresolver answers the range it queued and appends its items 20 a tick, in the order the projection recorded them; a request it cannot read is answered with the refusal and logged.
- **`Ontology_Writer::append()` writes ontology events from outside the worker** through a transient Partition built from the streams topology's own `intel:ontology` declaration. Its `keep_everything` line is honoured through the Partition's setter rather than replayed as a command, so a caller below MANAGE can append and the writer still never prunes the stream; any other config line on `intel:ontology` is refused by name. It flushes and releases its name whatever happens, and a failure reaches the caller.
- **The `intelligence` service CI answers the graph's read verbs.** Mounted on every request graph, it declares eight verbs, each gated at `read`, its args bound by the schema: `search` (items matching `q` over title, body and summary, an `entity`, a `topic`, a `kind`, a `source` and UTC days, newest first, with each item's summary, kind, scores and linked entities, and the total), `entity` (one entity as its merge survivor, with the keys merged into it, its names, identifiers, roles, topics, and counts of its items and proposed links), `timeline`, `related` (entities sharing the most items, each citing the five newest), `claims`, `trends` (items counted by kind or topic per day, week or month, filterable by an entity or a role held at the item's time, each row citing its five newest items), `review` (proposed links, or proposed entities with the items citing them) and `collections`. `Graph_Query` holds every read. A merged key reads as its survivor everywhere, links to it counting for the survivor, and a key whose merge chain cycles or runs past 10 hops is refused; `Entity_Index::survivors()` is the one survivor map the index load and every read share. `Intel_Graph_Row_Schema::range()` and `day()` parse UTC days for the reads, the `reresolve` command, the Reresolver and the client roster. A link counts by its effective state — a person's judgment over an agent's, else the machine's — and a rejected link counts nowhere. A read resolves the readable tiers once (`Intel_Tiers::readable()`: `public` and `internal`) into `ri`, the readable items every statement reads items through, so no item, link, claim, count or citation rests on a restricted item, and an entity short of `accepted` — proposed, or rejected — answers only while a readable item cites it (`Graph_Query::visible()`); `entity` and `timeline` refuse any other key as `no entity`; an entity's names, identifiers and roles carry no tier and are not filtered. A blank optional arg is a value read as given, so it matches nothing. `q` reaches FTS5 as one quoted phrase per word; no answer carries an item body; `limit` is capped at 200. A projection that will not open answers `available: false` beside empty rows.
- **The `intelligence` CI curates the graph, each judgment one ontology event.** Thirteen verbs, each gated at `tune` and answering `{ ok, event_id }`: `confirm_link` and `reject_link` (carrying the item's URL), `accept_entity`, `reject_entity`, `merge_entities`, `add_name`, `add_identifier`, `assert_role`, `end_role`, `tag_topic`, `create_entity` (keyed by type and slugged name, answering the key), `create_collection` (keyed by its slugged name, answering the key) and `collect`. A call through a labelled session, such as an MCP connection, records `agent:<login>:<label>`, the label as the session stored it; any other call records `user:<login>` (`Intel_Event::caller()`). Each verb reads the projection once and acts on the survivor each entity key answers as, so accepting, merging or tagging a key merged away acts on its survivor. Each refusal throws naming its cause and writes nothing: an entity key a read caller could not see answers `no entity`, exactly as a missing one, and an item outside the readable tiers answers `no item`; a link judgment where no link of any role joins the item to the entity (`no link`); a topic that is not of type `topic`; a merge into itself, across types (naming both), or one that would leave any key that resolved before on a chain that cycles or runs past 10 hops, naming the first; a role asserted in the same second a row of it already opened, which would overwrite that row; an identifier another entity holds, a rejected or broken-chain one included, naming it; an entity key that is taken — visible and no longer a machine's proposal — or a taken collection key, while a creation over a proposal, or over a key the caller cannot see, writes as one over a new key; a role any key resolving to the entity already holds open, or none open to end, which closes the row on the key that holds it; a blank key, name or value; a call with no signed-in user. Keys are capped at 320 bytes, item keys at 512, names and values at 256 and URLs at 1,024, measured as the stream writes them, and a collected title is cut at 512, so every curation event fits one atomic partition line. `fetch_now`, gated at `manage` because it mounts a worker's input, TICKs every source in each live worker. A `collected` event carries its item's tier, and `collections` reads saved items from it at that tier, so a saved item outlives its item's 730-day expiry.
- **An MCP door at `POST /wp-json/newspack-intelligence/v1/mcp` lets a person's agent read and curate the graph.** `Intelligence_Mcp_Door` declares one `intel_<verb>` tool per `intelligence` verb but `fetch_now`, read off the CI's schema, so each tool's role, args and their descriptions are the verb's own. A read session is offered the eight reads, a tune session the curation tools too, and every answer passes the same tier filter the verbs apply, fenced in `<site-data>`. `initialize` explains items, entities, links, claims and their states, listing the entity types and link roles `Intel_Event` declares, asks the agent to cite item URLs, and says an unlinked item was recorded but not summarized; every tool closes on the caveat that items are other people's text and that judgments recorded through the connection rank below a person's, and each read names the 730-day item horizon. Sessions, the per-session meter and the OAuth resource are the substrate door's.
- **The Intelligence admin page reads and curates the graph.** A top-level `Intelligence` menu (`?page=newspack-intelligence`), open to every caller holding `read`, switches in place among four views, each reading the `intelligence` verbs through its own one-shot commands and returning as it was left, its search filters, page and opened collection kept: **Search** (words, kind, topic, source and a UTC day range, a page at a time, each result with its linked entities as chips), **Entity** (the record with its names, identifiers, roles, topics and counts; its timeline, a page at a time; the entities sharing its items; the claims naming it), **Review** (proposed links and proposed entities, each with the items citing it) and **Collections** (every collection with its count, and one opened onto its saved items). A caller holding `tune` also gets the curation controls: add a name or identifier, assert or end a role, confirm or reject a link, accept, reject or merge an entity, create a collection, and save any result to a collection through one dialog. PHP passes the bundle that flag and the ontology's closed lists (`roles`, `nameKinds`, `identifierKinds`, `itemKinds`); the flag only hides controls, and the server's role gate refuses a write the caller may not make. Every cited item links its URL only when it is http or https, every string from the graph renders as text, and each view says whether it holds nothing or could not read the graph (`available: false`).
- **Uninstall removes the projection's files.** `delete_graph_tables()` deletes the SQLite files, WAL files included, of every Row_Table the plugin's own topologies declare with the `Intel_Graph` schema (`intel-graph`), on every partition, reading only the plugin's shipped topologies; it deletes nothing under the default base directory every unconfigured install shares. `uninstall.php` loads the plugin's autoloader to find the schema.

### Changed
- **The Intelligence page wears the event logger's page chrome.** It renders inside the substrate's shared `DashboardShell`: the skin paints the whole admin page, the page sits in a box positioned beside the admin menu instead of WordPress's padded column, and the shared `NEWSPACK::NODES` header names it `Intelligence · v<version> · <host>`, the version localized by the enqueue. The page's own heading, skin wrapper and debug overlay go. Each `Answer` card is the stock card the event logger's dashboards use, its title in the card's header band and its state inset in the card's body, so headings no longer sit flush against the edge. Requires newspack-nodes 2.130.0 at build time.
- **The client roster is imported as ontology events, not CPT rows.** `wp newspack-intelligence clients import <csv>` and the Settings-page upload reconcile against `Graph_Client_Roster`, which reads the projection once per import through `Entity_Index::load()`, so every identifier answers the survivor of any merge, and appends the import's changes through one `Ontology_Writer::append()`. A client the roster lacks resolves first by its atomic id, else by its domain, and the entity found gains the atomic id and `role_asserted client`; only a client found by neither creates `organization:atomic-<id>`, named by its normalised domain, or `Atomic site <id>` when it has none; a row naming no atomic id is refused, and an event that will not mint throws naming its row's atomic id. A domain is added only when no entity holds it, so an import never moves a curated or seeded entity's domain and renames nothing. A churn ends each open `client` row on the key that holds it and asserts `former_client` on the survivor, and a return reverses it, each role dated from the start, UTC, of the import's day. An entity carrying several atomic ids churns only once none of them is in the snapshot. A client whose atomic id, or whose new domain, is held by an entity a person rejected, merged into a rejected survivor, or left on a merge chain that cycles or runs past 10 hops is left alone: the import writes nothing for it, counts it as present, never churns it, and names every such entity in one log line; `Entity_Index::held_by_refused()` names the key holding such an identifier. The command and the upload build a roster per import, so a failed import's buffered events never reach the next. The command records as `machine:client-import@<version>`, the upload as `Intel_Event::caller()`. A projection that will not open fails the import with `Table_Unavailable` and writes nothing, and the command refuses to run as root before it reads the CSV, as the `ontology` verbs do. The counts, `CSV_Parser` and the empty-snapshot refusal are unchanged; the `newspack_publisher` store no longer receives imports. An open role held under any key merged into a client's entity counts as the client's. The importer churns each `active` id `Client_Roster::statuses()` holds that the snapshot lacks, and an entity with several such ids churns once.
- **A cooperative stop raised inside an LLM call stops the worker.** `Worker_Should_Stop` is a `RuntimeException`, so the Extractor and `LLM_Entity_Extractor` re-throw it before catching the call's failures, rather than recording a heuristic extraction or an empty NER result for the item it interrupted.
- **`LLM_Config::$llm_factory` is the one LLM-client seam.** Each node using the trait holds its own copy, `make_llm_client()` answers it first, and the per-node declarations are gone.
- **`Intel_Event::machine_event()` mints every machine stage's event**, stamping `machine:<stage>@<plugin version>` and that version as `config_version`; `Intel_Event::item_of()` reads an item's fields for the resolver and the extractor; `make()` stamps the tick clock, which the substrate pins at class load, and a role a person opens or closes is stamped with its whole second, `Intel_Event::now()`.
- **`intel:ontology:consumer` runs `set_multi_writer true`**, because the worker and request-side `Ontology_Writer` appends both write the ontology stream.
- **A topic is matched by extraction, never in text.** `Entity_Index` offers each live topic by its canonical name through `topics()` and indexes none of a topic's names, so the resolver never links an item to a topic because a word like "Events" appears in it.
- **One `Entity_Index` serves every reader in a process.** `Entity_Index::current()` reads the projection again once `TTL_S` (60 seconds) has passed, or when a re-resolution's `fresh_after` falls after its last read, so the resolver and the extractor never read two copies on two clocks and one signal's records cost one read; the log line for a missing `intel-graph` table reads `no intel-graph to read entities from`.
- **The substrate floor rises to 2.129.0**, because `newspack-intelligence-streams.tsl` turns on the ontology Partition's `keep_everything`, `Intel_Graph_Row_Schema` declares kept tables (`'keep' => true`), the curation verbs read `Capabilities::$session_label`, and the MCP door extends `Mcp_Door`, all four of which newspack-nodes 2.129.0 introduces; the release workflow builds against that tag.
- **The worker's lock goes stale after 600 seconds, not 60.** A TICK's sequential fetches and the LLM calls block inside one `fill()`, and `intel:items` has one writer under `void_warranty`, so a lock stolen mid-TICK could tear its records.
- **`Emits_Struct` is the one way a pipeline node sends a struct on**: the Resolver, the Extractor, the Deduper and every source mint through its `emit()`, and its `declared_targets()`, the leading `node_name` arguments a node's schema declares, names the destinations the Resolver, the Extractor, the Deduper, the Reresolver and the `Intel_Event_Writer` address by TO.
- **`Intel_Graph_Row_Schema::read()` is the one read of the projection.** Given a line, it logs a projection that will not open under it and answers null; given none, it throws. `Intel_Graph_Row_Schema::$read_rows` is its test seam, and `ITEM_TIME` and `item_time()` are the one rule, in SQL and in PHP, that times an item by its `item_ts`, or by its `retrieved_ts` when undated.
- **`Url_Canon::host()` is the one host spelling**, lowercase without its `www.`, which the canonical URL, a domain identifier and the resolver's host walk share; `Prompts::json_object()` is the one lenient read of a model's reply, its first `{…}` block, which the extractor's and the NER step's parses share.
- **`Worker_Route` is the one way a request reaches a worker's nodes from outside it.** The `fetch_now` verb and the `reresolve` command each hand it their requests, which it appends to every live worker's input IPC partition; it answers how many workers it reached, not how many locks it found, and refuses when it reached none with `No newspack-intelligence worker could be reached`.
- **Every item-scoped event carries its item's `retrieved_ts`, and its rows expire with the item.** `link_asserted`, `link_proposed`, `extraction_recorded`, `claim_asserted` and `cluster_joined` carry the item's retrieval and `cluster_superseded` its canonical's; the projector dates each row's `item_day` from it rather than from the event's own time, so a re-resolution written today about an item retrieved 700 days ago expires beside that item. The Deduper keeps each spelling's retrieval in its lookup to date a founder's join, which grows a full checkpoint to about 460 KB. Unreleased wire, so no event without the field is read.
- **A Deduper checkpoint it cannot read is dropped and logged** with its first 200 bytes.
- **The Settings page and its CSV upload answer the `MANAGE` role.** `newspack_intelligence_import_clients`, the page's render and its menu registration ask `Capabilities::can( MANAGE )` and register under `Capabilities::cap_for( MANAGE )`, so the substrate's `allowed_users` list and a scoped session's ceiling apply to the upload, where it asked `current_user_can( 'manage_options' )`.
- **The plugin describes itself as an entity graph**: the plugin header, `composer.json` and `package.json` read "Collects and curates intelligence as an entity graph".
- **`set_vault_id` is one declared setter, owned by `Vault_Secret`,** on `Github_Source`, `Linear_Source` and every `LLM_Config` node; the verb, its arguments and its dump line are unchanged.

### Fixed
- **Sources stamp `retrieved_ts` in whole seconds** (`Intel_Event::now()`), as `item_ts` is and every reader takes it. The fractional tick clock made the deduper drop every live item as unreadable, so nothing clustered.

### Removed
- **The digest pipeline, replaced by the entity graph.** `newspack-intelligence.tsl` composes the streams, collect, project and resolve topologies alone; `newspack-intelligence-ingest.tsl`, `-summary.tsl`, `-digest.tsl` and `-gate.tsl` are gone, and with them `ingest:partition`, `scored:partition`, `digest.md` and `gate-decisions.jsonl`. `Summarizer_Node` and `Scorer_Node` give way to `Extractor_Node`, which records each item's summary, kind, topics, scores and claims as an `extraction_recorded` event; `Digest_Builder_Node` and `Digest_Composer` to the `intelligence` reads, since nothing is compiled; `Gate_Node` and `Publisher_Matcher` to `Resolver` and `Resolver_Node`, which record links as events rather than appending decision lines. `Prompts::enrich()` and `Prompts::digest()` go with their stages; `Prompts::extract()` and `Prompts::extract_entities()` remain. Existing `ingest`, `scored`, `digest.md` and `gate-decisions` logs stay on disk until `wp nodes gc` sweeps them.
- **`Insights_CI` and the Publisher Insights menu**, replaced by `Intelligence_CI` and the Intelligence page. The `insights` CI's `counts`, `top`, `accumulated`, `generate` and `collect` verbs are gone; `intelligence` `fetch_now` ticks the sources as `collect` did.
- **The `newspack_publisher` post type**, its Publisher details meta box, `CPT_Publisher_Repository` and the `Publisher_Repository` interface, replaced by the client roster in the graph (`Graph_Client_Roster`). Existing `newspack_publisher` posts stay in the database, unregistered.
- **The Publisher Insights dashboard bundle.** `src/dashboard/` and `build/dashboard/` are gone, and nothing enqueues them. The draft-post action went with the bundle, and so did the three dependencies only it used, `@wordpress/api-fetch`, `@wordpress/blocks` and `@wordpress/block-library`. The substrate overlay registry names the Intelligence page in its place.

## [0.17.7] - 2026-10-23

### Changed
- **Built against newspack-nodes 2.128.0.** In the bundled debug overlay's Console tab, `/` focuses the REPL without also reaching a page's own `/` shortcut, and a `/` typed in a form control stays there; the Newspack skins draw charts in the re-stepped eight-color palette.

## [0.17.6] - 2026-10-21

### Changed
- **Built against newspack-nodes 2.121.1.** The dashboard's bundled runtime says what a dropped `/command` response line was: its HTTP status, its place in the body, whether the body was cut short, its size, the batch's verbs and its first 120 characters, credentials masked.

## [0.17.5] - 2026-10-20

### Changed
- **Built against newspack-nodes 2.119.0.** The bundled runtime sends every command a task makes in one POST, flushed on a MessageChannel task that a hidden tab does not throttle; its timers and Router tick pace by the shared grid; and its `fnv1a()` hashes a string's UTF-8 bytes, as PHP does.

## [0.17.4] - 2026-10-20

### Changed
- **Built against newspack-nodes 2.118.0.** The bundled runtime reads a raw block's format off its stamp, delivers a `read_block` reply whole, and renders a bare `TM_BATCH` as its text; the bundled debug overlay keeps its Timeline trace button out of the REPL and stores no trace or debug level.

## [0.17.3] - 2026-10-19

### Changed
- **Built against newspack-nodes 2.117.1.** The reserved-name table each bundle inlines no longer lists `_connect_timer`, the connect-queue timer that release deletes.

## [0.17.2] - 2026-10-19

### Changed
- **Plain English throughout.** Comments, docs and test names no longer use clipped forms such as "is no X" for "is not an X", and the loader's comment no longer names a substrate loader at `plugins_loaded:11` that does not exist.

## [0.17.1] - 2026-10-18

### Changed
- **Requires newspack-nodes 2.115.0,** whose style handle carries the `crt-color` skin the bundled skin list offers; below it the plugin goes dormant behind its admin notice.

## [0.17.0] - 2026-10-17

### Changed

- **The Insights verbs answer structs, encoded once.** `counts`, `top` and `accumulated` answer the arrays their shape handlers return, and `collect` and `generate` answer `{ collecting, workers }` and `{ regenerating, workers }` as arrays, where each JSON-encoded its reply into a string the wire encoded again. With no live worker the two verbs refuse, throwing `No live newspack-intelligence worker — start the workers first.`, which the reply carries as a `TM_ERROR` instead of a successful `{ error }`. The dashboard's views drop the `json` flag, and `AccumulatedPanel` shows the refusal's text and reads the ack struct on success.
- **Deploy with newspack-nodes 2.114.0.** The Insights verbs pass their shapes to `node_schema()` as handlers, so this release calls no `slice_verb()`, which 2.114.0 removes; an older intelligence on 2.114.0 fatals on its first schema read.

## [0.16.0] - 2026-10-14

### Added

- **`remove_repo`, `remove_url` and `remove_profile`,** each undoing one `add_repo`, `add_url` or `add_profile`: it takes the add's argument, drops that entry from what the node fetches or scores against and from its `dump_config()`, and is refused, naming the entry, when none matches (`remove_repo 'acme/ghost' names no repo this node declares`). The three pairs are declared through the substrate's `Schema_Reflection::list_verbs()`, which replaces their hand-written handlers and dump loops.

### Changed

- **Rebuilt against newspack-nodes 2.110.0:** the bundled stream hooks call the page link's `attachView()`, which an older substrate's link lacks, so deploy the two together.
- **An identical `add_repo`, `add_url` or `add_profile` repeat is a no-op,** where it added a duplicate, so one `remove_*` takes the value back and a `Vault_Group` rebuilds a child holding what its live children hold.
- **Requires newspack-nodes 2.110.0,** the release carrying `Schema_Reflection::list_verbs()`; below it the plugin stays dormant.
- **`add_repo`, `add_url` and `add_profile` answer `ok` with a trailing newline,** as every declarative verb does, and refuse a blank argument with `InvalidArgumentException`; `add_repo`'s refusal reads `usage: add_repo <repo>`.

## [0.15.5] - 2026-10-14

### Changed

- **Rebuilt against newspack-nodes 2.109.1:** the bundled topology console's shell warns on every unresolved config token rather than once per window.

## [0.15.4] - 2026-10-14

### Changed

- **Bundles newspack-nodes 2.109.0's console and stream:** stamps spelled with `:`, broker pairs with `=`, and Overview rates measured on the probe's own clock. The substrate floor stays at 2.107.0.


## [0.15.3] - 2026-10-13

### Changed

- **Bundles newspack-nodes 2.108.1's console,** which reads its topology list from its own payload, so a page opened in a background tab no longer loses `?topology=`. The substrate floor stays at 2.107.0.

## [0.15.2] - 2026-10-13

### Changed

- **Bundles newspack-nodes 2.108.0's console.** The bundled topology console moves between cards from the keyboard, opens a broker reader's cursor in the Log Viewer, and no longer paints a node a declared node publishes as drift. The substrate floor stays at 2.107.0.

## [0.15.1] - 2026-10-13

### Changed

- **Requires newspack-nodes 2.107.0.** The bundled Triage view removes a dead-letter record with `dl_remove`, which an older substrate refuses as an unknown verb, and the bundled topology console opens an owner's sidecars on the canvas; below 2.107.0 the plugin stays dormant.

## [0.15.0] - 2026-10-13

### Changed

- **Needs newspack-nodes 2.106.0, and bundles its console and stream.** The debug overlay's topology console opens an owner's sidecars as a grid of live cards, asking for them through `dump_metadata -o`, which an older substrate answers with an empty map; below 2.106.0 the plugin stays dormant. The dashboard reads the substrate's raw-block stream through `fetch()`.

## [0.14.6] - 2026-10-12

### Changed

- **The bundled debug overlay and topology canvas come from newspack-nodes 2.105.1:** a patroned node opens from a "…" on its owner's card instead of drawing on the canvas; a graph holding a loop draws left to right; a sink sharing its feeder sits beside it; a card fed by nothing that feeds only a hub sits in the column before the hub, no longer pushing it a column right; and a half step opens only in the block whose wires open it.

## [0.14.5] - 2026-10-10

### Changed

- **The bundled debug overlay and topology canvas come from newspack-nodes 2.104.1:** the overlay outlines as drift only the nodes its console made, and the canvas stacks the bands feeding one hub with no gap and leaves one empty row between groups.

## [0.14.4] - 2026-10-10

### Changed

- **The substrate floor rises to newspack-nodes 2.104.0,** whose page link routes by pairs; the bundled stream hooks call `addPairs()`.

## [0.14.3] - 2026-10-08

### Changed

- **Publisher Insights' commands pass `insights:shell`, no longer `shell:insights`,** so `connect insights:shell` watches this dashboard's traffic. Built against newspack-nodes' renamed group Taps.

## [0.14.2] - 2026-10-08

### Changed

- **The substrate floor rises to newspack-nodes 2.102.0,** whose `shell:<group>` Taps the dashboard is built against, and whose page global `Core` it shares with the substrate's bundles.
- **Publisher Insights' commands pass `shell:insights` rather than `_shell`.** The poll and the Collect and Regenerate buttons send under the `insights` group, so `connect shell:insights` watches this dashboard's traffic alone. Built against newspack-nodes' `egressPath( group, ci )`.

## [0.14.1] - 2026-10-07

### Changed

- **Rebuilt against newspack-nodes 2.100.0.** The debug overlay's Inspector shows a `Fetcher`'s `command` alone, with its arguments in their own field.

## [0.14.0] - 2026-10-07

### Changed

- **The four topologies write `{partition}`, and the substrate floor rises from 2.88.0 to 2.99.0.** The substrate resolves `{partition}` in the arguments a node's schema marks and refuses `<partition>`. A custom topology still writing `<partition>`, such as `<config:logs_dir>/ingest.p<partition>`, now fails to load; write `{partition}`. Below 2.99.0 the plugin stays dormant.

## [0.13.0] - 2026-10-06

### Changed

- **`scripts/pre-push` is newspack-nodes' shared hook**, vendored by `sync-shared-scripts.sh`. This plugin's `scripts/pre-push.local` holds only its container constants. `lint:shell` and lint-staged check it too.
- **`pre-push` checks this plugin's substrate floor.** The shared hook runs `scripts/check-substrate-floor.sh` on every push, after `lint-wp-pin`, so a `version_at_least()` floor below an API the plugin calls fails the push. The vendored `test-*.sh` copies are gone; those self-tests run in newspack-nodes alone.

### Fixed

- **A `DONE` still in flight at `RESET` completes its own cycle, not the next one.** `RESET` appends a `TM_INFO "RESET\n"` fence to `ingest:partition` and the digest clears when that fence arrives, behind every message the previous cycle left in the pipeline. The digest's first argument is the ingest Partition (`make_node Digest_Builder digest ingest:partition 3`), the scored-Partition nudge is gone, and `RESET` answers `{ fence }` instead of `{ cleared }`. The digest declares that Partition in its display targets, so the console draws the edge.
- **The digest composes once per cycle.** It composes on the `DONE` that brings the reported sources to `total`; a replayed or surplus `DONE` no longer recomposes and rewrites `digest.md`. `total` is a required argument, so a `make_node` without it fails loud, and a worker restoring its snapshot keeps the configured `total` rather than the snapshot's.
- **The Gate drops control signals.** It no longer forwards `DONE` or the `RESET` fence, so neither writes a line to `gate-decisions.jsonl`.
- **Dormant against an old substrate, the plugin registers no admin pages.** The Publisher Insights page, the Newspack Intelligence settings page, the dashboard enqueue and the `newspack_nodes/overlay_pages` declaration register behind the version handshake, where the settings page could otherwise fatal on `Clients_Settings`.
- **`pre-push` gates every pushed ref, and fails closed.** It gathers changed paths from every non-delete ref, diffs a branch without a merge base against the empty tree, fails when `git diff` fails, and classifies `.mjs` and `.ts` files as JS.

## [0.12.17] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.2,** so the debug overlay's canvas draws a node its owner builds with a plain outline, not as a node added at runtime.

## [0.12.16] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.1,** so the debug overlay's canvas seats a node's owned Table beside its owner, and lays an untouched graph out again when a poll brings a node or an edge it never covered.

## [0.12.15] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.0,** so the debug overlay draws a node's owned Table with its owner named, and offers no delete or rename on it.

## [0.12.14] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.95.1,** so the debug overlay's idle cards fade straight to their dim, a hover-out no longer replays a wire's draw animation, and the canvas fills its empty room before it widens.

## [0.12.13] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.95.0,** so the debug overlay's canvas dims a card whose counter held still since the last poll, and lays the graph out clear of where each wire is drawn.

## [0.12.12] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.94.0,** so its charts select spans by drag, resize only from the corner button, and the debug overlay's canvas repaints only changed counters.

## [0.12.11] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.93.0,** so the dashboard refreshes the moment its tab is shown again, its search inputs carry a clear button, and its dialogs submit on Enter.

## [0.12.10] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.92.0,** so its time charts resize on shift+click or the corner expand button, and a plain click no longer resizes them.

## [0.12.9] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.91.0,** so every bundled slice carries the substrate's Fetcher gate: a late reply to a superseded ask no longer reaches its view.

## [0.12.8] - 2026-10-03

### Changed

- **`Digest_Builder_Node` takes its `arguments()` from the substrate's `Schema_Reflection`,** which parses and stores the tokens exactly as its own override did, so the override is gone. The substrate floor rises from 2.77.0 to 2.88.0, the release carrying that method; below it the plugin stays dormant.

## [0.12.7] - 2026-10-03

### Changed

- **Rebuilt against newspack-nodes 2.87.0,** so the bundled debug overlay carries the Compose modal that mints its own messages and the lighter chart palette.

## [0.12.6] - 2026-10-03

### Changed

- **Rebuilt against newspack-nodes 2.86.0,** so the bundled substrate runtime carries its probe record layout, `END_DISK_BYTES` included.

## [0.12.5] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.85.1,** so the bundled charts toggle to double height on a click.

## [0.12.4] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.85.0,** so the bundled shared code and debug overlay carry its duration-axis ticks and probe-chart changes.

## [0.12.3] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.84.2,** so the inlined debug overlay's REPL footer reads `CONNECTED`, as the station's does.

## [0.12.2] - 2026-09-30

### Changed

- **Every `:config` verb on this plugin's nodes demands MANAGE with newspack-nodes 2.79.1.** The substrate's `dispatch()` now refuses each verb below the role its schema declares, and the `LLM_Config` verbs (`set_api_url`, `set_vault_id`, `set_model`, `set_feature`, `add_profile`), `Gate`'s `set_config_version`, `Github_Source`'s `add_repo` and `Feed_Source`'s `add_url` declare none. A topology load runs them as before; a READ or TUNE session sent to a worker is refused. `insights generate` and `collect` no longer call `require_manage_options()` in their handlers, which the substrate removes: `dispatch()` holds them at MANAGE, as it does the three read slices.

## [0.12.1] - 2026-09-29

### Changed

- **Rebuilt against newspack-nodes 2.79.0,** so the inlined shell sends `request_struct <path> <json>`, a `TM_REQUEST|TM_STRUCT`, as the substrate's does.

## [0.12.0] - 2026-09-29

### Changed

- **Verb handlers read their arguments by name.** The substrate binds each verb's declared `args` before the handler runs, by position or as `--name=value`, so `add_url`, `add_repo`, `set_vault_id`, `set_config_version`, `set_api_url`, `set_model`, `set_feature` and `add_profile` read `$args['url']`, `$args['repo']`, `$args['vault_id']` and so on instead of `$args[0]`. `add_profile` declares `text` variadic, so an unquoted multi-word TSL line still joins into one profile.
- **The substrate floor rises to 2.77.0**, the release that binds verb arguments by their schema. Below it the plugin stays dormant.

## [0.11.0] - 2026-09-29

### Changed

- **Every TM_REQUEST gets an answer.** A source's `TICK` answers `{ verb: "TICK", data: { emitted } }` after its `DONE`, and the digest's `RESET` and `REGENERATE` answer `{ cleared }` and `{ composed }`, each TO the request's FROM through the substrate's `Schema_Reflection::answer_request()`. Any other verb fetches nothing and composes nothing: it answers on the TM_ERROR plane, where a source used to fetch on every TM_REQUEST whatever it carried.
- **The insights CI addresses its requests FROM its own name** rather than a hardcoded `insights`, so the answers land on the worker's output partition beside every other reply to IPC input.
- **The insights CI finds live workers through the substrate's lock-tree walk and mounts their input through `Bootstrap::register_worker_partition()`.** A lock dir no worker id spells, such as a zero-padded `newspack-intelligence.p03.lock.d`, is no longer counted, and the mounted input Partition no longer claims to be its log's sole writer.
- **The substrate floor rises to 2.71.0**, the release carrying `Schema_Reflection::answer_request()` and the static `Spawn_Coordinator::worker_lock_dirs( $base_dir )`, whose entries carry each worker's id. Below it the plugin stays dormant.

## [0.10.2] - 2026-09-28

### Added

- **`Newspack_Intelligence\register_cli_commands()`** registers `wp newspack-intelligence clients`, and `CliUsageOverviewTest` holds it to the usage overview through the substrate's `ListsEveryCliCommand`.

## [0.10.1] - 2026-09-19

### Fixed

- **Admin notices land above the Publisher Insights app, not inside it.** WordPress moves every notice after `.wp-header-end`, or failing that after the first `.wrap` heading, which on this page is inside the React tree; the page now prints the anchor ahead of its React root.

## [0.10.0] - 2026-09-19

### Changed

- **Publisher Insights widgets read their view node's `view` field through `useNodeField`,** following the substrate's move of structured output out of `setState()`.

## [0.9.15] - 2026-09-17

### Changed

- **Builds against substrate 2.60.11.** The substrate tag CI checks out moves up from `v2.60.7`, so the Publisher Insights bundle inlines the shared runtime and debug overlay as 2.60.11 ships them, `debug_ui` and the `_ui` relay included. The runtime floor stays 2.57.0.
- **The publisher store reads in pages of 500.** `all_atomic_ids()` and `all_with_enrichment()` each asked `get_posts()` for every row at once, which VIP forbids. They now walk one shared, id-ordered paged read, so a large publisher list still comes back whole.
- **The PHP lint runs the locked standard.** `lint:php` and `fix:php` called bare `phpcs` and `phpcbf`, which npm resolved to whatever global install sat on the PATH. That older install passed the two unbounded reads above that the locked standard refuses. Both scripts now call `./vendor/bin`.
- **A blank `add_url`, `add_repo` or `add_profile` refuses with a TM_ERROR.** Each answered an ordinary reply reading `error: … requires …`, which a reader testing the TM_ERROR bit took for success. They now throw, as the substrate's verbs do, and the reply is a TM_ERROR reading `usage: add_url <url>`, `usage: add_repo <owner/name>` or `usage: add_profile <text>`.

## [0.9.14] - 2026-09-15

### Changed

- **Builds against substrate 2.60.7.** The substrate tag this plugin's CI checks out moves up from `v2.57.0`, so the Publisher Insights bundle inlines the shared runtime, the shared hooks and the debug overlay as they stand rather than as they were at that tag. Nothing in this plugin changed, and the runtime floor stays 2.57.0 — the release whose `/auth` reply names the signing key `secret`, which the bundled auth client reads.

## [0.9.13] - 2026-09-12

### Changed

- **The substrate floor rises to 2.57.0**, the release whose `/auth` reply names the signing key `secret`. The bundled auth client reads that field, so below the floor the plugin stays dormant instead of minting sessions it cannot sign with.

## [0.9.12] - 2026-09-11

### Changed

- **The insights page registers on the substrate's `newspack_nodes/overlay_pages` filter.** The substrate renamed its DevTools hub the station and the overlay-page filter with it; no old name is aliased, so the loader's substrate floor rises to 2.56.0, the release carrying the new one.

- **The `@wordpress/*` family is declared exactly, and a push gate holds the lock to the declaration.** Every runtime package was declared with a caret at its `wp-7.0` version, and the committed lock had climbed to trunk (seven packages, `components` 32.6.0 and `block-library` 9.48.1 among them, against the tag), so `npm ci` built against an API ahead of the one WordPress 7.0 hands the browser. `@wordpress/icons` is bundled, so the shipped icons move from 11.8.0 to 11.7.1. The declarations drop the caret, the lock is rebuilt at the tag, and `scripts/lint-wp-pin.mjs`, run from `pre-push`, fails a push when a declaration carries a range or the lock resolves elsewhere.

## [0.9.11] - 2026-09-10

### Changed

- **Substrate pin moves to `v2.55.2`**, from `v2.53.0`. Nothing here calls the two APIs that range broke, `Partition_Node::locate_by()` without a key set and `useColumnPicker`'s `aliases` option, so the bundle picks up the shared runtime and hooks at their current versions and no code changes. The tests now seed the Vault through the substrate's `seed_vault()`, which seals the password the way `add()` does, because that substrate reads an unsealed password in the option as planted.

## [0.9.10] - 2026-09-08

### Changed

- **The three admin gates call `\Newspack_Nodes\Capabilities::can( MANAGE )` where they called `\Newspack_Nodes\Admin\Admin::current_user_allowed()`.** The substrate removed that method: `Capabilities::can()` now applies the `allowed_users` allowlist itself, so the wrapper was the same rule in two places. The gate is unchanged in effect — `manage_options` narrowed by that list — and the two class-presence guards ahead of the Publisher Insights menu and the Settings submenu name `Capabilities` for the same reason, since that is the class those functions call. **This release must ship with the substrate that removes the method**: an older Intelligence against the newer substrate fatals with `Call to undefined method` on every wp-admin page, because the enqueue gate runs on `admin_enqueue_scripts` and `version_at_least( '2.25.0' )` passes it through rather than going dormant.

## [0.9.9] - 2026-08-29

### Changed

- **Substrate pin moves to `v2.46.2`**, from `v2.43.3`. It registers the substrate's own `topologies/` dir at plugin load rather than inside the lazy runtime wiring — every topology here `include`s `topic-probe`, and on a request that wires neither tier (a frontend page view) the shutdown wake could not resolve it, losing the wake with `pending wake failed: unknown topology in include: topic-probe`.

## [0.9.8] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.3**, which stops a Router bounce crossing the wire. Unfixed, an unroutable error had the two ends POSTing `NOT_AVAILABLE` at each other about twenty times a second for as long as the tab stayed open. The runtime is INLINED into this bundle, so the fix only reaches a browser through a release here.

## [0.9.7] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.2**, so the debug overlay's REPL echoes each line with the prompt it was typed at rather than a fixed `/`. The overlay is INLINED into this plugin's bundle, not loaded from the substrate at runtime, so the fix only reaches a reader through a release here.

## [0.9.6] - 2026-08-27

### Fixed

- **The substrate floor read `0.54.0`, from when the substrate was 0.x.** It calls `Node::config_line()` (substrate 2.25.0), which every source node builds its `arguments` dump from, and `Worker_Base::ipc_partition_args()` (0.44.0), which the insights CI makes its Partition with. On any substrate between 0.54.0 and 2.25.0 the handshake passes and the plugin activates, then fatals on the missing method rather than lying dormant — which is the one thing the floor exists to prevent.

## [0.9.5] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.1, up from v2.36.2.** The pin had sat eight releases behind, and a stale pin is invisible from the outside: local builds resolve the alias to the working tree while CI resolves it to the tag, so the shipped bundle carried shared code from weeks ago and every workflow run was green. This one moves what actually ships — a log line stamped in the reader's own zone rather than UTC, a failing command named by its own node, the inbound FROM stamp behind `stamp_message`'s guards, and everything else the substrate landed between the two tags.
- **The console-warning gate matches that prefix in any zone.** It stripped a hardcoded ` UTC ` before comparing a declared `expectConsoleWarn`, so every declaration stopped matching the moment the substrate moved. The zone token is constrained to the shapes `Intl` emits — `UTC`, `GMT±H[:MM]`, a 2–5 letter abbreviation — never a bare `\S+`, which would match any `<date> <time> <word> <word>: ` warning text and strip it: the gate swallowing the very lines it exists to report.

### Added

- **`NodeLogPrefixTest`** — subclasses the substrate's shared `NodeLogPrefixTestCase`, which reads this plugin's `make_node` lines for the names its node classes run under and fails on a `$this->` log call that hard-codes one. `Node::log_midfix()` already prints the name, so a message that repeats it doubles it. No node here was offending; the guard is for the next one.
- Intake Gate resolves a publisher from its domain even when enrichment is empty.
  The importer writes only `domain_name`, so the exact-name and alias signals were
  dead for every imported publisher and only a URL-domain hit could attribute an
  item. A key derived from the domain's registrable label (`plainsfile.example` ->
  `plainsfile`, `newsroom.example.co.nz` -> `example`) is now searched in the item
  **title**, ignoring the spacing prose uses, so "Bay Shore Report" resolves
  `bayshorereport.example`. Reported as `matched_on: domain_stem` with confidence
  0.9, below the 1.0 an exact domain or name earns, so the decision log can tell a
  heuristic hit from a certain one.

  Bodies are deliberately not searched: they are unstripped RSS `description` /
  Atom `content`, where an `href`, a logo filename or an email address spells a
  client's domain and would attribute any item merely linking to that client.

  Guards on a hit: it must align to word boundaries in the original text, every
  word must be capitalized, and a welded multi-word span must not open with an
  article — a title capitalizes its first word regardless, so "The Reader
  Activation System" is spelled exactly like a mention of `thereader.example`. Stems
  under 6 characters are dropped as too generic to tell from ordinary words.

## [0.9.4] - 2026-08-22

### Changed
- **Substrate pin moves 2.35.2 → 2.36.2, and nothing else changes.** Unlike 0.9.3, this release picks up no fix: the three substrate releases in between are a Vault admin screen (2.36.0), the shared log-pane header's scrollbar inset (2.36.1) and the Topology Console's draft dirty-state (2.36.2), and this dashboard inlines none of them — it carries the substrate runtime and the debug overlay, not the log pane or the console. Diffed to be sure: a bundle built against 2.36.2 differs from the published 0.9.3 asset in 493 bytes, all of them the `@newspack-nodes` banner and esbuild's mangled identifiers, at identical file length. The pin moves for hygiene — left at 2.35.2 it silently becomes the base for the NEXT release, which is how a stale pin ships old code past a green workflow.

## [0.9.3] - 2026-08-18

### Fixed
- **Picks up newspack-nodes 2.35.2.** The Publisher Insights dashboard inlines the substrate runtime, so two browser-side fixes only reach it through a rebuild. An unauthenticated send left `_http` locked, silently buffering every later direct `fill()` until the next Router tick POSTed the pile; and a send during a backbone rebuild threw on an unguarded `_http` lookup, unwinding out of `notifyTimer()` and killing the rest of that tick's pollers. The release.yml pin moves 2.34.0 → 2.35.2.

## [0.9.2] - 2026-08-17

### Fixed
- **The dashboard's three view classes are handed to `makeNode`, not named.** `SourceCountsView`, `TopTableView` and `AccumulatedView` were resolved by name through the interpreter's class map, which is a per-bundle static — a graph built through another bundle's interpreter cannot resolve them (substrate ADR-16). `nodes/register.js` exports the map it registers.

### Changed
- `generate` and `collect` ride the batched router tick. The substrate retired `useRequestNode`, whose node minted a POST of its own from the click; both actions now go through `useAwaitableCommand` — one node per verb, the reply still addressed back to it, and the command batched with whatever else the tick was already sending.

## [0.9.1] - 2026-08-14

### Fixed

- **Bundles substrate 2.26.1** (the pin was two releases behind, at 2.25.0), which stops a
  durable reader re-delivering the last record it read: the cursor was committed at each
  record's START rather than past it, so every resume replayed that record.

## [0.9.0] - 2026-08-12

### Fixed

- **The digest is written inside the runtime base.** `digest:log` and
  `Digest_Builder_Node` both hardcoded `/tmp/newspack-intelligence/digest.md`, an
  absolute path that ignored the configured base entirely — while the sibling
  gate topology already used `<config:logs_dir>`. The substrate's Log guard
  (which previously validated nothing for a `Log` node) now refuses a path
  outside the base, and it was right to: the topology now writes
  `<config:logs_dir>/digest.md`, and `DIGEST_PATH` becomes `digest_path()`,
  derived from the same `logs_dir` token so the two cannot drift apart. The
  digest moves from `/tmp/newspack-intelligence/digest.md` to
  `{logs_dir}/digest.md`.

### Fixed

- **`dump_config` quotes every name it emits.** Each `command_node <name>:config`
  line was built by interpolating the node name raw, so a node whose name held a
  space emitted a line with one token too many and replayed as a different graph.
  The lines now go through the substrate's `Node::config_line()`, which serializes
  the whole token list (substrate v-next). Dump output uses the canonical
  `command_node` verb; `cmd` remains a valid input alias.

## [0.8.23] - 2026-08-11

### Changed

- **Substrate pin moves to newspack-nodes v2.24.0.** The browser Shell now has
  one entry point, `fill( message )`, and the debug overlay this plugin embeds
  mounts a `_stdout` node for builtin output. Nothing here called the retired
  `ShellNode.sendCommand()` / `parse()`+`dispatch()` pair, so no source change
  was needed — but the shared code this plugin inlines is only rebuilt against
  the new substrate by a release, which is what this one is for.


## [0.8.22] - 2026-08-11

### Fixed

- **Rebuilt against substrate v2.23.0, whose SSE client fixes the resume.**
  The dashboard's stream inlines `SseInNode`, which used to depend on the
  browser's `Last-Event-ID` header — a header a freshly-constructed
  `EventSource` never sends, so every reconnect tail-seeked past whatever
  arrived while the tab was hidden. The client now owns its reconnect and
  carries its own `positions`.

## [0.8.21] - 2026-08-10

### Changed

- **The `commandClient` seam is gone.** `useInsightsGraph` and
  `PublisherInsights` no longer take it. Injecting a transport replaced the
  whole subsystem, so a hook test never ran HttpOut, pack/unpack, the router
  or the interpreter; the suites seam at `fetch` via the substrate's
  `installFakeCommandWire` instead. Requires the substrate release that
  removes the seam.

## [0.8.20] - 2026-08-10

### Added

- **`lint:types` and the dead-code phpstan layer**, matching the substrate and
  event-logger-nodes. The type-check found a real defect: `pasteHandler` widens
  to `string` for `mode:'INLINE'`, so `serialize()` was handed a type it cannot
  take. The dead-code detector needs the substrate really loaded rather than
  merely scanned, so `.phpstan/load-substrate.php` mirrors the ELN bootstrap.

### Changed

- **Dependencies updated within range** — phpstan 2.2.8, vipwpcs 3.1.0, phpunit
  10.5.64, esbuild, knip, babel presets.
- **The `@wordpress/*` packages now follow the `wp-7.0` dist tag** rather than
  npm `latest`: they are build externals mapped to `window.wp.*`, so core
  supplies the code at runtime. `react`/`react-dom` are pinned to the major
  WP 7.0 bundles.
- Plugin header now carries the same fields, in the same order, as its siblings.

## [0.8.19] - 2026-08-09

### Changed

- Shared tooling synced from newspack-nodes. `collapse_generics` rejected prose
  whose angle brackets happen to balance (`a<b, see c>d`) and let an unbalanced
  `<` earlier in a line hide a real generic from the gate; it validates each
  candidate span and backtracks now. `--fix` also gates instead of always
  exiting 0, and `lint-docs.sh` gains a rule keeping a documented substrate
  version floor in step with the loader that enforces it.

## [0.8.18] - 2026-08-09

### Changed

- Substrate pin moves to newspack-nodes v2.16.1 → v2.17.1.

- One spelling for a generic type in docblocks: `array<string,mixed>`, no space
  after the comma. Both parse identically; only that one highlights as a single
  type in the editor. `lint-comments.php` carries the rule now, so it holds.

## [0.8.17] - 2026-08-09

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.3 → v2.16.1**, five releases at
  once, so the bundled runtime and debug overlay stop being that far behind.
  What it carries: a log stream that resumes at EOF closes on the first tick
  instead of holding a worker for the whole idle window; the browser's SSE node
  is named `<link>:sse-in` so `trace` can reach it; a `set_state` transition is
  traced like the PHP twin's; the heartbeat's expected `slot_released` no
  longer counts as an error; a CONNECTED state no longer publishes the lease
  owner into the transcript; and "reset stats" clears the overlay's message
  list rather than leaving it on screen.

## [0.8.16] - 2026-08-07

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.3**, which stops an on-demand
  worker respawning itself at exit. A worker that both writes and tails a
  partition marked a wake for itself every run, then woke on its own writes
  once it had released its lock — so it cycled on an exact `on_demand_idle`
  period instead of scaling to zero.

## [0.8.15] - 2026-08-07

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.2**, which stops an on-demand
  worker being pinned awake by a Consumer tailing a log that has never been
  written. Any topology here that mounts a Consumer against a log a given
  install never produces was scaling to zero only until the first message woke
  it, then staying up indefinitely.

## [0.8.14] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.1**, a performance release on
  paths the digest pipeline sits on: `Router_Node::fill()` (~1427ns → ~1020ns
  per routed message) and `Message::packed()` (~754ns → ~593ns, on every
  partition write). Figures measured in the arm64 dev container, whose PHP 8.4
  build lacks the vectorized JSON escape scan present on x86 — read them as
  ratios, not absolutes.

## [0.8.13] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.0**, which adds the
  `wp nodes stop` / `wp nodes start` deploy hold. Digest runs are long-lived
  LLM calls, so this fleet is among the most exposed to a plugin update landing
  mid-job: swapping `includes/` under a live worker makes its autoloader fail
  on this plugin's own classes, and the in-flight record is quarantined as
  poison. Take the fleet down around the deploy:
  `wp nodes stop && ./deploy.sh && wp nodes start`.

## [0.8.12] - 2026-08-07

## [0.8.11] - 2026-08-07

### Fixed

- **Re-pinned to newspack-nodes 2.13.1** for the shared log-browser header
  alignment. `LogRowList` is inlined at build time, so 0.8.10 shipped the
  pre-fix copy.

## [0.8.10] - 2026-08-07

### Changed

- **Pinned resident with `var on_demand_idle = 0`.** newspack-nodes 2.13.0 lets
  a topology scale to zero when every reporter goes idle, and an operator can
  turn that on fleet-wide from config. This pipeline opts out explicitly rather
  than inheriting that: it holds LLM work in flight that no Consumer's EOF
  describes, so an idle window would be measuring the wrong thing.

### Changed

- **Blank-line runs are collapsed on commit.** `scripts/fix-blank-lines.php`
  joins the shared tooling and runs in `lint-staged` after the comment gate. It
  is token-aware: heredoc and string bodies keep their blank lines.

## [0.8.9] - 2026-08-06

### Changed

- **The shared comment gate is now `scripts/lint-comments.{php,mjs}`** (was
  `lint-comment-length`), because the PHP half no longer checks only length: at
  class-body level the only comment allowed is a docblock immediately preceding
  its declaration. Section headers, `//` notes where a docblock belongs, and
  docblocks whose method was deleted are all rejected. Comments inside a
  class-level initializer annotate their entry and stay exempt. Existing
  violations in this plugin are cleaned up here; no behavior changes.

## [0.8.8] - 2026-08-05

### Changed

- **Re-pinned to newspack-nodes v2.10.0.** No change of its own — the substrate
  turned every command-interpreter refusal into a TM_ERROR reply, and the shared
  runtime is inlined into this plugin's bundles at build time, so the old pin
  would keep shipping an interpreter that answers a refusal with a plain string.

## [0.8.7] - 2026-08-04

### Fixed

- **The insights poll declares its own cadence.** `useInsightsGraph` forwarded
  `opts.intervalMs` straight through, so a caller that omitted it — which
  `PublisherInsights` does unless its own prop is supplied — fell through to
  `useBatchedPoll`'s silent default of every router tick, i.e. 1Hz for a digest
  that changes on the order of minutes. It now defaults to 30s.

## [0.8.6] - 2026-08-04

### Changed

- **Substrate pinned to newspack-nodes v2.8.0**, up from v2.4.6 — four minor
  versions of shared code the bundle had been building against a stale tag.
- The newspaper-order method gate (`reorder-node-methods --check`) runs in
  lint-staged, matching the substrate.

### Fixed

- Followed the substrate's `Fetcher` field rename: the configured command name
  is `verb`, because the old spelling shadowed the inherited `Node#command()`
  minter and made `.command` a string on that one class.

## [0.8.5] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.6.** Dashboards stop polling while
  their tab is hidden — the router tick every poller hitchhikes is now gated on
  page visibility — and the SSE slot poke drops from every 5s to every 15s
  against the server's 60s lease TTL.

## [0.8.4] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.5.** A node whose schema declares
  `hidden` is now hidden on the topology console canvas, not just in the palette.

## [0.8.3] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.4.** Brings the Topic partition-dir
  declaration fix: a Topic that re-partitions beyond its topology's worker count
  now declares every directory it writes, so `Log_Cleaner` stops treating them as
  orphans and worker status shows them all.

## [0.8.2] - 2026-08-02

### Changed

- **Substrate pinned to newspack-nodes v2.4.3.** Brings the per-site SSE slot
  namespace — the pool keyed on `gethostname()`, which on Atomic is the shared
  pool host, so co-located sites collapsed onto a single 10-slot budget and the
  surplus was refused with a permanent HTTP 429. Also brings the staggered
  Remote_Link connects, the phased session request, and the topology console's
  auto-layout and stored-coordinate fixes.

## [0.8.1] - 2026-08-02

### Fixed

- **Rebuilt against substrate v2.4.2**, which keeps a `Request` node alive while
  any hook still holds it. `useRequestNode` is inlined into this bundle, so
  0.8.0 shipped the copy where one hook's unmount removed a node another still
  held. No source change here; the pin is the fix.

## [0.8.0] - 2026-08-02

### Changed

- **`CommandClient` is gone from the substrate**, folded into `HttpOut`. The
  `_http.client` seam is unchanged and duck-typed, so the dashboard's fake still
  works; production no longer constructs one.

- **`generate` and `collect` each mint from their own `Request` node.** They used
  to stamp an op-id into `message[ID]` and have `accumulated:view` settle the
  match — routing that had already happened, since a command is minted FROM the
  node that wants the answer and the server replies `TO = FROM`. Two nodes, two
  replies, nothing to tell apart. `accumulated:view` is a slice publisher again.

## [0.7.14] - 2026-08-02

### Added

- **`npm run lint:deadcode:js` — the JS half of the dead-code audit.** Mirrors
  the substrate's `knip.json`: `__tests__` out of `project`, knip's jest plugin
  off so a module reachable only from its own test reads as dead, and the
  `@newspack-nodes/*` aliases resolved through the sibling checkout. Opt-in, not
  in the push gate.

- **`.prettierrc.js`.** Every sibling plugin loads `@wordpress/prettier-config`
  through this file; this one had the dependency installed and nothing wiring it
  up, so `npm run format` ran on wp-prettier's defaults and fought eslint. Found
  by knip reporting the dependency as unused.

### Removed

- **`@xyflow/react` and `d3-flame-graph`.** Declared but imported nowhere in
  this plugin or the substrate. Found by knip.

### Changed

- **The build takes ONE substrate override, `NEWSPACK_NODES_SRC`.** It replaces
  four independent ones — `NEWSPACK_NODES_RUNTIME`, `_DEBUG_OVERLAY`, `_SHARED`,
  `_BUILD_KIT` — which all named paths inside the same directory. Every alias and
  the build-kit path now derive from that one base via the substrate's
  `build-kit/alias-map.js`, so a new alias needs no workflow change.

  That enumeration is what made `ERR_MODULE_NOT_FOUND` releases possible: omit
  any single variable and the build fell back to a nonexistent sibling path.
  Setting a retired name now fails immediately and names it, rather than being
  silently ignored — a stale override that does nothing is how a release builds
  against the wrong checkout and still goes green. `release.yml` updated to match.

  Build output is byte-identical, verified by rebuilding with only `build.mjs`
  varying.

## [0.7.13] - 2026-07-31

### Security

- **Dev-dependency advisories cleared where a fix exists.** `js-yaml` to 4.3.1
  and `brace-expansion` to 1.1.18 / 2.1.4, closing GHSA-52cp-r559-cp3m and
  GHSA-3jxr-9vmj-r5cp. All are development scope — `build-release.sh` excludes
  `node_modules` and PHP installs `--no-dev`, so none of this ships.

  **GHSA-mh99-v99m-4gvg (brace-expansion) is deliberately left open.** It covers
  every version `<= 5.0.7`, so the fix is 5.0.8 — and 5.0.8 changed the CommonJS
  export from a bare function to `{ EXPANSION_MAX, EXPANSION_MAX_LENGTH, expand }`,
  which every `minimatch` below 10 calls as a function. An override to 5.0.8 or
  5.0.9 breaks eslint with `TypeError: expand is not a function`. `npm audit fix
  --force` clears it only by taking eslint 10 AND downgrading jest 30 to 25 and
  babel-jest 30 to 23. The residual risk is a DoS reachable only by feeding a
  hostile glob to our own build tooling, which a hostile PR could not do without
  already being able to run code in CI.

### Fixed

- **`Source_Node`'s docblock promised a durability guarantee that does not exist.**
  It claimed the emitted-id set round-trips through `save_state`/`restore_state`;
  neither method is defined on that class, and `git log -S` shows neither ever was.
  The set is in-memory and bounded, so a respawned worker re-emits whatever its
  next fetch still returns. `Digest_Builder_Node` dedups on the same id, so the
  digest stays correct — but the summarize and score stages sit upstream of that
  dedup and pay for the repeat. A TICK-driven source has no Consumer offsetlog to
  co-commit a snapshot into, which is why `add_snapshot_node` is unavailable here.

## [0.7.12] - 2026-07-31

### Changed

- **`lint-docs.sh` is a shared pre-push gate.** The doc-drift lint was
  substrate-only; it now ships to every plugin via `sync-shared-scripts.sh` and
  runs from each `pre-push`. It caught three `make_node` examples in
  event-logger-nodes documenting a retention arg list the shipped topology never
  passes.


## [0.7.11] - 2026-07-31

### Changed

- **The vendored `reorder-node-methods` tooling now passes the comment-length
  gate.** Function-level prose moved into docblocks, inline prose condensed to
  one line; four algorithm notes that genuinely need the length carry
  `@longform`. No behavior change — the tool's own test still passes 38/38.


## [0.7.10] - 2026-07-31

### Added

- **Vendored copies of the substrate's shared tooling** (`scripts/bump-version.sh`
  + `scripts/lib/`, `reorder-node-methods`, the coverage and comment-length
  gates, `pre-commit`, `commit-msg`), so a standalone clone works without a
  sibling checkout. `scripts/sync-shared-scripts.sh` refreshes them from
  `../newspack-nodes/scripts/` on each `pre-commit` when that sibling exists —
  edit shared scripts THERE, not here.
- **`scripts/commit-msg`** — the conventional-commit gate, now a tracked hook.
  It skips cleanly where commitlint isn't installed.

### Changed

- **Git hooks come from `core.hooksPath`, not `.git/hooks`.** `composer install`
  now points git at `scripts/`, so the hooks are version controlled and reviewed
  with the code they gate. A clone that has never run `composer install` has no
  hooks at all.
- `scripts/bump-version.sh` replaces dndocker's `tools/bump-intelligence-version.sh`.
  Behavior is unchanged; the shared flow lives in `scripts/lib/bump-version.sh`
  and the wrapper is only the per-plugin knobs.

### Removed

- `brainmaestro/composer-git-hooks` — `core.hooksPath` does the job with no
  dependency, and cghooks-installed `.git/hooks` files are now dead files git
  ignores.


## [0.7.9] - 2026-07-31

### Fixed

- **A dev build and a CI build now emit the same bytes.** Shared substrate
  source importing a bare dependency (`d3`, `@noble/hashes`) resolved it from
  the substrate's own tree first. CI checks the substrate out without
  `node_modules`, so resolution fell through to this plugin's copy; a developer
  checkout HAS `node_modules`, so esbuild bundled a second copy under a
  different absolute path. Every
  dependency this plugin owns is now pinned to its own copy. Verified by
  building against a substrate checkout carrying `node_modules` and diffing the
  result against the published release: byte-identical.


## [0.7.8] - 2026-07-30

### Fixed

- **Bundles newspack-nodes v2.2.10.** The release workflow still pinned the
  v2.2.9 substrate, so v0.7.7 shipped the pre-rewrite graph stylesheet — the
  very styles that release set out to load.

## [0.7.7] - 2026-07-30

### Changed

- **The embedded Publisher Insights graph/debug overlay declares its stylesheet
  dependencies.** The dashboard asset now loads `wp-components` and
  `newspack-nodes-graph` for that substrate surface.

## [0.7.6] - 2026-07-29

### Changed
- **Bundles newspack-nodes v2.2.9.** Dashboard first polls now survive
  navigation-time authentication and visibility races instead of remaining
  stalled until a later reload or focus transition.
- Updated the release workflow to checkout and Node setup Actions v7.

## [0.7.5] - 2026-07-28

### Changed
- **Release builds against newspack-nodes 2.2.5.** The inlined runtime carries
  the canonical seven-check Site Health/doctor report, APCu-capable command
  sessions, and base-dir-independent diagnostics while preserving full
  attached-console access to normal workers.

## [0.7.4] - 2026-07-28

### Changed
- **Release builds against newspack-nodes 2.2.4.** The inlined runtime now uses
  ownership-fenced SSE slot leases and reports the endpoint's disconnect reason,
  preventing a stale connection from releasing a successor's slot while making
  future unexplained stream endings distinguishable from lease loss.

## [0.7.3] - 2026-07-27

### Changed
- **Release builds against newspack-nodes 2.2.3.** The pin sat at 2.0.0, which
  predates the `secure` ratchet the topologies below now declare, and the shell
  and command-session work in 2.1–2.2 that the inlined runtime carries.

### Security
- **Every topology declares `secure`.** The ratchet (newspack-nodes 2.1.0) drops
  a class of verb per level; level 1 removes `make_node`, so a wire-arrived
  command can no longer add nodes to a running graph. It sits on the LAST line
  of each file because it disables the very verb the rest of the file uses —
  declared any earlier it would refuse the graph it exists to protect. Each
  sub-topology carries its own, since `include` strips the declaration from an
  included file and the four are separately activatable.

## [0.7.2] - 2026-07-26

### Changed
- **Release builds against newspack-nodes 2.0.0**, and declares
  `@noble/hashes`. The workflow pinned an older substrate tag, so the archive
  compiled against a runtime predating the command-session exports; and the
  substrate runtime this plugin inlines imports `@noble/hashes` for its
  synchronous HMAC, which resolved locally only by walking up into the
  substrate's own `node_modules`. CI checks the substrate out without its
  dependencies, so it has to be declared here.

### Fixed
- **`useInsightsGraph` mints through `Node.command()`.** The hand-built builder
  called `markLocal()`, which marks LOCAL but declines to sign with no session.
  Minting through the accumulated view gates on the session instead.
- **`useInsightsGraph` actions hold for the session.** A click during the
  `/auth` round trip minted UNSIGNED and was refused; the action now fires on
  `ensureSession()`, guarded against a teardown mid-round-trip. Requires the
  matching newspack-nodes change.

### Changed
- **`useInsightsGraph`'s command mint completes.** It built commands without
  marking them, so every first poll shipped unsigned and was refused. The poller
  retries, so it self-healed once the session landed — noisy, not broken.
- **Test harness authenticates, polyfills `TextEncoder`, and transforms
  `@noble/hashes`.** The substrate's emitters hold until a command session
  exists, and its signer is ESM-only and needs `TextEncoder`, which jsdom lacks.
  This plugin inlines that runtime and needed all three. Requires the matching
  newspack-nodes change.

## [0.7.1] - 2026-07-24

### Changed
- Dropped `ReflectionProperty`/`ReflectionMethod::setAccessible()` calls from
  the test suite — deprecated in PHP 8.5, a no-op since PHP 8.1.

## [0.7.0] - 2026-07-24

### Added
- Substrate version handshake at boot: on a substrate older than 0.54.0 the
  plugin goes dormant with an admin notice (via the substrate's
  `Bootstrap::version_at_least()`) instead of fataling on a missing API.
  A substrate predating the handshake API (nodes < 0.54.0) also parks the
  plugin — the stack deploys as a unit, so a missing API means too old.

### Changed
- The release workflow pins the newspack-nodes checkout to the `v0.54.0` tag
  instead of tracking `main` — bump the pin when adopting a newer substrate.

## [0.6.0] - 2026-07-24

### Changed
- Track the substrate's retention rename: `newspack-intelligence-ingest.tsl` and
  `newspack-intelligence-summary.tsl` swap the durable Partition tails from
  `<config:max_segments>` / `<config:max_lifetime>` to `<config:num_segments>` /
  `<config:lifetime>` (count target and age rule under their new names). Test
  config renames `max_segments`→`num_segments` and `max_lifetime`→`lifetime`.

## [0.5.3] - 2026-07-23

**Pairs with newspack-nodes ≥ 0.51.0** (the `add_snapshot_node` verb and the
name-keyed snapshot frame).

### Changed
- `newspack-intelligence-digest.tsl` migrates `set_snapshot_node` (deleted
  upstream) to `add_snapshot_node`; `Insights_CI_Node` reads the digest state
  from the frame's name-keyed `cache` map (`cache.digest`), including the
  `read_latest_snapshot_cache( …, 'digest' )` node argument.

## [0.5.2] - 2026-07-23

### Fixed
- **`add_profile` takes the whole line.** The verb dispatch was a stale port
  from the token-array migration reading only `$args[0]`, so an unquoted
  multi-word profile stored just its first word (`Don't produce tables.` →
  `Do`). All positional tokens now join with spaces, like `echo`.
- **`dump_config` round-trips.** LLM-config verb args emit through
  `Node::serialize_args()` (multi-word profiles come back single-quoted as
  one token), and an explicitly-set value dumps even when it equals the
  trait default — a pinned `set_model gpt-oss-120b` survives a future
  default bump instead of silently vanishing from the dump.
- **Topology profile lines are properly quoted** (double quotes, so
  apostrophes survive the tokenizer and `<…>` still interpolates).

## [0.5.1] - 2026-07-21

### Fixed

- **`Proxy_LLM_Client::chat()` no longer silently POSTs an empty body to the AI proxy.** Ingested GitHub/Linear/feed content isn't guaranteed clean UTF-8; when `wp_json_encode()` on the chat-completions body failed, the bare `(string)` cast around it silently coerced the encode failure to `''`, sending an empty request body instead of a diagnosable error. `chat()` now throws a `RuntimeException` naming `json_last_error_msg()` before sending anything (mirrors the substrate `newspack-nodes` fix in `Message::packed()` for the same swallow-into-empty-string shape).

## [0.5.0] - 2026-07-17

### Added

- Add `Publisher_Repository::all_with_enrichment()`, exposing each publisher's matchable fields (domain, publisher name, aliases, status) for the intake Gate.
- Add `Publisher_Matcher`, the intake Gate's deterministic hard-match layer: resolves a normalized item to a publisher by URL domain then exact name/alias (whole-word), emitting a `pass`/`hold`/`bypass` decision record. GitHub/Linear items bypass the Gate; deterministic misses `hold` (recall-biased, pending the LLM NER layer).
- Add `Prompts::extract_entities()` and `LLM_Entity_Extractor` (behind an `Entity_Extractor` seam) — the intake Gate's cheap-NER step: extract an item's subject organizations/people/locations as JSON, with a lenient parse that degrades to an empty result on any model/transport failure.
- `Publisher_Matcher`: on a deterministic miss, optionally run NER + fuzzy string-similarity match against the publisher store, banded by confidence into `pass` (≥0.85), `hold` (0.60–0.85 or ambiguous), or `ignore` (<0.60). The decision record now carries a `confidence` field. With no extractor injected, behavior is unchanged (miss ⇒ `hold`).
- Add `Gate_Node`, a Transform node wrapping `Publisher_Matcher`: runs the Gate on each item and emits a decision record (stamped with a persist-time `ts`). Uses the `LLM_Config` verbs for the optional NER extractor (no token ⇒ deterministic-only) plus a `set_config_version` verb; builds its matcher once so the publisher-set memoization spans a collect.
- Wire the intake Gate into the pipeline as an **observer** stage (`newspack-intelligence-gate`): a second consumer (`gate:consumer`) tails the same `ingest` partition with its own offsets, feeding `gate → gate:tojson → gate:log`, which appends every decision as one JSON line to `gate-decisions.jsonl` (the append-only decision-log backbone). Purely additive — the summary/digest stages are unchanged; moving the Gate inline to filter the digest is deferred.

### Changed

- **Renamed the plugin to Newspack Intelligence.** Slug `newspack-ai-newsletter` → `newspack-intelligence`; PHP namespace `Newspack_AI_Newsletter` → `Newspack_Intelligence`; constants (`NEWSPACK_INTELLIGENCE_*`), text domain, admin-menu slugs, WP option keys (`newspack_intelligence_*`), WP-CLI root (`wp newspack-intelligence clients`), AI-proxy feature (`X-WPCOM-AI-Feature: newspack-intelligence`), and the digest path (`/tmp/newspack-intelligence/digest.md`) all move. The GitHub repo and plugin directory are renamed to match. Clean break — no migration of old options/paths (local-only environment). The pipeline topologies were already `newspack-intelligence`; this finishes the rebrand of the plugin identity.

## [0.4.1] - 2026-07-16

### Fixed

- **A malformed clients CSV can no longer churn every publisher.** `CSV_Parser::parse()` now returns `null` (instead of `[]`) when the first non-blank line isn't the expected "Atomic…" header, or when zero valid data rows result; `Client_Importer::import()` refuses to reconcile an empty snapshot. Previously a readable-but-malformed file produced an empty snapshot whose reconciliation marked EVERY existing client churned.
- **The Publisher Insights dashboard emits the token-array command form.** `useInsightsGraph` action verbs (`generate` / `collect`) now send `arguments: []` instead of the retired joined-string `''`.

### Changed

- **Split the pipeline topology into `newspack-intelligence` and deleted the `newspack-ai-newsletter` monolith.** The one-file `newspack-ai-newsletter.tsl` is replaced by `newspack-intelligence.tsl`, which `include`s three composable stages (`newspack-intelligence-ingest`, `-summary`, `-digest`) that build the identical node graph. The worker topology identifier — and therefore the worker-id/lock-dir prefix `Insights_CI` globs — is now `newspack-intelligence` (was `newspack-ai-newsletter`). The plugin slug, plugin file, namespace, text domain, admin-menu slugs, AI-proxy feature name, and the `/tmp/newspack-ai-newsletter/` digest path are unchanged; only the topology name moved.

### Removed

- Removed the dead `Insights_CI_Node::read_snapshot_items()` — the parallel snapshot-read path retired when Regenerate moved to the worker; production reads through `read_snapshot()`.
- **Removed the never-loaded `newspack-ai-newsletter-config.php` and its scaffold-era guards.** The plugin has no app `Config` class, so nothing loaded the bundled file; it runs on the `newspack-nodes` substrate config + option overlay (its retention values were identical to the substrate defaults, and the digest path is the hardcoded `Digest_Builder_Node::DIGEST_PATH`). Also dropped the redundant `is_dir( build/dashboard )` enqueue guard (the substrate's `Admin::enqueue_react_page()` already no-ops when `index.js` is absent), the build.mjs "entry may not exist yet" filter (the dashboard now always ships), and its stale `phpcs.xml.dist` scan entry. Behavior-neutral.

## [0.4.0] - 2026-07-16

### Changed

- **Migrated to the newspack-nodes token-array command contract.** TM_COMMAND `arguments` and node-constructor `arguments` are now a token array (`list<string>` argv) rather than a joined string, matching the substrate change. The source-node config verbs (`add_url`, `add_repo`, `set_vault_id`, `set_api_url`, `set_model`, `set_feature`, `add_profile`), `Digest_Builder_Node::arguments()`, the `Insights_CI` verbs, and the Publisher Insights dashboard read and produce token arrays; `Insights_CI` spreads the substrate IPC-partition argument tokens into `make_node`.

## [0.3.2] - 2026-07-15

### Fixed

- **The publisher CPT no longer rewrites every `manage_options` check into a bare `delete_post` check.** Its fully explicit admin-only capability map assigned `manage_options` to all three singular post capabilities while also enabling `map_meta_cap`; WordPress consequently registered the global reverse mapping `manage_options => delete_post`, so ordinary admin-page permission checks emitted the “specific post” notice. Disabling post-type meta-cap registration keeps every publisher operation gated directly by `manage_options` without poisoning unrelated capability checks.

## [0.3.1] - 2026-07-15


### Fixed

- **The publisher CPT/meta-box hooks no longer fatal the site when the substrate is briefly inactive.** `init`/`add_meta_boxes`/`save_post` were wired to `Publisher_CPT`/`Publisher_Meta_Box` class-string callables at plugin-file scope, but those classes only autoload after the substrate-gated bootstrap requires the Composer autoloader. Whenever `newspack-nodes` was momentarily deactivated (e.g. a nodes redeploy), the gate short-circuited, the autoloader never loaded, and the still-registered `init` callback raised `class "…\Publisher_CPT" not found` — a site-wide critical error. The three registrations now sit inside the gated bootstrap closure (like the Clients upload hooks), so they only wire when the plugin's own classes are loadable.

## [0.3.0] - 2026-07-15

### Added

- Add CSV_Parser for the Newspack clients list.
- Add Publisher_Repository contract and Client_Importer reconciliation.
- Register the newspack_publisher master-data CPT.
- Add CPT-backed Publisher_Repository implementation.
- Add `wp newspack-ai-newsletter clients import` WP-CLI command.
- Add Settings-page CSV upload for the publisher master store.
- Add `CSV_Parser::parse_file()`, the single owner of the CSV file-read (readability guard + `file_get_contents` + parse), used by both the CLI command and the Settings handler.
- Render an `admin_notices` success notice ("Newspack clients imported.") after a completed Settings-page CSV import.
- Add `Publisher_Meta_Box`, the "Publisher details" admin meta box on `newspack_publisher`: editable enrichment fields (publisher name, localities, GitHub org, LinkedIn company ID, X handle, aliases, beat tags) plus a read-only provenance section for the import-managed fields (atomic site ID, domain, created, status, first/last seen, churned at).

### Changed

- De-duplicated the CSV file-read between the WP-CLI command and the Settings handler by routing both through `CSV_Parser::parse_file()`; `Client_Importer` remains pure (no file I/O).
- Documented the `Publisher_Repository` interface contract (each method now states its effect, e.g. `set_active()` clears `churned_at`, `create()` seeds `first_seen`/`last_seen`/`churned_at`).

### Fixed

- Register the publisher-CSV `admin_post` handler and `admin_notices` inside the `plugins_loaded` bootstrap closure (after the composer autoloader is required) instead of at plugin-file scope. Referencing `Clients_Settings::ADMIN_POST_ACTION` at file-load time fataled with `Class "Newspack_AI_Newsletter\Clients_Settings" not found` on activation, before the autoloader was set up.
- `Client_Importer::import()` no longer double-counts a reactivated publisher in both `updated` and `reactivated`; the counts are now disjoint (a churned row that returns is counted only as `reactivated`).
- The Settings-page CSV import's redirect fallback now points at the plugin's own Settings page (`options-general.php?page=newspack-ai-newsletter-settings`) instead of the generic admin dashboard.
- Restrict the `newspack_publisher` CPT to `manage_options`: an explicit `capabilities` map now gates list/edit/delete/create, so roles with only `edit_posts` (Editors/Authors) can no longer view or modify publisher records via `capability_type => 'post'` defaults.
- `CPT_Publisher_Repository::update_atomic_fields()` now syncs `post_title` to the new domain when a publisher's domain changes on re-import (previously only the `_npainl_domain_name` meta updated, leaving the admin list showing the stale domain); the title write is skipped when the domain is unchanged.
- **The PHPUnit bootstrap no longer reflects the removed Nodes config allowlist.** Operator-selected config files are validated directly by the substrate, so the obsolete test-only widening seam now stays out of the consumer harness and the suite boots against the current Nodes API.
- **The PHPUnit `add_action`/`add_filter` doubles accept lazy class-string callables, matching WordPress.** The stubs type-hinted `callable`, which rejects a `[ 'Class', 'method' ]` array for a class Composer autoloads after plugin-file scope — exactly the publisher CPT/meta-box registrations at plugin-file scope. That fataled the whole test bootstrap (`TypeError: add_action(): Argument #2 must be of type callable`). The doubles now mirror WordPress's untyped `$callback` and resolve it lazily at `do_action` time.

## [0.2.13] - 2026-07-14

### Fixed

- **`ingest:consumer` and `scored:consumer` now declare a dead-letter dir.** Without it the substrate disables the DLQ, so a poison item was logged and dropped rather than quarantined.

- **The insights IPC partition inherited a retention config that stopped it pruning.** It built its Partition from `IPC_SEGMENT_SIZE` + a bare segment count, leaving `min_lifetime` to fall back to `<config:min_lifetime>` (an hour) — which protects every freshly-written segment from the count rule, so the scratch dir grew without bound. It now uses the substrate's `Worker_Base::ipc_partition_args()`, which declares all four retention axes, so the geometry can't drift from the substrate's again.

## [0.2.12] - 2026-07-13

### Added

- **`NodeSchemaArgumentDescriptionsTest` gate** — fails if any node's `node_schema` constructor argument lacks a `description` (the tooltip the topology console shows). `Digest_Builder`'s args already carry descriptions; `Scorer`/`Summarizer` take none. Guards future args.

### Changed

- **Topology + config migrated to the substrate's four-knob retention split** (`newspack-nodes` `min_segments` / `max_segments` / `min_lifetime` / `max_lifetime`, replacing `num_segments` / `max_lifespan`). The `ingest`/`scored` `Partition` make_node lines now pass `<config:segment_size> <config:min_segments> <config:max_segments> <config:min_lifetime> <config:max_lifetime>`, and `digest:log`'s `Log` line inserts `min_segments=2` (`… digest.md 1 2 7`). Behavior-preserving mapping: old retained-count → `max_segments` (`min_segments` at the hard floor 2), old min-age → `min_lifetime`, `max_lifetime` 0. Without this the old count landed in `max_segments`'s slot, disabling count-pruning (unbounded disk growth). `newspack-ai-newsletter-config.php` (and the test config) split their keys to match. No stored-option migration is needed — these keys are config-file-only.
- **Publisher Insights newsletter-card buttons use stock WordPress admin `.button` classes** instead of the bespoke `eai-insights__btn` re-theme. Collect / Regenerate digest are `button button-primary`; Copy markdown / Create draft post are `button`. The `&__btn` (+ `&__btn--secondary`) SCSS appearance block and the now-orphaned `$cobalt-hover` token are deleted; only the `&__actions` layout rules remain.

### Fixed

- **`print_less_often()` call sites split throttle-key from varying payload (consumes the substrate's variadic port).** The four fire-and-forget connector/compose warnings that concatenated a per-call error message into the throttled string — `GitHub fetch failed`, `Linear fetch failed`, `Feed fetch failed`, and `AI digest compose failed` — now pass the stable category prefix as the throttle key with the `WP_Error` / exception message as a trailing `...$extra` payload arg. Previously the varying error text minted a fresh key every call, so a flapping source never throttled (it logged worst exactly when failing most); now one line per category per window. Emitted text is byte-for-byte unchanged.

## [0.2.11] - 2026-07-10

**Requires newspack-nodes ≥ 0.34.0** (the release carrying the `Core` coercion-helper family).

### Changed

- **Coercions fold onto the substrate's new `\Newspack_Nodes\Core` helper family** — `Insights_CI_Node`'s `to_float`/`int_of` onto the strict `num_float()`/`num_int()` (an untrusted JSON score that isn't numeric contributes exactly 0), and the `is_string`/`is_array`/`is_scalar` read idioms in the source nodes, summarizer, digest composer, and prompts onto `str()`/`arr()`/`as_string()` — same semantics, defined once in the substrate this plugin already depends on.

## [0.2.10] - 2026-07-09

### Changed

- **The `DONE` completion sentinel now carries a trailing newline (`"DONE\n"`), so it flushes through a `Log` → `Tail` pipeline immediately.** Written to a `Log` (a line-buffered `Partition`) and read back over `Tail`, a `'DONE'` value with no terminator stalls as an incomplete final line — it only flushes once a later newline-bearing write arrives, so completion could hang indefinitely. Source nodes now emit `TM_INFO "DONE\n"` and `Digest_Builder_Node` matches it in lockstep.

## [0.2.9] - 2026-07-07

### Changed

- **`fill()` takes the message by value** (`array $message`, was `array &$message`), propagating the newspack-nodes substrate contract: each Node subclass owns the message it is given and forwards a value to its sink. Requires newspack-nodes ≥ 0.29.0.

## [0.2.8] - 2026-07-07

### Security

- **Direct-access guard on the first-party PHP files that lacked it.** Added `\defined( 'ABSPATH' ) || exit;` so no plugin PHP file runs on a direct web hit. (`uninstall.php` keeps its stricter `WP_UNINSTALL_PLUGIN` guard.)

### Changed

- **BREAKING: configuration moved from the Settings page into the topology.** The **Settings → AI Newsletter** page is removed; the five pipeline nodes now self-configure via topology `:config` verbs (set in the nodes console or the `.tsl`) instead of the global option store. GitHub/Linear/Summarizer/Digest take `set_vault_id` (a Vault-entry dropdown in the console); GitHub takes `add_repo`, Feed takes `add_url`, Summarizer/Digest take `add_profile` (+ optional `set_api_url`/`set_model`/`set_feature`, defaulting to the AI proxy / `gpt-oss-120b` / `newspack-ai-newsletter`). **Re-enter your connector repos/feeds, vault ids, and digest profile as node verbs** — existing `newspack_ai_newsletter_*` options are no longer read (they remain inert in the DB and are cleared on uninstall). The digest path constant moved from `Settings::DIGEST_PATH` to `Digest_Builder_Node::DIGEST_PATH`.

## [0.2.7] - 2026-07-02

### Added

- **Uninstall cleanup.** Deleting the plugin now removes every `newspack_ai_newsletter_` option row it created (settings + runtime state) and their transient variants, via a prefix-based `uninstall.php`. It runs only on delete (`WP_UNINSTALL_PLUGIN`), never on deactivate, so a deactivate/reactivate keeps all settings; previously these options were orphaned in the database on uninstall. Prefix-based so it stays complete as options come and go and catches `autoload=off` rows a hardcoded list would miss.

## [0.2.6] - 2026-06-30

### Fixed

- Stopped `esc_html()` over-escaping of `Proxy_LLM_Client` error messages. The thrown text is plain text for its log/CLI consumers (and React escapes on render), so runtime escaping only mangled quotes/markup in the logs. Escaping belongs to the view, not the runtime.

## [0.2.5] - 2026-06-29

### Fixed

- Restored the two-column Publisher Insights dashboard layout. The "de-god the dashboard" refactor (splitting the monolith into the `SourceCounts` / `TopTable` / `AccumulatedPanel` widgets) replaced the `eai-insights__layout` grid wrapper with a flat `eai-insights__grid` div that has no CSS rule, so all three cards stacked in a single column. The orchestrator now restores the original grid: the accumulated digest and source counts stack in the left column, the tall per-source Top-items table takes the right. No styling changed — the existing `__layout`/`__side` rules were already in `insights.scss`.

## [0.2.4] - 2026-06-29

### Changed

- **Rebuilt against newspack-nodes 0.24.1**, refreshing the inlined `@newspack-nodes/runtime` + `debug-overlay`: the bundled debug-overlay/console no-node stats header now reads wire-accurate IoTelemetry for browser graphs and no longer spikes its rate sparklines to the cumulative total on a fresh load / shift-reload / worker-switch; `dump_config` takes an optional regex-glob name filter; and the `HttpOut` bytesRead / `RemoteLink` write-byte tallies are corrected.

## [0.2.3] - 2026-06-28

### Changed

- Rebuilt against newspack-nodes 0.22.1: `_http`/`_heartbeat` are now permanent backbone fixtures of `mountExospine` (survive Reset Graph), plus the overlay shell-special/local command dispatch, the accumulating reset-chip `reinitNames`, and the JS class-catalog `arguments` fixes. No ai-newsletter code changes; the dashboards inline the updated substrate JS.

## [0.2.2] - 2026-06-27

### Changed

- Updated the three dashboard view nodes' import path for the shared `SliceViewNode` base to its renamed kebab file (`@newspack-nodes/shared/nodes/slice-view-node`). No behavior change. Rebuilds against newspack-nodes 0.22.0's shared modules.

## [0.2.1] - 2026-06-27

### Changed

- De-godded the Publisher Insights dashboard. The server `Insights_CI_Node` `insights` god verb is replaced by three slice verbs (`counts`/`top`/`accumulated`) built via `Service_CI_Node::slice_verb()` over one memoized scored-snapshot read; the browser graph is now `useBatchedPoll` + three `SliceViewNode` view nodes (`source-counts:view`/`top-table:view`/`accumulated:view`) fed by per-slice Fetchers (one batched POST per tick), and `PublisherInsights` is split into per-slice widgets (`SourceCounts`/`TopTable`/`AccumulatedPanel`) each reading its own view. `generate`/`collect` worker-routing verbs, the rendered digest, collection progress, and per-source top-10 are preserved; the debug overlay stays mounted.

### Removed

- The god `insights:view` view node (`nodes/insightsView.js`) and the single-view-model `useInsightsGraph` path.

### Fixed

- Publisher Insights debug overlay: the page now declares itself on the substrate's `newspack_nodes/devtools_overlay_pages` registry, so ELN's "Request" overlay tab loads here (previously the overlay showed only Overview + Console). Harmless no-op when newspack-nodes / the event logger aren't active.

## [0.2.0] - 2026-06-23

### Added

- Publisher Insights dashboard: the substrate debug overlay now mounts on the page (debug-gated, storage key `newspack-nodes:debug:publisher-insights`), so the `insights:view` browser node graph is inspectable like every other dashboard.

### Changed

- **Credential settings now reference a substrate Vault entry instead of storing a raw secret.** The three credential fields (`ai_proxy_token`, `github_token`, `linear_token`) store a `\Newspack_Nodes\Vault` entry ID, chosen from a `<select>` of vault entries, and the real secret is resolved at use-time via the new `Settings::get_secret()` (reads the entry's `auth_password`). Consumers (`Github_Source_Node`, `Linear_Source_Node`, `Settings::llm_client()`) now resolve through `get_secret()`. The Vault holds the encrypted secret; the plugin's own options no longer do. Falls back gracefully (empty secret, None-only dropdown) when newspack-nodes' Vault class is unavailable.

### Fixed

- Publisher Insights dashboard: the page title now uses the standard WordPress admin heading size (23px / 400) instead of an oversized 32px heading, so it matches the rest of wp-admin.

## [0.1.0] - 2026-06-17

### Changed

- **Regenerate digest delegates to the worker instead of composing in the request graph.** Insights_CI's `generate` verb no longer reads the snapshot and composes itself; it routes a single `TM_REQUEST REGENERATE` to the worker's `digest` node over the input IPC partition (`{base}/ipc/newspack-ai-newsletter.p0/input` — the same request-graph→worker transport `collect` uses) and returns an ack. The worker's `Digest_Builder` (which handles `REGENERATE` on `TM_REQUEST`) composes from its live in-memory items and writes `digest:log`; the dashboard's poll surfaces the new draft. Removes the parallel request-graph compose path (`generate_json` / `read_snapshot_items`), so there's one digest writer. The dashboard's "Regenerate digest" button now shows a "Regenerating…" ack (or a no-worker error) and lets the poll bring in the result, mirroring Collect.

- **The collect pipeline runs through a durable `ingest` partition so the worker keeps heartbeating during a collect.** Sources append fetched items to a new `ingest` partition; an `ingest:consumer` (in `line_mode`) paces them one read-block per drain into Summarizer → Scorer → `scored`. Previously a `TICK` ran the fetch plus every item's blocking LLM enrich in one synchronous pass, freezing the worker's heartbeat (it went `[stale]` mid-collect); the buffer + paced consumer spread that work across drain cycles. Requires newspack-nodes with the Consumer `line_mode` verb.

- **Collect is reachable everywhere and clearly gated.** The Collect button now renders in the empty state too (you need it most when nothing's scored yet), shows `Collected X/3` (0 immediately on click), and is enabled only at a clean boundary — empty (`0`) or complete (`done >= total`) — so it can't double-fire mid-collection; an optimistic in-flight lock self-releases when the poll reflects the new cycle or after a timeout (so a no-op collection can't latch the button). A success ack ("Collecting from N worker(s)…") or a no-worker error now shows in both the empty and populated states.

- **The dashboard's top items are per-source (top 10 each), not one global list.** `Insights_CI::top_by_source` groups the scored items into a per-source top-10; the dashboard renders a ranked table per source (github / linear / feed) instead of one list a single high-scoring source dominated. The model's `top` is now keyed by source (`{ source: [{title, score}] }`).

- **The dashboard is a two-column layout.** The left column holds the KPI stat cards, "By source", and the "Newsletter" actions; the right column holds "Top items by source" as a single stacked column of per-source tables. Widens to use the wp-admin content area. The digest action button is now **Regenerate digest** (the durable digest composes on its own; this recomposes on demand), and the empty preview reads simply "No digest yet." The draft preview is taller (720px) so more of the markdown is visible without scrolling.

- **The digest covers every accumulated item, not just the top 10.** `Digest_Composer` previously sent only the top-10-by-score items into the LLM prompt, so the draft summarized 10 of (e.g.) 30 collected items while the no-LLM fallback already listed them all. It now ranks the whole set by score and sends all of it, with a larger output-token budget to fit the bigger briefing. One cycle's items are bounded (the builder resets per cycle).

- **The AI Newsletter settings page moved to the WordPress "Settings" menu.** It was a submenu under the Publisher Insights dashboard; it now registers under `options-general.php` as "Settings → AI Newsletter". Publisher Insights stays its own top-level dashboard.

- **The Summarizer drops each item's `body` after summarizing it.** The `body` (release notes / PR descriptions / feed content) feeds the summary, but nothing past the Summarizer reads it (the Scorer uses `relevance_score`/`source`/`timestamp`; the digest and dashboard use `summary`/`title`/`score`/`url`). Stripping it there shrinks every downstream message plus the durable `scored` log and the digest snapshot — which previously carried a full body per accumulated item.

### Added

- **Collect button + live collection progress.** The dashboard's Newsletter section gains a **Collect** button that drives a full collection cycle: it sends a `collect` command to `Insights_CI`, which (since the sources live in the worker, not the request graph) writes a RESET then a TICK to each source into the worker's input IPC partition — the same transport `wp nodes cli` uses. Each `Source_Node`, after its fetch, emits a `TM_INFO DONE` (always — even if the fetch throws); Summarizer and Scorer now forward `TM_INFO` so the DONE flows down to the `Digest_Builder`, which counts **distinct sources reported** into its snapshot as `done`/`total`. The dashboard shows "Collected X/total" and gates the buttons accordingly: **Regenerate digest** only enables once every source has reported (`done >= total`), and **Copy markdown** / **Create draft post** only once a digest exists. `collect` replies in JSON (`{collecting,workers}` or `{error}`) so the dashboard surfaces failures instead of guessing.

### Fixed

- **The digest is balanced per source — no source gets crowded out.** `Digest_Composer` previously sent every accumulated item ranked by raw score, so a high-volume source (github) dominated the briefing while linear/feed barely appeared (or didn't). It now selects the top 10 PER SOURCE, so every source is represented in the digest regardless of volume.
- **The Collect button stays disabled for the whole collection cycle.** The optimistic lock released on the first poll tick (or a short timeout), so at `done=0` the button became clickable again mid-collection. The lock now holds until THIS cycle actually completes (a `complete` reading after it's been observed in progress), with a long safety timeout, so you can't re-fire Collect while it's still running.
- **"Create draft post" now uses the block editor's OWN markdown-paste engine.** The hand-rolled `markdownToBlocks` converter is gone, replaced by `markdownToBlockMarkup` which runs the editor's `registerCoreBlocks` + `pasteHandler` (empty HTML + plainText markdown → blocks) and serializes the result — the exact path the editor takes when you paste markdown into it. So a created draft matches "Copy markdown → paste" byte-for-byte, including GFM tables → `core/table` (the custom converter mangled them). Needs the `@wordpress/blocks` + `@wordpress/block-library` runtime scripts (now enqueued via the build kit's externals).
- **The dashboard's status notes no longer linger forever.** The "Collecting from N worker(s)…" and "Regenerating… the draft updates on the next poll." acks had no reliable clear path (the collect lock's timeout released the lock but left the note; the regenerate note only cleared on the next regenerate), so they stuck on screen. Both transient acks now auto-dismiss after a short delay.
- **The digest composes automatically once every source reports DONE — no manual FLUSH.** `Digest_Builder` takes the scored Partition node to nudge plus the source `total` (`make_node Digest_Builder digest scored:partition 3`, where `3` MUST equal `count(Insights_CI::SOURCE_NODES)`) and composes + emits the markdown draft to `digest:tee` → `digest:log` the moment `count(distinct sources reported) === total`. The dashboard reads that durable `digest:log` on every poll, so a generated digest survives a page reload. There is no `request_node digest FLUSH` trigger or manual-flush path — runtime triggers are `TICK` (sources), `RESET` (clear state), and `REGENERATE` (recompose on demand). The `Digest_Builder` still **dedupes accumulated items by id** (cleared on `RESET`, which the dashboard Collect sends before TICKing the sources, and which nudges the scored Partition so the consumer persists the emptied snapshot; a dirty restored snapshot is deduped too), so the same item can't appear twice in a digest.

### Changed

- **The Scorer is now source-agnostic: it blends the LLM `relevance_score` with a recency bonus only.** The old per-source weight table keyed on `releases`/`community` — never the live `github`/`linear`/`feed` sources — so that term silently contributed zero for every real item; it's dropped. The no-LLM fallback (`score()`) stays a flat base plus title-keyword bumps. The relevance judgment lives upstream in the Summarizer's LLM `enrich` call; the Scorer just ranks.

- **The main topology now runs the real connectors, not the stub.** `topologies/newspack-ai-newsletter.tsl` replaces the single `Stub_Source` head with the three live connectors (`github`, `linear`, `feed`) fanned into the summarizer. They emit nothing until configured (`github_repos`/`github_token`, `linear_token`, `feeds`); trigger a source with `request_node <source> TICK` and the digest composes automatically once all sources report DONE (or `request_node digest REGENERATE` to recompose on demand). The canned `Stub_Source_Node` demo source has been removed now that the real connectors are wired.
- **Runtime triggers are now fire-and-forget `TM_REQUEST`s, not `TM_COMMAND` verbs.**
  The source's `tick` and the digest's triggers were dispatched as `TM_COMMAND` verbs
  through a sibling `{node}:config` interpreter (`Schema_Reflection` +
  `auto_wire_interpreter`). Per the Tachikoma convention — `TM_COMMAND` is for
  startup/administration, runtime triggers are `TM_REQUEST` — the source nodes and
  `Digest_Builder` now handle a `TM_REQUEST` directly in `fill()` (TICK / RESET /
  REGENERATE), emit downstream fire-and-forget, and declare the trigger under
  `node_schema()['requests']` so the console renders a request button. Trigger from
  the REPL with `request_node source TICK` / `request_node digest REGENERATE`.

### Added

- **Publisher Insights React dashboard.** `src/dashboard/` is now a real control panel (built to `build/dashboard`, enqueued on the Publisher Insights admin page). It mounts a node graph (`useInsightsGraph`) that polls the `Insights_CI` `insights` verb over the substrate `_http` boundary — page-visibility-gated, interval poll, reply pivot `FROM=insights:view` — and renders the scored model (`{ sources, top, accumulated }`) as KPI stats, proportion bars, and a ranked table. The Newsletter section shows the **real LLM-rendered digest** (`Insights_CI` now also serves the latest `digest:log` content as `model.digest`): **Generate digest** recomposes a fresh one on demand via a new manage_options-gated `generate` verb (awaited request/reply over the graph), **Copy markdown** copies that digest, and **Create draft post** converts it to native Gutenberg blocks (via the editor's paste engine) and POSTs a WordPress draft via `@wordpress/api-fetch`. The shared compose core lives in `Digest_Composer` so the worker auto-compose and the dashboard recompose can't drift. Ported from the `newspack-nodes/examples/example-ai-newsletter` teaching dashboard; the JS test harness gains `@testing-library/react` + `@testing-library/jest-dom`.
- **Nodes publish `set_state` lifecycle observability.** Traced nodes (`debug_state > 0`) now stream their progress to the REPL: the Summarizer emits `SUMMARIZED` (item title) on the LLM-enrich path and `FAILED` (title) when an LLM call errors; the Scorer emits `SCORED` (title); the Digest_Builder emits `RECEIVED` (title) per accumulated item and `COMPOSED` (item count) when it composes the draft. Trace a node to watch items flow.
- **Settings UI — a classic settings page for the AI proxy + connector credentials.** Every `Settings` field now carries a `render` + `sanitize` callback (text/password inputs; one-entry-per-line textareas for the `github_repos`/`feeds` lists), so the substrate `Schema` actually wires them via the Settings API — previously the fields were declared without callbacks and silently skipped, so there was no UI. A new **Publisher Insights → Settings** submenu (`manage_options`-gated) renders the form (`settings_fields` + `do_settings_sections` + save). Secrets render as password inputs; list values sanitize to trimmed, non-empty arrays; `relevance_profile` is a multi-line textarea (newlines preserved via `sanitize_textarea_field`). The list/textarea fields are fixed-width (not full-bleed) and the repo/feed lists show 14 rows. (The React dashboard handles insights *display* separately.)
- **Connector substrate — `Source_Node` base.** A new abstract `Source_Node extends Node implements Source` owns the uniform connector behavior so each connector supplies only `fetch( $config )` (the blocking HTTP call) and `config()` (its Settings read). On a fire-and-forget `TICK` (`TM_REQUEST`) it fetches, dedups by item `id` against an in-process bounded set (`MAX_SEEN = 2000`, oldest evicted), and emits each new item as `TM_STRUCT`. It also provides `normalize_item()` (the shared `{source,id,title,url,body,timestamp}` coercion) and `source_schema()` (the shared Source node_schema) so connectors don't restate either. (Dedup is per-worker-lifetime; durable cross-respawn dedup is a follow-up — it belongs at the ingest layer keyed by id, not in the head source.)
- **Connector — GitHub source.** `Github_Source_Node` fetches Releases, Merged PRs (closed with a `merged_at`), and Issues (the issues endpoint's PR entries are dropped) for every repo in the `github_repos` setting, normalized to the item contract with stable ids (`github:owner/repo#release-11` / `#pr-5` / `#issue-7`). Bearer auth + `User-Agent` when `github_token` is set. A failed repo/endpoint contributes nothing and never throws. Trigger `request_node github TICK`; the `wp_remote_get` call sits behind `Github_Source_Node::$http_get`.
- **Connector — Linear source.** `Linear_Source_Node` POSTs a GraphQL query for recently-updated issues (raw-token `Authorization`, no `Bearer`) and normalizes `data.issues.nodes[]` to the item contract (`linear:ABC-123`). No token → no call; transport error / non-200 / GraphQL-error body → nothing, never throws. Trigger `request_node linear TICK`; behind `Linear_Source_Node::$http_post`.
- **Settings — `github_repos` list + `Settings::get_array()`.** A new `github_repos` connector field (list of `owner/name`) and a list-config reader that returns the stored value as a trimmed, non-empty list of strings (shared by the `feeds` + `github_repos` connectors).
- **Connector — RSS/Atom Feed source.** `Feed_Source_Node` (a `Source_Node`) fetches
  every URL in the `feeds` setting and normalizes both RSS 2.0 (`channel/item`) and
  Atom (`entry`) into the digest item contract `{source,id,title,url,body,timestamp}`.
  The id prefers the RSS `guid` / Atom `id` and falls back to the link, so it stays
  stable across ticks. Atom link selection prefers `rel="alternate"` (the canonical
  URL) over a leading `rel="self"`/`edit`; RSS dating falls back to Dublin Core
  `<dc:date>` when `<pubDate>` is absent. The body is parsed with `LIBXML_NONET`
  (untrusted third-party XML). A feed that transport-errors, returns non-200, or won't
  parse contributes nothing and never throws. Trigger with `request_node feed TICK`;
  the `wp_remote_get` call sits behind the `Feed_Source_Node::$http_get` closure seam.

- **AI core — the Summarizer now calls the LLM.** Each item makes one AI API Proxy
  enrich call (`Prompts::enrich`) returning a one-line `summary`, a 0–10
  `relevance_score` (against the configured relevance profile), and a `reason`. When
  no `ai_proxy_token` is set or the proxy errors/returns unparseable JSON, it falls
  back to the heuristic summary (no score) and never throws. Driven by `Settings::get`
  / `Settings::llm_client` and the new `Prompts` builders.

- **AI core — the Scorer ranks by LLM relevance + recency + source.** When an item
  carries the Summarizer's `relevance_score`, the final score is
  `relevance × weight + recency_bonus(timestamp) + source_weight` (7-day half-life
  exponential recency decay); when it doesn't (Summarizer fell back), the existing
  keyword/source heuristic is used. No LLM call — purely deterministic.

- **AI core — the Digest builder composes an LLM briefing.** Once every source
  reports DONE, the scored items are sent in one AI API Proxy call (`Prompts::digest`) to compose a
  "what mattered" markdown briefing (intro + grouped sections + per-item blurbs).
  No token / proxy error / empty result → falls back to the ranked bullet list. The
  offsetlog snapshot contract (`save_state`/`restore_state`) is unchanged.

- **Foundation of the `newspack-ai-newsletter` sibling plugin** — a team-intelligence
  digest built on the newspack-nodes substrate. This initial drop is the runnable
  pipeline skeleton + the shared seams; live sources, real LLM wiring, the dashboard,
  and publishing land in follow-on sub-projects.
  - Scaffold: build/lint/test tooling (composer + npm + jest + phpunit + phpcs +
    phpstan), the `@newspack-nodes/*` build aliases, release + pre-push wiring —
    mirroring `newspack-event-logger-nodes`.
  - Pipeline spine (ported from the `example-ai-newsletter` walkthrough, contracts
    preserved byte-for-byte): `Summarizer_Node`, `Scorer_Node`, `Digest_Builder_Node`,
    `Insights_CI_Node` (its `{sources,top,accumulated}` JSON model unchanged), plus a
    canned `Stub_Source_Node` so the graph runs end-to-end before real connectors exist.
  - Bootstrap + `topologies/newspack-ai-newsletter.tsl` (the real ingest→summarize→
    score→scored-partition→digest→log graph, stub source wired) + an Insights admin page
    (dashboard enqueue guarded until the React build ships).
  - `LLM_Client` interface + `Proxy_LLM_Client` targeting the Automattic AI API Proxy
    (OpenAI `chat/completions`; default model `gpt-oss-120b`), with a closure-HTTP test seam.
  - `Source` connector interface + `Settings` schema declaring the AI + connector config,
    flagging the credential fields (`ai_proxy_token`, `github_token`, `linear_token`) secret
    via the substrate's `register_args` extension seam.
  - See `dndocker/docs/superpowers/specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md`.
