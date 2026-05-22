# CHAPTER TWO

## LITERATURE REVIEW

### 2.1 Introduction to the Chapter

This chapter reviews the literature and existing systems that inform the design of FamilyFunds. It is organised around five connected themes: informal family and group finance, multi-tenant software architecture, role-based access control, digital payments, and AI-supported reporting and prediction. The purpose is not only to summarise prior work, but to show where existing research and tools are insufficient for informal family fund management. The chapter therefore moves from concepts and theory to empirical studies, existing platforms, comparative analysis, and the specific research gap addressed by this project.

### 2.2 Conceptual Review

#### 2.2.1 Informal Collective Finance and Family Contribution Funds

Informal collective finance refers to financial arrangements maintained outside formal banking structures, usually through social trust, repeated interaction, and community accountability. In Nigeria, these arrangements include *ajo*, *esusu*, *adashi*, cooperative savings, welfare funds, and family purses. Earlier studies established the importance of rotating savings and credit associations in African economies, but recent financial inclusion reports still show that informal saving and group finance remain relevant in Nigeria (Demirgüç-Kunt et al., 2022; EFInA, 2023; Kouandou & Zeh, 2024).

The family contribution fund differs from the better-known rotating model. In a rotating savings association, members contribute at fixed intervals and the pooled amount goes to one member per cycle. In a family fund, contributions are normally retained in a central pool and later used for family obligations. The purpose is not only saving; it is shared welfare. This difference affects the software design. A family fund needs a permanent balance, expense records, contribution categories, partial-payment handling, reports, and governance rules. A rotating savings application may not need all of these.

Trust is central to both arrangements. However, trust in a family fund should not mean the absence of verification. When payment records are scattered across notebooks, private spreadsheets, and messaging platforms, the group relies heavily on the memory and integrity of the financial secretary. That arrangement may work for a small group over a short period, but it becomes weaker as membership grows, members migrate, contribution categories change, and payments arrive through different channels. The concept of a family fund therefore includes both social trust and the need for reliable records.

#### 2.2.2 Multi-Tenant Software Architecture

Multi-tenancy is a software architecture in which one application instance serves multiple independent tenants. In a business SaaS application, a tenant might be a company. In FamilyFunds, the tenant is a family. The architectural value is that one platform can support many families without deploying a separate application for each one. The main risk is tenant isolation: one tenant's data must never be exposed to another tenant (Amazon Web Services, 2020; Bezemer & Zaidman, 2010; Sharma & Kaur, 2021).

There are different data-isolation strategies. A system may provide a separate database for each tenant, a separate schema for each tenant, or a shared schema with a tenant identifier on tenant-owned records. Separate databases offer stronger physical isolation but increase cost and maintenance. A shared-schema design is cheaper and easier to operate for many small tenants, but it requires strict application-level controls. For FamilyFunds, shared-schema multi-tenancy is appropriate because the expected tenants are small family groups, not large institutions. The design therefore depends on `family_id` scoping, middleware, policies, and careful query boundaries.

Recent SaaS literature continues to treat isolation and tenant-specific quality requirements as central design concerns. Jia et al. (2021) reviewed multi-tenancy in cloud platforms and emphasised the need to balance shared resources with tenant-specific requirements. Sharma and Kaur (2021) also treated tenant-aware storage and isolation as a key concern in multi-tenant applications. For a family fund platform, this concern becomes personal: a tenant-isolation failure would not merely be a technical bug; it would expose one family's private financial records to another family.

#### 2.2.3 Role-Based Access Control and Accountability

Role-Based Access Control (RBAC) assigns permissions to roles rather than assigning them directly to individual users. The classical RBAC model remains important because it is simple, understandable, and suitable for organisations where duties are clearly separated (Sandhu et al., 1996). In FamilyFunds, the main roles are Family Administrator, Financial Secretary, Member, and Platform Super Administrator.

The family fund setting makes RBAC more than a security feature. It is a governance structure. A Family Administrator should be able to manage members, settings, categories, and subscriptions. A Financial Secretary should be able to record payments, expenses, reminders, and reports. A Member should be able to view personal contribution history and pay online, but not alter financial records for others. The Platform Super Administrator should manage platform-level plans and oversight without becoming the treasurer for any one family.

Accountability also depends on audit-friendly records. If a financial secretary records a payment, the system should store who recorded it, when it was recorded, which member it relates to, and how it affected the contribution balance. RBAC therefore works together with data design. Permissions prevent unauthorised actions, while records make authorised actions reviewable.

#### 2.2.4 Digital Payments and Payment Allocation

Digital payment systems reduce the friction of sending and receiving money. In African contexts, mobile money and payment gateways have expanded access to financial transactions and have influenced how households send, receive, and manage funds (Jack & Suri, 2014; Mbiti & Weil, 2016; Wezel & Ree, 2023). For Nigerian web applications, Paystack provides a practical payment gateway because it supports online payment flows, transaction references, webhooks, and developer-facing APIs.

However, accepting a payment is only part of the problem. A family fund also needs to decide how the payment should be applied to outstanding balances. If a member owes for January, February, and March but pays only enough for one and a half months, the system needs a clear rule. FamilyFunds uses an oldest-balance-first allocation rule. This is similar to a first-in, first-out treatment of obligations: older unpaid contributions are settled before newer ones. The benefit is consistency. The same payment history will always produce the same allocation result, which reduces disagreement and makes member statements easier to understand.

#### 2.2.5 AI Assistance, Reporting, and Predictive Analytics

AI features in a financial system must be handled carefully. Large Language Models can help users ask questions in ordinary language and can turn structured report data into readable summaries. This is useful in a family fund because not every member is comfortable reading financial tables. Classical Natural Language Generation relied on templates and rule-based pipelines; modern LLMs are more flexible, but they also introduce the risk of fluent errors (Dale, 2020; de Wynter et al., 2023; Kang & Liu, 2023).

For this reason, the AI assistant in FamilyFunds is treated as a controlled interface, not as an independent financial authority. It should answer only within the user's family context, respect role permissions, use available system data, and avoid unsupported financial claims. Where actions are involved, the assistant should not silently change records without confirmation.

Predictive analytics is a separate but related concept. It uses historical contribution and payment data to estimate future behaviour, such as whether a member is likely to miss a contribution deadline. Credit-scoring literature provides useful evaluation ideas, including accuracy, precision, recall, F1-score, and attention to class imbalance (Hussin Adam Khatir & Bee, 2022; Robisco & Carbó Martínez, 2022). Still, the family fund context is smaller and more personal than bank credit scoring. A model that works with millions of loan records may not be appropriate for a family with twelve months of contribution history. Predictive analytics is therefore treated in this project as data-dependent and evaluation-dependent.

#### 2.2.6 Authentication, Usability, and Member Access

A family fund system must be secure, but it must also be usable by people with different levels of technical confidence. Strong authentication is necessary because the system stores personal and money-related records. At the same time, a login process that is too difficult may discourage older or less technical members from using the platform. WebAuthn and passkeys are relevant because they reduce dependence on passwords and use public-key authentication through browsers and devices (FIDO Alliance, 2022; W3C, 2021). Lyastani et al. (2020), however, showed that passwordless authentication still needs careful user support because people may not immediately understand the relationship between passkeys, biometrics, and account security.

The usability concept behind FamilyFunds is therefore role-sensitive simplicity. A member should not face the same interface as a financial secretary. A member mainly needs personal balance, payment history, payment action, and reminders. A financial secretary needs payment entry, expenses, reports, and follow-up tools. A family administrator needs members, categories, settings, and subscription controls. Good responsive interface design also matters because many users are likely to interact with the system on phones rather than desktop computers (Li et al., 2022). Security and usability are treated together because a system that users avoid cannot improve family accountability, no matter how strong its technical design is.

### 2.3 Theoretical Framework

This study is guided by an applied system-development and design-oriented framework. The project starts with a real problem, studies the context, designs an artefact, implements it, and evaluates whether it addresses the problem. This aligns with the practical logic of software engineering: the contribution is demonstrated through a working system, not only through abstract explanation.

The development approach is Agile and iterative. Agile is suitable because the project requirements are likely to evolve as the family fund workflow becomes clearer. A payment feature may reveal the need for allocation rules; allocation rules may affect reports; reports may affect the AI summary design. Research on Agile development shows that iterative work helps teams respond to changing requirements and manage quality concerns throughout development rather than treating them as late additions (Karhapää et al., 2021; Masood et al., 2020; Schwaber & Sutherland, 2020).

The architectural theory is a modular monolith supported by multi-tenancy. A modular monolith allows related modules to remain inside one deployable Laravel application while still separating responsibilities such as authentication, families, contributions, payments, reports, subscriptions, notifications, and AI. This is appropriate for a final-year system because the project is broad enough to need modularity but not large enough to justify separate microservices.

The access-control theory is RBAC. The RBAC model is used because the family fund domain already has role-like responsibilities. Instead of inventing complex permission rules, the system formalises familiar duties: administrators manage the family workspace, financial secretaries manage money-related records, and members view and pay their own obligations. This theoretical choice supports both security and usability.

### 2.4 Empirical Review of Related Works

Empirical and technical studies related to this project can be grouped into informal finance, digital financial inclusion, multi-tenant systems, Agile development, AI reporting, and credit-risk prediction. Table 2.1 summarises selected works and their relevance to FamilyFunds.

*Table 2.1: Empirical Review of Related Works*

| Author(s) and Year | Focus | Method/Approach | Key Finding | Relevance to FamilyFunds |
| --- | --- | --- | --- | --- |
| Adegbite and Machethe (2020) | Financial inclusion in Nigeria | Quantitative analysis | Gender and access gaps affect financial inclusion. | Shows that access to suitable financial tools remains uneven. |
| Demirgüç-Kunt et al. (2022) | Global financial inclusion | Global survey/database | Informal saving remains important in many economies. | Supports the need to consider informal finance in system design. |
| EFInA (2023) | Nigerian financial access | National survey | Formal inclusion improved, but informal services remain part of financial behaviour. | Provides recent Nigerian context for the problem. |
| Wezel and Ree (2023) | Digital financial services in Nigeria | IMF policy analysis | Digital services can support inclusion but need stronger uptake and trust. | Supports the use of digital channels while recognising adoption barriers. |
| Kouandou and Zeh (2024) | Informal savings in Nigeria | Household panel analysis | Informal savings help households manage shocks. | Reinforces the social value of informal savings structures. |
| Jia et al. (2021) | Multi-tenancy in cloud platforms | Systematic review | Tenant-specific quality and resource-sharing concerns are central. | Supports the system's focus on tenant isolation. |
| Sharma and Kaur (2021) | Tenant-centric SaaS storage | System design and evaluation | Tenant-aware storage improves isolation and efficiency. | Supports tenant-aware data design. |
| Karhapää et al. (2021) | Quality requirements in Agile | Multiple case study | Quality requirements must be managed throughout Agile work. | Justifies handling security, reliability, and usability from design stage. |
| Masood et al. (2020) | Agile team practices | Grounded theory study | Agile work benefits from self-organisation and iterative delivery. | Supports the iterative development approach. |
| Lyastani et al. (2020) | FIDO2 authentication | Comparative usability study | Passwordless authentication can improve usability but needs user understanding. | Supports passkeys with fallback authentication. |
| Dale (2020) | Natural language generation | State-of-the-art review | NLG moved from rule-based systems toward neural methods. | Supports AI-generated narrative reports. |
| de Wynter et al. (2023) | LLM output evaluation | Empirical evaluation | LLM output requires careful evaluation. | Supports validation of AI summaries. |
| Kang and Liu (2023) | LLM hallucination in finance | Empirical examination | Off-the-shelf LLMs can hallucinate in financial tasks. | Supports controlled, data-grounded AI assistant design. |
| Hussin Adam Khatir and Bee (2022) | Credit scoring | Comparative ML study | Model performance depends on algorithm and data-balancing choices. | Informs evaluation of planned prediction. |
| Robisco and Carbó Martínez (2022) | Credit default prediction | Model-risk evaluation | Predictive models should be evaluated beyond raw accuracy. | Supports using precision and recall for prediction. |

The reviewed studies justify the main design decisions of the project. Informal savings research shows that informal finance is still relevant rather than obsolete. Digital financial inclusion studies show why online payments and accessible interfaces matter. Multi-tenancy studies show why tenant isolation must be explicit. Agile studies support iterative development. LLM studies warn that AI reports must be verified. Credit-scoring research provides metrics for prediction but also shows why prediction cannot be claimed without enough data.

One important pattern across these works is that technology alone does not solve a trust problem. Informal savings groups work partly because members know each other and can apply social pressure. A digital system should not remove that social layer; it should make the shared facts easier to verify. This is why FamilyFunds focuses on transparent balances, role-aware actions, payment receipts, and reports. It supports the family structure rather than replacing it with a bank-like process.

### 2.5 Review of Existing Systems/Tools

Several existing systems are related to FamilyFunds, but none fully addresses the project problem.

PiggyVest is a Nigerian digital savings and investment platform for individuals. It supports personal savings goals and automated saving, which makes it useful for disciplined individual finance. Its limitation in this study is that it is not designed for family-level governance. It does not provide family tenants, financial-secretary roles, contribution categories, partial-payment allocation, or family expense reporting.

Cowrywise is also focused on individual saving and investment. It offers a polished digital experience and contributes to the broader adoption of financial technology in Nigeria. However, the platform is centred on personal financial goals, not informal family funds. It does not model a family purse with shared obligations, group reports, or role-separated record keeping.

CreditClan provides credit and lending infrastructure. It is closer to formal cooperative and lending workflows than to informal family welfare funds. Its strengths are credit-oriented features and payment support, but its assumptions differ from this project. A family fund does not require loan origination, credit underwriting, or formal lending operations as its core workflow.

Lendsqr provides lending infrastructure for digital lenders and financial institutions. It supports more advanced lending operations than a family fund needs. Its existence shows that Nigerian fintech has strong tools for credit businesses, but it also highlights the gap: the informal family contribution domain remains outside the main focus of such systems.

WhatsApp and spreadsheets are not financial platforms, but they are widely used in practice. They are accessible and familiar, which explains their popularity. Their weakness is that they are not structured record systems. They do not enforce roles, calculate balances reliably across partial payments, prevent accidental edits, verify online payments, or produce consistent family reports.

*Table 2.2: Review of Existing Systems and Tools*

| System/Tool | Main Strength | Main Limitation for Family Funds |
| --- | --- | --- |
| PiggyVest | Strong individual savings experience | No family workspace, RBAC, or contribution allocation |
| Cowrywise | Personal saving and investment support | Not designed for shared family welfare funds |
| CreditClan | Credit and cooperative finance infrastructure | More suited to formal lending than informal family funds |
| Lendsqr | Lending infrastructure and credit workflows | Focuses on lenders, not family contribution governance |
| WhatsApp | Familiar communication channel | No structured financial records, permissions, or reports |
| Spreadsheets | Flexible calculation and tabulation | Weak audit trail, weak access control, and error-prone collaboration |

### 2.6 Comparative Analysis of Related Works

The comparison in Table 2.3 shows that related work and existing systems address parts of the problem but not the complete FamilyFunds requirement set.

*Table 2.3: Comparative Analysis of Related Works*

| Area Compared | Prior Work or Tool | What It Covers | Gap Remaining |
| --- | --- | --- | --- |
| Informal finance | ROSCA and financial inclusion studies | Social value of informal savings and group finance | Limited attention to non-rotating family purses and software requirements |
| Digital financial inclusion | World Bank, EFInA, IMF studies | Importance of digital access and financial inclusion | Does not provide a system for family fund management |
| Individual savings platforms | PiggyVest, Cowrywise | Personal saving, investment, payment convenience | No family governance, tenant model, or shared fund reporting |
| Formal lending platforms | CreditClan, Lendsqr | Credit operations and lending infrastructure | Not designed for informal family contribution funds |
| Multi-tenant architecture | SaaS literature and cloud studies | Tenant isolation, resource sharing, scalability | Usually assumes business tenants, not family groups |
| RBAC | Access-control literature | Role-permission separation | Needs adaptation to family admin, secretary, and member roles |
| Digital payments | Paystack and mobile money literature | Online transactions and payment verification | Does not solve allocation across family contribution balances |
| AI reporting | NLG and LLM studies | Automated text generation and reporting support | Financial hallucination risk requires controlled, data-grounded summaries |
| Predictive analytics | Credit-scoring studies | Prediction metrics and model-risk lessons | Family funds have small datasets and social defaults, not bank-scale credit data |

The key observation is that FamilyFunds is not trying to replace all existing tools. Instead, it combines the relevant strengths of several areas: the accessibility of digital finance, the separation of SaaS multi-tenancy, the accountability of RBAC, the convenience of online payment gateways, the consistency of payment-allocation algorithms, and the readability of AI-supported reporting. The gap lies in applying these ideas together to the informal family fund context.

### 2.7 Identified Research Gap

The literature and system review reveal four main gaps.

First, family contribution funds are under-represented in software-focused research. Informal finance literature often discusses rotating savings groups and financial inclusion, but the non-rotating family purse has different requirements. It needs contribution balances, shared expenses, adjustments, audit trails, and long-term reporting.

Second, existing digital finance platforms do not match family fund governance. Individual savings tools are useful for personal goals, and lending platforms are useful for formal credit operations, but neither category provides a role-governed family treasury with contribution categories and partial-payment allocation.

Third, multi-tenancy and RBAC have not been sufficiently discussed in the context of informal family finance. The technical pattern is established, but its application to family trust boundaries is specific. The system must prevent cross-family data exposure while also enforcing role differences within each family.

Fourth, AI-supported reporting and predictive analytics require careful adaptation. LLMs can improve readability, but financial hallucination is a serious risk. Predictive analytics can be useful, but only when enough reliable historical data exists. FamilyFunds addresses this gap by treating AI as a controlled assistant layer and treating prediction as data-dependent rather than automatically reliable.

The identified gap can therefore be stated as follows: there is limited research and no evident purpose-built system that integrates multi-tenant architecture, family-level RBAC, contribution tracking, deterministic payment allocation, expense management, online payments, AI-assisted reporting, and planned predictive analytics for informal family contribution funds.

### 2.8 Summary of the Chapter

This chapter reviewed the concepts, theories, empirical studies, and existing tools relevant to FamilyFunds. It showed that informal finance remains important in Nigeria, but family contribution funds are still managed with weak manual tools. It also showed that multi-tenant architecture, RBAC, payment gateways, AI-assisted reporting, and predictive analytics each offer useful ideas, but none of them alone solves the family fund problem.

The review established the academic and practical justification for the proposed system. FamilyFunds is positioned as an engineering response to a specific gap: informal family funds need a secure, role-governed, multi-tenant, payment-aware, report-friendly system. Chapter Three builds on this literature by explaining the methodology, design decisions, requirements, diagrams, database structure, tools, and ethical considerations used to develop the proposed system.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
