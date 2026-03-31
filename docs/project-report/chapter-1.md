# CHAPTER ONE

## INTRODUCTION

### 1.1 Background of the Study

The collective management of financial resources within family units and small cooperative groups represents one of the oldest and most enduring forms of informal financial organisation across Sub-Saharan Africa (Aryeetey, 2008). In Nigeria, practices such as *ajo* (rotating savings), *esusu* (pooled savings with rotating disbursement), and *adashi* have historically enabled communities to mobilise funds for education, healthcare, enterprise development, and social obligations (Akanji, 2006; Iganiga & Asemota, 2008). These informal financial mechanisms have persisted not because of a lack of formal banking infrastructure, but because they are rooted in the social fabric of trust, kinship, and collective responsibility that characterises African communal life (Oloyede, 2008).

However, a distinct and increasingly prevalent variant of collective finance exists outside the well-documented rotating savings models: the **family contribution fund**, or family purse. Unlike *ajo* or *esusu*, where pooled funds rotate among members in a predetermined sequence, the family purse operates as a **non-rotating, centrally managed treasury** into which family members contribute fixed or tiered monthly amounts. These funds are retained collectively and disbursed as needed for shared family expenses—funerals, medical emergencies, celebrations, property maintenance, and educational support (Nnama-Okechukwu & Okoye, 2019). The management of such funds typically falls on a designated family member, often an elder or an elected financial secretary, who maintains records, collects payments, tracks expenses, and provides periodic financial summaries to the family.

Despite the critical role these family funds play in household financial resilience, their management remains overwhelmingly manual and informal. Records are kept in physical notebooks, spreadsheet applications, or, increasingly, within messaging platform groups such as WhatsApp (Ogunleye & Adewale, 2021). This informality introduces several well-documented challenges: human errors in calculation, inconsistent record-keeping, lack of audit trails, disputes over outstanding balances, and an inability to produce structured financial reports (Adeyemo & Bamire, 2005). The situation is further compounded when family members reside in different geographical locations, as is common in Nigeria's pattern of urban-rural and diaspora migration (Osili, 2007).

The rapid advancement of web technologies and cloud computing has enabled the development of Software as a Service (SaaS) platforms capable of addressing complex multi-user, multi-organisation challenges through **multi-tenant architectures** (Bezemer & Zaidman, 2010). Multi-tenancy allows a single software instance to serve multiple independent groups (tenants) while ensuring strict logical isolation of each tenant's data and operations—a property essential for the privacy and trust required in family financial management (Chong et al., 2006). This architectural pattern, combined with modern web frameworks such as Laravel (PHP) and reactive frontend technologies like Vue.js, makes it feasible to build sophisticated financial management platforms that are accessible, scalable, and secure (Otwell, 2024; You, 2024).

Concurrently, the emergence of **artificial intelligence (AI)** in financial technology has opened new possibilities for enhancing the intelligence of financial management systems. **Predictive analytics**, powered by machine learning algorithms, can analyse historical payment patterns to forecast future member behaviour—identifying individuals likely to default on their contributions or those who consistently pay on time (Khandani et al., 2010; Lessmann et al., 2015). Such capabilities are invaluable for proactive fund management, enabling administrators to intervene before shortfalls occur. Furthermore, the advent of **Large Language Models (LLMs)** has made it possible to generate human-readable narrative summaries from structured financial data, transforming raw numbers and statistics into comprehensible reports that any family member can understand regardless of their financial literacy level (Brown et al., 2020; OpenAI, 2023).

It is against this backdrop—the persistence of informal family fund management, the limitations of manual approaches, and the transformative potential of modern web and AI technologies—that this project undertakes the design and implementation of an AI-enhanced multi-tenant family fund management system. The system aims to digitise and modernise the family purse by providing a secure, role-governed, and intelligent platform for contribution tracking, payment processing, expense management, predictive analytics, and narrative report generation.

### 1.2 Statement of the Problem

The management of collective financial contributions within Nigerian families and extended family groups is plagued by a constellation of interrelated problems that undermine transparency, accountability, and the effective stewardship of shared funds.

**First**, the reliance on manual record-keeping methods—physical notebooks, personal spreadsheets, or ad hoc notes within messaging platforms—introduces a high risk of arithmetic errors, data loss, and inconsistent documentation (Adeyemo & Bamire, 2005). When a family's financial secretary records a member's partial payment in a notebook or a WhatsApp message, there is no systematic mechanism to track the remaining balance, link it to the correct contribution period, or ensure that the record is immutable and auditable. Over time, discrepancies accumulate, leading to disputes and erosion of trust among family members (Ogunleye & Adewale, 2021).

**Second**, there is a fundamental lack of **structured role-based governance** in how these funds are managed. In most family groups, the distinction between who can record payments, who can authorise expenses, and who has view-only access to financial records is undefined or entirely absent. This ambiguity creates opportunities for mismanagement, whether intentional or inadvertent, and places a disproportionate burden of trust on a single individual without adequate checks and balances (Nnama-Okechukwu & Okoye, 2019).

**Third**, the problem of **payment tracking complexity** is acute. Family members often have different contribution amounts based on their economic capacity—employed members may contribute more than students or unemployed members. Payments may be partial, arriving in instalments rather than as lump sums. When a member finally makes a payment after missing several months, the question of how to allocate that payment—to the oldest outstanding balance or to the current month—is a non-trivial administrative challenge that most manual systems handle inconsistently (Aryeetey, 2008).

**Fourth**, existing digital financial platforms in Nigeria are **not designed for family-level collective fund management**. Platforms such as PiggyVest and Cowrywise are oriented towards individual savings and investment goals (PiggyVest, 2024; Cowrywise, 2024). Cooperative management software such as CreditClan and Lendsqr targets formal lending cooperatives with regulatory compliance requirements that are irrelevant to informal family groups (CreditClan, 2024). There is no widely available platform that combines multi-tenant group isolation, tiered contribution management, role-based access control, and integrated payment processing specifically for the family fund use case.

**Fifth**, the absence of **predictive and analytical capabilities** means that family administrators operate reactively rather than proactively. There is no mechanism to forecast which members are likely to default in the coming month, anticipate fund shortfalls before they materialise, or identify trends in payment behaviour over time. Without such intelligence, families are unable to plan effectively or intervene early to ensure financial obligations are met (Khandani et al., 2010).

**Finally**, the reporting challenge is significant. Even where digital spreadsheets are used, producing a comprehensible financial summary that non-financially literate family members can understand requires considerable effort and expertise. The potential of Large Language Models to translate structured financial data into natural-language narratives remains unexploited in this domain (Brown et al., 2020).

In summary, there exists a clear and unmet need for a purpose-built, intelligent, multi-tenant web application that addresses the specific challenges of family fund management—providing structured governance, automated tracking with intelligent payment allocation, integrated online payments, predictive analytics for payment behaviour, and AI-generated narrative financial reports.

### 1.3 Aim and Objectives of the Study

#### Aim

The aim of this project is to design and implement an AI-enhanced multi-tenant web-based application for family fund management that provides automated contribution tracking, intelligent payment allocation, predictive analytics for member payment behaviour, and Large Language Model-powered narrative financial reporting.

#### Objectives

The specific objectives of this study are to:

1. **Design** a multi-tenant software architecture that ensures logical data isolation between family groups and implements a role-based access control model with Administrator, Financial Secretary, and Member permission tiers.

2. **Develop** a contribution tracking module with support for tiered contribution amounts, partial payment recording, an oldest-balance-first automatic allocation algorithm, and overdue detection with automated reminder notifications.

3. **Integrate** online payment processing through the Paystack payment gateway alongside expense recording and fund balance management modules to provide a comprehensive financial operations suite.

4. **Implement** a predictive analytics engine using machine learning techniques to analyse historical payment data and forecast individual member payment behaviour, including the likelihood of default and on-time payment.

5. **Deploy** an intelligent reporting module that leverages Large Language Model technology to generate human-readable narrative summaries of monthly and annual financial reports, complemented by WebAuthn passkey authentication and two-factor authentication for system security.

*Table 1.2: Summary of System Objectives*

| No. | Objective | Action Verb | Key Deliverable |
| --- | --- | --- | --- |
| 1 | Multi-tenant architecture and RBAC | Design | Isolated tenant environments with three-tier role model |
| 2 | Contribution tracking and payment allocation | Develop | Balance-first allocation algorithm with overdue detection |
| 3 | Payment processing and expense management | Integrate | Paystack gateway, expense and fund balance modules |
| 4 | Predictive analytics for payment behaviour | Implement | ML-based default likelihood and trend forecasting |
| 5 | Intelligent reporting and security | Deploy | LLM narrative reports, WebAuthn, and 2FA |

### 1.4 Scope of the Study

This section defines the boundaries of the system in terms of functionality, technology, and exclusions.

#### Functional Scope

The system encompasses the following functional areas:

- **Multi-Tenant Family Management:** Creation and management of family groups, each operating as an isolated tenant with its own members, contribution schedules, categories, and financial records. Family administrators can configure group settings including family name, currency, contribution due day, and bank account details.

- **Role-Based Access Control (RBAC):** A three-tier permission model comprising Administrator, Financial Secretary, and Member roles. Administrators possess full management capabilities including member management, family settings, and category configuration. Financial Secretaries can record payments, manage expenses, and generate reports. Members have access to their personal contribution history and online payment functionality.

- **Contribution Tracking:** Monthly contribution generation for all paying members based on their assigned category (e.g., Employed, Unemployed, Student) with corresponding tiered amounts. The system supports partial payment recording and implements an automatic oldest-balance-first allocation algorithm that distributes incoming payments across outstanding contributions chronologically.

- **Payment Processing:** Dual payment channels—manual recording by authorised roles and online payment via the Paystack payment gateway for member self-service. Both channels integrate with the contribution allocation engine to maintain consistent records.

- **Expense and Fund Balance Management:** Recording of family expenses with descriptions and timestamps, fund balance adjustments for non-contribution inflows, and a computed fund balance derived from total payments, adjustments, and expenses.

- **Reporting:** Monthly and annual financial reports with aggregate statistics, collection rates, category-wise breakdowns, and individual member contribution status. Reports include both tabular data and summary metrics.

- **Predictive Analytics (Planned):** Machine learning-based prediction of member payment behaviour, including default likelihood scoring and payment trend analysis. This module will consume historical contribution and payment data to train classification models.

- **Intelligent Reporting (Planned):** Integration with a Large Language Model API to generate natural-language narrative summaries of financial reports, enabling comprehensible reporting for non-financially literate family members.

- **Authentication and Security:** Email/password authentication with email verification, two-factor authentication (TOTP-based), WebAuthn passkey registration and authentication for passwordless login and biometric-assisted two-factor verification, and platform-level super-administrator access for system-wide management.

- **Invitation and Onboarding:** Email-based family invitation system with tokenised acceptance flow, supporting the onboarding of new members into existing family groups with pre-assigned roles.

- **Subscription and Plan Management:** A platform subscription model with tiered plans (Free, Starter, Pro, Enterprise) that govern member limits and feature access, processed through the Paystack payment gateway.

#### Technical Scope

*Table 1.3: Technical Stack Overview*

| Layer | Technology | Version |
| --- | --- | --- |
| Backend Framework | Laravel (PHP) | v13 |
| Frontend Framework | Vue.js | v3.5 |
| Server-Client Bridge | Inertia.js | v3 |
| Database | PostgreSQL | Latest stable |
| CSS Framework | Tailwind CSS | v4 |
| Payment Gateway | Paystack API | Current |
| Authentication | Laravel Fortify, WebAuthn | v1 / Custom |
| Build Tool | Vite | v8 |
| Type System | TypeScript | v5 |
| Route Generation | Laravel Wayfinder | v0 |
| Code Quality | Laravel Pint, ESLint, Prettier | Latest |
| Testing | Pest PHP | v4 |
| ML/Predictive Analytics | To be determined (Python/scikit-learn or in-app) | — |
| LLM Integration | To be determined (OpenAI API or equivalent) | — |

#### Exclusions

The following are explicitly outside the scope of this study:

- Native mobile applications (iOS, Android); the system is a responsive web application accessible on mobile browsers.
- Cryptocurrency or blockchain-based payment mechanisms.
- Formal cooperative society regulatory compliance (CAMA registration, CBN cooperative guidelines).
- Tax computation, invoicing, or full-scale accounting functionality.
- Multi-language or localisation support beyond English.
- Integration with banking APIs beyond Paystack (e.g., direct bank transfer APIs, USSD payment channels).

### 1.5 Significance of the Study

This study holds significance across multiple dimensions encompassing practical utility, academic contribution, industry relevance, and research advancement.

**Significance to Families and Cooperative Groups.** At its most fundamental level, this project addresses a tangible, everyday problem faced by millions of Nigerian families. By providing a dedicated platform for managing family contributions, the system replaces error-prone manual methods with automated, auditable, and transparent financial tracking. The role-based access control ensures that responsibilities are clearly delineated—administrators manage family settings and membership, financial secretaries record payments and expenses, and members view their personal contribution history and make online payments. This structured governance reduces disputes, enhances accountability, and strengthens the trust that is essential for the continued functioning of family financial cooperation (Nnama-Okechukwu & Okoye, 2019). While the system is designed for the family fund use case, its multi-tenant architecture makes it readily extensible to other informal cooperative groups such as workplace savings clubs, religious organisation funds, and community development associations.

**Academic and Research Contribution.** From an academic perspective, this project contributes to the body of knowledge on the application of artificial intelligence to informal financial management—an area that has received limited scholarly attention despite the economic significance of informal finance in developing economies (Aryeetey, 2008). The integration of predictive analytics to forecast payment behaviour within a non-commercial, family-oriented context represents a novel application of machine learning that differs substantively from traditional credit scoring models which operate on formal lending data (Lessmann et al., 2015). Similarly, the use of Large Language Models for generating narrative financial reports in a low-stakes, non-regulatory context provides an empirical case study for the application of natural language generation in financial communication (Brown et al., 2020). The project also serves as a practical demonstration of multi-tenant SaaS architecture principles as taught in software engineering curricula, providing a replicable reference implementation for future students and researchers.

**Industry and Technological Relevance.** The multi-tenant architecture implemented in this project—employing application-level tenant isolation through middleware-enforced family context binding, policy-based authorisation, and query-scoped data access—demonstrates a production-grade approach to building SaaS platforms on the Laravel framework. The integration of the Paystack payment gateway, WebAuthn passwordless authentication, and two-factor authentication reflects current industry best practices in fintech application development within the Nigerian and broader African technology ecosystem (Paystack, 2024). The project thus serves as a reference architecture for developers building similar multi-tenant financial applications in the Laravel and Vue.js ecosystem.

**Social Impact.** By lowering the barrier to effective fund management, the system has the potential to strengthen the financial resilience of families, particularly those with members spread across different geographical locations. The automated reminder system, predictive default detection, and transparent reporting collectively address the social friction points that cause many informal family funds to collapse, thereby preserving a vital mechanism of mutual financial support.

### 1.6 Motivation for the Study

The motivation for this project is rooted in a personal and lived experience. The researcher belongs to a Nigerian family that practises monthly financial contributions into a shared family purse. As with many such families, the management of this fund has historically relied on manual methods—handwritten records, informal WhatsApp group announcements, and verbal confirmations of payments. Over time, this approach has led to recurring challenges: disputes over who has paid and who has not, confusion about partial payments and outstanding balances, difficulty producing clear financial summaries for family meetings, and the absence of any mechanism to hold the fund manager accountable.

These challenges are not unique to the researcher's family. Conversations with peers, classmates, and community members have revealed that similar frustrations are widespread across Nigerian families and extended family networks that operate collective contribution funds. The problem is both common and consequential—when trust erodes due to poor record-keeping, families may abandon their contribution arrangements entirely, losing a valuable mechanism of collective financial support.

The researcher's background in software engineering presented an opportunity to address this problem through technology. Rather than proposing a purely theoretical project, the decision was made to design and build a functional system that solves a real problem the researcher and their family actively face. This personal stake ensures that the project is grounded in genuine user needs and informed by first-hand understanding of the domain.

Furthermore, the project presented an opportunity to explore the intersection of two rapidly advancing fields: **multi-tenant SaaS architecture** and **artificial intelligence in financial technology**. The integration of predictive analytics and Large Language Model-based report generation into a family fund management context represents an intellectually stimulating challenge that extends the project beyond a standard CRUD application into the domain of intelligent systems. The prospect of building a system that not only tracks contributions but also anticipates payment behaviour and communicates financial insights in natural language served as a strong intellectual motivation.

Finally, the multi-tenant architecture requirement—ensuring that multiple independent families can operate securely on a single platform—aligned well with the software engineering principles of scalability, data isolation, and security that the researcher sought to demonstrate as part of a 400-level capstone project.

### 1.7 Limitations of the Study

While the system is designed to be comprehensive and functional, several constraints bound its current implementation and deployment:

1. **Internet Connectivity Dependency.** The system is a web-based application requiring active internet connectivity for all operations. In regions of Nigeria with limited or unreliable internet infrastructure, this dependency may hinder consistent access. The system does not currently support offline-first or progressive web application capabilities for disconnected usage scenarios.

2. **Payment Gateway Geographic Limitation.** The Paystack payment gateway, which serves as the sole online payment processor, primarily supports Nigerian Naira (NGN) transactions and is optimised for the Nigerian financial ecosystem. Families operating in other currencies or countries without Paystack coverage would need an alternative payment integration, which is not within the current scope.

3. **Machine Learning Model Data Requirements.** The predictive analytics module's accuracy is contingent upon a sufficient volume of historical payment data. For newly onboarded families with limited transaction history, the predictive models will have reduced reliability until adequate data has been accumulated. The cold-start problem is a recognised limitation in recommendation and prediction systems (Schein et al., 2002).

4. **LLM API Costs and Latency.** The intelligent reporting feature relies on external Large Language Model API services, which introduce both financial costs (per-token or per-request pricing) and potential latency in report generation. The operational cost of this feature may influence its availability across different subscription tiers, and the quality of generated narratives is dependent on the capabilities of the underlying LLM provider (OpenAI, 2023).

5. **Single-Language Interface.** The application interface and all generated reports are in English. While English is Nigeria's official language and lingua franca, some family members, particularly elderly participants, may be more comfortable with indigenous languages. Multilingual support is not addressed in this project.

6. **Security Assumptions.** The WebAuthn passkey implementation requires devices with biometric sensors or platform authenticators (e.g., fingerprint readers, facial recognition). Users whose devices lack such hardware must rely on the fallback TOTP-based two-factor authentication, which provides a different security posture.

7. **Scalability Boundaries.** While the multi-tenant architecture supports multiple families on a single platform instance, the system has not been subjected to large-scale load testing with thousands of concurrent tenants. Performance under high concurrency remains an area for future evaluation.

### 1.8 Definition of Terms

*Table 1.5: Definition of Key Terms*

| Term | Definition | Reference |
| --- | --- | --- |
| **Multi-Tenancy** | A software architecture in which a single instance of an application serves multiple independent user groups (tenants), with each tenant's data logically or physically isolated from others. | Bezemer & Zaidman (2010) |
| **Role-Based Access Control (RBAC)** | A method of regulating access to system resources based on the roles assigned to individual users within an organisation. Users inherit permissions associated with their assigned role rather than being granted permissions individually. | Sandhu et al. (1996) |
| **Contribution** | In the context of this system, a periodic (typically monthly) financial obligation assigned to a member of a family group, the amount of which is determined by the member's assigned category or tier. | — |
| **Partial Payment** | A payment that satisfies only a portion of a member's outstanding contribution for a given period, resulting in a remaining balance that must be fulfilled through subsequent payments. | — |
| **Balance-First Allocation** | An automatic payment distribution algorithm that applies incoming funds to the member's oldest outstanding contribution balance before allocating to more recent periods, ensuring chronological debt resolution. | — |
| **Predictive Analytics** | The use of statistical techniques and machine learning algorithms to analyse historical data and make predictions about future outcomes, such as the likelihood of a member defaulting on a contribution payment. | Khandani et al. (2010) |
| **Large Language Model (LLM)** | A deep learning model trained on vast corpora of text data, capable of generating coherent natural-language text, answering questions, and performing text transformation tasks. In this project, LLMs are used to generate narrative summaries of financial reports. | Brown et al. (2020) |
| **WebAuthn** | A web standard published by the World Wide Web Consortium (W3C) that enables passwordless authentication using public-key cryptography. It allows users to authenticate using biometric sensors, security keys, or platform authenticators built into their devices. | W3C (2021) |
| **Two-Factor Authentication (2FA)** | A security mechanism that requires users to provide two distinct forms of identification before accessing a system—typically something they know (a password) and something they have (a time-based one-time password or authentication device). | Ometov et al. (2018) |
| **Software as a Service (SaaS)** | A software distribution model in which applications are hosted by a service provider and made available to customers over the internet, typically on a subscription basis, eliminating the need for local installation and maintenance. | Chong et al. (2006) |
| **Single Page Application (SPA)** | A web application architecture in which the browser loads a single HTML page and dynamically updates content in response to user interactions without requiring full page reloads, providing a fluid, desktop-like user experience. | Mikowski & Powell (2013) |
| **Inertia.js** | A library that enables the creation of server-driven single-page applications by connecting server-side frameworks (such as Laravel) directly to client-side frameworks (such as Vue.js) without requiring a separate API layer. | Reinink (2024) |
| **Application Programming Interface (API)** | A set of defined protocols, routines, and tools that enable different software systems to communicate and exchange data with one another. In this project, APIs are used for payment gateway integration and LLM service communication. | Fielding (2000) |
| **Payment Gateway** | A technology service that authorises and processes electronic payment transactions between a buyer, a seller, and their respective financial institutions. Paystack is the payment gateway integrated in this system. | Paystack (2024) |

### 1.9 Organization of the Report

This project report is organised into three chapters, each addressing a distinct phase of the research and development process.

**Chapter One: Introduction.** This chapter provides the background and context for the study, articulates the problem statement, defines the aim and objectives, delineates the scope and significance of the project, presents the researcher's motivation, identifies limitations, and defines key terms used throughout the report.

**Chapter Two: Literature Review.** This chapter examines the historical perspectives of family fund management and informal financial systems, establishes the theoretical framework underpinning the system design, reviews related work from at least fifty scholarly sources, identifies gaps in existing research, and summarises how this project addresses those gaps.

**Chapter Three: System Design and Methodology.** This chapter presents the proposed system design, specifies functional and non-functional requirements, describes the software development methodology adopted, details the system architecture and database design, documents the programming languages and tools used, explains the key software modules and components, and outlines the security measures implemented in the system.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
