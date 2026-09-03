# Preliminary Pages

---

## Title Page

<div align="center">

### MIVA OPEN UNIVERSITY

### FACULTY OF COMPUTING

### DEPARTMENT OF SOFTWARE ENGINEERING

<br/>

### DESIGN AND IMPLEMENTATION OF AN AI-ENHANCED MULTI-TENANT FAMILY FUND MANAGEMENT SYSTEM WITH PREDICTIVE ANALYTICS AND INTELLIGENT REPORTING

<br/>

### BY

### AMINU DANLADI HUSSAIN

### 2024/A/SENG/0156

<br/>

### A PROJECT SUBMITTED TO THE DEPARTMENT OF SOFTWARE ENGINEERING, FACULTY OF COMPUTING, MIVA OPEN UNIVERSITY, IN PARTIAL FULFILMENT OF THE REQUIREMENTS FOR THE AWARD OF THE DEGREE OF BACHELOR OF SCIENCE (B.Sc.) IN SOFTWARE ENGINEERING

<br/>

### SUPERVISOR: DR. AYODEJI SAMUEL MAKINDE

<br/>

### MAY, 2026

</div>

---

## Declaration

I, **Aminu Danladi Hussain** (Matriculation Number: **2024/A/SENG/0156**), declare that this report accurately presents the design, implementation, testing, and limitations of the project titled **“Design and Implementation of an AI-Enhanced Multi-Tenant Family Fund Management System with Predictive Analytics and Intelligent Reporting.”** All sources consulted have been acknowledged in the reference list, and no data, test result, model metric, or system evidence has been knowingly fabricated. Forms of technical and editorial assistance, including the limited use of generative artificial intelligence, are disclosed in Section 5.9. This report has not been submitted elsewhere for the award of another degree.

<br/>

**Aminu Danladi Hussain** &emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
Student &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

---

## Certification

This is to certify that this project titled **"Design and Implementation of an AI-Enhanced Multi-Tenant Family Fund Management System with Predictive Analytics and Intelligent Reporting"** was carried out by **Aminu Danladi Hussain** (Matriculation Number: **2024/A/SENG/0156**) of the Department of Software Engineering, Faculty of Computing, Miva Open University.

<br/>

**Dr. Ayodeji Samuel Makinde** &emsp;&emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
Supervisor &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

<br/>

**\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_** &emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
Head of Department &emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

<br/>

**\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_** &emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
External Examiner &emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

---

## Dedication

This project is dedicated to my family, whose collective spirit of contribution, mutual support, and togetherness inspired the problem addressed in this study.

---

## Acknowledgments

I express my sincere gratitude to my supervisor, **Dr. Ayodeji Samuel Makinde**, for his guidance, constructive feedback, and encouragement throughout this project. I also appreciate the Department of Software Engineering, Miva Open University, for providing the academic environment in which the study was undertaken.

I am grateful to my family, whose practical experience of managing shared contributions inspired the problem addressed by this project. My appreciation also goes to my classmates and friends for their moral support and useful technical discussions, and to the open-source community, particularly the maintainers of Laravel, Vue.js, and the supporting libraries used in developing the system.

Above all, I am grateful to **Almighty God** for the wisdom, strength, and perseverance to complete this work.

---

## Abstract

The management of shared financial contributions within families and cooperative groups in Nigeria remains largely informal, relying on manual record-keeping methods such as spreadsheets, notebooks, and messaging platforms. These approaches are prone to errors, lack transparency, and offer no mechanism for accountability, payment reminders, or financial forecasting. While digital financial platforms exist for individual savings and formal cooperatives, no purpose-built solution addresses the unique needs of family-level fund management with role-based governance. This project presents the design and implementation of an AI-enhanced multi-tenant web-based family fund management system. The system employs a multi-tenant software architecture to provide isolated operational environments for each family group on a single platform. It implements a role-based access control model with three tiers—Administrator, Financial Secretary, and Member—to enforce appropriate permissions across fund management operations. Core modules include contribution tracking with partial payment support and an oldest-balance-first allocation algorithm, expense recording, fund balance management, and online payment processing via the Paystack payment gateway; reconciliation was added later as an audit-focused extension. The system further integrates controlled AI assistant and report-summary features through Large Language Model (LLM) technology, allowing authorised users to ask permitted questions and receive human-readable narrative summaries of financial reports. The predictive component implements an offline, leakage-aware logistic-regression training pipeline, two transparent baselines (training prevalence and previous-period lateness), a versioned portable model artefact, activation gates, and role-restricted advisory inference. The component remains activation-gated: no authorised private training CSV is present in this report package, so production-model training, held-out evaluation, populated predictive screenshots, and live predictive performance claims are recorded as not executed rather than inferred. Security is reinforced through WebAuthn passkey authentication and two-factor authentication mechanisms. The application is developed using Laravel (PHP) for the backend, Vue.js with Inertia.js for the frontend single-page application, PostgreSQL for the database, Tailwind CSS for responsive styling, and Python/scikit-learn for the offline predictive pipeline. The final local gate passed 1,421 Pest tests with 6,904 assertions and 100.0% PHP coverage, while the locked predictive package passed 66 tests with 100% statement coverage. Twenty redacted synthetic-tenant images document the principal interface journeys. Hosted, live-provider, performance, recovery, and private-data model evidence remains explicitly pending in Chapter Four.

**Keywords:** Multi-Tenancy, Family Fund Management, Role-Based Access Control, Payment Allocation, Large Language Model, Logistic Regression, Payment Gateway, WebAuthn, Laravel, Vue.js, PostgreSQL

---

## Table of Contents

- [Preliminary Pages](#preliminary-pages)
  - [Title Page](#title-page)
  - [Declaration](#declaration)
  - [Certification](#certification)
  - [Dedication](#dedication)
  - [Acknowledgments](#acknowledgments)
  - [Abstract](#abstract)
  - [Table of Contents](#table-of-contents)
  - [List of Tables](#list-of-tables)
  - [List of Figures](#list-of-figures)
  - [List of Listings](#list-of-listings)
  - [List of Abbreviations](#list-of-abbreviations)
- [Chapter One: Introduction](chapter-1.md)
  - [1.1 Background to the Study](chapter-1.md#11-background-to-the-study)
  - [1.2 Statement of the Problem](chapter-1.md#12-statement-of-the-problem)
  - [1.3 Aim of the Study](chapter-1.md#13-aim-of-the-study)
  - [1.4 Objectives of the Study](chapter-1.md#14-objectives-of-the-study)
  - [1.5 Research Questions](chapter-1.md#15-research-questions)
  - [1.7 Significance of the Study](chapter-1.md#17-significance-of-the-study)
  - [1.8 Scope of the Study](chapter-1.md#18-scope-of-the-study)
  - [1.9 Limitations of the Study](chapter-1.md#19-limitations-of-the-study)
  - [1.10 Definition of Terms](chapter-1.md#110-definition-of-terms)
  - [1.11 Organisation of the Report](chapter-1.md#111-organisation-of-the-report)
- [Chapter Two: Literature Review](chapter-2.md)
  - [2.1 Introduction to the Chapter](chapter-2.md#21-introduction-to-the-chapter)
  - [2.2 Conceptual Review](chapter-2.md#22-conceptual-review)
  - [2.3 Theoretical Framework](chapter-2.md#23-theoretical-framework)
  - [2.4 Empirical Review of Related Works](chapter-2.md#24-empirical-review-of-related-works)
  - [2.5 Review of Existing Systems/Tools](chapter-2.md#25-review-of-existing-systemstools)
  - [2.6 Comparative Analysis of Related Works](chapter-2.md#26-comparative-analysis-of-related-works)
  - [2.7 Identified Research Gap](chapter-2.md#27-identified-research-gap)
  - [2.8 Summary of the Chapter](chapter-2.md#28-summary-of-the-chapter)
- [Chapter Three: Methodology](chapter-3.md)
  - [3.1 Introduction to the Chapter](chapter-3.md#31-introduction-to-the-chapter)
  - [3.2 Research Design / Project Approach](chapter-3.md#32-research-design--project-approach)
  - [3.3 Analysis of Existing System](chapter-3.md#33-analysis-of-existing-system)
  - [3.4 Proposed System Overview](chapter-3.md#34-proposed-system-overview)
  - [3.5 System Requirements](chapter-3.md#35-system-requirements)
  - [3.6 Data Collection Methods](chapter-3.md#36-data-collection-methods)
  - [3.7 Population and Sampling](chapter-3.md#37-population-and-sampling)
  - [3.8 System Architecture / Design](chapter-3.md#38-system-architecture--design)
  - [3.9 Use Case / UML Diagrams](chapter-3.md#39-use-case--uml-diagrams)
  - [3.10 Database Design](chapter-3.md#310-database-design)
  - [3.11 Algorithm / Model Design](chapter-3.md#311-algorithm--model-design)
  - [3.12 Tools and Technologies](chapter-3.md#312-tools-and-technologies)
  - [3.13 Ethical Considerations](chapter-3.md#313-ethical-considerations)
  - [3.14 Summary of the Chapter](chapter-3.md#314-summary-of-the-chapter)
- [Chapter Four: System Implementation and Testing](chapter-4.md)
  - [4.1 Implementation of the System Design](chapter-4.md#41-implementation-of-the-system-design)
  - [4.2 Module Integration and Coding](chapter-4.md#42-module-integration-and-coding)
  - [4.3 Testing Strategy and Procedures](chapter-4.md#43-testing-strategy-and-procedures)
  - [4.4 Test Results and Discussion](chapter-4.md#44-test-results-and-discussion)
- [Chapter Five: Summary, Conclusion and Recommendations](chapter-5.md)
  - [5.1 Introduction](chapter-5.md#51-introduction)
  - [5.2 Summary of Findings](chapter-5.md#52-summary-of-findings)
  - [5.3 Achievement of Objectives](chapter-5.md#53-achievement-of-objectives)
  - [5.4 Contributions of the Study](chapter-5.md#54-contributions-of-the-study)
  - [5.5 Conclusion](chapter-5.md#55-conclusion)
  - [5.6 Limitations](chapter-5.md#56-limitations)
  - [5.7 Recommendations](chapter-5.md#57-recommendations)
  - [5.8 Future Work](chapter-5.md#58-future-work)
  - [5.9 AI-Assistance Disclosure](chapter-5.md#59-ai-assistance-disclosure)
- [References](references.md)

---

## List of Tables

| Table No. | Title | Page |
| --- | --- | --- |
| 1.1 | Comparison of Existing Financial Management Platforms | 3 |
| 1.2 | Definition of Key Terms | 7 |
| 2.1 | Empirical Review of Related Works | 14 |
| 2.2 | Review of Existing Systems and Tools | 17 |
| 2.3 | Comparative Analysis of Related Works | 17 |
| 3.1 | Summary of Proposed System Features | 23 |
| 3.2 | Functional Requirements | 24 |
| 3.3 | Non-Functional Requirements | 26 |
| 3.4 | Deployment Requirements | 27 |
| 3.5 | Major Database Entities | 34 |
| 3.6 | Oldest-Balance-First Payment Allocation Algorithm | 36 |
| 3.7 | Tools and Technologies | 37 |
| 4.1 | Implementation and Evidence Environment | 42 |
| 4.2 | Design-to-Implementation Traceability | 44 |
| 4.3 | Chapter Four Interface Evidence Register | 55 |
| 4.4 | Testing Strategy and Evidence Sources | 74 |
| 4.5 | Structured System Test Cases and Current Results | 76 |
| 4.6 | Predictive Analytics Test and Evidence Matrix | 80 |
| 4.7 | Non-Functional Requirement Evaluation | 83 |
| 4.8 | Predictive Training and Evaluation Evidence Status | 85 |
| 4.9 | Objectives and Research Questions Verdict Matrix | 88 |
| 5.1 | Achievement of Study Objectives | 92 |

---

## List of Figures

| Figure No. | Title | Page |
| --- | --- | --- |
| 3.1 | Existing Manual Family Fund Process | 22 |
| 3.2 | System Architecture Diagram | 29 |
| 3.3 | Use Case Diagram | 30 |
| 3.4 | Payment Allocation Flowchart | 31 |
| 3.5 | Entity-Relationship Diagram (ERD) | 34 |
| 4.1a | Login Entry for the Synthetic Demonstration Tenant | 56 |
| 4.1b | Password Confirmation Before a Sensitive Setting | 56 |
| 4.1c | Account Security, Two-Factor and Passkey Settings | 57 |
| 4.2 | Family Administrator Dashboard | 58 |
| 4.3 | Member, Role and Contribution-Category Management | 58 |
| 4.4 | Contribution Register and Payment States | 59 |
| 4.5 | Oldest-First Payment Allocation Review | 60 |
| 4.6a | Member Contribution Statement | 61 |
| 4.6b | Receipt Showing Split Allocation Across Periods | 62 |
| 4.6c | Paystack Self-Pay Review State Before Submission | 63 |
| 4.7a | Expense Register | 64 |
| 4.7b | Fund-Adjustment Register | 64 |
| 4.8a | Reports, Exports and Schedule Controls | 65 |
| 4.8b | Generated Financial Report Output | 66 |
| 4.9 | Seeded Contribution-Reminder Notification | 67 |
| 4.10 | Current Growth Plan and Feature Entitlements | 68 |
| 4.11 | Existing Controlled-AI Conversation in the Synthetic Tenant | 69 |
| 4.12 | Predictive Analytics Model-Unavailable State | 70 |
| 4.13 | Reconciliation Workspace and Seeded Settlement Candidate | 71 |
| 4.14 | Responsive Member Dashboard at 390 Pixels | 72 |
| 4.15 | Predictive Class Distribution | Not executed |
| 4.16 | Logistic Regression Evaluation Curves | Not executed |
| 4.17 | Predictive Confusion Matrix | Not executed |
| 4.18 | Predictive Calibration and Standardised Coefficients | Not executed |

---

## List of Listings

| Listing No. | Title | Page |
| --- | --- | --- |
| 4.1 | Family Context Binding | 47 |
| 4.2 | Oldest-Balance-First Payment Allocation | 47 |
| 4.3 | Paystack Webhook Reference Verification | 48 |
| 4.4 | Confirm-First AI Write Governance | 49 |
| 4.5 | Logistic Regression Training | 52 |
| 4.6 | Baseline Comparison and Activation Gates | 53 |

*Listings 4.5 and 4.6 show the implemented training and evaluation path. They do not represent an executed production training run or substitute for the missing authorised dataset and model evidence.*

---

## List of Abbreviations

| Abbreviation | Full Meaning |
| --- | --- |
| 2FA | Two-Factor Authentication |
| AI | Artificial Intelligence |
| API | Application Programming Interface |
| CRUD | Create, Read, Update, Delete |
| CSS | Cascading Style Sheets |
| ERD | Entity-Relationship Diagram |
| HMAC | Hash-based Message Authentication Code |
| HTML | HyperText Markup Language |
| HTTP | HyperText Transfer Protocol |
| JSON | JavaScript Object Notation |
| LLM | Large Language Model |
| ML | Machine Learning |
| MVC | Model-View-Controller |
| NGN | Nigerian Naira |
| ORM | Object-Relational Mapping |
| PHP | PHP: Hypertext Preprocessor |
| RBAC | Role-Based Access Control |
| REST | Representational State Transfer |
| SaaS | Software as a Service |
| SHA | Secure Hash Algorithm |
| SPA | Single Page Application |
| SQL | Structured Query Language |
| SSR | Server-Side Rendering |
| UI | User Interface |
| UX | User Experience |
| WebAuthn | Web Authentication |
