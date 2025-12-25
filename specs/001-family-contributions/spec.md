# Feature Specification: Family Contribution Tracker

**Feature Branch**: `001-family-contributions`  
**Created**: 2025-12-25  
**Status**: Draft  
**Input**: User description: "Build an application that tracks family member monthly contributions. Each family member belongs to a category which determines their monthly payment amount. Categories: Employed (₦4,000), Unemployed (₦2,000), Student (₦1,000). Roles include Super Admin, Financial Secretary (manages contributions and records payments), and Members. Contributions are due on the 28th of each month."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Financial Secretary Records a Payment (Priority: P1)

As a Financial Secretary, I want to record when a family member makes their monthly contribution so that I can track who has paid and who hasn't.

**Why this priority**: This is the core functionality—without payment recording, the entire system has no purpose. Every other feature depends on this working correctly.

**Independent Test**: Can be fully tested by logging in as Financial Secretary, selecting a member, recording a payment amount, and verifying the payment appears in the member's history.

**Acceptance Scenarios**:

1. **Given** I am logged in as Financial Secretary and viewing a member's profile, **When** I record a payment of ₦4,000 for December 2025, **Then** the payment is saved with the date, amount, and my name as the recorder, and the member's payment status for December shows as "Paid"
2. **Given** I am recording a payment, **When** I enter an amount different from the expected category amount (partial payment), **Then** the system accepts it and marks the contribution as "Partial" with the remaining balance displayed
3. **Given** a member has already paid for a month, **When** I try to record another payment for the same month, **Then** the system warns me and asks for confirmation before adding an additional payment

---

### User Story 2 - View Contribution Dashboard (Priority: P1)

As any authenticated user, I want to see a dashboard showing contribution status so that I can understand the family's overall collection progress.

**Why this priority**: Visibility into payment status is essential for both administrators and members. This is the primary interface users will interact with daily.

**Independent Test**: Can be tested by logging in and verifying the dashboard displays appropriate information based on user role.

**Acceptance Scenarios**:

1. **Given** I am logged in as Super Admin or Financial Secretary, **When** I view the dashboard, **Then** I see all family members with their category, expected amount, and current month's payment status
2. **Given** I am logged in as a regular Member, **When** I view the dashboard, **Then** I see the family's aggregate totals (total expected, total collected, total outstanding) but NOT individual members' payment details
3. **Given** it is after the 28th of the month, **When** I view the dashboard as Super Admin or Financial Secretary, **Then** members who haven't paid are highlighted as "Overdue"
4. **Given** I am viewing the dashboard as Super Admin or Financial Secretary, **When** I click on a member, **Then** I see their complete payment history

---

### User Story 3 - Super Admin Manages Family Members (Priority: P2)

As the Super Admin, I want to add, edit, and remove family members from the system so that the member list stays current as the family grows or changes.

**Why this priority**: Member management is required before payments can be tracked, but this is typically a one-time setup activity that can be done manually initially.

**Independent Test**: Can be tested by adding a new member with name and category, verifying they appear in the member list, then editing their category and confirming the change persists.

**Acceptance Scenarios**:

1. **Given** I am logged in as Super Admin, **When** I add a new family member with name "Aisha" and category "Student", **Then** the member is created with expected monthly contribution of ₦1,000
2. **Given** a member exists, **When** I change their category from "Student" to "Employed", **Then** their expected contribution updates to ₦4,000 for future months (past records remain unchanged)
3. **Given** a member has payment history, **When** I attempt to delete them, **Then** the system offers to archive instead of delete to preserve financial records

---

### User Story 4 - Super Admin Manages Roles (Priority: P2)

As the Super Admin, I want to assign roles to family members so that the Financial Secretary can record payments while regular members can only view their own information.

**Why this priority**: Role-based access is important for security, but the app can function initially with just the Super Admin doing all administrative tasks.

**Independent Test**: Can be tested by assigning Financial Secretary role to a member, logging in as that member, and verifying they can record payments but cannot manage members.

**Acceptance Scenarios**:

1. **Given** I am Super Admin, **When** I assign the Financial Secretary role to a member, **Then** they can log in and access payment recording features
2. **Given** I am a regular Member, **When** I try to access payment recording, **Then** I am denied access and shown an appropriate message
3. **Given** a Financial Secretary exists, **When** I as Super Admin remove their role, **Then** they can no longer record payments on next login

---

### User Story 5 - Member Views Own Contribution History (Priority: P3)

As a family member, I want to view my own contribution history so that I can verify my payments and see any outstanding balances.

**Why this priority**: Self-service history access reduces administrative burden but is not critical for MVP—Financial Secretary can share this information verbally.

**Independent Test**: Can be tested by logging in as a regular member and viewing personal payment history showing all past contributions with dates and amounts.

**Acceptance Scenarios**:

1. **Given** I am logged in as a regular member, **When** I view my profile, **Then** I see my payment history for all months including payment dates and amounts
2. **Given** I have a partial payment for a month, **When** I view that month's details, **Then** I see the paid amount, remaining balance, and expected total
3. **Given** I am viewing my history, **When** I access it from any device, **Then** the information is consistent and up-to-date

---

### User Story 6 - Generate Contribution Reports (Priority: P3)

As the Financial Secretary or Super Admin, I want to generate monthly and yearly contribution reports so that I can share financial summaries with the family.

**Why this priority**: Reports are valuable for transparency but can be calculated manually initially. This enhances the user experience but isn't blocking.

**Independent Test**: Can be tested by selecting a date range and generating a report that shows total collected, outstanding amounts, and per-member breakdown.

**Acceptance Scenarios**:

1. **Given** I am Financial Secretary or Super Admin, **When** I generate a report for December 2025, **Then** I see total expected (sum of all member amounts), total collected, total outstanding, and collection percentage
2. **Given** I generate an annual report, **When** I view it, **Then** I see month-by-month breakdown of collection performance
3. **Given** I generate a report, **When** I choose to export, **Then** I can download it in a printable format

---

### Edge Cases *(Clarified)*

| Edge Case | Resolution | FR |
|-----------|------------|----|
| Member changes category mid-month | Change takes effect from NEXT month; current month uses old category amount | FR-017 |
| Advance payments for future months | System ALLOWS advance payments up to 6 months ahead; each payment recorded against its target month | FR-018 |
| Partial payment then new payment | New payments MUST complete oldest incomplete month's balance first before applying to future months | FR-020 |
| Financial Secretary role removed from all members | System WARNS Super Admin before removing last FS; SA retains payment recording ability | FR-019 |
| Deceased or departed family members | Use archive (soft delete); preserves financial records while hiding from active lists | FR-010 |
| February 28th/29th in leap years | Due date is 28th regardless; no special handling needed | N/A |
| Foreign currency or denominations | Out of scope for MVP - all payments in Naira (₦) only | N/A |

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST allow Super Admin to add family members with name and category assignment
- **FR-002**: System MUST support three member categories: Employed (₦4,000/month), Unemployed (₦2,000/month), and Student (₦1,000/month)
- **FR-003**: System MUST allow Financial Secretary to record payments against specific members for specific months
- **FR-004**: System MUST track payment date, amount, month/year applied, and who recorded the payment
- **FR-005**: System MUST display payment status (Paid, Partial, Unpaid, Overdue) for each member per month
- **FR-006**: System MUST mark contributions as overdue when unpaid after the 28th of each month
- **FR-007**: System MUST support three roles: Super Admin (full access), Financial Secretary (payment management), and Member (view-only)
- **FR-008**: System MUST allow members to view their own payment history
- **FR-009**: System MUST prevent unauthorized users from recording or modifying payments
- **FR-010**: System MUST preserve payment history even when member details are modified
- **FR-011**: System MUST authenticate users before granting access to any features
- **FR-012**: System MUST allow Super Admin to assign and revoke the Financial Secretary role
- **FR-013**: System MUST calculate and display outstanding balances for partial payments
- **FR-014**: System MUST generate summary reports showing collection status by month
- **FR-015**: System MUST show Members the family's aggregate balance (total expected, collected, outstanding) on the dashboard
- **FR-016**: System MUST NOT show Members other individuals' contribution details or payment status—only their own
- **FR-017**: System MUST apply category changes from the following month, not the current month
- **FR-018**: System MUST allow members to make advance payments for up to 6 months ahead, recording each payment against its target month
- **FR-019**: System MUST warn Super Admin before removing the last Financial Secretary role and ensure SA can still record payments
- **FR-020**: System MUST automatically apply any new payment to the oldest incomplete contribution month first before allowing payment toward future months (balance-first rule)

### Key Entities

- **Family Member**: Represents a person in the contribution system. Has a name, contact information, assigned category, role, and account credentials. Belongs to exactly one category at a time.
- **Category**: Defines a contribution tier. Has a name (Employed/Unemployed/Student) and monthly amount (₦4,000/₦2,000/₦1,000). Determines expected contribution.
- **Contribution**: Represents a monthly financial obligation. Has month/year, expected amount (from category), and belongs to one member.
- **Payment**: Represents money received. Has amount, date received, month/year applied to, and reference to who recorded it. Belongs to one contribution.
- **Role**: Defines access permissions. Three types: Super Admin, Financial Secretary, Member. A member has exactly one role.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Financial Secretary can record a payment in under 30 seconds from dashboard view
- **SC-002**: Users can view current month's contribution status for all members within 3 seconds of page load
- **SC-003**: 100% of payments are accurately attributed to the correct member and month
- **SC-004**: Super Admin can add a new family member in under 1 minute
- **SC-005**: Dashboard shows real-time status—payments recorded reflect immediately
- **SC-006**: System correctly marks all unpaid contributions as overdue after the 28th
- **SC-007**: All authenticated users can access their personal payment history on any device
- **SC-008**: Role-based access correctly restricts Financial Secretary from member management
- **SC-009**: Role-based access correctly restricts Members from payment recording
- **SC-010**: System maintains accurate running totals for monthly and yearly collections

## Clarifications

### Session 2025-12-25

- Q: Can members with Member role see the whole family's aggregate balance and other members' individual contributions? → A: Members CAN see family aggregate balance, CANNOT see other members' individual contributions
