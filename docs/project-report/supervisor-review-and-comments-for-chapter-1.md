# Critical Review

# Chapter 1

**On the Topic**

The title is acceptable in scope for a 400-level capstone. However, it is verbose. Consider the cleaner
form:

**"An AI-Enhanced Multi-Tenant Web Application for Family Fund Management: Predictive
Analytics and Intelligent Reporting"**

This removes the redundant "Design and Implementation of" (implied by the project type) and reads
better academically.

**Section-by-Section Issues**

```
# Location Issue Recommended Correction / Action
```
```
1 §1.1,
Para. 1
```
```
Adashi is the only local financial term
left undefined — supervisor
Comment [2] flags this directly
```
```
Add inline: "adashi (a Hausa term for a
fixed-cycle rotating savings and credit
association among members)"
```
```
2 §1.1,
Para. 3– 4
```
```
The background pivots abruptly from
the problem context to multi-tenancy
and AI without explicitly stating the
gap these technologies fill — this is
what supervisor Comment [1]
("Ensure to justify your work") is
pointing to
```
```
Add a bridging sentence such as: "No
existing platform specifically addresses
the combined requirements of multi-
group isolation, tiered contributions, and
intelligent reporting for informal family
fund management" — before introducing
the technologies
```
```
3 §1.2,
Para. 1
```
```
The problem statement lacks
empirical grounding; it asserts that
the problem is widespread but
provides no data (survey figures,
reported incidents, usage statistics) to
substantiate it
```
```
Supplement at least the opening
paragraph with a quantitative reference
or survey-based citation demonstrating
the prevalence of manual family fund
management in Nigeria
```
```
4 §1.2 WhatsApp-based management and
manual notebooks are described again
here after already being raised in §1.
```
```
Remove repeated points from §1.2; the
Statement of the Problem should extend
the background, not echo it
```
```
5 §1.3,
Objective
5
```
```
Objective 5 bundles two distinct
deliverables: (a) LLM-based narrative
reporting and (b) WebAuthn + 2FA
security — these are separate in
scope, design, and implementation
```
```
Split into two objectives: Objective 5 →
intelligent LLM reporting; Objective 6
→ security implementation (WebAuthn,
2FA). This also aligns better with the
scope items listed in §1.
```
```
6 §1.3 There is no Research
Questions section between the
objectives and the scope — this is
typically required in this format
```
```
Add a subsection (§1.3b or between §1.
and §1.4) with 3–5 research questions
derived directly from the objectives
(e.g., "To what extent can machine
```

```
learning techniques accurately predict
member default behaviour in a family
fund context?" )
```
7 §1.3,
Table 1.

```
The table is labelled Table 1.2 but
no Table 1.1 exists anywhere in the
chapter
```
```
Either renumber to Table 1.1 , or insert a
Table 1.1 earlier (e.g., a comparative
summary of existing platforms in §1.2)
to justify the numbering
```
8 §1.4,
Table 1.

```
Vite is listed as v8 — as of 2026,
Vite's stable release is in the v5–v
range; v8 does not exist
```
```
Correct to the actual current stable
version of Vite at the time of writing
```
## 9 §1.4,

```
Table 1.
```
```
"ML/Predictive
Analytics" and "LLM
Integration" are both listed as "To be
determined" — these are the two
features that distinguish this project
from a standard CRUD application
```
```
These must be resolved by Chapter 3.
For Chapter 1, state at minimum the
candidate options and the selection
criteria (e.g., "Python/scikit-learn is
preferred for its mature classification
libraries; a final decision is pending
feasibility assessment" )
```
10 §1.4,
Table 1.

```
"Authentication: WebAuthn v1 /
Custom" — WebAuthn is a W3C
specification, not a versioned PHP
library; "v1 / Custom" is ambiguous
```
```
Specify the actual package to be used
(e.g., laragear/webauthn) and its version.
Replace "v1" with the package name
```
11 §1.4 The **"Planned"** label on Predictive
Analytics and Intelligent Reporting in
the scope is transparent and
acceptable, but it creates tension with
the title which positions these as core
deliverables

```
Add a clarifying sentence in the scope
section: "These modules are
architectural targets; their completion is
subject to data availability and API
integration timelines"
```
## 12 §1.8,

```
Table 1.
```
```
The table is labelled Table
1.5 but Table 1.4 is absent from the
chapter
```
```
Audit all table numbers; renumber
consecutively from Table 1.1. Table 1.
appears to be missing from §1.5 or §1.
```
13 §1.9 The report is described as **"organised
into three chapters"** — the standard
undergraduate project structure is five
chapters (Introduction; Literature
Review; Methodology;
Results/Implementation; Conclusion
& Recommendations)

```
Update §1.9 to project five chapters and
expand the description of Chapters 4
(System Implementation and Testing)
and 5 (Conclusion and
Recommendations)
```
14 §1.6 The motivation is detailed and
genuine but is written in a very
personal, narrative tone that may not
suit formal academic writing in some
institutional styles

```
Retain the personal basis but reframe the
paragraphs in third person or semi-
formal tone (e.g., "The researcher's
membership in a Nigerian family
operating a collective contribution fund
provided direct observational access
to..." ) — confirm your institution's
preferred style
```

```
15 General
— all
tables
```
```
Track-change markup (purple
strikethrough text visible in Table 1.
image) remains in the document
```
```
Accept or reject all tracked changes
before submission; do not submit with
visible markup
```
**Summary of Critical Priorities**

The three issues most important to resolve before Chapter 2 is written:

1. **Address the justification gap** (Comment [1]) — the background must explicitly establish the
    gap before pivoting to the solution.
2. **Split Objective 5** and add a Research Questions section — the objectives as written do not
    map cleanly to the scope or to what will become the evaluation criteria in Chapter 3.
3. **Fix all table numbering** — Tables 1.1 and 1.4 are missing; this will compound into a
    consistency problem across subsequent chapters.


