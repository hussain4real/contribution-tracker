# CHAPTER ONE

## INTRODUCTION

### 1.1 Background to the Study

Digital finance has changed how people save, transfer money, and keep records, yet many collective financial practices still depend on informal administration. Across Sub-Saharan Africa, households and small groups continue to rely on cooperative saving, rotating contributions, mutual aid, and family-level support systems because these arrangements are close to the people who use them and are flexible enough to respond to emergencies. Recent studies on financial inclusion still show that informal savings and group-based financial arrangements remain relevant in Nigeria, especially where formal products are too expensive, too distant, or not trusted enough for everyday use (Demirgüç-Kunt et al., 2022; EFInA, 2023; Kouandou & Zeh, 2024).

Nigeria has a long history of collective finance. The Yoruba *ajo* and *esusu* systems, the Hausa *adashi*, and similar community-based arrangements are usually built on periodic contributions, social pressure, and shared expectations of fairness (Akanji, 2006; Iganiga & Asemota, 2008; Oloyede, 2008). Some of these systems are rotating, where members take turns receiving the pooled amount. Others are non-rotating and exist mainly as a common purse for emergencies, ceremonies, school fees, medical bills, property maintenance, or family welfare. This second arrangement is the focus of this project. It is referred to in this study as a family fund or family purse.

Although family funds are socially important, their administration is still mostly manual. A family may keep a notebook, a spreadsheet, a WhatsApp thread, or a mixture of all three. These tools are convenient at first, but they are weak as financial records. They do not enforce roles, they do not allocate partial payments consistently, they do not produce reliable audit trails, and they are difficult to review when disputes arise. A payment screenshot posted in a messaging group may prove that money was sent, but it does not show which month the payment covers, whether it clears an old balance, or whether the family treasurer has updated the record correctly.

At the same time, Nigeria's digital financial services market has grown rapidly. Payment gateways and savings platforms have made online transactions familiar to many users, while the policy discussion around financial inclusion increasingly recognises digital channels as a route to wider access (Wezel & Ree, 2023). However, most popular platforms are designed either for individual savers or for formal lending and cooperative structures. PiggyVest and Cowrywise support personal saving and investment; CreditClan and Lendsqr focus more on formal lending infrastructure. None of these tools is designed primarily for informal family contribution funds with tiered member contributions, family-level roles, partial payments, expenses, reminders, and transparent reporting.

Modern software architecture makes it possible to address this gap. A multi-tenant web application can allow many families to use one platform while keeping each family's data logically separate. Role-based access control can distinguish the work of a family administrator, a financial secretary, and an ordinary member. Payment gateways such as Paystack can support online contributions, while PostgreSQL can provide structured relational storage for members, contributions, payments, expenses, and reports. In addition, recent advances in artificial intelligence make it possible to add controlled assistant features and plain-language report summaries, provided the system protects financial data and avoids unsupported claims in AI output (Brown et al., 2020; Kang & Liu, 2023).

This study therefore proposes FamilyFunds, an AI-enhanced multi-tenant family fund management system. The project is not merely a record book moved online. It is a structured system for managing contribution categories, generating monthly obligations, recording online and offline payments, allocating partial payments to the oldest outstanding balances first, tracking expenses and adjustments, producing reports, and giving authorised users secure access to the information they need. The work is timely because family funds remain socially useful, manual administration remains fragile, and the available digital tools still do not reflect the way many Nigerian families organise shared financial responsibility.

### 1.2 Statement of the Problem

Family contribution funds are often trusted because they are managed within a family, but that same informality creates administrative weaknesses. The person keeping the records may be honest and committed, yet mistakes can still occur when payments are late, partial, or made through different channels. A notebook can be misplaced. A spreadsheet can be overwritten. A WhatsApp message can be buried under hundreds of unrelated messages. Once the record is unclear, family members may disagree about who has paid, how much is outstanding, and whether expenses were properly authorised.

The problem is not simply that family funds lack software. The deeper problem is that existing software does not fit the structure of the practice. Individual savings applications do not support shared governance. Lending platforms are built for formal credit operations rather than informal family welfare. Generic spreadsheets allow calculations but do not enforce tenant isolation, member roles, invitations, payment verification, or audit-friendly records. As a result, families continue to manage money through tools that were never designed for the trust and accountability demands of a family purse.

Table 1.1 compares common digital finance tools with the requirements of the proposed system.

*Table 1.1: Comparison of Existing Financial Management Platforms*

| Feature | PiggyVest | Cowrywise | CreditClan | Lendsqr | FamilyFunds |
| --- | --- | --- | --- | --- | --- |
| Main user focus | Individuals | Individuals | Cooperatives and lenders | Digital lenders | Family groups |
| Family workspace isolation | No | No | Limited | Limited | Yes |
| Tiered contribution categories | No | No | No | No | Yes |
| Admin, secretary, and member roles | No | No | Limited | Limited | Yes |
| Partial payment allocation | No | No | No | No | Yes |
| Expense and adjustment tracking | No | No | Limited | Limited | Yes |
| Online payment support | Yes | Yes | Yes | Yes | Yes |
| Family-readable reports | Limited | Limited | Limited | Limited | Yes |
| AI assistant or narrative summaries | No | No | No | No | Yes |
| Predictive analytics | No | No | No | Yes | Planned |

The specific problem addressed in this study is the absence of a purpose-built, secure, and intelligent digital system for managing informal family contribution funds. Existing tools do not combine multi-tenant family separation, role-based governance, tiered contribution tracking, deterministic payment allocation, expense management, online payment support, and understandable reporting in one system. This gap exposes families to inaccurate records, weak accountability, delayed follow-up, and avoidable disputes.

### 1.3 Aim of the Study

The aim of this study is to design and implement FamilyFunds, an AI-enhanced multi-tenant web application for managing family contribution funds with secure member access, structured contribution tracking, payment allocation, expense recording, reminders, and financial reporting.

### 1.4 Objectives of the Study

The objectives of the study are to:

1. Review the management practices, digital tools, and literature related to informal savings groups, family contribution funds, multi-tenant systems, access control, payments, and AI-supported reporting.
2. Design a multi-tenant family fund management system with logical data isolation, role-based access control, contribution categories, payment allocation rules, reporting, and secure user access.
3. Implement the proposed system using Laravel, Vue.js, Inertia.js, PostgreSQL, Paystack, Laravel Fortify, Laravel AI SDK, and related development tools.
4. Integrate AI assistant and report-summary features in a controlled way that respects role permissions and uses available family fund data.
5. Define and evaluate the planned predictive analytics component according to data availability, payment-history quality, and suitable metrics such as accuracy, precision, recall, and usefulness.
6. Test the system against key requirements, including tenant isolation, role enforcement, payment allocation correctness, report accuracy, authentication security, and usability.

### 1.5 Research Questions

The study is guided by the following research questions:

1. What design features are required for a web-based system to manage informal family contribution funds securely and transparently?
2. How can multi-tenant architecture and role-based access control be applied to protect the records of separate family groups on one platform?
3. How can partial and lump-sum payments be allocated consistently across outstanding contribution balances?
4. To what extent can AI assistant and report-summary features improve access to understandable family fund information without exposing unauthorised data?
5. Under what conditions can historical contribution data support predictive analytics for member payment behaviour?
6. How can the system be tested to confirm that it meets functional, security, reliability, and usability requirements?

### 1.7 Significance of the Study

This study is significant to Nigerian families that maintain contribution funds because it addresses a problem that is often treated as a private inconvenience rather than a software design challenge. A family fund may be informal, but the money involved is real and the social consequences of inaccurate records can be serious. FamilyFunds provides a clearer structure: administrators manage settings and members, financial secretaries record operational transactions, and members can view their own obligations and payment history. This can reduce disputes and improve confidence in the fund.

The project is also useful to software engineering practice. It demonstrates how a modern Laravel and Vue application can be designed as a multi-tenant financial record system with tenant scoping, policy-based permissions, Paystack payment handling, PostgreSQL relational storage, scheduled contribution generation, reminders, passkeys, two-factor authentication, and AI features. The implementation therefore provides a concrete case study for applying established software engineering principles to an under-served local problem.

Academically, the study contributes by connecting separate areas of literature: informal finance, SaaS multi-tenancy, access control, payment processing, financial reporting, and AI-assisted interfaces. The novelty of the work is not that any single technology is new, but that these technologies are brought together for the family fund context. That context matters because a family fund is neither an individual wallet nor a formal cooperative loan system. It has its own governance structure, payment patterns, trust boundaries, and reporting needs.

The project may also benefit small community groups, religious associations, class sets, and workplace welfare groups that manage shared contributions without formal accounting systems. Although the design is centred on families, the underlying model can support any small group that needs transparent contributions, role-aware access, and simple financial reports.

### 1.8 Scope of the Study

The study covers the design and implementation of a web-based family fund management system. Each family is treated as a tenant, meaning that its records are separated from other families' records. The functional scope includes family workspace setup, member invitation, contribution category management, monthly contribution generation, manual payment recording, Paystack payment initiation and verification, oldest-balance-first payment allocation, expense tracking, fund adjustments, reminders, subscription plans, dashboards, reports, AI assistant support, and AI-assisted report summaries.

The technical scope covers a Laravel 13 backend, Vue.js 3 frontend, Inertia.js 3 server-client bridge, PostgreSQL database, Tailwind CSS 4 styling, Laravel Fortify authentication, WebAuthn/passkeys, Paystack integration, Laravel AI SDK, Laravel Pennant feature flags, Vite, Git, and Pest testing. The application is designed for web and mobile-browser access rather than native mobile deployment.

The study does not cover cryptocurrency payments, direct bank API integration, tax computation, full double-entry accounting, regulatory management for formal cooperative societies, or multilingual interfaces. Predictive analytics is included as a planned and evaluation-dependent capability because useful prediction requires sufficient payment-history data. The AI assistant and report-summary functions are treated as system features only where the application can ground the output in available family data and role permissions.

### 1.9 Limitations of the Study

The first limitation is internet dependency. FamilyFunds is a web application, so members need a working device and internet access. This may affect users in areas with unstable connectivity.

The second limitation is payment-channel scope. Paystack is used as the online payment gateway because the project is designed around Nigerian use. Families outside Paystack-supported contexts would need other gateways.

The third limitation is data availability for prediction. A new family may not have enough historical contribution records for reliable predictive analytics. In such cases, the system can still track payments and produce reports, but prediction should be delayed or treated as experimental.

The fourth limitation concerns AI output. Large Language Models can produce fluent but inaccurate responses if they are not constrained (Kang & Liu, 2023). For this reason, AI features in FamilyFunds must be grounded in system data, limited by user permissions, and validated before they are trusted for financial decisions.

The fifth limitation is project scale. The system is designed for multiple families, but very large-scale performance testing across thousands of tenants is beyond the scope of this study.

### 1.10 Definition of Terms

*Table 1.2: Definition of Key Terms*

| Term | Definition |
| --- | --- |
| Family fund | A non-rotating pool of money contributed by family members for shared needs such as welfare, emergencies, ceremonies, and family projects. |
| Tenant | A separate family workspace within the same application, with its own members, contribution records, payments, expenses, and reports. |
| Multi-tenancy | A software architecture where one application serves many tenants while keeping their data and operations logically separated. |
| Role-Based Access Control (RBAC) | An access-control model where permissions are assigned according to user roles such as Administrator, Financial Secretary, and Member. |
| Contribution category | A family-defined tier that determines how much a member is expected to contribute each month. |
| Partial payment | A payment that is less than the full amount owed for one or more contribution periods. |
| Oldest-balance-first allocation | The rule that applies a payment to the earliest outstanding contribution balance before newer balances. |
| Payment gateway | A service that processes online payments between users, banks, cards, and the application. Paystack is used in this project. |
| Large Language Model (LLM) | An AI model capable of generating and interpreting human language. In this project, it supports assistant responses and report summaries. |
| Predictive analytics | The use of historical data to estimate future outcomes, such as whether a member is likely to miss a contribution deadline. |
| WebAuthn/passkey | A browser-based authentication standard that uses public-key credentials, often linked to device biometrics or security keys. |

### 1.11 Organisation of the Report

This report is organised into five chapters. Chapter One introduces the study, states the problem, presents the aim, objectives, research questions, significance, scope, limitations, and key terms. Chapter Two reviews the literature on informal finance, multi-tenant systems, access control, payment systems, AI-assisted reporting, predictive analytics, and related systems. Chapter Three explains the methodology and system design, including the development approach, existing-system analysis, proposed system, requirements, data collection, architecture, UML diagrams, database design, algorithm design, tools, and ethical considerations. Chapter Four presents the implementation, testing, results, and validation of the system. Chapter Five summarises the study, draws conclusions, states the contribution of the project, and recommends areas for future work.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
