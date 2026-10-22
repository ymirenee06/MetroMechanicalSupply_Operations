# MMSC Operations System — Guide

Web-based Inventory, Sales, and Employee Accountability Management System for Metro Mechanical Supply Corporation.
PHP 8.1+ and MySQL/MariaDB (XAMPP). No Composer packages, no internet connection needed.

This one file replaces the old READMEs: **setup**, **accounts**, **how to use each role**, **change log**, and **troubleshooting**.

---

## 1. Setup (XAMPP)

1. Copy the `MMSC_OperationsSystem` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open **phpMyAdmin** → *Import* → choose `database/mmsc_db.sql` → *Go*.
   The file creates the `mmsc_db` database by itself; you don't need to create it first.
   - If an older `mmsc_db` already exists, drop it first (or rename it as a backup), then import.
4. Open `http://localhost/MMSC_OperationsSystem/`.

Database settings are in `backend/config.php` (default: host `127.0.0.1`, port `3306`, user `root`, no password).
If your XAMPP MySQL runs on port **3307**, change `'db_port' => 3306` to `3307` there.

**Keeping an existing database instead of re-importing**
If you want to keep the data you already have, don't import `mmsc_db.sql`. Instead, open a terminal in the project folder and run:

```
C:\xampp\php\php.exe tools\migrate.php
```

That applies `database/migrations/004_operations_update.sql`, `005_suppliers.sql`, `006_job_templates.sql`, and `007_tester_fixes.sql`: item pictures, the Received By / Encoded By split, required items per job, the customer and supplier lists, job templates, the "Released" request status, cancellable tool transfers, and deactivating customers. You won't get the demo data or the default passwords this way.

**Item pictures** are saved in `uploads/items/`. The folder is created automatically. `uploads/.htaccess` blocks scripts from running there.

**Backups:** `C:\xampp\php\php.exe tools\backup.php` writes a full `.sql` backup into `backups/`.

---

## 2. Accounts

Log in with the **Employee ID**. Every account starts with the default password **`Mmsc@2026`**.
At first login the system opens **My account** and nothing else works until the person sets their own password (at least 12 characters).

| Role | Employee IDs (name) |
|---|---|
| Executive Officer | MMS-2015-001 (Joshua Madrid Sampoli), MMS-2016-004 (LJ Del Rosario), MMS-2017-006 (Jonathan Madrid Sampoli), MMS-2024-022 (Bernadette Dizon) |
| Logistics | MMS-2019-010 (Danilo Soriano), MMS-2015-002, MMS-2021-014, MMS-2022-017, MMS-2023-019, MMS-2023-021, MMS-2024-023 |
| Sales | MMS-2023-020 (Angela Nicole Ocampo), MMS-2017-005, MMS-2019-009, MMS-2020-012, MMS-2020-013 |
| Purchasing Officer | MMS-2019-011 (Arlene Mae Garcia), MMS-2018-007, MMS-2022-018 |
| Accounting | MMS-2016-003 (Liza Santos), MMS-2018-008, MMS-2021-016, MMS-2025-025 |
| Technician | MMS-2021-015 (Christian Paul Ramos), MMS-2025-024 (Reginald Tan) |

These are exactly your original 25 accounts. An Executive Officer can reset any password from **Users**; the person then sets a new one at next login.

**Two-person rule (still enforced):** nobody approves their own work. If an Executive Officer records a delivery, a *different* Executive Officer posts it. Same for requests, voids, and returns.

---

## 3. Demo data (what's already in the database)

Your **original 25 employees and 49 items are unchanged**: same names, codes, prices, and suppliers. Only **history** was generated, Jul 13 to Oct 10, 2026 (Monday to Saturday), starting from an opening inventory on Jul 11:

- **635 sales**: counter sales (walk-ins and contractors) and **job billings**, paid by Cash, GCash, Bank Transfer, and Check, including split payments and change.
- **55 jobs** for 26 saved customers, each with required items, releases, leftovers, tool returns, billing, and closing.
- **81 deliveries**, each with Received By, Encoded By, a supplier, and an Executive Officer who posted it.
- One voided sale, one approved customer return, and one damaged-tool incident (resolved).
- Supplier list: your existing suppliers plus a few local distributors used by the demo deliveries.

**Things waiting for someone, so you can demo each screen:**

| Where | What is waiting |
|---|---|
| Approval queue | 2 deliveries to post, 1 additional-item request, 1 return-date extension |
| Release items | 1 approved request not yet released; **JO-1055** has required items but nothing released yet |
| Returns / Dashboard | Company tools out, some **overdue** |
| My items (Technician) | Consumables to declare; 1 return to confirm |
| Job billing | **JO-1051** is ready to bill (itemized materials + service fee) |

---

## 4. How to use it (by role)

### Executive Officer: full access
The sidebar includes every module: approvals, inventory, jobs, the stockroom (release and returns), the counter (sales, downpayments, billing, drawer), sales records, reports, customers, users, settings, and the audit log.

- **Approval queue:** post or reject deliveries (**no reason needed to Post**; a reason is only needed to Reject), and approve requests, extensions, voids, returns, and refunds.
- **Add item:** includes an optional **picture** (JPG/PNG/WEBP, up to 3 MB) and a **Supplier** dropdown (or add a new supplier). Pictures can also be added later on the item page.
- **Register job:** see *Jobs* below.
- To use the counter, open a drawer first under **My drawer**, exactly like Sales staff.

### Jobs (Executive Officer registers; Logistics releases; Technician confirms; Sales bills)
0. **Job templates** (Executive Officer, menu *Jobs → Job templates*)
   - A template is the usual list of required items and the usual service fee for one kind of job, e.g. "Split-type aircon installation". Five starter templates are included; edit them or add your own.
   - Templates accept company tools and consumables only (tools for sale can't be listed). **Hide** a template you no longer use; past jobs are not affected.
1. **Register job**
   - **Start from a template** (optional): picking one fills in the service type, the usual service fee, and the required items. **Everything stays editable**: change quantities, remove rows, add items, or change the fee. The job keeps its own list; editing a template later doesn't change existing jobs.
   - The job order number is suggested (`JO-####`); change it to match the paper form.
   - **Client** is a dropdown of saved customers. Choose *+ Add a new customer…* to add one on the spot.
   - Enter the **Agreed service fee** (labor only).
   - Under **Required items**, add one row per item: **Type** (Consumable or Tool / equipment) → **Item** → **Quantity**.
   - The **Estimated total = service fee + consumables + 12% VAT** is computed automatically. Nobody has to compute it by hand. Tools are listed so Logistics can prepare them; they are lent, not billed.
2. **Release items** (Logistics or Executive Officer)
   - When you choose the job, its **required items fill in automatically** (only what hasn't been released yet). Adjust or remove rows as needed.
   - Each row has its own **Type** dropdown, so you can release tools and consumables together. They are saved as a tool release and a consumable release.
   - **Approved additional-item request:** choosing one fills in the job, technician, and item. After release the request shows **Released**.
3. **Technician (My items)**
   - Confirms each release.
   - Sends **additional item requests** and follows them under **My requests** (Pending → Approved/Denied → Released).
   - Declares consumable leftovers and confirms tool returns.
4. **Returns** (Logistics)
   - Record the inspection of a returned tool. The row then shows **"Waiting for the technician to confirm"** until the technician confirms; after that, the tool is back in available stock.
   - Receive declared leftovers back into stock.
5. **Job billing** (Sales or Executive Officer)
   - The bill is **itemized**: every material actually used (released minus leftovers), with qty, price, and total, then the service fee.
   - If a required item was never released, a warning names it, because only what was released and used is billed.
6. **Close the job** (Executive Officer) once tools are back and leftovers are received.

### Sales
1. **My drawer:** open a drawer with the opening float.
2. **Counter sale**
   - Items: quantities are **whole numbers** unless the item is sold by measure (meters of wire, kilograms), which the hint under the item shows as "decimals allowed".
   - **Customer:** a dropdown (Walk-in, a saved customer, or *+ Add a new customer…*); the contact fills in automatically.
   - **Payment:** type the amount the customer hands over (e.g., ₱1,000). **Amount received** and **Change** update live.
   - **Split payment:** adds a second line already filled with what's still unpaid, so there's nothing to compute.
   - **Exact amount:** fills the last line with the remaining balance.
   - The receipt shows each payment, **Amount received**, and **Change**.
   - **Print receipt** (on the sale page, also after job billing) opens a clean receipt page in a new tab that prints just the receipt on one A4 page: OR number, date, customer, job, items, totals, payments, change, and signature lines. Nothing else is printed, so there is no need to adjust the scale. Voided sales print with a "VOIDED" mark.
3. **Downpayment:** for registered jobs, up to the estimated total.
4. **Job billing:** see Jobs above.
5. **Customers:** new customers are added straight from Counter sale or Register job (*+ Add a new customer…*). The **Customers** page (Administration, Executive Officer only) corrects names and contact numbers, **deletes** a customer added by mistake (only when they have no sales or jobs), and **deactivates** anyone with history: they disappear from the dropdowns but their sales and jobs are kept. **Reactivate** brings them back.

### Purchasing Officer / Logistics: Deliveries
- **Quantity to add to stock** is the number in the item's unit of measure that gets added to inventory (a 15 m roll of an item sold *per meter* is **15**).
- **Quantity as printed on the DR** is optional text copied from the supplier's receipt (e.g., "1 roll") so the Executive Officer can match the paper DR.
- **Received by** is the person who physically accepted the delivery. The logged-in user is recorded separately as **Encoded by**.
- **Supplier** is a dropdown. It's pre-selected from the item when the item has a usual supplier; choose **+ Add a new supplier…** to add one on the spot. It can't be left blank.
- Stock goes up only after an Executive Officer posts it.

### Items and stock (everyone)
There are **three kinds of items**:

| Kind | What it is | Where it appears |
|---|---|---|
| **Company tool** | Equipment the company owns and lends to technicians (must come back) | Release items, Register job, Returns, technician requests. Never in Counter sale. |
| **Tool for sale** | Tools sold to customers as merchandise | Counter sale only. Never released to technicians. |
| **Consumable** | Used up on jobs or sold | Counter sale, Release items, Register job |

A tool's kind is its **"Can be sold at the counter"** setting: ticked = tool for sale, not ticked = company tool. An Executive Officer can switch it on the item page (**How this tool is used**). Switching to "for sale" is blocked while units are still with technicians.

- The tabs **All items / Company tools / Tools for sale / Consumables** (with counts) separate them, and each row has a coloured label.
- The list shows a small picture on the left; open an item to see the larger picture, its details, and stock history.

### Accounting / Executive Officer: Sales summary
**Sales summary** groups sales **Daily, Weekly, Monthly, Quarterly, or Yearly** and shows:
- net sales, VAT, discounts, transactions, average sale, and the counter vs. job billing split;
- a bar chart over time;
- **most bought** items (by quantity) and top earners (by amount);
- payment methods (with change handed back);
- busiest days of the week and top customers.

**Export to Excel** downloads every section as separate sheets.

### Reports
Pick a report and dates → **Show report**. Results show 20 rows a page (change it with Rows per page). The screen shows the key columns so nothing scrolls sideways. **Export to Excel** downloads every row and column (headers frozen, filters on, pesos formatted). There is no Print button; use the Excel file. Receipts on the sale page can still be printed.

**Current-status reports** (Stock Status, Checked-Out / Overdue Items, Technician Accountability, Downpayments Held) show things as they are right now, so their From/To boxes are switched off and the page says "current status as of …". For every other report the From date must be on or before the To date.

---

## 5. Common questions

**Why are some quantities whole numbers and others not?** Money always has centavos. Quantities are **whole numbers for countable items** (pieces, units, sets, cylinders, rolls). Items sold **by measure** (wire per meter, welding rod per kilogram) are marked "allows decimals" on the item, so 12.5 m is accepted. The quantity box follows the item you pick, and the server rejects decimals for countable items.

**Why does the estimate include VAT?** Because the downpayment limit and the final bill are both VAT-inclusive, so the client is quoted the same kind of number they will pay.

**Why does billing differ from the estimate?** The estimate is the plan; billing uses what was actually released and used (minus leftovers returned). Unreleased required items are named on the billing screen.

**Can an Executive Officer do everything?** Yes, every page except the technician's personal "My items". Approvals still need a different person than the one who did the work.

---

## 6. Change log

### Round 9 (Oct 22, 2026): remove an item's picture
- **Item page:** a **Remove picture** button sits next to **Save picture** (same width). It asks for confirmation, deletes the picture file, and the item goes back to "No picture yet" on its page and in the Items list. The removal is written to the audit log (ITEM_IMAGE_REMOVE). Available to the same roles that can upload pictures (Executive Officer, Purchasing Officer, Logistics).

### Round 8 (Oct 15, 2026): reasons, pagination, alignment
**Reasons and notes**
- Every required reason or note needs at least **3 letters or numbers**, so "x", "ok" or "..." don't count.
- The browser shows the message next to the box right away, and the server refuses it too.
- An additional-item request with a blank reason now says "Say why you need the extra item", not "Enter a positive quantity and reason".

**Add item**
- While typing the name, items already in the catalog that match appear underneath with their stock.
- An exact duplicate name is refused, with the existing item's code.

**Reports and Audit log**
- Reports show **20 rows a page**, with a Rows per page choice (20/50/100) and page numbers. Export to Excel still has every row.
- The Audit log shows 25 entries a page.
- Dates and reference numbers no longer break onto two lines.

**Dates**
- Typing a year (e.g. 2026) in the Reports From/To boxes no longer clears the date.

**Alignment**
- **Counter sale / Job billing / Downpayment:** the item and payment rows share one header row; item, quantity, amount and × sit on one line (also tidy on phones).
- **Forms:** hints sit on the same line as their label, so labels and boxes line up across each row (Add item, Deliveries, Register job, Returns).
- **Table buttons:** fixed widths (Customers, Users, Job templates, Approval queue), so Edit + Delete lines up with Edit + Deactivate.
- **Settings:** the value box and Save are on one line.
- **Job page:** Executive controls buttons are the same width.
- **Sales and Daily summary:** the date filter no longer stretches across the page.
- **Empty tables:** they now say so (Physical count, Sales returns).

### Round 7 (Oct 15, 2026): tester feedback
**Text fields**
- Blank-looking text is treated as empty everywhere. Spaces, non-breaking spaces, zero-width spaces, the Hangul filler (U+3164) and Braille blank (U+2800) that look like a blank, and other invisible characters are removed before anything is saved. The same check runs in the browser, so Reject is stopped on the spot.
- A required reason (discount, reject, deny, dispute, void, return, cancellation, extension, explanation) must contain at least one letter or number. Approving with only spaces saves no note.
- Reject/Dispute with an empty reason is stopped on the spot with a message.

**Duplicate saves**
- Every form can be saved only once. A double click, pressing Enter twice, or re-sending the page records it once and shows "That form was already submitted".

**Additional-item requests**
- The quantity can't be more than **Qty Available** (SOW flow 2.5): the quantity box stops at the stock level, and the server refuses more.
- Approving a request re-checks the stock.

**Prices**
- An item that is sold (a consumable or a tool for sale) can't have a selling price below its unit cost or a price of ₱0. A red note shows the loss per sale while typing.
- Company tools can stay at ₱0 because they are never sold.

**Job page**
- When every technician is already on the job, it says "No technicians available to add" instead of showing an empty dropdown.

**Customers**
- Delete (no sales or jobs), Deactivate (has history) and Reactivate.

**Technician transfers**
- Fixed **Accept/Decline not working** ("That transfer is not waiting for you"). The transfer's status was being read from the wrong record.
- **Transfers to you** shows only transfers addressed to you, with Accept/Decline. Switching between technician accounts in the same browser shows each technician only their own.
- **Transfers you sent** shows your own transfers with their status. **Cancel transfer** works while one is still waiting.
- A tool can't be sent in two transfers at once.

**Receipt**
- **Print receipt** opens in the same tab. **Back to the sale** and **New sale** / **Bill another job** return in that tab, so no duplicate tabs are left open.

**Reports**
- Dates are switched off for current-status reports.
- From after To is refused with a message.
- Quantities and Yes/No columns no longer show as pesos (e.g. Qty Held "₱1.00").

**Already working before this round**
- Posting a delivery in the Approval queue never needed a reason; the screenshot was from an older build.

### Round 6 (Oct 11, 2026, late night): pages stay where you were after saving
- **No more jumping to the top**: on every page, pressing a save button (Declare, Confirm, Approve, Deactivate, Reset password, Show/Hide and so on) brings you back to the same spot after the save. The section you were working in stays at the same place on screen, even when the row you just finished disappears.
- **Messages you can see**: the "saved" or error message now also appears in the bottom-right corner, because the banner at the top is out of view. Success messages fade after 6 seconds; error messages stay until closed with ×.
- **Exceptions**: saves that open a different page (new sale → receipt page, new job → job page, release → job page) open that page at the top, as before. **Record an incoming delivery** returns to the top on purpose so the empty form is ready for the next delivery.
- **My items → Consumable leftovers**: each item now has its full name on one line, with the Unused quantity, unit and Declare button underneath, so long names no longer squeeze into a narrow column.

### Round 5 (Oct 11, 2026, night): job templates and receipt printing
- **Job templates** (new `job_templates` and `job_template_items` tables, migration 006): manage under *Jobs → Job templates*. Register job has **Start from a template**, which fills the service type, usual fee, and required items; everything stays editable. Jobs remember which template they started from.
- **Print receipt**: a dedicated print page (`receipt`) with only the receipt, laid out for A4 portrait with fixed margins. It opens the print dialog automatically. Pressing Ctrl+P on the sale page also prints only the receipt now.
- **Receipt alignment**: the receipt uses one column grid. Totals sit inside the item table, so labels start under QTY and amounts line up exactly under AMOUNT. Header details (Date/Sold to/Cashier/Contact) follow the same split. Notes such as a discount reason, downpayment OR number or payment reference appear in small text under their label.

### Round 4 (Oct 11, 2026, night): company tools vs tools for sale
- Tools are either **company tools** (lent) or **tools for sale** (merchandise), based on the existing "Can be sold at the counter" setting. Your data: 6 company tools, 14 tools for sale.
- Release items, Register job, and technician requests list company tools only; the server rejects a tool for sale there too. Counter sale lists tools for sale under their own group.
- Items page has four tabs; labels read "Company tool" / "Tool for sale"; Deliveries groups the item list the same way.
- Item page: Executive Officers can switch a tool between the two kinds.
- Demo data: only company tools are lent; tools for sale are sold at the counter and restocked by deliveries.

### Round 3 (Oct 11, 2026, evening)
| Request | What changed |
|---|---|
| Pages looked broken / old behaviour came back | The browser kept the old CSS/JS in its cache. The app now stamps CSS/JS links with the file's date (`asset()` in `helpers.php`), so browsers always load the latest version. |
| Default password, change at first login | All 25 accounts: `Mmsc@2026`, must change on first login. |
| Approval queue scrolled sideways | Rebuilt: count chips at the top, one full-width table per type, decision form on one line; empty types are hidden. |
| Tools / Consumables separation | Proper tabs with counts and an underline on the active one. |
| Picture filled the screen | Fixed 220×220 picture on the item page; 48×48 thumbnail in the list (sizes set on the image itself too). |
| Item list vs item page | List: small picture + name/code/category. Item page: picture, stock figures, and details at the top; latest 25 stock movements below. |
| Delivery form | Full-width, three aligned columns, short hints; Supplier dropdown with **+ Add a new supplier…** (new `suppliers` table, migration 005). |
| Release page | Table-style editor: headings once, one row per item (Type, Item, Qty, Condition, Due back). Consumable rows show "Not returned" instead of empty boxes. |
| Don't add employees/items | Back to your original 25 employees and 49 items, unchanged; only transaction history is seeded. |
| Sales summary messy | Summary cards, chart, then ranked tables (most bought, top earners, payment methods, weekdays, customers) with small share bars. |
| Remove Print | Removed from Reports (Excel export instead). |
| Customers page | Didn't exist originally. Kept only for Executive Officers (Administration) to correct details; everyone else adds customers inline. |
| Sidebar jumped to the top | The sidebar remembers its scroll position between pages. |
| Blank item showing "Roll 77 in stock" | Same old cached script; fixed by the cache stamping above. Counter-sale items are now grouped into Consumables and Tools. |
| Small text fields | All fields share one style; explanation fields in Shift handover are now text areas. |

Also found and fixed while checking the business flows:
- **Customer returns could never be submitted** (a misplaced bracket in the return check). Fixed.
- **Cash Drawer report always failed** (it asked for a column that doesn't exist). Fixed.
- The Sales returns page only listed **today's** sales although returns are allowed for 7 days (Settings); it now lists the whole return window.
- Tool transfer only lists technicians on the same job (others were always rejected).
- "Add technician" on a job no longer lists people already assigned.
- Reports show the key columns on screen, so none scroll sideways.

### Round 2 (Oct 11, 2026)
| # | Request | What changed |
|---|---|---|
| 1 | "Apply" → "Search" in Inventory | Button renamed; added a Type filter and All / Tools / Consumables buttons. |
| 2 | Item pictures | Upload on Add item and on the item page (Executive Officer, Purchasing, Logistics); thumbnails in the list. |
| 3 | Executive Officer full access | Added to every route and menu (deliveries, release, returns, counter, drawer, refunds, voids, returns, summary, customers). The two-person rule stays. |
| 4 | Separate Received By / Encoded By | New `encoded_by` column; the delivery form has a **Received by** dropdown; both names are shown in Deliveries, Approvals, and the deliveries report. |
| 5 | Required items in the job entry | New `job_items` table; Register job has item rows; the job page shows needed / released / status and lets an Executive Officer edit the list; the release form pre-fills from it. |
| 6 | Additional requests and returns not updating | Requests now become **Released** when Logistics releases them, technicians see **My requests**, the job page lists requests, and the release page highlights approved requests. Returns show "waiting for technician confirmation" instead of looking unchanged, and the wrong "returned to stock" message was corrected. |
| 7 | Seed 3 months of data | See section 3 (round 3 restored your original employees and items). |
| 8 | Clean zip + one guide | Removed old READMEs, example config, .gitignore, and old migrations (already inside the database file); this guide replaces them. |
| 9 | Tool / consumable distinction | Coloured type labels everywhere; type filter; Type dropdown on every item row. |
| 10 | Whole-number quantities | Quantity boxes use whole numbers unless the item allows decimals (also enforced by the server and a database trigger). |
| 11 | Automatic estimated total | Computed from service fee + consumables + VAT, live on screen and saved; recomputed when the fee or required items change. |
| 12 | Sales summary with charts | New **Sales summary** page (daily to yearly, most bought, payment mix, customers, weekday) with Excel export. Charts are drawn in the page, so they also work offline. |
| 13 | "Run report" + Excel export | Renamed **Show report**; **Export to Excel** on every report (built-in .xlsx writer; no PHP extension needed). |
| 14 | Reset password / Deactivate touching | Buttons now have spacing. |
| 15 | No tools in seed | Tools now have stock and lending history (round 3 keeps your original 20 tools). |
| 16 | "What are you releasing?" | Replaced by **Items to release** with a Type dropdown on each row (and on every added row). |
| 17 | Blank item in counter-sale dropdown showing "Roll · 60 in stock" | That blank line was the first item being renamed by the stock hint script. Fixed: the hint only writes to the hint text now. |
| 18 | Split payment hard to compute | New payment lines start with the unpaid balance; "Exact amount" fills the remainder. |
| 19 | Customer dropdown | Saved customer list (new `customers` table) with an inline "add new"; also used by Register job; **Customers** page. |
| 20 | How much the customer actually paid | **Amount received** shown live, in the success message, and on the receipt, next to Change. |
| 21 | Job billing showed only Service Fee | Billing is itemized by material used. The earlier bill showed only the service fee because no consumables had been released for that job; it now says so and names the unreleased required items. |

Also fixed in round 2:
- The included SQL dump could not be re-imported (a broken `v_item_stock` view definition).
- A text-collation mismatch on import; it now imports with `utf8mb4_unicode_ci`.
- Unstyled Notes/Reason boxes on the technician page.
- Release now groups items by their real type, even if the row's Type dropdown is wrong.

### Round 1 (from the error PDF)
1. **Approval Post saved as Rejected / "N/A" needed:** the Post button sent the wrong value; fixed in your copy. Old rows rejected this way must be re-encoded.
2. **Physical count warning "Undefined array key closer":** the active-count query lacked the `closed_by` join, and an HTML comment `<!-- -->` does not stop PHP from running. Both fixed.
3. **Report printing cut off:** landscape print stylesheet, print header, full table; plus Excel export in round 2.
4. **Delivery quantity fields unclear:** relabeled (see Deliveries above).
5. **Per-item tool/consumable choice on release:** done (round 2 refined it).
6. **Job billing SQL error 21S01 (column count):** one missing `?` in the job-billing INSERT. Fixed.
7. **Stock hint overwriting item names** in the counter sale and release dropdowns. Fixed.

---

## 7. Troubleshooting

| Problem | Fix |
|---|---|
| "Database problem … Access denied / Connection refused" | Check MySQL is running and the port/user in `backend/config.php`. |
| "Illegal mix of collations" after importing | Import `database/mmsc_db.sql` again into a fresh database (the file sets `utf8mb4_unicode_ci`). |
| Picture upload fails | The file must be JPG, PNG, or WEBP and 3 MB or smaller; XAMPP's `upload_max_filesize` must be at least 3M (the default is 40M). |
| "A different Executive Officer must verify…" | That's the two-person rule. Log in as another Executive Officer. |
| Can't take a downpayment | The job needs an estimated total; it is computed when the job is registered. |
| Page looks unstyled after an update | Press **Ctrl+F5** once. (Normally not needed any more: CSS/JS links change whenever the files change.) |
| Refund says "Open a cash drawer first" | Refunds come out of a drawer; open one under My drawer. |
| Job not listed under Job billing | The technician still has consumables to declare (My items → Consumable leftovers). |

**Project layout:** `index.php` (router) · `backend/` (config, database, rules in `services/`) · `frontend/` (pages, CSS/JS) · `database/` (`mmsc_db.sql` + `migrations/`) · `tools/` (migrate, backup) · `uploads/` (item pictures).
