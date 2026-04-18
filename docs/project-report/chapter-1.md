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

As someone studying software engineering, the researcher saw an opportunity to build something that would actually solve this problem—not as a theoretical exercise but as a working tool that the researcher's own family could use. That personal stake matters. It means the requirements are grounded in lived experience rather than hypothetical user stories.

Beyond the immediate practical motivation, the project offered an intellectually compelling challenge. Building a multi-tenant platform where dozens of independent families operate securely on shared infrastructure is a non-trivial architectural problem. Layering machine learning for payment prediction and LLM-based report generation on top of that pushes the project well beyond a standard database-backed web application into the territory of intelligent systems. The combination of SaaS architecture and applied AI in an informal finance context is what makes this project interesting as a 400-level capstone—it demands competence across backend engineering, frontend development, data modelling, security, and emerging AI capabilities.

### 1.7 Limitations of the Study

Several constraints bound what this system can do in its current form:

1. **Internet dependency.** Everything requires an active connection. In parts of Nigeria where internet access is unreliable, this is a genuine barrier. The system does not currently work offline or as a progressive web application.

2. **Paystack-only payments.** Online payments go exclusively through Paystack, which is optimised for the Nigerian Naira ecosystem. Families in other countries or currencies would need a different gateway, and that is outside the current scope.

3. **Cold-start problem for ML predictions.** The predictive analytics module needs a meaningful volume of historical payment data to produce useful results. Newly registered families with only a few months of records will not get reliable predictions until enough data accumulates—a well-known limitation in prediction systems generally (Schein et al., 2002).

4. **LLM cost and speed.** Generating narrative reports through external LLM APIs costs money per request and introduces latency. How much this costs in practice will affect which subscription tiers get access to the feature, and the quality of the output depends on whichever LLM provider the system is connected to (OpenAI, 2023).

5. **English only.** The interface and all generated reports are in English. Nigeria is linguistically diverse, and some family members—particularly older ones—might prefer their native language. Multilingual support is a future consideration, not a current feature.

6. **Device requirements for WebAuthn.** Passkey-based authentication requires devices with biometric sensors or platform authenticators (fingerprint readers, face recognition, etc.). Members whose devices lack these capabilities fall back to TOTP-based two-factor authentication instead.

7. **Untested at scale.** The multi-tenant design supports multiple families on one platform instance, but large-scale load testing with thousands of concurrent tenants has not been performed. Performance under heavy concurrent use remains to be evaluated.

### 1.8 Definition of Terms

*Table 1.3: Definition of Key Terms*

| Term | Definition | Reference |
| --- | --- | --- |
| **Multi-Tenancy** | A software architecture where one application instance serves multiple independent user groups (tenants) with logical or physical data isolation between them. | Bezemer & Zaidman (2010) |
| **Role-Based Access Control (RBAC)** | An access control method where permissions are assigned to roles rather than individual users. Users inherit the permissions of whatever role they are assigned. | Sandhu et al. (1996) |
| **Contribution** | A periodic (usually monthly) financial obligation assigned to each member of a family group, with the amount set by the member's category or tier. | — |
| **Partial Payment** | A payment that covers only part of a member's outstanding contribution for a given period, leaving a remaining balance to be paid later. | — |
| **Balance-First Allocation** | An algorithm that applies incoming payments to the member's oldest unpaid contribution first, then moves forward chronologically through remaining outstanding periods. | — |
| **Predictive Analytics** | Using statistical methods and machine learning to analyse historical data and forecast future outcomes—in this case, whether a member is likely to default on their contribution. | Khandani et al. (2010) |
| **Large Language Model (LLM)** | A deep learning model trained on large text datasets that can generate coherent natural language, answer questions, and transform data into readable text. Used here to produce narrative financial summaries. | Brown et al. (2020) |
| **WebAuthn** | A W3C web standard for passwordless authentication using public-key cryptography. Users authenticate with biometric sensors, security keys, or device-level platform authenticators. | W3C (2021) |
| **Two-Factor Authentication (2FA)** | A security approach requiring two separate forms of identification—typically a password plus a time-based one-time code from an authenticator app or device. | Ometov et al. (2018) |
| **Software as a Service (SaaS)** | A model where software is hosted by a provider and accessed over the internet on a subscription basis, with no local installation required. | Chong et al. (2006) |
| **Single Page Application (SPA)** | A web application that loads a single HTML page and updates content dynamically without full page reloads, creating a smoother user experience similar to a desktop application. | Mikowski & Powell (2013) |
| **Inertia.js** | A library that connects server-side frameworks like Laravel directly to client-side frameworks like Vue.js, enabling server-driven single-page applications without a separate REST API layer. | Reinink (2024) |
| **Application Programming Interface (API)** | A defined set of protocols and tools that allow different software systems to communicate. In this project, APIs connect the system to Paystack for payments and to LLM services for report generation. | Fielding (2000) |
| **Payment Gateway** | A service that processes electronic payment transactions between buyers, sellers, and their banks. Paystack is the gateway used in this system. | Paystack (2024) |

### 1.9 Organization of the Report

This report is structured in five chapters.

**Chapter One** sets out the background, problem statement, objectives, research questions, scope, significance, motivation, limitations, and key definitions—establishing what the project is about and why it matters.

**Chapter Two** reviews the literature across several thematic areas: informal finance, multi-tenant architecture, RBAC, payment systems, machine learning for default prediction, LLM-based reporting, web authentication, and SPA frameworks. It draws on at least fifty scholarly sources, identifies the gaps in existing research, and establishes how this project addresses them.

**Chapter Three** covers the system design and methodology—requirements specification, the development methodology (Agile), system architecture, database design, programming tools, module descriptions, and security measures. The methodology choices are theoretically grounded in the framework established in Chapter Two.

**Chapter Four** documents the actual implementation. It covers how each module was coded and integrated, the testing strategy (unit tests, feature tests, browser tests using Pest PHP), and the results of testing with evidence of working functionality.

**Chapter Five** wraps up with a summary of findings, an evaluation of how well the objectives were met, the contributions of the study, and recommendations for future development.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
