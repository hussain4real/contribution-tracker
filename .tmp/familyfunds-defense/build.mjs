import fs from "node:fs/promises";
import { Presentation, PresentationFile } from "@oai/artifact-tool";

const OUT = "/Users/amisha/www/contribution-tracker/docs/project-report/familyfunds-final-project-defense.pptx";
const RENDER = "/Users/amisha/www/contribution-tracker/.tmp/familyfunds-defense/rendered";
const ROOT = "/Users/amisha/www/contribution-tracker";

const C = {
  white: "#FFFFFF",
  ink: "#0A0D12",
  muted: "#5D6673",
  panel: "#EEF1F4",
  panel2: "#F7F8FA",
  rule: "#B8BCC4",
  teal: "#0F9D83",
  tealDark: "#087263",
  tealPale: "#E7F7F2",
  navy: "#173B63",
  navyPale: "#E8EFF7",
  amber: "#B86B00",
  amberPale: "#FFF5DE",
  red: "#B42318",
  redPale: "#FDECEA",
};

const presentation = Presentation.create({ slideSize: { width: 1280, height: 720 } });

async function imageBytes(relativePath) {
  const bytes = await fs.readFile(`${ROOT}/${relativePath}`);
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
}

function addText(slide, text, left, top, width, height, options = {}) {
  const box = slide.shapes.add({
    geometry: "textbox",
    name: options.name,
    position: { left, top, width, height },
    fill: "none",
    line: { style: "solid", fill: "none", width: 0 },
  });
  box.text = text;
  box.text.style = {
    fontSize: options.fontSize ?? 22,
    typeface: "Helvetica Neue",
    color: options.color ?? C.ink,
    bold: options.bold ?? false,
    alignment: options.alignment ?? "left",
    verticalAlignment: options.verticalAlignment ?? "top",
    autoFit: options.autoFit ?? "shrinkText",
  };
  return box;
}

function addRect(slide, left, top, width, height, fill = C.panel, options = {}) {
  return slide.shapes.add({
    geometry: options.geometry ?? "rect",
    name: options.name,
    position: { left, top, width, height },
    fill,
    line: {
      style: options.lineStyle ?? "solid",
      fill: options.lineFill ?? "none",
      width: options.lineWidth ?? 0,
    },
    ...(options.borderRadius ? { borderRadius: options.borderRadius } : {}),
  });
}

function addRule(slide, left, top, width, color = C.rule, weight = 1) {
  return slide.shapes.add({
    geometry: "straightConnector1",
    position: { left, top, width, height: 0 },
    fill: "none",
    line: { style: "solid", fill: color, width: weight },
  });
}

function addFooter(slide, number) {
  addText(slide, "FAMILYFUNDS  ·  FINAL PROJECT DEFENCE", 56, 678, 500, 18, {
    fontSize: 13,
    color: C.muted,
    bold: true,
    verticalAlignment: "bottom",
  });
  addText(slide, String(number).padStart(2, "0"), 1180, 675, 44, 20, {
    fontSize: 14,
    color: C.muted,
    bold: true,
    alignment: "right",
    verticalAlignment: "bottom",
  });
}

function addHeader(slide, title, number, eyebrow = "FAMILYFUNDS") {
  slide.background.fill = C.white;
  addText(slide, eyebrow, 56, 30, 310, 22, {
    fontSize: 14,
    color: C.tealDark,
    bold: true,
  });
  addText(slide, title, 56, 59, 1168, 74, {
    fontSize: 48,
    bold: true,
    name: `slide-${number}-title`,
  });
  addRule(slide, 56, 142, 1168, C.ink, 1.3);
  addFooter(slide, number);
}

function addNotes(slide, lines, sources) {
  slide.speakerNotes.textFrame.setText([
    ...lines,
    "",
    "[Sources]",
    ...sources.map((source) => `- ${source}`),
  ]);
  slide.speakerNotes.setVisible(true);
}

function addImage(slide, bytes, alt, left, top, width, height, fit = "contain") {
  return slide.images.add({
    blob: bytes,
    contentType: "image/png",
    alt,
    fit,
    position: { left, top, width, height },
  });
}

// Slide 1 — cover-image-field hierarchy.
{
  const slide = presentation.slides.add();
  slide.background.fill = C.white;
  addText(slide, "MIVA OPEN UNIVERSITY  ·  FACULTY OF COMPUTING", 58, 44, 650, 24, {
    fontSize: 15,
    color: C.tealDark,
    bold: true,
  });
  addText(slide, "FamilyFunds", 58, 114, 650, 72, {
    fontSize: 72,
    bold: true,
    name: "cover-title",
  });
  addText(slide, "Design and implementation of an AI-enhanced multi-tenant family fund management system", 58, 204, 628, 185, {
    fontSize: 36,
    bold: true,
  });
  addText(slide, "Predictive analytics and intelligent reporting remain governed by explicit evidence gates.", 58, 410, 590, 78, {
    fontSize: 24,
    color: C.muted,
  });
  addRect(slide, 740, 42, 484, 578, C.tealPale, {
    geometry: "roundRect",
    borderRadius: "rounded-3xl",
    lineFill: C.rule,
    lineWidth: 1,
  });
  const icon = await imageBytes("public/app-icon-1024.png");
  addImage(slide, icon, "FamilyFunds application icon", 842, 118, 280, 280, "contain");
  addText(slide, "Secure tenancy", 812, 446, 340, 32, { fontSize: 25, bold: true, alignment: "center" });
  addText(slide, "Deterministic ledger", 812, 489, 340, 32, { fontSize: 25, bold: true, alignment: "center" });
  addText(slide, "Governed intelligence", 812, 532, 340, 32, { fontSize: 25, bold: true, alignment: "center" });
  addText(slide, "AMINU DANLADI HUSSAIN  ·  2024/A/SENG/0156", 58, 630, 620, 24, {
    fontSize: 17,
    bold: true,
  });
  addText(slide, "Supervisor: Dr Samuel Makinde", 58, 661, 420, 20, { fontSize: 15, color: C.muted });
  addText(slide, "01", 1180, 675, 44, 20, { fontSize: 14, color: C.muted, bold: true, alignment: "right" });
  addNotes(slide, [
    "Timing: 35–40 seconds.",
    "Introduce the project as a software-engineering response to fragmented family-fund administration.",
    "State the evidence rule early: the conventional system and predictive pipeline are discussed separately from claims that need hosted services or authorised private data.",
  ], [
    "docs/project-report/preliminary-pages.md",
    "docs/project-report/chapter-1.md",
    "public/app-icon-1024.png",
  ]);
}

// Slide 2 — evidence-led image split.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Manual records fragment one financial story", 2, "THE PROBLEM");
  addText(slide, "A contribution can exist in four places—yet still lack one defensible balance.", 56, 171, 332, 84, {
    fontSize: 28,
    bold: true,
  });
  const issues = [
    ["01", "No shared source of truth"],
    ["02", "Weak role boundaries"],
    ["03", "Inconsistent partial-payment allocation"],
    ["04", "Delayed reporting and reconciliation"],
  ];
  issues.forEach(([n, label], index) => {
    const y = 292 + index * 70;
    addText(slide, n, 56, y, 46, 30, { fontSize: 20, color: C.tealDark, bold: true });
    addText(slide, label, 112, y - 2, 276, 48, { fontSize: 21, bold: true });
    if (index < issues.length - 1) addRule(slide, 56, y + 51, 332, C.rule, 1);
  });
  addRect(slide, 426, 171, 798, 468, C.panel2, { lineFill: C.rule, lineWidth: 1 });
  const manual = await imageBytes("docs/project-report/diagrams/chapter-3-existing-system-flow.png");
  addImage(slide, manual, "Existing manual family fund workflow and its weaknesses", 446, 190, 758, 430, "contain");
  addNotes(slide, [
    "Timing: 45 seconds.",
    "Explain that the problem is not simply a lack of digital payment. It is the absence of one governed record linking obligations, receipts, expenses and reports.",
    "Use the workflow to show why disputes are resolved by memory when partial payments and corrections are recorded inconsistently.",
  ], [
    "docs/project-report/chapter-1.md",
    "docs/project-report/chapter-3.md",
    "docs/project-report/diagrams/chapter-3-existing-system-flow.png",
  ]);
}

// Slide 3 — data-table evidence hierarchy.
{
  const slide = presentation.slides.add();
  addHeader(slide, "The missing capability is governed coordination", 3, "THE GAP");
  addText(slide, "Adjacent tools solve pieces of the problem; none combines the full family-fund workflow.", 56, 166, 1130, 42, {
    fontSize: 23,
    color: C.muted,
  });
  const x = [56, 410, 648, 888, 1224];
  const headers = ["Approach", "Contribution rules", "Tenant / role control", "Auditable allocation & reports"];
  addRect(slide, 56, 230, 1168, 54, C.navy);
  headers.forEach((h, i) => addText(slide, h, x[i] + 10, 244, x[i + 1] - x[i] - 20, 28, {
    fontSize: i === 0 ? 20 : 18,
    color: C.white,
    bold: true,
    alignment: i === 0 ? "left" : "center",
  }));
  const rows = [
    ["Notebook / chat / spreadsheet", "Manual", "Weak", "Fragmented"],
    ["Consumer savings and payment apps", "Limited", "Individual", "Payment-led"],
    ["Formal cooperative platforms", "Structured", "Organisation-led", "Formal-scheme fit"],
    ["FamilyFunds", "Family-defined", "Family membership", "Deterministic + traceable"],
  ];
  rows.forEach((row, r) => {
    const top = 284 + r * 71;
    addRect(slide, 56, top, 1168, 71, r === 3 ? C.tealPale : (r % 2 === 0 ? C.white : C.panel2));
    addRule(slide, 56, top + 70, 1168, C.rule, 1);
    row.forEach((value, i) => addText(slide, value, x[i] + 10, top + 18, x[i + 1] - x[i] - 20, 36, {
      fontSize: i === 0 ? 19 : 18,
      color: r === 3 ? C.tealDark : C.ink,
      bold: r === 3 || i === 0,
      alignment: i === 0 ? "left" : "center",
      verticalAlignment: "middle",
    }));
  });
  addText(slide, "FamilyFunds is a governed ledger and workflow—not another payment button.", 56, 602, 1168, 38, {
    fontSize: 26,
    bold: true,
    color: C.navy,
    alignment: "center",
  });
  addNotes(slide, [
    "Timing: 45 seconds.",
    "Compare system categories, not marketing claims. Savings apps, spreadsheets and cooperative platforms each address part of the need.",
    "Emphasise that the contribution rule, family-specific role and allocation audit trail must work together.",
  ], [
    "docs/project-report/chapter-1.md",
    "docs/project-report/chapter-2.md",
  ]);
}

// Slide 4 — six objectives as a flat paired sequence.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Six objectives connect the study to evidence", 4, "STUDY OBJECTIVES");
  const objectives = [
    ["01", "Review", "Practice, tools and literature"],
    ["02", "Design", "Multi-tenancy, roles and data structures"],
    ["03", "Implement", "Core financial and reporting modules"],
    ["04", "Govern AI", "Family-scoped assistance with confirmation"],
    ["05", "Evaluate prediction", "Logistic regression against two baselines"],
    ["06", "Test", "Functional, security, reliability and usability"],
  ];
  objectives.forEach(([n, heading, detail], index) => {
    const col = index % 2;
    const row = Math.floor(index / 2);
    const left = 56 + col * 584;
    const top = 188 + row * 143;
    addText(slide, n, left, top, 64, 45, { fontSize: 29, color: C.tealDark, bold: true });
    addText(slide, heading, left + 76, top - 2, 440, 36, { fontSize: 27, bold: true });
    addText(slide, detail, left + 76, top + 43, 440, 56, { fontSize: 20, color: C.muted });
    addRule(slide, left, top + 113, 528, index >= 4 ? C.ink : C.rule, index >= 4 ? 1.5 : 1);
  });
  addNotes(slide, [
    "Timing: 50 seconds.",
    "Present the objectives as a chain: understand the domain, design the controls, implement the system, govern intelligent features and test the result.",
    "Objective five is deliberately conditional: evaluation requires an authorised historical dataset rather than synthetic claims.",
  ], ["docs/project-report/chapter-1.md"]);
}

// Slide 5 — three-stage process timeline. Connector first, then nodes.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Agile iterations exposed the real accounting boundaries", 5, "METHOD");
  addText(slide, "Applied system development converted each finding into a reviewable increment.", 56, 166, 1080, 36, {
    fontSize: 23,
    color: C.muted,
  });
  addRule(slide, 122, 342, 1036, C.ink, 2);
  const stages = [
    ["DISCOVER", "Problem, literature,\nrequirements", 122],
    ["BUILD", "Tenant controls, ledger,\nreports and integrations", 506],
    ["VALIDATE", "Automated evidence,\nexternal evidence gates", 890],
  ];
  stages.forEach(([label, body, left], i) => {
    addRect(slide, left, 330, 24, 24, i === 2 ? C.teal : C.ink, { geometry: "ellipse" });
    addText(slide, `0${i + 1}`, left, 242, 60, 30, { fontSize: 18, color: C.tealDark, bold: true });
    addText(slide, label, left, 275, 270, 35, { fontSize: 25, bold: true });
    addText(slide, body, left, 384, 270, 74, { fontSize: 21, color: C.muted });
  });
  addRect(slide, 56, 520, 1168, 112, C.amberPale, { lineFill: C.rule, lineWidth: 1 });
  addText(slide, "Post-design extension", 78, 545, 270, 30, { fontSize: 22, color: C.amber, bold: true });
  addText(slide, "Reconciliation was added after implementation exposed the audit need; it is not backdated into the original FR1–FR20 baseline.", 354, 538, 842, 64, {
    fontSize: 22,
    bold: true,
  });
  addNotes(slide, [
    "Timing: 45 seconds.",
    "Describe the method as applied and iterative, not as an abstract Agile checklist.",
    "Point out the reconciliation extension as an example of honest scope evolution: it was valuable, but it was documented after the original baseline rather than silently rewritten into it.",
  ], [
    "docs/project-report/chapter-3.md",
    "docs/project-report/chapter-4.md",
  ]);
}

// Slide 6 — architecture image field with interpretation rail.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Laravel governs every external event", 6, "SYSTEM ARCHITECTURE");
  addText(slide, "Family context", 56, 188, 300, 30, { fontSize: 24, color: C.tealDark, bold: true });
  addText(slide, "Every request is bound to one active family membership.", 56, 229, 310, 66, { fontSize: 20, color: C.muted });
  addText(slide, "Governed application", 56, 326, 300, 30, { fontSize: 24, color: C.tealDark, bold: true });
  addText(slide, "Policies, validation and domain services decide what may reach the ledger.", 56, 367, 310, 76, { fontSize: 20, color: C.muted });
  addText(slide, "Recoverable integrations", 56, 473, 300, 30, { fontSize: 24, color: C.tealDark, bold: true });
  addText(slide, "Paystack, messaging and AI responses remain observations—not authority.", 56, 514, 310, 76, { fontSize: 20, color: C.muted });
  addRect(slide, 398, 174, 826, 468, C.panel2, { lineFill: C.rule, lineWidth: 1 });
  const architecture = await imageBytes("docs/project-report/diagrams/slide-05-system-architecture-full-trimmed.png");
  addImage(slide, architecture, "Layered FamilyFunds architecture with actors, Laravel modules, data and external services", 416, 190, 790, 434, "contain");
  addNotes(slide, [
    "Timing: 55 seconds.",
    "Follow the request from the user interface into Laravel, where the active family, policy and subscription context are enforced.",
    "Explain that providers attest to payment, delivery or generated text; they do not own authorisation or the financial ledger.",
  ], [
    "docs/project-report/chapter-3.md",
    "docs/project-report/chapter-4.md",
    "docs/project-report/diagrams/slide-05-system-architecture-full-trimmed.png",
  ]);
}

// Slide 7 — allocation interpretation plus report diagram.
{
  const slide = presentation.slides.add();
  addHeader(slide, "One deterministic rule makes partial payments explainable", 7, "PAYMENT ALLOCATION");
  const steps = [
    ["01", "Validate the receipt", "Manual input or verified Paystack event enters the same governed service."],
    ["02", "Lock incomplete obligations", "Load family-owned balances in chronological order inside one transaction."],
    ["03", "Allocate oldest first", "Persist the batch and immutable allocation lines; repeat until exhausted."],
  ];
  steps.forEach(([n, heading, body], i) => {
    const top = 184 + i * 142;
    addText(slide, n, 56, top, 58, 36, { fontSize: 25, color: C.tealDark, bold: true });
    addText(slide, heading, 124, top, 356, 34, { fontSize: 25, bold: true });
    addText(slide, body, 124, top + 43, 360, 78, { fontSize: 19, color: C.muted });
    if (i < 2) addRule(slide, 56, top + 124, 428, C.rule, 1);
  });
  addRect(slide, 522, 174, 702, 468, C.panel2, { lineFill: C.rule, lineWidth: 1 });
  const allocation = await imageBytes("docs/project-report/diagrams/panels/slide-07-payment-allocation-flowchart-panel-2.png");
  addImage(slide, allocation, "Oldest-balance-first payment allocation loop", 598, 184, 550, 446, "contain");
  addNotes(slide, [
    "Timing: 55 seconds.",
    "Use a partial or lump-sum payment example: the oldest unpaid period is reduced first, and the service moves forward only when that balance is settled.",
    "This rule is shared by manual and online payments, which keeps reports and member statements consistent.",
  ], [
    "docs/project-report/chapter-3.md",
    "docs/project-report/chapter-4.md",
    "docs/project-report/diagrams/panels/slide-07-payment-allocation-flowchart-panel-2.png",
  ]);
}

// Slide 8 — paired governance, not autonomous intelligence.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Intelligence is constrained, not autonomous", 8, "AI AND PREDICTIVE GOVERNANCE");
  addText(slide, "Controlled AI assistant", 72, 184, 470, 36, { fontSize: 29, bold: true, color: C.navy });
  addText(slide, "Family-scoped tools", 72, 249, 470, 31, { fontSize: 23, bold: true });
  addText(slide, "Authoritative data is read through permitted application queries.", 72, 287, 470, 56, { fontSize: 20, color: C.muted });
  addRule(slide, 72, 357, 470, C.rule, 1);
  addText(slide, "Preview before writes", 72, 380, 470, 31, { fontSize: 23, bold: true });
  addText(slide, "An officer must explicitly confirm a proposed action; members cannot write.", 72, 418, 470, 62, { fontSize: 20, color: C.muted });
  addText(slide, "Payment-risk advisory", 684, 184, 470, 36, { fontSize: 29, bold: true, color: C.tealDark });
  addText(slide, "Offline model, native scoring", 684, 249, 470, 31, { fontSize: 23, bold: true });
  addText(slide, "Schema, checksum and activation gates separate training from production requests.", 684, 287, 470, 56, { fontSize: 20, color: C.muted });
  addRule(slide, 684, 357, 470, C.rule, 1);
  addText(slide, "Officer-only and advisory", 684, 380, 470, 31, { fontSize: 23, bold: true });
  addText(slide, "Cold-start tiers can show unavailable; a score cannot sanction, remind or mutate records.", 684, 418, 470, 66, { fontSize: 20, color: C.muted });
  addRule(slide, 622, 180, 0, C.rule, 1);
  addRect(slide, 56, 536, 1168, 92, C.navy);
  addText(slide, "Neither path can bypass Laravel authorisation, validation or the financial ledger.", 88, 558, 1104, 48, {
    fontSize: 28,
    color: C.white,
    bold: true,
    alignment: "center",
    verticalAlignment: "middle",
  });
  addNotes(slide, [
    "Timing: 55 seconds.",
    "Contrast the two intelligent features. The assistant is a governed interface over application tools; the predictor is an advisory classifier with activation and history gates.",
    "Make the safety boundary explicit: neither feature can create a financial event or automatic reminder by itself.",
    "Do not claim live provider usefulness or predictive accuracy; those experiments were not executed.",
  ], [
    "docs/project-report/chapter-3.md",
    "docs/project-report/chapter-4.md",
  ]);
}

// Slide 9 — metric-led evidence.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Local checks passed across implemented boundaries", 9, "EXECUTED TEST EVIDENCE");
  addText(slide, "Final canonical local rerun: 15 August 2026 · Pest 22.209 s. Focused runs overlap and are not combined.", 56, 164, 1168, 48, {
    fontSize: 21,
    color: C.muted,
  });
  const panels = [
    [56, "1,421", "Pest tests", "6,904 assertions\n100.0% PHP coverage"],
    [452, "66", "Python ML tests", "100% statement coverage\nLocked Python environment"],
    [848, "138 + 5", "Boundary runs", "138 PostgreSQL tests / 440 assertions\n5 Chromium tests / 82 assertions"],
  ];
  panels.forEach(([left, stat, label, detail], index) => {
    addRect(slide, left, 260, 360, 334, index === 0 ? C.navyPale : (index === 1 ? C.tealPale : C.panel));
    addText(slide, stat, left + 28, 302, 304, 82, { fontSize: 58, bold: true, color: index === 1 ? C.tealDark : C.navy });
    addText(slide, label, left + 28, 396, 304, 34, { fontSize: 24, bold: true });
    addRule(slide, left + 28, 450, 304, C.rule, 1);
    addText(slide, detail, left + 28, 472, 304, 84, { fontSize: 19, color: C.muted });
  });
  addText(slide, "Local result only · no fresh post-change hosted SHA", 56, 623, 1168, 28, {
    fontSize: 18,
    color: C.red,
    bold: true,
    alignment: "center",
  });
  addNotes(slide, [
    "Timing: 55 seconds.",
    "Report the final canonical local Laravel rerun from 15 August 2026 first: 1,421 Pest tests, 6,904 assertions, 100.0% PHP coverage and 22.209 seconds Pest duration.",
    "Then report 66 Python tests at 100% statement coverage and the focused PostgreSQL and Chromium boundary runs.",
    "Clarify that focused runs overlap the canonical suite. These are not summed, and there is no accepted final hosted SHA.",
  ], ["docs/project-report/chapter-4.md"]);
}

// Slide 10 — verdict table, matching Chapter Four.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Design goals were met; operational and predictive claims remain open", 10, "OBJECTIVE VERDICTS");
  const col = [56, 150, 925, 1224];
  addRect(slide, 56, 174, 1168, 48, C.navy);
  ["No.", "Evidence-based outcome", "Verdict"].forEach((h, i) => addText(slide, h, col[i] + 10, 186, col[i + 1] - col[i] - 20, 25, {
    fontSize: 18,
    color: C.white,
    bold: true,
    alignment: i === 2 ? "center" : "left",
  }));
  const rows = [
    ["01", "Review relevant practice, tools and literature", "Met for report scope"],
    ["02", "Design multi-tenant family-fund system", "Met for design scope"],
    ["03", "Implement proposed system", "Partially Met overall"],
    ["04", "Integrate controlled AI features", "Partially Met"],
    ["05", "Implement and evaluate logistic regression", "Partially Met; evaluation Not Executed"],
    ["06", "Test against key requirements", "Partially Met"],
  ];
  rows.forEach((row, r) => {
    const top = 222 + r * 66;
    const isOpen = r >= 2;
    addRect(slide, 56, top, 1168, 66, r % 2 === 0 ? C.white : C.panel2);
    addRule(slide, 56, top + 65, 1168, C.rule, 1);
    addText(slide, row[0], col[0] + 10, top + 19, col[1] - col[0] - 20, 28, { fontSize: 19, color: C.tealDark, bold: true });
    addText(slide, row[1], col[1] + 10, top + 15, col[2] - col[1] - 20, 36, { fontSize: 19, bold: true });
    addText(slide, row[2], col[2] + 10, top + 13, col[3] - col[2] - 20, 42, {
      fontSize: 17,
      color: isOpen ? (r === 4 ? C.red : C.amber) : C.tealDark,
      bold: true,
      alignment: "center",
      verticalAlignment: "middle",
    });
  });
  addNotes(slide, [
    "Timing: 55 seconds.",
    "Read the verdict pattern rather than every cell. Literature and design objectives are met within their stated scope.",
    "Implementation, AI and testing are partially met because external and hosted evidence is incomplete.",
    "The predictive engineering path exists, but the evaluation itself is Not Executed because the authorised dataset was absent.",
  ], [
    "docs/project-report/chapter-4.md",
    "docs/project-report/chapter-5.md",
  ]);
}

// Slide 11 — explicit evidence boundary.
{
  const slide = presentation.slides.add();
  addHeader(slide, "Evidence gaps still limit operational claims", 11, "EVIDENCE BOUNDARY");
  const limits = [
    ["01", "Predictive evaluation", "No authorised private CSV; no threshold, coefficients, confusion matrix, model metrics or populated prediction."],
    ["02", "Hosted acceptance", "No fresh post-change hosted SHA; a699a821 remains a pre-change baseline only."],
    ["03", "Live integrations", "Paystack, messaging and AI-provider acceptance were not executed for the final revision."],
    ["04", "Operational non-functionals", "Performance, concurrency, recovery and longitudinal user evidence remain pending."],
  ];
  limits.forEach(([n, heading, detail], index) => {
    const top = 179 + index * 112;
    addText(slide, n, 56, top, 54, 36, { fontSize: 23, color: C.red, bold: true });
    addText(slide, heading, 126, top, 310, 34, { fontSize: 25, bold: true });
    addText(slide, detail, 454, top - 1, 770, 72, { fontSize: 20, color: C.muted });
    addRule(slide, 56, top + 86, 1168, index === 3 ? C.ink : C.rule, index === 3 ? 1.5 : 1);
  });
  addRect(slide, 56, 618, 1168, 42, C.tealPale);
  addText(slide, "Interface evidence captured: 20 redacted synthetic-tenant images, Figures 4.1a–c through 4.14; prediction remains inactive and advisory.", 76, 626, 1128, 27, {
    fontSize: 16,
    color: C.tealDark,
    bold: true,
    alignment: "center",
  });
  addNotes(slide, [
    "Timing: 50 seconds.",
    "A complete 20-image redacted synthetic-tenant interface set now covers Figures 4.1a–c through 4.14; interface evidence is no longer listed as a gap.",
    "State the remaining limitations directly. Live external/provider, hosted, performance, recovery and authorised-data model evidence remain pending.",
    "The predictive software can be tested without claiming that a useful real-world model has been trained.",
    "These gaps define the next acceptance work and prevent overstatement in the conclusion.",
  ], [
    "docs/project-report/chapter-4.md",
    "docs/project-report/chapter-5.md",
  ]);
}

// Slide 12 — sparse close.
{
  const slide = presentation.slides.add();
  slide.background.fill = C.white;
  addText(slide, "CONCLUSION", 58, 46, 300, 24, { fontSize: 15, color: C.tealDark, bold: true });
  addText(slide, "FamilyFunds turns informal contribution practice into governed, traceable records.", 58, 137, 982, 175, {
    fontSize: 56,
    bold: true,
    name: "closing-thesis",
  });
  addText(slide, "The conventional application is substantially implemented and locally verified. Operational readiness and predictive usefulness remain intentionally gated by evidence.", 58, 344, 916, 102, {
    fontSize: 26,
    color: C.muted,
  });
  addRule(slide, 58, 510, 760, C.ink, 1.5);
  addText(slide, "Secure tenancy  ·  Deterministic ledger  ·  Governed intelligence", 58, 538, 900, 34, {
    fontSize: 23,
    color: C.navy,
    bold: true,
  });
  addText(slide, "Questions", 894, 524, 330, 72, { fontSize: 48, bold: true, color: C.tealDark, alignment: "right" });
  addText(slide, "AMINU DANLADI HUSSAIN  ·  MIVA OPEN UNIVERSITY", 58, 650, 650, 22, { fontSize: 15, color: C.muted, bold: true });
  addText(slide, "12", 1180, 675, 44, 20, { fontSize: 14, color: C.muted, bold: true, alignment: "right" });
  addNotes(slide, [
    "Timing: 35–40 seconds, then invite questions.",
    "Resolve the opening problem: one governed system can replace fragmented family-fund records and apply consistent rules.",
    "Close with the measured verdict. The core implementation is locally supported; operational and predictive claims remain gated until their declared evidence exists.",
  ], [
    "docs/project-report/chapter-5.md",
  ]);
}

await fs.mkdir(RENDER, { recursive: true });
for (const [index, slide] of presentation.slides.items.entries()) {
  const stem = `slide-${String(index + 1).padStart(2, "0")}`;
  const png = await presentation.export({ slide, format: "png", scale: 2 });
  await fs.writeFile(`${RENDER}/${stem}.png`, new Uint8Array(await png.arrayBuffer()));
  const layout = await slide.export({ format: "layout" });
  await fs.writeFile(`${RENDER}/${stem}.layout.json`, await layout.text());
}

const montage = await presentation.export({ format: "webp", montage: true, scale: 1 });
await fs.writeFile(`${RENDER}/deck-montage.webp`, new Uint8Array(await montage.arrayBuffer()));

const pptx = await PresentationFile.exportPptx(presentation);
await pptx.save(OUT);

const inspection = await presentation.inspect({
  kind: "slide,textbox,shape,image,notes",
  maxChars: 20000,
});
await fs.writeFile(`${RENDER}/inspection.ndjson`, inspection.ndjson);

console.log(JSON.stringify({ output: OUT, slides: presentation.slides.items.length, renderDir: RENDER }));
