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

### SUPERVISOR: DR SAMUEL MAKINDE

<br/>

### MARCH, 2026

</div>

---

## Certification

This is to certify that this project titled **"Design and Implementation of an AI-Enhanced Multi-Tenant Family Fund Management System with Predictive Analytics and Intelligent Reporting"** was carried out by **Aminu Danladi Hussain** (Matriculation Number: **2024/A/SENG/0156**) of the Department of Software Engineering, Faculty of Computing, Miva Open University.

<br/>

**Dr Samuel Makinde** &emsp;&emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
Supervisor &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

<br/>

**\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_** &emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
Head of Department &emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

<br/>

**\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_** &emsp;&emsp;&emsp;&emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ &emsp;&emsp; \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
External Examiner &emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Signature &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&emsp; Date

---

## Dedication

*\[To be completed by the student. Example: "This project is dedicated to my family, whose collective spirit of contribution and togetherness inspired this work."]*

---

## Acknowledgments

*\[To be completed by the student. Suggested structure:]*

I would like to express my sincere gratitude to:

- **My supervisor, Dr Samuel Makinde**, for his invaluable guidance, constructive feedback, and encouragement throughout this project.
- **The Department of Software Engineering, Miva Open University**, for providing an enabling academic environment.
- **My family**, whose real-world challenge of managing a family contribution fund inspired the very problem this project addresses.
- **My classmates and friends**, for their moral support, technical discussions, and collaborative spirit.
- **The open-source community**, particularly the maintainers of Laravel, Vue.js, and the many libraries that made this project possible.

Above all, I am grateful to **Almighty God** for the wisdom, strength, and perseverance to see this project to completion.

---

## Abstract

The management of shared financial contributions within families and cooperative groups in Nigeria remains largely informal, relying on manual record-keeping methods such as spreadsheets, notebooks, and messaging platforms. These approaches are prone to errors, lack transparency, and offer no mechanism for accountability, payment reminders, or financial forecasting. While digital financial platforms exist for individual savings and formal cooperatives, no purpose-built solution addresses the unique needs of family-level fund management with role-based governance.

This project presents the design and implementation of an AI-enhanced multi-tenant web-based family fund management system. The system employs a multi-tenant software architecture to provide isolated operational environments for each family group on a single platform. It implements a role-based access control model with three tiers—Administrator, Financial Secretary, and Member—to enforce appropriate permissions across fund management operations. Core modules include contribution tracking with partial payment support and an oldest-balance-first allocation algorithm, expense recording, fund balance management, and online payment processing via the Paystack payment gateway.

The system further integrates predictive analytics using machine learning to forecast member payment behaviour—identifying members likely to default or pay on time—and employs Large Language Model (LLM) technology to generate human-readable narrative summaries of financial reports. Security is reinforced through WebAuthn passkey authentication and two-factor authentication mechanisms.

The application is developed using Laravel (PHP) for the backend, Vue.js with Inertia.js for the frontend single-page application, PostgreSQL for the database, and Tailwind CSS for responsive styling. The outcome is a comprehensive, secure, and intelligent platform that modernises family fund management while providing actionable financial insights through artificial intelligence.

**Keywords:** Multi-Tenancy, Family Fund Management, Role-Based Access Control, Predictive Analytics, Large Language Model, Payment Gateway, WebAuthn, Laravel, Vue.js

---

## Table of Contents

- [Preliminary Pages](#preliminary-pages)
  - [Title Page](#title-page)
  - [Certification](#certification)
  - [Dedication](#dedication)
  - [Acknowledgments](#acknowledgments)
  - [Abstract](#abstract)
  - [Table of Contents](#table-of-contents)
  - [List of Tables](#list-of-tables)
  - [List of Figures](#list-of-figures)
  - [List of Abbreviations](#list-of-abbreviations)
- [Chapter One: Introduction](chapter-1.md)
  - [1.1 Background of the Study](chapter-1.md#11-background-of-the-study)
  - [1.2 Statement of the Problem](chapter-1.md#12-statement-of-the-problem)
  - [1.3 Aim, Objectives, and Research Questions of the Study](chapter-1.md#13-aim-objectives-and-research-questions-of-the-study)
  - [1.4 Scope of the Study](chapter-1.md#14-scope-of-the-study)
  - [1.5 Significance of the Study](chapter-1.md#15-significance-of-the-study)
  - [1.6 Motivation for the Study](chapter-1.md#16-motivation-for-the-study)
  - [1.7 Limitations of the Study](chapter-1.md#17-limitations-of-the-study)
  - [1.8 Definition of Terms](chapter-1.md#18-definition-of-terms)
  - [1.9 Organization of the Report](chapter-1.md#19-organization-of-the-report)
- [Chapter Two: Literature Review](chapter-2.md)
  - [2.1 Historical Perspectives of Family Fund Management Systems](chapter-2.md#21-historical-perspectives-of-family-fund-management-systems)
  - [2.2 Theoretical Framework](chapter-2.md#22-theoretical-framework)
  - [2.3 Review of Related Work](chapter-2.md#23-review-of-related-work)
  - [2.4 Gaps in Existing Research](chapter-2.md#24-gaps-in-existing-research)
  - [2.5 Summary of the Literature Review](chapter-2.md#25-summary-of-the-literature-review)
- Chapter Three: System Design and Methodology
  - 3.1 Review of the Proposed System
  - 3.2 System Requirements
  - 3.3 Software Development Methodology
  - 3.4 System Architecture
  - 3.5 Programming Languages and Tools Used
  - 3.6 Database Design
  - 3.7 Software Modules and Components
  - 3.8 System Security
- Chapter Four: System Implementation and Testing
  - 4.1 Implementation of the System Design
  - 4.2 Module Integration and Coding
  - 4.3 Testing Strategy and Procedures
  - 4.4 Test Results and Discussion
- Chapter Five: Summary, Conclusion, and Recommendations
  - 5.1 Summary of Findings
  - 5.2 Conclusion
  - 5.3 Contributions of the Study
  - 5.4 Recommendations for Future Work
- [References](references.md)

---

## List of Tables

| Table No. | Title | Page |
| --- | --- | --- |
| 1.1 | Comparison of Existing Financial Management Platforms | — |
| 1.2 | Technical Stack Overview | — |
| 1.3 | Definition of Key Terms | — |

*\[To be updated as the document progresses.]*

---

## List of Figures

| Figure No. | Title | Page |
| --- | --- | --- |
| 3.1 | System Architecture Diagram | — |
| 3.2 | Entity-Relationship Diagram (ERD) | — |
| 3.3 | Use Case Diagram | — |
| 3.4 | Multi-Tenant Data Isolation Model | — |
| 3.5 | Payment Allocation Flowchart | — |
| 3.6 | Predictive Analytics Pipeline | — |

*\[To be updated as the document progresses.]*

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
