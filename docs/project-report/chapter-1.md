# CHAPTER ONE

## INTRODUCTION

### 1.1 Background of the Study

Long before colonial banks opened their doors in West Africa, families and small groups were already pooling money together. Aryeetey (2008) documents these arrangements going back centuries across Sub-Saharan Africa. Nigeria alone has several culturally distinct versions. The Yoruba have *ajo*—everyone puts in a fixed amount on a regular schedule, and members take turns collecting the pot. Then there is *esusu*, also Yoruba, which works similarly but adds a designated treasurer to oversee how the money gets disbursed (Akanji, 2006). In the north, the Hausa equivalent goes by *adashi*: a fixed-cycle system where members pool what they have for collective or sequential payouts (Iganiga & Asemota, 2008). These are not purely financial mechanisms. They run on reciprocal obligation, on trust, on the kind of communal identity that holds extended families and neighbourhoods together (Oloyede, 2008).

But there is another form of collective finance that gets far less scholarly attention—the **family contribution fund**, sometimes called the family purse. This arrangement works differently from *ajo* or *esusu*. The funds do not rotate. Instead, family members pay into a **non-rotating, centrally managed treasury** every month, and the money stays pooled until it is needed for shared expenses: funerals, hospital bills, school fees, family celebrations, or property upkeep (Oloyede, 2008; Apt, 2002). Typically, one person—an elder or someone elected as financial secretary—keeps the records, collects the money, tracks who has paid and who has not, and gives the family periodic updates.

The trouble is that almost all of this management happens manually. The financial secretary writes contributions down in a notebook, tracks payments on a personal spreadsheet, or posts updates in a WhatsApp group (Aker & Mbiti, 2010). These methods work tolerably at small scale but create real problems as the group grows or persists over time: arithmetic mistakes creep in, records get lost, there is no audit trail, and disputes over balances become difficult to resolve because there is no single authoritative source of truth (Adeyemo & Bamire, 2005). Scatter the family across Lagos, Abuja, London, and Houston—which is perfectly normal given Nigeria's rural-urban and diaspora migration patterns (Osili, 2007)—and the whole thing becomes nearly impossible to manage.

Here is what makes the situation frustrating: Nigeria's fintech sector has grown enormously. There are apps for saving, apps for lending, apps for investing. But not one of them is built for this particular use case. None of them combine multi-group data isolation with tiered contribution structures, role-based governance, and intelligent reporting—which is exactly what family fund management demands. That gap is what this project exists to fill.

The good news is that the technology to solve this problem properly already exists—it just has not been pointed at this particular use case. **Multi-tenant architectures** let a single Software as a Service (SaaS) application serve dozens or hundreds of independent groups, each with its own walled-off data. That is precisely the trust boundary family funds need: my family's finances are invisible to yours, even though we are on the same platform (Bezemer & Zaidman, 2010; Chong et al., 2006). And building something like this no longer requires an enterprise budget. Laravel handles the backend; Vue.js handles the frontend. Both are mature, well-documented, and free (Otwell, 2024; You, 2024).

At the same time, **artificial intelligence** offers capabilities that go beyond what any manual bookkeeper could provide. Machine learning models trained on historical payment data can flag members who are likely to miss their next contribution, giving administrators a chance to follow up before a shortfall develops (Khandani et al., 2010; Lessmann et al., 2015). And **Large Language Models (LLMs)** can take the structured data from monthly financial records and turn it into plain-English summaries—reports that any family member can read and understand, regardless of whether they have any background in accounting or finance (Brown et al., 2020; OpenAI, 2023).

So the pieces are all there: a widespread practice that families care about deeply, manual methods that keep failing them, and technology that could actually fix the problem if someone built the right thing. That is what this project does. FamilyFunds is a secure, role-governed, AI-enhanced platform for tracking contributions, processing payments, managing expenses, predicting payment behaviour, and generating financial reports that anyone can read.

### 1.2 Statement of the Problem

Managing collective financial contributions within Nigerian families is harder than it looks. It is not just bookkeeping. Governance, trust, who has access to what, the mismatch between how families actually work and the digital tools on offer—all of it feeds the problem. Consider the scale: the World Bank Global Findex Database reports that roughly 55% of Nigerian adults who save do so informally, outside the banking system entirely (Demirgüç-Kunt et al., 2022). EFInA (2023) puts a sharper number on it—over 36 million adults depend on informal financial groups for their savings and credit. And yet, no mainstream digital tool has been built specifically for family funds.

The problems break down into six interrelated areas.

**Record-keeping fragility.** Most family funds run on notebooks, personal spreadsheets, or WhatsApp messages. These methods lack the immutability and computational capability that reliable financial administration requires (Adeyemo & Bamire, 2005). When someone makes a partial payment, there is no automatic way to track the remaining balance, link it to the right month, or ensure the record cannot be altered later. Discrepancies pile up quietly, and by the time they surface—usually at a family meeting—they have already damaged trust (Aryeetey, 2008).

**Absence of role-based governance.** In most family groups, there are no formal rules about who can record payments, who can approve expenses, and who simply has read-only visibility into the accounts. Everyone either trusts the financial secretary completely or has no recourse if something goes wrong. This lack of structured access control creates opportunities for mismanagement—sometimes deliberate, sometimes just careless—and concentrates risk on a single person (Aryeetey, 2008; Bouman, 1995).

**Payment allocation complexity.** Family members rarely contribute identical amounts. An employed professional might pay ₦4,000 monthly while a student pays ₦1,000. Payments may arrive late, partially, or in lump sums covering several months at once. Deciding how to allocate an incoming payment—against the oldest unpaid balance or the current month—is a non-trivial problem that most manual systems handle inconsistently, if they handle it at all (Aryeetey, 2008).

**No suitable digital platform exists.** PiggyVest and Cowrywise serve individual savers. CreditClan and Lendsqr target formal lending cooperatives with regulatory requirements that have nothing to do with an informal family group (PiggyVest, 2024; Cowrywise, 2024; CreditClan, 2024). Table 1.1 maps the gap concretely—none of these platforms offer multi-tenant group isolation, tiered contribution management, RBAC, or partial payment allocation together. The family fund use case falls through the cracks.

**No predictive or analytical tools.** Family fund administrators only find out someone has not paid after the deadline has already passed. They cannot forecast defaults, spot patterns, or see a shortfall coming (Khandani et al., 2010). Without data to work with, proactive management is not just difficult—it is not possible.

**Reporting that nobody can read.** Even the families that bother with spreadsheets struggle to turn them into summaries that every member actually understands. Large Language Models could convert structured financial data into plain-English narrative reports, but nobody has tried that in this domain (Brown et al., 2020).

*Table 1.1: Comparison of Existing Financial Management Platforms in Nigeria*

| Feature | PiggyVest | Cowrywise | CreditClan | Lendsqr | FamilyFunds (This Project) |
| --- | --- | --- | --- | --- | --- |
| Target Users | Individuals | Individuals | Formal cooperatives | Formal lenders | Family groups |
| Multi-Tenant Group Isolation | No | No | No | No | Yes |
| Tiered Contribution Management | No | No | No | No | Yes |
| RBAC (Admin / Secretary / Member) | No | No | Limited | Limited | Yes |
| Partial Payment Allocation | No | No | No | No | Yes |
| Integrated Payment Gateway | Yes | Yes | Yes | Yes | Yes |
| Predictive Analytics | No | No | No | Yes | Yes (Planned) |
| LLM Narrative Reports | No | No | No | No | Yes (Planned) |
| Family Fund Specific | No | No | No | No | Yes |

Table 1.1 makes the gap visible. What is needed is a purpose-built, intelligent, multi-tenant web application that brings together structured governance, automated payment tracking with smart allocation, online payment support, predictive analytics, and AI-generated financial reports—all designed specifically for family fund management.

### 1.3 Aim, Objectives, and Research Questions of the Study

#### Aim

This project aims to design and implement FamilyFunds—an AI-enhanced multi-tenant web application for family fund management. The system will provide automated contribution tracking, intelligent payment allocation, machine learning-based prediction of member payment behaviour, and LLM-powered narrative financial reporting.

#### Objectives

The specific objectives are to:

1. **Review** existing digital platforms for informal group and cooperative financial management, assess their limitations in addressing family-level contribution fund requirements, and establish functional design criteria for the proposed system.

2. **Design** a multi-tenant web application architecture incorporating logical tenant data isolation, a three-tier role-based access control model, and modules for contribution tracking, payment processing, predictive analytics, and intelligent narrative reporting.

3. **Develop** the designed system using Laravel, Vue.js, and Inertia.js, integrating the Paystack payment gateway, a machine learning-based payment behaviour prediction engine, an LLM-powered report generation module, and a WebAuthn/TOTP security framework.

4. **Test** the system rigorously—unit tests for individual components, feature tests for end-to-end workflows, and browser tests for real user interactions—covering functional correctness, tenant data isolation, payment allocation accuracy, and security.

5. **Evaluate** whether the finished system actually meets the requirements identified in Objective 1, paying particular attention to how well the predictive analytics perform and whether the LLM-generated reports are coherent and useful.

#### Research Questions

Five questions drive the investigation. Each one connects back to at least one of the objectives listed above.

1. What architectural decisions does a multi-tenant system need to get right in order to guarantee data isolation and enforce role-based access control across independent family groups sharing the same platform?

2. Does an automated oldest-balance-first allocation algorithm actually improve contribution tracking accuracy compared to how families do it manually?

3. When trained on historical payment records from a family contribution fund, how accurately can machine learning models predict which members are likely to default?

4. Can LLM technology generate financial reports that make sense to family members who have never studied accounting or finance?

5. For a multi-tenant family fund management system handling real money and personal data, which security mechanisms provide the most effective protection?

### 1.4 Scope of the Study

What follows draws the boundaries of the system—what it does, what it runs on, and what it deliberately leaves out.

#### Functional Scope

Every family group gets its own isolated tenant on the platform. Members, contribution schedules, categories, financial records—all of it lives within the family's boundary, invisible to every other family on the system. An administrator can configure the basics: family name, currency, the day contributions are due, bank account details. No family sees another family's data. That isolation is not optional; it is enforced at the application level.

Within each family, three roles govern who can do what. Administrators handle the big-picture work—managing members, adjusting settings, configuring contribution categories. Financial Secretaries deal with the operational side: recording payments, logging expenses, pulling reports. Members get read-only access to their own contribution history, plus the ability to pay online. The system enforces these boundaries. A member cannot record a payment for someone else. A Financial Secretary cannot change the family's settings.

Contributions are generated automatically each month for every paying member, based on their assigned category. An employed member might owe ₦4,000, an unemployed one ₦2,000, a student ₦1,000. When payments come in—and they often arrive partial, late, or lumped together covering several months—the system applies them oldest-balance-first. The allocation is deterministic. No human judgment calls, no ambiguity about which month got credited.

Payments flow through two channels. Authorised roles can record them manually for members who pay cash or transfer outside the platform. Members can also pay directly through Paystack. Both channels feed the same allocation engine, so records stay consistent regardless of how the money actually arrives.

Expenses get recorded with descriptions and dates. The system also handles fund adjustments—non-contribution inflows like donations or interest—and computes the overall fund balance: total payments minus total expenses, adjusted for any non-contribution money that came in.

On the reporting side, the system produces monthly and annual financial summaries covering collection rates, category breakdowns, and individual member statuses. Both tabular data and summary statistics.

Two modules are planned but depend on what proves feasible during development. The first is a **predictive analytics** component—machine learning that would analyse historical payment data to flag likely defaults and surface payment trends. The second is **intelligent reporting**—LLM-generated narrative summaries written in plain language for family members who are not comfortable reading spreadsheets or charts. Both are architectural targets. Whether they ship in full depends on data availability and API integration timelines.

Security covers several layers: email-and-password login with email verification, TOTP-based two-factor authentication for an extra layer, and WebAuthn passkey support for passwordless or biometric-assisted login. A super-administrator role at the platform level provides system-wide oversight across all family tenants.

New members join through email invitations with tokenised acceptance links. Existing families can onboard someone and pre-assign their role before that person even creates an account.

The platform itself runs on tiered subscription plans—Free, Starter, Pro, Enterprise—that control how many members a family can have and which features they can access. Billing goes through Paystack.

#### Technical Scope

*Table 1.2: Technical Stack Overview*

| Layer | Technology | Version |
| --- | --- | --- |
| Backend Framework | Laravel (PHP) | v13 |
| Frontend Framework | Vue.js | v3.5 |
| Server-Client Bridge | Inertia.js | v3 |
| Database | PostgreSQL | Latest stable |
| CSS Framework | Tailwind CSS | v4 |
| Payment Gateway | Paystack API | Current |
| Authentication | Laravel Fortify | v1 |
| WebAuthn | Custom (web-auth/cose-lib, @simplewebauthn/browser) | v4.5 / v13.3 |
| Build Tool | Vite | v8 |
| Type System | TypeScript | v5 |
| Route Generation | Laravel Wayfinder | v0 |
| Code Quality | Laravel Pint, ESLint, Prettier | Latest |
| Testing | Pest PHP | v4 |
| ML/Predictive Analytics | Laravel AI SDK (preferred for native integration); Python/scikit-learn (candidate for granular model control) | v0.5 (selection pending feasibility assessment) |
| LLM Integration | Laravel AI SDK with OpenAI GPT-4 or Anthropic Claude providers | v0.5 (selection pending API evaluation) |

#### Exclusions

The following fall outside this project's scope:

- Native mobile apps for iOS or Android. The system is a responsive web application that works in mobile browsers.
- Cryptocurrency or blockchain-based payments.
- Regulatory compliance for formal cooperative societies (CAMA registration, CBN guidelines).
- Tax computation, invoicing, or full accounting functionality.
- Multilingual support. The interface and reports are English-only.
- Banking API integrations beyond Paystack (no direct bank transfers, no USSD).

### 1.5 Significance of the Study

Start with what matters most: families. Millions of Nigerian families run contribution funds, and the way most of them manage the money—notebooks, personal spreadsheets, WhatsApp messages—is fragile. This project replaces that fragility with an automated, auditable system where roles are clearly defined and technically enforced. Administrators run the group. Financial Secretaries handle payments and expenses. Members check their own records and pay online. That structure alone goes a long way toward addressing the disputes and mistrust that scholars like Aryeetey (2008) and Oloyede (2008) have identified as existential threats to informal family financial arrangements. And because the platform is multi-tenant by design, it is not limited to one family. A workplace savings club, a church group, a community development association—any of them could adopt it with minimal modification.

Academically, this project walks into relatively thin territory. The credit scoring literature has spent two decades refining models for banks and formal lenders (Lessmann et al., 2015). Applying machine learning to predict payment behaviour in a family contribution context—where the consequences of default are social embarrassment and strained relationships rather than credit bureau flags—is something nobody has published on. The same is true for using LLMs to generate financial reports outside corporate and regulatory settings; it contributes to a small but growing body of work on natural language generation for everyday financial communication (Brown et al., 2020). For future software engineering students, the project also doubles as a concrete, replicable case study in multi-tenant SaaS architecture.

From an industry standpoint, the implementation showcases a production-grade multi-tenant pattern on Laravel: application-level isolation through middleware, policy-based authorisation, and explicit query scoping—no global scopes, no magic. Layering Paystack integration, WebAuthn passwordless login, and TOTP-based two-factor authentication within the same application reflects current best practice in Nigerian and African fintech development (Paystack, 2024). Anyone building a similar platform in the Laravel and Vue.js ecosystem can treat this as a reference architecture.

The social impact argument is simple. Families whose members are scattered across different cities or countries—and that describes a huge share of Nigerian families, given migration patterns—stand to benefit the most. Automated reminders, early-warning signals from predictive models, and transparent reporting target exactly the friction points that cause informal family funds to fall apart. These funds matter. For many families, they are the first safety net available. Sometimes the only one.

### 1.6 Motivation for the Study

This project grew out of a real problem the researcher faces personally. The researcher's family in Nigeria runs a monthly contribution fund—a family purse—in which members pay different amounts depending on their employment status. For years, this fund has been managed manually: handwritten records, announcements in a messaging group, and verbal confirmations at family meetings. The results have been predictable. Disagreements about who has paid and who has not. Confusion over partial payments. Difficulty producing any kind of clear financial summary when the family meets. No way to hold the person managing the money properly accountable.

These frustrations turn out to be remarkably common. In conversations with classmates, friends, and colleagues, the researcher found that nearly every Nigerian family with a contribution arrangement faces similar problems. Some families have given up on their funds entirely because the record-keeping became too unreliable and the arguments too frequent.

As someone studying software engineering, the researcher looked at the mess and thought: I can fix this. Not as a thought experiment or a hypothetical proof-of-concept, but as an actual tool the family could start using. When your own relatives are the first users, you do not get to cut corners on requirements. Every feature maps to a real argument somebody had at a family meeting.

The technical challenge sealed the deal. Getting dozens of independent families to coexist securely on shared infrastructure—with proper data isolation, role enforcement, and auditable records—is a genuinely hard architectural problem. Stack machine learning on top for payment prediction, then add LLM-generated reports in plain language, and you have something that goes well past the typical CRUD web app. For a 400-level capstone, that felt right. It pulls together backend engineering, frontend development, data modelling, security design, and emerging AI capabilities all at once. The researcher wanted a project worth struggling with. This one qualifies.

### 1.7 Limitations of the Study

Several constraints bound what this system can do in its current form:

1. **Internet dependency.** No internet, no access. That is a real problem in parts of Nigeria where connectivity drops out regularly. Right now the system has no offline mode and does not work as a progressive web application—if the connection goes down, you wait.

2. **Paystack-only payments.** The only online payment channel is Paystack, which works best with Nigerian Naira. A family based in Ghana or Kenya, or one that deals in dollars, would need a different gateway entirely—and supporting that is not part of the current build.

3. **Cold-start problem for ML predictions.** Machine learning needs data to be useful, and a brand-new family with two months of payment records does not give the model much to work with. Predictions will be unreliable until enough history accumulates—a well-documented challenge in prediction systems of all kinds (Schein et al., 2002).

4. **LLM cost and speed.** Every time the system asks an LLM to write a report, that is an API call—and API calls cost money and take time. Whether that cost is pennies or dollars per report will determine which subscription tiers can offer the feature. The quality also varies depending on the provider (OpenAI, 2023).

5. **English only.** Everything—the interface, the buttons, the generated reports—is in English. In a country with over 500 living languages, that leaves out family members who would be more comfortable in Hausa, Yoruba, or Igbo. Adding other languages is a future goal, not a current capability.

6. **Device requirements for WebAuthn.** Not every phone or laptop has a fingerprint reader or face recognition. Passkey login only works on devices that do. For members with older hardware, the system falls back to TOTP codes from an authenticator app—functional, but not as seamless.

7. **Untested at scale.** The architecture is designed for multi-tenancy, but nobody has thrown thousands of families at it simultaneously to see what breaks. That kind of load testing is outside the scope of this project. Whether the system holds up under serious concurrent traffic is an open question.

### 1.8 Definition of Terms

*Table 1.3: Definition of Key Terms*

| Term | Definition | Reference |
| --- | --- | --- |
| **Multi-Tenancy** | One application instance serving many independent groups (tenants), where each tenant's data is kept separate through logical or physical isolation. Think of it as apartment units in a building—shared infrastructure, private spaces. | Bezemer & Zaidman (2010) |
| **Role-Based Access Control (RBAC)** | Instead of giving permissions to individual people, you assign them to roles. A user gets whatever permissions their role carries. In this project: Administrator, Financial Secretary, or Member. | Sandhu et al. (1996) |
| **Contribution** | The monthly amount each family member owes, determined by which category they fall into—employed, unemployed, or student. | — |
| **Partial Payment** | When a member pays less than the full amount owed for a given month. The unpaid remainder carries forward as an outstanding balance. | — |
| **Balance-First Allocation** | The rule the system follows when money comes in: apply it to the member's oldest outstanding month first, then work forward. No cherry-picking which month gets credited. | — |
| **Predictive Analytics** | Running statistical models and machine learning on past payment data to guess what happens next—specifically, whether a given member is heading toward a missed payment. | Khandani et al. (2010) |
| **Large Language Model (LLM)** | A neural network trained on massive amounts of text that can generate human-readable prose, answer questions, and turn raw data into narrative summaries. This project uses one to write financial reports in plain English. | Brown et al. (2020) |
| **WebAuthn** | The W3C standard that lets people log in without passwords. Instead, the browser uses public-key cryptography tied to a fingerprint reader, face scanner, or hardware security key. | W3C (2021) |
| **Two-Factor Authentication (2FA)** | Logging in with two separate proofs of identity. Usually that means a password plus a six-digit code from an authenticator app on your phone. | Ometov et al. (2018) |
| **Software as a Service (SaaS)** | Software that lives on someone else's server and you access through a browser. No installation, no updates to manage locally—just a subscription and an internet connection. | Chong et al. (2006) |
| **Single Page Application (SPA)** | A web app that loads once and then updates itself dynamically as you interact with it, rather than reloading a fresh page every time you click something. Feels more like a desktop app than a traditional website. | Mikowski & Powell (2013) |
| **Inertia.js** | The glue between Laravel on the server and Vue.js in the browser. It lets you build an SPA that is actually driven by server-side routing—no separate REST API needed. | Reinink (2024) |
| **Application Programming Interface (API)** | The agreed-upon way two pieces of software talk to each other. Here, APIs connect the system to Paystack for processing payments and to external LLM services for generating reports. | Fielding (2000) |
| **Payment Gateway** | The middleman that handles electronic transactions between a buyer, a seller, and their respective banks. Paystack fills this role in the system. | Paystack (2024) |

### 1.9 Organization of the Report

This report is structured in five chapters.

**Chapter One** sets out the background, problem statement, objectives, research questions, scope, significance, motivation, limitations, and key definitions—establishing what the project is about and why it matters.

**Chapter Two** reviews the literature across several thematic areas: informal finance, multi-tenant architecture, RBAC, payment systems, machine learning for default prediction, LLM-based reporting, web authentication, and SPA frameworks. It draws on at least fifty scholarly sources, identifies the gaps in existing research, and establishes how this project addresses them.

**Chapter Three** covers the system design and methodology—requirements specification, the development methodology (Agile), system architecture, database design, programming tools, module descriptions, and security measures. The methodology choices are theoretically grounded in the framework established in Chapter Two.

**Chapter Four** is where the code meets reality. It walks through how each module was actually built and wired together, then documents the testing strategy—unit tests, feature tests, browser tests with Pest PHP—and shows the results. Screenshots, test outputs, the evidence that things work.

**Chapter Five** steps back and asks: did it work? It summarises the findings, evaluates the system against the objectives from Chapter One, states what this project contributes to the field, and suggests where future developers could take it next.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
