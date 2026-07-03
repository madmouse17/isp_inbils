# Approval flow business rules

Approval flow standardizes risky or financial action review. Steps define actor/role, decision, timestamp, comment, and resulting state. Backend policies enforce authority. Frontend hides disabled actions but never replaces backend checks. Critical approved actions run in database transactions.
